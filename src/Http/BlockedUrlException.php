<?php

namespace DataHelm\Crawler\Http;

/**
 * Thrown by {@see UrlGuard} when an outbound request URL is refused (a non-http
 * scheme, or — when SSRF protection is enabled — a private/reserved host).
 */
final class BlockedUrlException extends \RuntimeException
{
}
