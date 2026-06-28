<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * JSON-API source configuration (used when blueprint mode is "api").
 *
 * Instead of scraping rendered HTML, the engine calls a JSON endpoint directly
 * — the same one a JavaScript SPA calls in the background — and reads fields by
 * dot-path. This is the right approach for sites like Copart whose listing is
 * powered by an XHR/fetch call to a clean JSON API.
 *
 * endpoint        — the JSON URL to call.
 * method          — GET or POST.
 * headers         — extra request headers (Content-Type, Accept, auth, …).
 * body            — request body (associative array) for POST.
 * body_format     — how to encode the body: "json" (default) or "form"
 *                   (application/x-www-form-urlencoded; nested arrays become
 *                   key[sub]=… like DataTables/Copart payloads).
 * query           — query-string parameters merged into the URL.
 * items_path      — dot-path to the array of items in the response
 *                   (e.g. "data.results.content"); "" means the decoded root is
 *                   itself the list.
 * total_path      — optional dot-path to the total result count, used to stop
 *                   paginating once every item has been seen.
 * page_param      — query/body key carrying the page number (e.g. "page");
 *                   null disables API pagination (single request).
 * page_size_param — query/body key carrying the page size (e.g. "size").
 * page_size       — items requested per page.
 * start_page      — first page index (0 for zero-based APIs, 1 for one-based).
 * page_in_body    — inject page/size into the JSON body instead of the query string.
 * detail          — optional per-item second request for richer data.
 */
final class ApiConfig
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $body
     * @param array<string,string> $query
     */
    public function __construct(
        public readonly string $endpoint = '',
        public readonly string $method = 'GET',
        public readonly array $headers = [],
        public readonly array $body = [],
        public readonly string $bodyFormat = 'json',
        public readonly array $query = [],
        public readonly string $itemsPath = '',
        public readonly ?string $totalPath = null,
        public readonly ?string $pageParam = null,
        public readonly ?string $pageSizeParam = null,
        public readonly int $pageSize = 100,
        public readonly int $startPage = 0,
        public readonly bool $pageInBody = false,
        public readonly ApiDetailConfig $detail = new ApiDetailConfig(),
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            endpoint:      (string) ($data['endpoint'] ?? ''),
            method:        strtoupper((string) ($data['method'] ?? 'GET')),
            headers:       is_array($data['headers'] ?? null) ? $data['headers'] : [],
            body:          is_array($data['body'] ?? null) ? $data['body'] : [],
            bodyFormat:    in_array($data['body_format'] ?? 'json', ['json', 'form'], true) ? (string) ($data['body_format'] ?? 'json') : 'json',
            query:         is_array($data['query'] ?? null) ? $data['query'] : [],
            itemsPath:     (string) ($data['items_path'] ?? ''),
            totalPath:     isset($data['total_path']) && $data['total_path'] !== '' ? (string) $data['total_path'] : null,
            pageParam:     isset($data['page_param']) && $data['page_param'] !== '' ? (string) $data['page_param'] : null,
            pageSizeParam: isset($data['page_size_param']) && $data['page_size_param'] !== '' ? (string) $data['page_size_param'] : null,
            pageSize:      max(1, (int) ($data['page_size'] ?? 100)),
            startPage:     (int) ($data['start_page'] ?? 0),
            pageInBody:    (bool) ($data['page_in_body'] ?? false),
            detail:        ApiDetailConfig::fromArray($data['detail'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'endpoint'        => $this->endpoint,
            'method'          => $this->method,
            'headers'         => (object) $this->headers,
            'body'            => (object) $this->body,
            'body_format'     => $this->bodyFormat,
            'query'           => (object) $this->query,
            'items_path'      => $this->itemsPath,
            'total_path'      => $this->totalPath,
            'page_param'      => $this->pageParam,
            'page_size_param' => $this->pageSizeParam,
            'page_size'       => $this->pageSize,
            'start_page'      => $this->startPage,
            'page_in_body'    => $this->pageInBody,
            'detail'          => $this->detail->toArray(),
        ];
    }
}
