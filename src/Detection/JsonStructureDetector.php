<?php

namespace DataHelm\Crawler\Detection;

/**
 * Given a decoded JSON response, finds the most likely "list of records" — the
 * array of objects an API returns for a listing — and reports its dot-path plus
 * a sample record (used to scaffold field selectors).
 *
 * Strategy: walk the structure recursively and pick the list-of-objects with the
 * most elements, preferring shallower paths on ties.
 */
final class JsonStructureDetector
{
    /**
     * Minimum distinct fields for a list's records to count as "real records"
     * rather than a facet/aggregation map (e.g. `{"2018": 255}` buckets), which
     * a listing endpoint often returns alongside the actual data and which can be
     * far longer than the data array itself.
     */
    private const RECORD_MIN_FIELDS = 3;

    /**
     * @return array{path:string, sample:array<string,mixed>, count:int}|null
     */
    public function detect(mixed $data): ?array
    {
        $best = null;
        $this->walk($data, '', $best);

        if ($best !== null) {
            unset($best['tier'], $best['category']); // internal ranking keys — not part of the contract
        }

        /** @var array{path:string, sample:array<string,mixed>, count:int}|null $best */
        return $best;
    }

    /**
     * Classify a list's dot-path as content data (2), unknown (1) or a
     * facet/aggregation/metadata list (0). A listing endpoint commonly returns
     * its records alongside far longer aggregation arrays (facets, filter counts);
     * without this, the aggregation would win on raw size. A facet segment
     * anywhere in the path demotes it even if the leaf looks data-like
     * (e.g. `aggregations.MODEL.elements`).
     */
    private function pathCategory(string $path): int
    {
        if ($path === '') {
            return 2; // a root-level array is almost always the data itself
        }

        $segments = explode('.', $path);

        foreach ($segments as $segment) {
            if (preg_match('/^(aggregations?|facets?|filters?|buckets?|refinements?|meta|_links|links|breadcrumbs?)$/i', $segment)) {
                return 0;
            }
        }

        $leaf = end($segments);
        if (preg_match('/(results?|data|items?|records?|content|deals?|products?|listings?|ads?|hits|docs|nodes|rows|entries|elements)$/i', (string) $leaf)) {
            return 2;
        }

        return 1;
    }

    /**
     * @param array{path:string, sample:array<string,mixed>, count:int, tier:int, category:int}|null $best
     */
    private function walk(mixed $node, string $path, ?array &$best): void
    {
        if (! is_array($node)) {
            return;
        }

        if ($this->isListOfObjects($node)) {
            $count  = count($node);
            $depth  = $path === '' ? 0 : substr_count($path, '.');
            /** @var array<string,mixed> $sample */
            $sample = $node[array_key_first($node)];
            // Record-like lists (rich objects) beat facet/bucket lists outright,
            // even when the facet list has many more elements.
            $tier     = count($sample) >= self::RECORD_MIN_FIELDS ? 1 : 0;
            $category = $this->pathCategory($path);

            // Rank by: content category (data > unknown > facet), then record
            // richness, then size, then shallowness. Category dominates so a long
            // aggregation/facet array never beats the real (often page-sized) data.
            $better = $best === null
                || $category > $best['category']
                || ($category === $best['category'] && $tier > $best['tier'])
                || ($category === $best['category'] && $tier === $best['tier'] && $count > $best['count'])
                || ($category === $best['category'] && $tier === $best['tier'] && $count === $best['count']
                    && $depth < substr_count($best['path'], '.'));

            if ($better) {
                $best = ['path' => $path, 'sample' => $sample, 'count' => $count, 'tier' => $tier, 'category' => $category];
            }

            return; // Don't descend into the records themselves.
        }

        // Associative container — descend into each value.
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
                $this->walk($value, $childPath, $best);
            }
        }
    }

    private function isListOfObjects(array $node): bool
    {
        if ($node === [] || ! array_is_list($node)) {
            return false;
        }

        $objects = 0;
        foreach ($node as $element) {
            if (is_array($element) && ! array_is_list($element)) {
                $objects++;
            }
        }

        // Majority of elements are associative objects.
        return $objects > 0 && $objects >= (count($node) / 2);
    }
}
