<?php

namespace DataHelm\Crawler\Tests\Detection;

use DataHelm\Crawler\Detection\MainContentScope;
use DataHelm\Crawler\Dom\Page;
use PHPUnit\Framework\TestCase;

/**
 * MainContentScope backs the --main-content flag: it must pick a confident
 * content region in a fixed priority order and hand back null (the caller's
 * signal to fall back to <body>) whenever nothing scores highly enough or
 * every candidate lives inside page chrome.
 *
 * The priority order is conditional on confidence: locate() takes the first
 * ROOT_SELECTORS match that clears bestCandidate()'s floor, and falls through
 * to the next selector when it doesn't — both halves are covered below.
 */
final class MainContentScopeTest extends TestCase
{
    private function page(string $html): Page
    {
        return Page::fromHtml('https://example.com/page', $html);
    }

    public function test_locates_a_main_element_when_present(): void
    {
        $page = $this->page(<<<'HTML'
            <html><body>
                <nav><a href="/">Home</a> <a href="/shop">Shop</a></nav>
                <main>
                    <h1>Article title</h1>
                    <p>Enough prose here for the content score to clear the threshold easily.</p>
                    <a href="/related-a">Related A</a>
                    <a href="/related-b">Related B</a>
                </main>
            </body></html>
            HTML);

        $found = MainContentScope::locate($page);

        $this->assertNotNull($found);
        $this->assertSame('main', $found->tagName);
    }

    public function test_falls_back_to_role_main_when_no_main_tag_exists(): void
    {
        $page = $this->page(<<<'HTML'
            <html><body>
                <header><a href="/">Home</a></header>
                <div role="main" id="app-root">
                    <h1>Dashboard</h1>
                    <p>Plenty of descriptive text so this block clears the content-score threshold.</p>
                    <a href="/one">One</a> <a href="/two">Two</a>
                </div>
            </body></html>
            HTML);

        $found = MainContentScope::locate($page);

        $this->assertNotNull($found);
        $this->assertSame('app-root', $found->getAttribute('id'));
    }

    public function test_falls_back_to_content_id_when_main_and_role_main_are_both_absent(): void
    {
        $page = $this->page(<<<'HTML'
            <html><body>
                <div id="content">
                    <h1>Wikipedia-style article</h1>
                    <p>A long enough paragraph of body copy to score well above the detection floor.</p>
                    <a href="/cite-1">Citation one</a> <a href="/cite-2">Citation two</a>
                </div>
                <div id="sidebar"><a href="/related">Related topic</a></div>
            </body></html>
            HTML);

        $found = MainContentScope::locate($page);

        $this->assertNotNull($found);
        $this->assertSame('content', $found->getAttribute('id'));
    }

    public function test_prefers_main_over_a_higher_scoring_role_main_candidate(): void
    {
        // Root selectors are tried in priority order, so a <main> that already
        // clears the confidence floor wins outright — locate() never even looks
        // at [role="main"]. The fixture is deliberately lopsided: <main> scores
        // 42 while the role="main" block scores 320, so this only passes if the
        // ROOT_SELECTORS order is honoured rather than the raw content score.
        $page = $this->page(<<<'HTML'
            <html><body>
                <main id="primary">
                    <p>Short but scoring main region.</p>
                    <a href="/a">A</a>
                </main>
                <div role="main" id="secondary">
                    <p>A far longer secondary block whose raw content score is much higher
                    than the primary region above, with many more links, so that it would
                    win outright if the priority order were not being honoured.</p>
                    <a href="/c">C</a> <a href="/d">D</a> <a href="/e">E</a> <a href="/f">F</a>
                    <a href="/g">G</a> <a href="/h">H</a> <a href="/i">I</a> <a href="/j">J</a>
                </div>
            </body></html>
            HTML);

        $found = MainContentScope::locate($page);

        $this->assertNotNull($found);
        $this->assertSame('primary', $found->getAttribute('id'));
    }

    public function test_skips_a_root_selector_that_scores_below_the_confidence_floor(): void
    {
        // The priority order is conditional on confidence, not absolute: when the
        // higher-priority <main> scores under bestCandidate()'s floor, locate()
        // falls through to the next selector instead of returning a weak match.
        $page = $this->page(<<<'HTML'
            <html><body>
                <main id="primary">.</main>
                <div role="main" id="secondary">
                    <p>A confident content region with enough copy and links to clear the
                    detection floor comfortably on its own.</p>
                    <a href="/c">C</a> <a href="/d">D</a>
                </div>
            </body></html>
            HTML);

        $found = MainContentScope::locate($page);

        $this->assertNotNull($found);
        $this->assertSame('secondary', $found->getAttribute('id'));
    }

    public function test_ignores_a_root_selector_candidate_sitting_inside_page_chrome(): void
    {
        // A `#content` block nested inside <nav> doesn't count as the page's main
        // content, no matter how it scores — chrome is always excluded.
        $page = $this->page(<<<'HTML'
            <html><body>
                <nav>
                    <div id="content">
                        <p>This lives inside nav so it must never be treated as the main region.</p>
                        <a href="/x">X</a> <a href="/y">Y</a> <a href="/z">Z</a>
                    </div>
                </nav>
                <div class="page-body">
                    <p>The real article text, long enough on its own to win by elimination here.</p>
                    <a href="/real-1">Real one</a> <a href="/real-2">Real two</a>
                </div>
            </body></html>
            HTML);

        $found = MainContentScope::locate($page);

        $this->assertNotNull($found);
        $this->assertNotSame('content', $found->getAttribute('id'));
    }

    public function test_falls_back_to_the_largest_content_block_when_no_root_selector_matches(): void
    {
        // No <main>, [role=main], #main, #content, #primary or *-content class —
        // detection must still find the biggest plain <section>/<article>/<div>.
        $page = $this->page(<<<'HTML'
            <html><body>
                <header><a href="/">Home</a></header>
                <article class="post">
                    <h1>Untitled post</h1>
                    <p>A full paragraph of article body text, long enough by itself to clear the
                    larger threshold that applies once none of the named root selectors match.</p>
                    <a href="/tag-1">Tag one</a> <a href="/tag-2">Tag two</a> <a href="/tag-3">Tag three</a>
                </article>
                <footer><a href="/terms">Terms</a></footer>
            </body></html>
            HTML);

        $found = MainContentScope::locate($page);

        $this->assertNotNull($found);
        $this->assertSame('article', $found->tagName);
    }

    public function test_returns_null_when_nothing_scores_as_a_confident_content_region(): void
    {
        // Only page chrome and a couple of bare links — nothing here should ever
        // be confidently promoted to "main content"; callers fall back to <body>.
        $page = $this->page(<<<'HTML'
            <html><body>
                <nav><a href="/">Home</a> <a href="/about">About</a></nav>
                <footer><a href="/privacy">Privacy</a></footer>
            </body></html>
            HTML);

        $this->assertNull(MainContentScope::locate($page));
    }
}
