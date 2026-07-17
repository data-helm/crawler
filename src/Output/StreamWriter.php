<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Writes scraped items to a file (or STDOUT) one at a time as they arrive,
 * avoiding the need to buffer all items in memory.
 *
 * Used by ScrapesToConsole when output_config.stream = true.
 * Call write() for each item, then close() when done.
 *
 * True streaming applies to `json` and `jsonl` only. `csv` needs a header row
 * that is the union of every item's keys, and `markdown` is one document — so
 * emitting those row-by-row would corrupt the output (a CSV header frozen from
 * item #1 misaligns every later row that carries different fields). For those
 * two formats items are buffered internally and the whole document is written
 * once at close(), via the same exporters the buffered path uses.
 */
final class StreamWriter
{
    /** @var resource */
    private $handle;
    private bool $ownsHandle;
    private bool $firstItem = true;

    /** @var list<ScrapedItem> Items held back for whole-document formats (csv, markdown). */
    private array $buffered = [];

    public function __construct(
        private readonly string $destination,
        private readonly string $format = 'json',
    ) {
        if ($destination === '-') {
            $this->handle    = STDOUT;
            $this->ownsHandle = false;
        } else {
            $dir = dirname($destination);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $handle = fopen($destination, 'w');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open output file: {$destination}");
            }
            $this->handle    = $handle;
            $this->ownsHandle = true;
        }

        if ($format === 'json') {
            fwrite($this->handle, "[\n");
        }
    }

    public function write(ScrapedItem $item): void
    {
        if ($this->format === 'csv' || $this->format === 'markdown') {
            $this->buffered[] = $item;
            $this->firstItem = false;

            return;
        }

        $data = $item->toArray();

        match ($this->format) {
            'jsonl' => $this->writeJsonl($data),
            default => $this->writeJson($data),
        };

        $this->firstItem = false;
    }

    public function close(): void
    {
        if ($this->format === 'csv' || $this->format === 'markdown') {
            $exporter = $this->format === 'csv' ? new CsvExporter() : new MarkdownExporter();
            fwrite($this->handle, $exporter->export($this->buffered));
            $this->buffered = [];
        }

        if ($this->format === 'json') {
            fwrite($this->handle, $this->firstItem ? "]\n" : "\n]\n");
        }

        if ($this->ownsHandle) {
            fclose($this->handle);
        }
    }

    // -------------------------------------------------------------------------

    private function writeJson(array $data): void
    {
        if (! $this->firstItem) {
            fwrite($this->handle, ",\n");
        }

        fwrite($this->handle, '  ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function writeJsonl(array $data): void
    {
        fwrite($this->handle, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
