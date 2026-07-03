<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects the item title: the first non-empty heading (h1..h5), then a
 * meaningful {@code alt} on an image, then the first non-utility anchor.
 */
final class TitleFieldDetector implements FieldDetector
{
    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5'];

    /** Class / href fragments that mark navigation or action links, not titles. */
    private const UTILITY_LINK_PATTERNS = [
        'wishlist', 'favorite', 'favorit', 'share', 'cart', 'compare',
        'javascript:', 'btn-', 'button', 'icon-', 'social',
    ];

    public function detect(\DOMElement $sample): ?FieldSelector
    {
        foreach (self::HEADINGS as $tag) {
            foreach ($sample->getElementsByTagName($tag) as $heading) {
                if (trim($heading->textContent) !== '') {
                    return new FieldSelector('title', Selector::cssFor($heading));
                }
            }
        }

        foreach ($sample->getElementsByTagName('img') as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }

            $alt = trim($img->getAttribute('alt'));
            if (strlen($alt) > 3) {
                return new FieldSelector('title', Selector::cssFor($img), 'alt');
            }
        }

        foreach ($sample->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            if (trim($anchor->textContent) === '' || $this->isUtilityLink($anchor)) {
                continue;
            }

            return new FieldSelector('title', Selector::cssFor($anchor));
        }

        return null;
    }

    private function isUtilityLink(\DOMElement $anchor): bool
    {
        $haystack = strtolower($anchor->getAttribute('class') . ' ' . $anchor->getAttribute('href'));

        foreach (self::UTILITY_LINK_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
