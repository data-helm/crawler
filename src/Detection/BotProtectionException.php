<?php

namespace DataHelm\Crawler\Detection;

/**
 * Raised by {@see BlueprintGenerator} when the target page can't be fetched
 * because an anti-bot firewall (Akamai, Cloudflare, …) blocked the request.
 *
 * This is distinct from a network failure: the site is up, but it refused us.
 * Detection (SPA analysis, endpoint discovery) never gets to run because no
 * HTML came back, so the command surfaces the WAF vendor and the realistic ways
 * forward (a render-js/browser transport, proxies, or a hand-captured API
 * endpoint) rather than a raw HTML deny page.
 */
final class BotProtectionException extends \RuntimeException
{
    public function __construct(
        public readonly string $vendor,
        public readonly ?int $status,
        public readonly string $url,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
