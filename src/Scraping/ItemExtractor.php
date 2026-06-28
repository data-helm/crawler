<?php

namespace DataHelm\Crawler\Scraping;

use DataHelm\Crawler\Blueprint\FieldSelector;
use DataHelm\Crawler\Dom\LabelValue;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Applies a set of {@see FieldSelector}s to a context node (a list item or a
 * whole detail page) and produces a {@see ScrapedItem}.
 */
final class ItemExtractor
{
    /**
     * @param list<FieldSelector> $fields
     */
    public function __construct(private readonly array $fields)
    {
    }

    public function extract(Crawler $context): ScrapedItem
    {
        $item = new ScrapedItem();

        // Built lazily and reused: label lookup is computed once per context.
        $labels = null;

        foreach ($this->fields as $field) {
            if ($field->label !== null && $field->label !== '') {
                $labels ??= $this->labelMap($context);
                $raw = $labels[LabelValue::normalize($field->label)] ?? null;
                $item->set($field->name, $this->applyRegex(is_string($raw) ? trim($raw) : $raw, $field));

                continue;
            }

            $item->set($field->name, $this->value($context, $field));
        }

        return $item;
    }

    /**
     * @return array<string,string>
     */
    private function labelMap(Crawler $context): array
    {
        $root = $context->getNode(0);

        return $root !== null ? LabelValue::map($root) : [];
    }

    private function value(Crawler $context, FieldSelector $field): mixed
    {
        try {
            if ($field->css === '') {
                $target = $context;
            } elseif ($field->type === 'xpath') {
                $target = $context->filterXPath($field->css);
            } else {
                $target = $context->filter($field->css);
            }

            if ($target->count() === 0) {
                return $field->multiple ? [] : null;
            }

            if ($field->multiple) {
                $values = $target->each(fn (Crawler $node) => $this->readValue($node, $field));

                return array_values(array_filter(
                    $values,
                    static fn ($value) => $value !== null && $value !== '',
                ));
            }

            return $this->readValue($target->first(), $field);
        } catch (\Throwable) {
            return $field->multiple ? [] : null;
        }
    }

    private function readValue(Crawler $node, FieldSelector $field): mixed
    {
        $raw = $field->attribute !== null ? $node->attr($field->attribute) : $node->text();
        $raw = is_string($raw) ? trim($raw) : $raw;

        return $this->applyRegex($raw, $field);
    }

    private function applyRegex(mixed $raw, FieldSelector $field): mixed
    {
        if ($field->regex !== null && is_string($raw) && $raw !== '') {
            return preg_match($field->regex, $raw, $matches) ? ($matches[1] ?? $matches[0]) : null;
        }

        return $raw;
    }
}
