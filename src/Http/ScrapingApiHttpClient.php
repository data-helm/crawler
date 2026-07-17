<?php

namespace DataHelm\Crawler\Http;

use DataHelm\Crawler\Blueprint\HttpConfig;
use GuzzleHttp\Client;

/**
 * {@see HttpClient} that fetches through a managed scraping API (ScraperAPI,
 * ZenRows, ScrapingBee, Zyte, …).
 *
 * These services maintain their own residential/mobile proxy pools and anti-bot
 * solving, so they get past aggressive WAFs (Akamai, PerimeterX, …) that a plain
 * HTTP client or even a self-hosted headless browser on a datacenter IP cannot.
 *
 * They share one calling convention: a GET to the service endpoint with the
 * target URL and your API key passed as query parameters; the response body is
 * the rendered HTML (or, when the target is a JSON API, the JSON). This client
 * is configured entirely from config('crawler.scraping_api.*') so a single class
 * covers every provider — see config/crawler.php for per-provider examples.
 *
 * Transport selection: set CRAWLER_TRANSPORT=scraping_api.
 */
final class ScrapingApiHttpClient implements HttpClient, HttpRequester
{
    private HttpConfig $config;

    /**
     * @param string               $serviceUrl  Provider endpoint, e.g. https://api.scraperapi.com/
     * @param string               $apiKey      Your provider API key.
     * @param string               $apiKeyParam Query param the provider expects the key in (api_key, apikey, …).
     * @param string               $urlParam    Query param the provider expects the target URL in (usually "url").
     * @param array<string,scalar> $extraParams Provider flags (render=true, premium_proxy=true, country_code=br, …).
     */
    public function __construct(
        private readonly string $serviceUrl,
        private readonly string $apiKey,
        private readonly string $apiKeyParam = 'api_key',
        private readonly string $urlParam = 'url',
        private readonly array $extraParams = [],
        ?HttpConfig $config = null,
    ) {
        $this->config = $config ?? new HttpConfig();
    }

    public function configure(HttpConfig $config): void
    {
        $this->config = $config;
    }

    public function get(string $url): string
    {
        return $this->fetch($url);
    }

    /**
     * API mode. Managed APIs proxy a GET to the target (the JSON endpoint) and
     * return its body. POST bodies aren't expressible through the simple query
     * convention, so those must use the Guzzle transport instead.
     *
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
        if (strtoupper($method) !== 'GET') {
            throw new \LogicException(
                'ScrapingApiHttpClient only proxies GET requests. Use the guzzle transport '
                . 'for API-mode POST requests, or a provider that supports POST forwarding.',
            );
        }

        $target = $query !== []
            ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query)
            : $url;

        return $this->fetch($target);
    }

    // -------------------------------------------------------------------------

    private function fetch(string $targetUrl): string
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException(
                'CRAWLER_TRANSPORT=scraping_api but no API key is configured. '
                . 'Set SCRAPING_API_KEY (and SCRAPING_API_URL) in your .env.',
            );
        }

        // Managed scrapers are slow (they render + rotate proxies); give the call
        // generous headroom so we don't time out before the provider responds.
        $client = new Client(['timeout' => max(70, $this->config->timeout), 'verify' => $this->config->verifyTls]);

        $query = array_merge(
            $this->extraParams,
            [
                $this->apiKeyParam => $this->apiKey,
                $this->urlParam    => $targetUrl,
            ],
        );

        $attempt = 0;
        while (true) {
            try {
                return (string) $client->get($this->serviceUrl, ['query' => $query])
                    ->getBody()
                    ->getContents();
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt > $this->config->retryCount) {
                    throw $e;
                }
                if ($this->config->retryDelayMs > 0) {
                    usleep($this->config->retryDelayMs * 1000);
                }
            }
        }
    }
}
