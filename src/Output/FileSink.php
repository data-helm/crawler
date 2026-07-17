<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Streams items to a file (json / jsonl / csv) via {@see StreamWriter}.
 *
 * This is the default sink and reproduces the historical behaviour: one file
 * per scrape under the configured output directory, named after the scrape.
 * A destination of "-" writes to STDOUT. Subclasses fix the format so the
 * container can resolve a concrete default (see {@see JsonFileSink}).
 */
class FileSink implements OutputSink
{
    private ?StreamWriter $writer = null;
    private string $resolvedPath = '';

    public function __construct(
        private readonly string $outputDir,
        private readonly string $format = 'json',
        private readonly ?string $destination = null,
    ) {
    }

    public function open(string $name): void
    {
        $this->resolvedPath = $this->resolvePath($name);
        $this->writer = new StreamWriter($this->resolvedPath, $this->format);
    }

    public function write(ScrapedItem $item): void
    {
        $this->writer?->write($item);
    }

    public function close(): string
    {
        $this->writer?->close();
        $this->writer = null;

        return $this->resolvedPath === '-' ? 'STDOUT' : $this->resolvedPath;
    }

    private function resolvePath(string $name): string
    {
        if ($this->destination === '-') {
            return '-';
        }

        if (is_string($this->destination) && $this->destination !== '') {
            return $this->destination;
        }

        $ext = match ($this->format) {
            'jsonl'    => 'jsonl',
            'csv'      => 'csv',
            'markdown' => 'md',
            default    => 'json',
        };

        return rtrim($this->outputDir, '/') . '/' . $name . '.' . $ext;
    }
}
