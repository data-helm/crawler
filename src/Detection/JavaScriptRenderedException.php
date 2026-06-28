<?php

namespace DataHelm\Crawler\Detection;

/**
 * Raised by {@see BlueprintGenerator} when a page's content is JavaScript/AJAX
 * rendered and therefore absent from the static HTML.
 *
 * This is an informational outcome, not a failure: detection worked correctly
 * and simply needs the user to point the generator at the site's JSON API
 * (--api-endpoint) or enable a render-js transport. The command surfaces it as
 * green guidance rather than a red error.
 */
final class JavaScriptRenderedException extends \RuntimeException
{
}
