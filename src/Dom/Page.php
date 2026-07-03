<?php

namespace DataHelm\Crawler\Dom;

use Symfony\Component\DomCrawler\Crawler;

/**
 * A fetched page: its URL plus a parsed DOM, exposed both as a Symfony
 * {@see Crawler} (for CSS extraction) and as a raw {@see \DOMDocument}
 * (for structural auto-detection).
 */
final class Page
{
    private function __construct(
        public readonly string $url,
        private readonly Crawler $crawler,
    ) {
    }

    public static function fromHtml(string $url, string $html): self
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'UTF-8');

        return new self($url, $crawler);
    }

    public function crawler(): Crawler
    {
        return $this->crawler;
    }

    public function document(): \DOMDocument
    {
        $node = $this->crawler->getNode(0);

        return $node?->ownerDocument ?? new \DOMDocument();
    }
}
