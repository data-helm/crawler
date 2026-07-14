<?php

namespace DataHelm\Crawler\Tests\Detection;

use DataHelm\Crawler\Detection\JsonStructureDetector;
use PHPUnit\Framework\TestCase;

/**
 * The JSON structure detector picks the "list of records" a listing API returns.
 * These cases lock in the ranking that stops facet/aggregation arrays and long
 * lookup lists from beating the real (often page-sized) data — the autoscar and
 * primeiramaosaga regressions.
 */
final class JsonStructureDetectorTest extends TestCase
{
    private JsonStructureDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new JsonStructureDetector();
    }

    public function test_detects_a_root_level_array_of_records(): void
    {
        $data = [
            ['id' => 1, 'name' => 'A', 'price' => 10],
            ['id' => 2, 'name' => 'B', 'price' => 20],
        ];

        $result = $this->detector->detect($data);

        $this->assertNotNull($result);
        $this->assertSame('', $result['path']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(['id' => 1, 'name' => 'A', 'price' => 10], $result['sample']);
    }

    public function test_finds_a_nested_items_array_by_path(): void
    {
        $data = ['data' => ['results' => [
            ['id' => 1, 'title' => 'x', 'price' => 5],
            ['id' => 2, 'title' => 'y', 'price' => 6],
        ]]];

        $result = $this->detector->detect($data);

        $this->assertNotNull($result);
        $this->assertSame('data.results', $result['path']);
        $this->assertSame(2, $result['count']);
    }

    public function test_prefers_record_data_over_a_larger_facet_bucket_list(): void
    {
        // The real listing endpoint returns its records next to much longer
        // aggregation/facet arrays. Count alone would pick the facets; the
        // detector must pick "data" (the rich records) instead.
        $data = [
            'data' => [
                ['id' => 1, 'model' => 'Compass', 'price' => 100, 'color' => 'white'],
                ['id' => 2, 'model' => 'Polo', 'price' => 90, 'color' => 'black'],
            ],
            'filters' => [
                'year'  => array_map(fn ($y) => [(string) $y => 1], range(1990, 2026)), // 37 buckets
                'price' => array_map(fn ($p) => [(string) $p => 1], range(1, 100)),      // 100 buckets
            ],
        ];

        $result = $this->detector->detect($data);

        $this->assertNotNull($result);
        $this->assertSame('data', $result['path'], 'Should pick the records, not the facet buckets');
        $this->assertSame(2, $result['count']);
    }

    public function test_prefers_a_named_data_path_over_an_aggregation_named_path(): void
    {
        // aggregations.MODEL.elements looked like records (rich objects) AND was
        // longer than "results" — the primeiramaosaga case. A facet segment in
        // the path must demote it below the real "results" list.
        $data = [
            'results' => [
                ['id' => 1, 'vehicleType' => 'CAR', 'price' => 100, 'makeName' => 'Ford'],
            ],
            'aggregations' => [
                'MODEL' => [
                    'elements' => array_map(
                        fn ($i) => ['id' => $i, 'name' => "m$i", 'count' => $i, 'selected' => false],
                        range(1, 174),
                    ),
                ],
            ],
        ];

        $result = $this->detector->detect($data);

        $this->assertNotNull($result);
        $this->assertSame('results', $result['path']);
    }

    public function test_returns_null_when_there_is_no_list_of_objects(): void
    {
        $this->assertNull($this->detector->detect(['status' => 'ok', 'count' => 0]));
        $this->assertNull($this->detector->detect([]));
        $this->assertNull($this->detector->detect('not json'));
    }

    public function test_a_list_of_scalars_is_not_treated_as_records(): void
    {
        $this->assertNull($this->detector->detect(['tags' => ['a', 'b', 'c']]));
    }

    public function test_shallower_path_wins_on_an_otherwise_equal_match(): void
    {
        $records = [['id' => 1, 'a' => 1, 'b' => 2], ['id' => 2, 'a' => 3, 'b' => 4]];
        $data = ['items' => $records, 'nested' => ['deep' => ['items' => $records]]];

        $result = $this->detector->detect($data);

        $this->assertNotNull($result);
        $this->assertSame('items', $result['path']);
    }
}
