<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Dom\Page;

/**
 * Locates the primary content region of a page so list detection ignores
 * global chrome (header nav, footer links, sidebars).
 */
final class MainContentScope
{
    /** @var list<string> */
    private const ROOT_SELECTORS = [
        'main',
        '[role="main"]',
        '#main',
        '#content',
        '#primary',
        '.main-content',
        '.content-main',
        '.page-content',
    ];

    /** @var list<string> */
    private const CHROME_TAGS = ['nav', 'header', 'footer', 'aside'];

    public static function locate(Page $page): ?\DOMElement
    {
        $crawler = $page->crawler();

        foreach (self::ROOT_SELECTORS as $selector) {
            try {
                $nodes = $crawler->filter($selector);
            } catch (\Throwable) {
                continue;
            }

            if ($nodes->count() === 0) {
                continue;
            }

            $best = self::bestCandidate($nodes);
            if ($best !== null) {
                return $best;
            }
        }

        return self::largestContentBlock($page);
    }

    private static function bestCandidate(\Symfony\Component\DomCrawler\Crawler $nodes): ?\DOMElement
    {
        $best     = null;
        $bestScore = 0;

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement || self::insideChrome($node)) {
                continue;
            }

            $score = self::contentScore($node);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $node;
            }
        }

        return $bestScore >= 20 ? $best : null;
    }

    private static function largestContentBlock(Page $page): ?\DOMElement
    {
        $best      = null;
        $bestScore = 0;

        foreach ($page->document()->getElementsByTagName('*') as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            if (! in_array(strtolower($node->tagName), ['div', 'section', 'article'], true)) {
                continue;
            }

            if (self::insideChrome($node)) {
                continue;
            }

            $score = self::contentScore($node);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $node;
            }
        }

        return $bestScore >= 40 ? $best : null;
    }

    private static function contentScore(\DOMElement $element): int
    {
        $links = $element->getElementsByTagName('a')->length;
        $text  = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');

        return ($links * 10) + min(strlen($text), 500);
    }

    private static function insideChrome(\DOMElement $element): bool
    {
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, self::CHROME_TAGS, true)) {
                return true;
            }

            $role = strtolower($node->getAttribute('role'));
            if (in_array($role, ['navigation', 'banner', 'contentinfo', 'complementary'], true)) {
                return true;
            }
        }

        return false;
    }
}
