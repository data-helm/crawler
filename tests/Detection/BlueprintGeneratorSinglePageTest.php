<?php

namespace DataHelm\Crawler\Tests\Detection;

use DataHelm\Crawler\Blueprint\PaginationStrategy;
use DataHelm\Crawler\Detection\AddressFieldDetector;
use DataHelm\Crawler\Detection\BlueprintGenerator;
use DataHelm\Crawler\Detection\DescriptionFieldDetector;
use DataHelm\Crawler\Detection\ImageFieldDetector;
use DataHelm\Crawler\Detection\LinkFieldDetector;
use DataHelm\Crawler\Detection\ListCandidateValidator;
use DataHelm\Crawler\Detection\ListDetector;
use DataHelm\Crawler\Detection\PaginationDetector;
use DataHelm\Crawler\Detection\PriceFieldDetector;
use DataHelm\Crawler\Detection\RatingFieldDetector;
use DataHelm\Crawler\Detection\TitleFieldDetector;
use DataHelm\Crawler\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class BlueprintGeneratorSinglePageTest extends TestCase
{
    private const ARTICLE_HTML = <<<'HTML'
        <html>
        <body>
            <nav><a href="/">Home</a> <a href="/about">About</a></nav>
            <article>
                <h1 class="title">How to scrape a single page</h1>
                <img class="hero-image" src="/img/hero.jpg" alt="hero">
                <p class="description">A practical guide to scraping one-off pages instead of listings, covering selectors and field detection.</p>
            </article>
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

    public function test_single_page_produces_body_item_selector_with_pagination_disabled(): void
    {
        $blueprint = $this->generator(self::ARTICLE_HTML)->generate(
            url: 'https://example.com/articles/one-off',
            singlePage: true,
        );

        $this->assertSame('body', $blueprint->itemSelector);
        $this->assertSame(PaginationStrategy::NONE, $blueprint->pagination->strategy);
    }

    public function test_single_page_detects_fields_from_the_whole_body(): void
    {
        $blueprint = $this->generator(self::ARTICLE_HTML)->generate(
            url: 'https://example.com/articles/one-off',
            singlePage: true,
        );

        $names = array_map(static fn ($f) => $f->name, $blueprint->fields);

        $this->assertContains('title', $names);
        $this->assertContains('description', $names);
    }

    public function test_single_page_skips_list_detection_even_when_a_repeating_list_exists(): void
    {
        // A page that ALSO has a real repeating list — single-page mode must not
        // fall into list detection regardless; it always treats body as one item.
        $html = <<<'HTML'
            <html><body>
                <h1 class="title">Company profile</h1>
                <ul class="related">
                    <li><a href="/a" class="title">Related A</a></li>
                    <li><a href="/b" class="title">Related B</a></li>
                    <li><a href="/c" class="title">Related C</a></li>
                </ul>
            </body></html>
            HTML;

        $blueprint = $this->generator($html)->generate(
            url: 'https://example.com/company/acme',
            singlePage: true,
        );

        $this->assertSame('body', $blueprint->itemSelector);
    }

    public function test_no_list_detected_without_single_page_suggests_the_flag(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--single-page');

        $this->generator(self::ARTICLE_HTML)->generate(
            url: 'https://example.com/articles/one-off',
        );
    }

    public function test_main_content_scopes_the_item_selector_to_the_content_region(): void
    {
        $html = <<<'HTML'
            <html><body>
                <nav><a href="/">Home</a> <a href="/shop">Shop</a> <a href="/blog">Blog</a></nav>
                <main>
                    <h1 class="title">Scoped article</h1>
                    <p class="description">Long enough prose for the description detector to accept it as a real paragraph of content.</p>
                    <a href="/articles/scoped-article" class="permalink">Permalink</a>
                </main>
                <footer><a href="/privacy">Privacy</a></footer>
            </body></html>
            HTML;

        $blueprint = $this->generator($html)->generate(
            url: 'https://example.com/articles/scoped',
            singlePage: true,
            mainContent: true,
        );

        $this->assertSame('main', $blueprint->itemSelector);
    }

    public function test_main_content_falls_back_to_body_when_the_region_selector_is_not_unique(): void
    {
        // Two div.main-content blocks: the located region's selector matches both,
        // which would split one page into two items — must fall back to body.
        $html = <<<'HTML'
            <html><body>
                <div class="main-content">
                    <h1 class="title">First block with plenty of text to score as content region here</h1>
                    <a href="/one">One</a> <a href="/two">Two</a> <a href="/three">Three</a>
                </div>
                <div class="main-content">
                    <h2>Second block, same class, also long enough to be considered for scoring</h2>
                    <a href="/four">Four</a> <a href="/five">Five</a>
                </div>
            </body></html>
            HTML;

        $blueprint = $this->generator($html)->generate(
            url: 'https://example.com/page',
            singlePage: true,
            mainContent: true,
        );

        $this->assertSame('body', $blueprint->itemSelector);
    }
}
