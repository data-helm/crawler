<?php

namespace DataHelm\Crawler\Scraping;

use DataHelm\Crawler\Blueprint\CrawlMode;
use DataHelm\Crawler\Blueprint\InfiniteScrollConfig;
use DataHelm\Crawler\Blueprint\PaginationStrategy;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Blueprint\SearchFilter;
use DataHelm\Crawler\Dom\Page;
use DataHelm\Crawler\Dom\Url;
use DataHelm\Crawler\Http\BrowserHttpClient;
use DataHelm\Crawler\Http\CachedHttpClient;
use DataHelm\Crawler\Http\GuardedHttpClient;
use DataHelm\Crawler\Http\GuzzleHttpClient;
use DataHelm\Crawler\Http\HttpClient;
use DataHelm\Crawler\Http\HttpRequester;
use DataHelm\Crawler\Http\TransportFactory;
use DataHelm\Crawler\Http\UrlGuard;
use DataHelm\Crawler\Media\ItemImageResolver;
use DataHelm\Crawler\Pipeline\ItemPipeline;
use DataHelm\Crawler\Pipeline\ItemProcessor;
use DataHelm\Crawler\Pipeline\SchemaCoercionProcessor;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Step 2 — drives the actual crawl from a blueprint.
 *
 * For every paginated page it extracts each list item, optionally follows the
 * item's detail page, runs the result through the processing pipeline, and
 * collects it.
 *
 * When the blueprint mode is "api" the crawl is delegated to {@see ApiCrawler},
 * which calls a JSON endpoint directly instead of scraping HTML (for JavaScript
 * SPAs backed by an API).
 *
 * Additional features wired in from blueprint configs:
 *  - HTTP cache (cache_config.enabled)
 *  - AutoThrottle: dynamically adjusts inter-page delay based on server latency
 *  - Streaming: items are emitted via onItem callback instead of buffered
 *  - Deduplication, conditional filters, crawl delays
 *  - CrawlStats: accumulated metrics accessible via getLastStats() after crawl()
 */
final class CrawlEngine
{
    private ?CrawlStats $lastStats = null;
    private readonly ItemImageResolver $imageResolver;
    private ?CrawlState $resumeState = null;
    private ?string $resumeStateName = null;

    /**
     * @param array<string,class-string<ItemProcessor>> $pipelineRegistry
     *   Named processors from config('crawler.pipeline_registry') injected by
     *   the service provider; used to resolve blueprint-level pipeline_names.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Paginator $paginator,
        private readonly ItemPipeline $pipeline,
        private readonly array $pipelineRegistry = [],
        private readonly ?TransportFactory $transports = null,
        private readonly ?UrlGuard $guard = null,
    ) {
        $this->imageResolver = new ItemImageResolver();
    }

    /**
     * Load persisted dedup state so a subsequent crawl skips already-seen items.
     * Call before crawl() when the robot flag --resume is present.
     */
    public function loadState(string $name): void
    {
        $this->resumeStateName = $name;
        $this->resumeState     = CrawlState::loadFor($name);
    }

    /**
     * @param (callable(string):void)|null     $onPage  Progress hook invoked per page URL.
     * @param (callable(ScrapedItem):void)|null $onItem  Called immediately for each item (streaming mode).
     *
     * @return list<ScrapedItem> Empty when streaming (onItem is set + outputConfig.stream = true).
     */
    public function crawl(
        ScrapeBlueprint $blueprint,
        ?callable $onPage = null,
        int $limit = 0,
        ?callable $onItem = null,
    ): array {
        // A blueprint can pin its own transport (http_config.transport); otherwise
        // use the globally configured client. This lets each robot remember the
        // transport its target needs (e.g. flaresolverr for Cloudflare) without a
        // global CRAWLER_TRANSPORT flag on every run.
        if ($blueprint->httpConfig->transport !== null && $this->transports !== null) {
            $baseHttp = $this->transports->make($blueprint->httpConfig->transport, $blueprint->httpConfig);
        } else {
            // Configure the base HTTP client with blueprint settings.
            $baseHttp = $this->http instanceof CachedHttpClient ? $this->http->getInner() : $this->http;
            if ($baseHttp instanceof GuzzleHttpClient) {
                $baseHttp->configure($blueprint->httpConfig);
            }
            if ($baseHttp instanceof BrowserHttpClient) {
                $baseHttp->configure($blueprint->httpConfig);
            }
        }

        // Warn when blueprint requests browser rendering but no browser client is wired.
        if ($blueprint->httpConfig->renderJs && ! ($baseHttp instanceof BrowserHttpClient)) {
            fwrite(STDERR, 'Warning: blueprint sets render_js=true but no BrowserHttpClient is bound. '
                . 'Set config(\'crawler.transport\') = \'browser\' and bind a BrowserHttpClient subclass. '
                . 'Falling back to Guzzle.' . PHP_EOL);
        }

        // Whether the raw transport can issue API-mode requests, captured before
        // wrapping (GuardedHttpClient always advertises HttpRequester, so the
        // check below must look at the underlying client, not the wrapper).
        $rawSupportsApi = $baseHttp instanceof HttpRequester;

        // SSRF guard: wrap the configured base client so every fetch downstream —
        // list pages, pagination, detail pages (URLs built from scraped content),
        // and API-mode calls — passes the guard. The instanceof checks above stay
        // on the raw client; everything below fetches through the guarded one. One
        // guard instance is shared by every wrapper so its per-host resolution
        // cache is reused across all of them.
        $rawHttp   = $baseHttp;
        $guard     = $this->guard ?? new UrlGuard();
        $baseHttp  = new GuardedHttpClient($rawHttp, $guard);
        $paginator = new Paginator($baseHttp);

        // API mode: delegate to the JSON crawler. It runs the same pipeline
        // (incl. item_schema coercion) and dedup/resume state as the HTML path.
        if ($blueprint->mode === CrawlMode::API) {
            if (! $rawSupportsApi) {
                throw new \RuntimeException('API mode requires an HttpRequester-capable HTTP client.');
            }

            $apiCrawler = new ApiCrawler($baseHttp, $this->buildPipeline($blueprint), $this->imageResolver, $this->resumeState);
            $result = $apiCrawler->crawl($blueprint, $onPage, $limit, $onItem);
            $this->lastStats = $apiCrawler->getLastStats();

            $this->persistResumeState($blueprint);

            return $result;
        }

        $stats = new CrawlStats();
        $this->lastStats = $stats;

        // Wrap with cache layer when enabled. The guard goes on the OUTSIDE of the
        // cache (Guard(Cache(raw))) so it runs on every logical fetch, including
        // cache hits — the per-host resolution cache keeps that cheap. Build the
        // cache on $rawHttp (which may be a per-blueprint transport client) — not
        // $this->http, the unconfigured global default that would ignore a
        // transport override.
        $http = $blueprint->cache->enabled
            ? new GuardedHttpClient(new CachedHttpClient($rawHttp, $blueprint->cache), $guard)
            : $baseHttp;

        $extractor = new ItemExtractor($blueprint->fields);
        $detailExtractor = $blueprint->scrapeDetail && $blueprint->detailFields !== []
            ? new ItemExtractor($blueprint->detailFields)
            : null;

        $effectiveLimit = $limit > 0 ? $limit : $blueprint->crawlConfig->maxItems;
        $streaming = $onItem !== null && $blueprint->outputConfig->stream;

        $pipeline = $this->buildPipeline($blueprint);

        $sink = new ItemSink(
            $blueprint,
            $pipeline,
            $this->imageResolver,
            $stats,
            $effectiveLimit,
            $streaming,
            $streaming ? $onItem : null,
            $this->resumeState,
        );

        $isInfiniteScroll = $blueprint->pagination->strategy === PaginationStrategy::INFINITE_SCROLL
            && $blueprint->infiniteScroll !== null;

        $currentDelayMs = (float) ($blueprint->autoThrottle->enabled
            ? $blueprint->autoThrottle->startDelayMs
            : $blueprint->httpConfig->delayMs);

        // One robot can crawl several start URLs (e.g. categories of a site). They
        // share the same fields/pagination/filters and the same $sink, so dedup
        // and the item limit apply across all of them.
        $searchFilters = $blueprint->searchFilters !== [] ? $blueprint->searchFilters : [new SearchFilter('')];
        $reachedLimit  = false;

        foreach ($searchFilters as $searchFilter) {
            // Resolve each filter's suffix against the blueprint base URL. Only the
            // pagination needs the per-URL blueprint; field/detail extraction is
            // URL-independent so it keeps using $blueprint.
            $targetUrl       = $searchFilter->resolvedUrl($blueprint->url);
            $blueprintForUrl = $targetUrl === $blueprint->url ? $blueprint : $blueprint->withUrl($targetUrl);

            // Per-filter cap: stop this filter after `limit` items (0 = unlimited)
            // and move on to the next, so each category gets its own quota.
            $filterItemCap = $searchFilter->limit > 0 ? $stats->itemsScraped + $searchFilter->limit : 0;

            foreach ($paginator->pages($blueprintForUrl) as $pageUrl) {
                if ($onPage !== null) {
                    $onPage($pageUrl);
                }

                if ($currentDelayMs > 0) {
                    usleep((int) ($currentDelayMs * 1000));
                }

                $t0 = microtime(true);

                try {
                    $page = Page::fromHtml($pageUrl, $http->get($pageUrl));
                    $stats->pagesFetched++;
                } catch (\Throwable) {
                    $stats->pagesFailed++;
                    continue;
                }

                if ($blueprint->autoThrottle->enabled) {
                    $latencyMs   = (microtime(true) - $t0) * 1000;
                    $targetDelay = $latencyMs / max(0.1, $blueprint->autoThrottle->targetConcurrency);
                    $targetDelay = max($blueprint->httpConfig->delayMs, min((float) $blueprint->autoThrottle->maxDelayMs, $targetDelay));
                    $currentDelayMs = ($currentDelayMs + $targetDelay) / 2;

                    if ($blueprint->autoThrottle->debug) {
                        fwrite(STDERR, sprintf(
                            'AutoThrottle: latency=%.0fms → delay=%.0fms%s',
                            $latencyMs,
                            $currentDelayMs,
                            PHP_EOL,
                        ));
                    }
                }

                $reachedLimit = $this->consumePage($page, $pageUrl, $blueprint, $extractor, $detailExtractor, $http, $stats, $sink, $searchFilter->meta, $filterItemCap);

                // Infinite scroll: keep fetching fragments from the configured
                // endpoint after the first server-rendered page.
                if (! $reachedLimit && $isInfiniteScroll) {
                    $reachedLimit = $this->crawlInfiniteScroll($blueprint, $baseHttp, $rawSupportsApi, $page, $extractor, $detailExtractor, $http, $stats, $sink, $onPage);
                }

                if ($reachedLimit) {
                    break;
                }

                // This filter hit its own quota — move on to the next filter.
                if ($filterItemCap > 0 && $stats->itemsScraped >= $filterItemCap) {
                    break;
                }

                if ($blueprint->crawlConfig->delayBetweenPagesMs > 0) {
                    usleep($blueprint->crawlConfig->delayBetweenPagesMs * 1000);
                }
            }

            if ($reachedLimit) {
                break;
            }
        }

        $this->syncCacheStats($http, $stats);
        $stats->finish();

        $this->persistResumeState($blueprint);

        return $sink->results();
    }

    public function getLastStats(): ?CrawlStats
    {
        return $this->lastStats;
    }

    // -------------------------------------------------------------------------

    /**
     * Persist dedup state for resumable blueprints (HTML and API paths alike).
     */
    private function persistResumeState(ScrapeBlueprint $blueprint): void
    {
        if (($blueprint->resumable || $this->resumeStateName !== null) && $this->resumeState !== null) {
            $name = $this->resumeStateName ?? ($this->lastStats?->itemsScraped ?? 0) . '-run';
            $this->resumeState->saveFor($name);
        }
    }

    /**
     * Extract every item from one page, merge its detail page when configured,
     * and feed each into the sink.
     *
     * @param array<string,scalar> $meta          Tags merged into each item (e.g. category).
     * @param int                  $filterItemCap Absolute itemsScraped target at which the
     *                                            current filter stops (0 = no per-filter cap).
     *
     * @return bool true when the GLOBAL item limit was reached (stops the whole crawl).
     */
    private function consumePage(
        Page $page,
        string $pageUrl,
        ScrapeBlueprint $blueprint,
        ItemExtractor $extractor,
        ?ItemExtractor $detailExtractor,
        HttpClient $http,
        CrawlStats $stats,
        ItemSink $sink,
        array $meta = [],
        int $filterItemCap = 0,
    ): bool {
        foreach ($this->extractItems($page, $blueprint, $extractor) as $item) {
            // Tag the item with its start URL's meta (e.g. category) so the JSON
            // records which source it came from.
            foreach ($meta as $key => $value) {
                $item->set((string) $key, $value);
            }

            if ($detailExtractor !== null && $blueprint->detailLinkField !== null) {
                $this->mergeDetail($item, $blueprint->detailLinkField, $pageUrl, $detailExtractor, $http, $stats);
            }

            if ($sink->accept($item, $pageUrl)) {
                return true;
            }

            // Per-filter quota reached — stop consuming this page (the outer loop
            // moves to the next filter). Not the global limit, so return false.
            if ($filterItemCap > 0 && $stats->itemsScraped >= $filterItemCap) {
                return false;
            }
        }

        return false;
    }

    /**
     * Drives infinite-scroll pagination: scrapes the token from the first page,
     * then repeatedly calls the configured endpoint for the next batch (an HTML
     * fragment) until it is empty, the limit is hit, or max_pages is reached.
     *
     * @return bool true when the item limit was reached.
     */
    private function crawlInfiniteScroll(
        ScrapeBlueprint $blueprint,
        HttpClient $baseHttp,
        bool $baseSupportsApi,
        Page $firstPage,
        ItemExtractor $extractor,
        ?ItemExtractor $detailExtractor,
        HttpClient $http,
        CrawlStats $stats,
        ItemSink $sink,
        ?callable $onPage,
    ): bool {
        $scroll = $blueprint->infiniteScroll;
        if ($scroll === null) {
            return false;
        }

        // $baseHttp is the SSRF-guarded wrapper, which always advertises
        // HttpRequester — so test the raw transport's capability (captured before
        // wrapping) to keep the clear "only the first page was scraped" message.
        if (! $baseSupportsApi || ! $baseHttp instanceof HttpRequester) {
            fwrite(STDERR, 'infinite_scroll: HTTP client cannot issue custom requests; only the first page was scraped.' . PHP_EOL);

            return false;
        }

        $endpoint = $scroll->endpoint !== '' ? $scroll->endpoint : $blueprint->url;
        $token    = $this->scrapeToken($firstPage, $scroll);

        $lastSignature = null;

        // First server-rendered page counts as page 1; fetch until empty or max_pages.
        for ($i = 0; PageCap::isUnlimited($blueprint->maxPages) || $i < $blueprint->maxPages - 1; $i++) {
            if ($blueprint->httpConfig->delayMs > 0) {
                usleep($blueprint->httpConfig->delayMs * 1000);
            }

            $params = $scroll->params;
            $params[$scroll->param] = $scroll->valueForBatch($i);
            if ($token !== null && $scroll->tokenParam !== '') {
                $params[$scroll->tokenParam] = $token;
            }

            [$body, $query] = $this->encodeParams($scroll->method, $params, $scroll->bodyFormat);

            if ($onPage !== null) {
                $onPage(sprintf('%s (%s=%d)', $endpoint, $scroll->param, $scroll->valueForBatch($i)));
            }

            try {
                $raw = $baseHttp->request($scroll->method, $endpoint, $scroll->headers, $body, $query);
                $stats->pagesFetched++;
            } catch (\Throwable) {
                $stats->pagesFailed++;
                break;
            }

            $fragment = Page::fromHtml($blueprint->url, $raw);
            $batch    = $this->extractItems($fragment, $blueprint, $extractor);

            if ($batch === [] && $scroll->stopWhenEmpty) {
                break;
            }

            // Identical-payload guard (prevents an infinite loop with maxPages=0):
            // if a fragment is byte-identical to the previous one, the endpoint is
            // ignoring the offset/param and returning the same rows forever — stop
            // instead of paging into oblivion. Mirrors ApiCrawler's guard.
            $signature = md5($raw);
            if ($signature === $lastSignature) {
                fwrite(STDERR, sprintf(
                    'infinite_scroll: batch %d returned the same payload as the previous one — stopping '
                    . '(the endpoint likely ignores the "%s" parameter).' . PHP_EOL,
                    $i + 1,
                    $scroll->param,
                ));
                break;
            }
            $lastSignature = $signature;

            foreach ($batch as $item) {
                if ($detailExtractor !== null && $blueprint->detailLinkField !== null) {
                    $this->mergeDetail($item, $blueprint->detailLinkField, $blueprint->url, $detailExtractor, $http, $stats);
                }

                if ($sink->accept($item, $blueprint->url)) {
                    return true;
                }
            }

            if ($blueprint->crawlConfig->delayBetweenPagesMs > 0) {
                usleep($blueprint->crawlConfig->delayBetweenPagesMs * 1000);
            }
        }

        return false;
    }

    private function scrapeToken(Page $page, InfiniteScrollConfig $scroll): ?string
    {
        if ($scroll->tokenCss === '') {
            return null;
        }

        try {
            $node = $page->crawler()->filter($scroll->tokenCss);
            if ($node->count() === 0) {
                return null;
            }

            $value = $scroll->tokenAttribute !== '' ? $node->first()->attr($scroll->tokenAttribute) : $node->first()->text('', true);

            return is_string($value) && $value !== '' ? trim($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,scalar> $params
     *
     * @return array{0:?string,1:array<string,scalar>} [body, query]
     */
    private function encodeParams(string $method, array $params, string $bodyFormat): array
    {
        if (strtoupper($method) === 'GET') {
            return [null, $params];
        }

        $body = $bodyFormat === 'form'
            ? http_build_query($params)
            : (string) json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$body, []];
    }

    /**
     * @return list<ScrapedItem>
     */
    private function extractItems(Page $page, ScrapeBlueprint $blueprint, ItemExtractor $extractor): array
    {
        $items = [];

        try {
            $page->crawler()->filter($blueprint->itemSelector)->each(
                function (Crawler $node) use (&$items, $extractor, $page): void {
                    $items[] = $extractor->extract($node, $page->url);
                },
            );
        } catch (\Throwable) {
            // Selector did not match on this page.
        }

        return $items;
    }

    private function mergeDetail(
        ScrapedItem $item,
        string $linkField,
        string $pageUrl,
        ItemExtractor $detailExtractor,
        HttpClient $http,
        CrawlStats $stats,
    ): void {
        $detailUrl = Url::absolute($pageUrl, is_string($item->get($linkField)) ? $item->get($linkField) : null);
        if ($detailUrl === null) {
            return;
        }

        try {
            $detailPage = Page::fromHtml($detailUrl, $http->get($detailUrl));
            $stats->detailFetched++;
        } catch (\Throwable) {
            $stats->detailFailed++;

            return;
        }

        foreach ($detailExtractor->extract($detailPage->crawler(), $detailPage->url)->toArray() as $key => $value) {
            $item->set($key, $value);
        }
    }

    /**
     * Build the pipeline to use for a specific crawl.
     *
     * When the blueprint lists explicit pipeline_names, resolve them from the
     * registry and build a custom pipeline (possibly extended with a schema
     * coercion step when item_schema is non-empty).  Otherwise fall back to the
     * globally injected pipeline and append schema coercion when needed.
     */
    private function buildPipeline(ScrapeBlueprint $blueprint): ItemPipeline
    {
        $processors = [];

        if ($blueprint->pipelineNames !== []) {
            foreach ($blueprint->pipelineNames as $name) {
                $class = $this->pipelineRegistry[$name] ?? null;
                if ($class !== null && class_exists($class)) {
                    $processors[] = new $class();
                } else {
                    fwrite(STDERR, "CrawlEngine: unknown pipeline name '{$name}' — skipped." . PHP_EOL);
                }
            }

            $pipeline = new ItemPipeline($processors);
        } else {
            $pipeline = $this->pipeline;
        }

        if ($blueprint->itemSchema !== []) {
            $pipeline = $pipeline->withExtra(new SchemaCoercionProcessor($blueprint->itemSchema));
        }

        return $pipeline;
    }

    private function syncCacheStats(HttpClient $http, CrawlStats $stats): void
    {
        // The page client is Guard(Cache(raw)) when caching is on, so peel the
        // guard wrapper to reach the CachedHttpClient underneath.
        if ($http instanceof GuardedHttpClient) {
            $http = $http->getInner();
        }

        if ($http instanceof CachedHttpClient) {
            $stats->cacheHits   = $http->hits();
            $stats->cacheMisses = $http->misses();
        }
    }
}
