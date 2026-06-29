<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Immutable, serialisable recipe describing how to scrape one website.
 *
 * Produced (best-effort) by the generator in step 1, persisted as JSON, and
 * consumed by the crawl engine in step 2. Because it is plain data, a human can
 * open the JSON and refine any selector the auto-detector got wrong.
 */
final class ScrapeBlueprint
{
    /**
     * @param list<FieldSelector>   $fields
     * @param list<FieldSelector>   $detailFields
     * @param string|null           $imageFolder   Custom image subfolder; null → scrapes/images/{host}
     * @param list<string>          $pipelineNames Named processors from config('crawler.pipeline_registry')
     *                                             to run instead of the global pipeline. Empty = use global.
     * @param array<string,string>  $itemSchema    field → type coercion map (see SchemaCoercionProcessor).
     *                                             e.g. {"price":"float","year":"int","images":"string[]"}
     * @param bool                  $resumable     Persist dedup state between runs so only new items are
     *                                             processed on subsequent executions (--resume flag).
     */
    public function __construct(
        public readonly string $url,
        public readonly string $itemSelector,
        public readonly array $fields,
        public readonly PaginationSelector $pagination,
        public readonly CrawlMode $mode = CrawlMode::HTML,
        public readonly ?ApiConfig $api = null,
        public readonly ?InfiniteScrollConfig $infiniteScroll = null,
        public readonly bool $scrapeDetail = false,
        public readonly ?string $detailLinkField = null,
        public readonly array $detailFields = [],
        public readonly int $maxPages = 0,
        public readonly bool $getAllImages = false,
        public readonly bool $getPrimaryImage = false,
        public readonly bool $getGalleryImages = false,
        public readonly bool $hashNames = false,
        public readonly string $imageDisk = 'storage',
        public readonly ?string $imageFolder = null,
        public readonly HttpConfig $httpConfig = new HttpConfig(),
        public readonly CrawlConfig $crawlConfig = new CrawlConfig(),
        public readonly OutputConfig $outputConfig = new OutputConfig(),
        public readonly DedupConfig $dedup = new DedupConfig(),
        public readonly FiltersConfig $filters = new FiltersConfig(),
        public readonly CacheConfig $cache = new CacheConfig(),
        public readonly AutoThrottleConfig $autoThrottle = new AutoThrottleConfig(),
        public readonly array $pipelineNames = [],
        public readonly array $itemSchema = [],
        public readonly bool $resumable = false,
        /**
         * Search filters: extra URLs crawled with the same fields/pagination/
         * dedup/filters. Empty = just $url. Lets one robot cover several categories
         * of a site, and each entry can tag its items (e.g. category) via
         * {@see SearchFilter::$meta}.
         *
         * @var list<SearchFilter>
         */
        public readonly array $searchFilters = [],
    ) {
    }

    /** Clone this blueprint pointed at a different start URL (used per start_urls entry). */
    public function withUrl(string $url): self
    {
        $data        = $this->toArray();
        $data['url'] = $url;

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url:             (string) $data['url'],
            itemSelector:    (string) ($data['item_selector'] ?? ''),
            fields:          array_map(FieldSelector::fromArray(...), $data['fields'] ?? []),
            pagination:      PaginationSelector::fromArray($data['pagination'] ?? []),
            mode:            CrawlMode::fromValue($data['mode'] ?? 'html'),
            api:             isset($data['api']) && is_array($data['api']) ? ApiConfig::fromArray($data['api']) : null,
            infiniteScroll: isset($data['infinite_scroll']) && is_array($data['infinite_scroll'])
                ? InfiniteScrollConfig::fromArray($data['infinite_scroll'])
                : (isset($data['load_more']) && is_array($data['load_more']) ? InfiniteScrollConfig::fromArray($data['load_more']) : null),
            scrapeDetail:    (bool) ($data['scrape_detail'] ?? false),
            detailLinkField: $data['detail_link_field'] ?? null,
            detailFields:    array_map(FieldSelector::fromArray(...), $data['detail_fields'] ?? []),
            maxPages:        max(0, (int) ($data['max_pages'] ?? 0)),
            getAllImages:       (bool) ($data['get_all_images'] ?? $data['save_images'] ?? false),
            getPrimaryImage:    (bool) ($data['get_primary_image'] ?? $data['save_primary_image'] ?? false),
            getGalleryImages:   (bool) ($data['get_gallery_images'] ?? false),
            hashNames:          (bool) ($data['hash_names'] ?? false),
            imageDisk:       (string) ($data['image_disk'] ?? 'storage'),
            imageFolder:     isset($data['image_folder']) && $data['image_folder'] !== null && $data['image_folder'] !== ''
                                 ? (string) $data['image_folder']
                                 : null,
            httpConfig:      HttpConfig::fromArray($data['http_config'] ?? []),
            crawlConfig:     CrawlConfig::fromArray($data['crawl_config'] ?? []),
            outputConfig:    OutputConfig::fromArray($data['output_config'] ?? []),
            dedup:          DedupConfig::fromArray($data['dedup'] ?? []),
            filters:        FiltersConfig::fromArray($data['result_filters'] ?? $data['filters'] ?? []),
            cache:          CacheConfig::fromArray($data['cache'] ?? []),
            autoThrottle:   AutoThrottleConfig::fromArray($data['auto_throttle'] ?? []),
            pipelineNames:  is_array($data['pipeline_names'] ?? null) ? array_values($data['pipeline_names']) : [],
            itemSchema:     is_array($data['item_schema'] ?? null) ? $data['item_schema'] : [],
            resumable:      (bool) ($data['resumable'] ?? false),
            searchFilters:  (function (array $data): array {
                $raw = $data['search_filters'] ?? $data['start_urls'] ?? null;

                return is_array($raw)
                    ? array_values(array_map(
                        SearchFilter::fromMixed(...),
                        array_filter($raw, static fn ($e): bool => is_string($e) || is_array($e)),
                    ))
                    : [];
            })($data),
        );
    }

    public function toArray(): array
    {
        return [
            'url'               => $this->url,
            'mode'              => $this->mode->value,
            'api'               => $this->api?->toArray(),
            'infinite_scroll'   => $this->infiniteScroll?->toArray(),
            'item_selector'     => $this->itemSelector,
            'scrape_detail'     => $this->scrapeDetail,
            'pagination'        => $this->pagination->toArray(),
            'fields'            => array_map(static fn (FieldSelector $f) => $f->toArray(), $this->fields),
            'detail_link_field' => $this->detailLinkField,
            'detail_fields'     => array_map(static fn (FieldSelector $f) => $f->toArray(), $this->detailFields),
            'max_pages'         => $this->maxPages,
            'get_all_images'      => $this->getAllImages,
            'get_primary_image'   => $this->getPrimaryImage,
            'get_gallery_images'  => $this->getGalleryImages,
            'hash_names'          => $this->hashNames,
            'image_disk'        => $this->imageDisk,
            'image_folder'      => $this->imageFolder,
            'http_config'       => $this->httpConfig->toArray(),
            'crawl_config'      => $this->crawlConfig->toArray(),
            'output_config'     => $this->outputConfig->toArray(),
            'dedup'          => $this->dedup->toArray(),
            'result_filters' => $this->filters->toArray(),
            'cache'          => $this->cache->toArray(),
            'auto_throttle'  => $this->autoThrottle->toArray(),
            'pipeline_names' => $this->pipelineNames,
            'item_schema'    => $this->itemSchema,
            'resumable'      => $this->resumable,
            'search_filters' => array_map(static fn (SearchFilter $s) => $s->toArrayOrString(), $this->searchFilters),
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Blueprint JSON embedded in generated robot commands.
     *
     * Omits image_disk / image_folder (robot class properties own destination)
     * and drops field stubs with no selector and no label.
     */
    public function toRobotJson(): string
    {
        $data = $this->toArray();
        unset($data['image_disk'], $data['image_folder']);

        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('Blueprint JSON is invalid.');
        }

        return self::fromArray($data);
    }
}
