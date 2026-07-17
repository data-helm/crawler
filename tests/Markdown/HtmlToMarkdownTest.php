<?php

namespace DataHelm\Crawler\Tests\Markdown;

use DataHelm\Crawler\Markdown\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * The HTML→Markdown converter is a headline feature (LLM-ready output); these
 * lock down headings, emphasis, links, lists, code, tables, and chrome removal.
 */
final class HtmlToMarkdownTest extends TestCase
{
    private HtmlToMarkdown $md;

    protected function setUp(): void
    {
        $this->md = new HtmlToMarkdown();
    }

    public function test_headings_and_paragraphs(): void
    {
        $out = $this->md->convert('<h1>Title</h1><p>Hello <strong>world</strong>.</p>');

        $this->assertStringContainsString('# Title', $out);
        $this->assertStringContainsString('Hello **world**.', $out);
    }

    public function test_links_and_images_resolve_against_base(): void
    {
        $out = $this->md->convert('<a href="/about">About</a> <img src="/p.jpg" alt="Pic">', 'https://a.com/');

        $this->assertStringContainsString('[About](https://a.com/about)', $out);
        $this->assertStringContainsString('![Pic](https://a.com/p.jpg)', $out);
    }

    public function test_unordered_and_ordered_lists(): void
    {
        $ul = $this->md->convert('<ul><li>one</li><li>two</li></ul>');
        $this->assertStringContainsString('- one', $ul);
        $this->assertStringContainsString('- two', $ul);

        $ol = $this->md->convert('<ol><li>first</li><li>second</li></ol>');
        $this->assertStringContainsString('1. first', $ol);
        $this->assertStringContainsString('2. second', $ol);
    }

    public function test_inline_code_and_code_block_with_language(): void
    {
        $this->assertStringContainsString('`x`', $this->md->convert('<p><code>x</code></p>'));

        $block = $this->md->convert('<pre><code class="language-php">echo 1;</code></pre>');
        $this->assertStringContainsString('```php', $block);
        $this->assertStringContainsString('echo 1;', $block);
    }

    public function test_table_renders_with_header_separator(): void
    {
        $out = $this->md->convert('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>');

        $this->assertStringContainsString('| A | B |', $out);
        $this->assertStringContainsString('| --- | --- |', $out);
        $this->assertStringContainsString('| 1 | 2 |', $out);
    }

    public function test_scripts_styles_and_chrome_are_stripped(): void
    {
        $out = $this->md->convert(
            '<nav>menu</nav><script>evil()</script><style>.x{}</style>'
            . '<main><p>real content</p></main><footer>foot</footer>',
        );

        $this->assertStringContainsString('real content', $out);
        $this->assertStringNotContainsString('evil()', $out);
        $this->assertStringNotContainsString('.x{}', $out);
        $this->assertStringNotContainsString('menu', $out);
        $this->assertStringNotContainsString('foot', $out);
    }

    public function test_empty_input_yields_empty_string(): void
    {
        $this->assertSame('', $this->md->convert(''));
        $this->assertSame('', $this->md->convert('   '));
    }

    public function test_blockquote(): void
    {
        $out = $this->md->convert('<blockquote>quoted</blockquote>');
        $this->assertStringContainsString('> quoted', $out);
    }
}
