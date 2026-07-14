<?php

/**
 * Test bootstrap for the DataHelm Crawler package.
 *
 * The package's classes are pure PHP (Symfony DomCrawler + Guzzle, no Laravel),
 * so the suite runs standalone. It borrows PHPUnit and the runtime dependencies
 * from a sibling vendor/ directory (this dev checkout has no vendor/ of its own —
 * it is symlinked into the host app's vendor), then registers PSR-4 autoloading
 * for the package src and the tests.
 *
 * Point DATAHELM_VENDOR at a directory containing an installed autoload.php if
 * the default lookup doesn't find one.
 */

$packageRoot = dirname(__DIR__);

$vendorCandidates = array_filter([
    getenv('DATAHELM_VENDOR') ?: null,
    $packageRoot . '/vendor/autoload.php',
    // Sibling standalone package checkout ships an installed vendor/ with PHPUnit.
    dirname($packageRoot, 4) . '/crawler/vendor/autoload.php',
]);

$vendorAutoload = null;
foreach ($vendorCandidates as $candidate) {
    if (is_file($candidate)) {
        $vendorAutoload = $candidate;
        break;
    }
}

if ($vendorAutoload === null) {
    fwrite(STDERR, "Could not locate a vendor/autoload.php with PHPUnit + symfony/dom-crawler.\n"
        . "Set DATAHELM_VENDOR to an installed vendor directory.\n");
    exit(1);
}

require $vendorAutoload;

// Package src + tests take precedence so we always exercise THIS checkout's code,
// not whatever DataHelm\Crawler classes the borrowed vendor may also autoload.
spl_autoload_register(static function (string $class) use ($packageRoot): void {
    foreach ([
        'DataHelm\\Crawler\\Tests\\' => $packageRoot . '/tests/',
        'DataHelm\\Crawler\\'        => $packageRoot . '/src/',
    ] as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, prepend: true);
