<?php

namespace DataHelm\Crawler\Scraping;

use DataHelm\Crawler\Blueprint\ApiConfig;
use DataHelm\Crawler\Blueprint\ApiDetailConfig;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Http\HttpRequester;
use DataHelm\Crawler\Media\ItemImageResolver;
use DataHelm\Crawler\Pipeline\ItemPipeline;

/**
 * Crawls a JSON API instead of HTML (blueprint mode "api").
 *
 * Calls the configured endpoint, walks `items_path` to the list of records,
 * extracts each via JSON dot-paths, optionally fetches a per-item detail
 * document, then applies the same pipeline / dedup / filters / images / output
 * transforms as the HTML engine.
 *
 * Pagination uses an incrementing page parameter injected into the query string
 * (or the request body when `page_in_body` is set), stopping when a page returns
 * no items, the total count is reached, or max_pages / limit is hit.
 *
 * Multiple categories: like the HTML engine, an API robot can carry
 * `search_filters`. Each filter substitutes its `url_sufix` into the endpoint
 * (replacing a `{search}` placeholder, or appended to the path), tags every item
 * from it, and applies its own per-filter limit. Dedup and the global limit are
 * shared across all of them.
 */
final class ApiCrawler
{
    private ?CrawlStats $lastStats = null;

    public function __construct(
        private readonly HttpRequester $http,
        private readonly ItemPipeline $pipeline,
        private readonly ItemImageResolver $imageResolver,
        private readonly ?CrawlState $resumeState = null,
    ) {
    }

    /**
     * @param (callable(string):void)|null     $onPage
     * @param (callable(ScrapedItem):void)|null $onItem
     *
     * @return list<ScrapedItem>
     */
    public function crawl(
        ScrapeBlueprint $blueprint,
        ?callable $onPage = null,
        int $limit = 0,
        ?callable $onItem = null,
    ): array {
        $stats = new CrawlStats();
        $this->lastStats = $stats;

        $api = $blueprint->api;
        if ($api === null || $api->endpoint === '') {
            $stats->finish();

            return [];
        }

        $extractor       = new JsonItemExtractor($blueprint->fields);
        $detailExtractor = $blueprint->detailFields !== []
            ? new JsonItemExtractor($blueprint->detailFields)
            : null;

        $effectiveLimit = $limit > 0 ? $limit : $blueprint->crawlConfig->maxItems;
        $streaming      = $onItem !== null && $blueprint->outputConfig->stream;

        // One shared ItemSink drives the identical post-extraction pipeline the
        // HTML engine uses — pipeline (incl. item_schema coercion), dedup, filters,
        // image resolution, resume state, streaming/buffering and the global limit.
        // A single instance across all sources shares the dedup set and item buffer,
        // so search_filters, --limit and --resume behave exactly as in HTML mode.
        $sink = new ItemSink(
            $blueprint,
            $this->pipeline,
            $this->imageResolver,
            $stats,
            $effectiveLimit,
            $streaming,
            $streaming ? $onItem : null,
            $this->resumeState,
        );

        // Each search_filter is a "source" (its own endpoint, tags and limit). With
        // no filters there is a single source: the endpoint as-is.
        foreach ($this->buildSources($blueprint, $api) as $source) {
            if ($this->crawlSource($blueprint, $api, $source, $extractor, $detailExtractor, $sink, $onPage, $stats)) {
                break;
            }
        }

        $stats->finish();

        return $sink->results();
    }

    public function getLastStats(): ?CrawlStats
    {
        return $this->lastStats;
    }

    // -------------------------------------------------------------------------

    /**
     * Resolve the endpoints to crawl. In API mode each search_filter becomes a
     * source: its url_sufix is spliced into the endpoint, its meta tags are
     * stamped on every item, and its limit caps that source. No search_filters →
     * a single source (the endpoint, no tags, no per-source limit).
     *
     * @return list<array{endpoint:string,tags:array<string,scalar>,limit:int}>
     */
    private function buildSources(ScrapeBlueprint $blueprint, ApiConfig $api): array
    {
        if ($blueprint->searchFilters === []) {
            return [['endpoint' => $api->endpoint, 'tags' => [], 'limit' => 0]];
        }

        $sources = [];
        foreach ($blueprint->searchFilters as $filter) {
            $sources[] = [
                'endpoint' => $this->applySearch($api->endpoint, $filter->urlSuffix),
                'tags'     => $filter->meta,
                'limit'    => $filter->limit,
            ];
        }

        return $sources;
    }

    /**
     * Splice a search_filter suffix into the endpoint: replace a `{search}`
     * placeholder if present, otherwise append it to the path before any query
     * string — so ".../products/search?_from=0" + "men/shirts" becomes
     * ".../products/search/men/shirts?_from=0".
     */
    private function applySearch(string $endpoint, string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return $endpoint;
        }

        if (str_contains($endpoint, '{search}')) {
            return str_replace('{search}', trim($suffix, '/'), $endpoint);
        }

        [$path, $queryString] = array_pad(explode('?', $endpoint, 2), 2, null);
        $path = rtrim($path, '/') . '/' . ltrim($suffix, '/');

        return $queryString === null ? $path : $path . '?' . $queryString;
    }

    /**
     * Crawl one source (endpoint) to exhaustion, its per-source limit, or the
     * global limit — sharing the sink (dedup set, item buffer, stats) with the
     * others.
     *
     * @param array{endpoint:string,tags:array<string,scalar>,limit:int} $source
     * @param (callable(string):void)|null $onPage
     *
     * @return bool True when the GLOBAL limit was reached (stop all sources).
     */
    private function crawlSource(
        ScrapeBlueprint $blueprint,
        ApiConfig $api,
        array $source,
        JsonItemExtractor $extractor,
        ?JsonItemExtractor $detailExtractor,
        ItemSink $sink,
        ?callable $onPage,
        CrawlStats $stats,
    ): bool {
        $endpoint      = $source['endpoint'];
        $tags          = $source['tags'];
        $sourceLimit   = $source['limit'];
        $sourceScraped = 0;

        $page  = $api->startPage;
        $pages = 0;
        $total = null;
        $lastSignature = null;

        while (PageCap::allowsMore($pages, $blueprint->maxPages)) {
            if ($blueprint->httpConfig->delayMs > 0) {
                usleep($blueprint->httpConfig->delayMs * 1000);
            }

            [$url, $body, $query] = $this->buildRequestParts($api, $page, $endpoint);

            if ($onPage !== null) {
                $onPage($url);
            }

            try {
                $raw = $this->http->request($api->method, $url, $api->headers, $body, $query);
                $decoded = json_decode($raw, true);
                $stats->pagesFetched++;
            } catch (\Throwable $e) {
                $stats->pagesFailed++;
                $this->diagnose($pages, 'request failed: ' . $e->getMessage());
                break;
            }

            if (! is_array($decoded)) {
                $this->diagnose($pages, sprintf(
                    'response is not JSON (got %d bytes starting "%s"). The endpoint may be blocked '
                    . '(anti-bot/expired cookies) or returning HTML.',
                    strlen($raw),
                    substr(preg_replace('/\s+/', ' ', trim($raw)) ?? '', 0, 120),
                ));
                break;
            }

            $total ??= $this->resolveTotal($decoded, $api);

            $records = JsonPath::get($decoded, $api->itemsPath);
            if (! is_array($records) || $records === []) {
                $this->diagnose($pages, sprintf(
                    'no items at items_path "%s". Top-level keys in the response: [%s].',
                    $api->itemsPath,
                    implode(', ', array_slice(array_keys($decoded), 0, 20)),
                ));
                break;
            }

            // Identical-payload guard (prevents infinite loops). If this page's
            // records are byte-identical to the previous page's, the endpoint is
            // ignoring the page param and returning the same data every call —
            // e.g. a wrong endpoint was detected (a cart/orderForm rather than a
            // product list). Stop instead of fetching the same page forever.
            $signature = md5((string) json_encode($records));
            if ($signature === $lastSignature) {
                fwrite(STDERR, sprintf(
                    'API: page %d returned the same payload as the previous page — stopping '
                    . '(the endpoint likely ignores pagination; check api.endpoint / items_path).'
                    . PHP_EOL,
                    $pages,
                ));
                break;
            }
            $lastSignature = $signature;

            $scrapedBefore = $stats->itemsScraped;
            $dedupedBefore = $stats->itemsDeduped;

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $item = $extractor->extract($record);

                // Stamp this source's tags (e.g. category) onto every item — done
                // before the sink so the pipeline/dedup/filters see them too.
                foreach ($tags as $tagKey => $tagValue) {
                    $item->set($tagKey, $tagValue);
                }

                if ($detailExtractor !== null && $api->detail->enabled) {
                    $this->mergeDetail($item, $api->detail, $detailExtractor, $stats);
                }

                // Delegate the shared post-extraction pipeline to the sink. It
                // updates stats and returns true when the GLOBAL limit is reached.
                $scrapedBeforeItem = $stats->itemsScraped;
                $reachedGlobalLimit = $sink->accept($item, $blueprint->url);
                $kept = $stats->itemsScraped > $scrapedBeforeItem;

                if ($kept) {
                    $sourceScraped++;
                }

                // Global cap (--limit / max_items): stop the whole crawl.
                if ($reachedGlobalLimit) {
                    return true;
                }

                // Per-source cap (search_filter limit): this source is done, move
                // on to the next category.
                if ($sourceLimit > 0 && $sourceScraped >= $sourceLimit) {
                    break 2;
                }
            }

            $pages++;
            $page++;

            // No pagination configured → single request only.
            $usesPathPagination = str_contains($endpoint, '{page}')
                || str_contains($endpoint, '{page_size}');
            if ($api->pageParam === null && ! $usesPathPagination) {
                break;
            }

            // All-duplicates guard (prevents infinite loops). If a page produced no
            // new items *because* every record was a duplicate of one already seen,
            // pagination is repeating data — stop. Scoped to dedup (not user
            // filters), so a page emptied only by filters can still page forward.
            if ($stats->itemsScraped === $scrapedBefore && $stats->itemsDeduped > $dedupedBefore) {
                fwrite(STDERR, sprintf(
                    'API: page %d contained only already-seen items — stopping (end of data '
                    . 'or the endpoint is repeating pages).' . PHP_EOL,
                    $pages - 1,
                ));
                break;
            }

            // Stop once we've fetched everything the API says it has.
            if ($total !== null && $stats->itemsScraped >= $total) {
                break;
            }

            if ($blueprint->crawlConfig->delayBetweenPagesMs > 0) {
                usleep($blueprint->crawlConfig->delayBetweenPagesMs * 1000);
            }
        }

        return false;
    }

    /**
     * Builds the per-page URL, body and query, injecting pagination params.
     *
     * @return array{0:string,1:?string,2:array<string,scalar>}
     */
    private function buildRequestParts(ApiConfig $api, int $page, string $endpoint): array
    {
        $body = $api->body;

        // Preserve any query string already on the endpoint. Guzzle's `query`
        // request option overwrites the URI's query string wholesale, so params
        // the API requires (sort, filters, feature flags) would be silently
        // dropped — causing a 400, or worse, quietly wrong results — unless we
        // lift them into the merged query array here. Precedence: the endpoint's
        // own params, then the blueprint's api.query, then pagination params.
        [$url, $existingQuery] = array_pad(explode('?', $endpoint, 2), 2, '');
        $query = [];
        if ($existingQuery !== '') {
            parse_str($existingQuery, $query);
        }
        $query = array_merge($query, $api->query);

        // Path-based pagination: .../GetLotes/{page}/{page_size}
        if (str_contains($url, '{page}') || str_contains($url, '{page_size}')) {
            $url = str_replace(
                ['{page}', '{page_size}'],
                [(string) $page, (string) $api->pageSize],
                $url,
            );
        } elseif ($api->pageParam !== null) {
            if ($api->pageInBody) {
                $body[$api->pageParam] = $page;
                if ($api->pageSizeParam !== null) {
                    $body[$api->pageSizeParam] = $api->pageSize;
                }
                // DataTables-style offset pagination: advance start/length when
                // the body already declares them (e.g. Copart's payload).
                if (array_key_exists('start', $body)) {
                    $body['start'] = $page * $api->pageSize;
                }
                if (array_key_exists('length', $body)) {
                    $body['length'] = $api->pageSize;
                }
            } else {
                // Offset-style params advance by page size (offset=0,50,100…)
                // rather than by ordinal page number — mirrors the DataTables
                // start/length handling in the body branch above.
                $query[$api->pageParam] = in_array(strtolower($api->pageParam), ['offset', 'start', 'from', 'skip'], true)
                    ? $page * $api->pageSize
                    : $page;
                if ($api->pageSizeParam !== null) {
                    $query[$api->pageSizeParam] = $api->pageSize;
                }
            }
        }

        $encodedBody = $this->encodeBody($api->method, $body, $api->bodyFormat);

        /** @var array<string,scalar> $query */
        return [$url, $encodedBody, $query];
    }

    /**
     * Surfaces why a page yielded nothing — only for the first page, to STDERR,
     * so a misconfigured endpoint/items_path or an anti-bot block is obvious
     * instead of silently producing 0 items.
     */
    private function diagnose(int $pageIndex, string $message): void
    {
        if ($pageIndex === 0) {
            fwrite(STDERR, 'API: ' . $message . PHP_EOL);
        }
    }

    /**
     * @param array<string,mixed> $body
     */
    private function encodeBody(string $method, array $body, string $format): ?string
    {
        if (strtoupper($method) === 'GET') {
            return null;
        }

        // Some POST APIs (e.g. Sublime GetLotes) require a JSON body even when
        // empty — sending no body returns 400.
        if ($body === []) {
            return $format === 'form' ? '' : '{}';
        }

        return $format === 'form'
            ? http_build_query($body)
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function mergeDetail(
        ScrapedItem $item,
        ApiDetailConfig $detail,
        JsonItemExtractor $detailExtractor,
        CrawlStats $stats,
    ): void {
        $url = $this->fillTemplate($detail->endpoint, $item);
        if ($url === '' || str_contains($url, '{')) {
            return; // Unresolved placeholder — skip.
        }

        $body = $this->encodeBody($detail->method, $detail->body, $detail->bodyFormat);

        try {
            $raw     = $this->http->request($detail->method, $url, $detail->headers, $body, $detail->query);
            $decoded = json_decode($raw, true);
            $stats->detailFetched++;
        } catch (\Throwable) {
            $stats->detailFailed++;

            return;
        }

        if (! is_array($decoded)) {
            return;
        }

        $node = JsonPath::get($decoded, $detail->itemsPath);
        if (! is_array($node)) {
            return;
        }

        foreach ($detailExtractor->extract($node)->toArray() as $key => $value) {
            $item->set($key, $value);
        }
    }

    /**
     * Replaces {field} placeholders in a URL template with item field values.
     */
    private function fillTemplate(string $template, ScrapedItem $item): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            static function (array $m) use ($item): string {
                $value = $item->get($m[1]);

                return is_scalar($value) ? rawurlencode((string) $value) : $m[0];
            },
            $template,
        );
    }

    private function resolveTotal(mixed $decoded, ApiConfig $api): ?int
    {
        if ($api->totalPath === null) {
            return null;
        }

        $total = JsonPath::get($decoded, $api->totalPath);

        return is_numeric($total) ? (int) $total : null;
    }
}
