<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects a star/numeric rating field in a list-item element.
 *
 * Tries four strategies in order of precision:
 *
 *   1. A data-* attribute (data-rating, data-score, …) that already holds the
 *      numeric value — the cleanest extraction path.
 *   2. An aria-label like "4.5 out of 5 stars" — accessible markup, very common.
 *   3. A CSS class that matches one of the configured hints (star, rating, …),
 *      with either a data attribute or text content as the value.
 *   4. Text content that looks like "4.5/5" or "4 out of 5".
 *
 * The regex stored on the returned selector captures only the numeric portion so
 * the runtime extractor yields a clean "4.5" string rather than "4.5 out of 5 stars".
 *
 * Class hints are supplied from the active preset
 * (config('crawler.presets.<active>.rating_hints')).
 */
final class RatingFieldDetector implements FieldDetector
{
    /** Attributes that commonly carry the numeric rating value directly. */
    private const DATA_ATTRS = ['data-rating', 'data-score', 'data-stars', 'data-average', 'data-rate'];

    /** Regex to match human-readable rating text. */
    private const TEXT_PATTERN = '/\b(\d+(?:\.\d+)?)\s*(?:\/\s*5|\/\s*10|out\s+of\s+\d|stars?)\b/i';

    /** Regex used as the FieldSelector regex to pull just the number. */
    private const EXTRACT_PATTERN = '/\d+(?:\.\d+)?/';

    /** @var list<string> */
    private array $hints;

    /**
     * @param list<string> $hints CSS class fragments that identify a rating widget,
     *                            e.g. ['star', 'rating', 'score', 'nota'].
     */
    public function __construct(array $hints = ['star', 'rating', 'review-score', 'score'])
    {
        $this->hints = $hints !== [] ? array_values($hints) : ['star', 'rating', 'review-score', 'score'];
    }

    public function detect(\DOMElement $sample): ?FieldSelector
    {
        foreach ($sample->getElementsByTagName('*') as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }

            // Strategy 1: data-* attribute carries a numeric value directly.
            foreach (self::DATA_ATTRS as $attr) {
                $val = trim($el->getAttribute($attr));
                if ($val !== '' && is_numeric($val)) {
                    return new FieldSelector('rating', Selector::cssFor($el), $attr, self::EXTRACT_PATTERN);
                }
            }

            // Strategy 2: aria-label like "4.5 out of 5 stars".
            $ariaLabel = trim($el->getAttribute('aria-label'));
            if ($ariaLabel !== '' && preg_match(self::TEXT_PATTERN, $ariaLabel)) {
                return new FieldSelector('rating', Selector::cssFor($el), 'aria-label', self::EXTRACT_PATTERN);
            }

            // Strategy 3: CSS class matches a configured hint.
            $class = strtolower($el->getAttribute('class'));
            foreach ($this->hints as $hint) {
                if ($hint !== '' && str_contains($class, strtolower($hint))) {
                    // Prefer a data-* attribute over text.
                    foreach (self::DATA_ATTRS as $attr) {
                        $val = trim($el->getAttribute($attr));
                        if ($val !== '') {
                            return new FieldSelector('rating', Selector::cssFor($el), $attr, self::EXTRACT_PATTERN);
                        }
                    }
                    // Fall back to text content if it contains a digit.
                    $text = trim($el->textContent);
                    if ($text !== '' && preg_match('/\d/', $text)) {
                        return new FieldSelector('rating', Selector::cssFor($el), null, self::EXTRACT_PATTERN);
                    }
                }
            }

            // Strategy 4: text looks like "4.5/5" or "4.5 out of 5".
            $text = trim($el->textContent);
            if ($text !== '' && preg_match(self::TEXT_PATTERN, $text) && ! $this->hasMatchingChild($el)) {
                return new FieldSelector('rating', Selector::cssFor($el), null, self::EXTRACT_PATTERN);
            }
        }

        return null;
    }

    /**
     * True if any child element also matches the rating text pattern, meaning
     * this element is a wrapper whose child is the better extraction target.
     */
    private function hasMatchingChild(\DOMElement $el): bool
    {
        foreach ($el->getElementsByTagName('*') as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            if (preg_match(self::TEXT_PATTERN, trim($child->textContent))) {
                return true;
            }
        }

        return false;
    }
}
