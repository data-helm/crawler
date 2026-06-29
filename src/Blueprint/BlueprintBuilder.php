<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Fluent builder that assembles a {@see ScrapeBlueprint} step by step as the
 * detectors contribute their findings.
 */
final class BlueprintBuilder
{
    private string $url = '';
    private string $itemSelector = '';
    /** @var list<FieldSelector> */
    private array $fields = [];
    private PaginationSelector $pagination;
    private CrawlMode $mode = CrawlMode::HTML;
    private ?ApiConfig $api = null;
    private ?InfiniteScrollConfig $infiniteScroll = null;
    private bool $scrapeDetail = false;
    private ?string $detailLinkField = null;
    /** @var list<FieldSelector> */
    private array $detailFields = [];
    private int $maxPages = 0;
    private bool $getAllImages = false;
    private bool $getPrimaryImage = false;
    private bool $getGalleryImages = false;
    private bool $hashNames = false;
    private string $imageDisk = 'storage';
    private ?string $imageFolder = null;
    private HttpConfig $httpConfig;
    private CrawlConfig $crawlConfig;
    private OutputConfig $outputConfig;
    private DedupConfig $dedup;
    private FiltersConfig $filters;
    private CacheConfig $cache;
    private AutoThrottleConfig $autoThrottle;
    /** @var list<string> */
    private array $pipelineNames = [];
    /** @var array<string,string> */
    private array $itemSchema = [];
    private bool $resumable = false;

    public function __construct()
    {
        $this->pagination    = PaginationSelector::none();
        $this->httpConfig    = new HttpConfig();
        $this->crawlConfig   = new CrawlConfig();
        $this->outputConfig  = new OutputConfig();
        $this->dedup         = new DedupConfig();
        $this->filters       = new FiltersConfig();
        $this->cache         = new CacheConfig();
        $this->autoThrottle  = new AutoThrottleConfig();
    }

    public static function make(): self
    {
        return new self();
    }

    public function url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function itemSelector(string $selector): self
    {
        $this->itemSelector = $selector;

        return $this;
    }

    public function mode(CrawlMode $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function api(?ApiConfig $api): self
    {
        $this->api = $api;

        return $this;
    }

    public function infiniteScroll(?InfiniteScrollConfig $infiniteScroll): self
    {
        $this->infiniteScroll = $infiniteScroll;

        return $this;
    }

    public function addField(FieldSelector $field): self
    {
        $this->fields[] = $field;

        return $this;
    }

    public function pagination(PaginationSelector $pagination): self
    {
        $this->pagination = $pagination;

        return $this;
    }

    public function scrapeDetail(bool $scrapeDetail): self
    {
        $this->scrapeDetail = $scrapeDetail;

        return $this;
    }

    public function detailLinkField(?string $field): self
    {
        $this->detailLinkField = $field;

        return $this;
    }

    public function addDetailField(FieldSelector $field): self
    {
        $this->detailFields[] = $field;

        return $this;
    }

    public function maxPages(int $maxPages): self
    {
        $this->maxPages = max(0, $maxPages);

        return $this;
    }

    public function getAllImages(bool $getAllImages): self
    {
        $this->getAllImages = $getAllImages;

        return $this;
    }

    public function getPrimaryImage(bool $getPrimaryImage): self
    {
        $this->getPrimaryImage = $getPrimaryImage;

        return $this;
    }

    public function getGalleryImages(bool $getGalleryImages): self
    {
        $this->getGalleryImages = $getGalleryImages;

        return $this;
    }

    public function hashNames(bool $hashNames): self
    {
        $this->hashNames = $hashNames;

        return $this;
    }

    public function imageDisk(string $imageDisk): self
    {
        $this->imageDisk = $imageDisk !== '' ? $imageDisk : 'storage';

        return $this;
    }

    public function imageFolder(?string $folder): self
    {
        $this->imageFolder = $folder !== '' ? $folder : null;

        return $this;
    }

    public function httpConfig(HttpConfig $config): self
    {
        $this->httpConfig = $config;

        return $this;
    }

    public function crawlConfig(CrawlConfig $config): self
    {
        $this->crawlConfig = $config;

        return $this;
    }

    public function outputConfig(OutputConfig $config): self
    {
        $this->outputConfig = $config;

        return $this;
    }

    public function dedup(DedupConfig $config): self
    {
        $this->dedup = $config;

        return $this;
    }

    public function filters(FiltersConfig $config): self
    {
        $this->filters = $config;

        return $this;
    }

    public function cache(CacheConfig $config): self
    {
        $this->cache = $config;

        return $this;
    }

    public function autoThrottle(AutoThrottleConfig $config): self
    {
        $this->autoThrottle = $config;

        return $this;
    }

    /**
     * @param list<string> $names  Short processor names from config('crawler.pipeline_registry').
     */
    public function pipelineNames(array $names): self
    {
        $this->pipelineNames = $names;

        return $this;
    }

    /**
     * @param array<string,string> $schema  field → type map.
     */
    public function itemSchema(array $schema): self
    {
        $this->itemSchema = $schema;

        return $this;
    }

    public function resumable(bool $resumable): self
    {
        $this->resumable = $resumable;

        return $this;
    }

    public function build(): ScrapeBlueprint
    {
        return new ScrapeBlueprint(
            url:             $this->url,
            itemSelector:    $this->itemSelector,
            fields:          $this->filterUsableFields($this->fields),
            pagination:      $this->pagination,
            mode:            $this->mode,
            api:             $this->api,
            infiniteScroll: $this->infiniteScroll,
            scrapeDetail:    $this->scrapeDetail,
            detailLinkField: $this->detailLinkField,
            detailFields:    $this->filterUsableFields($this->detailFields),
            maxPages:        $this->maxPages,
            getAllImages:       $this->getAllImages,
            getPrimaryImage:    $this->getPrimaryImage,
            getGalleryImages:   $this->getGalleryImages,
            hashNames:          $this->hashNames,
            imageDisk:       $this->imageDisk,
            imageFolder:     $this->imageFolder,
            httpConfig:      $this->httpConfig,
            crawlConfig:     $this->crawlConfig,
            outputConfig:    $this->outputConfig,
            dedup:         $this->dedup,
            filters:       $this->filters,
            cache:         $this->cache,
            autoThrottle:  $this->autoThrottle,
            pipelineNames: $this->pipelineNames,
            itemSchema:    $this->itemSchema,
            resumable:     $this->resumable,
        );
    }

    /**
     * Drop selectors with no CSS/XPath/JSON path and no label (dead stubs).
     *
     * @param list<FieldSelector> $fields
     *
     * @return list<FieldSelector>
     */
    private function filterUsableFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            static fn (FieldSelector $field) => $field->css !== ''
                || ($field->label !== null && $field->label !== ''),
        ));
    }
}
