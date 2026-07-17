<?php

namespace DataHelm\Crawler;

use DataHelm\Crawler\Detection\ApiFieldScaffolder;
use DataHelm\Crawler\Detection\BlueprintGenerator;
use DataHelm\Crawler\Detection\ListCandidateValidator;
use DataHelm\Crawler\Detection\ListDetector;
use DataHelm\Crawler\Detection\PaginationDetector;
use DataHelm\Crawler\Detection\PriceFieldDetector;
use DataHelm\Crawler\Detection\RatingFieldDetector;
use DataHelm\Crawler\Console\GenerateBlueprintCommand;
use DataHelm\Crawler\Console\RunScrapCommand;
use DataHelm\Crawler\Console\ShellCommand;
use DataHelm\Crawler\Console\ValidateBlueprintCommand;
use DataHelm\Crawler\Http\GuardedHttpClient;
use DataHelm\Crawler\Http\GuzzleHttpClient;
use DataHelm\Crawler\Http\HttpClient;
use DataHelm\Crawler\Http\TransportFactory;
use DataHelm\Crawler\Http\UrlGuard;
use DataHelm\Crawler\Media\ImageStore;
use DataHelm\Crawler\Output\ItemExporter;
use DataHelm\Crawler\Output\JsonExporter;
use DataHelm\Crawler\Output\OutputSink;
use DataHelm\Crawler\Pipeline\AbsoluteUrlProcessor;
use DataHelm\Crawler\Pipeline\ItemPipeline;
use DataHelm\Crawler\Scraping\CrawlEngine;
use DataHelm\Crawler\Scraping\Paginator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the generic, Scrapy-like crawler subsystem.
 *
 * Everything the crawler needs is wired here from config/crawler.php and the
 * active preset, so the package can be dropped into any Laravel app and tuned
 * entirely through configuration — no edits to the engine itself.
 *
 * When this is extracted into a standalone Composer package, this is the only
 * provider consumers register (it would also publish the config and migrations).
 */
class CrawlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/crawler.php', 'crawler');

        $this->registerHeuristics();
        $this->registerGenerator();
        $this->registerEngine();
        $this->registerSink();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/crawler.php' => config_path('crawler.php'),
        ], 'crawler-config');

        // The package's own commands live under Scrap\Console, outside
        // the app's auto-discovered Console/Commands directory, so register them
        // explicitly. Generated robot commands stay in the app and are still
        // auto-discovered by the framework.
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateBlueprintCommand::class,
                RunScrapCommand::class,
                ShellCommand::class,
                ValidateBlueprintCommand::class,
            ]);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Bind the preset-configured detectors/processors so the class-name lists in
     * config resolve to instances carrying the right currency/image heuristics.
     */
    private function registerHeuristics(): void
    {
        // Transport selection lives in TransportFactory (guzzle | browser |
        // flaresolverr | scraping_api). HttpClient is bound lazily so it reads the
        // current config('crawler.transport') at resolve time — this lets the
        // generate command's --transport flag take effect, and lets CrawlEngine
        // swap to a blueprint's own http_config.transport per crawl.
        $this->app->singleton(TransportFactory::class, fn (Application $app) => new TransportFactory($app));
        $this->app->bind(HttpClient::class, fn (Application $app) => $app->make(TransportFactory::class)->make());

        // Image downloads always use a plain HTTP client, never the page transport:
        // images are static CDN assets, and a headless-browser/FlareSolverr
        // transport would return the browser's HTML image-viewer wrapper instead
        // of the raw bytes. The client is still SSRF-guarded, since image URLs are
        // scraped content too (e.g. a hostile page could set an image to an
        // internal/metadata address).
        $this->app->bind(ImageStore::class, fn () => new ImageStore(
            new GuardedHttpClient(new GuzzleHttpClient(), UrlGuard::fromConfig()),
        ));

        $this->app->bind(ItemExporter::class, JsonExporter::class);

        $this->app->bind(PriceFieldDetector::class, fn () => new PriceFieldDetector(
            $this->preset('price_patterns', ['/(?:R\$|US\$|\$|€|£)\s*[\d.,]+/']),
        ));

        $this->app->bind(RatingFieldDetector::class, fn () => new RatingFieldDetector(
            $this->preset('rating_hints', ['star', 'rating', 'review-score', 'score']),
        ));

        $this->app->bind(ApiFieldScaffolder::class, fn () => new ApiFieldScaffolder(
            $this->preset('image_field_hints', ['image', 'img', 'photo']),
            $this->preset('link_field_hints', ['url', 'link', 'href']),
        ));

        $this->app->bind(AbsoluteUrlProcessor::class, fn () => new AbsoluteUrlProcessor(
            $this->preset('image_path_prefix', null),
        ));
    }

    private function registerGenerator(): void
    {
        $this->app->bind(BlueprintGenerator::class, fn (Application $app) => new BlueprintGenerator(
            $app->make(HttpClient::class),
            $this->makeListDetector(),
            new PaginationDetector(),
            $this->resolveList((array) config('crawler.detectors', [])),
            apiScaffolder: $app->make(ApiFieldScaffolder::class),
            endpointHints: $this->preset('endpoint_hints', BlueprintGenerator::DEFAULT_ENDPOINT_HINTS),
        ));
    }

    private function makeListDetector(): ListDetector
    {
        $detectors = $this->resolveList((array) config('crawler.detectors', []));

        return new ListDetector(
            $detectors,
            new ListCandidateValidator(
                $detectors,
                (array) $this->preset('list_core_fields', ['link', 'title', 'image']),
                (int) $this->preset('list_min_core_fields', 2),
                (float) $this->preset('list_min_success_rate', 0.6),
                (float) $this->preset('list_min_link_uniqueness', 0.65),
            ),
        );
    }

    private function registerEngine(): void
    {
        $this->app->bind(CrawlEngine::class, function (Application $app) {
            $http = $app->make(HttpClient::class);

            return new CrawlEngine(
                $http,
                new Paginator($http),
                new ItemPipeline($this->resolveList((array) config('crawler.pipeline', []))),
                (array) config('crawler.pipeline_registry', []),
                $app->make(TransportFactory::class),
                UrlGuard::fromConfig(),
            );
        });
    }

    private function registerSink(): void
    {
        $this->app->bind(OutputSink::class, function (Application $app) {
            $sinkClass = (string) config('crawler.sink');

            return $app->make($sinkClass, [
                'outputDir' => (string) config('crawler.paths.output', storage_path('app/scrapes')),
            ]);
        });
    }

    // -------------------------------------------------------------------------

    /**
     * Resolve a list of class names through the container, honouring any
     * preset-configured bindings registered above.
     *
     * @param  list<class-string> $classes
     * @return list<object>
     */
    private function resolveList(array $classes): array
    {
        return array_map(fn (string $class) => $this->app->make($class), $classes);
    }

    /**
     * Read a value from the active preset, falling back to the generic preset
     * and finally to the supplied default.
     */
    private function preset(string $key, mixed $default): mixed
    {
        $active = (string) config('crawler.active_preset', 'generic');

        return config("crawler.presets.{$active}.{$key}")
            ?? config("crawler.presets.generic.{$key}")
            ?? $default;
    }
}
