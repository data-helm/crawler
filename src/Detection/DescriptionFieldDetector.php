<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects a free-text description / summary in the item.
 *
 * Heuristic: the longest prose paragraph. Descriptions are long, multi-word
 * blurbs, unlike titles, prices or labels — so the {@code <p>} with the most
 * text (above a sentence-length threshold) is almost always the description.
 * Conservative on purpose: short paragraphs are skipped so prices/captions
 * aren't misread as a description.
 */
final class DescriptionFieldDetector implements FieldDetector
{
    /** Minimum characters for a paragraph to count as a description, not a caption. */
    private const MIN_LENGTH = 60;

    public function detect(\DOMElement $sample): ?FieldSelector
    {
        $best       = null;
        $bestLength = 0;

        foreach ($sample->getElementsByTagName('p') as $p) {
            if (! $p instanceof \DOMElement) {
                continue;
            }

            $text   = trim((string) preg_replace('/\s+/', ' ', $p->textContent));
            $length = mb_strlen($text);

            // Require real prose: long enough and containing spaces (multi-word).
            if ($length < self::MIN_LENGTH || ! str_contains($text, ' ')) {
                continue;
            }

            if ($length > $bestLength) {
                $bestLength = $length;
                $best       = $p;
            }
        }

        if ($best === null) {
            return null;
        }

        return new FieldSelector('description', Selector::cssFor($best));
    }
}
