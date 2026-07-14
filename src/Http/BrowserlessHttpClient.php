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
final class BrowserlessHttpClient extends BrowserHttpClient implements NetworkCapturingHttpClient
{
    public function __construct(
        private readonly string $serviceUrl,
        private readonly ?string $token = null,
        private readonly string $proxyUrl = '',
    ) {
        parent::__construct();
    }

    /**
     * Render $url and capture every JSON XHR/fetch response the page made, so a
     * SPA's data endpoint can be discovered from real network traffic. Uses
     * browserless's `/function` endpoint to run a Puppeteer script that installs
     * a response listener before navigation.
     *
     * {@inheritdoc}
     */
    public function captureJsonResponses(string $url): array
    {
        $endpoint = rtrim($this->serviceUrl, '/') . '/function';
        if ($this->token !== null && $this->token !== '') {
            $endpoint .= '?' . http_build_query(['token' => $this->token]);
        }

        $timeoutMs = max(30, $this->config->timeout) * 1000;
        $script    = str_replace(
            ['__URL__', '__TIMEOUT__', '__COOKIES__', '__HEADERS__', '__USER_AGENT__'],
            [
                json_encode($url),
                (string) $timeoutMs,
                json_encode($this->cookiesPayload($url)),
                json_encode((object) $this->config->headers),
                json_encode($this->config->userAgent !== '' ? $this->config->userAgent : null),
            ],
            self::CAPTURE_SCRIPT,
        );

        $client   = new Client(['timeout' => (int) ($timeoutMs / 1000) + 20]);
        $response = $client->post($endpoint, [
            'body'        => $script,
            'headers'     => ['Content-Type' => 'application/javascript'],
            'http_errors' => true,
        ]);

        $decoded = json_decode((string) $response->getBody()->getContents(), true);
        if (! is_array($decoded)) {
            return ['html' => '', 'responses' => []];
        }

        return [
            'html'      => is_string($decoded['html'] ?? null) ? $decoded['html'] : '',
            'responses' => is_array($decoded['responses'] ?? null) ? array_values($decoded['responses']) : [],
        ];
    }

    /**
     * Puppeteer script run inside browserless. Listens for JSON responses,
     * skips framework/static/asset traffic, and returns the rendered HTML plus
     * the captured responses. __URL__/__TIMEOUT__ are substituted before sending.
     */
    private const CAPTURE_SCRIPT = <<<'JS'
        module.exports = async ({ page }) => {
          const cookies = __COOKIES__;
          const headers = __HEADERS__;
          const userAgent = __USER_AGENT__;
          if (userAgent) await page.setUserAgent(userAgent);
          if (headers && Object.keys(headers).length) await page.setExtraHTTPHeaders(headers);
          if (cookies.length) await page.setCookie(...cookies);

          const responses = [];
          const seen = new Set();
          page.on('response', async (res) => {
            try {
              const req = res.request();
              const rt = req.resourceType();
              if (rt === 'image' || rt === 'media' || rt === 'font' || rt === 'stylesheet' || rt === 'script') return;
              const ct = (res.headers()['content-type'] || '').toLowerCase();
              if (!ct.includes('json')) return;
              const u = res.url();
              if (u.includes('/_next/') || u.endsWith('manifest.json')) return;
              if (seen.has(u)) return;
              seen.add(u);
              let body = '';
              try { body = await res.text(); } catch (e) { return; }
              if (!body || body.length > 800000) return;
              responses.push({ url: u, method: req.method(), body });
            } catch (e) {}
          });
          await page.goto(__URL__, { waitUntil: 'networkidle2', timeout: __TIMEOUT__ });
          await new Promise((r) => setTimeout(r, 3500));
          let html = '';
          try { html = await page.content(); } catch (e) {}
          if (html.length > 2500000) html = html.slice(0, 2500000);
          return { data: { html, responses: responses.slice(0, 25) }, type: 'application/json' };
        };
        JS;

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
        // browserless's /content endpoint takes this as a bare "waitFor" string;
        // an object under "waitForSelector" is rejected outright (400) before the
        // page is even loaded.
        $waitFor = trim($this->config->browserWaitFor);
        if ($waitFor !== '' && ! $this->isWaitUntilKeyword($waitFor)) {
            $payload['waitFor'] = $waitFor;
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

        $cookies = $this->cookiesPayload($url);
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
     * objects. CDP (Network.setCookie/deleteCookies — behind both /content's
     * cookies payload and page.setCookie in /function scripts) requires each
     * cookie to carry either a domain or a url, so entries with no domain fall
     * back to the page $url being rendered.
     *
     * @return list<array<string,string>>
     */
    private function cookiesPayload(string $url): array
    {
        $out = [];
        foreach ($this->config->cookies as $entry) {
            if (! is_array($entry) || ! isset($entry['name'], $entry['value'])) {
                continue;
            }

            $cookie = ['name' => (string) $entry['name'], 'value' => (string) $entry['value']];
            if (isset($entry['domain']) && $entry['domain'] !== '') {
                $cookie['domain'] = (string) $entry['domain'];
            } else {
                $cookie['url'] = $url;
            }
            $out[] = $cookie;
        }

        return $out;
    }
}
