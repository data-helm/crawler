<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects the item's link by finding the highest-quality anchor in the sample
 * element. Uses {@see LinkQualityHeuristic} to skip share buttons, social
 * profile links, pagination controls, and other navigational junk before
 * committing to a selector.
 */
final class LinkFieldDetector implements FieldDetector
{
    public function detect(\DOMElement $sample): ?FieldSelector
    {
        $anchors = $sample->getElementsByTagName('a');
        $best    = LinkQualityHeuristic::bestAnchor($anchors);

        if ($best === null) {
            // All anchors scored as junk; fall back to the first non-empty href.
            foreach ($anchors as $anchor) {
                if ($anchor instanceof \DOMElement && trim($anchor->getAttribute('href')) !== '') {
                    return new FieldSelector('link', Selector::cssFor($anchor), 'href');
                }
            }

            return null;
        }

        return new FieldSelector('link', Selector::cssFor($best), 'href');
    }
}
