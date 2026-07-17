<?php

namespace DataHelm\Crawler\Tests\Http;

use DataHelm\Crawler\Http\UrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * The SSRF guard: non-http schemes are always refused; private/reserved hosts
 * are refused only when block_private_hosts is on; an allowlist overrides.
 */
final class UrlGuardTest extends TestCase
{
    public function test_non_http_schemes_are_always_blocked(): void
    {
        $guard = new UrlGuard(blockPrivateHosts: false);

        $this->assertNotNull($guard->blockReason('file:///etc/passwd'));
        $this->assertNotNull($guard->blockReason('gopher://evil/'));
        // http(s) to a public host is fine even with private-blocking off.
        $this->assertNull($guard->blockReason('https://example.com/x'));
    }

    public function test_private_hosts_pass_when_guard_disabled(): void
    {
        $guard = new UrlGuard(blockPrivateHosts: false);

        $this->assertNull($guard->blockReason('http://127.0.0.1/'));
        $this->assertNull($guard->blockReason('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_private_and_metadata_hosts_are_blocked_when_enabled(): void
    {
        $guard = new UrlGuard(blockPrivateHosts: true);

        $this->assertNotNull($guard->blockReason('http://127.0.0.1/'));
        $this->assertNotNull($guard->blockReason('http://10.0.0.5/'));
        $this->assertNotNull($guard->blockReason('http://192.168.1.1/'));
        // Cloud metadata endpoint — the classic SSRF pivot.
        $this->assertNotNull($guard->blockReason('http://169.254.169.254/latest/meta-data/'));
        // IPv6 loopback.
        $this->assertNotNull($guard->blockReason('http://[::1]/'));
    }

    public function test_public_host_ip_passes_when_enabled(): void
    {
        $guard = new UrlGuard(blockPrivateHosts: true);

        // A public literal IP (Cloudflare DNS) must not be blocked.
        $this->assertNull($guard->blockReason('http://1.1.1.1/'));
    }

    public function test_allowlist_overrides_private_block(): void
    {
        $guard = new UrlGuard(blockPrivateHosts: true, allowHosts: ['localhost']);

        $this->assertNull($guard->blockReason('http://localhost:6379/'));
        // A host NOT on the allowlist is still blocked.
        $this->assertNotNull($guard->blockReason('http://127.0.0.1/'));
    }

    public function test_assert_throws_on_blocked_url(): void
    {
        $guard = new UrlGuard(blockPrivateHosts: true);

        $this->expectException(\DataHelm\Crawler\Http\BlockedUrlException::class);
        $guard->assert('http://169.254.169.254/');
    }

    public function test_repeated_checks_for_the_same_host_are_consistent(): void
    {
        // The per-host verdict is memoised; repeated calls must return the same
        // answer (and, in practice, resolve DNS only once).
        $guard = new UrlGuard(blockPrivateHosts: true);

        $this->assertNotNull($guard->blockReason('http://127.0.0.1/a'));
        $this->assertNotNull($guard->blockReason('http://127.0.0.1/b'));
        $this->assertNull($guard->blockReason('http://1.1.1.1/a'));
        $this->assertNull($guard->blockReason('http://1.1.1.1/b'));
    }

    public function test_block_reason_names_the_offending_ip(): void
    {
        $guard  = new UrlGuard(blockPrivateHosts: true);
        $reason = $guard->blockReason('http://10.0.0.5/');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('10.0.0.5', $reason);
    }
}
