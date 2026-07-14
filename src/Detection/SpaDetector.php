<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Dom\Url;

/**
 * Recognises JavaScript single-page apps (whose listing isn't in the server
 * HTML) and best-effort extracts the JSON/XHR endpoints they call.
 *
 * Endpoint discovery is heuristic: it scans the HTML and inline scripts for
 * URL-like strings that look like data APIs (/api, /public, /rest, /search, …).
 * It cannot see endpoints assembled at runtime or guarded by signatures — for
 * those the user supplies --api-endpoint explicitly.
 */
final class SpaDetector
{
    private const SPA_MARKERS = [
        'id="root"', 'id="app"', 'data-reactroot', 'ng-app', '__INITIAL_STATE__',
        '__NUXT__', '__NEXT_DATA__', 'window.__APP', 'ng-version',
    ];

    private const ENDPOINT_PATTERNS = [
        '#["\'](/(?:public|api|rest|graphql|v\d)/[a-zA-Z0-9_\-/]*(?:search|list|results?|finder|items?|data)[a-zA-Z0-9_\-/]*)["\']#i',
        '#["\'](https?://[^"\']+/(?:public|api|rest)/[^"\']*(?:search|list|results?|finder|items?)[^"\']*)["\']#i',
        '#(?:fetch|axios\.(?:get|post)|url)\s*[\(:]\s*["\'](https?://[^"\']+|/[^"\']+)["\']#i',
        // Framework data endpoints commonly seen in site JS (ASP.NET ApiEngine /
        // *.aspx web methods, /ajax/…). Relative matches are resolved against the
        // site root in candidateEndpoints().
        '#["\'](/?(?:[A-Za-z0-9_\-/]*\.aspx/[A-Za-z][A-Za-z0-9_]+|(?:ApiEngine|ApiFeatures)/[A-Za-z][A-Za-z0-9_\-/]*|ajax/[A-Za-z0-9_\-/]+))["\']#i',
    ];

    /**
     * Signatures of a JS framework/build that renders content client-side.
     * Unlike {@see SPA_MARKERS}, these don't require an empty shell — a Next.js
     * or Gatsby page can ship plenty of static chrome yet still load its actual
     * listing via a client-side fetch (e.g. a section that's just a spinner).
     */
    private const FRAMEWORK_MARKERS = [
        '_next/static', 'self.__next_f', 'id="__next"', '__NUXT__', '__NEXT_DATA__',
        'data-reactroot', 'ng-version', 'window.__INITIAL_STATE__', '/webpack-runtime-',
        '___gatsby', 'data-server-rendered', 'wp-json',
    ];

    /** Loading-state markers that mean "the real content arrives via a later fetch". */
    private const LOADING_MARKERS = [
        'animate-spin', 'lucide-loader', 'spinner', 'skeleton-', 'data-loading',
    ];

    public function isSpa(string $html, int $visibleTextLength): bool
    {
        foreach (self::SPA_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        // Heavy on scripts but almost no visible text → very likely an SPA shell.
        $scriptCount = substr_count(strtolower($html), '<script');

        return $scriptCount >= 3 && $visibleTextLength < 800;
    }

    /**
     * Broader than {@see isSpa()}: true when the page is built by a JS framework
     * that may hydrate its content client-side, even if the shell carries static
     * text. Used to decide whether it's worth rendering the page and sniffing its
     * network traffic for a data endpoint.
     */
    public function looksJsRendered(string $html, int $visibleTextLength): bool
    {
        if ($this->isSpa($html, $visibleTextLength)) {
            return true;
        }

        // A nearly-empty body (just a header/footer shell) that reached this
        // check — i.e. list detection already failed — is almost certainly
        // filled in by JavaScript, whatever the script count (e.g. a single
        // inline XHR loader with a "Loading..." placeholder).
        if ($visibleTextLength < 300) {
            return true;
        }

        foreach (self::FRAMEWORK_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        foreach (self::LOADING_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate data-endpoint URLs found in the HTML and (optionally) the page's
     * external script sources, made absolute and de-duped.
     *
     * Pass the concatenated contents of the page's <script src> files as $scripts
     * to catch XHR/API URLs that live in external JS rather than the page HTML.
     *
     * @return list<string>
     */
    public function candidateEndpoints(string $baseUrl, string $html, string $scripts = ''): array
    {
        $haystack = $scripts !== '' ? $html . "\n" . $scripts : $html;

        // API paths in JS are concatenated to the site root (e.g. Domain + 'api/x'),
        // not the current page path, so resolve relative matches against the origin.
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host   = parse_url($baseUrl, PHP_URL_HOST);
        $root   = is_string($host) && $host !== '' ? "{$scheme}://{$host}/" : $baseUrl;

        $found = [];

        foreach (self::ENDPOINT_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $haystack, $matches)) {
                foreach ($matches[1] as $raw) {
                    $absolute = Url::absolute($root, $raw);
                    if ($absolute !== null && Url::isFetchable($absolute) && $this->looksLikeData($absolute)) {
                        $found[$absolute] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    private function looksLikeData(string $url): bool
    {
        // Drop obvious static assets that slipped through the URL regex.
        return ! (bool) preg_match('/\.(?:js|css|png|jpe?g|gif|svg|webp|woff2?|ico|map)(?:\?|$)/i', $url);
    }
}
