<?php

namespace DataHelm\Crawler\Tests\Markdown;

use DataHelm\Crawler\Markdown\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

final class HtmlToMarkdownTest extends TestCase
{
    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', (new HtmlToMarkdown())->convert(''));
        $this->assertSame('', (new HtmlToMarkdown())->convert('   '));
    }

    public function test_headings(): void
    {
        $md = (new HtmlToMarkdown())->convert('<h1>Title</h1><h3>Sub</h3>');

        $this->assertSame("# Title\n\n### Sub", $md);
    }

    public function test_paragraphs_are_separated_by_blank_line(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p>First</p><p>Second</p>');

        $this->assertSame("First\n\nSecond", $md);
    }

    public function test_bare_inline_text_without_block_wrapper(): void
    {
        $md = (new HtmlToMarkdown())->convert('Just <strong>bold</strong> text');

        $this->assertSame('Just **bold** text', $md);
    }

    public function test_bold_and_italic(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p><strong>bold</strong> and <em>italic</em></p>');

        $this->assertSame('**bold** and *italic*', $md);
    }

    public function test_emphasis_keeps_edge_whitespace_outside_markers(): void
    {
        // "<strong> bold </strong>" must not render as "** bold **" (most parsers ignore that).
        $md = (new HtmlToMarkdown())->convert('<p>a<strong> bold </strong>b</p>');

        $this->assertSame('a **bold** b', $md);
    }

    public function test_line_break_becomes_newline(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p>line one<br>line two</p>');

        $this->assertSame("line one\nline two", $md);
    }

    public function test_link_with_href(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p><a href="https://example.com">Example</a></p>');

        $this->assertSame('[Example](https://example.com)', $md);
    }

    public function test_link_without_href_renders_as_plain_text(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p><a href="#">Anchor</a> and <a>No href</a></p>');

        $this->assertSame('Anchor and No href', $md);
    }

    public function test_link_without_text_falls_back_to_href(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p><a href="https://example.com"></a></p>');

        $this->assertSame('[https://example.com](https://example.com)', $md);
    }

    public function test_image(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p><img src="https://example.com/a.jpg" alt="A cat"></p>');

        $this->assertSame('![A cat](https://example.com/a.jpg)', $md);
    }

    public function test_link_and_image_are_resolved_against_base_url(): void
    {
        $html = '<p><a href="/wiki/Foo">Foo</a> <img src="//cdn.example.com/a.jpg" alt="A"></p>';

        $md = (new HtmlToMarkdown())->convert($html, 'https://en.wikipedia.org/wiki/Bar');

        $this->assertSame(
            '[Foo](https://en.wikipedia.org/wiki/Foo) ![A](https://cdn.example.com/a.jpg)',
            $md,
        );
    }

    public function test_already_absolute_link_is_left_untouched_with_base_url(): void
    {
        $md = (new HtmlToMarkdown())->convert(
            '<p><a href="https://other.com/x">X</a></p>',
            'https://example.com/page',
        );

        $this->assertSame('[X](https://other.com/x)', $md);
    }

    public function test_relative_link_is_left_as_is_without_base_url(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p><a href="/wiki/Foo">Foo</a></p>');

        $this->assertSame('[Foo](/wiki/Foo)', $md);
    }

    public function test_image_without_src_is_dropped(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p>before<img alt="no src">after</p>');

        $this->assertSame('beforeafter', $md);
    }

    public function test_unordered_list(): void
    {
        $md = (new HtmlToMarkdown())->convert('<ul><li>One</li><li>Two</li></ul>');

        $this->assertSame("- One\n- Two", $md);
    }

    public function test_ordered_list(): void
    {
        $md = (new HtmlToMarkdown())->convert('<ol><li>One</li><li>Two</li></ol>');

        $this->assertSame("1. One\n2. Two", $md);
    }

    public function test_nested_list_is_indented(): void
    {
        $md = (new HtmlToMarkdown())->convert(
            '<ul><li>Parent<ul><li>Child A</li><li>Child B</li></ul></li></ul>',
        );

        $this->assertSame("- Parent\n  - Child A\n  - Child B", $md);
    }

    public function test_nested_ordered_list_pads_to_marker_width(): void
    {
        $md = (new HtmlToMarkdown())->convert(
            '<ol><li>Parent<ol><li>Child A</li><li>Child B</li></ol></li></ol>',
        );

        $this->assertSame("1. Parent\n   1. Child A\n   2. Child B", $md);
    }

    public function test_blockquote(): void
    {
        $md = (new HtmlToMarkdown())->convert('<blockquote><p>Quoted line</p></blockquote>');

        $this->assertSame('> Quoted line', $md);
    }

    public function test_inline_code(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p>Run <code>composer install</code> first</p>');

        $this->assertSame('Run `composer install` first', $md);
    }

    public function test_fenced_code_block_with_language(): void
    {
        $html = '<pre><code class="language-php">echo "hi";</code></pre>';
        $md = (new HtmlToMarkdown())->convert($html);

        $this->assertSame("```php\necho \"hi\";\n```", $md);
    }

    public function test_fenced_code_block_without_language(): void
    {
        $md = (new HtmlToMarkdown())->convert('<pre><code>plain</code></pre>');

        $this->assertSame("```\nplain\n```", $md);
    }

    public function test_table(): void
    {
        $html = '<table>'
            . '<tr><th>Name</th><th>Price</th></tr>'
            . '<tr><td>Widget</td><td>10</td></tr>'
            . '<tr><td>Gadget</td><td>20</td></tr>'
            . '</table>';

        $md = (new HtmlToMarkdown())->convert($html);

        $expected = "| Name | Price |\n"
            . "| --- | --- |\n"
            . "| Widget | 10 |\n"
            . "| Gadget | 20 |";

        $this->assertSame($expected, $md);
    }

    public function test_table_cell_pipe_is_escaped(): void
    {
        $html = '<table><tr><th>Name</th></tr><tr><td>A | B</td></tr></table>';

        $md = (new HtmlToMarkdown())->convert($html);

        $this->assertStringContainsString('A \\| B', $md);
    }

    public function test_script_and_style_are_always_stripped(): void
    {
        $html = '<p>Visible</p><script>alert(1)</script><style>.a{color:red}</style>';

        $md = (new HtmlToMarkdown())->convert($html);

        $this->assertSame('Visible', $md);
    }

    public function test_site_chrome_is_stripped_by_default(): void
    {
        $html = '<nav>Home | About</nav><header>Site Header</header>'
            . '<article><p>Main content</p></article>'
            . '<footer>Copyright</footer><aside>Sidebar</aside>';

        $md = (new HtmlToMarkdown())->convert($html);

        $this->assertSame('Main content', $md);
    }

    public function test_site_chrome_is_kept_when_disabled(): void
    {
        $html = '<header>Site Header</header><p>Body</p>';

        $md = (new HtmlToMarkdown(stripChrome: false))->convert($html);

        $this->assertStringContainsString('Site Header', $md);
        $this->assertStringContainsString('Body', $md);
    }

    public function test_excessive_blank_lines_collapse_to_one(): void
    {
        $md = (new HtmlToMarkdown())->convert('<p>A</p><div></div><div></div><p>B</p>');

        $this->assertSame("A\n\nB", $md);
    }

    public function test_convert_element_from_a_live_dom_node(): void
    {
        $dom = new \DOMDocument();
        $dom->loadHTML('<body><div id="target"><h2>Heading</h2><p>Body text</p></div></body>');
        $node = $dom->getElementById('target');

        $this->assertNotNull($node);
        $md = (new HtmlToMarkdown())->convertElement($node);

        $this->assertSame("## Heading\n\nBody text", $md);
    }
}
