<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;

/**
 * Strategy that inspects a sample element and, if it recognises a field, returns
 * a {@see FieldSelector} describing how to extract it. The generator runs a
 * collection of these (one per field kind) over each list item.
 */
interface FieldDetector
{
    public function detect(\DOMElement $sample): ?FieldSelector;
}
