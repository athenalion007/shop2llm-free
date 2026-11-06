<?php

/**
 * Shop2LLM — Policy JSON-LD & Commerce Accuracy (v1.4.0)
 *
 * Phase 2 discovery layer:
 *
 *  - Policy pages (returns / shipping / tax / terms / privacy) become
 *    machine-readable for AI commerce agents. Claude's Commerce Agent
 *    answers *policy questions only* — a machine-readable MerchantReturnPolicy
 *    or OfferShippingDetails node on the policy page removes all ambiguity
 *    with zero payment risk.
 *  - Google Merchant Center feed health (Google for WooCommerce detection,
 *    local checks only — no phoning home).
 *  - Product-level price/stock accuracy helpers shared by the commerce
 *    AI Score model (sale-price consistency, stale out-of-stock detection).
 *
 * Advanced offer-level enrichment (priceValidUntil, per-variant nodes,
 * review entities) remains a Pro feature — this file only covers the
 * page-level basics.
 *
 * @package Shop2LLM
 * @since   1.4.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// ─── Policy page detection ─────────────────────────────────────────────

/**
 * Map a page slug to its policy type.
 *
 * @param string $slug Page slug (post_name).
 * @return string One of: returns|shipping|tax|terms|privacy|'' when unknown.
 */
function shop_to_llm_policy_detect_type(string $slug): string
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return '';
    }
    $exact = [
        'returns'  => ['return-policy', 'returns', 'refund-policy', 'refunds'],
        'shipping' => ['shipping-policy', 'shipping'],
        'tax'      => ['tax-policy', 'tax', 'taxes'],
        'terms'    => ['terms-of-service', 'terms', 'terms-and-conditions'],
        'privacy'  => ['privacy-policy', 'privacy'],
    ];
    foreach ($exact as $type => $slugs) {
        if (in_array($slug, $slugs, true)) {
            return $type;
        }
    }
    // Prefix fallback for localized slugs (e.g. privacy-policy-2, returns-fr)
    foreach ($exact as $type => $slugs) {
        foreach ($slugs as $candidate) {
            if (strlen($slug) > strlen($candidate) && strpos($slug, $candidate) === 0) {
                return $type;
            }
        }
    }
    return '';
}

/**
 * Find published policy pages, keyed by policy type.
 *
 * Cached 6h; invalidated on save_post_page.
 *
 * @return array<string, array{slug:string,url:string,title:string}>
 */
function shop_to_llm_policy_find_pages(): array
{
    $cached = get_transient('shop2llm_policy_pages');
    if (is_array($cached)) {
        return $cached;
    }

    $types = ['returns', 'shipping', 'tax', 'terms', 'privacy'];
    $exact = [
        'returns'  => ['return-policy', 'returns', 'refund-policy', 'refunds'],
        'shipping' => ['shipping-policy', 'shipping'],
        'tax'      => ['tax-policy', 'tax', 'taxes'],
        'terms'    => ['terms-of-service', 'terms', 'terms-and-conditions'],
        'privacy'  => ['privacy-policy', 'privacy'],
    ];

    $found = [];
    $pages = get_pages([
        'post_status' => 'publish',
        'number'      => 200,
        'sort_column' => 'menu_order',
        'order'       => 'ASC',
    ]);

    // Pass 1: exact canonical slugs win.
    foreach ((array) $pages as $page) {
        $slug = strtolower((string) $page->post_name);
        if ($slug === '') {
            continue;
        }
        foreach ($types as $type) {
            if (!isset($found[$type]) && in_array($slug, $exact[$type], true)) {
                $found[$type] = [
                    'slug'  => $slug,
                    'url'   => (string) get_permalink($page),
                    'title' => (string) get_the_title($page),
                ];
                break;
            }
        }
    }

    // Pass 2: prefix detection for localized slugs.
    foreach ((array) $pages as $page) {
        $slug = strtolower((string) $page->post_name);
        if ($slug === '') {
            continue;
        }
        $type = shop_to_llm_policy_detect_type($slug);
        if ($type !== '' && !isset($found[$type])) {
            $found[$type] = [
                'slug'  => $slug,
                'url'   => (string) get_permalink($page),
                'title' => (string) get_the_title($page),
            ];
        }
    }

    set_transient('shop2llm_policy_pages', $found, 6 * HOUR_IN_SECONDS);
    return $found;
}

// Invalidate the policy page cache when pages change.
add_action('save_post_page', static function (): void {
    delete_transient('shop2llm_policy_pages');
});

// ─── Policy configuration helpers ──────────────────────────────────────

/**
 * Best-effort return policy configuration (free basics).
 *
 * Filterable so developers can adapt without UI:
 * apply_filters('shop2llm_policy_return_config', $config).
 *
 * @return array{days:int,fees:string,country:string} fees = schema.org enum.
 */
function shop_to_llm_policy_return_config(): array
{
    $days = (int) get_option('shop_to_llm_policy_return_days', 30);
    if ($days <= 0) {
        $days = 30;
    }
    $fees = get_option('shop_to_llm_policy_return_fees', 'free') === 'customer'
        ? 'https://schema.org/ReturnFeesCustomerResponsibility'
        : 'https://schema.org/FreeReturn';
    $country = 'US';
    if (function_exists('wc_get_base_location')) {
        $loc = (array) wc_get_base_location();
        if (!empty($loc['country'])) {
            $country = strtoupper((string) $loc['country']);
        }
    }
    /**
     * Filter the return policy configuration.
     *
     * @param array{days:int,fees:string,country:string} $config
     */
    return (array) apply_filters('shop2llm_policy_return_config', [
        'days'    => $days,
        'fees'    => $fees,
        'country' => $country,
    ]);
}

/**
 * Best-effort flat-rate shipping cost from Woo settings (free basics).
 * Mirrors Pro's helper scope but stays page-level.
 *
 * @return array{cost:float,currency:string}|null Null when flat rate unavailable.
 */
function shop_to_llm_policy_flat_rate(): ?array
{
    if (!function_exists('WC')) {
        return null;
    }
    $settings = get_option('woocommerce_flat_rate_settings', []);
    if (!is_array($settings) || empty($settings['enabled'])) {
        return null;
    }
    $cost = 0.0;
    if (isset($settings['cost_per_order']) && is_numeric($settings['cost_per_order'])) {
        $cost = (float) $settings['cost_per_order'];
    } elseif (isset($settings['cost']) && is_numeric($settings['cost'])) {
        $cost = (float) $settings['cost'];
    }
    return [
        'cost'     => $cost,
        'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'USD',
    ];
}

// ─── Schema builders (pure, unit-testable) ─────────────────────────────

/**
 * Build the WebPage shell shared by all policy nodes.
 *
 * @param array{url?:string,name?:string,dateModified?:string} $args Page context.
 * @return array WebPage JSON-LD node (without @context extras).
 */
function shop_to_llm_policy_build_webpage_schema(array $args): array
{
    $page = [
        '@context' => 'https://schema.org',
        '@type'    => 'WebPage',
        'url'      => (string) ($args['url'] ?? ''),
    ];
    if (!empty($args['name'])) {
        $page['name'] = (string) $args['name'];
    }
    if (!empty($args['dateModified'])) {
        $page['dateModified'] = (string) $args['dateModified'];
    }
    return $page;
}

/**
 * Build returns-page JSON-LD: WebPage with MerchantReturnPolicy mainEntity.
 *
 * @param array{url?:string,name?:string,dateModified?:string,country?:string,days?:int,fees?:string} $args
 * @return array
 */
function shop_to_llm_policy_build_return_schema(array $args): array
{
    $policy = [
        '@type'                => 'MerchantReturnPolicy',
        'applicableCountry'    => strtoupper((string) ($args['country'] ?? 'US')),
        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
        'merchantReturnDays'   => max(1, (int) ($args['days'] ?? 30)),
        'returnMethod'         => 'https://schema.org/ReturnByMail',
        'returnFees'           => ($args['fees'] ?? '') !== '' ? (string) $args['fees'] : 'https://schema.org/FreeReturn',
    ];
    if (!empty($args['url'])) {
        $policy['merchantReturnLink'] = (string) $args['url'];
    }
    $page = shop_to_llm_policy_build_webpage_schema($args);
    $page['mainEntity'] = $policy;
    return $page;
}

/**
 * Build shipping-page JSON-LD: WebPage with OfferShippingDetails mainEntity.
 *
 * @param array{url?:string,name?:string,dateModified?:string,country?:string,cost?:float|int|string|null,currency?:string} $args
 * @return array
 */
function shop_to_llm_policy_build_shipping_schema(array $args): array
{
    $details = [
        '@type'               => 'OfferShippingDetails',
        'shippingDestination' => [
            '@type'           => 'DefinedRegion',
            'addressCountry'  => strtoupper((string) ($args['country'] ?? 'US')),
        ],
    ];
    if (isset($args['cost']) && is_numeric($args['cost'])) {
        $details['shippingRate'] = [
            '@type'    => 'MonetaryAmount',
            'value'    => (float) $args['cost'],
            'currency' => (string) ($args['currency'] ?? 'USD'),
        ];
    }
    if (!empty($args['url'])) {
        $details['shippingDetailsLink'] = (string) $args['url'];
    }
    $page = shop_to_llm_policy_build_webpage_schema($args);
    $page['mainEntity'] = $details;
    return $page;
}

// ─── Frontend injection ────────────────────────────────────────────────

/**
 * Inject policy JSON-LD on detected policy pages (wp_head, priority 12).
 *
 * Runs after the SEO module's schema (10) — policy pages are outside the
 * Product/Article types it emits, so there is no duplication risk.
 */
function shop_to_llm_inject_policy_schema(): void
{
    if (get_option('shop_to_llm_policy_schema', 'yes') !== 'yes') {
        return;
    }
    if (!is_page()) {
        return;
    }
    $page = get_queried_object();
    if (!$page instanceof WP_Post || $page->post_status !== 'publish') {
        return;
    }
    $type = shop_to_llm_policy_detect_type((string) $page->post_name);
    if ($type === '') {
        return;
    }

    $config = shop_to_llm_policy_return_config();
    $args = [
        'url'          => (string) get_permalink($page),
        'name'         => (string) get_the_title($page),
        'dateModified' => (string) get_post_modified_time('c', true, $page),
        'country'      => $config['country'],
    ];

    $schema = match ($type) {
        'returns'  => shop_to_llm_policy_build_return_schema($args + ['days' => $config['days'], 'fees' => $config['fees']]),
        'shipping' => shop_to_llm_policy_build_shipping_schema($args + (array) (shop_to_llm_policy_flat_rate() ?? ['cost' => null, 'currency' => 'USD'])),
        default    => shop_to_llm_policy_build_webpage_schema($args),
    };

    /**
     * Filter the policy JSON-LD node before output.
     *
     * @param array  $schema Policy schema node.
     * @param string $type   Policy type (returns|shipping|tax|terms|privacy).
     * @param int    $pageId Page post ID.
     */
    $schema = apply_filters('shop2llm_policy_schema', $schema, $type, (int) $page->ID);
    if (!is_array($schema) || $schema === []) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
}
add_action('wp_head', 'shop_to_llm_inject_policy_schema', 12);

// ─── Score factors (site-level) ────────────────────────────────────────

/**
 * Site-level policy factor (0 / 5 / 10).
 *
 * 10 = returns/shipping page found AND JSON-LD enabled
 *  5 = policy pages found but JSON-LD disabled
 *  0 = no policy pages
 */
function shop_to_llm_policy_score(): int
{
    $enabled = get_option('shop_to_llm_policy_schema', 'yes') === 'yes';
    $pages = shop_to_llm_policy_find_pages();
    $hasPolicyPages = isset($pages['returns']) || isset($pages['shipping']);
    if ($hasPolicyPages && $enabled) {
        return 10;
    }
    if ($hasPolicyPages) {
        return 5;
    }
    return 0;
}

// ─── Product accuracy helpers (commerce AI Score dims) ─────────────────

/**
 * Sale-price consistency: sale price must be strictly lower than regular.
 * Missing sale price is fine; sale without regular (or sale >= regular)
 * is broken data AI engines will flag as a fake discount.
 *
 * @param object $product WC_Product-like (duck-typed for unit tests).
 * @return bool
 */
function shop_to_llm_product_sale_consistent($product): bool
{
    if (!is_object($product) || !method_exists($product, 'get_sale_price')) {
        return true; // unknown shape — don't punish
    }
    $sale = $product->get_sale_price();
    if ($sale === '' || $sale === null || !is_numeric((string) $sale)) {
        return true;
    }
    $regular = $product->get_regular_price();
    if ($regular === '' || $regular === null || !is_numeric((string) $regular)) {
        return false; // sale price without regular price = broken data
    }
    return (float) $sale < (float) $regular;
}

/**
 * Stock-signal currency: the availability signal AI engines quote must be
 * current. Variable products need at least one purchasable in-stock
 * variation; simple products must be in stock — or, when out of stock,
 * touched within 90 days (a stale dead listing misleads AI engines).
 *
 * @param object   $product    WC_Product-like (duck-typed for unit tests).
 * @param int|null $modifiedTs Post-modified timestamp; null = read from WP.
 * @return bool
 */
function shop_to_llm_product_stock_current($product, ?int $modifiedTs = null): bool
{
    if (!is_object($product) || !method_exists($product, 'is_in_stock')) {
        return true; // unknown shape — don't punish
    }
    if (method_exists($product, 'is_type') && $product->is_type('variable')) {
        $children = method_exists($product, 'get_children') ? (array) $product->get_children() : [];
        foreach ($children as $childId) {
            $variation = function_exists('wc_get_product') ? wc_get_product($childId) : null;
            if ($variation && $variation->is_in_stock() && $variation->is_purchasable()) {
                return true;
            }
        }
        return false;
    }
    if ($product->is_in_stock()) {
        return true;
    }
    if ($modifiedTs === null && function_exists('get_post_timestamp') && method_exists($product, 'get_id')) {
        $ts = get_post_timestamp((int) $product->get_id(), 'modified');
        $modifiedTs = $ts === false ? null : (int) $ts;
    }
    if ($modifiedTs === null || $modifiedTs <= 0) {
        return false;
    }
    return (time() - $modifiedTs) <= 90 * DAY_IN_SECONDS;
}
