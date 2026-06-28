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
     * @return array{path:string, sample:array<string,mixed>, count:int}|null
     */
    public function detect(mixed $data): ?array
    {
        $best = null;
        $this->walk($data, '', $best);

        return $best;
    }

    /**
     * @param array{path:string, sample:array<string,mixed>, count:int}|null $best
     */
    private function walk(mixed $node, string $path, ?array &$best): void
    {
        if (! is_array($node)) {
            return;
        }

        if ($this->isListOfObjects($node)) {
            $count = count($node);
            $depth = $path === '' ? 0 : substr_count($path, '.');

            if (
                $best === null
                || $count > $best['count']
                || ($count === $best['count'] && $depth < substr_count($best['path'], '.'))
            ) {
                /** @var array<string,mixed> $sample */
                $sample = $node[array_key_first($node)];
                $best = ['path' => $path, 'sample' => $sample, 'count' => $count];
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
