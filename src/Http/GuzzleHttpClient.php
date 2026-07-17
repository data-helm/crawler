<?php

namespace DataHelm\Crawler\Http;

use DataHelm\Crawler\Blueprint\HttpConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\ConnectException;

/**
 * Guzzle-backed {@see HttpClient} adapter.
 *
 * Supports per-blueprint reconfiguration via configure():
 * - custom timeout / User-Agent / extra headers
 * - round-robin proxy rotation
 * - pre-set cookies injected into a CookieJar
 * - automatic retry with back-off on transient failures
 *
 * Also implements {@see HttpRequester} so API mode can issue POST/GET requests
 * with custom headers, a body and query parameters.
 */
final class GuzzleHttpClient implements HttpClient, HttpRequester
{
    private Client $client;
    private HttpConfig $config;
    private int $proxyIndex = 0;
    private ?CookieJar $jar = null;

    /** @var array<string,bool> Hosts whose domain-less cookies were already seeded. */
    private array $cookieHosts = [];

    public function __construct(?HttpConfig $config = null)
    {
        $this->config = $config ?? new HttpConfig();
        $this->buildClient();
    }

    /**
     * Reconfigure the client for a specific blueprint crawl.
     * Called by CrawlEngine at the start of each crawl() invocation.
     */
    public function configure(HttpConfig $config): void
    {
        $this->config     = $config;
        $this->proxyIndex = 0;
        $this->buildClient();
    }

    public function get(string $url): string
    {
        $this->seedCookies($url);
        $attempt = 0;

        while (true) {
            try {
                $options = $this->requestOptions();

                return (string) $this->client->request('GET', $url, $options)->getBody()->getContents();
            } catch (\Throwable $e) {
                // A connection-level failure (DNS, refused, connect timeout) won't
                // be fixed by hammering the same dead host — fail fast.
                if ($e instanceof ConnectException) {
                    throw $e;
                }

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

    /**
     * Arbitrary request used by API mode. Honours the configured retry policy,
     * proxies and cookies just like get().
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
        $this->seedCookies($url);
        $attempt = 0;

        while (true) {
            try {
                $options = $this->requestOptions();
                if ($headers !== []) {
                    $options['headers'] = $headers;
                }
                if ($body !== null) {
                    $options['body'] = $body;
                }
                if ($query !== []) {
                    $options['query'] = $query;
                }

                return (string) $this->client->request(strtoupper($method), $url, $options)
                    ->getBody()
                    ->getContents();
            } catch (\Throwable $e) {
                // A connection-level failure (DNS, refused, connect timeout) won't
                // be fixed by hammering the same dead host — fail fast.
                if ($e instanceof ConnectException) {
                    throw $e;
                }

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

    // -------------------------------------------------------------------------

    private function buildClient(): void
    {
        $this->jar         = $this->config->cookies === [] ? null : new CookieJar();
        $this->cookieHosts = [];
        $jar               = $this->jar;

        $this->client = new Client([
            'timeout'         => $this->config->timeout,
            // Cap connection establishment so an unreachable/dead host fails fast
            // instead of hanging for the full request timeout on every attempt.
            'connect_timeout' => min(15, $this->config->timeout ?: 15),
            'verify'          => $this->config->verifyTls,
            'cookies'         => $jar ?? true,
            'allow_redirects' => ['max' => 15, 'strict' => false, 'referer' => true, 'protocols' => ['http', 'https']],
            'headers'         => array_merge(
                [
                    'Accept'     => '*/*',
                    'User-Agent' => $this->config->userAgent,
                ],
                $this->config->headers,
            ),
        ]);
    }

    /**
     * Per-request options: picks the next proxy if any are configured.
     *
     * @return array<string,mixed>
     */
    private function requestOptions(): array
    {
        if ($this->config->proxies === []) {
            return [];
        }

        $proxy = $this->config->proxies[$this->proxyIndex % count($this->config->proxies)];
        $this->proxyIndex++;

        return ['proxy' => $proxy];
    }

    /**
     * Load the configured cookies into the jar for $url's host. Blueprint
     * cookies (--cookie) usually carry no domain, and a jar entry with an empty
     * domain matches no request host — so those cookies would silently never be
     * sent. Instead, cookies without a domain adopt the host of the first
     * request made to each host (idempotent per host).
     */
    private function seedCookies(string $url): void
    {
        if ($this->jar === null) {
            return;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '' || isset($this->cookieHosts[$host])) {
            return;
        }
        $this->cookieHosts[$host] = true;

        foreach ($this->config->cookies as $entry) {
            if (! is_array($entry) || ! isset($entry['name'], $entry['value'])) {
                continue;
            }

            $domain = (string) ($entry['domain'] ?? '');
            $this->jar->setCookie(new SetCookie([
                'Name'   => (string) $entry['name'],
                'Value'  => (string) $entry['value'],
                'Domain' => $domain !== '' ? $domain : $host,
                'Path'   => '/',
            ]));
        }
    }
}
