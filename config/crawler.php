<?php

use DataHelm\Crawler\Detection\AddressFieldDetector;
use DataHelm\Crawler\Detection\DescriptionFieldDetector;
use DataHelm\Crawler\Detection\ImageFieldDetector;
use DataHelm\Crawler\Detection\LinkFieldDetector;
use DataHelm\Crawler\Detection\PriceFieldDetector;
use DataHelm\Crawler\Detection\RatingFieldDetector;
use DataHelm\Crawler\Detection\TitleFieldDetector;
use DataHelm\Crawler\Output\JsonFileSink;
use DataHelm\Crawler\Pipeline\AbsoluteUrlProcessor;
use DataHelm\Crawler\Pipeline\SchemaCoercionProcessor;
use DataHelm\Crawler\Pipeline\TrimProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Command prefix
    |--------------------------------------------------------------------------
    |
    | Namespace used for every Artisan command the crawler exposes, e.g.
    | "{prefix}:scrap:generate", "{prefix}:scrap:run", and generated robots
    | "{prefix}:robot:{name}". Override per project so the package can ship under
    | any brand without code changes.
    |
    */

    'command_prefix' => env('CRAWLER_COMMAND_PREFIX', 'datahelm'),

    /*
    |--------------------------------------------------------------------------
    | Robot scaffolding
    |--------------------------------------------------------------------------
    |
    | Where `--robot` writes generated command classes and the namespace they
    | are declared under. Both must agree with your autoload configuration.
    |
    */

    'robot' => [
        'namespace' => 'App\\Console\\Commands\\RobotsCommand',
        'path'      => app_path('Console/Commands/RobotsCommand'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'blueprints' => storage_path('app/blueprints'),
        'output'     => storage_path('app/scrapes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP defaults
    |--------------------------------------------------------------------------
    |
    | Baseline request settings used when a blueprint does not override them.
    |
    */

    'http' => [
        'user_agent' => env(
            'CRAWLER_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        ),
        'delay_ms' => (int) env('CRAWLER_HTTP_DELAY', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | Default filesystem disk a generated robot downloads images to ('storage'
    | and 'local' write under storage/app; 'public' under storage/app/public, web
    | accessible after `php artisan storage:link'; or any cloud disk: s3, gcs, …).
    | Override per run with --image-disk. Robots can still edit $imageDisk by hand.
    |
    */

    'images' => [
        'disk' => env('CRAWLER_IMAGE_DISK', 'storage'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Crawl timing defaults
    |--------------------------------------------------------------------------
    |
    | Baseline pacing baked into new blueprints when --page-delay / --item-delay
    | are omitted on datahelm:scrap:generate. Existing robots keep their embedded
    | values until regenerated.
    |
    */

    'crawl' => [
        'delay_between_pages_ms' => (int) env('CRAWLER_PAGE_DELAY', 300),
        'delay_between_items_ms' => (int) env('CRAWLER_ITEM_DELAY', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Field detectors
    |--------------------------------------------------------------------------
    |
    | Ordered list of FieldDetector implementations the blueprint generator runs
    | over a sample list row. Add your own (implementing FieldDetector) to teach
    | the generator about domain-specific fields (SKU, salary, address, …).
    |
    */

    'detectors' => [
        LinkFieldDetector::class,
        TitleFieldDetector::class,
        ImageFieldDetector::class,
        PriceFieldDetector::class,
        RatingFieldDetector::class,
        AddressFieldDetector::class,
        DescriptionFieldDetector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Item pipeline
    |--------------------------------------------------------------------------
    |
    | Ordered list of ItemProcessor implementations applied to every scraped
    | item before it is stored. Append your own to normalise currency, geocode
    | addresses, enrich records, etc.
    |
    */

    'pipeline' => [
        TrimProcessor::class,
        AbsoluteUrlProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP transport
    |--------------------------------------------------------------------------
    |
    | 'auto'    — detect the protection and escalate transports automatically
    |             (guzzle → browser/flaresolverr → scraping_api), baking the one
    |             that works into the blueprint. Best hands-off default.
    | 'guzzle'  — default, uses GuzzleHttpClient.
    | 'browser' — headless browser rendering. Defaults to the bundled
    |             BrowserlessHttpClient (talks to a browserless/chrome container —
    |             see docker-compose). Bind your own BrowserHttpClient subclass
    |             (spatie/browsershot, symfony/panther, …) before this provider
    |             registers to override it.
    |
    */

    'transport' => env('CRAWLER_TRANSPORT', 'guzzle'),

    /*
    |--------------------------------------------------------------------------
    | Headless browser (browserless) settings
    |--------------------------------------------------------------------------
    |
    | Used when transport = 'browser' and no custom BrowserHttpClient is bound.
    | 'url'   — base URL of the browserless/chrome service.
    | 'token' — browserless auth token, if the service requires one.
    |
    */

    'browser' => [
        'url'   => env('BROWSERLESS_URL', 'http://browserless:3000'),
        'token' => env('BROWSERLESS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FlareSolverr settings (transport = 'flaresolverr')
    |--------------------------------------------------------------------------
    |
    | Self-hosted Cloudflare/DDoS-Guard challenge solver (free, open source —
    | see docker-compose). Best first choice for Cloudflare-protected sites.
    | 'url'         — base URL of the FlareSolverr service.
    | 'max_timeout' — ms FlareSolverr may spend solving a challenge per request.
    |
    */

    'flaresolverr' => [
        'url'         => env('FLARESOLVERR_URL', 'http://flaresolverr:8191'),
        'max_timeout' => (int) env('FLARESOLVERR_MAX_TIMEOUT', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream proxy (browser + flaresolverr transports)
    |--------------------------------------------------------------------------
    |
    | Routes the self-hosted browser transports through an upstream proxy. The
    | realistic way past IP-reputation blocks (Akamai, DataDome, PerimeterX) is a
    | RESIDENTIAL or mobile proxy — a datacenter IP is flagged regardless of how
    | good the browser fingerprint is.
    |
    | 'url' — full proxy URL, optionally with credentials:
    |             http://user:pass@host:port   or   http://host:port
    |
    | Notes per transport:
    |   • flaresolverr — native proxy support incl. user:pass auth.
    |   • browser (browserless) — Chrome's --proxy-server ignores embedded
    |     credentials, so auth is sent as a Proxy-Authorization header. The most
    |     reliable setup for browserless is an IP-whitelisted proxy (no creds).
    |
    */

    'proxy' => [
        'url' => env('CRAWLER_PROXY_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security — SSRF guard
    |--------------------------------------------------------------------------
    |
    | A crawler follows URLs, including detail-page URLs built from SCRAPED
    | content — so a malicious page (or an untrusted blueprint) could aim a
    | request at cloud metadata (169.254.169.254) or an internal service
    | (localhost:6379, 10.x, …). The UrlGuard blocks those when enabled.
    |
    | Non-http(s) schemes (file://, gopher://, …) are ALWAYS rejected. Private/
    | reserved/loopback/link-local hosts are rejected only when
    | 'block_private_hosts' is true — off by default so the common case (an
    | operator scraping their own staging/internal host) keeps working. Turn it
    | ON for multi-tenant / SaaS setups where blueprints or targets aren't
    | fully trusted. 'allow_hosts' whitelists specific hosts even then.
    |
    */

    'security' => [
        'block_private_hosts' => (bool) env('CRAWLER_BLOCK_PRIVATE_HOSTS', false),
        'allow_hosts'         => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CRAWLER_ALLOW_HOSTS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Managed scraping API (transport = 'scraping_api')
    |--------------------------------------------------------------------------
    |
    | A hosted scraper (ScraperAPI, ZenRows, ScrapingBee, Zyte, …) that brings
    | its own residential proxies and anti-bot solving — the realistic way past
    | aggressive WAFs like Akamai. All share a "GET <service>?<url>&<key>&flags"
    | shape, so just set the values for your provider. Examples:
    |
    |  ScraperAPI:
    |    SCRAPING_API_URL=https://api.scraperapi.com/
    |    SCRAPING_API_KEY=xxxxx
    |    SCRAPING_API_KEY_PARAM=api_key   SCRAPING_API_URL_PARAM=url
    |    params: ['render' => 'true', 'country_code' => 'br']
    |
    |  ZenRows:
    |    SCRAPING_API_URL=https://api.zenrows.com/v1/
    |    SCRAPING_API_KEY=xxxxx
    |    SCRAPING_API_KEY_PARAM=apikey    SCRAPING_API_URL_PARAM=url
    |    params: ['js_render' => 'true', 'antibot' => 'true', 'premium_proxy' => 'true', 'proxy_country' => 'br']
    |
    |  ScrapingBee:
    |    SCRAPING_API_URL=https://app.scrapingbee.com/api/v1/
    |    SCRAPING_API_KEY=xxxxx
    |    SCRAPING_API_KEY_PARAM=api_key   SCRAPING_API_URL_PARAM=url
    |    params: ['render_js' => 'true', 'premium_proxy' => 'true', 'country_code' => 'br']
    |
    | The extra flags below are read from SCRAPING_API_PARAMS as a query string
    | (e.g. "render=true&country_code=br") so you can tune them without code edits.
    |
    */

    'scraping_api' => [
        'url'           => env('SCRAPING_API_URL', ''),
        'api_key'       => env('SCRAPING_API_KEY', ''),
        'api_key_param' => env('SCRAPING_API_KEY_PARAM', 'api_key'),
        'url_param'     => env('SCRAPING_API_URL_PARAM', 'url'),
        'params'        => (function (): array {
            parse_str((string) env('SCRAPING_API_PARAMS', ''), $params);

            return $params;
        })(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output sink
    |--------------------------------------------------------------------------
    |
    | Default destination for scraped items. Swap for a DatabaseSink, QueueSink,
    | WebhookSink, … (implementing OutputSink) to push results straight into your
    | own systems instead of writing JSON files.
    |
    | Examples:
    |   'sink' => \DataHelm\Crawler\Output\DatabaseSink::class,
    |   'sink' => \DataHelm\Crawler\Output\QueueSink::class,
    |   'sink' => \DataHelm\Crawler\Output\WebhookSink::class,
    |
    */

    'sink' => JsonFileSink::class,

    /*
    |--------------------------------------------------------------------------
    | Pipeline registry
    |--------------------------------------------------------------------------
    |
    | Maps short names (used in blueprint "pipeline_names" array) to processor
    | class names. Blueprints can declare a custom pipeline subset:
    |
    |   "pipeline_names": ["trim", "schema_coercion"]
    |
    | This overrides the global pipeline for that specific crawl only.
    | Add your own processors to extend the registry.
    |
    */

    'pipeline_registry' => [
        'trim'            => TrimProcessor::class,
        'absolute_url'    => AbsoluteUrlProcessor::class,
        'schema_coercion' => SchemaCoercionProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Active preset
    |--------------------------------------------------------------------------
    |
    | Controls which set of heuristics the blueprint generator uses. Override
    | per-run on the CLI without touching this file:
    |
    |   datahelm:scrap:generate <url> --preset=ecommerce
    |   datahelm:scrap:generate <url> --preset=auctions
    |   datahelm:scrap:generate <url> --preset=properties
    |
    | Or set a project-wide default here / via .env:
    |
    |   CRAWLER_PRESET=ecommerce
    |
    */

    'active_preset' => env('CRAWLER_PRESET', 'generic'),

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | A preset is a named bag of heuristic values loaded into the detectors at
    | generate-time. The ServiceProvider falls back through:
    |   active preset → generic preset → hardcoded defaults
    | so you only need to override the keys that differ from `generic`.
    |
    | ─────────────────────────────────────────────────────────────────────────
    | UNIVERSAL vs LOCALE — what do you need to translate?
    | ─────────────────────────────────────────────────────────────────────────
    |
    | UNIVERSAL (do NOT translate — leave in English):
    |   • CSS class / attribute hints: `star`, `rating`, `in-stock`, `sku`, …
    |     Developers write English class names even on French/Portuguese/Arabic
    |     sites. These hints match HTML regardless of the page's display language.
    |
    | LOCALE-SPECIFIC (translate for your language / platform):
    |   • `price_patterns`     — currency symbols and number format.
    |                            $ and € work globally; add your local symbol.
    |   • `image_field_hints`  — when the site is an SPA/API, these match JSON
    |                            *field names* in the API response, which are
    |                            often in the local language (e.g. "foto", "bild").
    |   • `link_field_hints`   — same as above for URL/link fields in JSON.
    |
    | PLATFORM-SPECIFIC (site/platform convention, not language):
    |   • `image_path_prefix`  — a URL path segment that identifies image URLs.
    |                            e.g. "/arquivos/" is a VTEX (Brazilian SaaS)
    |                            convention. Leave null unless you know your
    |                            platform always uses a fixed prefix.
    |
    | ─────────────────────────────────────────────────────────────────────────
    | Adding a locale override
    | ─────────────────────────────────────────────────────────────────────────
    |
    | The easiest way to support a non-English language is to create a custom
    | preset that extends an existing one by merging in the local vocabulary:
    |
    |   'auctions_pt_BR' => [
    |       'price_patterns'    => ['/R\$\s*[\d.,]+/'],          // BRL currency
    |       'image_field_hints' => array_merge(                   // VTEX API keys
    |           ['image', 'img', 'photo', 'thumb'],
    |           ['foto', 'fotos', 'imagem', 'imagens', 'galeria'],
    |       ),
    |       'link_field_hints'  => ['url', 'link', 'href', 'permalink', 'lote'],
    |       'image_path_prefix' => '/arquivos/',                  // VTEX image CDN
    |   ],
    |
    | Then run: datahelm:scrap:generate <url> --preset=auctions_pt_BR
    |
    */

    'presets' => [

        // ─────────────────────────────────────────────────────────────────────
        // generic — safe for any country, any vertical.
        // Start here when you are unsure which preset to use.
        // ─────────────────────────────────────────────────────────────────────
        'generic' => [

            // Matches the most common currency formats worldwide.
            // [LOCALE] Add your local symbol/format if missing.
            'price_patterns' => [
                '/(?:US\$|CA\$|AU\$|NZ\$|\$)\s*[\d.,]+/',   // dollar variants
                '/(?:R\$)\s*[\d.,]+/',                         // Brazilian Real
                '/(?:€)\s*[\d.,]+/',                           // Euro
                '/(?:£)\s*[\d.,]+/',                           // British Pound
                '/(?:¥|CN¥|JP¥)\s*[\d.,]+/',                  // Yen / Yuan
                '/[\d.,]+\s*(?:USD|CAD|AUD|EUR|GBP|BRL|JPY|CNY|INR|MXN)/i',
            ],

            // [UNIVERSAL] CSS class fragments that identify images.
            // These are English regardless of the page's display language.
            'image_field_hints' => ['image', 'img', 'photo', 'thumb', 'picture', 'gallery', 'media'],

            // [UNIVERSAL] CSS / JSON field names that hold item URLs.
            'link_field_hints'  => ['url', 'link', 'href', 'permalink', 'slug'],

            // [UNIVERSAL] CSS class fragments for star/numeric rating widgets.
            'rating_hints'      => ['star', 'rating', 'review-score', 'score'],

            // [UNIVERSAL] CSS class/attribute fragments for stock indicators.
            'stock_hints'       => ['stock', 'availability', 'in-stock', 'out-of-stock', 'inventory'],

            // [UNIVERSAL] CSS class/attribute / JSON key fragments for product codes.
            'sku_hints'         => ['sku', 'ref', 'code', 'article', 'mpn', 'ean', 'gtin'],

            // [PLATFORM] URL path prefix that identifies image CDN URLs. null = auto.
            'image_path_prefix' => null,

            // List detection validation ({@see ListCandidateValidator}).
            'list_core_fields'          => ['link', 'title', 'image'],
            'list_min_core_fields'      => 2,
            'list_min_success_rate'     => 0.6,
            'list_min_link_uniqueness'  => 0.65,

            'item_schema' => [],
        ],

        // ─────────────────────────────────────────────────────────────────────
        // ecommerce — online shops, marketplaces.
        // Use: datahelm:scrap:generate <url> --preset=ecommerce
        //
        // Recommended item_schema in the blueprint for clean typed output:
        //   "item_schema": { "price": "float", "rating": "float", "stock": "string" }
        // ─────────────────────────────────────────────────────────────────────
        'ecommerce' => [

            // [LOCALE] Add your local currency if not listed below.
            'price_patterns' => [
                '/(?:US\$|CA\$|AU\$|\$)\s*[\d.,]+/',
                '/(?:€)\s*[\d.,]+/',
                '/(?:£)\s*[\d.,]+/',
                '/[\d.,]+\s*(?:USD|CAD|AUD|EUR|GBP)/i',
            ],

            // [UNIVERSAL] Extended image hints for typical shop/CDN class names.
            'image_field_hints' => [
                'image', 'img', 'photo', 'thumb', 'thumbnail',
                'picture', 'gallery', 'media', 'cover', 'product-image',
            ],

            // [UNIVERSAL] Extended link/URL hints (Shopify uses `handle`).
            'link_field_hints' => ['url', 'link', 'href', 'permalink', 'slug', 'handle', 'product-url'],

            // [UNIVERSAL] Rating widget class names.
            'rating_hints' => ['star', 'rating', 'review-score', 'score', 'stars', 'average-rating'],

            // [UNIVERSAL] Stock/availability class names.
            'stock_hints' => [
                'stock', 'availability', 'in-stock', 'out-of-stock',
                'inventory', 'qty', 'quantity',
            ],

            // [UNIVERSAL] Product code / identifier class names.
            'sku_hints' => [
                'sku', 'ref', 'code', 'article', 'mpn', 'ean', 'gtin',
                'barcode', 'product-id', 'item-id', 'model',
            ],

            'image_path_prefix' => null,

            'list_core_fields'          => ['link', 'title', 'image'],
            'list_min_core_fields'      => 2,
            'list_min_success_rate'     => 0.6,
            'list_min_link_uniqueness'  => 0.65,

            'item_schema' => [
                'price'  => 'float',
                'rating' => 'float',
            ],
        ],

        // ─────────────────────────────────────────────────────────────────────
        // auctions — auction houses, liquidation platforms.
        // Use: datahelm:scrap:generate <url> --preset=auctions
        //
        // Recommended item_schema:
        //   "item_schema": { "opening_bid": "float", "estimate": "float" }
        // ─────────────────────────────────────────────────────────────────────
        'auctions' => [

            // [LOCALE] Add your local currency symbol.
            'price_patterns' => [
                '/(?:US\$|CA\$|\$)\s*[\d.,]+/',
                '/(?:€)\s*[\d.,]+/',
                '/(?:£)\s*[\d.,]+/',
                '/[\d.,]+\s*(?:USD|CAD|EUR|GBP)/i',
            ],

            // [UNIVERSAL] Image class names used by auction platforms.
            'image_field_hints' => [
                'image', 'img', 'photo', 'thumb', 'lot-image',
                'auction-image', 'picture', 'gallery',
            ],

            // [UNIVERSAL] Link/URL class names typical in auction JSON APIs.
            'link_field_hints' => ['url', 'link', 'href', 'lot-url', 'permalink', 'slug'],

            // [UNIVERSAL] Auction items rarely have star ratings; kept for completeness.
            'rating_hints' => ['star', 'rating', 'score'],

            // [UNIVERSAL] Lot status class names.
            'stock_hints' => ['status', 'lot-status', 'availability', 'active', 'closed', 'sold'],

            // [UNIVERSAL] Lot / catalogue number class names.
            'sku_hints' => ['lot', 'lot-number', 'lot-id', 'sku', 'ref', 'code', 'catalogue'],

            // Hints for prioritising the data/listing endpoint when a site is a
            // SPA/API: universal terms + auction-domain terms (EN + PT-BR). These
            // only RANK candidates — detection still works without them.
            'endpoint_hints' => [
                'api', 'ajax', 'json', 'data', 'list', 'search', 'result', 'item', 'feed', 'graphql', 'rest', 'grid', 'page',
                'auction', 'lot', 'bid', 'catalog', 'catalogue',
                'leilao', 'leiloes', 'leil', 'lote', 'lance', 'arremat', 'busca', 'destaque', 'edital', 'produto',
            ],

            'image_path_prefix' => null,

            'item_schema' => [
                'price' => 'float',
            ],
        ],

        // ─────────────────────────────────────────────────────────────────────
        // properties — real estate listings (houses, apartments, land).
        // Use: datahelm:scrap:generate <url> --preset=properties
        //
        // Recommended item_schema:
        //   "item_schema": { "price": "float", "area": "float", "bedrooms": "int" }
        // ─────────────────────────────────────────────────────────────────────
        'properties' => [

            // [LOCALE] Real estate prices are highly currency-specific. Add yours.
            'price_patterns' => [
                '/(?:US\$|CA\$|\$)\s*[\d.,]+/',
                '/(?:€)\s*[\d.,]+/',
                '/(?:£)\s*[\d.,]+/',
                '/[\d.,]+\s*(?:USD|CAD|EUR|GBP)/i',
            ],

            // [UNIVERSAL] Image class names used on property listing sites.
            'image_field_hints' => [
                'image', 'img', 'photo', 'thumb', 'picture',
                'property-image', 'listing-image', 'gallery', 'media',
            ],

            // [UNIVERSAL] URL / link class names.
            'link_field_hints' => ['url', 'link', 'href', 'permalink', 'slug', 'listing-url'],

            // Ratings are uncommon in real estate; kept as a no-op fallback.
            'rating_hints' => ['star', 'rating', 'score'],

            // [UNIVERSAL] Availability / status class names.
            'stock_hints' => [
                'status', 'availability', 'for-sale', 'for-rent',
                'sold', 'pending', 'active',
            ],

            // [UNIVERSAL] Listing reference / MLS number class names.
            'sku_hints' => ['ref', 'reference', 'mls', 'listing-id', 'property-id', 'code'],

            'image_path_prefix' => null,

            'item_schema' => [
                'price'     => 'float',
                'area'      => 'float',
                'bedrooms'  => 'int',
            ],
        ],

        // ─────────────────────────────────────────────────────────────────────
        // LOCALE EXAMPLE — Brazilian Portuguese / VTEX platform.
        //
        // Copy this block and rename it (e.g. "auctions_pt_BR", "shop_fr",
        // "properties_es") to create a locale-specific preset for your project.
        //
        // WHICH LINES TO TRANSLATE:
        //   price_patterns   → change the currency regex (R$, MXN$, S/, etc.)
        //   image_field_hints → add your language's words for "image/photo/gallery"
        //   link_field_hints  → add your language's words for "link/url"
        //   stock_hints       → add local availability words
        //   sku_hints         → add local product-code words
        //
        // DO NOT TRANSLATE:
        //   CSS class names (star, rating, in-stock…) — always English in HTML.
        //   image_path_prefix — this is a platform CDN path, not a language thing.
        // ─────────────────────────────────────────────────────────────────────
        'auctions_pt_BR' => [

            // BRL currency + common VTEX price formats.
            'price_patterns' => ['/R\$\s*[\d.,]+/'],

            // English CSS hints + Portuguese JSON key names from VTEX APIs.
            // [LOCALE] "foto/fotos/imagem/imagens/galeria" are Portuguese.
            'image_field_hints' => [
                'image', 'img', 'photo', 'thumb', 'picture', 'tims',
                'foto', 'fotos', 'imagem', 'imagens', 'galeria',
            ],

            // [LOCALE] "lote" = lot (auction term in Portuguese).
            'link_field_hints' => ['url', 'link', 'href', 'permalink', 'slug', 'lote'],

            'rating_hints' => ['star', 'rating', 'avaliacao', 'nota'],

            // [LOCALE] "estoque" = stock, "disponibilidade" = availability,
            //          "disponivel" = available — Portuguese words.
            'stock_hints' => ['status', 'availability', 'stock', 'estoque', 'disponibilidade', 'disponivel'],

            // [LOCALE] "codigo" = code, "lote" = lot number — Portuguese words.
            'sku_hints' => ['lot', 'lot-number', 'sku', 'ref', 'code', 'codigo', 'lote'],

            // [PLATFORM] /arquivos/ is the VTEX image CDN path.
            'image_path_prefix' => '/arquivos/',

            'item_schema' => [
                'price' => 'float',
            ],
        ],

    ],

];
