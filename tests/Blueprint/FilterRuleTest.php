<?php

namespace DataHelm\Crawler\Tests\Blueprint;

use DataHelm\Crawler\Blueprint\FilterRule;
use DataHelm\Crawler\Scraping\ScrapedItem;
use PHPUnit\Framework\TestCase;

/**
 * Every result_filters operator, exercised against a representative item.
 */
final class FilterRuleTest extends TestCase
{
    private function item(): ScrapedItem
    {
        return new ScrapedItem([
            'title' => 'Apartamento Centro',
            'price' => 'R$ 250.000',
            'area'  => '80',
            'empty' => '',
            'tags'  => ['novo', 'centro'],
        ]);
    }

    public function test_not_empty_and_empty(): void
    {
        $this->assertTrue((new FilterRule('title', 'not_empty'))->passes($this->item()));
        $this->assertFalse((new FilterRule('empty', 'not_empty'))->passes($this->item()));
        $this->assertTrue((new FilterRule('empty', 'empty'))->passes($this->item()));
        $this->assertFalse((new FilterRule('title', 'empty'))->passes($this->item()));
        // A missing field counts as empty.
        $this->assertTrue((new FilterRule('nope', 'empty'))->passes($this->item()));
    }

    public function test_contains_and_not_contains(): void
    {
        $this->assertTrue((new FilterRule('title', 'contains', 'Centro'))->passes($this->item()));
        $this->assertFalse((new FilterRule('title', 'contains', 'Praia'))->passes($this->item()));
        $this->assertTrue((new FilterRule('title', 'not_contains', 'Praia'))->passes($this->item()));
        $this->assertFalse((new FilterRule('title', 'not_contains', 'Centro'))->passes($this->item()));
    }

    public function test_equals_and_not_equals(): void
    {
        $this->assertTrue((new FilterRule('area', 'equals', '80'))->passes($this->item()));
        $this->assertFalse((new FilterRule('area', 'equals', '81'))->passes($this->item()));
        $this->assertTrue((new FilterRule('area', 'not_equals', '81'))->passes($this->item()));
    }

    public function test_matches_regex(): void
    {
        $this->assertTrue((new FilterRule('price', 'matches', '/R\$\s*[\d.]+/'))->passes($this->item()));
        $this->assertFalse((new FilterRule('title', 'matches', '/^\d+$/'))->passes($this->item()));
    }

    public function test_gt_and_lt_numeric(): void
    {
        $this->assertTrue((new FilterRule('area', 'gt', '50'))->passes($this->item()));
        $this->assertFalse((new FilterRule('area', 'gt', '100'))->passes($this->item()));
        $this->assertTrue((new FilterRule('area', 'lt', '100'))->passes($this->item()));
        // Non-numeric field never satisfies gt/lt.
        $this->assertFalse((new FilterRule('title', 'gt', '10'))->passes($this->item()));
    }

    public function test_array_field_is_joined_for_contains(): void
    {
        // tags => ['novo','centro'] is flattened to "novo centro" for matching.
        $this->assertTrue((new FilterRule('tags', 'contains', 'centro'))->passes($this->item()));
    }

    public function test_unknown_operator_keeps_the_item(): void
    {
        $this->assertTrue((new FilterRule('title', 'bogus'))->passes($this->item()));
    }
}
