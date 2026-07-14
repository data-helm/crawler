<?php

namespace DataHelm\Crawler\Markdown;

use DataHelm\Crawler\Dom\Url;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Converts an HTML fragment (or a live DOM node) into clean, LLM-ready Markdown.
 *
 * This is the piece Firecrawl and Crawl4AI are known for: point at a page and
 * get back readable Markdown instead of a wall of tags. It powers two entry
 * points in this package:
 *
 *   - A field of type "markdown" (see {@see \DataHelm\Crawler\Blueprint\FieldSelector}),
 *     which renders the matched element's content as Markdown — ideal for an
 *     article body, product description, or any long-form block.
 *   - The "markdown" output format (see {@see \DataHelm\Crawler\Output\MarkdownExporter}),
 *     which renders whole scraped items as a Markdown document.
 *
 * Dependency-free by design: it needs only ext-dom (bundled with PHP), so it can
 * be used standalone without pulling in a Markdown library. Non-content nodes
 * (script, style, nav chrome, forms, media embeds) are dropped so the result is
 * suitable to feed straight into an LLM context window.
 */
final class HtmlToMarkdown
{
    /** Tags whose subtree carries no readable content and is dropped entirely. */
    private const SKIP = [
        'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'head',
        'form', 'button', 'input', 'textarea', 'select', 'option', 'label',
        'canvas', 'audio', 'video', 'map', 'object', 'embed', 'link', 'meta',
    ];

    /** Site chrome dropped when $stripChrome is on (the default). */
    private const CHROME = ['nav', 'header', 'footer', 'aside'];

    /** @var list<string> */
    private array $skip;

    /** Page URL used to resolve relative href/src during the current conversion. */
    private ?string $baseUrl = null;

    /**
     * @param bool $stripChrome Drop site chrome (nav/header/footer/aside) for cleaner,
     *                          article-only output. Turn off to keep every element.
     */
    public function __construct(bool $stripChrome = true)
    {
        $this->skip = $stripChrome
            ? array_merge(self::SKIP, self::CHROME)
            : self::SKIP;
    }

    /** Elements that start a new block (everything else is treated as inline). */
    private const BLOCK = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt',
        'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section',
        'table', 'tbody', 'tfoot', 'thead', 'tr', 'ul',
    ];

    /**
     * Convert an HTML fragment to Markdown.
     *
     * @param string|null $baseUrl When set, relative link/image URLs are resolved
     *                             against it so the output stays valid outside the
     *                             page it came from (e.g. dropped into an LLM/RAG doc).
     */
    public function convert(string $html, ?string $baseUrl = null): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The <meta charset> keeps DOMDocument from mangling multibyte text; the
        // <body> wrapper gives us a stable root to walk regardless of the fragment.
        $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>' . $html . '</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);

        return $body !== null ? $this->convertElement($body, $baseUrl) : '';
    }

    /**
     * Convert an existing DOM node (e.g. one matched by a CSS selector) to Markdown.
     *
     * @param string|null $baseUrl See {@see self::convert()}.
     */
    public function convertElement(DOMNode $node, ?string $baseUrl = null): string
    {
        $this->baseUrl = $baseUrl;

        return $this->finalize($this->renderChildren($node));
    }

    // -------------------------------------------------------------------------

    /**
     * Walk a node's children, grouping runs of inline content into paragraphs and
     * rendering block-level children on their own.
     */
    private function renderChildren(DOMNode $node): string
    {
        $blocks = [];
        $inline = '';

        foreach ($node->childNodes as $child) {
            if ($this->isBlock($child)) {
                if (trim($inline) !== '') {
                    $blocks[] = $this->collapse($inline);
                    $inline = '';
                }
                $block = $this->renderBlock($child);
                if (trim($block) !== '') {
                    $blocks[] = $block;
                }
            } else {
                $inline .= $this->renderInline($child);
            }
        }

        if (trim($inline) !== '') {
            $blocks[] = $this->collapse($inline);
        }

        return implode("\n\n", $blocks);
    }

    private function renderBlock(DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, $this->skip, true)) {
            return '';
        }

        return match ($tag) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' =>
                str_repeat('#', (int) $tag[1]) . ' ' . $this->collapse($this->renderInline($node)),
            'hr'         => '---',
            'pre'        => $this->renderPre($node),
            'blockquote' => $this->renderBlockquote($node),
            'ul', 'ol'   => $this->renderList($node),
            'table'      => $this->renderTable($node),
            default      => $this->renderChildren($node),
        };
    }

    private function renderInline(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            // Collapse literal whitespace but keep a single separating space.
            return (string) preg_replace('/\s+/u', ' ', (string) $node->nodeValue);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, $this->skip, true)) {
            return '';
        }

        return match ($tag) {
            'br'             => "\n",
            'strong', 'b'    => $this->emphasis($node, '**'),
            'em', 'i'        => $this->emphasis($node, '*'),
            'code'           => '`' . trim($this->textOf($node)) . '`',
            'a'              => $this->renderLink($node),
            'img'            => $this->renderImage($node),
            default          => $this->renderInlineChildren($node),
        };
    }

    private function renderInlineChildren(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->renderInline($child);
        }

        return $out;
    }

    /**
     * Wrap inline content in an emphasis marker, moving edge whitespace outside
     * the markers so we never emit "** bold **" (which most parsers ignore).
     */
    private function emphasis(DOMElement $node, string $marker): string
    {
        $inner = $this->renderInlineChildren($node);
        $trimmed = trim($inner);
        if ($trimmed === '') {
            return $inner === '' ? '' : ' ';
        }

        $lead  = preg_match('/^\s/u', $inner) ? ' ' : '';
        $trail = preg_match('/\s$/u', $inner) ? ' ' : '';

        return $lead . $marker . $trimmed . $marker . $trail;
    }

    private function renderLink(DOMElement $node): string
    {
        $text = trim($this->renderInlineChildren($node));
        $href = trim((string) $node->getAttribute('href'));

        if ($href === '' || $href === '#') {
            return $text;
        }
        if ($text === '') {
            $text = $href;
        }

        return '[' . $text . '](' . $this->resolveUrl($href) . ')';
    }

    private function renderImage(DOMElement $node): string
    {
        $src = trim((string) $node->getAttribute('src'));
        if ($src === '') {
            return '';
        }
        $alt = trim((string) $node->getAttribute('alt'));

        return '![' . $alt . '](' . $this->resolveUrl($src) . ')';
    }

    /** Resolve a relative href/src against the page URL, when one was given. */
    private function resolveUrl(string $url): string
    {
        return $this->baseUrl !== null ? (Url::absolute($this->baseUrl, $url) ?? $url) : $url;
    }

    private function renderPre(DOMElement $node): string
    {
        $code = $node->getElementsByTagName('code')->item(0);
        $language = '';
        if ($code !== null) {
            foreach (explode(' ', (string) $code->getAttribute('class')) as $class) {
                if (str_starts_with($class, 'language-')) {
                    $language = substr($class, strlen('language-'));
                    break;
                }
            }
        }

        $text = rtrim($this->textOf($node), "\n");

        return '```' . $language . "\n" . $text . "\n" . '```';
    }

    private function renderBlockquote(DOMElement $node): string
    {
        $inner = $this->renderChildren($node);
        $lines = explode("\n", $inner);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
            $lines,
        ));
    }

    private function renderList(DOMElement $list): string
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $index = 1;
        $lines = [];

        foreach ($list->childNodes as $li) {
            if (! $li instanceof DOMElement || strtolower($li->nodeName) !== 'li') {
                continue;
            }

            $marker = $ordered ? $index++ . '. ' : '- ';
            $content = $this->renderListItem($li);
            $itemLines = explode("\n", $content);
            $first = array_shift($itemLines) ?? '';
            $lines[] = $marker . $first;

            // Nested lines (continuation text or a nested sub-list) align under the
            // marker; a sub-list's own recursive call already indents itself by its
            // marker width, so this single pad is all that accumulates per depth.
            $pad = str_repeat(' ', strlen($marker));
            foreach ($itemLines as $line) {
                $lines[] = $line === '' ? '' : $pad . $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Render one list item: its own inline/block text, with nested lists indented
     * under it.
     */
    private function renderListItem(DOMElement $li): string
    {
        $parts = [];
        $inline = '';

        foreach ($li->childNodes as $child) {
            $tag = $child instanceof DOMElement ? strtolower($child->nodeName) : '';

            if ($tag === 'ul' || $tag === 'ol') {
                if (trim($inline) !== '') {
                    $parts[] = $this->collapse($inline);
                    $inline = '';
                }
                $parts[] = $this->renderList($child);
            } elseif ($this->isBlock($child)) {
                if (trim($inline) !== '') {
                    $parts[] = $this->collapse($inline);
                    $inline = '';
                }
                $block = $this->renderBlock($child);
                if (trim($block) !== '') {
                    $parts[] = $block;
                }
            } else {
                $inline .= $this->renderInline($child);
            }
        }

        if (trim($inline) !== '') {
            $parts[] = $this->collapse($inline);
        }

        return implode("\n", $parts);
    }

    private function renderTable(DOMElement $table): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '\\|', $this->collapse($this->renderInlineChildren($cell)));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $header = array_shift($rows);
        $columns = count($header);

        $out = '| ' . implode(' | ', $header) . ' |';
        $out .= "\n| " . implode(' | ', array_fill(0, $columns, '---')) . ' |';
        foreach ($rows as $row) {
            $row = array_pad(array_slice($row, 0, $columns), $columns, '');
            $out .= "\n| " . implode(' | ', $row) . ' |';
        }

        return $out;
    }

    private function textOf(DOMNode $node): string
    {
        return (string) $node->textContent;
    }

    private function isBlock(DOMNode $node): bool
    {
        return $node instanceof DOMElement && in_array(strtolower($node->nodeName), self::BLOCK, true);
    }

    /** Trim and collapse internal whitespace of an inline run into one line. */
    private function collapse(string $text): string
    {
        // Preserve explicit <br> newlines; collapse only spaces/tabs runs.
        $text = (string) preg_replace('/[ \t\x{00a0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);

        return trim($text);
    }

    /** Normalise blank-line runs and trim the final document. */
    private function finalize(string $markdown): string
    {
        $markdown = (string) preg_replace("/\n{3,}/", "\n\n", $markdown);

        return trim($markdown);
    }
}
