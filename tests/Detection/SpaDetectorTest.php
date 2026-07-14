<?php

namespace DataHelm\Crawler\Tests\Detection;

use DataHelm\Crawler\Detection\SpaDetector;
use PHPUnit\Framework\TestCase;

/**
 * SpaDetector decides whether a page is JavaScript-rendered (so the generator
 * should render it and/or look for an API). These cases cover the framework
 * signatures and the "empty loading shell" rule that let quotes.toscrape/scroll
 * and Gatsby/Next sites be recognised.
 */
final class SpaDetectorTest extends TestCase
{
    private SpaDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new SpaDetector();
    }

    public function test_isSpa_true_on_a_known_spa_marker(): void
    {
        $this->assertTrue($this->detector->isSpa('<html><body><div id="root"></div></body></html>', 0));
        $this->assertTrue($this->detector->isSpa('<html><script>window.__NUXT__={}</script></html>', 5));
    }

    public function test_isSpa_true_for_script_heavy_page_with_little_text(): void
    {
        $html = '<html><body>' . str_repeat('<script src="a.js"></script>', 5) . 'x</body></html>';
        $this->assertTrue($this->detector->isSpa($html, 1));
    }

    public function test_isSpa_false_for_a_normal_static_page(): void
    {
        $html = '<html><body><h1>Hello</h1><p>Lots of real content here.</p></body></html>';
        $this->assertFalse($this->detector->isSpa($html, 5000));
    }

    public function test_looksJsRendered_true_on_framework_build_markers(): void
    {
        // Gatsby / Next.js build artefacts with plenty of static chrome text —
        // isSpa() alone would miss these, looksJsRendered() must catch them.
        $this->assertTrue($this->detector->looksJsRendered('<script src="/_next/static/chunk.js"></script>', 4000));
        $this->assertTrue($this->detector->looksJsRendered('<script>self.__next_f.push([1,"x"])</script>', 4000));
        $this->assertTrue($this->detector->looksJsRendered('<div>/webpack-runtime-abc.js ___gatsby</div>', 4000));
    }

    public function test_looksJsRendered_true_for_an_empty_loading_shell(): void
    {
        // quotes.toscrape.com/scroll: a near-empty body with a "Loading…" note.
        $this->assertTrue($this->detector->looksJsRendered('<body>Loading…</body>', 95));
    }

    public function test_looksJsRendered_true_on_a_loading_spinner_marker(): void
    {
        $html = '<div class="lucide lucide-loader-circle animate-spin"></div>';
        $this->assertTrue($this->detector->looksJsRendered($html, 4000));
    }

    public function test_looksJsRendered_false_for_a_content_rich_static_page(): void
    {
        $html = '<html><body><main><article>' . str_repeat('Real words. ', 200) . '</article></main></body></html>';
        $this->assertFalse($this->detector->looksJsRendered($html, 5000));
    }

    public function test_candidate_endpoints_are_made_absolute_and_asset_urls_dropped(): void
    {
        $html = <<<'HTML'
        <script>
          fetch("/api/v1/search/results?q=1");
          var img = "/static/logo.png";
        </script>
        HTML;

        $found = $this->detector->candidateEndpoints('https://shop.test/listing', $html);

        $this->assertContains('https://shop.test/api/v1/search/results?q=1', $found);
        $this->assertNotContains('https://shop.test/static/logo.png', $found, 'Static assets must be filtered out');
    }
}
