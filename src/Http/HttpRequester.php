<?php

namespace DataHelm\Crawler\Http;

/**
 * Richer transport contract for API mode: arbitrary method, headers, body and
 * query string. {@see HttpClient} only fetches HTML via GET; this adds the POST
 * (and custom-header) capability JSON endpoints typically require.
 */
interface HttpRequester
{
    /**
     * @param array<string,string> $headers
     * @param array<string,scalar> $query
     *
     * @return string Raw response body.
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $query = [],
    ): string;
}
