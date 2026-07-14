<?php

namespace DataHelm\Crawler\Http;

/**
 * A transport that can render a URL in a real browser and report the JSON
 * XHR/fetch responses the page fired while loading.
 *
 * This is how a JavaScript SPA's data endpoint is discovered automatically:
 * instead of guessing URLs from static script text (which misses cross-origin
 * or runtime-assembled endpoints), we watch the page's actual network traffic.
 */
interface NetworkCapturingHttpClient
{
    /**
     * Render $url and capture the JSON responses it requested.
     *
     * @return array{html:string, responses:list<array{url:string,method:string,body:string}>}
     *   The fully-rendered HTML plus every JSON response observed (deduped by URL).
     */
    public function captureJsonResponses(string $url): array;
}
