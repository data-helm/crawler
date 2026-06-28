<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\FieldName;
use DataHelm\Crawler\Dom\LabelValue;

/**
 * Suggests one field per "label: value" pair found in a subtree, named after the
 * label and extracted by label at run time. Unlike the single-value detectors
 * (link/title/image/price), this returns *many* fields so the generated blueprint
 * lists every candidate for the user to keep or prune.
 */
final class LabeledFieldDetector
{
    private const MAX_FIELDS = 40;

    /**
     * @return list<FieldSelector>
     */
    public function detect(\DOMElement $sample): array
    {
        $fields = [];
        $seen = [];

        foreach (LabelValue::detect($sample) as $pair) {
            $name = FieldName::fromLabel($pair['label']);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $fields[] = new FieldSelector(
                name: $name,
                css: '',
                attribute: null,
                regex: null,
                multiple: false,
                label: $pair['label'],
            );

            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }
        }

        return $fields;
    }
}
