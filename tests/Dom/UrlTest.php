<?php

namespace DataHelm\Crawler\Tests\Dom;

use DataHelm\Crawler\Dom\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URL resolution is where crawlers quietly break — relative links, protocol-
 * relative URLs, dot-segment traversal, and schemeless bases. These lock the
 * behaviour down.
 */
final class UrlTest extends TestCase
{
    /**
     * @return iterable<string,array{string,?string,?string}>
     */
    public static function absoluteCases(): iterable
    {
        yield 'absolute http is returned as-is' => ['https://a.com/x', 'https://b.com/y', 'https://b.com/y'];
        yield 'root-relative resolves against origin' => ['https://a.com/deep/page', '/img/p.jpg', 'https://a.com/img/p.jpg'];
        yield 'protocol-relative adopts base scheme' => ['https://a.com/', '//cdn.com/p.jpg', 'https://cdn.com/p.jpg'];
        yield 'relative resolves against directory' => ['https://a.com/cat/list.html', 'item.html', 'https://a.com/cat/item.html'];
        yield 'dot-segments collapse' => ['https://a.com/a/b/c.html', '../../x.jpg', 'https://a.com/x.jpg'];
        yield 'single-dot segment stays in the current dir' => ['https://a.com/a/b/', './x.jpg', 'https://a.com/a/b/x.jpg'];
        yield 'port is preserved' => ['https://a.com:8080/p', '/q', 'https://a.com:8080/q'];
        yield 'empty link yields null' => ['https://a.com/', '', null];
        yield 'null link yields null' => ['https://a.com/', null, null];
        yield 'schemeless base is normalized' => ['a.com/cat/', 'item.html', 'https://a.com/cat/item.html'];
    }

    #[DataProvider('absoluteCases')]
    public function test_absolute(string $base, ?string $link, ?string $expected): void
    {
        $this->assertSame($expected, Url::absolute($base, $link));
    }

    public function test_host_strips_scheme_and_returns_hostname(): void
    {
        $this->assertSame('example.com', Url::host('https://example.com/path?q=1'));
        $this->assertSame('example.com.br', Url::host('example.com.br/x'));
    }

    public function test_normalize_adds_https_to_schemeless(): void
    {
        $this->assertSame('https://example.com', Url::normalize('example.com'));
        $this->assertSame('https://example.com/x', Url::normalize('example.com/x'));
        $this->assertSame('http://example.com', Url::normalize('http://example.com'));
        $this->assertSame('https://cdn.com/a.js', Url::normalize('//cdn.com/a.js'));
    }

    public function test_is_fetchable_only_accepts_http_schemes(): void
    {
        $this->assertTrue(Url::isFetchable('https://a.com'));
        $this->assertTrue(Url::isFetchable('http://a.com'));
        $this->assertFalse(Url::isFetchable('file:///etc/passwd'));
        $this->assertFalse(Url::isFetchable('ftp://a.com'));
        $this->assertFalse(Url::isFetchable(''));
    }
}
