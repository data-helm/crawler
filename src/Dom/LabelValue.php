<?php

namespace DataHelm\Crawler\Dom;

/**
 * Harvests "label: value" data from a DOM subtree.
 *
 * Auction pages express most facts as labelled pairs that share CSS classes
 * (e.g. many `div.value` siblings each preceded by a `div.header`, or inline
 * `<b>1ª Praça:</b> 22/06/2026`). Because the value elements are
 * indistinguishable by CSS, both detection (step 1) and extraction (step 2) look
 * data up by its label instead. This helper is the single source of that logic.
 */
final class LabelValue
{
    private const SKIP_TAGS = ['script', 'style', 'noscript', 'svg', 'head', 'meta', 'link'];
    private const MAX_LABEL = 40;
    private const MAX_VALUE = 300;

    /**
     * @return list<array{label:string,value:string}> First occurrence per label.
     */
    public static function detect(\DOMNode $root): array
    {
        $pairs = [];

        foreach (self::elementsOf($root) as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($el->tagName);
            if (in_array($tag, self::SKIP_TAGS, true)) {
                continue;
            }

            // Definition lists / table rows: <dt>label</dt><dd>value</dd>, <th>..</th><td>..</td>.
            // The value must be the matching tag — never another <dt>/<th> (a header row).
            if ($tag === 'dt') {
                self::push($pairs, self::text($el), self::siblingText($el, 'dd'));

                continue;
            }
            if ($tag === 'th') {
                self::push($pairs, self::text($el), self::siblingText($el, 'td'));

                continue;
            }

            // class="header" / class="*label*" followed by class="*value*" (or next sibling).
            if (self::hasLabelClass($el)) {
                self::push($pairs, self::text($el), self::valueNear($el));

                continue;
            }

            // Inline "<b>Label:</b> value" — label in a bold/label tag, value in the parent text.
            if (in_array($tag, ['b', 'strong', 'label'], true)) {
                $labelText = self::text($el);
                if (str_ends_with($labelText, ':')) {
                    self::push($pairs, $labelText, self::stripPrefix(self::text($el->parentNode), $labelText));
                }

                continue;
            }

            // Leaf element whose own text is "Label: value".
            if (! self::hasElementChild($el)) {
                if (preg_match('/^(.{2,40}?):\s*(\S.*)$/u', self::text($el), $m)) {
                    self::push($pairs, $m[1], $m[2]);
                }
            }
        }

        return array_values($pairs);
    }

    /**
     * @return array<string,string> normalized label => value
     */
    public static function map(\DOMNode $root): array
    {
        $map = [];
        foreach (self::detect($root) as $pair) {
            $map[self::normalize($pair['label'])] = $pair['value'];
        }

        return $map;
    }

    public static function normalize(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/', ' ', $label));
        $label = rtrim($label, ':');

        return mb_strtolower(trim($label));
    }

    /**
     * @param array<string,array{label:string,value:string}> $pairs
     */
    private static function push(array &$pairs, string $label, ?string $value): void
    {
        $label = rtrim(trim((string) preg_replace('/\s+/', ' ', $label)), ':');
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
            return;
        }

        if ($value === null) {
            return;
        }
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($value === '' || mb_strlen($value) > self::MAX_VALUE) {
            return;
        }

        $key = self::normalize($label);
        if ($key === '' || isset($pairs[$key])) {
            return;
        }

        $pairs[$key] = ['label' => $label, 'value' => $value];
    }

    private static function valueNear(\DOMElement $el): ?string
    {
        // Prefer an explicit value element under the same parent.
        $parent = $el->parentNode;
        if ($parent instanceof \DOMElement) {
            foreach ($parent->childNodes as $child) {
                if ($child instanceof \DOMElement && $child !== $el && self::classContains($child, 'value')) {
                    $text = self::text($child);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        // Otherwise the next sibling — but only if it is not itself a label/header
        // (that would be a row of headers, not a label/value pair).
        $sibling = self::nextElementSibling($el);
        if ($sibling !== null && ! self::hasLabelClass($sibling) && self::text($sibling) !== '') {
            return self::text($sibling);
        }

        return null;
    }

    private static function stripPrefix(string $full, string $label): ?string
    {
        $full = trim((string) preg_replace('/\s+/', ' ', $full));
        $label = trim((string) preg_replace('/\s+/', ' ', $label));

        if ($label !== '' && mb_stripos($full, $label) === 0) {
            return ltrim(mb_substr($full, mb_strlen($label)), ": \t");
        }

        return null;
    }

    private static function siblingText(\DOMElement $el, string $expectedTag): ?string
    {
        $sibling = self::nextElementSibling($el);
        if ($sibling === null || strtolower($sibling->tagName) !== $expectedTag) {
            return null;
        }

        $text = self::text($sibling);

        return $text !== '' ? $text : null;
    }

    private static function nextElementSibling(\DOMNode $el): ?\DOMElement
    {
        $node = $el->nextSibling;
        while ($node !== null && ! $node instanceof \DOMElement) {
            $node = $node->nextSibling;
        }

        return $node instanceof \DOMElement ? $node : null;
    }

    private static function hasElementChild(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return true;
            }
        }

        return false;
    }

    private static function hasLabelClass(\DOMElement $el): bool
    {
        foreach (self::classes($el) as $class) {
            if ($class === 'header' || str_contains($class, 'label')) {
                return true;
            }
        }

        return false;
    }

    private static function classContains(\DOMElement $el, string $needle): bool
    {
        foreach (self::classes($el) as $class) {
            if (str_contains($class, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function classes(\DOMElement $el): array
    {
        $class = trim($el->getAttribute('class'));

        return $class === '' ? [] : array_values(array_filter(preg_split('/\s+/', $class) ?: []));
    }

    private static function text(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', $node->textContent ?? ''));
    }

    /**
     * @return iterable<\DOMNode>
     */
    private static function elementsOf(\DOMNode $root): iterable
    {
        if ($root instanceof \DOMDocument) {
            return $root->getElementsByTagName('*');
        }
        if ($root instanceof \DOMElement) {
            return $root->getElementsByTagName('*');
        }

        return $root->ownerDocument?->getElementsByTagName('*') ?? [];
    }
}
