<?php

namespace DataHelm\Crawler\Tests\Scraping;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Http\HttpRequester;
use DataHelm\Crawler\Media\ItemImageResolver;
use DataHelm\Crawler\Pipeline\ItemPipeline;
use DataHelm\Crawler\Pipeline\SchemaCoercionProcessor;
use DataHelm\Crawler\Scraping\ApiCrawler;
use DataHelm\Crawler\Scraping\CrawlState;
use PHPUnit\Framework\TestCase;

/**
 * Regression: resumable dedup state (--resume) was wired only into the HTML path,
 * so API-mode robots re-scraped everything. The ApiCrawler must now pre-load
 * seen keys from a {@see CrawlState} and record new ones back into it.
 */
final class ApiCrawlerResumeTest extends TestCase
{
    private function staticRequester(string $json): HttpRequester
    {
        return new class($json) implements HttpRequester {
            public function __construct(private string $json)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null, array $query = []): string
            {
                // One page of records, then empty to stop pagination.
                return (int) ($query['page'] ?? 0) === 0 ? $this->json : json_encode(['results' => []]);
            }
        };
    }

    private function blueprint(): ScrapeBlueprint
    {
        return ScrapeBlueprint::fromArray([
            'url'  => 'https://site.test/listing',
            'mode' => 'api',
            'api'  => [
                'endpoint'   => 'https://api.site.test/v1/deal',
                'method'     => 'GET',
                'items_path' => 'results',
                'page_param' => 'page',
                'page_size'  => 100,
            ],
            'fields' => [['name' => 'id', 'css' => 'id', 'type' => 'json']],
            'dedup'  => ['enabled' => true, 'key_field' => 'id'],
        ]);
    }

    public function test_preloaded_state_skips_already_seen_items(): void
    {
        $http  = $this->staticRequester((string) json_encode(['results' => [['id' => 1], ['id' => 2], ['id' => 3]]]));
        $state = new CrawlState(seenKeys: ['1' => true, '2' => true]);

        $crawler = new ApiCrawler($http, new ItemPipeline([]), new ItemImageResolver(), $state);
        $items   = $crawler->crawl($this->blueprint());

        // Only id=3 is new; ids 1 and 2 were pre-seen and skipped.
        // (JsonItemExtractor returns scalar values as trimmed strings.)
        $this->assertCount(1, $items);
        $this->assertSame('3', $items[0]->get('id'));
        $this->assertSame(2, $crawler->getLastStats()?->itemsDeduped);
    }

    public function test_new_keys_are_recorded_back_into_state(): void
    {
        $http  = $this->staticRequester((string) json_encode(['results' => [['id' => 10], ['id' => 11]]]));
        $state = new CrawlState();

        $crawler = new ApiCrawler($http, new ItemPipeline([]), new ItemImageResolver(), $state);
        $crawler->crawl($this->blueprint());

        // Both new keys are now persisted in the resume state for the next run.
        $this->assertTrue($state->hasSeen('10'));
        $this->assertTrue($state->hasSeen('11'));
        $this->assertSame(2, $state->itemCount);
    }

    public function test_item_schema_pipeline_is_applied_in_api_mode(): void
    {
        // Regression for bug #5: item_schema coercion was skipped in API mode.
        // The engine builds a pipeline carrying a SchemaCoercionProcessor and
        // hands it to the ApiCrawler; the raw JSON string price must become a float.
        $http     = $this->staticRequester((string) json_encode(['results' => [['id' => 1, 'price' => 'R$ 1.234,56']]]));
        $pipeline = (new ItemPipeline([]))->withExtra(new SchemaCoercionProcessor(['price' => 'float']));

        $blueprint = ScrapeBlueprint::fromArray([
            'url'    => 'https://site.test/listing',
            'mode'   => 'api',
            'api'    => ['endpoint' => 'https://api.site.test/v1/deal', 'method' => 'GET', 'items_path' => 'results', 'page_param' => 'page'],
            'fields' => [
                ['name' => 'id', 'css' => 'id', 'type' => 'json'],
                ['name' => 'price', 'css' => 'price', 'type' => 'json'],
            ],
            'dedup' => ['enabled' => false, 'key_field' => 'id'],
        ]);

        $items = (new ApiCrawler($http, $pipeline, new ItemImageResolver()))->crawl($blueprint);

        $this->assertSame(1234.56, $items[0]->get('price'));
    }
}
