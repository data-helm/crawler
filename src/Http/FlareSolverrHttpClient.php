<?php

namespace DataHelm\Crawler\Http;

use DataHelm\Crawler\Blueprint\HttpConfig;
use GuzzleHttp\Client;

/**
 * {@see HttpClient} backed by a FlareSolverr instance.
 *
 * FlareSolverr (https://github.com/FlareSolverr/FlareSolverr) is a self-hosted
 * proxy purpose-built to defeat Cloudflare / DDoS-Guard "I'm under attack" JS
 * challenges. It drives a stealth-hardened Chromium (undetected-chromedriver)
 * that hides the headless fingerprints plain browserless leaves exposed, solves
 * the challenge, and returns the resulting HTML plus the `cf_clearance` cookies.
 *
 * Use it for Cloudflare-protected sites (CRAWLER_TRANSPORT=flaresolverr) when the
 * plain browser transport gets challenged. It is NOT a cure for hard IP-reputation
 * blocks or interactive CAPTCHAs — those still need a residential IP.
 *
 * Protocol: a single POST to <service>/v1 with {cmd, url, maxTimeout}; the HTML
 * comes back in solution.response. See the FlareSolverr README for the full API.
 */
final class FlareSolverrHttpClient implements HttpClient, HttpRequester
{
    private HttpConfig $config;

    public function __construct(
        private readonly string $serviceUrl,
        private readonly int $maxTimeoutMs = 60000,
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
        return $this->solve('request.get', $url);
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
        $target = $query !== []
            ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query)
            : $url;

        return strtoupper($method) === 'POST'
            ? $this->solve('request.post', $target, $body)
            : $this->solve('request.get', $target);
    }

    // -------------------------------------------------------------------------

    private function solve(string $cmd, string $url, ?string $postData = null): string
    {
        $endpoint = rtrim($this->serviceUrl, '/') . '/v1';

        // FlareSolverr blocks while it solves the challenge (can take tens of
        // seconds), so give the HTTP call more than its own maxTimeout.
        $client = new Client(['timeout' => (int) ($this->maxTimeoutMs / 1000) + 30]);

        $payload = [
            'cmd'        => $cmd,
            'url'        => $url,
            'maxTimeout' => $this->maxTimeoutMs,
        ];

        if ($postData !== null && $cmd === 'request.post') {
            $payload['postData'] = $postData;
        }

        $cookies = $this->cookiesPayload();
        if ($cookies !== []) {
            $payload['cookies'] = $cookies;
        }

        $raw = (string) $client->post($endpoint, [
            'json'        => $payload,
            'headers'     => ['Content-Type' => 'application/json'],
            'http_errors' => true,
        ])->getBody()->getContents();

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('FlareSolverr returned a non-JSON response.');
        }

        if (($decoded['status'] ?? null) !== 'ok') {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'unknown error';
            throw new \RuntimeException("FlareSolverr could not solve {$url}: {$message}");
        }

        $solution = $decoded['solution'] ?? null;
        if (! is_array($solution) || ! isset($solution['response'])) {
            throw new \RuntimeException('FlareSolverr response had no solution HTML.');
        }

        return (string) $solution['response'];
    }

    /**
     * Translate the blueprint's cookie list into FlareSolverr cookie objects.
     *
     * @return list<array<string,string>>
     */
    private function cookiesPayload(): array
    {
        $out = [];
        foreach ($this->config->cookies as $entry) {
            if (! is_array($entry) || ! isset($entry['name'], $entry['value'])) {
                continue;
            }

            $cookie = ['name' => (string) $entry['name'], 'value' => (string) $entry['value']];
            if (isset($entry['domain']) && $entry['domain'] !== '') {
                $cookie['domain'] = (string) $entry['domain'];
            }
            $out[] = $cookie;
        }

        return $out;
    }
}
