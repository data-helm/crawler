<?php

namespace DataHelm\Crawler\Tests\Http;

use DataHelm\Crawler\Http\BlockedUrlException;
use DataHelm\Crawler\Http\GuardedHttpClient;
use DataHelm\Crawler\Http\HttpClient;
use DataHelm\Crawler\Http\HttpRequester;
use DataHelm\Crawler\Http\UrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * The guard decorator asserts before delegating on both get() and request().
 * This is the wrapper that protects page, detail, API and image fetches.
 */
final class GuardedHttpClientTest extends TestCase
{
    private function inner(): HttpClient
    {
        return new class implements HttpClient, HttpRequester {
            public bool $called = false;

            public function get(string $url): string
            {
                $this->called = true;

                return 'ok';
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null, array $query = []): string
            {
                $this->called = true;

                return 'ok';
            }
        };
    }

    public function test_get_blocks_before_delegating(): void
    {
        $inner  = $this->inner();
        $client = new GuardedHttpClient($inner, new UrlGuard(blockPrivateHosts: true));

        try {
            $client->get('http://169.254.169.254/latest/meta-data/');
            $this->fail('expected BlockedUrlException');
        } catch (BlockedUrlException) {
            // The inner client must never be reached for a blocked URL.
            $this->assertFalse($inner->called);
        }
    }

    public function test_request_blocks_before_delegating(): void
    {
        $inner  = $this->inner();
        $client = new GuardedHttpClient($inner, new UrlGuard(blockPrivateHosts: false));

        $this->expectException(BlockedUrlException::class);
        $client->request('GET', 'file:///etc/passwd');
    }

    public function test_allowed_url_reaches_the_inner_client(): void
    {
        $inner  = $this->inner();
        $client = new GuardedHttpClient($inner, new UrlGuard());

        $this->assertSame('ok', $client->get('https://example.com/'));
        $this->assertTrue($inner->called);
    }
}
