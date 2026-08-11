<?php

namespace DataHelm\Crawler\Tests\Detection;

use DataHelm\Crawler\Detection\AddressFieldDetector;
use DataHelm\Crawler\Detection\BlueprintGenerator;
use DataHelm\Crawler\Detection\DescriptionFieldDetector;
use DataHelm\Crawler\Detection\ImageFieldDetector;
use DataHelm\Crawler\Detection\JavaScriptRenderedException;
use DataHelm\Crawler\Detection\LinkFieldDetector;
use DataHelm\Crawler\Detection\ListCandidateValidator;
use DataHelm\Crawler\Detection\ListDetector;
use DataHelm\Crawler\Detection\PaginationDetector;
use DataHelm\Crawler\Detection\PriceFieldDetector;
use DataHelm\Crawler\Detection\RatingFieldDetector;
use DataHelm\Crawler\Detection\TitleFieldDetector;
use DataHelm\Crawler\Http\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a real-world case: a page with NO repeating list at
 * all (list detection returns null from the very first attempt, so the
 * "junk nav list found" flag is never set) that is nonetheless a JS SPA with
 * discoverable API endpoint candidates. Generation must surface those
 * candidates via JavaScriptRenderedException, not a bare
 * "could not detect a list" RuntimeException.
 */
final class BlueprintGeneratorSpaFallbackTest extends TestCase
{
    // No repeating list anywhere (no <li>/<article>/... siblings), an SPA marker
    // (id="app"), and one fetch() call matching SpaDetector's endpoint pattern.
    private const SPA_SHELL_HTML = <<<'HTML'
        <html>
        <body>
            <div id="app"></div>
            <script>
                fetch("https://example.com/api/search-results").then(r => r.json());
            </script>
        </body>
        </html>
        HTML;

    private function generator(string $html): BlueprintGenerator
    {
        $http = new class ($html) implements HttpClient {
            public function __construct(private readonly string $html)
            {
            }

            public function get(string $url): string
            {
                return $this->html;
            }
        };

        $detectors = [
            new LinkFieldDetector(),
            new TitleFieldDetector(),
            new ImageFieldDetector(),
            new PriceFieldDetector(),
            new RatingFieldDetector(),
            new AddressFieldDetector(),
            new DescriptionFieldDetector(),
        ];

        return new BlueprintGenerator(
            $http,
            new ListDetector($detectors, new ListCandidateValidator($detectors)),
            new PaginationDetector(),
            $detectors,
        );
    }

    public function test_no_list_found_at_all_but_spa_markers_present_surfaces_candidates(): void
    {
        $generator = $this->generator(self::SPA_SHELL_HTML);

        try {
            $generator->generate(url: 'https://example.com/listing');
            $this->fail('Expected a JavaScriptRenderedException.');
        } catch (JavaScriptRenderedException $e) {
            $this->assertStringContainsString('api/search-results', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->fail(
                'Fell back to the generic "could not detect a list" error instead of '
                . "surfacing the discovered endpoint candidate. Message was:\n" . $e->getMessage(),
            );
        }
    }

    public function test_discovered_endpoints_are_exposed_even_without_a_junk_list(): void
    {
        $generator = $this->generator(self::SPA_SHELL_HTML);

        try {
            $generator->generate(url: 'https://example.com/listing');
        } catch (\Throwable) {
            // Only the discoveredEndpoints() side effect matters here.
        }

        $this->assertNotSame([], $generator->discoveredEndpoints());
    }
}
