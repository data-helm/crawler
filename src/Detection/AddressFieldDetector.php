<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Selector;

/**
 * Detects a postal address in the item.
 *
 * Prefers the semantic HTML5 {@code <address>} tag — a stable signal that
 * survives the hashed/obfuscated class names modern sites generate (Zoopla,
 * Rightmove, directories, …). Falls back to an element whose class name clearly
 * names an address, so sites that don't use the semantic tag still work.
 */
final class AddressFieldDetector implements FieldDetector
{
    /** Class-name fragments that strongly imply an address (language-neutral-ish). */
    private const ADDRESS_CLASS_HINTS = ['address', 'location', 'endereco', 'localizacao', 'direccion'];

    public function detect(\DOMElement $sample): ?FieldSelector
    {
        // 1) Semantic <address> tag — the most reliable, deploy-proof signal.
        foreach ($sample->getElementsByTagName('address') as $address) {
            if (trim($address->textContent) !== '') {
                return new FieldSelector('address', Selector::cssFor($address));
            }
        }

        // 2) Class-name hint fallback (first non-empty match wins).
        foreach (['p', 'span', 'div', 'h2', 'h3', 'h4'] as $tag) {
            foreach ($sample->getElementsByTagName($tag) as $el) {
                if (! $el instanceof \DOMElement || trim($el->textContent) === '') {
                    continue;
                }

                $class = strtolower($el->getAttribute('class'));
                foreach (self::ADDRESS_CLASS_HINTS as $hint) {
                    if (str_contains($class, $hint)) {
                        return new FieldSelector('address', Selector::cssFor($el));
                    }
                }
            }
        }

        return null;
    }
}
