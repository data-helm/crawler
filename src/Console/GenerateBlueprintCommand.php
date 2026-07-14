<?php

namespace DataHelm\Crawler\Console;

use DataHelm\Crawler\Blueprint\CrawlConfig;
use DataHelm\Crawler\Blueprint\DedupConfig;
use DataHelm\Crawler\Blueprint\HttpConfig;
use DataHelm\Crawler\Blueprint\OutputConfig;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Blueprint\SearchFilter;
use DataHelm\Crawler\Detection\BlueprintGenerator;
use DataHelm\Crawler\Detection\BotProtectionException;
use DataHelm\Crawler\Detection\JavaScriptRenderedException;
use DataHelm\Crawler\Dom\Url;
use DataHelm\Crawler\Scaffolding\RobotCommandScaffolder;
use DataHelm\Crawler\Console\Concerns\UsesCrawlerPrefix;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Step 1 — "generator scrap".
 *
 * Inspects a URL, auto-detects the item list, pagination and field selectors,
 * and scaffolds a Robot{Name} command (the blueprint is baked into the .php).
 *
 * Pass --blueprint to instead save a reusable blueprint JSON file for the
 * file-based runner (datahelm:scrap:run).
 *
 * Examples:
 *   php artisan datahelm:scrap:generate https://www.megaleiloes.com.br/imoveis/apartamentos --get-detail=true
 *   php artisan datahelm:scrap:generate <url> --robot-name=MegaLeiloes
 *   php artisan datahelm:scrap:generate <url> --json
 *   php artisan datahelm:scrap:generate <url> --blueprint
 */
class GenerateBlueprintCommand extends Command
{
    use UsesCrawlerPrefix;

    protected $signature = 'datahelm:scrap:generate
        {url : The listing page to inspect}
        {--get-detail=false : Visit a sample item to also detect detail-page fields (true/false)}
        {--max-pages=0 : Page cap stored in the blueprint (0 = all pages; no limit)}
        {--get-all-images=false : Put every image URL (list thumbnail + gallery) into all_images in the JSON (true/false)}
        {--get-primary-image=false : Put only the primary (most relevant) image URL per item into primary_image in the JSON (true/false)}
        {--get-gallery-images=false : Detect the detail-page gallery and put its image URLs into gallery_images in the JSON (implies --get-detail) (true/false)}
        {--hash-names=false : Rename stored images to a unique content hash (true/false)}
        {--image-disk= : Filesystem disk for downloaded images: storage, public, s3, … (default config crawler.images.disk)}
        {--http-delay= : Milliseconds to wait between page requests (default: config crawler.http.delay_ms)}
        {--http-timeout=60 : HTTP request timeout in seconds}
        {--http-retries=3 : Retry count on transient request failures}
        {--output-format=json : Output format: json, jsonl, csv, markdown}
        {--dedup : Force-enable deduplication (already auto-enabled when a "link" field is detected)}
        {--dedup-key=link : Field used as the dedup uniqueness key}
        {--no-dedup : Disable the automatic link-based deduplication}
        {--page-delay= : Milliseconds to wait between pagination pages (default: config crawler.crawl.delay_between_pages_ms)}
        {--item-delay= : Milliseconds to wait between individual items (default: config crawler.crawl.delay_between_items_ms)}
        {--max-items=0 : Blueprint-level item cap (0 = unlimited)}
        {--api-endpoint= : Force API mode: JSON endpoint a JS site calls (skips HTML detection)}
        {--api-method=GET : HTTP method for the API endpoint (GET or POST)}
        {--api-items-path= : Dot-path to the items array in the JSON (e.g. data.results.content)}
        {--json : Output the raw blueprint JSON instead of a summary}
        {--blueprint : Save a reusable blueprint JSON file (for datahelm:scrap:run) instead of scaffolding a robot}
        {--robot-name= : Class base name for the generated robot (default: derived from the host)}
        {--force : Overwrite an existing robot command file}
        {--fields= : Comma-separated whitelist of field names to keep (e.g. title,link,price,image). Empty = keep all.}
        {--no-labeled-fields : Skip the labeled-fields heuristic (avoids nav labels / breadcrumbs being added as fields)}
        {--single-page : Treat the URL as one record instead of a list (an article, a profile, a one-off page). Skips list/SPA/API detection and runs field detectors against the whole page; produces item_selector "body" with pagination disabled.}
        {--main-content : With --single-page, scope detection to the page primary content region (like Firecrawl onlyMainContent) so nav/footer/sidebar text never becomes a field. Falls back to <body> when no region is confidently found.}
        {--resumable : Mark the blueprint as resumable (persists dedup state; use --resume on the robot to skip already-scraped items)}
        {--render-js : Set render_js=true in http_config (requires a BrowserHttpClient; see config crawler.transport)}
        {--transport= : HTTP transport to use AND bake into the blueprint (auto|guzzle|browser|flaresolverr|scraping_api). "auto" detects the protection and escalates automatically, then bakes the transport that worked. Persisted so the generated robot reuses it without -e CRAWLER_TRANSPORT.}
        {--search-filters= : JSON array of category pages to crawl with this blueprint. Each entry is an object with a url plus optional tag keys like category. URLs may be relative to the positional base URL or absolute, and each tag is stamped onto every item from that page. Detection runs on the first filter. See the README for an example.}
        {--header=* : Extra request header "Key: Value" (repeatable), baked into http_config.headers. Replay captured browser headers to reuse a session that already passed a WAF.}
        {--cookie= : Cookies as "name=value; name2=value2" (e.g. captured _px/_abck/session cookies), baked into http_config.cookies. The free way past hard WAFs for a one-off run — cookies expire in hours.}
        {--preset= : Override the active detection preset for this run (e.g. ecommerce, br_auctions, generic)}';

    protected $description = 'Inspect a URL and generate a scrape blueprint (Scrapy-like auto-detection)';

    /**
     * Runs before handle() parameters are resolved from the container, which
     * means any config changes here are picked up by preset-aware bindings
     * (PriceFieldDetector, RatingFieldDetector, ApiFieldScaffolder, …).
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $preset = $this->option('preset');
        if (is_string($preset) && $preset !== '') {
            config(['crawler.active_preset' => $preset]);
        }

        // Apply --transport before the container resolves HttpClient (lazy binding
        // reads this), so detection itself fetches through the chosen transport.
        $transport = $this->option('transport');
        if (is_string($transport) && $transport !== '') {
            config(['crawler.transport' => $transport]);
        }
    }

    public function handle(BlueprintGenerator $generator): int
    {
        $baseUrl = Url::normalize((string) $this->argument('url'));

        // Parse --search-filters (JSON) into relative entries (url_sufix + tags).
        // They stay relative in the blueprint; the engine resolves them against the
        // base at crawl time. Detection runs on the first filter resolved against
        // the base (a real listing), so the positional URL can be the site root.
        try {
            $searchFilters = $this->parseSearchFilters($this->option('search-filters'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $url = $searchFilters !== []
            ? SearchFilter::fromMixed($searchFilters[0])->resolvedUrl($baseUrl)
            : $baseUrl;
        $name = $this->robotName($baseUrl);

        $httpConfig = new HttpConfig(
            timeout:    (int) ($this->option('http-timeout') ?? 60),
            delayMs:    $this->configDelayMs('http-delay', 'crawler.http.delay_ms'),
            retryCount: (int) ($this->option('http-retries') ?? 3),
            // Captured headers/cookies replay a browser session that already
            // passed the WAF — sent on every request (page + API) and baked in.
            headers:    $this->parseHeaders((array) $this->option('header')),
            cookies:    $this->parseCookies((string) ($this->option('cookie') ?? '')),
            renderJs:   (bool) $this->option('render-js'),
            // Bake the transport into the blueprint so the generated robot reuses
            // it at run time (CrawlEngine reads http_config.transport).
            transport:  is_string($this->option('transport')) && $this->option('transport') !== ''
                            ? (string) $this->option('transport')
                            : null,
        );

        // max_items: respect an explicit --max-items; otherwise derive a safety
        // ceiling from the per-category limits (sum × 2). Unlike search_filters.limit
        // (HTML-only), max_items is honoured in BOTH HTML and API mode, so this caps
        // a misbehaving endpoint/pagination from massively overshooting the volume
        // asked for. The ×2 headroom means it only bites on runaway, not normal runs.
        $explicitMaxItems = (int) ($this->option('max-items') ?? 0);
        $maxItemsCap      = $explicitMaxItems;
        $autoMaxItems     = false;
        if ($explicitMaxItems === 0) {
            $limitSum = array_sum(array_map(
                static fn (array $f): int => (int) ($f['limit'] ?? 0),
                $searchFilters,
            ));
            if ($limitSum > 0) {
                $maxItemsCap  = $limitSum * 2;
                $autoMaxItems = true;
            }
        }

        $crawlConfig = new CrawlConfig(
            delayBetweenPagesMs: $this->configDelayMs('page-delay', 'crawler.crawl.delay_between_pages_ms'),
            delayBetweenItemsMs: $this->configDelayMs('item-delay', 'crawler.crawl.delay_between_items_ms'),
            maxItems:            $maxItemsCap,
        );

        $outputConfig = new OutputConfig(
            format: (string) ($this->option('output-format') ?? 'json'),
        );

        $dedupConfig = new DedupConfig(
            enabled:  (bool) $this->option('dedup'),
            keyField: (string) ($this->option('dedup-key') ?? 'link'),
        );

        $fieldsOption = $this->option('fields');
        $allowedFields = is_string($fieldsOption) && $fieldsOption !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $fieldsOption))))
            : [];

        $getGalleryImages = filter_var($this->option('get-gallery-images'), FILTER_VALIDATE_BOOLEAN);
        $getAllImages     = filter_var($this->option('get-all-images'), FILTER_VALIDATE_BOOLEAN);

        // Wrapped so we can run detection twice: once to auto-detect, and again
        // with a user-chosen endpoint after the interactive SPA picker below.
        // $apiEndpoint defaults to the --api-endpoint option but can be overridden.
        $optionEndpoint = $this->option('api-endpoint') !== null && $this->option('api-endpoint') !== ''
            ? (string) $this->option('api-endpoint')
            : null;
        $optionItemsPath = $this->option('api-items-path') !== null && $this->option('api-items-path') !== ''
            ? (string) $this->option('api-items-path')
            : null;

        $runGenerate = fn (?string $apiEndpoint, ?string $apiItemsPath): ScrapeBlueprint => $generator->generate(
            url:         $url,
            // Gallery lives on the detail page, so requesting it implies detail scraping.
            withDetail:  filter_var($this->option('get-detail'), FILTER_VALIDATE_BOOLEAN) || $getGalleryImages || $getAllImages,
            maxPages:    (int) $this->option('max-pages'),
            getAllImages:      $getAllImages,
            getPrimaryImage:   filter_var($this->option('get-primary-image'), FILTER_VALIDATE_BOOLEAN),
            getGalleryImages:  $getGalleryImages,
            hashNames:         filter_var($this->option('hash-names'), FILTER_VALIDATE_BOOLEAN),
            imageDisk:   (string) ($this->option('image-disk') ?: config('crawler.images.disk', 'storage')),
            httpConfig:  $httpConfig,
            crawlConfig: $crawlConfig,
            outputConfig: $outputConfig,
            dedup:       $dedupConfig,
            apiEndpoint: $apiEndpoint,
            apiMethod:   (string) ($this->option('api-method') ?? 'GET'),
            apiItemsPath: $apiItemsPath,
            allowedFields:       $allowedFields,
            includeLabeledFields: ! (bool) $this->option('no-labeled-fields'),
            singlePage:  (bool) $this->option('single-page'),
            mainContent: (bool) $this->option('main-content'),
        );

        try {
            $blueprint = $runGenerate($optionEndpoint, $optionItemsPath);
        } catch (BotProtectionException $e) {
            // The site is up but a WAF blocked us — print the vendor and the
            // realistic ways forward instead of a raw HTML deny page.
            $this->newLine();
            $this->error("Blocked by {$e->vendor}" . ($e->status !== null ? " (HTTP {$e->status})" : '') . " — couldn't read {$url}.");
            $this->line("  <fg=gray>{$e->getMessage()}</>");

            return self::FAILURE;
        } catch (JavaScriptRenderedException $e) {
            // SPA whose listing isn't in the static HTML. If we discovered
            // candidate endpoints and we're interactive, let the user pick one
            // and build from it; otherwise fall back to the guidance message.
            $chosen = $this->chooseSpaEndpoint($generator, $url, $e);

            if ($chosen === null) {
                return self::SUCCESS;
            }

            try {
                $blueprint = $runGenerate($chosen, $optionItemsPath);
            } catch (\Throwable $inner) {
                $this->error("Could not build a blueprint from {$chosen}: {$inner->getMessage()}");
                $this->line('  <fg=gray>The endpoint may need a POST body or auth header — capture the exact request in your browser\'s Network tab and pass --api-endpoint / --api-method / --api-items-path.</>');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if (preg_match('/scheme.*not allowed|cURL error|Connection|timed out|Could not resolve|getaddrinfo/i', $message)) {
                if (str_contains(strtolower($message), 'scheme')) {
                    $this->error("Could not fetch {$url} — a request was made without a valid http:// or https:// URL.");
                    $this->line('  Ensure the site URL includes https:// and retry. If this persists, the page may be JavaScript-rendered — try --render-js or --api-endpoint=<url>.');
                } else {
                    $this->error("Could not reach {$url} — the site appears to be down, too slow, or blocking requests.");
                }
                $this->line("  <fg=gray>{$message}</>");
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $blueprint = $this->applyPresetItemSchema($blueprint);

        // When transport=auto escalated to a concrete transport, bake the winner
        // into the blueprint so the generated robot uses it directly (no repeat
        // escalation, and the run isn't tied to whatever auto picks next time).
        $resolvedTransport = $generator->resolvedTransport();
        if ($resolvedTransport !== null) {
            // A render_js blueprint (SPA recovery re-rendered the list) always needs
            // the browser transport, regardless of what a later unrelated fetch on
            // the same auto client (detail page, script probing) last resolved to —
            // otherwise the robot bakes e.g. transport=guzzle + render_js=true, which
            // CrawlEngine can't reconcile and silently falls back to no JS rendering.
            $transport = $blueprint->httpConfig->renderJs ? 'browser' : $resolvedTransport;
            $data = $blueprint->toArray();
            $data['http_config']['transport'] = $transport;
            $blueprint = ScrapeBlueprint::fromArray($data);
            $this->line("<fg=cyan>·</> Auto-selected transport: <fg=green>{$transport}</> (baked into the robot).");
        }

        // Bake the search filters so one robot crawls several categories at once,
        // each tagging its items (e.g. category). Keep the base as the blueprint
        // url and store the suffixes relative — the engine resolves them per run.
        if ($searchFilters !== []) {
            $data = $blueprint->toArray();
            $data['url'] = $baseUrl;
            $data['search_filters'] = $searchFilters;
            $blueprint = ScrapeBlueprint::fromArray($data);
            $this->line('<fg=cyan>·</> Crawling ' . count($searchFilters) . ' search filter(s) (baked into the robot).');
            if ($autoMaxItems) {
                $this->line("<fg=cyan>·</> Safety cap: max_items = {$maxItemsCap} (2× the per-category limits; applies in HTML and API mode).");
            }
        }

        // Apply flags that are not threaded through the generator itself.
        if ($this->option('resumable')) {
            $data              = $blueprint->toArray();
            $data['resumable'] = true;
            $blueprint         = ScrapeBlueprint::fromArray($data);
        }

        // Auto-enable link-based dedup when a usable key field was detected, unless
        // the user forced it (--dedup) or opted out (--no-dedup). Saves typing
        // --dedup for paginated listings that repeat items. Safe even when the key
        // is sometimes empty — ItemSink never drops empty-key items.
        if (! $this->option('dedup') && ! $this->option('no-dedup')) {
            $data  = $blueprint->toArray();
            $names = array_column($data['fields'] ?? [], 'name');

            if ($this->input->hasParameterOption('--dedup-key', true)) {
                // User named the key explicitly — honour it.
                $key = (string) $this->option('dedup-key');
            } else {
                // Pick the most reliable unique key. In API mode the auto-scaffolded
                // "link" is often a non-unique slug (a make/model page shared by many
                // records), so a stable "id" dedups far better; HTML lists keep using
                // "link", which there is each item's own detail URL.
                $candidates = ($data['mode'] ?? 'html') === 'api'
                    ? ['id', 'uuid', 'link']
                    : ['link'];

                $key = '';
                foreach ($candidates as $candidate) {
                    if (in_array($candidate, $names, true)) {
                        $key = $candidate;
                        break;
                    }
                }
            }

            if ($key !== '' && ! ($data['dedup']['enabled'] ?? false) && in_array($key, $names, true)) {
                $data['dedup'] = ['enabled' => true, 'key_field' => $key];
                $blueprint     = ScrapeBlueprint::fromArray($data);
                $this->line("<fg=cyan>·</> Auto-enabled dedup on \"{$key}\" (pass --no-dedup to disable).");
            }
        }

        foreach ($generator->notes() as $note) {
            $this->line("<fg=cyan>·</> {$note}");
        }

        $json = $blueprint->toJson();

        if ($this->option('json')) {
            $this->line($json);

            return self::SUCCESS;
        }

        // Default: scaffold a Robot{Name} command. The blueprint is baked into
        // the generated .php, so there is no separate file to save.
        if (! $this->option('blueprint')) {
            return $this->scaffoldRobot($blueprint, $url) ? self::SUCCESS : self::FAILURE;
        }

        // Opt-in file workflow (--blueprint): persist the recipe for scrap:run.
        $path = $this->blueprintPath($url);
        $exists = file_exists($path);
        $this->saveBlueprint($path, $json);

        $label = strtolower($name);
        $this->info($exists ? "Blueprint {$label} was updated." : "Blueprint {$label} was created.");
        $this->line("Saved to {$path}");

        return self::SUCCESS;
    }

    /**
     * Parse the --search-filters JSON into normalised entries, each with a
     * relative `url_sufix` (resolved against the base at crawl time) plus any tag
     * keys (e.g. category). Accepts `url_sufix` or `url` on input. Returns [] when
     * the option is absent.
     *
     * @return list<array<string,scalar>>
     *
     * @throws \InvalidArgumentException on malformed JSON / shape.
     */
    private function parseSearchFilters(mixed $option): array
    {
        if (! is_string($option) || trim($option) === '') {
            return [];
        }

        $decoded = json_decode($option, true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException(
                '--search-filters must be a JSON array, e.g. [{"url_sufix":"cat/sub/","category":"label"}].',
            );
        }

        $entries = [];
        $seen    = [];

        foreach ($decoded as $raw) {
            $raw = is_string($raw) ? ['url_sufix' => $raw] : $raw;
            if (! is_array($raw)) {
                throw new \InvalidArgumentException('Each --search-filters entry must be a string or an object.');
            }

            $suffix = trim((string) ($raw['url_sufix'] ?? $raw['url'] ?? ''));
            if ($suffix === '') {
                throw new \InvalidArgumentException('Each --search-filters entry needs a non-empty "url_sufix" (or "url").');
            }

            if (isset($seen[$suffix])) {
                continue;
            }
            $seen[$suffix] = true;

            // Keep url_sufix + scalar tag keys (category, type, …) for the blueprint.
            $entry = ['url_sufix' => $suffix];
            foreach ($raw as $key => $value) {
                if ($key !== 'url_sufix' && $key !== 'url' && is_scalar($value)) {
                    $entry[(string) $key] = $value;
                }
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Parse repeated --header "Key: Value" options into a header map.
     *
     * @param  list<string>          $lines
     * @return array<string,string>
     */
    private function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if (! is_string($line) || ! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            if ($name !== '') {
                $headers[$name] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Parse a Cookie-header string ("a=1; b=2") into blueprint cookie entries.
     *
     * @return list<array{name:string,value:string}>
     */
    private function parseCookies(string $raw): array
    {
        $cookies = [];
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);
            if ($name !== '') {
                $cookies[] = ['name' => $name, 'value' => trim($value)];
            }
        }

        return $cookies;
    }

    /**
     * Handle a detected SPA. When candidate endpoints were discovered and the
     * console is interactive, ask the user which one to build from and return it.
     * Returns null when we should instead print the guidance message and stop
     * (no endpoints found, non-interactive run, or the user picked "none").
     */
    private function chooseSpaEndpoint(BlueprintGenerator $generator, string $url, JavaScriptRenderedException $e): ?string
    {
        $endpoints = $generator->discoveredEndpoints();

        // Nothing to offer, or running unattended (CI, --no-interaction): keep the
        // original behaviour — print the green guidance and let the caller exit.
        if ($endpoints === [] || ! $this->input->isInteractive()) {
            $this->newLine();
            $this->line("<fg=green>{$e->getMessage()}</>");

            return null;
        }

        $this->newLine();
        $this->line("<fg=yellow>We detected a JavaScript SPA at</> {$url}");
        $this->line('<fg=yellow>Its content is loaded from a data endpoint. We found these candidates in the page scripts:</>');
        $this->newLine();

        $manual = 'None of these — show me how to capture it manually';
        $choices = array_values($endpoints);
        $choices[] = $manual;

        $answer = $this->choice(
            'Which endpoint would you like us to build the blueprint from?',
            $choices,
            0,
        );

        if ($answer === $manual) {
            $this->newLine();
            $this->line("<fg=green>{$e->getMessage()}</>");

            return null;
        }

        $this->line("<fg=cyan>·</> Probing {$answer} …");

        return $answer;
    }

    private function scaffoldRobot(ScrapeBlueprint $blueprint, string $url): bool
    {
        $name = $this->robotName($url);
        $scaffolder = new RobotCommandScaffolder(
            (string) config('crawler.robot.path', app_path('Console/Commands/RobotsCommand')),
            (string) config('crawler.command_prefix', 'datahelm'),
            (string) config('crawler.robot.namespace', 'App\\Console\\Commands\\RobotsCommand'),
        );

        try {
            $path = $scaffolder->scaffold($blueprint, $name, (bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }

        $prefix = (string) config('crawler.command_prefix', 'datahelm');
        $this->info("Robot command written to {$path}");
        $this->line("Run it with: php artisan {$prefix}:robot:" . strtolower($name));

        return true;
    }

    private function robotName(string $url): string
    {
        $custom = $this->option('robot-name');
        if (is_string($custom) && $custom !== '') {
            // Str::studly only treats - and _ as word separators, so dots/spaces
            // in a custom name (e.g. "zalando.at") would survive into an invalid
            // PHP class name like "Zalando.at". Normalise every non-alphanumeric
            // run to _ first so it studlies cleanly → "ZalandoAt".
            $name = Str::studly((string) preg_replace('/[^A-Za-z0-9]+/', '_', $custom));

            // A class name can't be empty or start with a digit.
            return $name !== '' && ! ctype_digit($name[0]) ? $name : 'Site' . $name;
        }

        $host = preg_replace('/^www\./', '', Url::host($url));
        $label = explode('.', (string) $host)[0] ?: 'site';

        return Str::studly($label);
    }

    private function blueprintPath(string $url): string
    {
        return storage_path('app/blueprints/' . Url::host($url) . '.json');
    }

    private function saveBlueprint(string $path, string $json): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, $json);
    }

    private function applyPresetItemSchema(ScrapeBlueprint $blueprint): ScrapeBlueprint
    {
        if ($blueprint->itemSchema !== []) {
            return $blueprint;
        }

        $active = (string) config('crawler.active_preset', 'generic');
        $schema = config("crawler.presets.{$active}.item_schema")
            ?? config('crawler.presets.generic.item_schema')
            ?? [];

        if (! is_array($schema) || $schema === []) {
            return $blueprint;
        }

        $data                   = $blueprint->toArray();
        $data['item_schema']    = $schema;

        return ScrapeBlueprint::fromArray($data);
    }

    private function configDelayMs(string $option, string $configKey): int
    {
        $value = $this->option($option);
        if ($value !== null && $value !== '') {
            return max(0, (int) $value);
        }

        return max(0, (int) config($configKey, 0));
    }
}
