<?php

namespace DataHelm\Crawler\Detection;

/**
 * Scores images to distinguish real content photos from icons, badges, and
 * decorative elements. Used at both detection time (DOM inspection) and at
 * run time (URL filtering) to select the most relevant image.
 *
 * Score guide:
 *   >= 0   likely a real content photo — safe to use
 *   < -50  almost certainly an icon/decoration — skip if a better option exists
 */
final class ImageQualityHeuristic
{
    /**
     * URL path fragments that strongly suggest an icon or non-photo asset.
     * Matched case-insensitively against the URL path component.
     */
    private const LOW_QUALITY_PATTERNS = [
        'icon', 'logo', 'btn', 'button', 'badge', 'flag', 'arrow',
        'sprite', 'bullet', 'pixel', 'blank', 'spacer', 'placeholder',
        'loading', 'spinner', 'avatar', 'no-image', 'noimage', 'no_image',
        'not-found', 'missing', 'default', 'star', 'rating', 'share',
        'facebook', 'twitter', 'whatsapp', 'instagram', 'social',
        'banner-small', 'favicon', 'bank_icon', 'bank-icons',
        '/b.gif', '/images/b.',
    ];

    /**
     * URL path fragments that suggest a real content photo.
     */
    private const HIGH_QUALITY_PATTERNS = [
        'foto', 'photo', 'image', 'imagem', 'img', 'pic', 'picture',
        'galeria', 'gallery', 'produto', 'product', 'imovel', 'veiculo',
        'carro', 'original', 'full', 'large', 'big', 'max', 'thumb',
        'upload', 'media', 'file', 'asset', 'batch', 'batches',
    ];

    /**
     * Score a URL string. Higher means more likely a real photo.
     */
    public static function scoreUrl(string $url): int
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? $url));

        foreach (self::LOW_QUALITY_PATTERNS as $pattern) {
            if (str_contains($path, $pattern)) {
                return -100;
            }
        }

        foreach (self::HIGH_QUALITY_PATTERNS as $pattern) {
            if (str_contains($path, $pattern)) {
                return 50;
            }
        }

        return 0;
    }

    /**
     * Pick the attribute whose URL looks most like a real photo (e.g. prefer
     * {@code data-original} over a lazy-load {@code src} placeholder).
     *
     * @param list<string> $attributes
     */
    public static function bestAttribute(\DOMElement $element, array $attributes): ?string
    {
        $bestAttr  = null;
        $bestScore = PHP_INT_MIN;
        $firstAttr = null;

        foreach ($attributes as $attribute) {
            $value = trim($element->getAttribute($attribute));
            if ($value === '' || str_starts_with($value, 'data:')) {
                continue;
            }

            $firstAttr ??= $attribute;

            $score = self::scoreUrl($value);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAttr  = $attribute;
            }
        }

        return $bestAttr ?? $firstAttr;
    }

    /**
     * Score a DOM <img> element using URL, explicit size, and class attributes.
     * Uses the best-scoring source attribute, not merely the first non-empty one.
     */
    public static function scoreDomElement(\DOMElement $img): int
    {
        $urls = [];
        foreach (['src', 'data-src', 'data-original', 'data-lazy-src', 'data-bg'] as $attr) {
            $src = trim($img->getAttribute($attr));
            if ($src !== '' && ! str_starts_with($src, 'data:')) {
                $urls[] = $src;
            }
        }

        $score = 0;
        $best  = self::bestUrl($urls);
        if ($best !== null) {
            $score += self::scoreUrl($best);
        }

        // Explicit size attributes: large images are real photos.
        $w = (int) $img->getAttribute('width');
        $h = (int) $img->getAttribute('height');
        if ($w >= 150 || $h >= 150) {
            $score += 150;
        } elseif (($w > 0 && $w < 50) || ($h > 0 && $h < 50)) {
            $score -= 50; // Explicitly tiny → likely decorative.
        }

        // Class-name hints.
        $class = strtolower($img->getAttribute('class'));
        foreach (['icon', 'logo', 'badge', 'mini', 'xs', 'tiny', 'small-img'] as $bad) {
            if (str_contains($class, $bad)) {
                $score -= 50;
                break;
            }
        }

        // Alt text: a meaningful, longer alt usually belongs to a content photo.
        $alt = trim($img->getAttribute('alt'));
        if (strlen($alt) > 20) {
            $score += 30;
        }

        return $score;
    }

    /**
     * From a list of URLs, return the one with the highest score (most likely a
     * real photo). Falls back to the first non-empty URL when all candidates
     * look like icons, so callers always get something back.
     *
     * @param list<string> $urls
     */
    public static function bestUrl(array $urls): ?string
    {
        $best      = null;
        $bestScore = PHP_INT_MIN;
        $first     = null;

        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $first ??= $url;

            $score = self::scoreUrl($url);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $url;
            }
        }

        // Prefer a positively scored URL; fall back to first available.
        return $best ?? $first;
    }
}
