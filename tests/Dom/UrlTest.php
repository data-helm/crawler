<?php

namespace DataHelm\Crawler\Tests\Dom;

use DataHelm\Crawler\Dom\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function test_absolute_link_is_returned_unchanged(): void
    {
        $this->assertSame(
            'https://other.com/x',
            Url::absolute('https://example.com/page', 'https://other.com/x'),
        );
    }

    public function test_empty_and_null_links_return_null(): void
    {
        $this->assertNull(Url::absolute('https://example.com', null));
        $this->assertNull(Url::absolute('https://example.com', ''));
        $this->assertNull(Url::absolute('https://example.com', '   '));
    }

    public function test_root_relative_link_resolves_against_origin(): void
    {
        $this->assertSame(
            'https://example.com/wiki/Foo',
            Url::absolute('https://example.com/wiki/Bar', '/wiki/Foo'),
        );
    }

    public function test_relative_link_resolves_against_base_directory(): void
    {
        $this->assertSame(
            'https://example.com/a/x',
            Url::absolute('https://example.com/a/b', 'x'),
        );
    }

    public function test_dot_dot_segments_are_collapsed(): void
    {
        $this->assertSame(
            'https://example.com/a/x',
            Url::absolute('https://example.com/a/b/c', '../x'),
        );
    }

    public function test_protocol_relative_link_inherits_base_scheme(): void
    {
        $this->assertSame(
            'https://cdn.example.com/a.jpg',
            Url::absolute('https://example.com/page', '//cdn.example.com/a.jpg'),
        );
    }

    public function test_port_is_preserved(): void
    {
        $this->assertSame(
            'http://example.com:8080/next',
            Url::absolute('http://example.com:8080/page', '/next'),
        );
    }

    public function test_query_only_link_keeps_the_base_path(): void
    {
        // Pagination hrefs are very often bare query strings.
        $this->assertSame(
            'https://example.com/list/page?page=2',
            Url::absolute('https://example.com/list/page', '?page=2'),
        );
    }

    public function test_query_only_link_against_origin_without_path(): void
    {
        $this->assertSame(
            'https://example.com/?page=2',
            Url::absolute('https://example.com', '?page=2'),
        );
    }

    public function test_non_http_schemes_return_null(): void
    {
        $base = 'https://example.com/list/';

        $this->assertNull(Url::absolute($base, 'mailto:foo@bar.com'));
        $this->assertNull(Url::absolute($base, 'javascript:void(0)'));
        $this->assertNull(Url::absolute($base, 'tel:+5511999999999'));
        $this->assertNull(Url::absolute($base, 'data:text/plain;base64,aGk='));
    }

    public function test_fragment_only_link_returns_null(): void
    {
        $this->assertNull(Url::absolute('https://example.com/page', '#section'));
    }

    public function test_relative_link_containing_fragment_still_resolves(): void
    {
        $this->assertSame(
            'https://example.com/wiki/Foo#history',
            Url::absolute('https://example.com/wiki/Bar', '/wiki/Foo#history'),
        );
    }
}
