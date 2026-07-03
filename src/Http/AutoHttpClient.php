<?php

namespace DataHelm\Crawler\Http;

use DataHelm\Crawler\Blueprint\HttpConfig;
use DataHelm\Crawler\Detection\BotProtectionDetector;
use DataHelm\Crawler\Detection\BotProtectionException;

/**
 * Self-escalating {@see HttpClient}: tries the cheapest transport first and, when
 * it detects an anti-bot block, automatically steps up to a stronger transport
 * picked by the detected vendor — Cloudflare → FlareSolverr, Akamai/PerimeterX →
 * a managed scraping API, unknown → headless browser then the specialised solvers.
 *
 * It never beats a WAF a configured transport can't (e.g. Akamai with no
 * scraping_api key) — it just stops and reports what it tried. The transport that
 * succeeds is recorded ({@see getResolvedTransport()}) so the generator can bake
 * it into the blueprint and skip the escalation on subsequent runs.
 */
final class AutoHttpClient implements HttpClient, HttpRequester
{
    /** Vendors that a headless browser / FlareSolverr can't beat from a datacenter IP. */
    private const HARD_VENDORS = [
        'Akamai Bot Manager', 'PerimeterX / HUMAN', 'DataDome',
        'Imperva / Incapsula', 'Kasada', 'AWS WAF', 'Sucuri',
    ];

    private ?string $resolved = null;

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param list<string> $ladder Transports available to try, cheapest-first
     *                             (e.g. ['guzzle','browser','flaresolverr','scraping_api']).
     */
    public function __construct(
        private readonly TransportFactory $factory,
        private readonly BotProtectionDetector $detector,
        private array $ladder,
        private HttpConfig $config = new HttpConfig(),
    ) {
    }

    public function configure(HttpConfig $config): void
    {
        $this->config = $config;
    }

    /** The transport that successfully fetched the last request, if any. */
    public function getResolvedTransport(): ?string
    {
        return $this->resolved;
    }

    /** @return list<string> Human-readable escalation trace for the last request. */
    public function notes(): array
    {
        return $this->notes;
    }

    /** True when a JS-rendering (headless browser) transport is in the ladder. */
    public function canRenderJs(): bool
    {
        return in_array('browser', $this->ladder, true);
    }

    /**
     * Force one fetch through the headless-browser transport with JS rendering on,
     * bypassing the escalate-only-on-block logic. Unlike {@see get()}, this is
     * driven by content emptiness (a SPA whose static HTML carries no data), not
     * by an anti-bot block — the caller decides it needs a rendered DOM. Records
     * 'browser' as the resolved transport so it gets baked into the blueprint.
     */
    public function fetchRendered(string $url): string
    {
        $html = $this->factory->make('browser', $this->config->withRenderJs(true))->get($url);
        $this->resolved = 'browser';
        $this->notes[]  = "Re-fetched through 'browser' with JS rendering for SPA content.";

        return $html;
    }

    public function get(string $url): string
    {
        return $this->run($url, fn (HttpClient $client) => $client->get($url));
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
        return $this->run($url, function (HttpClient $client) use ($method, $url, $headers, $body, $query): string {
            if (! $client instanceof HttpRequester) {
                throw new \LogicException('Transport does not support API-mode requests.');
            }

            return $client->request($method, $url, $headers, $body, $query);
        });
    }

    // -------------------------------------------------------------------------

    /**
     * Which transports are still worth trying after a block by $vendor, filtered
     * to what's available. Pure so it can be unit-tested.
     *
     * @param  list<string> $ladder
     * @return list<string>
     */
    public static function escalationFor(string $vendor, array $ladder): array
    {
        if (in_array($vendor, self::HARD_VENDORS, true)) {
            // Only a managed scraper (its own residential proxies) has a chance.
            $keep = ['scraping_api'];
        } elseif ($vendor === 'Cloudflare') {
            // FlareSolverr is purpose-built for this; scraping_api as last resort.
            $keep = ['flaresolverr', 'scraping_api'];
        } else {
            // Unknown firewall: try real-browser rendering, then the solvers.
            $keep = ['browser', 'flaresolverr', 'scraping_api'];
        }

        return array_values(array_filter($ladder, static fn (string $t): bool => in_array($t, $keep, true)));
    }

    /**
     * @param callable(HttpClient):string $fetch
     */
    private function run(string $url, callable $fetch): string
    {
        $this->notes    = [];
        $this->resolved = null;

        $queue      = $this->ladder;
        $tried      = [];
        $lastVendor = null;
        $lastError  = null;

        while ($queue !== []) {
            $transport = array_shift($queue);
            if (isset($tried[$transport])) {
                continue;
            }
            $tried[$transport] = true;

            try {
                $html   = $fetch($this->factory->make($transport, $this->config));
                $vendor = $this->detector->detectRendered($html);

                if ($vendor === null) {
                    $this->resolved = $transport;
                    $this->notes[]  = "Transport '{$transport}' succeeded.";

                    return $html;
                }

                $lastVendor      = $vendor;
                $this->notes[]   = "Transport '{$transport}' was blocked by {$vendor}.";
            } catch (\Throwable $e) {
                $lastError = $e;
                $block     = $this->detector->fromThrowable($e);

                if ($block !== null) {
                    $lastVendor    = $block['vendor'];
                    $this->notes[] = "Transport '{$transport}' was blocked by {$block['vendor']}.";
                } else {
                    $this->notes[] = "Transport '{$transport}' failed: " . $e->getMessage();
                }
            }

            // Re-plan the remaining attempts from the detected vendor so we skip
            // transports that provably can't help (and stop wasting time/credits).
            if ($lastVendor !== null) {
                $queue = array_values(array_filter(
                    self::escalationFor($lastVendor, $this->ladder),
                    static fn (string $t): bool => ! isset($tried[$t]),
                ));
            }
        }

        // Never saw a block — the failures were ordinary errors; surface the real one.
        if ($lastVendor === null && $lastError !== null) {
            throw $lastError;
        }

        throw new BotProtectionException(
            $lastVendor ?? 'an anti-bot firewall',
            null,
            $url,
            "Auto transport tried [" . implode(', ', array_keys($tried)) . "] but every option was blocked"
            . ($lastVendor !== null ? " (last: {$lastVendor})" : '') . '. '
            . (in_array('scraping_api', $this->ladder, true)
                ? 'Even the managed scraping API could not pass it — consider a different provider or residential proxies.'
                : 'Configure a scraping_api provider key (SCRAPING_API_URL / SCRAPING_API_KEY) to handle hard WAFs like Akamai/PerimeterX.'),
        );
    }
}
