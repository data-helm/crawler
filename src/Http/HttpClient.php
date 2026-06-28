<?php

namespace DataHelm\Crawler\Http;

/**
 * Abstraction over the transport used to fetch a page's HTML.
 *
 * Depending on this contract (instead of Guzzle directly) keeps every crawler
 * component testable with a fake client and lets the transport be swapped.
 */
interface HttpClient
{
    public function get(string $url): string;
}
