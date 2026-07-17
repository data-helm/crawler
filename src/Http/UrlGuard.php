<?php

namespace DataHelm\Crawler\Http;

/**
 * SSRF guard for outbound request URLs.
 *
 * A crawler follows URLs — including detail-page URLs built from *scraped*
 * content — so without a guard a malicious page (or an untrusted blueprint) can
 * point a request at `http://169.254.169.254/` (cloud metadata),
 * `http://localhost:6379/` (internal Redis), or a `file://` path. This class
 * classifies a URL and, when enabled, blocks the dangerous ones.
 *
 * Enforcement is OFF by default so the common case — an operator scraping their
 * own internal/staging host — keeps working. Turn it on for multi-tenant / SaaS
 * setups where blueprints or target sites are not fully trusted:
 *
 *   'security' => ['block_private_hosts' => true],
 *
 * The scheme check (reject anything but http/https) always runs, since a
 * non-http scheme is never a legitimate crawl target.
 */
final class UrlGuard
{
    /**
     * @param bool         $blockPrivateHosts Reject private/reserved/loopback/link-local hosts.
     * @param list<string> $allowHosts        Hosts always permitted (exact, case-insensitive),
     *                                         even when they resolve to a private range.
     */
    public function __construct(
        private readonly bool $blockPrivateHosts = false,
        private readonly array $allowHosts = [],
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            blockPrivateHosts: (bool) config('crawler.security.block_private_hosts', false),
            allowHosts:        array_map(
                static fn ($h): string => strtolower((string) $h),
                (array) config('crawler.security.allow_hosts', []),
            ),
        );
    }

    /**
     * @return string|null A human-readable reason the URL is blocked, or null when it is allowed.
     */
    public function blockReason(string $url): ?string
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return "refusing to fetch a non-http(s) URL (scheme '{$scheme}')";
        }

        if (! $this->blockPrivateHosts) {
            return null;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return 'URL has no host';
        }

        if (in_array($host, $this->allowHosts, true)) {
            return null;
        }

        foreach ($this->resolveIps($host) as $ip) {
            if ($this->isPrivateIp($ip)) {
                return "host '{$host}' resolves to a private/reserved address ({$ip})";
            }
        }

        return null;
    }

    /**
     * @throws BlockedUrlException when the URL must not be fetched.
     */
    public function assert(string $url): void
    {
        $reason = $this->blockReason($url);
        if ($reason !== null) {
            throw new BlockedUrlException("Blocked request to {$url}: {$reason}.");
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Resolve a host to the IP addresses a request could actually connect to.
     * A literal IP host is returned as-is; a name is resolved via DNS (best
     * effort — an unresolvable name yields no IPs and is treated as allowed,
     * since the request will fail at connect time anyway).
     *
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        $literal = trim($host, '[]'); // IPv6 hosts arrive bracketed.
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    /**
     * True when an IP is loopback, link-local (incl. the cloud metadata address
     * 169.254.169.254), private (RFC 1918 / unique-local), or otherwise reserved.
     */
    private function isPrivateIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE make the filter reject exactly
        // the private/reserved space (and it understands both IPv4 and IPv6),
        // returning false for those — so a false result here means "not public".
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
