<?php

namespace DataHelm\Crawler\Http;

/**
 * Decorates any {@see HttpClient} (and {@see HttpRequester}) with a {@see UrlGuard}
 * SSRF check, run before every request. Because it wraps the shared base client,
 * a single wrap protects every fetch the engine makes — list pages, pagination,
 * detail pages (whose URLs come from scraped content), and API-mode calls.
 *
 * When the guard is disabled (the default) the scheme check is the only cost, so
 * this is safe to apply unconditionally.
 */
final class GuardedHttpClient implements HttpClient, HttpRequester
{
    public function __construct(
        private readonly HttpClient $inner,
        private readonly UrlGuard $guard,
    ) {
    }

    public function get(string $url): string
    {
        $this->guard->assert($url);

        return $this->inner->get($url);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,scalar> $query
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $query = [],
    ): string {
        $this->guard->assert($url);

        if (! $this->inner instanceof HttpRequester) {
            throw new \LogicException('Wrapped transport does not support API-mode requests.');
        }

        return $this->inner->request($method, $url, $headers, $body, $query);
    }

    public function getInner(): HttpClient
    {
        return $this->inner;
    }
}
