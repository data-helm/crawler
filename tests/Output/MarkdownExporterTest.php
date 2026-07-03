<?php

namespace DataHelm\Crawler\Tests\Output;

use DataHelm\Crawler\Output\MarkdownExporter;
use DataHelm\Crawler\Scraping\ScrapedItem;
use PHPUnit\Framework\TestCase;

final class MarkdownExporterTest extends TestCase
{
    public function test_empty_item_list_produces_empty_output(): void
    {
        $this->assertSame('', (new MarkdownExporter())->export([]));
    }

    public function test_title_and_markdown_body_with_metadata_bullets(): void
    {
        $item = new ScrapedItem([
            'title'    => 'Auction lot #42',
            'markdown' => "## Description\n\nA nice house.",
            'price'    => 150000,
            'featured' => true,
            'tags'     => ['house', 'auction'],
        ]);

        $out = (new MarkdownExporter())->export([$item]);

        $expected = "## Auction lot #42\n\n"
            . "## Description\n\nA nice house.\n\n"
            . "- **price:** 150000\n"
            . "- **featured:** true\n"
            . "- **tags:** house, auction\n";

        $this->assertSame($expected, $out);
    }

    public function test_falls_back_to_positional_heading_when_no_title_like_field(): void
    {
        $item = new ScrapedItem(['price' => 10]);

        $out = (new MarkdownExporter())->export([$item]);

        $this->assertStringStartsWith("## Item 1\n", $out);
    }

    public function test_items_are_separated_by_horizontal_rule(): void
    {
        $items = [
            new ScrapedItem(['title' => 'First']),
            new ScrapedItem(['title' => 'Second']),
        ];

        $out = (new MarkdownExporter())->export($items);

        $this->assertSame("## First\n\n---\n\n## Second\n", $out);
    }

    public function test_empty_and_null_and_blank_values_are_omitted_from_metadata(): void
    {
        $item = new ScrapedItem([
            'title' => 'X',
            'empty_string' => '',
            'null_value' => null,
            'empty_array' => [],
            'kept' => 'value',
        ]);

        $out = (new MarkdownExporter())->export([$item]);

        $this->assertStringNotContainsString('empty_string', $out);
        $this->assertStringNotContainsString('null_value', $out);
        $this->assertStringNotContainsString('empty_array', $out);
        $this->assertStringContainsString('- **kept:** value', $out);
    }
}
