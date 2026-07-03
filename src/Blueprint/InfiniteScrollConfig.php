<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Infinite-scroll pagination (used when the pagination strategy is
 * "infinite_scroll").
 *
 * Many sites render the first batch of items server-side, then fetch each
 * subsequent batch from an endpoint — usually a POST that returns an HTML
 * fragment of more rows — when the user scrolls or clicks a "load more" button.
 * The crawl engine fetches the first page normally, scrapes a token from it if
 * needed, then calls this endpoint with an incrementing offset/page value until
 * a batch comes back empty or max_pages is reached. The returned fragment is
 * parsed with the same item_selector as the main page, and the session cookie
 * set on page one is reused automatically.
 *
 * endpoint    — URL to call for more items ("" = the listing URL itself).
 * method      — GET or POST.
 * body_format — POST body encoding: "form" (urlencoded, default) or "json".
 * param       — name of the pagination parameter (e.g. "limit", "page", "offset").
 * param_mode  — "offset" (param = number of items already loaded) or
 *               "page" (param = page index).
 * page_size   — batch size; the step added each iteration in offset mode.
 * start       — the parameter value for the *second* batch (the first page is
 *               the initial GET). Offset example: 20. Page example: 2.
 * params      — extra static parameters sent on every request.
 * headers     — extra request headers.
 * token       — optional CSRF/anti-forgery token scraped from the first page:
 *                 token_css       CSS selector pointing at the token element
 *                 token_attribute attribute to read ("" = element text)
 *                 token_param     request parameter name to send it under
 * stop_when_empty — stop as soon as a batch returns no items (default true).
 */
final class InfiniteScrollConfig
{
    /**
     * @param array<string,scalar> $params
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $endpoint = '',
        public readonly string $method = 'POST',
        public readonly string $bodyFormat = 'form',
        public readonly string $param = 'page',
        public readonly string $paramMode = 'offset',
        public readonly int $pageSize = 20,
        public readonly int $start = 20,
        public readonly array $params = [],
        public readonly array $headers = [],
        public readonly string $tokenCss = '',
        public readonly string $tokenAttribute = 'value',
        public readonly string $tokenParam = '_token',
        public readonly bool $stopWhenEmpty = true,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $token = is_array($data['token'] ?? null) ? $data['token'] : [];

        return new self(
            endpoint:       (string) ($data['endpoint'] ?? ''),
            method:         strtoupper((string) ($data['method'] ?? 'POST')),
            bodyFormat:     in_array($data['body_format'] ?? 'form', ['json', 'form'], true) ? (string) $data['body_format'] : 'form',
            param:          (string) ($data['param'] ?? 'page'),
            paramMode:      in_array($data['param_mode'] ?? 'offset', ['offset', 'page'], true) ? (string) $data['param_mode'] : 'offset',
            pageSize:       max(1, (int) ($data['page_size'] ?? 20)),
            start:          (int) ($data['start'] ?? 20),
            params:         is_array($data['params'] ?? null) ? $data['params'] : [],
            headers:        is_array($data['headers'] ?? null) ? $data['headers'] : [],
            tokenCss:       (string) ($token['css'] ?? ''),
            tokenAttribute: (string) ($token['attribute'] ?? 'value'),
            tokenParam:     (string) ($token['param'] ?? '_token'),
            stopWhenEmpty:  (bool) ($data['stop_when_empty'] ?? true),
        );
    }

    public function toArray(): array
    {
        return [
            'endpoint'        => $this->endpoint,
            'method'          => $this->method,
            'body_format'     => $this->bodyFormat,
            'param'           => $this->param,
            'param_mode'      => $this->paramMode,
            'page_size'       => $this->pageSize,
            'start'           => $this->start,
            'params'          => (object) $this->params,
            'headers'         => (object) $this->headers,
            'token'           => [
                'css'       => $this->tokenCss,
                'attribute' => $this->tokenAttribute,
                'param'     => $this->tokenParam,
            ],
            'stop_when_empty' => $this->stopWhenEmpty,
        ];
    }

    /**
     * The parameter value for the i-th extra batch (0-based).
     */
    public function valueForBatch(int $i): int
    {
        return $this->paramMode === 'page'
            ? $this->start + $i
            : $this->start + ($i * $this->pageSize);
    }
}
