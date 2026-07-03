<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists scraped items directly to an Eloquent model via upsert.
 *
 * Usage (bind in AppServiceProvider or pass directly):
 *
 *   new DatabaseSink(
 *       model:       MyAuction::class,
 *       uniqueBy:    ['link'],          // upsert key(s)
 *       updateOnly:  ['title', 'price'],// restrict which columns are updated
 *   );
 *
 * Items are upserted one at a time. For high-volume scrapes consider
 * overriding write() to buffer rows and flush in batches.
 *
 * Field mapping: by default every key in ScrapedItem::toArray() is written.
 * Pass $fieldMap to rename or subset fields:
 *   ['link' => 'url', 'title' => 'name']
 *
 * @template TModel of Model
 */
class DatabaseSink implements OutputSink
{
    private int $count = 0;

    /**
     * @param class-string<TModel>   $model      Eloquent model class.
     * @param list<string>           $uniqueBy   Column(s) used as the upsert key.
     * @param list<string>|null      $updateOnly Limit which columns are updated (null = all).
     * @param array<string,string>   $fieldMap   Rename scraped fields before insert.
     * @param list<string>           $exclude    Scraped fields to drop before insert.
     */
    public function __construct(
        private readonly string $model,
        private readonly array $uniqueBy = ['link'],
        private readonly ?array $updateOnly = null,
        private readonly array $fieldMap = [],
        private readonly array $exclude = [],
    ) {
    }

    public function open(string $name): void
    {
        $this->count = 0;
    }

    public function write(ScrapedItem $item): void
    {
        $row = $this->mapFields($item->toArray());

        if ($row === []) {
            return;
        }

        /** @var class-string<Model> $model */
        $model = $this->model;

        $updateColumns = $this->updateOnly
            ?? array_keys(array_diff_key($row, array_flip($this->uniqueBy)));

        $model::upsert([$row], $this->uniqueBy, $updateColumns);

        $this->count++;
    }

    public function close(): string
    {
        /** @var Model $instance */
        $instance = new $this->model();

        return sprintf(
            '%d row(s) upserted into %s.%s',
            $this->count,
            $instance->getConnection()->getDatabaseName(),
            $instance->getTable(),
        );
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function mapFields(array $data): array
    {
        // Drop excluded keys.
        foreach ($this->exclude as $key) {
            unset($data[$key]);
        }

        if ($this->fieldMap === []) {
            return $data;
        }

        $mapped = [];
        foreach ($data as $key => $value) {
            $mapped[$this->fieldMap[$key] ?? $key] = $value;
        }

        return $mapped;
    }
}
