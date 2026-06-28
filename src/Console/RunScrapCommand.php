<?php

namespace DataHelm\Crawler\Console;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Output\ItemExporter;
use DataHelm\Crawler\Scraping\CrawlEngine;
use DataHelm\Crawler\Console\Concerns\ScrapesToConsole;
use DataHelm\Crawler\Console\Concerns\UsesCrawlerPrefix;
use Illuminate\Console\Command;

/**
 * Step 2 — "scrap".
 *
 * Loads a blueprint produced by {@see GenerateBlueprintCommand}, runs the crawl
 * and prints the scraped items as JSON (or writes them to --output). Nothing is
 * persisted to the database.
 *
 * The blueprint argument accepts either a path to a JSON file or a host name
 * previously saved with `--save` (resolved under storage/app/blueprints).
 *
 * Examples:
 *   php artisan datahelm:scrap:run www.vipleiloes.com.br --limit=20
 *   php artisan datahelm:scrap:run www.vipleiloes.com.br --output=storage/app/scrapes/vip.json
 */
class RunScrapCommand extends Command
{
    use ScrapesToConsole;
    use UsesCrawlerPrefix;

    protected $signature = 'datahelm:scrap:run
        {blueprint : Path to a blueprint JSON file, or a saved host name}
        {--limit=0 : Stop after N items (0 = no limit)}
        {--output= : Output path (default storage/app/scrapes/<blueprint>.json; use - for stdout)}';

    protected $description = 'Run a scrape from a blueprint and save the items as JSON';

    public function handle(CrawlEngine $engine, ItemExporter $exporter): int
    {
        $json = $this->loadBlueprint((string) $this->argument('blueprint'));
        if ($json === null) {
            return self::FAILURE;
        }

        try {
            $blueprint = ScrapeBlueprint::fromJson($json);
        } catch (\Throwable $e) {
            $this->error("Invalid blueprint: {$e->getMessage()}");

            return self::FAILURE;
        }

        $items = $engine->crawl($blueprint, $this->progressReporter(), (int) $this->option('limit'));

        $this->writeItems($items, $exporter);

        return self::SUCCESS;
    }

    /**
     * Base the default filename on the blueprint reference (host or file name).
     */
    protected function defaultScrapeName(): string
    {
        $name = preg_replace('/\.json$/i', '', basename((string) $this->argument('blueprint')));

        return is_string($name) && $name !== '' ? $name : 'scrape';
    }

    private function loadBlueprint(string $reference): ?string
    {
        $candidates = [
            $reference,
            storage_path('app/blueprints/' . $reference),
            storage_path('app/blueprints/' . $reference . '.json'),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        $this->error("Blueprint not found: {$reference}");

        return null;
    }
}
