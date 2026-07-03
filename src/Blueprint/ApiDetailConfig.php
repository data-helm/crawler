<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Per-item detail request for API mode.
 *
 * After an item is extracted from the listing JSON, its field values can be
 * substituted into the endpoint template ({field} placeholders) to fetch a
 * second JSON document with more data (e.g. full lot details).
 *
 * endpoint   — URL template; {placeholders} are replaced with item field values,
 *              e.g. "https://site/public/data/lotdetails/solr/{lot}".
 * method     — GET or POST.
 * headers    — extra request headers.
 * body       — request body (associative array, JSON-encoded) for POST.
 * query      — query-string parameters.
 * items_path — dot-path to the object holding the detail fields in the response
 *              (e.g. "data.lotDetails"); "" means the decoded root.
 */
final class ApiDetailConfig
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $body
     * @param array<string,string> $query
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $endpoint = '',
        public readonly string $method = 'GET',
        public readonly array $headers = [],
        public readonly array $body = [],
        public readonly string $bodyFormat = 'json',
        public readonly array $query = [],
        public readonly string $itemsPath = '',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enabled:    (bool) ($data['enabled'] ?? false),
            endpoint:   (string) ($data['endpoint'] ?? ''),
            method:     strtoupper((string) ($data['method'] ?? 'GET')),
            headers:    is_array($data['headers'] ?? null) ? $data['headers'] : [],
            body:       is_array($data['body'] ?? null) ? $data['body'] : [],
            bodyFormat: in_array($data['body_format'] ?? 'json', ['json', 'form'], true) ? (string) ($data['body_format'] ?? 'json') : 'json',
            query:      is_array($data['query'] ?? null) ? $data['query'] : [],
            itemsPath:  (string) ($data['items_path'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'     => $this->enabled,
            'endpoint'    => $this->endpoint,
            'method'      => $this->method,
            'headers'     => (object) $this->headers,
            'body'        => (object) $this->body,
            'body_format' => $this->bodyFormat,
            'query'       => (object) $this->query,
            'items_path'  => $this->itemsPath,
        ];
    }
}
