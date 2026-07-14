<?php

namespace DataHelm\Crawler\Scaffolding;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;

/**
 * Writes a self-contained Artisan "robot" command for a site, embedding the
 * blueprint JSON so the command runs without any external file. Used by
 * `datahelm:scrap:generate` to turn a detected blueprint into code under
 * app/Console/Commands/RobotsCommand.
 */
final class RobotCommandScaffolder
{
    public function __construct(
        private readonly string $directory,
        private readonly string $commandPrefix = 'datahelm',
        private readonly string $namespace = 'App\\Console\\Commands\\RobotsCommand',
    ) {
    }

    /**
     * @return string Absolute path of the written command file.
     */
    public function scaffold(ScrapeBlueprint $blueprint, string $name, bool $overwrite = false): string
    {
        $class = 'Robot' . $name;
        $path = rtrim($this->directory, '/') . '/' . $class . '.php';

        if (! $overwrite && file_exists($path)) {
            throw new \RuntimeException("Robot already exists: {$path} (pass --force to overwrite).");
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new \RuntimeException("Could not create directory: {$this->directory}");
        }

        file_put_contents($path, $this->render($blueprint, $class, $name));

        return $path;
    }

    private function render(ScrapeBlueprint $blueprint, string $class, string $name): string
    {
        $host = parse_url($blueprint->url, PHP_URL_HOST) ?: $blueprint->url;

        return strtr(self::TEMPLATE, [
            '{{NAMESPACE}}'      => $this->namespace,
            '{{CLASS}}'          => $class,
            '{{NAME}}'           => strtolower($name),
            '{{HOST}}'           => $host,
            '{{IMAGE_DISK}}'     => $blueprint->imageDisk,
            '{{SIGNATURE}}'      => $this->commandPrefix . ':robot:' . strtolower($name),
            '{{COMMAND_PREFIX}}' => $this->commandPrefix,
            '{{DESCRIPTION}}'    => "Scrape {$host} using a baked-in blueprint",
            '{{URL}}'            => $blueprint->url,
            '{{BLUEPRINT}}'      => $blueprint->toRobotJson(),
            '{{DEDUP_KEY}}'      => $blueprint->dedup->keyField,
        ]);
    }

    /**
     * Template for the generated command. The closing markers sit in column 0 so
     * the nowdoc bodies are copied verbatim (no indentation stripping).
     */
    private const TEMPLATE = <<<'PHP'
<?php

namespace {{NAMESPACE}};

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Media\ImageStore;
use DataHelm\Crawler\Output\JsonExporter;
use DataHelm\Crawler\Scraping\CrawlEngine;
use DataHelm\Crawler\Scraping\ScrapedItem;
use DataHelm\Crawler\Console\Concerns\ScrapesToConsole;
use Illuminate\Console\Command;

/**
 * Auto-generated robot command (scrap:generate --robot).
 *
 * Blueprint captured from: {{URL}}
 * Edit the BLUEPRINT JSON below to refine selectors, then re-run the command.
 *
 * Quick start:
 *   1. php artisan {{COMMAND_PREFIX}}:robot:{{NAME}} --limit=5
 *   2. Inspect storage/app/scrapes/{{NAME}}.json
 *   3. Edit the closure in handle() — disk, fields, model
 *
 * Image destination: $imageDisk + $imageFolder on this class (local, GCS, S3, …).
 * Image processing (resize, watermark): optional — uncomment processImage() below.
 *
 * Per-item logic lives in the closure inside handle().
 */
class {{CLASS}} extends Command
{
    use ScrapesToConsole;

    protected $signature = '{{SIGNATURE}}
        {--limit=0 : Stop after N items (0 = no limit)}
        {--output= : Output path (default storage/app/scrapes/<name>.json; use - for stdout)}
        {--resume : Skip items already seen in a previous run (requires resumable=true in blueprint)}
        {--no-images : Skip downloading images; URLs still appear in each item}';

    protected $description = '{{DESCRIPTION}}';

    // -------------------------------------------------------------------------
    // Image destination — any disk in config/filesystems.php ('storage', 'gcs', …)
    // -------------------------------------------------------------------------

    protected string $imageDisk = '{{IMAGE_DISK}}';

    protected string $imageFolder = 'scrapes/images/{{HOST}}';

    // -------------------------------------------------------------------------

    private const BLUEPRINT = <<<'JSON'
{{BLUEPRINT}}
JSON;

    public function handle(CrawlEngine $engine, ImageStore $images): int
    {
        $blueprint = ScrapeBlueprint::fromJson(self::BLUEPRINT);

        $hashNames = $blueprint->hashNames;
        $format    = $blueprint->outputConfig->format;

        $downloadImages = ! ($this->option('no-images'));

        // jsonl/csv/markdown need the full item list to shape their output, so
        // they're buffered here and written once via writeItems() after the
        // crawl. 'json' (the default) keeps streaming straight to disk.
        $buffered = [];

        // Runs once per scraped item as it streams in (nothing buffered).
        $this->crawlEach(
            $engine,
            $blueprint,
            function (ScrapedItem $item) use ($images, $hashNames, $downloadImages, $format, &$buffered): void {
                $imagePath = null;

                if ($downloadImages) {
                    // Blueprint only puts URLs on the item; download here.
                    $imageUrl = $item->get('primary_image') ?? $item->get('image');
                    if (is_array($imageUrl)) {
                        $imageUrl = $imageUrl[0] ?? null;
                    }
                    $imagePath = is_string($imageUrl) && $imageUrl !== ''
                        ? $images->store($imageUrl, $this->imageDisk, $this->imageFolder, $hashNames)
                        : null;

                    // Optional resize / watermark — uncomment inside processImage().
                    $this->processImage($imagePath);

                    // Every gallery image (uncomment to store all photos from "gallery_images"):
                    // foreach ((array) $item->get('gallery_images') as $url) {
                    //     if (is_string($url) && $url !== '') {
                    //         $this->processImage($images->store($url, $this->imageDisk, $this->imageFolder, $hashNames));
                    //     }
                    // }
                }

                $record = [...$item->toArray(), 'image_path' => $imagePath];

                if ($format === 'json') {
                    // DEFAULT — append to storage/app/scrapes/{{NAME}}.json (or --output).
                    $this->saveJson($record);
                } else {
                    $buffered[] = new ScrapedItem($record);
                }

                // SAVE TO A MODEL — replace the branch above, uncomment (key matches dedup key_field):
                //
                // Product::updateOrCreate(
                //     ['{{DEDUP_KEY}}' => $item->get('{{DEDUP_KEY}}')],
                //     [
                //         'title'              => $item->get('title'),
                //         'price'              => $item->get('price'),
                //         'image_path' => $imagePath,
                //         'raw'                => $item->toArray(),
                //     ],
                // );
            },
            (int) $this->option('limit'),
        );

        // Writes storage/app/scrapes/{{NAME}}.<ext> (or --output) using the
        // exporter that matches output_config.format — jsonl, csv, or markdown.
        if ($format !== 'json') {
            $this->writeItems($buffered, new JsonExporter(), $blueprint->outputConfig);
        }

        return self::SUCCESS;
    }

    /**
     * Optional per-image processing — runs after each download. Images are stored
     * as-is until you uncomment the block below.
     */
    protected function processImage(?string $path): void
    {
        if ($path === null) {
            return;
        }

        // Uncomment after: composer require intervention/image
        // (move the use lines below to the top of this file when enabling)
        // use Intervention\Image\ImageManager;
        // use Intervention\Image\Drivers\Gd\Driver;
        // use Illuminate\Support\Facades\Storage;
        //
        // $full = in_array($this->imageDisk, ['storage', 'local'], true)
        //     ? storage_path('app/' . $path)
        //     : Storage::disk($this->imageDisk)->path($path);
        //
        // if (! is_file($full)) {
        //     return;
        // }
        //
        // $manager = new ImageManager(new Driver());
        // $image   = $manager->read($full);
        // $image->scaleDown(width: 800);
        // $image->save($full, quality: 85);
    }
}
PHP;
}
