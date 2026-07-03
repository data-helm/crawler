<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\Page;
use DataHelm\Crawler\Scraping\ItemExtractor;

/**
 * Confirms a candidate list row selector by running field detectors on a sample
 * and checking that extraction succeeds on rows spread across the page.
 */
final class ListCandidateValidator
{
    private const MIN_SAMPLE_ITEMS = 5;

    private const MAX_SAMPLE_ITEMS = 15;

    private const MIN_LINKS_FOR_UNIQUENESS = 3;

    /**
     * @param list<FieldDetector> $fieldDetectors
     * @param list<string>        $coreFields Field names that define a valid row.
     */
    public function __construct(
        private readonly array $fieldDetectors,
        private readonly array $coreFields = ['link', 'title', 'image'],
        private readonly int $minCoreFields = 2,
        private readonly float $minSuccessRate = 0.6,
        private readonly float $minLinkUniqueness = 0.65,
    ) {
    }

    /**
     * @return array{valid: bool, score: float, fields: list<FieldSelector>}
     */
    public function validate(Page $page, string $itemSelector, \DOMElement $sample): array
    {
        $fields = $this->detectFields($sample);

        if (count($this->matchingCoreFields($fields)) < $this->minCoreFields) {
            return ['valid' => false, 'score' => 0.0, 'fields' => $fields];
        }

        $rate = $this->sampleSuccessRate($page, $itemSelector, $fields);
        if ($rate < $this->minSuccessRate) {
            return ['valid' => false, 'score' => $rate, 'fields' => $fields];
        }

        $linkUniqueness = $this->linkUniquenessRate($page, $itemSelector, $fields);
        if ($linkUniqueness !== null && $linkUniqueness < $this->minLinkUniqueness) {
            return ['valid' => false, 'score' => $rate, 'fields' => $fields];
        }

        return [
            'valid'  => true,
            'score'  => $rate,
            'fields' => $fields,
        ];
    }

    /**
     * @return list<FieldSelector>
     */
    private function detectFields(\DOMElement $sample): array
    {
        $fields = [];

        foreach ($this->fieldDetectors as $detector) {
            $field = $detector->detect($sample);
            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param list<FieldSelector> $fields
     */
    private function sampleSuccessRate(Page $page, string $itemSelector, array $fields): float
    {
        try {
            $items = $page->crawler()->filter($itemSelector);
        } catch (\Throwable) {
            return 0.0;
        }

        $total = $items->count();
        if ($total === 0) {
            return 0.0;
        }

        $sampleCount = min(max(self::MIN_SAMPLE_ITEMS, min($total, self::MAX_SAMPLE_ITEMS)), $total);
        $indices     = $this->spreadSampleIndices($total, $sampleCount);
        $extractor   = new ItemExtractor($fields);
        $hits        = 0;

        foreach ($indices as $index) {
            $data = $extractor->extract($items->eq($index))->toArray();
            if ($this->itemHasCoreContent($data)) {
                $hits++;
            }
        }

        return $hits / count($indices);
    }

    /**
     * @param list<FieldSelector> $fields
     */
    private function linkUniquenessRate(Page $page, string $itemSelector, array $fields): ?float
    {
        if (! in_array('link', $this->coreFields, true)) {
            return null;
        }

        $linkField = null;
        foreach ($fields as $field) {
            if ($field->name === 'link') {
                $linkField = $field;
                break;
            }
        }

        if ($linkField === null) {
            return null;
        }

        try {
            $items = $page->crawler()->filter($itemSelector);
        } catch (\Throwable) {
            return null;
        }

        $total = $items->count();
        if ($total === 0) {
            return null;
        }

        $sampleCount = min(max(self::MIN_SAMPLE_ITEMS, min($total, self::MAX_SAMPLE_ITEMS)), $total);
        $indices     = $this->spreadSampleIndices($total, $sampleCount);
        $extractor   = new ItemExtractor([$linkField]);
        $links       = [];

        foreach ($indices as $index) {
            $link = $extractor->extract($items->eq($index))->get('link');
            if (is_string($link) && trim($link) !== '') {
                $links[] = trim($link);
            }
        }

        if (count($links) < self::MIN_LINKS_FOR_UNIQUENESS) {
            return null;
        }

        return count(array_unique($links)) / count($links);
    }

    /**
     * Evenly spaced indices across the full match set (first, middle, last, …).
     *
     * @return list<int>
     */
    private function spreadSampleIndices(int $total, int $sampleCount): array
    {
        if ($total <= $sampleCount) {
            return range(0, max(0, $total - 1));
        }

        $indices = [];
        for ($i = 0; $i < $sampleCount; $i++) {
            $indices[] = (int) round($i * ($total - 1) / max(1, $sampleCount - 1));
        }

        return array_values(array_unique($indices));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function itemHasCoreContent(array $data): bool
    {
        $filled = 0;

        foreach ($this->coreFields as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $filled++;
            }
        }

        return $filled >= $this->minCoreFields;
    }

    /**
     * @param list<FieldSelector> $fields
     * @return list<FieldSelector>
     */
    private function matchingCoreFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (FieldSelector $field): bool => in_array($field->name, $this->coreFields, true),
        ));
    }
}
