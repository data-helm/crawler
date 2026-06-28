<?php

namespace DataHelm\Crawler\Scraping;

use DataHelm\Crawler\Blueprint\ApiConfig;
use DataHelm\Crawler\Blueprint\ApiDetailConfig;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Http\HttpRequester;
use DataHelm\Crawler\Media\ItemImageResolver;
use DataHelm\Crawler\Pipeline\ItemPipeline;

/**
 * Crawls a JSON API instead of HTML (blueprint mode "api").
 *
 * Calls the configured endpoint, walks `items_path` to the list of records,
 * extracts each via JSON dot-paths, optionally fetches a per-item detail
 * document, then applies the same pipeline / dedup / filters / images / output
 * transforms as the HTML engine.
 *
 * Pagination uses an incrementing page parameter injected into the query string
 * (or the request body when `page_in_body` is set), stopping when a page returns
 * no items, the total count is reached, or max_pages / limit is hit.
 */
final class ApiCrawler
{
    private ?CrawlStats $lastStats = null;

    public function __construct(
        private readonly HttpRequester $http,
        private readonly ItemPipeline $pipeline,
        private readonly ItemImageResolver $imageResolver,
    ) {
    }

    /**
     * @param (callable(string):void)|null     $onPage
     * @param (callable(ScrapedItem):void)|null $onItem
     *
     * @return list<ScrapedItem>
     */
    public function crawl(
        ScrapeBlueprint $blueprint,
        ?callable $onPage = null,
        int $limit = 0,
        ?callable $onItem = null,
    ): array {
        $stats = new CrawlStats();
        $this->lastStats = $stats;

        $api = $blueprint->api;
        if ($api === null || $api->endpoint === '') {
            $stats->finish();

            return [];
        }

        $extractor       = new JsonItemExtractor($blueprint->fields);
        $detailExtractor = $blueprint->detailFields !== []
            ? new JsonItemExtractor($blueprint->detailFields)
            : null;

        $effectiveLimit = $limit > 0 ? $limit : $blueprint->crawlConfig->maxItems;

        $items     = [];
        $seenKeys  = [];
        $streaming = $onItem !== null && $blueprint->outputConfig->stream;

        $page  = $api->startPage;
        $pages = 0;
        $total = null;

        while (PageCap::allowsMore($pages, $blueprint->maxPages)) {
            if ($blueprint->httpConfig->delayMs > 0) {
                usleep($blueprint->httpConfig->delayMs * 1000);
            }

            [$url, $body, $query] = $this->buildRequestParts($api, $page);

            if ($onPage !== null) {
                $onPage($url);
            }

            try {
                $raw = $this->http->request($api->method, $url, $api->headers, $body, $query);
                $decoded = json_decode($raw, true);
                $stats->pagesFetched++;
            } catch (\Throwable $e) {
                $stats->pagesFailed++;
                $this->diagnose($pages, 'request failed: ' . $e->getMessage());
                break;
            }

            if (! is_array($decoded)) {
                $this->diagnose($pages, sprintf(
                    'response is not JSON (got %d bytes starting "%s"). The endpoint may be blocked '
                    . '(anti-bot/expired cookies) or returning HTML.',
                    strlen($raw),
                    substr(preg_replace('/\s+/', ' ', trim($raw)) ?? '', 0, 120),
                ));
                break;
            }

            $total ??= $this->resolveTotal($decoded, $api);

            $records = JsonPath::get($decoded, $api->itemsPath);
            if (! is_array($records) || $records === []) {
                $this->diagnose($pages, sprintf(
                    'no items at items_path "%s". Top-level keys in the response: [%s].',
                    $api->itemsPath,
                    implode(', ', array_slice(array_keys($decoded), 0, 20)),
                ));
                break;
            }

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $item = $extractor->extract($record);

                if ($detailExtractor !== null && $api->detail->enabled) {
                    $this->mergeDetail($item, $api->detail, $detailExtractor, $stats);
                }

                $item = $this->pipeline->process($item, $blueprint->url);

                if ($blueprint->dedup->enabled) {
                    $key = (string) $item->get($blueprint->dedup->keyField);
                    if ($key !== '' && isset($seenKeys[$key])) {
                        $stats->itemsDeduped++;
                        continue;
                    }
                    if ($key !== '') {
                        $seenKeys[$key] = true;
                    }
                }

                if (! $blueprint->filters->passes($item)) {
                    $stats->itemsFiltered++;
                    continue;
                }

                if ($blueprint->getPrimaryImage || $blueprint->getAllImages || $blueprint->getGalleryImages) {
                    $this->imageResolver->enrich($item, $blueprint);
                }

                $stats->itemsScraped++;

                if ($streaming) {
                    $onItem($item);
                } else {
                    $items[] = $item;
                }

                if ($blueprint->crawlConfig->delayBetweenItemsMs > 0) {
                    usleep($blueprint->crawlConfig->delayBetweenItemsMs * 1000);
                }

                if ($effectiveLimit > 0 && $stats->itemsScraped >= $effectiveLimit) {
                    $stats->finish();

                    return $streaming ? [] : $blueprint->outputConfig->applyToItems($items);
                }
            }

            $pages++;
            $page++;

            // No pagination configured → single request only.
            $usesPathPagination = str_contains($api->endpoint, '{page}')
                || str_contains($api->endpoint, '{page_size}');
            if ($api->pageParam === null && ! $usesPathPagination) {
                break;
            }

            // Stop once we've fetched everything the API says it has.
            if ($total !== null && $stats->itemsScraped >= $total) {
                break;
            }

            if ($blueprint->crawlConfig->delayBetweenPagesMs > 0) {
                usleep($blueprint->crawlConfig->delayBetweenPagesMs * 1000);
            }
        }

        $stats->finish();

        return $streaming ? [] : $blueprint->outputConfig->applyToItems($items);
    }

    public function getLastStats(): ?CrawlStats
    {
        return $this->lastStats;
    }

    // -------------------------------------------------------------------------

    /**
     * Builds the per-page URL, body and query, injecting pagination params.
     *
     * @return array{0:string,1:?string,2:array<string,scalar>}
     */
    private function buildRequestParts(ApiConfig $api, int $page): array
    {
        $query = $api->query;
        $body  = $api->body;
        $url   = $api->endpoint;

        // Path-based pagination: .../GetLotes/{page}/{page_size}
        if (str_contains($url, '{page}') || str_contains($url, '{page_size}')) {
            $url = str_replace(
                ['{page}', '{page_size}'],
                [(string) $page, (string) $api->pageSize],
                $url,
            );
        } elseif ($api->pageParam !== null) {
            if ($api->pageInBody) {
                $body[$api->pageParam] = $page;
                if ($api->pageSizeParam !== null) {
                    $body[$api->pageSizeParam] = $api->pageSize;
                }
                // DataTables-style offset pagination: advance start/length when
                // the body already declares them (e.g. Copart's payload).
                if (array_key_exists('start', $body)) {
                    $body['start'] = $page * $api->pageSize;
                }
                if (array_key_exists('length', $body)) {
                    $body['length'] = $api->pageSize;
                }
            } else {
                $query[$api->pageParam] = $page;
                if ($api->pageSizeParam !== null) {
                    $query[$api->pageSizeParam] = $api->pageSize;
                }
            }
        }

        $encodedBody = $this->encodeBody($api->method, $body, $api->bodyFormat);

        /** @var array<string,scalar> $query */
        return [$url, $encodedBody, $query];
    }

    /**
     * Surfaces why a page yielded nothing — only for the first page, to STDERR,
     * so a misconfigured endpoint/items_path or an anti-bot block is obvious
     * instead of silently producing 0 items.
     */
    private function diagnose(int $pageIndex, string $message): void
    {
        if ($pageIndex === 0) {
            fwrite(STDERR, 'API: ' . $message . PHP_EOL);
        }
    }

    /**
     * @param array<string,mixed> $body
     */
    private function encodeBody(string $method, array $body, string $format): ?string
    {
        if (strtoupper($method) === 'GET') {
            return null;
        }

        // Some POST APIs (e.g. Sublime GetLotes) require a JSON body even when
        // empty — sending no body returns 400.
        if ($body === []) {
            return $format === 'form' ? '' : '{}';
        }

        return $format === 'form'
            ? http_build_query($body)
            : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function mergeDetail(
        ScrapedItem $item,
        ApiDetailConfig $detail,
        JsonItemExtractor $detailExtractor,
        CrawlStats $stats,
    ): void {
        $url = $this->fillTemplate($detail->endpoint, $item);
        if ($url === '' || str_contains($url, '{')) {
            return; // Unresolved placeholder — skip.
        }

        $body = $this->encodeBody($detail->method, $detail->body, $detail->bodyFormat);

        try {
            $raw     = $this->http->request($detail->method, $url, $detail->headers, $body, $detail->query);
            $decoded = json_decode($raw, true);
            $stats->detailFetched++;
        } catch (\Throwable) {
            $stats->detailFailed++;

            return;
        }

        if (! is_array($decoded)) {
            return;
        }

        $node = JsonPath::get($decoded, $detail->itemsPath);
        if (! is_array($node)) {
            return;
        }

        foreach ($detailExtractor->extract($node)->toArray() as $key => $value) {
            $item->set($key, $value);
        }
    }

    /**
     * Replaces {field} placeholders in a URL template with item field values.
     */
    private function fillTemplate(string $template, ScrapedItem $item): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            static function (array $m) use ($item): string {
                $value = $item->get($m[1]);

                return is_scalar($value) ? rawurlencode((string) $value) : $m[0];
            },
            $template,
        );
    }

    private function resolveTotal(mixed $decoded, ApiConfig $api): ?int
    {
        if ($api->totalPath === null) {
            return null;
        }

        $total = JsonPath::get($decoded, $api->totalPath);

        return is_numeric($total) ? (int) $total : null;
    }
}
