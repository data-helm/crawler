<?php

namespace DataHelm\Crawler\Detection;

/**
 * Recognises VTEX storefronts and builds their product-search API.
 *
 * VTEX (the dominant SaaS commerce platform in Latin America — Levi's, Dafiti,
 * many others) renders listings client-side from a JSON API, so HTML detection
 * finds nothing and generic endpoint probing tends to grab the wrong call (the
 * cart `/api/checkout/pub/orderForm`). Every VTEX store exposes the *same* public
 * catalog search though, so once we know it's VTEX we can emit the right endpoint
 * and field paths directly.
 *
 *   GET {base}/api/catalog_system/pub/products/search/{category}?_from=0&_to=49
 *   → a JSON array of products (productName, link, items[].images[].imageUrl,
 *     items[].sellers[].commertialOffer.Price).
 */
final class VtexDetector
{
    /** Fingerprints that appear in the HTML of essentially every VTEX storefront. */
    private const MARKERS = [
        'vteximg.com.br',
        'vtexassets.com',
        'vtexcommercestable',
        '/api/catalog_system/',
        '/api/vtex',
        '__RUNTIME__',
        'vtex.com.br',
    ];

    public function looksLikeVtex(string $html): bool
    {
        foreach (self::MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The catalog product-search endpoint with a {search} placeholder. Each
     * search_filter's url_sufix (a category path like "men/shirts") replaces
     * {search} at crawl time; _from/_to returns up to 50 products per request.
     */
    public function searchEndpoint(string $url): string
    {
        return $this->base($url) . '/api/catalog_system/pub/products/search/{search}?_from=0&_to=49';
    }

    /**
     * Standard VTEX product field paths (JSON dot-paths into each record).
     *
     * @return array<string,string> field name => json path
     */
    public function fields(): array
    {
        return [
            'title' => 'productName',
            'link'  => 'link',
            'image' => 'items.0.images.0.imageUrl',
            'price' => 'items.0.sellers.0.commertialOffer.Price',
        ];
    }

    /**
     * Extra detail fields (used when --get-detail is on). VTEX's search response
     * is already a full product document, so these come from the *same* record —
     * no second request, no detail page. Merged into the main fields.
     *
     * @return array<string,string> field name => json path
     */
    public function detailFields(): array
    {
        return [
            'description' => 'description',
            'brand'       => 'brand',
            'reference'   => 'productReference',
            'list_price'  => 'items.0.sellers.0.commertialOffer.ListPrice',
            'available'   => 'items.0.sellers.0.commertialOffer.AvailableQuantity',
        ];
    }

    private function base(string $url): string
    {
        $parts  = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';

        return $scheme . '://' . $host;
    }
}
