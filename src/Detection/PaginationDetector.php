<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\PaginationSelector;
use DataHelm\Crawler\Blueprint\PaginationStrategy;
use DataHelm\Crawler\Dom\Page;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Recognises common pagination shapes and reports how to traverse them.
 */
final class PaginationDetector
{
    private const LINK_LIST_CONTAINERS = [
        'ul.pagination',
        '.pagination',
        '.paginacao',
        '.paginator',
        'nav .page-numbers',
    ];

    private const NEXT_LINK_SELECTORS = [
        'a[rel="next"]',
        'a.next',
        'li.next a',
        '.next > a',
        '.pager .next a',
        '.pagination .next a',
    ];

    private const INFINITE_SCROLL_SELECTORS = [
        '#btn_carregarMais',
        '[id*="carregarMais"]',
        '[id*="carregar-mais"]',
        'button.load-more',
        'a.load-more',
        '.load-more',
        '[data-load-more]',
        'button[id*="loadMore"]',
    ];

    public function detect(Page $page): PaginationSelector
    {
        $crawler = $page->crawler();

        foreach (self::LINK_LIST_CONTAINERS as $container) {
            if ($this->hasLinks($crawler, $container)) {
                return new PaginationSelector(PaginationStrategy::LINK_LIST, $container . ' a');
            }
        }

        foreach (self::NEXT_LINK_SELECTORS as $selector) {
            if ($this->exists($crawler, $selector)) {
                return new PaginationSelector(PaginationStrategy::NEXT_LINK, $selector);
            }
        }

        // Infinite scroll: the button itself is recorded; the request details
        // (endpoint, params, token) go in the infinite_scroll block, which the
        // generator scaffolds for the user to complete.
        foreach (self::INFINITE_SCROLL_SELECTORS as $selector) {
            if ($this->exists($crawler, $selector)) {
                return new PaginationSelector(PaginationStrategy::INFINITE_SCROLL, $selector);
            }
        }

        return PaginationSelector::none();
    }

    private function hasLinks(Crawler $crawler, string $selector): bool
    {
        try {
            $node = $crawler->filter($selector);

            return $node->count() > 0 && $node->filter('a')->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function exists(Crawler $crawler, string $selector): bool
    {
        try {
            return $crawler->filter($selector)->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
