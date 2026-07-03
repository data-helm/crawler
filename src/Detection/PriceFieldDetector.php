<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects a price by locating the innermost element whose text matches one of
 * the configured currency patterns. The matching regex is stored on the
 * selector so the runner extracts only the price portion of the text.
 *
 * Patterns are supplied by the active preset (see config/crawler.php) so the
 * detector carries no built-in currency assumptions.
 */
final class PriceFieldDetector implements FieldDetector
{
    /** @var list<string> */
    private array $patterns;

    /**
     * @param list<string> $patterns Currency regexes, tried in order.
     */
    public function __construct(array $patterns = ['/(?:R\$|US\$|\$|€|£)\s*[\d.,]+/'])
    {
        $this->patterns = $patterns !== [] ? array_values($patterns) : ['/(?:R\$|US\$|\$|€|£)\s*[\d.,]+/'];
    }

    public function detect(\DOMElement $sample): ?FieldSelector
    {
        foreach ($sample->getElementsByTagName('*') as $element) {
            $pattern = $this->matchingPattern(trim($element->textContent));
            if ($pattern === null) {
                continue;
            }

            // Prefer the innermost element so the selector is as specific as possible.
            if ($this->hasMatchingChild($element)) {
                continue;
            }

            return new FieldSelector('price', Selector::cssFor($element), null, $pattern);
        }

        return null;
    }

    private function matchingPattern(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return $pattern;
            }
        }

        return null;
    }

    private function hasMatchingChild(\DOMElement $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $child) {
            if ($this->matchingPattern(trim($child->textContent)) !== null) {
                return true;
            }
        }

        return false;
    }
}
