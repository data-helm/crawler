<?php

namespace DataHelm\Crawler\Scraping;

use DataHelm\Crawler\Blueprint\PaginationStrategy;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Dom\Page;
use DataHelm\Crawler\Dom\Url;
use DataHelm\Crawler\Http\HttpClient;
use DataHelm\Crawler\Scraping\PageCap;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Resolves the ordered list of page URLs to crawl, applying the blueprint's
 * pagination strategy and respecting its maxPages cap.
 */
final class Paginator
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @return list<string>
     */
    public function pages(ScrapeBlueprint $blueprint): array
    {
        return match ($blueprint->pagination->strategy) {
            PaginationStrategy::NONE      => [$blueprint->url],
            PaginationStrategy::LINK_LIST => $this->fromLinkList($blueprint),
            PaginationStrategy::NEXT_LINK => $this->followNext($blueprint),
            // INFINITE_SCROLL is driven entirely by CrawlEngine (it needs a scraped
            // token and POST fragments); only the first page is a plain GET.
            PaginationStrategy::INFINITE_SCROLL => [$blueprint->url],
        };
    }

    /**
     * @return list<string>
     */
    private function fromLinkList(ScrapeBlueprint $blueprint): array
    {
        $urls = [$blueprint->url];

        try {
            $page = Page::fromHtml($blueprint->url, $this->http->get($blueprint->url));
            $page->crawler()->filter($blueprint->pagination->css)->each(
                function (Crawler $link) use (&$urls, $blueprint): void {
                    $absolute = Url::absolute($blueprint->url, $link->attr('href'));
                    if ($absolute !== null) {
                        $urls[] = $absolute;
                    }
                },
            );
        } catch (\Throwable) {
            // Fall back to just the first page.
        }

        // Prev/next arrows duplicate the numbered links; fetch each URL once.
        return PageCap::apply(array_values(array_unique($urls)), $blueprint->maxPages);
    }

    /**
     * @return list<string>
     */
    private function followNext(ScrapeBlueprint $blueprint): array
    {
        $urls = [];
        $seen = [];
        $current = $blueprint->url;

        while ($current !== null && ! in_array($current, $seen, true) && PageCap::allowsMore(count($urls), $blueprint->maxPages)) {
            $urls[] = $current;
            $seen[] = $current;

            try {
                $page = Page::fromHtml($current, $this->http->get($current));
                $next = $page->crawler()->filter($blueprint->pagination->css);
                $current = $next->count() > 0 ? Url::absolute($current, $next->first()->attr('href')) : null;
            } catch (\Throwable) {
                $current = null;
            }
        }

        return $urls;
    }
}
