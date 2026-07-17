<?php

namespace DataHelm\Crawler\Tests\Blueprint;

use DataHelm\Crawler\Blueprint\HttpConfig;
use PHPUnit\Framework\TestCase;

/**
 * TLS verification must default to ON (secure) and only be disabled by an
 * explicit blueprint opt-in — regression for the insecure verify=false default.
 */
final class HttpConfigTest extends TestCase
{
    public function test_verify_tls_defaults_to_true(): void
    {
        $this->assertTrue((new HttpConfig())->verifyTls);
        $this->assertTrue(HttpConfig::fromArray([])->verifyTls);
    }

    public function test_verify_tls_can_be_disabled_explicitly(): void
    {
        $config = HttpConfig::fromArray(['verify_tls' => false]);

        $this->assertFalse($config->verifyTls);
    }

    public function test_verify_tls_round_trips_through_array(): void
    {
        $insecure = HttpConfig::fromArray(['verify_tls' => false]);
        $this->assertFalse(HttpConfig::fromArray($insecure->toArray())->verifyTls);

        $secure = new HttpConfig();
        $this->assertTrue(HttpConfig::fromArray($secure->toArray())->verifyTls);
    }

    public function test_with_render_js_preserves_verify_tls(): void
    {
        $insecure = HttpConfig::fromArray(['verify_tls' => false]);

        $this->assertFalse($insecure->withRenderJs(true)->verifyTls);
    }
}
