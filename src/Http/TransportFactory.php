<?php

namespace DataHelm\Crawler\Http;

use DataHelm\Crawler\Blueprint\HttpConfig;
use DataHelm\Crawler\Detection\BotProtectionDetector;
use Illuminate\Contracts\Foundation\Application;

// AutoHttpClient lives in this namespace; referenced in make().

/**
 * Builds the right {@see HttpClient} for a transport name.
 *
 * Single source of truth for transport selection — used both for the global
 * default binding (CrawlerServiceProvider) and for per-blueprint overrides at
 * crawl time (CrawlEngine), so a robot can carry its own transport
 * (http_config.transport) instead of relying on the global CRAWLER_TRANSPORT.
 */
final class TransportFactory
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param string|null     $transport Transport name; null falls back to config('crawler.transport').
     * @param HttpConfig|null $config    When given, the new client is configured with it.
     */
    public function make(?string $transport = null, ?HttpConfig $config = null): HttpClient
    {
        $transport = $transport !== null && $transport !== ''
            ? $transport
            : (string) config('crawler.transport', 'guzzle');

        $client = match ($transport) {
            'auto'         => new AutoHttpClient($this, new BotProtectionDetector(), $this->autoLadder()),
            'browser'      => $this->browser(),
            'flaresolverr' => new FlareSolverrHttpClient(
                (string) config('crawler.flaresolverr.url', 'http://flaresolverr:8191'),
                (int) config('crawler.flaresolverr.max_timeout', 60000),
            ),
            'scraping_api' => new ScrapingApiHttpClient(
                (string) config('crawler.scraping_api.url', ''),
                (string) config('crawler.scraping_api.api_key', ''),
                (string) config('crawler.scraping_api.api_key_param', 'api_key'),
                (string) config('crawler.scraping_api.url_param', 'url'),
                (array) config('crawler.scraping_api.params', []),
            ),
            default        => new GuzzleHttpClient(),
        };

        // Every transport this factory builds is configurable; the only HttpClient
        // that isn't is CachedHttpClient, which the factory never constructs.
        if ($config !== null && method_exists($client, 'configure')) {
            $client->configure($config);
        }

        return $client;
    }

    /**
     * Escalation ladder for the 'auto' transport: cheapest first, ending at the
     * managed scraping API only when one is actually configured (otherwise hard
     * WAFs simply can't be solved and auto stops with an honest message).
     *
     * @return list<string>
     */
    public function autoLadder(): array
    {
        $ladder = ['guzzle', 'browser', 'flaresolverr'];

        if ($this->scrapingApiConfigured()) {
            $ladder[] = 'scraping_api';
        }

        return $ladder;
    }

    private function scrapingApiConfigured(): bool
    {
        return (string) config('crawler.scraping_api.url', '') !== ''
            && (string) config('crawler.scraping_api.api_key', '') !== '';
    }

    /**
     * Resolve the browser client: an app-bound BrowserHttpClient subclass
     * (Browsershot/Panther/…) if present, else the bundled browserless adapter.
     */
    private function browser(): HttpClient
    {
        if ($this->app->bound(BrowserHttpClient::class)) {
            return $this->app->make(BrowserHttpClient::class);
        }

        return new BrowserlessHttpClient(
            (string) config('crawler.browser.url', 'http://browserless:3000'),
            config('crawler.browser.token') !== null ? (string) config('crawler.browser.token') : null,
        );
    }
}
