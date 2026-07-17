<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * HTTP transport settings stored in the blueprint and applied per-crawl.
 *
 * timeout          — Guzzle request timeout in seconds.
 * delay_ms         — milliseconds to wait between page requests (polite crawling).
 * retry_count      — how many times to retry a failed request before giving up.
 * retry_delay_ms   — milliseconds to wait between retry attempts.
 * user_agent       — User-Agent header sent on every request.
 * headers          — extra key→value request headers (e.g. Accept-Language).
 * proxies          — list of proxy URLs rotated round-robin on each request.
 * cookies          — pre-set cookies sent on every request
 *                    (each entry: {name, value, domain?}).
 * render_js        — use a headless browser instead of Guzzle to fetch pages.
 *                    Requires a {@see BrowserHttpClient} implementation bound in
 *                    the container (see config('crawler.transport')).
 * verify_tls       — verify the target's TLS certificate (default true). Set to
 *                    false only for sites with broken/self-signed certificates —
 *                    it exposes every request (including cookies) to MITM.
 * browser_wait_for — CSS selector or keyword ('networkidle', 'domcontentloaded')
 *                    the browser waits for before capturing HTML. Only used when
 *                    render_js = true.
 */
final class HttpConfig
{
    private const DEFAULT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /**
     * @param array<string,string>                                  $headers
     * @param list<string>                                          $proxies
     * @param list<array{name:string,value:string,domain?:string}>  $cookies
     */
    public function __construct(
        public readonly int $timeout = 60,
        public readonly int $delayMs = 0,
        public readonly int $retryCount = 3,
        public readonly int $retryDelayMs = 1000,
        public readonly string $userAgent = self::DEFAULT_UA,
        public readonly array $headers = [],
        public readonly array $proxies = [],
        public readonly array $cookies = [],
        public readonly bool $renderJs = false,
        public readonly string $browserWaitFor = '',
        // Per-blueprint HTTP transport: guzzle | browser | flaresolverr | scraping_api.
        // null = use the global config('crawler.transport'). Lets each robot remember
        // the transport its target needs (e.g. flaresolverr for a Cloudflare site).
        public readonly ?string $transport = null,
        public readonly bool $verifyTls = true,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Clone with JS rendering toggled (and, optionally, a transport pinned).
     * Lets the generator promote a config to headless-browser rendering after it
     * discovers the page is a SPA, without rebuilding every field by hand.
     */
    public function withRenderJs(bool $renderJs = true, ?string $transport = null): self
    {
        return new self(
            timeout:        $this->timeout,
            delayMs:        $this->delayMs,
            retryCount:     $this->retryCount,
            retryDelayMs:   $this->retryDelayMs,
            userAgent:      $this->userAgent,
            headers:        $this->headers,
            proxies:        $this->proxies,
            cookies:        $this->cookies,
            renderJs:       $renderJs,
            browserWaitFor: $this->browserWaitFor,
            transport:      $transport ?? $this->transport,
            verifyTls:      $this->verifyTls,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            timeout:          (int) ($data['timeout'] ?? 60),
            delayMs:          (int) ($data['delay_ms'] ?? 0),
            retryCount:       max(0, (int) ($data['retry_count'] ?? 3)),
            retryDelayMs:     max(0, (int) ($data['retry_delay_ms'] ?? 1000)),
            userAgent:        (string) ($data['user_agent'] ?? self::DEFAULT_UA),
            headers:          is_array($data['headers'] ?? null) ? $data['headers'] : [],
            proxies:          is_array($data['proxies'] ?? null) ? array_values($data['proxies']) : [],
            cookies:          is_array($data['cookies'] ?? null) ? array_values($data['cookies']) : [],
            renderJs:         (bool) ($data['render_js'] ?? false),
            browserWaitFor:   (string) ($data['browser_wait_for'] ?? ''),
            transport:        isset($data['transport']) && $data['transport'] !== '' ? (string) $data['transport'] : null,
            verifyTls:        (bool) ($data['verify_tls'] ?? true),
        );
    }

    public function toArray(): array
    {
        return [
            'timeout'           => $this->timeout,
            'delay_ms'          => $this->delayMs,
            'retry_count'       => $this->retryCount,
            'retry_delay_ms'    => $this->retryDelayMs,
            'user_agent'        => $this->userAgent,
            'headers'           => $this->headers,
            'proxies'           => $this->proxies,
            'cookies'           => $this->cookies,
            'render_js'         => $this->renderJs,
            'browser_wait_for'  => $this->browserWaitFor,
            'transport'         => $this->transport,
            'verify_tls'        => $this->verifyTls,
        ];
    }
}
