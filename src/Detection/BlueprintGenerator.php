<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\ApiConfig;
use DataHelm\Crawler\Blueprint\BlueprintBuilder;
use DataHelm\Crawler\Blueprint\CrawlConfig;
use DataHelm\Crawler\Blueprint\CrawlMode;
use DataHelm\Crawler\Blueprint\DedupConfig;
use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Blueprint\HttpConfig;
use DataHelm\Crawler\Blueprint\InfiniteScrollConfig;
use DataHelm\Crawler\Blueprint\OutputConfig;
use DataHelm\Crawler\Blueprint\PaginationSelector;
use DataHelm\Crawler\Blueprint\PaginationStrategy;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Dom\Page;
use DataHelm\Crawler\Dom\Selector;
use DataHelm\Crawler\Dom\Url;
use DataHelm\Crawler\Http\AutoHttpClient;
use DataHelm\Crawler\Http\CachedHttpClient;
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
        private readonly VtexDetector $vtexDetector = new VtexDetector(),
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
     * Item count at or above which a list found in the static HTML is trusted as
     * the real listing. Below it, a JS-rendered page is rendered + network-sniffed
     * in case the static markup was only a placeholder/decoy grid, and a data API
     * returning at least this many records is preferred over the static list.
     */
    private const TRUSTED_LIST_MIN = 8;

    /**
     * @param list<string> $allowedFields       When non-empty, only fields whose name
     *                                          appears in this list are kept in the blueprint.
     *                                          Useful to quickly prune unwanted auto-detected fields.
     *                                          e.g. ['title', 'link', 'price', 'image']
     * @param bool         $includeLabeledFields When false the LabeledFieldDetector is skipped.
     *                                          Useful for sites where the "labeled" heuristic picks
     *                                          up irrelevant text (nav labels, breadcrumbs, …).
     * @param bool         $singlePage          Treat the URL as one record instead of a list —
     *                                          skip list/SPA/API detection and run the field
     *                                          detectors against the whole page.
     * @param bool         $mainContent         With $singlePage, scope detection to the page's
     *                                          primary content region (nav/footer excluded).
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
        bool $singlePage = false,
        bool $mainContent = false,
    ): ScrapeBlueprint {
        $this->notes               = [];
        $this->discoveredEndpoints = [];

        // Detection fetches with the same HTTP settings the user chose (timeout,
        // user-agent, render-js, …) so --http-timeout also bounds generation —
        // a dead/slow site fails fast instead of using the 60s default.
        $this->configureHttp($httpConfig);

        $html = $this->fetchOrExplainBlock($url);
        $page = Page::fromHtml($url, $html);

        // Single-page mode: the URL is one record (an article, a profile, a
        // one-off dashboard page), not a list. Skip list/SPA/API detection
        // entirely — there is nothing repeating to find — and run the same
        // field detectors directly against <body>, exactly as they would run
        // against one list item. item_selector: "body" + pagination: none is
        // all CrawlEngine needs; no engine changes required.
        if ($singlePage) {
            return $this->buildSinglePageBlueprint(
                $url,
                $page,
                $getAllImages,
                $getPrimaryImage,
                $getGalleryImages,
                $hashNames,
                $imageDisk,
                $imageFolder,
                $httpConfig,
                $crawlConfig,
                $outputConfig,
                $dedup,
                $allowedFields,
                $includeLabeledFields,
                $mainContent,
            );
        }

        // VTEX storefronts render from a known catalog API; HTML detection finds
        // nothing and generic probing grabs the cart endpoint. Recognise the
        // platform and emit the right endpoint + fields directly. (An explicit
        // --api-endpoint always wins, so the user can still override.)
        if ($apiEndpoint === null && $this->vtexDetector->looksLikeVtex($html)) {
            return $this->buildVtexBlueprint(
                $url,
                $withDetail,
                $maxPages,
                $getAllImages,
                $getPrimaryImage,
                $getGalleryImages,
                $hashNames,
                $imageDisk,
                $httpConfig,
                $crawlConfig,
                $outputConfig,
                $dedup,
                $imageFolder,
            );
        }

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

        // JS-app recovery + API auto-discovery. When the static HTML gave us no
        // list (a SPA) — or only a small, likely-placeholder one on a page built
        // by a client-side framework — render it once in a headless browser and
        // either (a) auto-detect the site's data API from the JSON it fetches, or
        // (b) fall back to the rendered DOM. This makes `--transport=auto` handle
        // Next/Nuxt/Gatsby/React sites with no manual --api-endpoint or --render-js.
        $htmlItemCount = $list !== null ? $this->itemCount($page, $list) : 0;

        if (
            $apiEndpoint === null
            && ! $httpConfig->renderJs
            && ($list === null || $htmlItemCount < self::TRUSTED_LIST_MIN)
            && $this->http instanceof AutoHttpClient
            && $this->http->canRenderJs()
            && ($looksLikeSpa || $this->spaDetector->looksJsRendered($html, $this->visibleTextLength($page)))
        ) {
            $capture = null;
            try {
                $capture = $this->http->renderAndCapture($url);
                $this->mergeTransportNotes();
            } catch (\Throwable $e) {
                $this->notes[] = 'Headless render/capture failed (' . $e->getMessage()
                    . ') — falling back to static detection.';
            }

            if ($capture !== null) {
                // What the page actually renders after JS — the ground truth for
                // "is this the content?" — plus the best re-fetchable JSON API it
                // called. A JS page often fires several data calls (facets, a
                // site-wide list, config), so we don't blindly trust the biggest
                // JSON; we compare it against the rendered DOM.
                $renderedPage = $capture['html'] !== '' ? Page::fromHtml($url, $capture['html']) : null;
                $renderedList = $renderedPage !== null ? $this->listDetector->detect($renderedPage) : null;
                $domReal      = $renderedList !== null
                    && $this->looksLikeRealList($renderedPage, $renderedList, $capture['html']);
                $domCount     = $domReal ? $this->itemCount($renderedPage, $renderedList) : 0;

                $api = $this->pickBestApiResponse($capture['responses']);

                // A DOM list built from CSS-in-JS hash classes (emotion/styled-
                // components) won't match at run time — those class names change
                // every build/render — so it can't be trusted even though it was
                // found now. Treat it as no usable DOM list and lean on the API.
                $domStable = $domReal && ! $this->selectorLooksUnstable($renderedList['itemSelector']);

                // Use the discovered API when the DOM gives us nothing reliable to
                // scrape, or when the API clearly represents more than the page
                // shows (a teaser grid + a full API). Otherwise trust the stable
                // rendered DOM — this avoids latching onto an unrelated secondary
                // endpoint the page merely happened to call (e.g. a site-wide list
                // on a category page whose own items load via a POST/auth call).
                $useApi = $api !== null
                    && $api['count'] >= self::TRUSTED_LIST_MIN
                    && (! $domStable || $api['count'] >= $domCount * 2);

                if ($useApi) {
                    $this->notes[] = sprintf(
                        "Auto-detected the site's data API from its network activity: %s "
                        . '(%d records) — building an API-mode robot.',
                        $api['endpoint'],
                        $api['count'],
                    );

                    return $this->buildApiBlueprint(
                        $url,
                        $api['endpoint'],
                        'GET',
                        $api['itemsPath'],
                        $api['sample'],
                        $withDetail,
                        $maxPages,
                        $getAllImages,
                        $getPrimaryImage,
                        $getGalleryImages,
                        $hashNames,
                        $imageDisk,
                        $httpConfig,
                        $crawlConfig,
                        $outputConfig,
                        $dedup,
                        $imageFolder,
                    );
                }

                // Otherwise trust the rendered DOM (render_js baked in). When we
                // also saw a comparable JSON API, point the user at it — an
                // API-mode robot is faster than rendering every page in a browser.
                if ($domReal) {
                    $this->notes[] = 'Headless re-render exposed a real content list — '
                        . 'building an HTML-mode robot (render_js baked in).';
                    if ($api !== null) {
                        $this->notes[] = sprintf(
                            'The page also calls a JSON API (%s, %d records/page). If it holds the '
                            . 'same items, re-run with --api-endpoint=%s for a faster API-mode robot.',
                            $api['endpoint'],
                            $api['count'],
                            $api['endpoint'],
                        );
                    }
                    $html       = $capture['html'];
                    $page       = $renderedPage;
                    $list       = $renderedList;
                    $httpConfig = $httpConfig->withRenderJs(true);
                } elseif ($list === null) {
                    $this->notes[] = 'Headless re-render showed no content list — '
                        . 'falling back to API-mode detection.';
                }
            }
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

    /**
     * Build a one-record blueprint for --single-page: the whole page (or its main
     * content region) is a single item. Runs the same field detectors used on a
     * list row against <body>, with pagination disabled and dedup off.
     *
     * @param list<string> $allowedFields
     */
    private function buildSinglePageBlueprint(
        string $url,
        Page $page,
        bool $getAllImages,
        bool $getPrimaryImage,
        bool $getGalleryImages,
        bool $hashNames,
        string $imageDisk,
        ?string $imageFolder,
        HttpConfig $httpConfig,
        CrawlConfig $crawlConfig,
        OutputConfig $outputConfig,
        DedupConfig $dedup,
        array $allowedFields,
        bool $includeLabeledFields,
        bool $mainContent = false,
    ): ScrapeBlueprint {
        $body = $page->document()->getElementsByTagName('body')->item(0);
        if ($body === null) {
            throw new \RuntimeException("Could not find a <body> element at {$url}.");
        }

        // --main-content: scope detection (and the item selector) to the page's
        // primary content region so global chrome (nav links, footer text) never
        // becomes a field. Falls back to <body> when no region is confidently
        // found, or when its selector isn't unique on the page (a non-unique
        // item_selector would turn one page into several items).
        $root     = $body;
        $selector = 'body';
        if ($mainContent) {
            $scoped = MainContentScope::locate($page);
            $css    = $scoped !== null ? Selector::cssFor($scoped) : '';

            if ($scoped !== null && $css !== '' && $this->matchesExactlyOne($page, $css)) {
                $root     = $scoped;
                $selector = $css;
                $this->notes[] = "Main-content scope: fields detected inside '{$css}' (site chrome excluded).";
            } else {
                $this->notes[] = 'Main-content scope: no unique content region found — using <body>.';
            }
        }

        $this->notes[] = 'Single-page mode: treating the whole page as one item '
            . "(item_selector \"{$selector}\", pagination disabled) instead of detecting a repeating list.";

        $fields = $this->detectFields($root, $includeLabeledFields);
        $fields = $this->pruneFields($fields, $allowedFields);

        // A whole-page record holds every image on the page, not one card
        // thumbnail. Collect them all as an array so the image resolver picks the
        // best real photo for primary_image (and fills all_images) instead of
        // latching onto whatever single <img> comes first — often a tracking pixel
        // or an icon.
        if (isset($fields['image']) && ($getPrimaryImage || $getAllImages || $getGalleryImages)) {
            $fields['image'] = new FieldSelector(name: 'image', css: 'img', attribute: 'src', multiple: true);
        }

        $builder = BlueprintBuilder::make()
            ->url($url)
            ->itemSelector($selector)
            ->pagination(PaginationSelector::none())
            ->getAllImages($getAllImages)
            ->getPrimaryImage($getPrimaryImage)
            ->getGalleryImages($getGalleryImages)
            ->hashNames($hashNames)
            ->imageDisk($imageDisk)
            ->imageFolder($imageFolder)
            ->httpConfig($httpConfig)
            ->crawlConfig($crawlConfig)
            ->outputConfig($outputConfig)
            ->dedup(new DedupConfig());

        foreach ($fields as $field) {
            $builder->addField($field);
        }

        return $builder->build();
    }

    /** True when the CSS selector matches exactly one element on the page. */
    private function matchesExactlyOne(Page $page, string $css): bool
    {
        try {
            return $page->crawler()->filter($css)->count() === 1;
        } catch (\Throwable) {
            return false;
        }
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
        HttpConfig $httpConfig,
        CrawlConfig $crawlConfig,
        OutputConfig $outputConfig,
        DedupConfig $dedup,
        ?string $imageFolder,
    ): ?ScrapeBlueprint {
        if ($this->spaDetector->isSpa($html, $this->visibleTextLength($page))) {
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
        // Merge with any endpoints already discovered from network capture.
        if ($apiEndpoint === null) {
            $this->discoveredEndpoints = array_values(array_unique([...$this->discoveredEndpoints, ...$candidates]));
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
     * Build an API blueprint for a VTEX storefront: the catalog product-search
     * endpoint (with a {search} placeholder filled per category by search_filters)
     * plus the standard VTEX product fields. No endpoint probing — the platform's
     * API shape is known and identical across stores.
     */
    private function buildVtexBlueprint(
        string $url,
        bool $withDetail,
        int $maxPages,
        bool $getAllImages,
        bool $getPrimaryImage,
        bool $getGalleryImages,
        bool $hashNames,
        string $imageDisk,
        HttpConfig $httpConfig,
        CrawlConfig $crawlConfig,
        OutputConfig $outputConfig,
        DedupConfig $dedup,
        ?string $imageFolder,
    ): ScrapeBlueprint {
        $this->notes[] = 'Detected a VTEX store — using the catalog_system product search API '
            . '({search} is filled per category by search_filters).';

        // VTEX's search response is already a full product document, so --get-detail
        // enriches the SAME record (description, brand, price, stock, …) rather than
        // making a second request. There is no separate detail page to scrape.
        $fields = $withDetail
            ? array_merge($this->vtexDetector->fields(), $this->vtexDetector->detailFields())
            : $this->vtexDetector->fields();

        if ($withDetail) {
            $this->notes[] = 'VTEX returns full product detail in one call — --get-detail adds '
                . 'detail fields (description, brand, list_price, available) to each item, no second request.';
        }

        $api = new ApiConfig(
            endpoint:      $this->vtexDetector->searchEndpoint($url),
            method:        'GET',
            itemsPath:     '',
            totalPath:     null,
            pageParam:     null,
            pageSizeParam: null,
            pageSize:      50,
            startPage:     0,
        );

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
            ->httpConfig($httpConfig)
            ->crawlConfig($crawlConfig)
            ->outputConfig($outputConfig)
            ->dedup($dedup);

        foreach ($fields as $name => $path) {
            $builder->addField(new FieldSelector(name: $name, css: $path, type: 'json'));
        }

        return $builder->build();
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
                if ($this->nodeHasImage($node)) {
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
     * True when the node carries a real photo. Component libraries (Quasar,
     * Vuetify, MUI, …) commonly render images as a `<picture>`, a
     * `role="img"` div, or a CSS `background-image` instead of a literal
     * `<img>` tag (e.g. Quasar's QImg), so a plain `img` check alone
     * false-negatives on those and misclassifies real content as nav chrome.
     */
    private function nodeHasImage(Crawler $node): bool
    {
        return $node->filter('img, picture, [role="img"], [style*="background-image"]')->count() > 0;
    }

    /**
     * Length of the page's visible body text — the SPA detector's "is there
     * content?" signal. Script/style/noscript bodies are stripped first:
     * DomCrawler's text() includes them, and a single inline loader script can
     * make an empty "Loading…" shell look like a page full of content.
     */
    private function visibleTextLength(Page $page): int
    {
        $body = $page->crawler()->filter('body');
        if ($body->count() === 0) {
            return 0;
        }

        $html = (string) $body->html('');
        $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        return strlen($text);
    }

    /**
     * How many elements the detected list selector matches on the page — used to
     * tell a real listing from a small placeholder/decoy grid.
     *
     * @param array{itemSelector:string,sample:\DOMElement} $list
     */
    private function itemCount(Page $page, array $list): int
    {
        try {
            return $page->crawler()->filter($list['itemSelector'])->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Choose the best data endpoint from JSON responses captured while rendering a
     * SPA: the re-fetchable GET whose body contains the largest list of records.
     * POST endpoints are skipped (they usually need a body/auth the run-time
     * crawler can't reproduce from a bare blueprint).
     *
     * @param  list<array{url:string,method:string,body:string}>                       $responses
     * @return array{endpoint:string,itemsPath:string,sample:array<string,mixed>,count:int}|null
     */
    private function pickBestApiResponse(array $responses): ?array
    {
        $best = null;

        foreach ($responses as $response) {
            if (strtoupper($response['method'] ?? 'GET') !== 'GET') {
                continue;
            }

            if ($this->isDiscardableEndpoint((string) $response['url'])) {
                continue;
            }

            $decoded = json_decode($response['body'] ?? '', true);
            if (! is_array($decoded)) {
                continue;
            }

            $structure = $this->jsonDetector->detect($decoded);
            if ($structure === null || $structure['sample'] === []) {
                continue;
            }

            $endpoint = $this->stripPageParam((string) $response['url']);
            $this->discoveredEndpoints[] = $endpoint;

            // A listing page calls many list endpoints (the offers, plus makes,
            // colors, financing rates, … for its filters). The main content entity
            // is the one with by far the richest records, so rank by field count
            // first and record count only as a tie-breaker — otherwise a long, thin
            // lookup list (128 car makes) beats the real, page-sized data (50 cars).
            $fields = count($structure['sample']);
            if ($best === null
                || $fields > $best['fields']
                || ($fields === $best['fields'] && $structure['count'] > $best['count'])
            ) {
                $best = [
                    'endpoint'  => $endpoint,
                    'itemsPath' => $structure['path'],
                    'sample'    => $structure['sample'],
                    'count'     => $structure['count'],
                    'fields'    => $fields,
                ];
            }
        }

        if ($best !== null) {
            unset($best['fields']); // internal ranking key
        }

        return $best;
    }

    /**
     * True when an item selector depends on CSS-in-JS generated class names
     * (emotion's `css-1a2b3c` / `e15r6ct20`, styled-components' `sc-…`). Such
     * classes are content-hashed and change on every build/render, so a robot
     * baked with them scrapes nothing on the next run — meaning a discovered API
     * is the reliable choice for that page.
     */
    private function selectorLooksUnstable(string $selector): bool
    {
        // emotion base classes and styled-components' generated prefixes.
        if (preg_match('/\bcss-[a-z0-9]{4,}\b/i', $selector)
            || preg_match('/\bsc-[a-zA-Z][a-zA-Z0-9]{4,}\b/', $selector)
        ) {
            return true;
        }

        // emotion "label" hashes, e.g. e15r6ct20: a short letter prefix followed
        // by a long alphanumeric run that contains digits. The digit requirement
        // keeps ordinary words (container, overflow, …) from matching.
        foreach (preg_split('/[.\s>+~]+/', $selector) ?: [] as $token) {
            if ($token !== ''
                && preg_match('/^[a-z]{1,2}[a-z0-9]{7,}$/i', $token)
                && preg_match('/[0-9]/', $token)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Endpoints that must never be baked into a robot even though they return a
     * list of records: framework SSG data files (Gatsby `page-data`, Next.js
     * `_next/data`) whose URLs are build-hashed and change on every deploy, plus
     * web manifests. The real, stable data API is what we want instead.
     */
    private function isDiscardableEndpoint(string $url): bool
    {
        return (bool) preg_match(
            '#(/page-data/|/_next/data/|/app-data\.json(?:$|\?)|manifest\.(?:json|webmanifest)(?:$|\?))#i',
            $url,
        );
    }

    /**
     * Drop a page/offset query parameter from a captured endpoint URL so the
     * blueprint's own pagination drives it from the first page, rather than being
     * pinned to whichever page the browser happened to request first.
     */
    private function stripPageParam(string $url): string
    {
        $parts = parse_url($url);
        if (! isset($parts['query']) || $parts['query'] === '') {
            return $url;
        }

        parse_str($parts['query'], $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(page|pagina|offset|start|from)$/i', (string) $key)) {
                unset($query[$key]);
            }
        }

        $rebuilt = http_build_query($query);
        $scheme  = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host    = $parts['host'] ?? '';
        $port    = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path    = $parts['path'] ?? '';

        return $scheme . $host . $port . $path . ($rebuilt !== '' ? '?' . $rebuilt : '');
    }

    /**
     * Apply the chosen HTTP settings to the underlying client before detection,
     * mirroring how CrawlEngine reconfigures per crawl (handles the cache wrapper).
     */
    private function configureHttp(HttpConfig $config): void
    {
        $http = $this->http instanceof CachedHttpClient ? $this->http->getInner() : $this->http;

        // Same idiom as TransportFactory: every transport it builds is
        // configurable (AutoHttpClient included — an instanceof list here would
        // silently drop cookies/headers for transports it forgot to name).
        if (method_exists($http, 'configure')) {
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

        // Offset pagination (?limit=50&offset=0): the endpoint's own query names
        // the scheme, so respect it instead of scaffolding generic page/size
        // params that a strictly-validating API rejects with a 400/422. The
        // offset param itself was stripped by stripPageParam(), so it is
        // inferred from the `limit` param that accompanies it.
        parse_str((string) (parse_url($endpoint, PHP_URL_QUERY) ?: ''), $query);
        if (isset($query['limit']) && (int) $query['limit'] > 0 && ! isset($query['page'])) {
            return [
                'endpoint'        => $endpoint,
                'startPage'       => 0,
                'pageSize'        => (int) $query['limit'],
                'pageParam'       => 'offset',
                'pageSizeParam'   => null,
                'pathPagination'  => false,
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
