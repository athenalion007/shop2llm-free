<?php

/**
 * Shop2LLM — IndexNow Auto-Push module (v1.7.2)
 *
 * Zero-config discovery acceleration: proactively notifies IndexNow engines
 * (Bing, Yandex, Naver, Seznam, Yep, Mojeek) when discovery artifacts or
 * content change. Breaks the cold-start cycle measured in production
 * (install → wait → never indexed → user churns). Ported from the Pro
 * IndexNow module (class-indexnow.php) — same wire protocol, free version
 * is always-on with no settings surface (lightweight principle).
 *
 * v1.7.2 additions (P0-3 remediation):
 *  - deletion push: before_delete_post notifies engines to drop removed URLs
 *    (WooCommerce products are posts, so product deletions fire this hook
 *    too; hooking woocommerce_before_delete_product as well would
 *    double-push inside the 60s throttle window)
 *  - IndexNow protocol batch cap: at most 10,000 URLs per request
 *  - bounded failure retry: one scheduled retry per failed push (5-minute
 *    backoff, max 3 attempts); throttled pushes are never retried
 *
 * Function names and behavior are unchanged from v1.7.1 — the block was
 * extracted verbatim from shop-to-llm.php so callers need no edits and the
 * wire protocol stays identical (only the three v1.7.2 additions above).
 *
 * Standalone-unit-testable: tests/unit/indexnow_unit_test.php requires this
 * file as-is with WP function stubs.
 *
 * @package Shop2LLM
 * @since   1.7.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SHOP_TO_LLM_INDEXNOW_API = 'https://api.indexnow.org/IndexNow';
const SHOP_TO_LLM_INDEXNOW_KEY_OPTION = 'shop2llm_indexnow_key';
const SHOP_TO_LLM_INDEXNOW_LOG_OPTION = 'shop2llm_indexnow_log';

/**
 * IndexNow API key — generated lazily on first use, no activation cost.
 */
function shop_to_llm_indexnow_key(): string
{
    $key = (string) get_option(SHOP_TO_LLM_INDEXNOW_KEY_OPTION, '');
    if ($key === '') {
        $key = wp_generate_password(32, false);
        add_option(SHOP_TO_LLM_INDEXNOW_KEY_OPTION, $key);
    }
    return $key;
}

/**
 * Serve the key verification file at /indexnow.txt (fixed path — the
 * IndexNow spec accepts any keyLocation on the verified host, so no
 * dynamic rewrite rules or flushes are needed; served from the v1.5.4
 * parse_request fallback router which wins over any shadowing plugin).
 */
function shop_to_llm_indexnow_serve_key(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    header('X-Robots-Tag: noindex');
    echo esc_html(shop_to_llm_indexnow_key());
    exit;
}

/**
 * Push URLs to IndexNow engines. Non-blocking by default so admin
 * save/activation paths never wait on the network.
 *
 * v1.7.2: IndexNow protocol cap of 10,000 URLs per request; on a real HTTP
 * failure (not throttled) one retry is scheduled on WP cron — 5-minute
 * backoff, max 3 attempts, deduped per payload.
 *
 * @param array<int, string> $urls     Absolute URLs (deduped, capped).
 * @param bool               $blocking True for synchronous push.
 * @param int                $attempt  Retry attempt counter (0 = first try).
 * @return array{success: bool, pushed: int, message: string}
 */
function shop_to_llm_indexnow_push(array $urls, bool $blocking = false, int $attempt = 0): array
{
    $key = shop_to_llm_indexnow_key();
    // IndexNow protocol limit: at most 10,000 URLs per request.
    $urls = array_slice(array_values(array_unique(array_filter(array_map('esc_url_raw', $urls)))), 0, 10000);
    if (empty($urls)) {
        return ['success' => false, 'pushed' => 0, 'message' => 'no urls'];
    }

    // Rate limit: one push per 60s regardless of caller (IndexNow etiquette).
    // Retries bypass the throttle only when scheduled outside the window;
    // a throttled push is logged and never rescheduled.
    if (get_transient('shop2llm_indexnow_throttle') !== false) {
        shop_to_llm_indexnow_log('indexnow', count($urls), false, 'throttled');
        return ['success' => false, 'pushed' => 0, 'message' => 'throttled'];
    }
    set_transient('shop2llm_indexnow_throttle', 1, 60);

    $site_url = untrailingslashit(get_site_url());
    $response = wp_remote_post(SHOP_TO_LLM_INDEXNOW_API, [
        'headers'  => ['Content-Type' => 'application/json; charset=utf-8'],
        'body'     => wp_json_encode([
            'host'        => wp_parse_url($site_url, PHP_URL_HOST),
            'key'         => $key,
            'keyLocation' => $site_url . '/indexnow.txt',
            'urlList'     => $urls,
        ]),
        'timeout'  => 5,
        'blocking' => $blocking,
    ]);

    $ok = !is_wp_error($response)
        && wp_remote_retrieve_response_code($response) >= 200
        && wp_remote_retrieve_response_code($response) < 300;
    $message = is_wp_error($response) ? $response->get_error_message()
        : 'HTTP ' . wp_remote_retrieve_response_code($response);

    shop_to_llm_indexnow_log('indexnow', count($urls), $ok, $message);
    if ($ok) {
        // Funnel telemetry: the URL left the site for index engines without
        // any manual copy step (fixes the measured url_copied=0 deadlock).
        shop_to_llm_log_event('url_submitted');
    } elseif ($attempt < 3) {
        // Bounded retry: one event per failed payload, 5-minute backoff.
        $retryArgs = [$urls, $attempt + 1];
        if (wp_next_scheduled('shop2llm_indexnow_retry', $retryArgs) === false) {
            wp_schedule_single_event(time() + 300, 'shop2llm_indexnow_retry', $retryArgs);
        }
    }

    return ['success' => $ok, 'pushed' => $ok ? count($urls) : 0, 'message' => $message];
}

/**
 * Bounded retry runner for a failed push (scheduled by
 * shop_to_llm_indexnow_push — max 3 attempts, 5-minute backoff).
 * Runs on WP cron.
 *
 * @param array<int, string> $urls    URLs from the failed push.
 * @param int                $attempt 1-based attempt counter.
 */
function shop_to_llm_indexnow_retry(array $urls, int $attempt = 1): void
{
    shop_to_llm_indexnow_push($urls, false, $attempt);
}

/**
 * Push a deleted content URL so IndexNow engines drop it from their index.
 *
 * Runs on before_delete_post, while the permalink is still resolvable.
 * WooCommerce products are posts, so product deletions fire this hook too —
 * adding woocommerce_before_delete_product as well would double-push inside
 * the 60s throttle window. Non-public post types (menus, revisions,
 * attachments) are skipped.
 */
function shop_to_llm_indexnow_on_delete(int $post_id): void
{
    $post = get_post($post_id);
    if (
        !$post instanceof \WP_Post
        || in_array($post->post_type, ['nav_menu_item', 'revision', 'attachment'], true)
    ) {
        return;
    }
    $permalink = get_permalink($post);
    if (!is_string($permalink) || $permalink === '') {
        return;
    }
    shop_to_llm_indexnow_push([$permalink]);
}

/**
 * Notify Google that the sitemap changed (60-minute cooldown; Google's
 * ping endpoint is deprecated-but-alive, IndexNow covers the others).
 */
function shop_to_llm_indexnow_push_google(): array
{
    if (get_transient('shop2llm_indexnow_google_throttle') !== false) {
        return ['success' => false, 'message' => 'throttled'];
    }
    set_transient('shop2llm_indexnow_google_throttle', 1, HOUR_IN_SECONDS);

    $sitemap = urlencode(untrailingslashit(get_site_url()) . '/wp-sitemap.xml');
    $response = wp_remote_get('https://www.google.com/ping?sitemap=' . $sitemap, ['timeout' => 5]);
    $ok = !is_wp_error($response)
        && wp_remote_retrieve_response_code($response) >= 200
        && wp_remote_retrieve_response_code($response) < 300;
    shop_to_llm_indexnow_log('google', 1, $ok, is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response));

    return ['success' => $ok, 'message' => $ok ? 'OK' : 'failed'];
}

/**
 * All discovery URLs — called on first admin load, weekly, and manually.
 */
function shop_to_llm_indexnow_push_all(): array
{
    $site_url = untrailingslashit(get_site_url());
    $urls = [
        $site_url . '/',
        $site_url . '/llms.txt',
        $site_url . '/llms-full.txt',
        $site_url . '/agents.md',
        $site_url . '/.well-known/mcp-server',
    ];
    if (shop_to_llm_has_woocommerce()) {
        $urls[] = $site_url . '/product-feed.xml';
    }
    $indexnow = shop_to_llm_indexnow_push($urls);
    $google = shop_to_llm_indexnow_push_google();

    return ['indexnow' => $indexnow, 'google' => $google];
}

/**
 * Push a single content URL (+ the catalog files) when content changes.
 * Hooks: woocommerce_new/update_product, product stock change,
 * transition_post_status (any post type going live).
 */
function shop_to_llm_indexnow_on_content_change(string $url): void
{
    $site_url = untrailingslashit(get_site_url());
    shop_to_llm_indexnow_push([$url, $site_url . '/llms.txt']);
}

/**
 * WooCommerce 3.x passes the hook payload as a WC_Product object
 * (woocommerce_new_product / woocommerce_update_product /
 * woocommerce_product_set_stock); accept objects and bare int IDs alike —
 * a typed int signature fatals here under strict_types and breaks product
 * saves (caught live in the docker e2e run, 2026-10-18).
 */
function shop_to_llm_indexnow_on_product_save($product): void
{
    if (is_object($product) && method_exists($product, 'get_id')) {
        $product_id = (int) $product->get_id();
    } else {
        $product_id = (int) $product;
    }
    if ($product_id <= 0) {
        return;
    }
    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
    if (!$product || $product->get_status() !== 'publish') {
        return;
    }
    shop_to_llm_indexnow_on_content_change($product->get_permalink());
}

function shop_to_llm_indexnow_on_status_transition(string $new_status, string $old_status, \WP_Post $post): void
{
    if ($new_status !== 'publish' || in_array($post->post_type, ['nav_menu_item', 'revision', 'attachment'], true)) {
        return;
    }
    shop_to_llm_indexnow_on_content_change(get_permalink($post));
}

function shop_to_llm_indexnow_weekly(): void
{
    try {
        shop_to_llm_indexnow_push_all();
    } catch (\Throwable $e) {
        // Never break the snapshot cron over a push failure.
    }
}
add_action('shop2llm_weekly_snapshot', 'shop_to_llm_indexnow_weekly');

add_action('woocommerce_new_product', 'shop_to_llm_indexnow_on_product_save', 20);
add_action('woocommerce_update_product', 'shop_to_llm_indexnow_on_product_save', 20);
add_action('woocommerce_product_set_stock', 'shop_to_llm_indexnow_on_product_save', 20);
add_action('transition_post_status', 'shop_to_llm_indexnow_on_status_transition', 20, 3);

// Deletion → notify engines to drop the URL.
add_action('before_delete_post', 'shop_to_llm_indexnow_on_delete', 20);

// Bounded retry runner (WP cron single event, max 3 attempts).
add_action('shop2llm_indexnow_retry', 'shop_to_llm_indexnow_retry', 10, 2);

/**
 * Last push entries for the dashboard status card (newest first, max 10).
 */
function shop_to_llm_indexnow_get_log(): array
{
    $log = get_option(SHOP_TO_LLM_INDEXNOW_LOG_OPTION, []);
    return is_array($log) ? $log : [];
}

/**
 * Append a push entry (bounded — last 10).
 */
function shop_to_llm_indexnow_log(string $engine, int $url_count, bool $success, string $message): void
{
    $log = shop_to_llm_indexnow_get_log();
    array_unshift($log, [
        'engine'    => $engine,
        'urls'      => $url_count,
        'success'   => $success,
        'message'   => $message,
        'timestamp' => current_time('mysql'),
    ]);
    update_option(SHOP_TO_LLM_INDEXNOW_LOG_OPTION, array_slice($log, 0, 10), false);
}
