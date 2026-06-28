<?php

namespace DataHelm\Crawler\Console;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Console\Concerns\UsesCrawlerPrefix;
use DataHelm\Crawler\Detection\BlueprintValidator;
use Illuminate\Console\Command;

/**
 * Validates a blueprint JSON file for structural correctness and likely
 * mis-configurations before running a full crawl.
 *
 * Examples:
 *   php artisan datahelm:scrap:validate storage/app/blueprints/megaleiloes.com.br.json
 *   php artisan datahelm:scrap:validate /absolute/path/to/blueprint.json
 *   cat blueprint.json | php artisan datahelm:scrap:validate -   (read from STDIN)
 */
class ValidateBlueprintCommand extends Command
{
    use UsesCrawlerPrefix;

    protected $signature = 'datahelm:scrap:validate
        {blueprint : Path to the blueprint JSON file (or - to read from STDIN)}
        {--strict : Treat warnings as errors (exit 1 when warnings exist)}';

    protected $description = 'Validate a scrape blueprint JSON file for correctness';

    public function handle(BlueprintValidator $validator): int
    {
        $source = (string) $this->argument('blueprint');

        // Read blueprint JSON.
        if ($source === '-') {
            $json = stream_get_contents(STDIN);
        } elseif (str_starts_with($source, '/') || str_starts_with($source, './')) {
            $path = $source;
            $json = is_file($path) ? file_get_contents($path) : false;
        } else {
            // Relative to project root (storage/app/blueprints/...).
            $path = base_path($source);
            if (! is_file($path)) {
                $path = storage_path($source);
            }
            $json = is_file($path) ? file_get_contents($path) : false;
        }

        if ($json === false || $json === '') {
            $this->error("Cannot read blueprint from: {$source}");

            return self::FAILURE;
        }

        // Parse.
        try {
            $blueprint = ScrapeBlueprint::fromJson((string) $json);
        } catch (\Throwable $e) {
            $this->error('Blueprint JSON is invalid: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Validate.
        $passed = $validator->validate($blueprint);

        // Report warnings.
        foreach ($validator->warnings() as $warning) {
            $this->line("<fg=yellow>⚠</>  {$warning}");
        }

        // Report errors.
        foreach ($validator->errors() as $error) {
            $this->line("<fg=red>✗</>  {$error}");
        }

        $errorCount   = count($validator->errors());
        $warningCount = count($validator->warnings());

        if ($passed && $warningCount === 0) {
            $this->line('<fg=green>✓</>  Blueprint is valid — no issues found.');

            return self::SUCCESS;
        }

        if ($passed) {
            $this->line(sprintf('<fg=green>✓</>  Blueprint is valid with %d warning(s).', $warningCount));

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->line(sprintf(
            '<fg=red>✗</>  Blueprint has %d error(s) and %d warning(s).',
            $errorCount,
            $warningCount,
        ));

        return self::FAILURE;
    }
}
