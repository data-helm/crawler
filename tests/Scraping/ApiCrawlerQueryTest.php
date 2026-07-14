<?php

namespace DataHelm\Crawler\Tests\Scraping;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Http\HttpRequester;
use DataHelm\Crawler\Media\ItemImageResolver;
use DataHelm\Crawler\Pipeline\ItemPipeline;
use DataHelm\Crawler\Scraping\ApiCrawler;
use PHPUnit\Framework\TestCase;

/**
 * Records every request an {@see ApiCrawler} makes, and returns canned JSON.
 */
final class RecordingRequester implements HttpRequester
{
    /** @var list<array{method:string,url:string,query:array<string,mixed>}> */
    public array $calls = [];

    /** @param callable(int):string $responder page index → raw JSON body */
    public function __construct(private $responder)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $query = []): string
    {
        $page = (int) ($query['page'] ?? 0);
        $this->calls[] = ['method' => $method, 'url' => $url, 'query' => $query];

        return ($this->responder)($page);
    }
}

/**
 * The endpoint's own query parameters (sort, feature flags, filters) must survive
 * to the wire alongside the pagination params — the primeiramaosaga 400 where
 * dropping `sort` produced a Spring "parameter not present" error.
 */
final class ApiCrawlerQueryTest extends TestCase
{
    private function makeCrawler(RecordingRequester $http): ApiCrawler
    {
        return new ApiCrawler($http, new ItemPipeline([]), new ItemImageResolver());
    }

    private function blueprint(string $endpoint, int $startPage = 0): ScrapeBlueprint
    {
        return ScrapeBlueprint::fromArray([
            'url'  => 'https://site.test/listing',
            'mode' => 'api',
            'api'  => [
                'endpoint'        => $endpoint,
                'method'          => 'GET',
                'items_path'      => 'results',
                'page_param'      => 'page',
                'page_size_param' => 'size',
                'page_size'       => 100,
                'start_page'      => $startPage,
            ],
            'fields' => [
                ['name' => 'id', 'css' => 'id', 'type' => 'json'],
            ],
            'dedup' => ['enabled' => false, 'key_field' => 'id'],
        ]);
    }

    public function test_preserves_endpoint_query_params_alongside_pagination(): void
    {
        $http = new RecordingRequester(fn (int $page): string => $page === 0
            ? json_encode(['results' => [['id' => 1], ['id' => 2]]])
            : json_encode(['results' => []]));

        $endpoint = 'https://api.site.test/v1/deal?size=50&sort=0&isNotOpen=true&isServerSide=true';
        $this->makeCrawler($http)->crawl($this->blueprint($endpoint), limit: 2);

        $first = $http->calls[0];
        // The endpoint's own params must all be present...
        $this->assertSame('0', (string) $first['query']['sort']);
        $this->assertSame('true', (string) $first['query']['isNotOpen']);
        $this->assertSame('true', (string) $first['query']['isServerSide']);
        // ...plus the pagination params the crawler injects.
        $this->assertSame(0, $first['query']['page']);
        $this->assertArrayHasKey('size', $first['query']);
        // And the base URL is sent without a duplicate query string.
        $this->assertStringNotContainsString('?', $first['url']);
    }

    public function test_pagination_advances_the_page_param_until_an_empty_page(): void
    {
        $http = new RecordingRequester(fn (int $page): string => $page < 3
            ? json_encode(['results' => [['id' => $page * 10 + 1], ['id' => $page * 10 + 2]]])
            : json_encode(['results' => []]));

        $items = $this->makeCrawler($http)->crawl($this->blueprint('https://api.site.test/v1/deal'));

        // 3 pages of 2 records, then an empty 4th page stops the crawl.
        $this->assertCount(6, $items);
        $pages = array_map(fn ($c) => $c['query']['page'], $http->calls);
        $this->assertSame([0, 1, 2, 3], $pages);
    }

    public function test_respects_a_one_indexed_start_page(): void
    {
        $http = new RecordingRequester(fn (int $page): string => $page === 1
            ? json_encode(['results' => [['id' => 1]]])
            : json_encode(['results' => []]));

        $this->makeCrawler($http)->crawl($this->blueprint('https://api.site.test/v1/deal', startPage: 1), limit: 1);

        $this->assertSame(1, $http->calls[0]['query']['page'], 'First request must use start_page = 1');
    }
}
