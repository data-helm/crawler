<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects the item image as the most relevant photo in the card.
 *
 * Checks both <img> tags and lazy-load anchors (e.g. MegaLeilões uses
 * {@code <a class="card-image" data-bg="…">} with no <img> for the thumbnail).
 * Every candidate is scored by {@see ImageQualityHeuristic}; the highest-scoring
 * element wins so a property photo beats a bank icon badge.
 */
final class ImageFieldDetector implements FieldDetector
{
    private const IMG_ATTRIBUTES = ['src', 'data-src', 'data-original', 'data-lazy-src'];

    /** Lazy-load URLs commonly stored on <a> rather than <img>. */
    private const LAZY_ATTRIBUTES = [
        'data-bg', 'data-src', 'data-original', 'data-lazy-src',
        'data-model-picture', 'data-product-picture',
    ];

    public function detect(\DOMElement $sample): ?FieldSelector
    {
        $bestElement   = null;
        $bestScore     = PHP_INT_MIN;
        $bestAttribute = 'src';

        foreach ($sample->getElementsByTagName('img') as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }

            $attribute = ImageQualityHeuristic::bestAttribute($img, self::IMG_ATTRIBUTES);
            if ($attribute === null) {
                continue;
            }

            $score = ImageQualityHeuristic::scoreDomElement($img);
            if ($score > $bestScore) {
                $bestScore     = $score;
                $bestElement   = $img;
                $bestAttribute = $attribute;
            }
        }

        foreach ($sample->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $attribute = ImageQualityHeuristic::bestAttribute($anchor, self::LAZY_ATTRIBUTES);
            if ($attribute === null) {
                continue;
            }

            $url = trim($anchor->getAttribute($attribute));
            $score = ImageQualityHeuristic::scoreUrl($url);

            $class = strtolower($anchor->getAttribute('class'));
            if (str_contains($class, 'card-image') || preg_match('/\blazyload\b/', $class)) {
                $score += 80;
            }

            if ($score > $bestScore) {
                $bestScore     = $score;
                $bestElement   = $anchor;
                $bestAttribute = $attribute;
            }
        }

        if ($bestElement === null) {
            return null;
        }

        return new FieldSelector('image', Selector::cssFor($bestElement), $bestAttribute);
    }
}
