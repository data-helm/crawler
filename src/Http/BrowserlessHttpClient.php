<?php

namespace DataHelm\Crawler\Http;

use GuzzleHttp\Client;

/**
 * {@see BrowserHttpClient} backed by a browserless/chrome service.
 *
 * Renders a URL in real headless Chromium (running in a separate container) and
 * returns the fully-evaluated HTML. Because the request originates from a real
 * browser — genuine TLS fingerprint, JS execution, full header set — it passes
 * many anti-bot checks (e.g. Akamai) that a plain Guzzle request cannot.
 *
 * It posts to browserless's `/content` endpoint. Configure the service URL and
 * (optional) token via config('crawler.browser.*') — see config/crawler.php.
 *
 * Transport selection: set CRAWLER_TRANSPORT=browser. This class is bound as the
 * concrete BrowserHttpClient in CrawlerServiceProvider, so no extra wiring is
 * needed; just run the browserless container.
 */
final class BrowserlessHttpClient extends BrowserHttpClient
{
    public function __construct(
        private readonly string $serviceUrl,
        private readonly ?string $token = null,
        private readonly string $proxyUrl = '',
    ) {
        parent::__construct();
    }

    protected function renderPage(string $url): string
    {
        $endpoint = rtrim($this->serviceUrl, '/') . '/content';

        $query = [];
        if ($this->token !== null && $this->token !== '') {
            $query['token'] = $this->token;
        }
        // Chrome's --proxy-server ignores embedded credentials, so pass only the
        // scheme://host:port here; any user:pass is sent as a header below.
        $proxyServer = $this->proxyServer();
        if ($proxyServer !== '') {
            $query['--proxy-server'] = $proxyServer;
        }
        if ($query !== []) {
            $endpoint .= '?' . http_build_query($query);
        }

        // browserless honours its own navigation timeout; give the HTTP call a
        // little more headroom so we surface browserless's error, not a Guzzle one.
        $client = new Client(['timeout' => max(30, $this->config->timeout + 15)]);

        $payload = [
            'url'         => $url,
            'gotoOptions' => $this->gotoOptions(),
        ];

        // browserWaitFor as a CSS selector → wait for that element to appear.
        $waitFor = trim($this->config->browserWaitFor);
        if ($waitFor !== '' && ! $this->isWaitUntilKeyword($waitFor)) {
            $payload['waitForSelector'] = ['selector' => $waitFor, 'timeout' => $this->config->timeout * 1000];
        }

        if ($this->config->userAgent !== '') {
            $payload['userAgent'] = $this->config->userAgent;
        }

        $extraHeaders = $this->config->headers;
        $proxyAuth = $this->proxyAuthorization();
        if ($proxyAuth !== '') {
            $extraHeaders['Proxy-Authorization'] = $proxyAuth;
        }
        if ($extraHeaders !== []) {
            $payload['setExtraHTTPHeaders'] = $extraHeaders;
        }

        $cookies = $this->cookiesPayload();
        if ($cookies !== []) {
            $payload['cookies'] = $cookies;
        }

        $response = $client->post($endpoint, [
            'json'        => $payload,
            'headers'     => ['Content-Type' => 'application/json'],
            'http_errors' => true,
        ]);

        return (string) $response->getBody()->getContents();
    }

    /**
     * Map browserWaitFor to Puppeteer's gotoOptions.waitUntil. A CSS selector
     * (handled separately) falls back to 'networkidle2' for the initial load.
     *
     * @return array<string,mixed>
     */
    private function gotoOptions(): array
    {
        $keyword = strtolower(trim($this->config->browserWaitFor));

        $waitUntil = match ($keyword) {
            'domcontentloaded' => 'domcontentloaded',
            'load'             => 'load',
            'networkidle', 'networkidle0' => 'networkidle0',
            'networkidle2'     => 'networkidle2',
            default            => 'networkidle2',
        };

        return [
            'waitUntil' => $waitUntil,
            'timeout'   => $this->config->timeout * 1000,
        ];
    }

    private function isWaitUntilKeyword(string $value): bool
    {
        return in_array(
            strtolower($value),
            ['domcontentloaded', 'load', 'networkidle', 'networkidle0', 'networkidle2'],
            true,
        );
    }

    /**
     * scheme://host:port for Chrome's --proxy-server flag, with any credentials
     * stripped (Chrome ignores them there — see {@see proxyAuthorization()}).
     */
    private function proxyServer(): string
    {
        if ($this->proxyUrl === '') {
            return '';
        }

        $parts = parse_url($this->proxyUrl);
        if (! isset($parts['host'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? 'http';
        $server = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $server .= ':' . $parts['port'];
        }

        return $server;
    }

    /**
     * "Basic <base64>" header value for an authenticated proxy, or '' when the
     * proxy URL carries no credentials.
     */
    private function proxyAuthorization(): string
    {
        if ($this->proxyUrl === '') {
            return '';
        }

        $parts = parse_url($this->proxyUrl);
        if (! isset($parts['user'])) {
            return '';
        }

        $credentials = $parts['user'] . ':' . ($parts['pass'] ?? '');

        return 'Basic ' . base64_encode($credentials);
    }

    /**
     * Translate the blueprint's cookie list into browserless/Puppeteer cookie
     * objects (which require name, value and either url or domain).
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
