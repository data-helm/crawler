<?php

namespace DataHelm\Crawler\Tests\Blueprint;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use PHPUnit\Framework\TestCase;

final class ScrapeBlueprintTest extends TestCase
{
    public function test_output_format_is_read_from_the_documented_output_key(): void
    {
        // The README documents `{ "output": { "format": "markdown" } }`; the
        // persisted/round-tripped key is `output_config` (see toArray()) — both
        // must work.
        $blueprint = ScrapeBlueprint::fromArray([
            'url' => 'https://example.com',
            'item_selector' => '.item',
            'output' => ['format' => 'markdown'],
        ]);

        $this->assertSame('markdown', $blueprint->outputConfig->format);
    }

    public function test_output_format_is_read_from_the_persisted_output_config_key(): void
    {
        $blueprint = ScrapeBlueprint::fromArray([
            'url' => 'https://example.com',
            'item_selector' => '.item',
            'output_config' => ['format' => 'markdown'],
        ]);

        $this->assertSame('markdown', $blueprint->outputConfig->format);
    }

    public function test_output_config_takes_precedence_over_output_when_both_are_set(): void
    {
        $blueprint = ScrapeBlueprint::fromArray([
            'url' => 'https://example.com',
            'item_selector' => '.item',
            'output' => ['format' => 'csv'],
            'output_config' => ['format' => 'markdown'],
        ]);

        $this->assertSame('markdown', $blueprint->outputConfig->format);
    }
}
