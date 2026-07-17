<?php

namespace DataHelm\Crawler\Tests\Output;

use DataHelm\Crawler\Output\CsvExporter;
use DataHelm\Crawler\Output\JsonExporter;
use DataHelm\Crawler\Output\JsonlExporter;
use DataHelm\Crawler\Output\MarkdownExporter;
use DataHelm\Crawler\Scraping\ScrapedItem;
use PHPUnit\Framework\TestCase;

/**
 * Buffered exporters: JSON array, JSONL, CSV (with union headers) and Markdown.
 */
final class ExportersTest extends TestCase
{
    /** @return list<ScrapedItem> */
    private function items(): array
    {
        return [
            new ScrapedItem(['title' => 'A', 'price' => '10']),
            new ScrapedItem(['title' => 'B', 'price' => '20', 'category' => 'shoes']),
        ];
    }

    public function test_json_exporter_produces_a_decodable_array(): void
    {
        $out = (new JsonExporter())->export($this->items());
        $decoded = json_decode($out, true);

        $this->assertCount(2, $decoded);
        $this->assertSame('A', $decoded[0]['title']);
        $this->assertSame('shoes', $decoded[1]['category']);
    }

    public function test_jsonl_exporter_writes_one_object_per_line(): void
    {
        $out = (new JsonlExporter())->export($this->items());
        $lines = explode("\n", $out);

        $this->assertCount(2, $lines);
        $this->assertSame(['title' => 'A', 'price' => '10'], json_decode($lines[0], true));
    }

    public function test_csv_exporter_unions_headers_across_items(): void
    {
        $out = (new CsvExporter())->export($this->items());
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_filter(explode("\n", trim($out))),
        );

        $this->assertSame(['title', 'price', 'category'], $rows[0]);
        $this->assertSame(['A', '10', ''], $rows[1]);
        $this->assertSame(['B', '20', 'shoes'], $rows[2]);
    }

    public function test_csv_exporter_json_encodes_array_cells(): void
    {
        $out = (new CsvExporter())->export([
            new ScrapedItem(['title' => 'A', 'images' => ['a.jpg', 'b.jpg']]),
        ]);

        // Parse the CSV back: the "images" cell must be the JSON-encoded array
        // (CSV quoting is applied on top and unwound by the parser).
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_filter(explode("\n", trim($out))),
        );

        $imagesCol = array_search('images', $rows[0], true);
        $this->assertSame('["a.jpg","b.jpg"]', $rows[1][$imagesCol]);
    }

    public function test_markdown_exporter_uses_title_heading_and_body(): void
    {
        $out = (new MarkdownExporter())->export([
            new ScrapedItem(['title' => 'Article', 'body' => 'Prose here', 'link' => 'https://a.com']),
        ]);

        $this->assertStringContainsString('## Article', $out);
        $this->assertStringContainsString('Prose here', $out);
        // Non heading/body scalar fields become a bullet list.
        $this->assertStringContainsString('- **link:**', $out);
    }

    public function test_empty_items_export_cleanly(): void
    {
        $this->assertSame('[]', (new JsonExporter())->export([]));
        $this->assertSame('', (new JsonlExporter())->export([]));
        $this->assertSame('', (new CsvExporter())->export([]));
        $this->assertSame('', (new MarkdownExporter())->export([]));
    }
}
