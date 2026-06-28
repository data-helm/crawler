<?php

namespace DataHelm\Crawler\Console\Concerns;

use DataHelm\Crawler\Blueprint\OutputConfig;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Output\CallbackSink;
use DataHelm\Crawler\Output\CsvExporter;
use DataHelm\Crawler\Output\ItemExporter;
use DataHelm\Crawler\Output\JsonExporter;
use DataHelm\Crawler\Output\JsonlExporter;
use DataHelm\Crawler\Output\OutputSink;
use DataHelm\Crawler\Output\StreamWriter;
use DataHelm\Crawler\Scraping\CrawlEngine;
use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Shared output handling for scrape commands.
 *
 * By default the JSON is saved to `storage/app/scrapes/<name>.json`, where
 * `<name>` is derived from the command (e.g. RobotMegaLeiloes => megaleiloes).
 * `--output=PATH` overrides the path; `--output=-` prints to STDOUT instead.
 * Progress/status always go to STDERR so a piped STDOUT stays pure JSON.
 * Commands using this trait must declare an `--output=` option.
 *
 * Stats: after a crawl, crawlToSink() / crawlEach() call printStats($engine) to
 *   write the run summary to STDERR.
 */
trait ScrapesToConsole
{
    /** Lazily opened by saveJson(); finalized by crawlEach()/flushJson(). */
    private ?StreamWriter $jsonWriter = null;

    /** Resolved path of the JSON file saveJson() wrote to ('' if none, '-' = STDOUT). */
    private string $jsonPath = '';

    /** Items whose store() callback threw and were skipped (see crawlEach()). */
    private int $failedItems = 0;

    /**
     * @return callable(string):void Progress reporter for CrawlEngine::crawl().
     */
    protected function progressReporter(): callable
    {
        return static fn (string $url) => fwrite(STDERR, "fetching: {$url}" . PHP_EOL);
    }

    /**
     * Runs the crawl and streams every item into the given {@see OutputSink}.
     *
     * This is the pluggable path: pass a JsonFileSink (default), a CallbackSink,
     * or any custom sink (database / queue / webhook) to control where results
     * go without buffering them in memory.
     */
    protected function crawlToSink(CrawlEngine $engine, ScrapeBlueprint $blueprint, OutputSink $sink, int $limit = 0, bool $announce = true): void
    {
        $this->applyResumeState($engine);
        $sink->open($this->defaultScrapeName());

        // The engine only invokes the callback when streaming is enabled; in
        // buffered mode it returns the full list. Handle both so any blueprint
        // works regardless of its output_config.stream setting.
        $streamed = false;
        $items = $engine->crawl(
            $blueprint,
            $this->progressReporter(),
            $limit,
            function (ScrapedItem $item) use ($sink, &$streamed): void {
                $sink->write($item);
                $streamed = true;
            },
        );

        if (! $streamed) {
            foreach ($items as $item) {
                $sink->write($item);
            }
        }

        $destination = $sink->close();
        $count       = $engine->getLastStats()?->itemsScraped ?? 0;

        // crawlEach() passes announce=false so it can report the real JSON path
        // (and any skipped items) itself; direct crawlToSink() callers announce.
        if ($announce) {
            fwrite(STDERR, sprintf('Sent %d item(s) to %s%s', $count, $destination, PHP_EOL));
            $this->printStats($engine);
        }
    }

    /**
     * Runs the crawl and hands every item to $store as it streams in (nothing
     * buffered). This is the place to do per-item work — download images, save
     * to your own model, etc.
     *
     * Inside $store, call saveJson($record) for a zero-config JSON file
     * (storage/app/scrapes/<name>.json), or swap that single line for your
     * model (e.g. Property::updateOrCreate(...)). The JSON file, if used, is
     * finalized automatically when the crawl ends.
     *
     * @param callable(ScrapedItem):void $store
     */
    protected function crawlEach(CrawlEngine $engine, ScrapeBlueprint $blueprint, callable $store, int $limit = 0): void
    {
        $this->jsonPath    = '';
        $this->failedItems = 0;

        // Per-item resilience: a single bad item (e.g. an image 404, a malformed
        // row) is logged and skipped so it cannot abort the whole crawl.
        $sink = new CallbackSink(function (ScrapedItem $item) use ($store): void {
            try {
                $store($item);
            } catch (\Throwable $e) {
                $this->failedItems++;
                $id = $item->get('link') ?? $item->get('url') ?? '?';
                fwrite(STDERR, sprintf('  ! skipped %s: %s%s', $id, $e->getMessage(), PHP_EOL));
            }
        }, $this->defaultScrapeName());

        $this->crawlToSink($engine, $blueprint, $sink, $limit, announce: false);

        $this->flushJson();

        $count = $engine->getLastStats()?->itemsScraped ?? 0;

        // Tell the user where the data actually went. When store() used saveJson()
        // that's the JSON file; otherwise it went wherever store() sent it.
        if ($this->jsonPath !== '') {
            $where = $this->jsonPath === '-' ? 'STDOUT' : $this->jsonPath;
            fwrite(STDERR, sprintf('Saved %d item(s) to %s%s', $count, $where, PHP_EOL));
        } else {
            fwrite(STDERR, sprintf('Processed %d item(s).%s', $count, PHP_EOL));
        }

        if ($this->failedItems > 0) {
            fwrite(STDERR, sprintf('Skipped %d item(s) due to errors.%s', $this->failedItems, PHP_EOL));
        }

        $this->printStats($engine);
    }

    /**
     * Append one record to the run's JSON file (storage/app/scrapes/<name>.json,
     * or --output / "-" for STDOUT). Opens the file on first call; crawlEach()
     * finalizes it. The default destination for scraped items — replace this
     * call with your own model when you want to persist elsewhere.
     *
     * @param ScrapedItem|array<string,mixed> $record
     */
    protected function saveJson(ScrapedItem|array $record): void
    {
        if ($this->jsonWriter === null) {
            $this->jsonPath   = $this->outputDestination('json');
            $this->jsonWriter = new StreamWriter($this->jsonPath, 'json');
        }

        $this->jsonWriter->write($record instanceof ScrapedItem ? $record : new ScrapedItem($record));
    }

    /**
     * Closes the JSON file opened by saveJson(), if any. Called by crawlEach().
     */
    private function flushJson(): void
    {
        $this->jsonWriter?->close();
        $this->jsonWriter = null;
    }

    /**
     * Save the items using the configured exporter (default path or --output),
     * or print to STDOUT when --output is "-".
     *
     * @param list<ScrapedItem> $items
     */
    protected function writeItems(array $items, ItemExporter $defaultExporter, ?OutputConfig $outputConfig = null): void
    {
        $exporter = $defaultExporter;
        $format   = 'json';

        if ($outputConfig !== null) {
            $format   = $outputConfig->format;
            $exporter = $this->resolveExporter($format);
        }

        $output = $exporter->export($items);
        $dest   = $this->option('output');

        if ($dest === '-') {
            $this->line($output);
            fwrite(STDERR, sprintf('Scraped %d item(s).%s', count($items), PHP_EOL));

            return;
        }

        $path = is_string($dest) && $dest !== '' ? $dest : $this->defaultOutputPath($format);

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $output);

        fwrite(STDERR, sprintf('Saved %d item(s) to %s%s', count($items), $path, PHP_EOL));
    }

    /**
     * Writes the CrawlStats summary to STDERR after a crawl.
     * Safe to call with any engine — skipped if no stats are available.
     */
    protected function printStats(CrawlEngine $engine): void
    {
        $stats = $engine->getLastStats();
        if ($stats === null) {
            return;
        }

        fwrite(STDERR, PHP_EOL . '--- Crawl stats ---' . PHP_EOL);
        fwrite(STDERR, $stats->summary() . PHP_EOL);
    }

    /**
     * Resolves the output destination path (or "-" for STDOUT).
     */
    protected function outputDestination(string $format = 'json'): string
    {
        $dest = $this->option('output');

        if ($dest === '-') {
            return '-';
        }

        return is_string($dest) && $dest !== '' ? $dest : $this->defaultOutputPath($format);
    }

    /**
     * Default destination when --output is omitted.
     */
    protected function defaultOutputPath(string $format = 'json'): string
    {
        $ext = match ($format) {
            'jsonl' => 'jsonl',
            'csv'   => 'csv',
            default => 'json',
        };

        return storage_path('app/scrapes/' . $this->defaultScrapeName() . '.' . $ext);
    }

    /**
     * Name derived from the command class: RobotMegaLeiloes => "megaleiloes".
     * Override (e.g. in a generic runner) to base the name on something else.
     */
    protected function defaultScrapeName(): string
    {
        $base = (string) preg_replace('/^Robot/', '', class_basename(static::class));

        return strtolower($base !== '' ? $base : class_basename(static::class));
    }

    /**
     * Load persisted dedup state into the engine when the --resume flag is set
     * or the blueprint declares resumable = true (handled by the engine itself).
     *
     * Uses hasOption() so robots without {--resume} in their signature are
     * unaffected (backward-compatible).
     */
    private function applyResumeState(CrawlEngine $engine): void
    {
        if (method_exists($this, 'hasOption') && $this->hasOption('resume') && $this->option('resume')) {
            $engine->loadState($this->defaultScrapeName());
        }
    }

    private function resolveExporter(string $format): ItemExporter
    {
        return match ($format) {
            'jsonl' => new JsonlExporter(),
            'csv'   => new CsvExporter(),
            default => new JsonExporter(),
        };
    }
}
