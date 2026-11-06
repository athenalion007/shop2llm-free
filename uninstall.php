<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Uninstall runs in global scope; all local variables are transient and safe.

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'shop_to_llm_enabled',
    'shop_to_llm_api_key',
    'shop_to_llm_catalog_search',
    'shop_to_llm_analytics',
    'shop_to_llm_gcp_sa',
    'shop_to_llm_merchant_id',
    'shop_to_llm_webhook_url',
    'shop_to_llm_enable_entity_checks',
];

foreach ($options as $option) {
    delete_option($option);
}

// Delete all options starting with shop_to_llm_
global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$all_s2ll_options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('shop_to_llm_') . '%'
    )
);
foreach ($all_s2ll_options as $opt) {
    delete_option($opt);
}

// Delete all options starting with shop2llm_ (behavior summary, onboarding state, etc.)
$all_shop2llm_options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('shop2llm_') . '%'
    )
);
foreach ($all_shop2llm_options as $opt) {
    delete_option($opt);
}

for ($i = 0; $i < 30; $i++) {
    $date = gmdate('Y-m-d', strtotime("-{$i} days"));
    delete_transient('shop_to_llm_count_' . $date);
    delete_transient('shop_to_llm_searches_' . $date);
    delete_transient('shop_to_llm_crawlers_' . $date);
}
delete_transient('shop_to_llm_mcp_discovery');
delete_transient('shop_to_llm_mcp_health');
delete_transient('shop_to_llm_mcp_alert_sent');

wp_clear_scheduled_hook('shop_to_llm_health_check');
wp_clear_scheduled_hook('shop_to_llm_weekly_report');
wp_clear_scheduled_hook('shop2llm_cloud_heartbeat');

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_shop_to_llm_') . '%'
    )
);
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_timeout_shop_to_llm_') . '%'
    )
);

// Drop custom tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}shop2llm_crawler_visits");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}shop2llm_search_logs");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}shop2llm_ai_orders");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}shop2llm_acp_payments");

// Cleanup Ultra options
$ultra_options = [
    'shop_to_llm_openai_key',
    'shop_to_llm_stripe_secret_key',
    'shop2llm_neg_price_range',
    'shop2llm_neg_delivery_days',
    'shop2llm_neg_payment_terms',
];
foreach ($ultra_options as $opt) {
    delete_option($opt);
}

// Cleanup channel-related options
$channel_options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('shop2llm_channel_') . '%',
        $wpdb->esc_like('shop_to_llm_channel_health_') . '%'
    )
);
foreach ($channel_options as $opt) {
    delete_option($opt);
}

// Cleanup idempotency transients
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_shop2llm_idem_') . '%',
        $wpdb->esc_like('_transient_timeout_shop2llm_idem_') . '%'
    )
);

// Cleanup health scan transient
delete_transient('shop2llm_health_scan_result');

// Cleanup shop2llm transients (score cache, robots, prerendered, wikidata, wikipedia)
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_shop2llm_') . '%',
        $wpdb->esc_like('_transient_timeout_shop2llm_') . '%'
    )
);

// Drop crawler_log and ai_referrals tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}shop2llm_crawler_log");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}shop2llm_ai_referrals");

// Delete all postmeta starting with _shop2llm_
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like('_shop2llm_') . '%'
    )
);

// Cleanup cron hooks
wp_clear_scheduled_hook('shop2llm_regenerate_openai_feed');
wp_clear_scheduled_hook('shop2llm_channel_health_check');
wp_clear_scheduled_hook('shop2llm_weekly_snapshot');
wp_clear_scheduled_hook('shop2llm_cleanup_crawler_logs');
wp_clear_scheduled_hook('shop2llm_seo_weekly_audit');
// phpcs:enable

delete_option('shop_to_llm_license_tier');

// Drop SEO tables (v1.3.0+). Uninstall context: direct DDL is required (no caching applicable).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}s2ll_redirects");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}s2ll_404_log");

// Cleanup AI SEO transients (v1.3.1+) — s2ll_ai_*
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_s2ll_ai_') . '%',
        $wpdb->esc_like('_transient_timeout_s2ll_ai_') . '%'
    )
);

// Cleanup SEO post meta
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like('_s2ll_ai_') . '%'
    )
);

flush_rewrite_rules();
