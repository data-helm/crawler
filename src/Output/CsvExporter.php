<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Exports scraped items as CSV.
 *
 * - Column headers are derived from the union of all field names across all items
 *   (so every item contributes its fields to the header row).
 * - Array values (e.g. saved_images, images) are JSON-encoded in their cell.
 * - First row is always the header; values are quoted by PHP's fputcsv.
 */
final class CsvExporter implements ItemExporter
{
    public function export(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $headers = $this->collectHeaders($items);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $headers);

        foreach ($items as $item) {
            $data = $item->toArray();
            $row  = array_map(
                static fn (string $col) => isset($data[$col])
                    ? (is_array($data[$col]) ? json_encode($data[$col], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $data[$col])
                    : '',
                $headers,
            );
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @param list<ScrapedItem> $items
     * @return list<string>
     */
    private function collectHeaders(array $items): array
    {
        $keys = [];
        foreach ($items as $item) {
            foreach (array_keys($item->toArray()) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
