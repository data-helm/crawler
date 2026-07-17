<?php

namespace DataHelm\Crawler\Tests\Output;

use DataHelm\Crawler\Output\StreamWriter;
use DataHelm\Crawler\Scraping\ScrapedItem;
use PHPUnit\Framework\TestCase;

/**
 * Guards against the streaming-CSV corruption: when items carry heterogeneous
 * keys, the CSV header must be the union of every item's keys and every row must
 * stay aligned to it — not frozen from item #1 (which dropped/​misaligned later
 * fields). Markdown streaming must likewise emit one coherent document.
 */
final class StreamWriterTest extends TestCase
{
    private function tmpFile(): string
    {
        return tempnam(sys_get_temp_dir(), 'dh_sw_') ?: throw new \RuntimeException('no tmp file');
    }

    public function test_streaming_csv_unions_headers_across_heterogeneous_items(): void
    {
        $path   = $this->tmpFile();
        $writer = new StreamWriter($path, 'csv');

        // Item #1 has only title/price; item #2 adds a "category" the header from
        // item #1 alone would have missed entirely.
        $writer->write(new ScrapedItem(['title' => 'A', 'price' => '10']));
        $writer->write(new ScrapedItem(['title' => 'B', 'price' => '20', 'category' => 'shoes']));
        $writer->close();

        $csv = (string) file_get_contents($path);
        unlink($path);

        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_filter(explode("\n", trim($csv))),
        );

        // Header is the union of all keys, in first-seen order.
        $this->assertSame(['title', 'price', 'category'], $rows[0]);
        // Row 1: category cell present but empty (item #1 had no category).
        $this->assertSame(['A', '10', ''], $rows[1]);
        // Row 2: category populated, still aligned under the right column.
        $this->assertSame(['B', '20', 'shoes'], $rows[2]);
    }

    public function test_streaming_markdown_emits_one_document(): void
    {
        $path   = $this->tmpFile();
        $writer = new StreamWriter($path, 'markdown');

        $writer->write(new ScrapedItem(['title' => 'First', 'body' => 'Hello world']));
        $writer->write(new ScrapedItem(['title' => 'Second', 'body' => 'Bye']));
        $writer->close();

        $md = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('## First', $md);
        $this->assertStringContainsString('Hello world', $md);
        $this->assertStringContainsString('## Second', $md);
        // Items are separated by the exporter's horizontal rule.
        $this->assertStringContainsString("\n---\n", $md);
    }

    public function test_streaming_jsonl_writes_one_object_per_line(): void
    {
        $path   = $this->tmpFile();
        $writer = new StreamWriter($path, 'jsonl');

        $writer->write(new ScrapedItem(['id' => 1]));
        $writer->write(new ScrapedItem(['id' => 2]));
        $writer->close();

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
        unlink($path);

        $this->assertCount(2, $lines);
        $this->assertSame(['id' => 1], json_decode($lines[0], true));
        $this->assertSame(['id' => 2], json_decode($lines[1], true));
    }
}
