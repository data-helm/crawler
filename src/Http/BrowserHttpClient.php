<?php

namespace DataHelm\Crawler\Http;

use DataHelm\Crawler\Blueprint\HttpConfig;

/**
 * Base class for headless-browser HTTP clients.
 *
 * Bind a concrete subclass in your AppServiceProvider or a custom service
 * provider when the blueprint has `http_config.render_js = true`. Example
 * implementations:
 *
 *   - Spatie Browsershot (Puppeteer/Chromium):
 *       composer require spatie/browsershot
 *       class BrowsershotClient extends BrowserHttpClient { ... }
 *
 *   - Symfony Panther (ChromeDriver):
 *       composer require symfony/panther
 *       class PantherClient extends BrowserHttpClient { ... }
 *
 * Subclasses must implement {@see renderPage()} to return the fully-rendered
 * HTML string; everything else (retries, user-agent injection, cookies) is
 * handled by this abstract class.
 *
 * Registration (AppServiceProvider):
 *   $this->app->when(CrawlEngine::class)
 *       ->needs(BrowserHttpClient::class)
 *       ->give(MyBrowsershotClient::class);
 *
 * Or bind globally and set 'crawler.transport' = 'browser' in config/crawler.php.
 */
abstract class BrowserHttpClient implements HttpClient, HttpRequester
{
    protected HttpConfig $config;

    public function __construct()
    {
        $this->config = new HttpConfig();
    }

    /**
     * Called by {@see CrawlEngine} when the blueprint sets render_js = true.
     */
    public function configure(HttpConfig $config): void
    {
        $this->config = $config;
    }

    /** {@inheritdoc} */
    final public function get(string $url): string
    {
        return $this->renderPage($url);
    }

    /** {@inheritdoc} */
    final public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $query = [],
    ): string {
        // Browser clients can't easily issue arbitrary POST/body requests.
        // For API mode with render_js, use a Guzzle client instead.
        if (strtoupper($method) !== 'GET') {
            throw new \LogicException(
                'BrowserHttpClient only supports GET requests. '
                . 'Use GuzzleHttpClient for API-mode (POST) requests.',
            );
        }

        $fullUrl = $query !== []
            ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query)
            : $url;

        return $this->renderPage($fullUrl);
    }

    /**
     * Fetch $url using the browser engine and return the fully-rendered HTML.
     *
     * The implementation is responsible for:
     *   - Launching / reusing a browser instance
     *   - Waiting for the page to stabilise (see $this->config->browserWaitFor)
     *   - Returning the full page HTML after JS execution
     */
    abstract protected function renderPage(string $url): string;
}
