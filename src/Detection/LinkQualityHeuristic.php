<?php

namespace DataHelm\Crawler\Detection;

/**
 * Scores anchor elements and href strings to distinguish real item links
 * (article pages, product pages, listing detail pages) from junk links
 * (share buttons, social profiles, pagination controls, same-page anchors).
 *
 * Score guide:
 *   >= 0   probably a real item link — safe to use
 *   < 0    probably junk — skip if a better candidate exists
 */
final class LinkQualityHeuristic
{
    /**
     * href fragments/patterns that reliably indicate navigational or social
     * junk rather than item detail pages.
     */
    private const JUNK_PATTERNS = [
        '?source=', '#', 'javascript:', 'mailto:', 'tel:', 'whatsapp://',
        'facebook.com/sharer', 'twitter.com/share', 't.co/',
        'linkedin.com/shareArticle', 'pinterest.com/pin/',
        '/followers', '/following', '/signin', '/signup', '/login',
        '/logout', '/register', '/subscribe', '/unsubscribe',
        '/privacy', '/terms', '/about', '/contact', '/sitemap',
        '/tag/', '/tags/', '/category/', '/categories/', '/author/',
        '/page/', '/search', '/feed', '/rss', '/atom',
        '?ref=',
    ];

    /**
     * href patterns that suggest item-level detail pages.
     */
    private const GOOD_PATTERNS = [
        '/p/', '/post/', '/article/', '/articles/', '/item/', '/items/',
        '/product/', '/products/', '/lote/', '/lot/',
        '/imovel/', '/veiculo/', '/carro/',
        '/imoveis/', '/apartamentos/', '/leilao', '/leiloes',
        '/detail/', '/view/',
    ];

    /**
     * Score a raw href string. Higher = more likely a real item link.
     */
    public static function scoreHref(string $href): int
    {
        $lower = strtolower($href);

        // Blank or anchor-only is always junk.
        if ($href === '' || $href === '#' || str_starts_with($href, '#')) {
            return -200;
        }

        foreach (self::JUNK_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return -100;
            }
        }

        // Query-string-only (no path change) is suspicious.
        if (str_starts_with($href, '?')) {
            return -50;
        }

        $score = 0;

        foreach (self::GOOD_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                $score += 60;
                break;
            }
        }

        // A slug-like path with hyphens and/or a numeric ID is a good sign.
        $path = (string) (parse_url($href, PHP_URL_PATH) ?? $href);
        if (preg_match('#/[a-z0-9]+(-[a-z0-9]+){2,}/?$#i', $path)) {
            $score += 30; // slug: my-product-name
        }
        if (preg_match('#/\d{3,}/?$#', $path)) {
            $score += 20; // ends in numeric ID: /3354/
        }

        // Internal relative links are often item links; external absolute URLs
        // are often share targets.
        if (! preg_match('#^https?://#i', $href)) {
            $score += 10;
        }

        // UTM params are common on real item links — penalise lightly only.
        if (str_contains($lower, 'utm_')) {
            $score -= 5;
        }

        return $score;
    }

    /**
     * Quickly decide if an href is certainly junk (skip it entirely).
     */
    public static function isJunk(string $href): bool
    {
        return self::scoreHref($href) < -50;
    }

    /**
     * From a DOMNodeList of <a> elements, return the one with the best href
     * score. Returns null when every candidate is junk.
     *
     * @param \DOMNodeList<\DOMNode> $anchors
     */
    public static function bestAnchor(\DOMNodeList $anchors): ?\DOMElement
    {
        $best      = null;
        $bestScore = PHP_INT_MIN;

        foreach ($anchors as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $href  = trim($node->getAttribute('href'));
            $score = self::scoreHref($href);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $node;
            }
        }

        // If the best candidate is still clearly junk, return null so the
        // detector can signal "no useful link found".
        if ($best !== null && $bestScore < -50) {
            return null;
        }

        return $best;
    }
}
