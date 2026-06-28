<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Dom\Page;
use DataHelm\Crawler\Dom\Selector;

/**
 * Finds the page's repeating "list of items" by scanning every element for a
 * group of sibling children that share the same structural signature (tag +
 * classes). Candidates are ranked structurally, then validated by attempting
 * real field extraction across several rows ({@see ListCandidateValidator}).
 */
final class ListDetector
{
    private const MIN_REPEAT = 3;

    /** Tailwind / layout utilities — useless alone as a list-row signature. */
    private const GENERIC_CLASSES = [
        'w-full', 'h-full', 'h-auto', 'w-auto', 'flex', 'grid', 'relative', 'absolute',
        'block', 'inline', 'hidden', 'group', 'fixed', 'sticky', 'static',
        'flex-col', 'flex-row', 'items-center', 'justify-start', 'justify-center',
        'box-content', 'overflow-hidden',
    ];

    /** Class fragments that strongly suggest a product / listing row. */
    private const SEMANTIC_CLASS_FRAGMENTS = [
        'product', 'spot-', 'card', 'item', 'offer', 'listing', 'result',
    ];

    private readonly ?ListCandidateValidator $validator;

    /**
     * @param list<FieldDetector> $fieldDetectors
     */
    public function __construct(
        array $fieldDetectors = [],
        ?ListCandidateValidator $validator = null,
    ) {
        $this->validator = $validator
            ?? ($fieldDetectors !== [] ? new ListCandidateValidator($fieldDetectors) : null);
    }

    /**
     * @return array{itemSelector:string,sample:\DOMElement}|null
     */
    public function detect(Page $page): ?array
    {
        $candidates = $this->collectCandidates($page);

        if ($candidates === []) {
            return null;
        }

        if ($this->validator === null) {
            return $this->candidateResult($candidates[0]);
        }

        $bestValid     = null;
        $bestValidRate = -1.0;
        $bestSpecific  = -1;

        foreach ($candidates as $candidate) {
            foreach ($this->expandCandidates($candidate) as $variant) {
                $result = $this->validator->validate(
                    $page,
                    $variant['itemSelector'],
                    $variant['sample'],
                );

                if (! $result['valid']) {
                    continue;
                }

                $specificity = $this->rowSpecificityScore($variant['sample']);

                if ($result['score'] > $bestValidRate
                    || ($result['score'] === $bestValidRate && $specificity > $bestSpecific)) {
                    $bestValidRate = $result['score'];
                    $bestSpecific  = $specificity;
                    $bestValid     = $variant;
                }
            }
        }

        if ($bestValid !== null) {
            return $this->candidateResult($bestValid);
        }

        // Nothing passed validation — fall back to the strongest structural match.
        return $this->candidateResult($candidates[0]);
    }

    /**
     * @return list<array{score:int,itemSelector:string,sample:\DOMElement,container:\DOMElement}>
     */
    private function collectCandidates(Page $page): array
    {
        $candidates = [];
        $scope      = MainContentScope::locate($page);
        $root       = $scope ?? $page->document()->documentElement;

        /** @var list<\DOMElement> $searchNodes */
        $searchNodes = [$root];
        foreach ($root->getElementsByTagName('*') as $node) {
            if ($node instanceof \DOMElement) {
                $searchNodes[] = $node;
            }
        }

        foreach ($searchNodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            foreach ($this->repeatingGroups($node) as $items) {
                $score = $this->score($items);
                if ($score <= 0) {
                    continue;
                }

                $candidates[] = [
                    'score'        => $score,
                    'itemSelector' => $this->buildItemSelector($node, $items),
                    'sample'       => $items[0],
                    'container'    => $node,
                    'items'        => $items,
                ];
            }
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score'],
        );

        return $candidates;
    }

    /**
     * Yield the structural candidate plus nested inner rows (e.g. grid cell → card).
     *
     * @param array{score:int,itemSelector:string,sample:\DOMElement,container:\DOMElement,items:list<\DOMElement>} $candidate
     * @return list<array{score:int,itemSelector:string,sample:\DOMElement,container:\DOMElement,items:list<\DOMElement>}>
     */
    private function expandCandidates(array $candidate): array
    {
        $variants = [$candidate];

        $nested = $this->promoteNestedCandidate($candidate['items'], $candidate['container']);
        if ($nested !== null) {
            $variants[] = [
                'score'        => $candidate['score'],
                'itemSelector' => $nested['itemSelector'],
                'sample'       => $nested['sample'],
                'container'    => $candidate['container'],
                'items'        => $nested['items'],
            ];
        }

        return $variants;
    }

    /**
     * When outer rows frequently wrap one inner element sharing the same
     * signature, treat that inner element as an alternate list row.
     *
     * @param list<\DOMElement> $items
     * @return array{itemSelector:string,sample:\DOMElement,items:list<\DOMElement>}|null
     */
    private function promoteNestedCandidate(array $items, \DOMElement $container): ?array
    {
        /** @var array<string, list<\DOMElement>> $bySignature */
        $bySignature = [];

        foreach ($items as $item) {
            $children = [];
            foreach ($item->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $children[] = $child;
                }
            }

            if (count($children) !== 1) {
                continue;
            }

            $bySignature[Selector::signature($children[0])][] = $children[0];
        }

        $innerItems = [];
        foreach ($bySignature as $group) {
            if (count($group) > count($innerItems)) {
                $innerItems = $group;
            }
        }

        if (count($innerItems) < self::MIN_REPEAT) {
            return null;
        }

        return [
            'itemSelector' => $this->buildItemSelector($container, $innerItems),
            'sample'       => $innerItems[0],
            'items'        => $innerItems,
        ];
    }

    /**
     * @param array{score:int,itemSelector:string,sample:\DOMElement,container:\DOMElement} $candidate
     * @return array{itemSelector:string,sample:\DOMElement}
     */
    private function candidateResult(array $candidate): array
    {
        return [
            'itemSelector' => $candidate['itemSelector'],
            'sample'       => $candidate['sample'],
        ];
    }

    /**
     * Group the direct element children of $node by structural signature and
     * keep only the groups that repeat enough to look like a list.
     *
     * @return list<list<\DOMElement>>
     */
    private function repeatingGroups(\DOMElement $node): array
    {
        $groups = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child instanceof \DOMElement) {
                $groups[Selector::signature($child)][] = $child;
            }
        }

        return array_values(array_filter(
            $groups,
            static fn (array $items) => count($items) >= self::MIN_REPEAT,
        ));
    }

    /**
     * Score a candidate group. A real listing is mostly clickable rows, and its
     * rows tend to be structurally rich "cards" (image + several nested nodes),
     * so we weight link-bearing repetition by image presence and average depth.
     * This keeps shallow navigation/sidebar lists from beating the real grid.
     *
     * @param list<\DOMElement> $items
     */
    private function score(array $items): int
    {
        $count = count($items);
        $withLink = 0;
        $withImage = 0;
        $descendants = 0;

        foreach ($items as $item) {
            if ($item->getElementsByTagName('a')->length > 0) {
                $withLink++;
            }
            if ($item->getElementsByTagName('img')->length > 0) {
                $withImage++;
            }
            $descendants += $item->getElementsByTagName('*')->length;
        }

        // Ignore decorative repetition that is mostly non-clickable.
        if ($withLink < max(2, (int) floor($count * 0.5))) {
            return 0;
        }

        $avgDepth = (int) min(40, $descendants / $count);

        $score = $withLink * (10 + $withImage * 5) * (1 + $avgDepth);

        return (int) ($score * $this->semanticMultiplier($items));
    }

    /**
     * Down-rank generic layout wrappers; boost rows with product-like markers.
     *
     * @param list<\DOMElement> $items
     */
    private function semanticMultiplier(array $items): float
    {
        $multiplier = 1.0;
        $first      = $items[0];
        $classes    = Selector::classes($first);

        $meaningful = array_values(array_filter(
            $classes,
            static fn (string $c): bool => ! in_array(strtolower($c), self::GENERIC_CLASSES, true),
        ));

        if ($meaningful === [] || $this->onlyGenericClasses($classes)) {
            $multiplier *= 0.05;
        }

        foreach ($classes as $class) {
            $lower = strtolower($class);
            foreach (self::SEMANTIC_CLASS_FRAGMENTS as $fragment) {
                if (str_contains($lower, $fragment)) {
                    $multiplier *= 4.0;
                    break 2;
                }
            }
        }

        if ($first->hasAttribute('data-product-id') || $first->hasAttribute('data-sku')) {
            $multiplier *= 3.0;
        }

        return $multiplier;
    }

    private function rowSpecificityScore(\DOMElement $sample): int
    {
        $score = 0;

        foreach (Selector::classes($sample) as $class) {
            $lower = strtolower($class);
            if (in_array($lower, self::GENERIC_CLASSES, true)) {
                continue;
            }

            $score += 10;

            foreach (self::SEMANTIC_CLASS_FRAGMENTS as $fragment) {
                if (str_contains($lower, $fragment)) {
                    $score += 50;
                    break;
                }
            }
        }

        if ($sample->hasAttribute('data-product-id') || $sample->hasAttribute('data-sku')) {
            $score += 100;
        }

        return $score;
    }

    /**
     * @param list<string> $classes
     */
    private function onlyGenericClasses(array $classes): bool
    {
        foreach ($classes as $class) {
            if (! in_array(strtolower($class), self::GENERIC_CLASSES, true)) {
                return false;
            }
        }

        return $classes !== [];
    }

    /**
     * Build a selector that matches EVERY item in the group, never one item's
     * unique id. Preference order, most to least stable:
     *   1. shared classes (the signature the group was formed on)
     *   2. a data-* attribute present on every item (e.g. [data-auction-id])
     *   3. a shared id prefix (id="offer-123" across items -> [id^="offer-"])
     *   4. the bare tag qualified by the container
     *
     * @param list<\DOMElement> $items
     */
    private function buildItemSelector(\DOMElement $container, array $items): string
    {
        $first = $items[0];
        $tag   = strtolower($first->tagName);

        // 1. Shared classes. The group was grouped by signature (tag + classes),
        // so the first item's classes are common to all of them.
        $classes = array_values(array_filter(
            Selector::classes($first),
            static fn (string $c) => (bool) preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $c),
        ));
        if ($classes !== []) {
            return $tag . '.' . implode('.', $classes);
        }

        // 2. A data-* attribute shared by every item.
        $dataAttr = $this->sharedDataAttribute($items);
        if ($dataAttr !== null) {
            return $tag . '[' . $dataAttr . ']';
        }

        // 3. A common id prefix (per-item ids that share a stable stem).
        $idPrefix = $this->sharedIdPrefix($items);
        if ($idPrefix !== null) {
            return $tag . '[id^="' . $idPrefix . '"]';
        }

        // 4. Bare tag (e.g. "li" / "div") — qualify it with the container.
        return Selector::cssFor($container) . ' > ' . $tag;
    }

    /**
     * Name of a data-* attribute present on every item, preferring an id-like
     * one (…-id). Returns null when the items share no data-* attribute.
     *
     * @param list<\DOMElement> $items
     */
    private function sharedDataAttribute(array $items): ?string
    {
        $shared = null;
        foreach ($items as $item) {
            $names = [];
            foreach (iterator_to_array($item->attributes ?? []) as $attr) {
                $name = $attr->nodeName;
                if (str_starts_with($name, 'data-') && (bool) preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $name)) {
                    $names[$name] = true;
                }
            }
            $shared = $shared === null ? $names : array_intersect_key($shared, $names);
            if ($shared === []) {
                return null;
            }
        }

        $names = array_keys($shared ?? []);
        sort($names);

        foreach ($names as $name) {
            if (str_ends_with($name, '-id')) {
                return $name;
            }
        }

        return $names[0] ?? null;
    }

    /**
     * Longest shared id prefix across items, trimmed to a separator so it does
     * not cut through a number (offer-475… -> "offer-"). Returns null unless the
     * prefix is a clean token ending in "-" or "_" and every item has an id.
     *
     * @param list<\DOMElement> $items
     */
    private function sharedIdPrefix(array $items): ?string
    {
        $prefix = null;
        foreach ($items as $item) {
            $id = trim($item->getAttribute('id'));
            if ($id === '') {
                return null;
            }

            if ($prefix === null) {
                $prefix = $id;
                continue;
            }

            $max = min(strlen($prefix), strlen($id));
            $i = 0;
            while ($i < $max && $prefix[$i] === $id[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') {
                return null;
            }
        }

        if ($prefix !== null && preg_match('/^(.*?[-_])/', $prefix, $m)) {
            $prefix = $m[1];
        }

        return is_string($prefix) && preg_match('/^[A-Za-z][A-Za-z0-9_-]*[-_]$/', $prefix) ? $prefix : null;
    }
}
