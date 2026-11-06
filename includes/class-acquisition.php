<?php

/**
 * Shop2LLM — AI Acquisition Attribution (v1.5.0)
 *
 * Phase 3 acquisition layer: closes the loop between AI referral traffic
 * and WooCommerce orders, entirely on the merchant's server.
 *
 *  - AI referral sessions (already detected by shop_to_llm_detect_ai_referral())
 *    are remembered in the WooCommerce session with a first-party cookie
 *    fallback (source label only — no personal data, no phoning home).
 *  - When the visitor checks out, the order is stamped with the same meta
 *    keys the Pro agentic checkout uses (_shop2llm_ai_order /
 *    _shop2llm_ai_platform), so Pro's AI Revenue tab picks these orders up
 *    automatically when Pro is installed.
 *  - A funnel aggregator turns crawler visits + referral sessions + tagged
 *    orders into one Acquisition Funnel view on the AI Traffic tab.
 *
 * Attribution window: last touch (session is authoritative while it lives,
 * cookie fallback expires after 30 days).
 *
 * @package Shop2LLM
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SHOP_TO_LLM_ACQ_COOKIE = 'shop2llm_ai_src';
const SHOP_TO_LLM_ACQ_COOKIE_TTL = 30 * DAY_IN_SECONDS;

// ─── AI source matching (pure, unit-testable) ──────────────────────────

/**
 * Match an incoming request to an AI platform source.
 *
 * Single source of truth shared by referral logging, session capture and
 * order tagging. Referer wins over utm_source (stronger signal).
 *
 * @param string $referer    HTTP Referer header value (may be empty).
 * @param string $utm_source utm_source query parameter (may be empty).
 * @return array{source:string,label:string}|null Null when not AI-originated.
 */
function shop_to_llm_acquisition_match_ai_source(string $referer, string $utm_source): ?array
{
    $referrers = [
        'chatgpt.com'           => ['source' => 'chatgpt', 'label' => 'ChatGPT'],
        'chat.openai.com'       => ['source' => 'chatgpt', 'label' => 'ChatGPT'],
        'claude.ai'             => ['source' => 'claude', 'label' => 'Claude'],
        'perplexity.ai'         => ['source' => 'perplexity', 'label' => 'Perplexity'],
        'gemini.google.com'     => ['source' => 'gemini', 'label' => 'Gemini'],
        'copilot.microsoft.com' => ['source' => 'copilot', 'label' => 'Copilot'],
        'bing.com/chat'         => ['source' => 'bing-chat', 'label' => 'Bing Chat'],
        'grok.com'              => ['source' => 'grok', 'label' => 'Grok'],
        'chat.deepseek.com'     => ['source' => 'deepseek', 'label' => 'DeepSeek'],
        'you.com'               => ['source' => 'you', 'label' => 'You.com'],
        'search.brave.com'      => ['source' => 'brave', 'label' => 'Brave Search'],
        'phind.com'             => ['source' => 'phind', 'label' => 'Phind'],
        'kagi.com'              => ['source' => 'kagi', 'label' => 'Kagi'],
    ];
    $utmMap = [
        'chatgpt'    => ['source' => 'chatgpt', 'label' => 'ChatGPT'],
        'claude'     => ['source' => 'claude', 'label' => 'Claude'],
        'perplexity' => ['source' => 'perplexity', 'label' => 'Perplexity'],
        'gemini'     => ['source' => 'gemini', 'label' => 'Gemini'],
        'copilot'    => ['source' => 'copilot', 'label' => 'Copilot'],
        'bing-chat'  => ['source' => 'bing-chat', 'label' => 'Bing Chat'],
        'grok'       => ['source' => 'grok', 'label' => 'Grok'],
        'deepseek'   => ['source' => 'deepseek', 'label' => 'DeepSeek'],
        'you'        => ['source' => 'you', 'label' => 'You.com'],
        'brave'      => ['source' => 'brave', 'label' => 'Brave Search'],
        'phind'      => ['source' => 'phind', 'label' => 'Phind'],
        'kagi'       => ['source' => 'kagi', 'label' => 'Kagi'],
    ];

    foreach ($referrers as $domain => $info) {
        if ($referer !== '' && stripos($referer, $domain) !== false) {
            return $info;
        }
    }
    if ($utm_source !== '') {
        $key = strtolower(trim($utm_source));
        if (isset($utmMap[$key])) {
            return $utmMap[$key];
        }
    }
    return null;
}

// ─── Session + cookie capture ──────────────────────────────────────────

/**
 * Remember an AI referral for order attribution.
 *
 * Called by shop_to_llm_detect_ai_referral() when a referral session is
 * detected (after its dedup window). Writes the WooCommerce session when
 * available and always a first-party cookie fallback holding only the
 * source label — no personal data, nothing leaves the server.
 *
 * @param string $source Machine source key (e.g. 'chatgpt').
 * @param string $label  Human label (e.g. 'ChatGPT').
 * @return void
 */
function shop_to_llm_acquisition_capture(string $source, string $label): void
{
    if ($source === '') {
        return;
    }
    if (function_exists('WC') && \WC() && \WC()->session) {
        \WC()->session->set('shop_to_llm_ai_source', $source);
        \WC()->session->set('shop_to_llm_ai_label', $label);
        \WC()->session->set('shop_to_llm_ai_time', time());
    }
    if (!headers_sent()) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.cookbook_setcookie -- first-party cookie, set before output on template_redirect
        @setcookie(SHOP_TO_LLM_ACQ_COOKIE, $source . '|' . $label, [
            'expires'  => time() + SHOP_TO_LLM_ACQ_COOKIE_TTL,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Read the remembered AI attribution for the current visitor.
 *
 * Session wins (fresher); cookie is the fallback across sessions.
 *
 * @return array{source:string,label:string}|null
 */
function shop_to_llm_acquisition_current(): ?array
{
    if (function_exists('WC') && \WC() && \WC()->session) {
        $source = (string) \WC()->session->get('shop_to_llm_ai_source', '');
        if ($source !== '') {
            $label = (string) \WC()->session->get('shop_to_llm_ai_label', $source);
            return ['source' => $source, 'label' => $label !== '' ? $label : $source];
        }
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line
    $raw = isset($_COOKIE[SHOP_TO_LLM_ACQ_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[SHOP_TO_LLM_ACQ_COOKIE])) : '';
    if ($raw !== '') {
        $parts = explode('|', $raw, 2);
        if ($parts[0] !== '') {
            return ['source' => $parts[0], 'label' => $parts[1] ?? $parts[0]];
        }
    }
    return null;
}

// ─── Order tagging ─────────────────────────────────────────────────────

/**
 * Stamp a newly created order with AI attribution meta when applicable.
 *
 * Runs on woocommerce_new_order (all channels: legacy checkout, blocks
 * checkout, Store API). Orders already carrying _shop2llm_ai_order (Pro
 * agentic checkout, or Pro's own tracking hook) are left untouched.
 *
 * @param int $order_id Order id.
 * @return void
 */
function shop_to_llm_acquisition_order_tag(int $order_id): void
{
    if (!function_exists('wc_get_order')) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order || $order->get_meta('_shop2llm_ai_order', true) === 'yes') {
        return;
    }
    // Direct utm_source on the checkout request wins (most recent intent).
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public tracking param, no action performed
    $utm = isset($_GET['utm_source']) ? sanitize_text_field(wp_unslash($_GET['utm_source'])) : '';
    $matched = shop_to_llm_acquisition_match_ai_source('', $utm);
    if ($matched === null) {
        $matched = shop_to_llm_acquisition_current();
    }
    if ($matched === null) {
        return;
    }
    $order->update_meta_data('_shop2llm_ai_order', 'yes');
    $order->update_meta_data('_shop2llm_ai_platform', $matched['source']);
    $order->update_meta_data('_shop2llm_ai_platform_label', $matched['label']);
    $order->save_meta_data();
    shop_to_llm_acquisition_bust_cache();
    // Keep Pro's AI Revenue tab in sync when Pro is installed.
    if (function_exists('shop_to_llm_bust_revenue_cache')) {
        shop_to_llm_bust_revenue_cache();
    }
}
add_action('woocommerce_new_order', 'shop_to_llm_acquisition_order_tag');

// ─── Funnel aggregation ────────────────────────────────────────────────

/**
 * Bust the acquisition funnel cache.
 *
 * @return void
 */
function shop_to_llm_acquisition_bust_cache(): void
{
    delete_transient('shop2llm_acq_funnel_30');
}

/**
 * Aggregate the AI acquisition funnel over a time window.
 *
 * Stages: AI crawler visits → AI referral sessions → AI-attributed orders
 * → AI GMV. Cached 15 minutes.
 *
 * @param int $days Window in days (1-90).
 * @return array{days:int,crawlers:int,referrals:int,ai_orders:int,ai_gmv:float,currency:string,by_platform:array<string,array{orders:int,gmv:float}>}
 */
function shop_to_llm_acquisition_funnel(int $days = 30): array
{
    global $wpdb;
    $days = min(90, max(1, $days));
    $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'USD';
    $default = [
        'days'        => $days,
        'crawlers'    => 0,
        'referrals'   => 0,
        'ai_orders'   => 0,
        'ai_gmv'      => 0.0,
        'currency'    => $currency,
        'by_platform' => [],
    ];

    $cacheKey = 'shop2llm_acq_funnel_' . $days;
    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    // Stage 1+2: crawler visits & referral sessions (local DB tables).
    $crawlerTable = $wpdb->prefix . 'shop2llm_crawler_log';
    $referralTable = $wpdb->prefix . 'shop2llm_ai_referrals';
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $crawlerTable)) === $crawlerTable) {
        $default['crawlers'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$crawlerTable} WHERE visited_at >= %s",
            $since
        ));
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $referralTable)) === $referralTable) {
        $default['referrals'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referralTable} WHERE visited_at >= %s",
            $since
        ));
    }
    // phpcs:enable

    // Stage 3+4: AI-attributed orders (same meta keys as Pro agentic checkout).
    if (function_exists('wc_get_orders')) {
        $orders = wc_get_orders([
            'limit'        => 500,
            'status'       => ['pending', 'processing', 'on-hold', 'completed'],
            'date_created' => '>=' . $since,
            // phpcs:disable WordPress.DB.SlowDBQuery -- attribution flag lookup, cached via transient
            'meta_key'     => '_shop2llm_ai_order',
            'meta_value'   => 'yes',
            // phpcs:enable WordPress.DB.SlowDBQuery
        ]);
        $default['ai_orders'] = count($orders);
        foreach ($orders as $order) {
            $total = (float) $order->get_total();
            $default['ai_gmv'] += $total;
            $platform = strtolower(trim((string) $order->get_meta('_shop2llm_ai_platform', true)));
            // v1.5.4: normalize missing / generic platform signals — ACP checkout
            // can stamp the literal "Unknown" (class-acp-adapter.php default) and
            // empty meta means no platform signal either. Both surface as
            // "Unattributed" instead of a fake platform row.
            if ($platform === '' || $platform === 'unknown') {
                $platform = 'unattributed';
            }
            if (!isset($default['by_platform'][$platform])) {
                $default['by_platform'][$platform] = ['orders' => 0, 'gmv' => 0.0];
            }
            $default['by_platform'][$platform]['orders']++;
            $default['by_platform'][$platform]['gmv'] += $total;
        }
        $default['ai_gmv'] = round($default['ai_gmv'], 2);
        array_walk($default['by_platform'], static function (array &$row): void {
            $row['gmv'] = round($row['gmv'], 2);
        });
    }

    set_transient($cacheKey, $default, 15 * MINUTE_IN_SECONDS);
    return $default;
}

// ─── Dashboard rendering ───────────────────────────────────────────────

/**
 * Render the Acquisition Channels card on the AI Traffic tab.
 *
 * v1.5.4: presented as three INDEPENDENT channels instead of a strict
 * funnel — orders can arrive via agentic checkout (MCP/ACP) without a
 * prior crawler visit or referral session, which made the sequential
 * funnel look contradictory (0 crawls → 0 referrals → N orders).
 *
 * Free shows the 30-day totals; the per-platform revenue breakdown
 * is a Pro feature (visible but locked, matching the product-score dims
 * pattern).
 *
 * @return void
 */
function shop_to_llm_render_acquisition_funnel(): void
{
    if (!shop_to_llm_has_woocommerce()) {
        return;
    }
    $channels = shop_to_llm_acquisition_funnel(30);
    $hasPro = class_exists('Shop_To_LLM_Pro') || defined('SHOP_TO_LLM_PRO_VERSION');
    $refToOrder = $channels['referrals'] > 0
        ? round(($channels['ai_orders'] / $channels['referrals']) * 100, 1)
        : null;
    ?>
    <div class="stl-card" style="border-left:4px solid #6366f1">
        <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#6366f1">
                <path d="M3 3v18h18"/><path d="M7 15l4-6 4 3 5-8"/>
            </svg>
            <?php echo esc_html__('Acquisition Channels', 'shop2llm'); ?>
            <span class="s2ll-help" title="<?php echo esc_attr__('Three independent AI acquisition channels (last 30 days): crawler visits, referral sessions, and AI-attributed orders. Orders placed via agentic checkout (MCP/ACP) are tagged even when no crawl or referral preceded them. Attribution is recorded locally — nothing is sent anywhere.', 'shop2llm'); ?>"></span>
        </h2>
        <p style="color:#64748b;font-size:12px;margin:0 0 12px"><?php echo esc_html__('Three independent AI acquisition channels, tracked in parallel (last 30 days)', 'shop2llm'); ?></p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:8px">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center">
                <div style="font-size:22px;font-weight:700;color:#0f172a"><?php echo esc_html(number_format_i18n($channels['crawlers'])); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px"><?php echo esc_html__('Crawler Visits', 'shop2llm'); ?></div>
                <div style="font-size:10px;color:#94a3b8;margin-top:2px"><?php echo esc_html__('AI bots that crawled your site', 'shop2llm'); ?></div>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center">
                <div style="font-size:22px;font-weight:700;color:#4338ca"><?php echo esc_html(number_format_i18n($channels['referrals'])); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px"><?php echo esc_html__('AI Referral Sessions', 'shop2llm'); ?></div>
                <div style="font-size:10px;color:#94a3b8;margin-top:2px"><?php echo esc_html__('Human visits from an AI assistant', 'shop2llm'); ?></div>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center">
                <div style="font-size:22px;font-weight:700;color:#166534"><?php echo esc_html(number_format_i18n($channels['ai_orders'])); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px"><?php echo esc_html__('AI Orders', 'shop2llm'); ?></div>
                <div style="font-size:10px;color:#94a3b8;margin-top:2px"><?php echo esc_html__('Incl. direct agentic checkout', 'shop2llm'); ?></div>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center">
                <div style="font-size:22px;font-weight:700;color:#b45309"><?php echo esc_html($channels['currency'] . ' ' . number_format($channels['ai_gmv'], 2)); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px"><?php echo esc_html__('AI GMV', 'shop2llm'); ?></div>
                <div style="font-size:10px;color:#94a3b8;margin-top:2px"><?php echo esc_html__('Revenue from AI orders', 'shop2llm'); ?></div>
            </div>
        </div>

        <p style="color:#94a3b8;font-size:11px;margin:0 0 12px;line-height:1.5">
            <?php echo esc_html__('Channels are tracked independently — an order placed by an AI agent via agentic checkout (MCP/ACP) is counted even when no crawler visit or referral session preceded it. These are not sequential funnel stages.', 'shop2llm'); ?>
        </p>

        <?php if ($refToOrder !== null) : ?>
            <p style="color:#64748b;font-size:12px;margin:0 0 12px"><?php /* translators: %s: conversion percentage */ echo sprintf(esc_html__('%s%% of AI referral sessions converted to orders.', 'shop2llm'), esc_html((string) $refToOrder)); ?></p>
        <?php endif; ?>

        <?php if ($channels['ai_orders'] === 0) : ?>
            <div class="s2ll-empty">
                ⏳ <?php echo esc_html__('No AI-attributed orders yet.', 'shop2llm'); ?>
                <br><span class="s2ll-empty-sub"><?php echo esc_html__('When a visitor arrives from ChatGPT, Perplexity, Claude or another AI assistant and checks out, the order is tagged here automatically. Orders placed directly by AI agents via agentic checkout are tagged too.', 'shop2llm'); ?></span>
            </div>
        <?php endif; ?>

        <div style="border-top:1px solid #f1f5f9;padding-top:10px;margin-top:10px">
            <div style="font-size:12px;font-weight:600;color:#1e1e1e;margin-bottom:8px"><?php echo esc_html__('Revenue by AI Platform', 'shop2llm'); ?></div>
            <?php if ($hasPro) : ?>
                <?php $rows = $channels['by_platform']; ?>
                <?php if ($rows === []) : ?>
                    <p style="color:#94a3b8;font-size:12px;margin:0"><?php echo esc_html__('Platform breakdown appears with the first AI order.', 'shop2llm'); ?></p>
                <?php else : ?>
                    <?php foreach ($rows as $platform => $row) : ?>
                        <div class="s2ll-bot-row">
                            <span class="s2ll-bot-name"><?php echo esc_html(ucfirst($platform)); ?></span>
                            <div class="s2ll-bot-track"></div>
                            <span class="s2ll-bot-cnt"><?php echo esc_html((int) $row['orders'] . ' · ' . $channels['currency'] . ' ' . number_format($row['gmv'], 2)); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else : ?>
                <?php foreach (['ChatGPT', 'Claude', 'Gemini', 'Perplexity'] as $pf) : ?>
                    <div class="s2ll-bot-row" style="opacity:0.55">
                        <span class="s2ll-bot-name"><?php echo esc_html($pf); ?></span>
                        <div class="s2ll-bot-track"></div>
                        <span class="s2ll-bot-cnt">🔒 <?php echo esc_html__('Pro', 'shop2llm'); ?></span>
                    </div>
                <?php endforeach; ?>
                <a href="https://shop2llm.com/fincore/v1/page/prolanding" target="_blank" rel="noopener" style="font-size:12px;color:#4f46e5;font-weight:600;text-decoration:none"><?php echo esc_html__('Unlock revenue by AI platform →', 'shop2llm'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
