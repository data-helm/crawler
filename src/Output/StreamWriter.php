<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Writes scraped items to a file (or STDOUT) one at a time as they arrive,
 * avoiding the need to buffer all items in memory.
 *
 * Used by ScrapesToConsole when output_config.stream = true.
 * Call write() for each item, then close() when done.
 */
final class StreamWriter
{
    /** @var resource */
    private $handle;
    private bool $ownsHandle;
    private bool $firstItem = true;
    /** @var list<string> */
    private array $csvHeaders = [];

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
        $data = $item->toArray();

        match ($this->format) {
            'jsonl' => $this->writeJsonl($data),
            'csv'   => $this->writeCsv($data),
            default => $this->writeJson($data),
        };

        $this->firstItem = false;
    }

    public function close(): void
    {
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

    private function writeCsv(array $data): void
    {
        if ($this->firstItem) {
            $this->csvHeaders = array_keys($data);
            $this->putCsvRow($this->csvHeaders);
        }

        $row = array_map(
            fn (string $col) => isset($data[$col])
                ? (is_array($data[$col])
                    ? json_encode($data[$col], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $data[$col])
                : '',
            $this->csvHeaders,
        );

        $this->putCsvRow($row);
    }

    private function putCsvRow(array $row): void
    {
        $tmp = fopen('php://temp', 'r+');
        if ($tmp === false) {
            return;
        }
        fputcsv($tmp, $row);
        rewind($tmp);
        $line = stream_get_contents($tmp);
        fclose($tmp);

        if (is_string($line)) {
            fwrite($this->handle, $line);
        }
    }
}
