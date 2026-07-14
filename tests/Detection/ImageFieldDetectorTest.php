<?php

namespace DataHelm\Crawler\Tests\Detection;

use DataHelm\Crawler\Detection\ImageFieldDetector;
use DataHelm\Crawler\Scraping\ItemExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Image detection has to cope with component libraries that don't use a plain
 * <img src> — Quasar/Vuetify render photos as a CSS background-image, a
 * role="img" div, or a <picture>. These cases lock in the pactoleiloes fix
 * (background-image) and the quality ranking that skips tracking pixels/icons.
 */
final class ImageFieldDetectorTest extends TestCase
{
    private function nodeFrom(string $html): \DOMElement
    {
        $doc = new \DOMDocument();
        // Suppress HTML5 tag warnings; force UTF-8.
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $div = $doc->getElementsByTagName('div')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $div);

        return $div;
    }

    public function test_detects_a_plain_img_src(): void
    {
        $field = (new ImageFieldDetector())->detect($this->nodeFrom(
            '<div class="card"><img class="thumb" src="https://cdn.test/photos/car.jpg"></div>',
        ));

        $this->assertNotNull($field);
        $this->assertSame('image', $field->name);
        $this->assertSame('src', $field->attribute);
        $this->assertStringContainsString('img', $field->css);
        $this->assertNull($field->regex);
    }

    public function test_detects_a_css_background_image_and_extracts_the_url(): void
    {
        // Quasar's QImg renders the photo as a background-image on a div — no <img>.
        $node = $this->nodeFrom(
            '<div class="card">'
            . '<div class="q-img__image" style="background-image: url(&quot;https://cdn.test/images/lot.webp&quot;);"></div>'
            . '</div>',
        );

        $field = (new ImageFieldDetector())->detect($node);

        $this->assertNotNull($field, 'A background-image photo must be detected');
        $this->assertSame('style', $field->attribute);
        $this->assertNotNull($field->regex, 'A background-image field needs a regex to pull the URL out of the style');

        // The whole point: the regex extracts the real URL at scrape time.
        $value = (new ItemExtractor([$field]))->extract(new Crawler($node))->get('image');
        $this->assertSame('https://cdn.test/images/lot.webp', $value);
    }

    public function test_ignores_a_data_uri_background_image(): void
    {
        $node = $this->nodeFrom(
            '<div class="card"><div class="x" style="background-image:url(data:image/svg+xml;base64,AAAA)"></div></div>',
        );

        $this->assertNull((new ImageFieldDetector())->detect($node));
    }

    public function test_prefers_a_real_photo_over_a_tracking_pixel(): void
    {
        // A page whose first <img> is a Facebook tracking pixel and second is a
        // real product photo — the quality heuristic must select the photo
        // element (its class makes the resulting selector unambiguous).
        $node = $this->nodeFrom(
            '<div class="page">'
            . '<img class="fb-pixel" src="https://tracker.test/tr?id=123&ev=PageView&noscript=1">'
            . '<img class="product-photo" src="https://cdn.test/images/product-large.jpg">'
            . '</div>',
        );

        $field = (new ImageFieldDetector())->detect($node);
        $this->assertNotNull($field);
        $this->assertStringContainsString('product-photo', $field->css, 'Should select the real photo, not the pixel');

        $value = (new ItemExtractor([$field]))->extract(new Crawler($node))->get('image');
        $this->assertSame('https://cdn.test/images/product-large.jpg', $value);
    }

    public function test_returns_null_when_there_is_no_image_at_all(): void
    {
        $this->assertNull((new ImageFieldDetector())->detect(
            $this->nodeFrom('<div class="card"><span>No picture here</span></div>'),
        ));
    }
}
