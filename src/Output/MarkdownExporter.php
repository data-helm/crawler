<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Exports scraped items as a single Markdown document — one section per item.
 *
 * This is the "LLM-ready" output format: the result drops straight into a model's
 * context window or a RAG index without any HTML noise. Each item becomes a
 * section headed by its most title-like field, followed by any long-form Markdown
 * body (a field named body/content/markdown, or one already containing newlines),
 * then a bullet list of its remaining scalar fields.
 *
 * Pairs naturally with a field of type "markdown" (see {@see \DataHelm\Crawler\Markdown\HtmlToMarkdown}):
 * extract the article body as Markdown, then export the whole run as Markdown.
 */
final class MarkdownExporter implements ItemExporter
{
    /** Field names, in priority order, used as a section heading. */
    private const HEADING_KEYS = ['title', 'name', 'heading', 'headline', 'label'];

    /** Field names, in priority order, rendered as the section body prose. */
    private const BODY_KEYS = ['markdown', 'content', 'body', 'description', 'text'];

    public function export(array $items): string
    {
        $sections = [];
        foreach ($items as $index => $item) {
            $sections[] = $this->renderItem($item, $index + 1);
        }
        $sections = array_filter($sections, static fn (string $s): bool => $s !== '');

        return $sections === [] ? '' : implode("\n\n---\n\n", $sections) . "\n";
    }

    private function renderItem(ScrapedItem $item, int $position): string
    {
        $data = $item->toArray();

        $headingKey = $this->firstPresent($data, self::HEADING_KEYS);
        $heading = $headingKey !== null && is_scalar($data[$headingKey]) && (string) $data[$headingKey] !== ''
            ? (string) $data[$headingKey]
            : 'Item ' . $position;

        $bodyKey = $this->firstPresent($data, self::BODY_KEYS);
        $body = $bodyKey !== null && is_string($data[$bodyKey]) && trim($data[$bodyKey]) !== ''
            ? trim($data[$bodyKey])
            : null;

        $lines = ['## ' . $this->inline($heading)];

        if ($body !== null) {
            $lines[] = '';
            $lines[] = $body;
        }

        // Remaining scalar fields become a bullet list; the heading and body
        // fields are already rendered above, so skip them here.
        $used = array_filter([$headingKey, $bodyKey], static fn (?string $k): bool => $k !== null);
        $meta = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $used, true)) {
                continue;
            }
            $rendered = $this->renderValue($value);
            if ($rendered !== null) {
                $meta[] = '- **' . $this->inline((string) $key) . ':** ' . $rendered;
            }
        }

        if ($meta !== []) {
            $lines[] = '';
            $lines = array_merge($lines, $meta);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $keys
     */
    private function firstPresent(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $key;
            }
        }

        return null;
    }

    private function renderValue(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            $flat = array_map(
                fn ($v): string => is_scalar($v) ? $this->inline((string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $value,
            );

            return implode(', ', array_filter($flat, static fn (string $v): bool => $v !== ''));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $this->inline((string) $value);
    }

    /** Flatten a value to a single line so it never breaks list/heading markup. */
    private function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
