<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects an image gallery (more than one content photo sharing the same
 * selector) and returns a "multiple" field named "images" — used on detail
 * pages where a record has several photos.
 *
 * Images whose URL looks like an icon, badge, or decoration are filtered out
 * before counting, so navigation icons that repeat across the page do not
 * accidentally trigger gallery detection.
 *
 * Contrast with {@see ImageFieldDetector}, which grabs the one representative
 * thumbnail from a list row.
 */
final class GalleryFieldDetector implements FieldDetector
{
    private const SOURCE_ATTRIBUTES = ['src', 'data-bg', 'data-src', 'data-original', 'data-lazy-src'];
    private const MIN_IMAGES = 2;

    public function detect(\DOMElement $sample): ?FieldSelector
    {
        $groups = [];

        foreach ($sample->getElementsByTagName('img') as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }

            $attribute = ImageQualityHeuristic::bestAttribute($img, self::SOURCE_ATTRIBUTES);
            if ($attribute === null) {
                continue;
            }

            $src = trim($img->getAttribute($attribute));

            // Skip images that look like icons or decorative elements.
            if (ImageQualityHeuristic::scoreUrl($src) < -50) {
                continue;
            }

            $groups[Selector::cssFor($img) . '|' . $attribute][] = $img;
        }

        $bestKey   = null;
        $bestCount = 0;

        foreach ($groups as $key => $images) {
            if (count($images) > $bestCount) {
                $bestKey   = $key;
                $bestCount = count($images);
            }
        }

        if ($bestKey === null || $bestCount < self::MIN_IMAGES) {
            return null;
        }

        [$css, $attribute] = explode('|', $bestKey, 2);

        return new FieldSelector('images', $css, $attribute, null, true);
    }
}
