<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\ApiConfig;
use DataHelm\Crawler\Blueprint\BlueprintBuilder;
use DataHelm\Crawler\Blueprint\CrawlConfig;
use DataHelm\Crawler\Blueprint\CrawlMode;
use DataHelm\Crawler\Blueprint\DedupConfig;
use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Blueprint\HttpConfig;
use DataHelm\Crawler\Blueprint\ImageResizeConfig;
use DataHelm\Crawler\Blueprint\InfiniteScrollConfig;
use DataHelm\Crawler\Blueprint\OutputConfig;
use DataHelm\Crawler\Blueprint\PaginationStrategy;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Dom\Page;
use DataHelm\Crawler\Dom\Url;
use DataHelm\Crawler\Http\AutoHttpClient;
use DataHelm\Crawler\Http\BrowserHttpClient;
use DataHelm\Crawler\Http\CachedHttpClient;
use DataHelm\Crawler\Http\GuzzleHttpClient;
use DataHelm\Crawler\Http\HttpClient;
use DataHelm\Crawler\Http\HttpRequester;
use DataHelm\Crawler\Scraping\ItemExtractor;
use DataHelm\Crawler\Scraping\JsonPath;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Step 1 — inspects a URL and produces a best-effort {@see ScrapeBlueprint}.
 *
 * It mediates the detection strategies (list, pagination, fields) and never
 * needs to know how any of them work; new field detectors can be injected
 * without touching this class (Open/Closed).
 */
final class BlueprintGenerator
{
    /**
     * @param list<FieldDetector> $fieldDetectors
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ListDetector $listDetector,
        private readonly PaginationDetector $paginationDetector,
        private readonly array $fieldDetectors,
        private readonly GalleryFieldDetector $galleryDetector = new GalleryFieldDetector(),
        private readonly LabeledFieldDetector $labeledDetector = new LabeledFieldDetector(),
        private readonly SpaDetector $spaDetector = new SpaDetector(),
        private readonly JsonStructureDetector $jsonDetector = new JsonStructureDetector(),
        private readonly ApiFieldScaffolder $apiScaffolder = new ApiFieldScaffolder(),
        private readonly BotProtectionDetector $botDetector = new BotProtectionDetector(),
        /** @var list<string> Keywords that hint at a data endpoint (see DEFAULT_ENDPOINT_HINTS). */
        private readonly array $endpointHints = self::DEFAULT_ENDPOINT_HINTS,
    ) {
    }

    /**
     * Diagnostic notes emitted during the last generate() call (SPA detection,
     * endpoint probing, …) so the command can surface them to the user.
     *
     * @var list<string>
     */
    private array $notes = [];

    /**
     * API endpoints discovered during the last API attempt (page HTML + scripts),
     * surfaced as suggestions when a JS/AJAX page can't be auto-scraped.
     *
     * @var list<string>
     */
    private array $discoveredEndpoints = [];

    /**
     * Language-neutral keywords that hint at a data/listing endpoint (or the
     * script file that defines it). Used ONLY to prioritise which scripts to
     * scan and which candidates to surface first — never to filter — so
     * detection still works on any site regardless of language or domain.
     *
     * Extend per domain/locale via the `endpoint_hints` preset key in
     * config/crawler.php (e.g. the `auctions` preset adds lot/leilão terms).
     *
     * @var list<string>
     */
    public const DEFAULT_ENDPOINT_HINTS = [
        'api', 'ajax', 'json', 'data', 'list', 'search', 'query', 'result', 'results',
        'item', 'items', 'feed', 'graphql', 'rest', 'catalog', 'grid', 'page', 'product', 'records',
    ];

    /**
     * @param list<string> $allowedFields       When non-empty, only fields whose name
     *                                          appears in this list are kept in the blueprint.
     *                                          Useful to quickly prune unwanted auto-detected fields.
     *                                          e.g. ['title', 'link', 'price', 'image']
     * @param bool         $includeLabeledFields When false the LabeledFieldDetector is skipped.
     *                                          Useful for sites where the "labeled" heuristic picks
     *                                          up irrelevant text (nav labels, breadcrumbs, …).
     */
    public function generate(
        string $url,
        bool $withDetail = false,
        int $maxPages = 0,
        bool $getAllImages = false,
        bool $getPrimaryImage = false,
        bool $getGalleryImages = false,
        bool $hashNames = false,
        string $imageDisk = 'storage',
        ImageResizeConfig $imageResize = new ImageResizeConfig(),
        HttpConfig $httpConfig = new HttpConfig(),
        CrawlConfig $crawlConfig = new CrawlConfig(),
        OutputConfig $outputConfig = new OutputConfig(),
        DedupConfig $dedup = new DedupConfig(),
        ?string $imageFolder = null,
        ?string $apiEndpoint = null,
        string $apiMethod = 'GET',
        ?string $apiItemsPath = null,
        array $allowedFields = [],
        bool $includeLabeledFields = true,
    ): ScrapeBlueprint {
        $this->notes               = [];
        $this->discoveredEndpoints = [];

        // Detection fetches with the same HTTP settings the user chose (timeout,
        // user-agent, render-js, …) so --http-timeout also bounds generation —
        // a dead/slow site fails fast instead of using the 60s default.
        $this->configureHttp($httpConfig);

        $html = $this->fetchOrExplainBlock($url);
        $page = Page::fromHtml($url, $html);

        $list = $apiEndpoint === null ? $this->listDetector->detect($page) : null;

        // A detected list that carries no images on a script-heavy page is almost
        // always navigation/footer chrome — the real listing is JS/AJAX-rendered.
        // Treat it as a SPA so we route to API mode (and warn) instead of emitting
        // a junk robot built from the menu.
        $looksLikeSpa = $list !== null && ! $this->looksLikeRealList($page, $list, $html);
        if ($looksLikeSpa) {
            $this->notes[] = 'The only repeating list in the static HTML looks like navigation, '
                . 'not content — treating this page as JavaScript/AJAX-rendered.';
            $list = null;
        }

        // No HTML list (or API explicitly requested) → try JSON/API mode.
        if ($list === null) {
            $apiBlueprint = $this->tryGenerateApi(
                $url,
                $html,
                $page,
                $apiEndpoint,
                $apiMethod,
                $apiItemsPath,
                $withDetail,
                $maxPages,
                $getAllImages,
                $getPrimaryImage,
                $getGalleryImages,
                $hashNames,
                $imageDisk,
                $imageResize,
                $httpConfig,
                $crawlConfig,
                $outputConfig,
                $dedup,
                $imageFolder,
            );

            if ($apiBlueprint !== null) {
                return $apiBlueprint;
            }

            if ($looksLikeSpa) {
                $suggestions = '';
                if ($this->discoveredEndpoints !== []) {
                    // Surface hint-matching endpoints (list/search/items/…) first.
                    $ranked = $this->discoveredEndpoints;
                    $re     = $this->endpointHintPattern();
                    usort($ranked, static function (string $a, string $b) use ($re): int {
                        return (int) (bool) preg_match($re, $b) <=> (int) (bool) preg_match($re, $a);
                    });

                    $listText = implode("\n  • ", array_slice($ranked, 0, 10));
                    $suggestions = "\n\nCandidate endpoints found in the page scripts "
                        . "(verify the method/params in the Network tab, then pass one):\n  • {$listText}";
                }

                throw new JavaScriptRenderedException(
                    "The static HTML at {$url} has no real content list — the only repeating markup "
                    . 'is navigation/footer, so this page is JavaScript/AJAX-rendered. Open it in your '
                    . "browser's Network tab (Fetch/XHR), find the request that returns the items, then "
                    . 're-run with --api-endpoint=<url> (plus --api-items-path / --api-method). '
                    . 'Alternatively enable a render-js (headless browser) transport.'
                    . $suggestions,
                );
            }

            throw new \RuntimeException(
                "Could not detect a repeating item list at {$url}. "
                . 'If this is a JavaScript site backed by a JSON API, pass --api-endpoint=<url> '
                . '(and --api-items-path / --api-method) to generate an API-mode blueprint.',
            );
        }

        $pagination = $this->paginationDetector->detect($page);

        $builder = BlueprintBuilder::make()
            ->url($url)
            ->itemSelector($list['itemSelector'])
            ->pagination($pagination)
            ->maxPages($maxPages)
            ->getAllImages($getAllImages)
            ->getPrimaryImage($getPrimaryImage)
            ->getGalleryImages($getGalleryImages)
            ->hashNames($hashNames)
            ->imageDisk($imageDisk)
            ->imageFolder($imageFolder)
            ->imageResize($imageResize)
            ->httpConfig($httpConfig)
            ->crawlConfig($crawlConfig)
            ->outputConfig($outputConfig)
            ->dedup($dedup);

        if ($pagination->strategy === PaginationStrategy::INFINITE_SCROLL) {
            $builder->infiniteScroll($this->scaffoldInfiniteScroll($url, $page));
            $this->notes[] = 'Detected an infinite-scroll button (' . $pagination->css . ').';
            $this->notes[] = 'An infinite_scroll block was scaffolded — set its endpoint/param/page_size to the '
                . 'request the button fires (check the network tab) before running the robot.';
        }

        $fields = $this->detectFields($list['sample'], $includeLabeledFields);
        $fields = $this->pruneFields($fields, $allowedFields);
        foreach ($fields as $field) {
            $builder->addField($field);
        }

        if ($withDetail && isset($fields['link'])) {
            $builder->scrapeDetail(true);
            $this->detectDetail(
                $builder,
                $url,
                $list['sample'],
                $fields['link'],
                $allowedFields,
                $includeLabeledFields,
                $getGalleryImages,
                $getAllImages,
            );
        }

        return $builder->build();
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Candidate API endpoints discovered during the last generate() call. Empty
     * unless the page was a SPA whose listing wasn't in the static HTML. Lets the
     * command offer the user a "which endpoint?" choice instead of giving up.
     *
     * @return list<string>
     */
    public function discoveredEndpoints(): array
    {
        return $this->discoveredEndpoints;
    }

    /**
     * Fetch the page, but translate an anti-bot block into a
     * {@see BotProtectionException} so the command can give actionable guidance.
     * Other failures (DNS, timeout, …) bubble up unchanged.
     */
    private function fetchOrExplainBlock(string $url): string
    {
        try {
            $html = $this->http->get($url);
        } catch (BotProtectionException $e) {
            // The auto transport already escalated and explained the block.
            $this->mergeTransportNotes();
            throw $e;
        } catch (\Throwable $e) {
            $block = $this->botDetector->fromThrowable($e);
            if ($block === null) {
                throw $e;
            }

            throw $this->botProtectionException($url, $block['vendor'], $block['status'], $e);
        }

        $this->mergeTransportNotes();

        // A headless-browser transport returns the block page with HTTP 200, so
        // the throw above never fires — inspect the rendered HTML too.
        $vendor = $this->botDetector->detectRendered($html);
        if ($vendor !== null) {
            throw $this->botProtectionException($url, $vendor, null);
        }

        return $html;
    }

    /**
     * The transport (auto-escalation) the http client settled on for the last
     * generate(), or null when a fixed transport was used. Lets the command bake
     * the winning transport into the blueprint so the robot skips escalation.
     */
    public function resolvedTransport(): ?string
    {
        return $this->http instanceof AutoHttpClient ? $this->http->getResolvedTransport() : null;
    }

    /** Fold the auto transport's escalation trace into the generator notes. */
    private function mergeTransportNotes(): void
    {
        if ($this->http instanceof AutoHttpClient) {
            foreach ($this->http->notes() as $note) {
                $this->notes[] = $note;
            }
        }
    }

    private function botProtectionException(string $url, string $vendor, ?int $status, ?\Throwable $previous = null): BotProtectionException
    {
        $statusText = $status !== null ? " (HTTP {$status})" : '';

        // $previous set ⇒ the request threw (plain HTTP transport): suggest the
        // browser transport first. No $previous ⇒ the block came back through the
        // browser transport already, so the next levers are proxies / API capture.
        $options = $previous !== null
            ? 'A plain HTTP client cannot pass this check. Options: enable a render-js (headless '
                . "browser) transport — the browser's real TLS fingerprint and JS execution often "
                . 'pass the check; route through residential proxies (the firewall also scores IP '
                . 'reputation); or capture the JSON API from your browser\'s Network tab and re-run '
                . 'with --api-endpoint=<url>.'
            : 'Even the headless browser was blocked — this firewall also scores IP reputation, so a '
                . 'datacenter IP is flagged regardless of the browser. Options: route the browser '
                . 'through residential proxies; or open the page in your own browser\'s Network tab '
                . '(Fetch/XHR), copy the request that returns the items, and re-run with '
                . '--api-endpoint=<url> (plus --api-items-path / --api-method).';

        return new BotProtectionException(
            $vendor,
            $status,
            $url,
            "The page at {$url} is protected by {$vendor}{$statusText}, so it couldn't be fetched "
            . "and endpoint auto-detection has nothing to work with. {$options}",
            previous: $previous,
        );
    }

    /**
     * Best-effort skeleton for an infinite-scroll endpoint. We cannot see the
     * XHR the button fires from static HTML, so the endpoint/param/page_size are
     * left as placeholders for the user, but we pre-fill a CSRF token selector
     * when a recognisable token element is present on the page.
     */
    private function scaffoldInfiniteScroll(string $url, Page $page): InfiniteScrollConfig
    {
        $token = [];
        $crawler = $page->crawler();

        try {
            if ($crawler->filter('input[name="_token"]')->count() > 0) {
                $token = ['css' => 'input[name="_token"]', 'attribute' => 'value', 'param' => '_token'];
            } elseif ($crawler->filter('meta[name="csrf-token"]')->count() > 0) {
                $token = ['css' => 'meta[name="csrf-token"]', 'attribute' => 'content', 'param' => '_token'];
            }
        } catch (\Throwable) {
            // no token detected
        }

        return InfiniteScrollConfig::fromArray([
            'endpoint'        => '',
            'method'          => 'POST',
            'body_format'     => 'form',
            'param'           => 'page',
            'param_mode'      => 'offset',
            'page_size'       => 20,
            'start'           => 20,
            'params'          => [],
            'token'           => $token,
            'stop_when_empty' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // API / JavaScript-site generation
    // -------------------------------------------------------------------------

    /**
     * Builds an API-mode blueprint by probing for (or using a supplied) JSON
     * endpoint, auto-detecting the items array and scaffolding json fields.
     * Returns null when no usable endpoint could be found.
     */
    private function tryGenerateApi(
        string $url,
        string $html,
        Page $page,
        ?string $apiEndpoint,
        string $apiMethod,
        ?string $apiItemsPath,
        bool $withDetail,
        int $maxPages,
        bool $getAllImages,
        bool $getPrimaryImage,
        bool $getGalleryImages,
        bool $hashNames,
        string $imageDisk,
        ImageResizeConfig $imageResize,
        HttpConfig $httpConfig,
        CrawlConfig $crawlConfig,
        OutputConfig $outputConfig,
        DedupConfig $dedup,
        ?string $imageFolder,
    ): ?ScrapeBlueprint {
        $visibleText = trim($page->crawler()->filter('body')->count() > 0 ? $page->crawler()->filter('body')->text('', true) : '');
        if ($this->spaDetector->isSpa($html, strlen($visibleText))) {
            $this->notes[] = 'Detected a JavaScript SPA — switching to API mode.';
        }

        // Discovery below (external-script scan + endpoint probing) is best-effort
        // and can fire many requests, so bound it with a short timeout and no
        // retries — a slow or blocking site must never stall generation. The user's
        // full httpConfig is still what gets baked into the generated blueprint.
        $this->configureHttp(new HttpConfig(
            timeout:        max(1, min(10, $httpConfig->timeout ?: 10)),
            delayMs:        0,
            retryCount:     0,
            retryDelayMs:   $httpConfig->retryDelayMs,
            userAgent:      $httpConfig->userAgent,
            headers:        $httpConfig->headers,
            proxies:        $httpConfig->proxies,
            cookies:        $httpConfig->cookies,
            renderJs:       $httpConfig->renderJs,
            browserWaitFor: $httpConfig->browserWaitFor,
        ));

        $candidates = $apiEndpoint !== null
            ? [$apiEndpoint]
            : $this->spaDetector->candidateEndpoints($url, $html, $this->collectScriptSources($url, $page));

        // Remember what we found (even if probing them all fails) so the caller
        // can suggest them to the user instead of a bare "go find it yourself".
        if ($apiEndpoint === null) {
            $this->discoveredEndpoints = $candidates;
        }

        if ($candidates === [] && $apiEndpoint === null) {
            return null;
        }

        // Probe a bounded number so dozens of discovered URLs can't stall us.
        foreach (array_slice($candidates, 0, 12) as $endpoint) {
            $this->notes[] = "Probing API endpoint: {$endpoint}";

            $decoded = $this->fetchJson($endpoint, $apiMethod);
            if ($decoded === null) {
                continue;
            }

            $structure = $apiItemsPath !== null
                ? ['path' => $apiItemsPath, 'sample' => $this->sampleAt($decoded, $apiItemsPath), 'count' => 0]
                : $this->jsonDetector->detect($decoded);

            if ($structure === null || $structure['sample'] === []) {
                continue;
            }

            $this->notes[] = sprintf(
                'Found %d records at "%s"; scaffolded %d field(s).',
                $structure['count'],
                $structure['path'] === '' ? '(root)' : $structure['path'],
                count($structure['sample']),
            );

            return $this->buildApiBlueprint(
                $url,
                $endpoint,
                $apiMethod,
                $structure['path'],
                $structure['sample'],
                $withDetail,
                $maxPages,
                $getAllImages,
                $getPrimaryImage,
                $getGalleryImages,
                $hashNames,
                $imageDisk,
                $imageResize,
                $httpConfig,
                $crawlConfig,
                $outputConfig,
                $dedup,
                $imageFolder,
            );
        }

        // We found/were given an endpoint but couldn't auto-detect its shape
        // (e.g. it needs a POST body). Scaffold a skeleton for the user to finish.
        if ($apiEndpoint !== null) {
            $this->notes[] = 'Could not auto-detect the JSON shape — scaffolding a skeleton api block to complete by hand.';

            return $this->buildApiBlueprint(
                $url,
                $apiEndpoint,
                $apiMethod,
                $apiItemsPath ?? '',
                [],
                $withDetail,
                $maxPages,
                $getAllImages,
                $getPrimaryImage,
                $getGalleryImages,
                $hashNames,
                $imageDisk,
                $imageResize,
                $httpConfig,
                $crawlConfig,
                $outputConfig,
                $dedup,
                $imageFolder,
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $sample
     */
    private function buildApiBlueprint(
        string $url,
        string $endpoint,
        string $method,
        string $itemsPath,
        array $sample,
        bool $withDetail,
        int $maxPages,
        bool $getAllImages,
        bool $getPrimaryImage,
        bool $getGalleryImages,
        bool $hashNames,
        string $imageDisk,
        ImageResizeConfig $imageResize,
        HttpConfig $httpConfig,
        CrawlConfig $crawlConfig,
        OutputConfig $outputConfig,
        DedupConfig $dedup,
        ?string $imageFolder,
    ): ScrapeBlueprint {
        $pagination = $this->normalizeApiEndpoint($endpoint);
        $isPost     = strtoupper($method) === 'POST';

        $api = new ApiConfig(
            endpoint:      $pagination['endpoint'],
            method:        strtoupper($method),
            headers:       $isPost ? ['Content-Type' => 'application/json', 'Accept' => 'application/json'] : [],
            body:          $isPost ? [] : [],
            itemsPath:     $itemsPath,
            totalPath:     null,
            pageParam:     $pagination['pageParam'],
            pageSizeParam: $pagination['pageSizeParam'],
            pageSize:      $pagination['pageSize'],
            startPage:     $pagination['startPage'],
        );

        if ($pagination['pathPagination']) {
            $this->notes[] = 'Endpoint uses path pagination ({page}/{page_size}) — page_param was disabled.';
        }

        $builder = BlueprintBuilder::make()
            ->url($url)
            ->mode(CrawlMode::API)
            ->api($api)
            ->itemSelector('')
            ->maxPages($maxPages)
            ->getAllImages($getAllImages)
            ->getPrimaryImage($getPrimaryImage)
            ->getGalleryImages($getGalleryImages)
            ->hashNames($hashNames)
            ->imageDisk($imageDisk)
            ->imageFolder($imageFolder)
            ->imageResize($imageResize)
            ->httpConfig($httpConfig)
            ->crawlConfig($crawlConfig)
            ->outputConfig($outputConfig)
            ->dedup($dedup);

        foreach ($this->apiScaffolder->scaffold($sample) as $field) {
            $builder->addField($field);
        }

        if ($withDetail) {
            // Detail endpoints are site-specific; leave a disabled skeleton the
            // user can point at e.g. https://site/public/data/details/{link}.
            $builder->scrapeDetail(true);
        }

        return $builder->build();
    }

    /**
     * Case-insensitive regex built from the configured endpoint hints. Used to
     * rank (not filter) scripts and candidate endpoints, so an empty/odd hint
     * list never breaks detection.
     */
    private function endpointHintPattern(): string
    {
        $hints = $this->endpointHints !== [] ? $this->endpointHints : self::DEFAULT_ENDPOINT_HINTS;
        $alt   = implode('|', array_map(static fn (string $h) => preg_quote($h, '#'), $hints));

        return "#(?:{$alt})#i";
    }

    /**
     * Fetch the page's same-origin <script src> files and return their combined
     * source, so candidate endpoint discovery can spot XHR/API URLs that live in
     * external JS rather than the page HTML. Bounded in count and size to stay
     * fast; third-party libraries and obvious vendor bundles are skipped.
     */
    private function collectScriptSources(string $baseUrl, Page $page): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        $srcs = [];
        try {
            $page->crawler()->filter('script[src]')->each(function (Crawler $node) use (&$srcs): void {
                $src = $node->attr('src');
                if (is_string($src) && $src !== '') {
                    $srcs[] = $src;
                }
            });
        } catch (\Throwable) {
            return '';
        }

        // Fetch hint-matching scripts first (api.js, ajax-*.js, …) so the file
        // carrying the listing endpoint is scanned before the cap is hit.
        $re = $this->endpointHintPattern();
        usort($srcs, static function (string $a, string $b) use ($re): int {
            return (int) (bool) preg_match($re, $b) <=> (int) (bool) preg_match($re, $a);
        });

        $combined = '';
        $fetched  = 0;

        foreach ($srcs as $src) {
            if ($fetched >= 6) {
                break;
            }

            $abs = Url::absolute($baseUrl, $src);
            if ($abs === null || ! Url::isFetchable($abs) || parse_url($abs, PHP_URL_HOST) !== $host) {
                continue; // third-party (jQuery CDN, analytics, …) won't carry the site API
            }

            // Skip well-known vendor bundles to save time and noise.
            if (preg_match('#/(?:jquery|jquery-ui|bootstrap|slick|fancybox|modernizr|popper|select2|moment|lodash)[.\-]#i', $abs)) {
                continue;
            }

            try {
                $combined .= "\n" . substr($this->http->get($abs), 0, 800000);
                $fetched++;
            } catch (\Throwable) {
                // unreachable script — ignore
            }
        }

        return $combined;
    }

    /**
     * Heuristic: does the detected list look like genuine content rather than
     * site chrome (nav / footer menus)?
     *
     * Real listing cards almost always carry a thumbnail, so any image among the
     * matched items counts as content. When NO item has an image and the page is
     * script-heavy, the match is almost certainly navigation and the real list is
     * JavaScript/AJAX-rendered — the caller should switch to API mode.
     *
     * @param array{itemSelector:string,sample:\DOMElement} $list
     */
    private function looksLikeRealList(Page $page, array $list, string $html): bool
    {
        try {
            $items = $page->crawler()->filter($list['itemSelector']);
        } catch (\Throwable) {
            return true; // can't assess — don't interfere with detection
        }

        if ($items->count() === 0) {
            return true;
        }

        $withImage = 0;
        $items->each(function (Crawler $node) use (&$withImage): void {
            try {
                if ($node->filter('img')->count() > 0) {
                    $withImage++;
                }
            } catch (\Throwable) {
                // ignore malformed nodes
            }
        });

        if ($withImage > 0) {
            return true;
        }

        // No images anywhere in the matched list. Only trust an image-less list on
        // largely static pages; on script-heavy pages it is almost certainly chrome.
        return substr_count(strtolower($html), '<script') < 3;
    }

    /**
     * Apply the chosen HTTP settings to the underlying client before detection,
     * mirroring how CrawlEngine reconfigures per crawl (handles the cache wrapper).
     */
    private function configureHttp(HttpConfig $config): void
    {
        $http = $this->http instanceof CachedHttpClient ? $this->http->getInner() : $this->http;

        if ($http instanceof GuzzleHttpClient || $http instanceof BrowserHttpClient) {
            $http->configure($config);
        }
    }

    private function fetchJson(string $endpoint, string $method): mixed
    {
        try {
            if (strtoupper($method) === 'GET') {
                $raw = $this->http->get($endpoint);
            } elseif ($this->http instanceof HttpRequester) {
                $raw = $this->http->request(
                    'POST',
                    $endpoint,
                    ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    '{}',
                    [],
                );
            } else {
                return null;
            }

            $decoded = json_decode($raw, true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Detects endpoints like .../GetLotes/1/20 and converts them to
     * .../GetLotes/{page}/{page_size} for path-based API pagination.
     *
     * @return array{endpoint:string,startPage:int,pageSize:int,pageParam:?string,pageSizeParam:?string,pathPagination:bool}
     */
    private function normalizeApiEndpoint(string $endpoint): array
    {
        if (preg_match('#^(.*)/(\d+)/(\d+)/?$#', $endpoint, $m)) {
            return [
                'endpoint'        => $m[1] . '/{page}/{page_size}',
                'startPage'       => (int) $m[2],
                'pageSize'        => (int) $m[3],
                'pageParam'       => null,
                'pageSizeParam'   => null,
                'pathPagination'  => true,
            ];
        }

        return [
            'endpoint'        => $endpoint,
            'startPage'       => 0,
            'pageSize'        => 100,
            'pageParam'       => 'page',
            'pageSizeParam'   => 'size',
            'pathPagination'  => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleAt(mixed $decoded, string $path): array
    {
        $node = JsonPath::get($decoded, $path);
        if (is_array($node) && array_is_list($node) && isset($node[0]) && is_array($node[0])) {
            return $node[0];
        }

        return is_array($node) ? $node : [];
    }

    /**
     * Semantic single-value fields (link/title/image/price) first, then every
     * "label: value" field found in the row — so the blueprint lists as many
     * candidates as possible for the user to keep or prune.
     *
     * @return array<string,FieldSelector>
     */
    /**
     * @return array<string,FieldSelector>
     */
    private function detectFields(\DOMElement $sample, bool $includeLabeledFields = true): array
    {
        $fields = [];
        foreach ($this->fieldDetectors as $detector) {
            $field = $detector->detect($sample);
            if ($field !== null) {
                $fields[$field->name] = $field;
            }
        }

        if ($includeLabeledFields) {
            foreach ($this->labeledDetector->detect($sample) as $field) {
                $fields[$field->name] ??= $field;
            }
        }

        return $fields;
    }

    /**
     * Keep only fields whose names appear in $allowedFields.
     * When $allowedFields is empty, all fields are kept.
     *
     * @param array<string,FieldSelector> $fields
     * @param list<string>                $allowedFields
     *
     * @return array<string,FieldSelector>
     */
    private function pruneFields(array $fields, array $allowedFields): array
    {
        if ($allowedFields === []) {
            return $fields;
        }

        $allowed = array_flip($allowedFields);

        return array_filter($fields, static fn (string $name): bool => isset($allowed[$name]), ARRAY_FILTER_USE_KEY);
    }

    private function detectDetail(
        BlueprintBuilder $builder,
        string $baseUrl,
        \DOMElement $sample,
        FieldSelector $linkField,
        array $allowedFields = [],
        bool $includeLabeledFields = true,
        bool $getGalleryImages = false,
        bool $getAllImages = false,
    ): void {
        $href = (new ItemExtractor([$linkField]))->extract(new Crawler($sample))->get('link');
        $detailUrl = Url::absolute($baseUrl, is_string($href) ? $href : null);
        if ($detailUrl === null || ! Url::isFetchable($detailUrl)) {
            return;
        }

        try {
            $detailPage = Page::fromHtml($detailUrl, $this->http->get($detailUrl));
        } catch (\Throwable) {
            return;
        }

        $builder->detailLinkField('link');

        $root = $detailPage->document()->documentElement;
        if ($root === null) {
            return;
        }

        // Natural field names (no "detail_" prefix) so a field that exists in both
        // the list and the detail shows up under the same name in each section —
        // the user keeps it in whichever place they want and removes the other.
        $added = [];
        foreach ($this->fieldDetectors as $detector) {
            $field = $detector->detect($root);
            if ($field === null) {
                continue;
            }
            // The link is the list-page anchor, and the single thumbnail is replaced
            // by the multi-image gallery below; keep only descriptive detail fields.
            if (in_array($field->name, ['link', 'image'], true)) {
                continue;
            }
            $builder->addDetailField($field);
            $added[$field->name] = true;
        }

        // Every "label: value" fact on the detail page (1ª Praça, Comitente, …).
        if ($includeLabeledFields) {
            foreach ($this->labeledDetector->detect($root) as $field) {
                if (! isset($added[$field->name]) && ($allowedFields === [] || in_array($field->name, $allowedFields, true))) {
                    $builder->addDetailField($field);
                    $added[$field->name] = true;
                }
            }
        }

        // Detail pages usually carry several photos — capture as "gallery_images".
        if ($getGalleryImages || $getAllImages) {
            $gallery = $this->galleryDetector->detect($root);
            if ($gallery !== null) {
                $builder->addDetailField($gallery->renamed('gallery_images'));
            }
        }
    }
}
