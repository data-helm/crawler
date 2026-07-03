<?php

namespace DataHelm\Crawler\Tests\Detection;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Detection\BlueprintValidator;
use PHPUnit\Framework\TestCase;

final class BlueprintValidatorTest extends TestCase
{
    public function test_markdown_field_type_is_accepted(): void
    {
        $blueprint = ScrapeBlueprint::fromArray([
            'url' => 'https://example.com',
            'item_selector' => '.item',
            'fields' => [
                ['name' => 'body', 'css' => '', 'type' => 'markdown'],
            ],
        ]);

        $validator = new BlueprintValidator();
        $valid = $validator->validate($blueprint);

        $this->assertTrue($valid, implode('; ', $validator->errors()));
        $this->assertSame([], $validator->errors());
    }

    public function test_markdown_field_with_empty_css_does_not_warn(): void
    {
        $blueprint = ScrapeBlueprint::fromArray([
            'url' => 'https://example.com',
            'item_selector' => '.item',
            'fields' => [
                ['name' => 'body', 'css' => '', 'type' => 'markdown'],
            ],
        ]);

        $validator = new BlueprintValidator();
        $validator->validate($blueprint);

        $this->assertSame([], $validator->warnings());
    }

}
