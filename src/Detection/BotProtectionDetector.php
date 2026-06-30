<?php

namespace DataHelm\Crawler\Detection;

/**
 * Recognises anti-bot / WAF block pages so the generator can tell the user
 * "the site blocked us" instead of failing with a raw, confusing HTML dump.
 *
 * Detection is signature-based: a known vendor fingerprint in the response body
 * is conclusive on its own; a blocking status code (403/429/503) paired with a
 * weaker generic marker ("Access Denied", "Request blocked") is also treated as
 * a block. Everything else returns null and is handled as a normal error.
 *
 * This never bypasses protection — it only classifies the response so the
 * command can suggest the right next step (a browser transport, proxies, or a
 * hand-captured API endpoint).
 */
final class BotProtectionDetector
{
    /**
     * Conclusive vendor fingerprints. A match means "definitely a bot block",
     * regardless of status code. Keyed by the human-readable vendor name.
     *
     * @var array<string,list<string>>
     */
    private const VENDOR_SIGNATURES = [
        'Akamai Bot Manager'        => ['akamaighost', 'errors.edgesuite.net', 'akamai reference', 'ak_bmsc', 'akamai.com/site', 'akamai-logo', '_abck', 'bm-verify', 'bm_sz'],
        'Cloudflare'                => ['cloudflare', 'cf-ray', 'attention required', 'just a moment', 'cf-chl', 'cf_chl'],
        'PerimeterX / HUMAN'        => ['perimeterx', 'px-captcha', '_pxhd', 'human challenge', 'access to this page has been denied'],
        'DataDome'                  => ['datadome', 'dd-captcha'],
        'Imperva / Incapsula'       => ['incapsula', '_incapsula_resource', 'imperva'],
        'AWS WAF'                    => ['awswaf', 'aws waf'],
        'Sucuri'                    => ['sucuri website firewall', 'cloudproxy'],
        'Kasada'                    => ['kasada', 'kpsdk'],
    ];

    /**
     * Weak markers that only count as a block when paired with a blocking
     * status code (403/429/503). They appear on generic deny pages.
     *
     * @var list<string>
     */
    private const GENERIC_MARKERS = [
        'access denied', 'request blocked', 'you don\'t have permission',
        'forbidden', 'bot detected', 'unusual traffic', 'are you a robot',
    ];

    private const BLOCKING_STATUS = [403, 429, 503];

    /**
     * Max visible-text length (chars) for a 200 response to still be considered a
     * possible block. Challenge/deny pages are tiny; anything larger is real
     * content and must not be flagged just for mentioning a CDN/WAF.
     */
    private const MAX_BLOCK_PAGE_TEXT = 2000;

    /**
     * Classify a response. Returns the vendor name (or a generic label) when the
     * response looks like a bot block, otherwise null.
     */
    public function detect(?int $status, string $body): ?string
    {
        $vendor = $this->matchVendor($body);
        if ($vendor !== null) {
            return $vendor;
        }

        if ($status !== null && in_array($status, self::BLOCKING_STATUS, true) && $this->hasGenericMarker($body)) {
            return 'an anti-bot firewall';
        }

        return null;
    }

    /**
     * Classify already-fetched HTML (e.g. from a headless-browser transport that
     * returns the block page with HTTP 200).
     *
     * Anti-bot challenge/deny interstitials carry almost no visible text, while a
     * real content page is large. So we gate on visible size FIRST: a substantial
     * page is never a block — even if it merely *references* a CDN/WAF (a
     * Cloudflare-hosted shop legitimately contains the word "cloudflare"). Only on
     * a suspiciously small page do we look for a vendor fingerprint or deny phrase.
     */
    public function detectRendered(string $html): ?string
    {
        if (strlen(trim(strip_tags($html))) > self::MAX_BLOCK_PAGE_TEXT) {
            return null;
        }

        return $this->matchVendor($html)
            ?? ($this->hasGenericMarker($html) ? 'an anti-bot firewall' : null);
    }

    private function matchVendor(string $body): ?string
    {
        $haystack = strtolower($body);

        foreach (self::VENDOR_SIGNATURES as $vendor => $signatures) {
            foreach ($signatures as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $vendor;
                }
            }
        }

        return null;
    }

    private function hasGenericMarker(string $body): bool
    {
        $haystack = strtolower($body);

        foreach (self::GENERIC_MARKERS as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort classification of a thrown HTTP error. Reads the response
     * status/body when the exception carries one (Guzzle's RequestException
     * exposes getResponse()); otherwise falls back to the exception message,
     * which usually embeds the status line and a snippet of the block page.
     *
     * @return array{vendor:string,status:?int}|null
     */
    public function fromThrowable(\Throwable $e): ?array
    {
        $status = null;
        $body   = $e->getMessage();

        // Duck-typed: avoids a hard dependency on Guzzle's exception classes.
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                $status = $response->getStatusCode();
                $body  .= "\n" . (string) $response->getBody();

                // The vendor fingerprint is often only in headers (e.g.
                // Server: AkamaiGHost, Set-Cookie: ak_bmsc, cf-ray, x-datadome).
                if (method_exists($response, 'getHeaders')) {
                    foreach ($response->getHeaders() as $name => $values) {
                        $body .= "\n{$name}: " . implode(', ', (array) $values);
                    }
                }
            }
        }

        if (preg_match('/\b(403|429|503)\b/', $e->getMessage(), $m) && $status === null) {
            $status = (int) $m[1];
        }

        $vendor = $this->detect($status, $body);

        return $vendor === null ? null : ['vendor' => $vendor, 'status' => $status];
    }
}
