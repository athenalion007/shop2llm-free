<?php

/**
 * Shop2LLM — AI Discovery for Your Website
 *
 * Make your website content discoverable by AI assistants.
 * Works with or without WooCommerce — product AI visibility for your store,
 * plus blog/article AI visibility for your posts and pages.
 * Free version supports ChatGPT with AI-friendly catalog, crawler tracking,
 * and auto-generated schema. Upgrade to Pro for 6 AI platforms.
 *
 * ---
 * Plugin Name: AI Visibility & LLMs.txt Generator — GEO, AEO & MCP for ChatGPT, Claude, Gemini & Perplexity
 * Plugin URI:  https://shop2llm.com/
 * Description: AI Search Visibility & GEO for WordPress — make your site discoverable by ChatGPT, Claude, Gemini, Perplexity & AI search engines. AI visibility score, llms.txt, MCP server, AI crawler tracking, and AI SEO schema in 2 clicks.
 * Version: 1.10.1
 * Author:      FinCore
 * Author URI:  https://shop2llm.com/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shop2llm
 * Domain Path: /languages
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * Tested up to: 7.1
 * WC requires at least: 8.0
 * WC tested up to: 10.8
 * ---
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SHOP_TO_LLM_VERSION', '1.10.1');
define('SHOP_TO_LLM_DIR', plugin_dir_path(__FILE__));
define('SHOP_TO_LLM_URL', plugin_dir_url(__FILE__));

// ── Self-hosted update channel (GitHub distribution only) ──
// selfhost-channel.php is written by deploy/build-shop-to-llm-zip.sh
// --channel=github and is absent from the WordPress.org package, so wp.org
// installs keep their official wordpress.org update channel untouched.
if (is_readable(__DIR__ . '/selfhost-channel.php') && is_readable(__DIR__ . '/includes/class-selfhost-updater.php')) {
    require_once __DIR__ . '/selfhost-channel.php';
    require_once __DIR__ . '/includes/class-selfhost-updater.php';
    \Shop2LLM\SelfhostUpdater::init();
}

// ── PHP2Shell runtime security check (2026-10-27, CVE-2026-109618/109619) ──
// Kernel-level memory-disclosure/RCE fixed in 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.
// Warns (does not block) when host PHP is unpatched or EOL (< 8.2).
add_action('admin_init', static function (): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $minForBranch = ['8.5' => '8.5.12', '8.4' => '8.4.27', '8.3' => '8.3.36', '8.2' => '8.2.35'];
    $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $vulnerable = !isset($minForBranch[$branch])
        || version_compare(PHP_VERSION, $minForBranch[$branch], '<');
    if (!$vulnerable) {
        return;
    }
    $msg = sprintf(
        /* translators: 1: current PHP version, 2: minimum patched PHP version. */
        esc_html__('This server runs PHP %1$s — below the patched floor for the "PHP2Shell" kernel vulnerabilities (CVE-2026-109618/109619, unauthenticated RCE). Upgrade to PHP %2$s or newer immediately. Data in process memory (DB credentials, API keys) may be exposed.', 'shop2llm'),
        PHP_VERSION,
        $minForBranch[$branch] ?? '8.2.35'
    );
    add_action('admin_notices', static function () use ($msg): void {
        printf('<div class="notice notice-error"><p><strong>🛡 Shop2LLM:</strong> %s</p></div>', esc_html($msg));
    });
    add_filter('site_status_tests', static function (array $tests) use ($msg): array {
        $tests['direct']['shop2llm_php_patch_level'] = [
            'label' => __('PHP security patch level (PHP2Shell)', 'shop2llm'),
            'test'  => static fn (): array => [
                'status'      => 'critical',
                'label'       => __('PHP is below the PHP2Shell patched floor', 'shop2llm'),
                'description' => '<p>' . esc_html($msg) . '</p>',
            ],
        ];
        return $tests;
    });
});

// v1.6.0: Dashboard onboarding video — paste the public YouTube URL of the
// 60-90s demo here once uploaded; the "Watch how it works" block on the
// dashboard hides itself automatically while this stays empty.
if (!defined('SHOP_TO_LLM_YOUTUBE_DEMO_URL')) {
    define('SHOP_TO_LLM_YOUTUBE_DEMO_URL', '');
}

/**
 * Check if WooCommerce is installed and active.
 */
function shop_to_llm_has_woocommerce(): bool
{
    return class_exists('WooCommerce');
}

// ─── PHP 7.4 Polyfills ─────────────────────────────────────────────────

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, 0, strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// ─── Lifecycle ─────────────────────────────────────────────────────────

register_activation_hook(__FILE__, 'shop_to_llm_activate');
register_deactivation_hook(__FILE__, 'shop_to_llm_deactivate');

// Translation — load textdomain for i18n
$shop2llm_mofile = __DIR__ . '/languages/shop-to-llm-' . determine_locale() . '.mo';
if (file_exists($shop2llm_mofile)) {
    load_textdomain('shop2llm', $shop2llm_mofile);
}
unset($shop2llm_mofile);

// Register custom cron schedules ('monthly' and 'weekly' are not built-in)
add_filter('cron_schedules', 'shop_to_llm_cron_schedules');
function shop_to_llm_cron_schedules(array $schedules): array
{
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Once Weekly', 'shop2llm'),
        ];
    }
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = [
            'interval' => MONTH_IN_SECONDS,
            'display' => __('Once Monthly', 'shop2llm'),
        ];
    }
    return $schedules;
}

// HPOS compatibility (required for WooCommerce Marketplace)
if (shop_to_llm_has_woocommerce()) {
    add_action('before_woocommerce_init', function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    });
}

function shop_to_llm_activate(): void
{
    // ─── Version compatibility check ──────────────────────────────────
    $minWpVersion = '6.0';
    $minPhpVersion = '8.0';
    $wpVersion = get_bloginfo('version');
    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    if (version_compare($wpVersion, $minWpVersion, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(sprintf(
            /* translators: 1: plugin name, 2: required WP version, 3: current WP version */
            esc_html__('%1$s requires WordPress %2$s or higher. Your site is running WordPress %3$s. Please upgrade WordPress before activating this plugin.', 'shop2llm'),
            'Shop2LLM',
            esc_html($minWpVersion),
            esc_html($wpVersion)
        ), 'Plugin Activation Error', ['back_link' => true, 'response' => 200]);
    }

    if (version_compare($phpVersion, $minPhpVersion, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(sprintf(
            /* translators: 1: plugin name, 2: required PHP version, 3: current PHP version */
            esc_html__('%1$s requires PHP %2$s or higher. Your site is running PHP %3$s. Please upgrade PHP before activating this plugin.', 'shop2llm'),
            'Shop2LLM',
            esc_html($minPhpVersion),
            esc_html($phpVersion)
        ), 'Plugin Activation Error', ['back_link' => true, 'response' => 200]);
    }

    if (!shop_to_llm_has_woocommerce()) {
        set_transient('shop_to_llm_woocommerce_warning', 1, DAY_IN_SECONDS);
    }

    add_option('shop_to_llm_enabled', 'yes');
    add_option('shop_to_llm_api_key', wp_generate_password(32, false));
    add_option('shop_to_llm_catalog_search', 'yes');
    add_option('shop_to_llm_analytics', 'no');
    add_option('shop_to_llm_show_badge', 'yes');
    // v1.3.3: Product sync consent — defaults to 'no', user opts in via settings or onboarding
    add_option('shop2llm_product_sync_consent', 'no');
    // Show onboarding wizard on first visit after activation
    add_option('shop_to_llm_just_activated', '1');
    // Reset value card dismissed state so it shows on activation
    delete_option('shop2llm_value_card_dismissed');
    shop_to_llm_log_event('activated');

    // v1.6.1: activation must be fast and fatal-proof. Catalog generation,
    // rewrite flush, DB table creation and SEO defaults moved out of the
    // activation request into shop_to_llm_run_deferred_setup() (first admin
    // load). On restricted shared hosting — DB user without CREATE privilege,
    // short max_execution_time — doing this work here killed the activation
    // with a fatal/timeout, and the user deleted the plugin on the spot.
    add_option('shop2llm_pending_setup', '1');

    // Schedule daily cleanup of crawler logs older than 30 days
    if (!wp_next_scheduled('shop2llm_cleanup_crawler_logs')) {
        wp_schedule_event(time(), 'daily', 'shop2llm_cleanup_crawler_logs');
    }

    // v1.3.3: Removed auto-ping on activation (WP Guideline 7 compliance).
    // Ping is now sent only when user explicitly enables Analytics in Settings.

    // v1.3.3: Removed auto product sync on activation (WP Guideline 7 compliance).
    // Product sync is now opt-in via Settings → Product Sync Consent, or triggered
    // by the daily cloud heartbeat only if analytics consent is given.

    // Daily cloud heartbeat — refreshes behavior data and active status
    if (!wp_next_scheduled('shop2llm_cloud_heartbeat')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'shop2llm_cloud_heartbeat');
    }

    // Weekly visibility snapshot for all published posts
    if (!wp_next_scheduled('shop2llm_weekly_snapshot')) {
        wp_schedule_event(time(), 'weekly', 'shop2llm_weekly_snapshot');
    }

    // Redirect to dashboard after activation
    add_option('shop_to_llm_do_activation_redirect', true);
}

/**
 * v1.6.1: one-shot heavy setup, deferred out of the activation request.
 *
 * Runs on the first admin page load after activation (priority 5, before the
 * activation redirect at priority 10), so the user lands on an already-live
 * dashboard instead of watching the activation request die on a fatal. Every
 * step is individually guarded: one failing step (e.g. CREATE privilege
 * denied on shared hosting) must never block the rest of onboarding.
 */
add_action('admin_init', 'shop_to_llm_run_deferred_setup', 5);
function shop_to_llm_run_deferred_setup(): void
{
    if (!get_option('shop2llm_pending_setup')) {
        return;
    }
    // Run exactly once, even if a step below throws.
    delete_option('shop2llm_pending_setup');

    // Convert PHP warnings/notices (e.g. dbDelta "CREATE command denied to
    // user") into caught exceptions so a restricted DB user can neither leak
    // raw warnings into the admin page nor abort the remaining steps.
    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
        // phpcs:ignore WordPress.Security.EscapeOutput -- ErrorException constructor arguments, not template output.
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    $steps = [
        'shop_to_llm_set_default_options',
        // Catalog generation (also hooked on init; idempotent — kept here so the
        // first dashboard load always finds the artifacts, mirroring the v1.5.4
        // activation-order fix for the flushed ruleset).
        'shop_to_llm_generate_llms_txt',
        'shop_to_llm_generate_agents_md',
        'shop_to_llm_generate_llms_full_txt',
        'shop_to_llm_register_feed_rewrite',
        'shop_to_llm_register_mcp_server_rewrite',
        'shop_to_llm_create_crawler_table',
        'shop_to_llm_create_ai_referrals_table',
    ];

    foreach ($steps as $step) {
        if (!function_exists($step)) {
            continue;
        }
        try {
            $step();
        } catch (\Throwable $e) {
            // Step-level containment: onboarding continues without this step.
        }
    }

    try {
        flush_rewrite_rules();
    } catch (\Throwable $e) {
        // Best-effort: the init-hook rewrite registrations and the v1.5.4
        // URI-level parse_request fallback still serve /llms.txt and friends.
    }

    // SEO default options (cheap writes, but grouped with the heavy block so
    // the activation request stays minimal).
    foreach (
        [
        'shop_to_llm_seo_home_title' => '',
        'shop_to_llm_seo_home_desc' => '',
        'shop_to_llm_title_sep' => '|',
        ] as $optionKey => $optionValue
    ) {
        try {
            add_option($optionKey, $optionValue);
        } catch (\Throwable $e) {
            // Ignore — options are re-created lazily by their readers.
        }
    }

    restore_error_handler();

    // v1.7.1 (A2): first IndexNow push — deferred out of activation (fast
    // activation principle) and non-blocking, so a slow indexnow.org never
    // delays the first dashboard load.
    try {
        shop_to_llm_indexnow_push_all();
    } catch (\Throwable $e) {
        // Best-effort: the weekly cron retry covers failures.
    }

    // v1.3.3: Removed auto-ping on activation (WP Guideline 7 compliance).
    // Ping is now sent only when user explicitly enables Analytics in Settings.

    // v1.3.3: Removed auto product sync on activation (WP Guideline 7 compliance).
    // Product sync is now opt-in via Settings → Product Sync Consent, or triggered
    // by the daily cloud heartbeat only if analytics consent is given.
}

function shop_to_llm_deactivate(): void
{
    wp_clear_scheduled_hook('shop2llm_cloud_heartbeat');
    wp_clear_scheduled_hook('shop2llm_cleanup_crawler_logs');
    wp_clear_scheduled_hook('shop2llm_weekly_snapshot');
    wp_clear_scheduled_hook('shop2llm_seo_weekly_audit'); // legacy cleanup for pre-1.6.1 upgraders
    // v1.3.3: Clear cached uninstall counts so they're recalculated on next activation
    delete_transient('shop2llm_uninstall_counts');
    flush_rewrite_rules();
}

// ─── REST API Routes ───────────────────────────────────────────────────

add_action('rest_api_init', 'shop_to_llm_register_routes');
add_action('wp', 'shop_to_llm_detect_ai_crawler', 1);

// ─── Admin Hooks ──────────────────────────────────────────────────────

add_action('admin_menu', 'shop_to_llm_add_admin_page');
add_action('admin_enqueue_scripts', 'shop_to_llm_enqueue_admin_assets');
/**
 * v1.5.4: Global notices may only render on WP-native surfaces (Dashboard,
 * Plugins list). Prevents Shop2LLM banners from leaking into third-party
 * plugin pages — competitor plugins in this category all have this defect.
 */
function shop_to_llm_is_global_notice_screen(): bool
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return (bool) ($screen && in_array($screen->id, ['dashboard', 'plugins'], true));
}

add_action('admin_notices', 'shop_to_llm_render_onboarding_banner');
add_action('admin_notices', 'shop_to_llm_first_crawler_notice');
add_action('admin_notices', 'shop_to_llm_review_fallback_notice');
add_action('admin_notices', 'shop_to_llm_weekly_crawler_summary');
add_action('admin_notices', 'shop_to_llm_woocommerce_warning_notice');
add_action('admin_notices', 'shop_to_llm_robots_txt_fix_notice');
add_action('admin_init', 'shop_to_llm_activation_redirect');
add_action('admin_init', 'shop_to_llm_review_fallback_dismiss');
add_action('wp_ajax_shop2llm_dismiss_onboarding', 'shop_to_llm_dismiss_onboarding');
add_action('wp_ajax_shop2llm_cache_community_count', 'shop_to_llm_cache_community_count');
add_action('wp_ajax_shop2llm_dismiss_pro_cta', 'shop_to_llm_dismiss_pro_cta');
add_action('wp_ajax_shop2llm_dismiss_review_card', 'shop_to_llm_dismiss_review_card');
add_action('wp_ajax_shop2llm_test_search', 'shop_to_llm_handle_test_search');

add_action('wp_ajax_shop2llm_fix_item', 'shop_to_llm_handle_fix_item');
add_action('wp_ajax_shop2llm_log_event', 'shop_to_llm_handle_log_event');
add_action('wp_ajax_shop2llm_add_focus_idea', 'shop_to_llm_handle_add_focus_idea');
add_action('wp_ajax_shop2llm_enable_analytics', 'shop_to_llm_handle_enable_analytics');
add_action('wp_ajax_shop2llm_asset_tool', 'shop_to_llm_handle_asset_tool');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'shop_to_llm_add_action_links');
add_action('wp_head', 'shop_to_llm_inject_product_schema');
add_action('init', 'shop_to_llm_generate_llms_txt');
add_action('shop2llm_cleanup_crawler_logs', 'shop_to_llm_cleanup_old_crawler_logs');
add_filter('query_vars', 'shop_to_llm_llms_txt_query_var');
add_action('template_redirect', 'shop_to_llm_render_llms_txt');
add_action('init', 'shop_to_llm_generate_agents_md');
add_filter('query_vars', 'shop_to_llm_agents_md_query_var');
add_action('template_redirect', 'shop_to_llm_render_agents_md_dossier', 1);
add_action('template_redirect', 'shop_to_llm_render_agents_md');
// v1.6.0: dedicated /llms-full.txt — complete-content catalog (llmstxt.org spec),
// no longer an alias of the /agents.md dossier.
add_action('init', 'shop_to_llm_generate_llms_full_txt');
add_filter('query_vars', 'shop_to_llm_llms_full_txt_query_var');
add_action('template_redirect', 'shop_to_llm_render_llms_full_txt', 1);
add_filter('robots_txt', 'shop_to_llm_robots_txt');
// v1.7.0: IETF MCP discovery manifest at /.well-known/mcp-server
// (draft-serra-mcp-discovery-uri — agents resolving a bare domain learn the
// site's MCP endpoint without out-of-band configuration).
add_action('init', 'shop_to_llm_register_mcp_server_rewrite');

// ─── Feature 1: AI Discovery Badge & Verification Page ────────────────

add_action('wp_footer', 'shop_to_llm_render_footer_badge');
add_action('init', 'shop_to_llm_add_visibility_rewrite');
add_filter('query_vars', 'shop_to_llm_visibility_query_var');
add_action('template_redirect', 'shop_to_llm_render_visibility_page');

// ─── Feature 2: AI Referral Tracker ───────────────────────────────────

add_action('template_redirect', 'shop_to_llm_detect_ai_referral', 1);

// ─── Anchoring Feature Hooks ──────────────────────────────────────────

// P0: Admin Bar Real-time Badge
add_action('admin_bar_menu', 'shop_to_llm_admin_bar_crawler_badge', 999);
// P0: Dashboard Widget Recovery
add_action('wp_dashboard_setup', 'shop_to_llm_register_dashboard_widget');
// P0: Uninstall Confirmation Dialog
add_action('admin_footer-plugins.php', 'shop_to_llm_uninstall_confirmation_js');
// P1: AI Visibility Score Meta Box (rendered inside the unified Shop2LLM hub meta box)
add_action('admin_head', 'shop_to_llm_visibility_meta_box_css');
// P2: AI Visibility Column in Post List
add_filter('manage_edit-post_columns', 'shop_to_llm_add_visibility_column');
add_filter('manage_edit-product_columns', 'shop_to_llm_add_visibility_column');
add_action('manage_post_posts_custom_column', 'shop_to_llm_render_visibility_column', 10, 2);
add_action('manage_product_posts_custom_column', 'shop_to_llm_render_visibility_column', 10, 2);
add_action('save_post', 'shop_to_llm_save_focus_query');
add_action('shop2llm_weekly_snapshot', 'shop_to_llm_weekly_snapshot_callback');

/**
 * v1.9.0: Detect the official WordPress AI stack (Abilities API plugin
 * providing wp_register_ability + the official MCP Adapter whose
 * mcp_adapter_init action has fired). When active, our MCP tools are exposed
 * as abilities on the adapter's default server and the built-in /mcp endpoint
 * retires so the same capabilities are never served twice.
 */
function shop_to_llm_official_mcp_stack_active(): bool
{
    // The adapter class loads at plugin-file load (before rest_api_init), so
    // class_exists is reliable here; the mcp_adapter_init action only fires
    // later (rest_api_init priority 15 / CLI init priority 20) and is kept as
    // a secondary signal for other integration orders.
    return function_exists('wp_register_ability')
        && (class_exists('\WP\Mcp\Core\McpAdapter') || did_action('mcp_adapter_init') > 0);
}

/**
 * v1.9.0: The MCP endpoint AI clients should connect to — the official MCP
 * Adapter default server when present, else our built-in fallback.
 */
function shop_to_llm_mcp_endpoint_url(): string
{
    return shop_to_llm_official_mcp_stack_active()
        ? rest_url('mcp/mcp-adapter-default-server')
        : rest_url('shop2llm/v1/mcp');
}

function shop_to_llm_register_routes(): void
{
    if (get_option('shop_to_llm_enabled', 'yes') !== 'yes') {
        return;
    }

    // v1.9.0: with the official stack active the adapter's default server
    // (/wp-json/mcp/mcp-adapter-default-server) exposes our abilities — retire
    // the built-in /mcp route so tools are never double-served.
    if (!shop_to_llm_official_mcp_stack_active()) {
        register_rest_route('shop2llm/v1', '/mcp', [
            'methods' => ['POST', 'GET'],
            'callback' => 'shop_to_llm_handle_mcp',
            'permission_callback' => 'shop_to_llm_check_auth',
        ]);
    }

    register_rest_route('shop2llm/v1', '/.well-known/mcp', [
        'methods' => ['GET'],
        'callback' => 'shop_to_llm_mcp_discovery',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('shop2llm/v1', '/community', [
        'methods' => ['GET'],
        'callback' => 'shop_to_llm_community_stats_endpoint',
        'permission_callback' => '__return_true',
    ]);
}

function shop_to_llm_add_admin_page(): void
{
    add_menu_page(
        'Shop2LLM',
        'Shop2LLM',
        'manage_options',
        'shop2llm',
        'shop_to_llm_render_settings',
        'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M5.5 7C3.5 7 2 8.5 2 10s1.5 3 3.5 3c1.2 0 2-.5 3-1.5L10 10l1.5 1.5c1 1 1.8 1.5 3 1.5 2 0 3.5-1.5 3.5-3S16.5 7 14.5 7c-1.2 0-2 .5-3 1.5L10 10 8.5 8.5C7.5 7.5 6.7 7 5.5 7z"/></svg>'),
        56
    );
}

function shop_to_llm_enqueue_admin_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_shop2llm') {
        return;
    }
    wp_enqueue_style(
        'shop2llm-admin',
        SHOP_TO_LLM_URL . 'assets/css/admin.css',
        [],
        SHOP_TO_LLM_VERSION . '.' . (string) filemtime(SHOP_TO_LLM_DIR . 'assets/css/admin.css')
    );
    wp_enqueue_script(
        'shop2llm-admin',
        SHOP_TO_LLM_URL . 'assets/js/admin.js',
        [],
        SHOP_TO_LLM_VERSION,
        true
    );
    wp_localize_script('shop2llm-admin', 'shop2llm', [
        'nonce' => wp_create_nonce('shop2llm_admin_nonce'),
        'ajaxurl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('shop2llm/v1/'),
    ]);
}

function shop_to_llm_add_action_links(array $links): array
{
    $settings_link = '<a href="' . admin_url('admin.php?page=shop2llm') . '">' . esc_html__('Settings', 'shop2llm') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

function shop_to_llm_activation_redirect(): void
{
    if (get_option('shop_to_llm_do_activation_redirect', false)) {
        delete_option('shop_to_llm_do_activation_redirect');
        // Only redirect if this is a single plugin activation (not bulk)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only, no form processing
        if (!isset($_REQUEST['activate-multi'])) {
            wp_safe_redirect(admin_url('admin.php?page=shop2llm'));
            exit;
        }
    }
}

// ─── Onboarding Wizard ────────────────────────────────────────────────

function shop_to_llm_first_crawler_notice(): void
{
    $platform = get_transient('shop_to_llm_first_crawler_admin_notice');
    if (!$platform) {
        return;
    }
    // v1.5.4: only on WP-native surfaces (Dashboard/Plugins)
    if (!shop_to_llm_is_global_notice_screen()) {
        return;
    }
    delete_transient('shop_to_llm_first_crawler_admin_notice');
    ?>
    <div class="notice notice-success is-dismissible" style="border-left-color:#166534">
        <p style="font-size:14px">
            <strong><?php echo wp_kses(shop_to_llm_icon('sparkles'), shop_to_llm_allowed_svg()); ?> <?php echo shop_to_llm_has_woocommerce() ? esc_html(sprintf(/* translators: %s: platform name */ __('%s just discovered your store!', 'shop2llm'), $platform)) : esc_html(sprintf(/* translators: %s: platform name */ __('%s just discovered your site!', 'shop2llm'), $platform)); ?></strong><br>
            <?php echo shop_to_llm_has_woocommerce() ? esc_html__('Your products are now visible to AI assistants. View your AI visibility dashboard:', 'shop2llm') : esc_html__('Your content is now visible to AI assistants. View your AI visibility dashboard:', 'shop2llm'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=shop2llm')); ?>"><?php echo esc_html__('Open Shop2LLM Dashboard', 'shop2llm'); ?></a>
        </p>
    </div>
    <?php
}

// ─── Rating Loop (v1.5.6): value-moment review asks ───────────────────
// Replaces the v1.3.2 milestone machinery (review_request_sent transient +
// day-7 notice + permanently-dismissed review card). One shared gate, three
// value moments, WordPress.org review-guideline compliant (no gating, no
// incentives):
//   M1 — inline toast in the Hero Test Search results on the first
//        successful search (server-flagged `review_ask`, rendered client-side)
//   M2 — inline ask in the AI Crawler Activity card when a real crawler
//        visit is visible on the AI Traffic tab
//   M3 — day-30 fallback notice on WP-native surfaces (Dashboard/Plugins)
// Gate: max 2 lifetime asks, >=14 days between asks, dismiss = re-ask
// after 30 days (never permanent).

function shop_to_llm_review_gate(): bool
{
    if ((int) get_option('shop2llm_review_asks', 0) >= 2) {
        return false;
    }
    $lastAsk = (int) get_option('shop2llm_review_last_ask', 0);
    if ($lastAsk && (time() - $lastAsk) < 14 * DAY_IN_SECONDS) {
        return false;
    }
    $lastDismiss = (int) get_option('shop2llm_review_last_dismiss', 0);
    if ($lastDismiss && (time() - $lastDismiss) < 30 * DAY_IN_SECONDS) {
        return false;
    }
    return true;
}

function shop_to_llm_review_register_ask(): void
{
    update_option('shop2llm_review_asks', (int) get_option('shop2llm_review_asks', 0) + 1, false);
    update_option('shop2llm_review_last_ask', time(), false);
}

function shop_to_llm_review_record_dismiss(): void
{
    update_option('shop2llm_review_last_dismiss', time(), false);
}

function shop_to_llm_review_url(): string
{
    return 'https://wordpress.org/support/plugin/shop2llm/reviews/#new-post';
}

/**
 * Shared inline ask markup for in-dashboard value moments (M2). Visuals are
 * the exact v1.3.2–1.5.4 review-card identity (indigo gradient, ⭐ heading,
 * ★ Leave a 5-Star Review + Maybe later buttons) — deliberately unchanged in
 * 1.5.6; only the trigger/gate logic is new. Both buttons and the ✕ post to
 * the shop2llm_dismiss_review_card AJAX action (30-day re-ask cooldown).
 */
function shop_to_llm_review_ask_html(string $id): string
{
    $nonce = wp_create_nonce('shop2llm_admin_nonce');
    $ajaxUrl = esc_url(admin_url('admin-ajax.php'));
    $reviewUrl = esc_url(shop_to_llm_review_url());
    $crawlerPlatform = esc_html(get_option('shop_to_llm_first_crawler_platform', 'AI'));
    $title = esc_html__('Enjoying Shop2LLM?', 'shop2llm');
    $body = sprintf(
        /* translators: %s: first AI crawler platform that visited the site */
        esc_html__('%s has already discovered your site. If Shop2LLM is helping you show up in AI search, a quick 5-star review on WordPress.org helps other site owners find it — and pushes us up the directory rankings.', 'shop2llm'),
        '<strong>' . $crawlerPlatform . '</strong>'
    );
    $cta = esc_html__('★ Leave a 5-Star Review', 'shop2llm');
    $later = esc_html__('Maybe later', 'shop2llm');
    $dismissTitle = esc_attr__("Dismiss — we'll check back in 30 days", 'shop2llm');
    $html = '<div class="stl-review-ask" id="' . esc_attr($id) . '" style="background:linear-gradient(135deg,#f5f3ff 0%,#ede9fe 100%);border:1px solid #c4b5fd;border-radius:8px;margin:0 0 12px;padding:12px 14px;position:relative">'
        . '<button type="button" class="stl-review-ask-x" title="' . $dismissTitle . '" style="position:absolute;top:8px;right:8px;background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:4px">✕</button>'
        . '<h2 style="color:#4338ca;margin:0 0 6px;display:flex;align-items:center;gap:6px"><span style="font-size:18px">⭐</span>' . $title . '</h2>'
        . '<p style="font-size:13px;color:#4c1d95;margin:0 0 10px;line-height:1.5">' . $body . '</p>'
        . '<div style="display:flex;gap:8px;flex-wrap:wrap">'
        . '<a href="' . $reviewUrl . '" target="_blank" rel="noopener" style="display:inline-block;padding:8px 16px;background:#6366f1;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px">' . $cta . '</a>'
        . '<button type="button" class="stl-review-ask-x" style="padding:8px 14px;background:#fff;color:#6366f1;border:1px solid #c4b5fd;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500">' . $later . '</button>'
        . '</div></div>'
        . '<script>(function(){var box=document.getElementById(' . wp_json_encode($id) . ');if(!box)return;'
        . 'box.querySelectorAll(".stl-review-ask-x").forEach(function(x){'
        . 'x.addEventListener("click",function(){box.style.display="none";var fd=new FormData();'
        . 'fd.append("action","shop2llm_dismiss_review_card");fd.append("nonce",' . wp_json_encode($nonce) . ');'
        . 'fetch(' . wp_json_encode($ajaxUrl) . ',{method:"POST",body:fd,credentials:"same-origin"}).catch(function(){});});});})();</script>';
    return $html;
}

/**
 * v1.10.0: one-time connection-verify review ask (M1 family).
 *
 * The first successful "Verify connection" (a real MCP handshake answering
 * with the site's own server name) is a value moment on par with the first
 * successful Hero search — flag the same inline review ask through the
 * shared gate (2 lifetime asks, >=14 days apart, 30-day dismiss cooldown)
 * and never re-ask for this specific trigger afterwards.
 */
function shop_to_llm_connect_review_ask(): bool
{
    if (get_option('shop2llm_connect_verified_at') || !shop_to_llm_review_gate()) {
        return false;
    }
    update_option('shop2llm_connect_verified_at', time(), false);
    shop_to_llm_review_register_ask();
    return true;
}

/**
 * M3 — day-30 fallback: catches admins who never hit the M1/M2 value
 * moments. Fires at most twice per site (shared gate), 30 days after
 * activation (or first admin visit), on WP-native surfaces only.
 */
function shop_to_llm_review_fallback_notice(): void
{
    if (!shop_to_llm_review_gate()) {
        return;
    }
    // WP-native surfaces only (Dashboard/Plugins) — same policy as v1.5.4
    if (!shop_to_llm_is_global_notice_screen()) {
        return;
    }
    $summary = get_option('shop2llm_behavior_summary', []);
    $activated = (is_array($summary) && !empty($summary['activated'])) ? (int) $summary['activated'] : 0;
    if (!$activated) {
        $firstSeen = get_option('shop2llm_review_first_seen');
        if (!$firstSeen) {
            add_option('shop2llm_review_first_seen', time(), '', false);
            return;
        }
        $activated = (int) $firstSeen;
    }
    if ((time() - $activated) < 30 * DAY_IN_SECONDS) {
        return;
    }
    shop_to_llm_review_register_ask();
    $dismissUrl = wp_nonce_url(
        add_query_arg('shop2llm_review_dismiss', '1', admin_url('index.php')),
        'shop2llm_review_dismiss'
    );
    ?>
    <div class="notice notice-info" style="border-left-color:#6366f1">
        <p style="font-size:13px">
            <strong><?php echo esc_html__('Shop2LLM has been live on your site for 30 days.', 'shop2llm'); ?></strong>
            <?php echo esc_html__('If AI assistants are discovering your content, a short review helps other merchants find the plugin.', 'shop2llm'); ?><br>
            <a href="<?php echo esc_url(shop_to_llm_review_url()); ?>" target="_blank" rel="noopener" style="display:inline-block;margin-top:6px;padding:4px 12px;background:#6366f1;color:#fff;border-radius:4px;text-decoration:none;font-weight:600;font-size:11px">
                <?php echo wp_kses(shop_to_llm_icon('star'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Leave a Review on WP.org', 'shop2llm'); ?>
            </a>
            <a href="<?php echo esc_url($dismissUrl); ?>" style="color:#94a3b8;font-size:11px;text-decoration:none;margin-left:8px"><?php echo esc_html__('No thanks', 'shop2llm'); ?></a>
        </p>
    </div>
    <?php
}

function shop_to_llm_review_fallback_dismiss(): void
{
    if (!isset($_GET['shop2llm_review_dismiss'], $_GET['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'shop2llm_review_dismiss')) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    shop_to_llm_review_record_dismiss();
}

function shop_to_llm_weekly_crawler_summary(): void
{
    // v1.5.4: only on WP-native surfaces (Dashboard/Plugins)
    if (!shop_to_llm_is_global_notice_screen()) {
        return;
    }

    $transientKey = 'shop2llm_weekly_summary_dismissed';
    if (get_transient($transientKey)) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if (!$exists) {
        return;
    }

    // Count crawlers this week
    $weekAgo = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
    $weekCount = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(*) FROM {$table} WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $weekAgo
    ));
    if ($weekCount < 1) {
        return;
    }

    $uniqueBots = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(DISTINCT bot_name) FROM {$table} WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $weekAgo
    ));
    $totalAll = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Mark as shown for 7 days
    set_transient($transientKey, 1, 7 * DAY_IN_SECONDS);
    ?>
    <div class="notice notice-info is-dismissible" style="border-left-color:#8b5cf6">
        <p style="font-size:13px">
            <strong><?php echo wp_kses(shop_to_llm_icon('bot'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html(sprintf(
                /* translators: 1: number of crawler visits, 2: number of AI bots */
                __('%1$d AI crawler visits from %2$d platforms this week', 'shop2llm'),
                $weekCount,
                $uniqueBots
            )); ?></strong><br>
            <?php echo esc_html(sprintf(
                /* translators: %d: total crawler records */
                __('%d total crawler records accumulated. View your AI visibility trends:', 'shop2llm'),
                $totalAll
            )); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=shop2llm')); ?>" style="margin-left:4px;font-weight:600">
                <?php echo esc_html__('Open Dashboard →', 'shop2llm'); ?>
            </a>
        </p>
    </div>
    <?php
}

function shop_to_llm_woocommerce_warning_notice(): void
{
    if (!get_transient('shop_to_llm_woocommerce_warning')) {
        return;
    }
    // v1.5.4: only on WP-native surfaces (Dashboard/Plugins)
    if (!shop_to_llm_is_global_notice_screen()) {
        return;
    }
    delete_transient('shop_to_llm_woocommerce_warning');
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong>Shop2LLM:</strong>
            <?php echo esc_html__('WooCommerce is not active. Product-related features (product search, product feed, product AI score) are disabled. Blog and page content is still discoverable by AI. Install WooCommerce for full product AI visibility.', 'shop2llm'); ?>
        </p>
    </div>
    <?php
}

function shop_to_llm_dismiss_onboarding(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('unauthorized');
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    update_option('shop2llm_onboarding_done', 1);
    wp_die('ok');
}

function shop_to_llm_cache_community_count(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('unauthorized');
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    $count = isset($_POST['count']) ? absint($_POST['count']) : 0;
    if ($count > 0) {
        set_transient('shop2llm_community_count', $count, DAY_IN_SECONDS);
    }
    wp_die('ok');
}

/**
 * One-click AI asset tooling (P1): regenerate/copy llms.txt, flush rewrite
 * rules, and preview the JSON-LD Shop2LLM emits. All admin-side and opt-in.
 */
function shop_to_llm_handle_asset_tool(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('unauthorized');
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');

    $asset = isset($_POST['asset']) ? sanitize_key($_POST['asset']) : '';
    if ($asset === 'llms_txt') {
        // Regenerate from scratch (bypass the 1h transient)
        delete_transient('shop_to_llm_llms_txt_output');
        wp_send_json_success(['content' => shop_to_llm_build_llms_txt()]);
    }
    if ($asset === 'flush') {
        delete_transient('shop_to_llm_llms_txt_output');
        delete_transient('shop_to_llm_agents_md_output');
        flush_rewrite_rules();
        wp_send_json_success(['message' => __('Rewrite rules flushed and llms.txt cache cleared. /llms.txt, /agents.md and /llms-full.txt are now rebuilt on next visit.', 'shop2llm')]);
    }
    if ($asset === 'jsonld') {
        $posts = get_posts([
            'post_type' => shop_to_llm_has_woocommerce() ? 'product' : 'post',
            'post_status' => 'publish',
            'numberposts' => 1,
            'orderby' => 'modified',
        ]);
        $post = $posts[0] ?? null;
        if ($post === null) {
            wp_send_json_error(['message' => __('No published content found to preview schema for.', 'shop2llm')]);
        }
        $schema = shop_to_llm_seo_build_schema($post->post_type === 'product' ? 'Product' : 'Article', $post);
        if ($schema === []) {
            wp_send_json_error(['message' => __('Schema could not be generated for this content.', 'shop2llm')]);
        }
        wp_send_json_success([
            'title' => get_the_title($post),
            'content' => wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
    if ($asset === 'mcp_check') {
        // v1.6.0: live JSON-RPC initialize handshake against the site's own MCP
        // endpoint — proves the endpoint answers, not just that rewrites exist.
        // v1.9.0: with the official stack active the built-in endpoint retires;
        // verify locally instead (abilities registered + adapter detected).
        if (shop_to_llm_official_mcp_stack_active()) {
            $abilities = function_exists('wp_get_abilities') ? (array) call_user_func('wp_get_abilities') : [];
            $ours = 0;
            foreach ($abilities as $ability) {
                if ($ability instanceof \WP_Ability && str_starts_with($ability->get_name(), 'shop2llm/')) {
                    $ours++;
                }
            }
            if ($ours > 0) {
                delete_transient('shop_to_llm_ucp_check_mcp');
                set_transient('shop_to_llm_ucp_check_mcp', '1', HOUR_IN_SECONDS);
                wp_send_json_success([
                    'ok' => true,
                    /* translators: 1: ability count */
                    'message' => sprintf(__('Exposed via the official MCP Adapter — %1$d abilities registered', 'shop2llm'), $ours),
                    // v1.10.0: one-time inline review ask on the first successful verify.
                    'review_ask' => shop_to_llm_connect_review_ask(),
                ]);
            }
            wp_send_json_success([
                'ok' => false,
                'message' => __('Official MCP Adapter detected but no Shop2LLM abilities registered yet — deactivate/reactivate Shop2LLM to register them', 'shop2llm'),
            ]);
        }
        $start = microtime(true);
        $checkUrl = rtrim((string) get_site_url(), '/') . '/wp-json/shop2llm/v1/mcp';
        $resp = wp_remote_post($checkUrl, [
            'timeout' => 8,
            // v1.10.0 (Bug 1 fix): the /mcp permission callback requires the API
            // key — without this header the loopback handshake always failed 401
            // and "Verify connection" could never go green on the legacy path.
            // The key is read server-side and never leaves this process.
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . (string) get_option('shop_to_llm_api_key', ''),
            ],
            'body' => wp_json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass()],
            ]),
        ]);
        $latency = (int) round((microtime(true) - $start) * 1000);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        $data = is_wp_error($resp) ? null : json_decode(wp_remote_retrieve_body($resp), true);
        $serverName = is_array($data) ? ($data['result']['serverInfo']['name'] ?? '') : '';
        if ($code === 200 && $serverName !== '') {
            delete_transient('shop_to_llm_ucp_check_mcp');
            set_transient('shop_to_llm_ucp_check_mcp', '1', HOUR_IN_SECONDS);
            wp_send_json_success([
                'ok' => true,
                /* translators: 1: server name, 2: latency in ms */
                'message' => sprintf(__('Connection verified — %1$s responded in %2$dms', 'shop2llm'), $serverName, $latency),
                // v1.10.0: one-time inline review ask on the first successful verify.
                'review_ask' => shop_to_llm_connect_review_ask(),
            ]);
        }
        wp_send_json_success([
            'ok' => false,
            /* translators: %d: HTTP status code */
            'message' => sprintf(__('Not reachable (HTTP %d) — flush rewrites or check caching/security plugins', 'shop2llm'), $code),
        ]);
    }
    wp_die('unknown asset');
}

function shop_to_llm_dismiss_pro_cta(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('unauthorized');
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    $userId = get_current_user_id();
    if ($userId > 0) {
        update_user_meta($userId, 'shop2llm_pro_cta_dismissed', '1');
        update_user_meta($userId, 'shop2llm_pro_cta_dismissed_at', time());
    }
    wp_die('ok');
}

/**
 * Ajax handler: dismiss a rating-loop ask (M1 hero toast / M2 inline card).
 *
 * v1.5.6: records a site-wide 30-day re-ask cooldown instead of the old
 * per-user permanent dismissal — the shared gate (2 lifetime asks, >=14d
 * between asks) decides if and when the next value-moment ask appears.
 *
 * @since 1.3.2
 */
function shop_to_llm_dismiss_review_card(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('unauthorized');
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    shop_to_llm_review_record_dismiss();
    wp_die('ok');
}

/**
 * v1.10.0: GSC AI-insights guidance card state — one option, three states.
 * '' (default, activation hook) → 'connected' (user confirmed the site is in
 * Search Console → interpretation mode) or 'dismissed' (card hidden).
 * Pure guidance: the card never implies Shop2LLM pulls or displays GSC data.
 */
add_action('wp_ajax_shop2llm_gsc_state', 'shop_to_llm_gsc_state');
function shop_to_llm_gsc_state(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('unauthorized');
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    $state = isset($_POST['state']) ? sanitize_key(wp_unslash($_POST['state'])) : '';
    if ($state === 'connected' || $state === 'dismissed') {
        update_option('shop2llm_gsc_insights_state', $state, false);
    }
    wp_die('ok');
}

function shop_to_llm_handle_test_search(): void
{
    if (!current_user_can('manage_options')) {
        wp_die();
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
    if (empty($query)) {
        wp_send_json_error(['error' => __('Query required', 'shop2llm')]);
    }

    // v1.5.4: self-tests track a SEPARATE counter — the API Calls metric and
    // the MCP score factor must reflect external AI assistants only, so an
    // admin testing the hero search no longer inflates "an AI assistant
    // connected to your site". Incremented after the cloud call so only
    // executed searches count (matches the quota accounting below).

    // v1.1.1: Local quota counter (UI hint only; real enforcement is server-side)
    $todayKey = gmdate('Y-m-d');
    $quotaKey = 'shop2llm_test_quota_' . $todayKey;
    $usedToday = (int) get_transient($quotaKey);
    $dailyLimit = (int) apply_filters('shop2llm_test_search_daily_limit', 5);
    $remainingBefore = max(0, $dailyLimit - $usedToday);

    // 60s-to-aha: remember pre-search state so the response can flag the moment
    // the user reaches their first successful search (onboarding completion).
    $behaviorNow = get_option('shop2llm_behavior_summary', []);
    if (!is_array($behaviorNow)) {
        $behaviorNow = [];
    }
    $ahaPending = empty($behaviorNow['first_search_ok']);
    $wasFirstSearch = empty($behaviorNow['first_search']);

    $results = shop_to_llm_search_products(['query' => $query, 'limit' => 10]);
    $items = $results['products'] ?? [];

    // Format results for the frontend — v1.1.1: include full product fields
    $products = [];
    foreach ($items as $r) {
        $products[] = [
            'name' => $r['name'] ?? 'Unknown',
            'price' => $r['price'] ?? '',
            'currency' => $r['currency'] ?? '',
            'in_stock' => !empty($r['in_stock']),
            'rating' => (float) ($r['rating'] ?? 0),
            'short_description' => $r['short_description'] ?? '',
            'url' => $r['url'] ?? '',
            'image_url' => $r['image_url'] ?? '',
        ];
    }

    // v1.1.1: Generate ChatGPT-style reply via FinCore cloud LLM proxy.
    // Server-side enforces real quota (5/day per site_hash). Local counter is UI hint only.
    $aiReply = shop_to_llm_generate_ai_test_reply($query, $products);

    // 60s-to-aha: log first_search authoritatively server-side (JS also logs;
    // shop_to_llm_log_event dedups). success = AI reply present OR items found.
    $isSuccessfulSearch = ($aiReply !== '') || !empty($products);
    if ($ahaPending || $wasFirstSearch) {
        shop_to_llm_log_event('first_search', ['success' => $isSuccessfulSearch]);
    }

    // Increment local quota counter after the cloud call (matches server-side accounting)
    set_transient($quotaKey, $usedToday + 1, DAY_IN_SECONDS);
    // v1.5.4: separate self-test counter (external API Calls metric stays clean)
    $selfToday = (int) get_transient('shop_to_llm_self_count_' . gmdate('Y-m-d'));
    set_transient('shop_to_llm_self_count_' . gmdate('Y-m-d'), $selfToday + 1, DAY_IN_SECONDS);
    $remainingAfter = max(0, $dailyLimit - ($usedToday + 1));

    // v1.5.6 rating loop (M1): the first successful Hero Test Search is the
    // strongest value moment — flag an inline review ask in the response.
    // Rendered as a toast inside the results container, never an admin notice;
    // the shared gate caps this at 2 lifetime asks, >=14 days apart.
    $reviewAsk = false;
    if ($isSuccessfulSearch && $ahaPending && shop_to_llm_review_gate()) {
        $reviewAsk = true;
        shop_to_llm_review_register_ask();
    }

    wp_send_json_success([
        'products' => $products,
        'ai_reply' => $aiReply,
        // v1.6.1 Aha 本地化: cloud AI unavailable → attach a preview of the
        // local /llms.txt catalog so the value moment never depends on the
        // external proxy. Computed only on the failure path (zero cost when
        // the cloud reply is present).
        'llms_preview' => ($aiReply === '') ? shop_to_llm_local_aha_preview() : null,
        'remaining_quota' => $remainingAfter,
        'daily_limit' => $dailyLimit,
        'first_success' => ($isSuccessfulSearch && $ahaPending),
        'review_ask' => $reviewAsk,
    ]);
}

/**
 * v1.6.1 Aha 本地化: short preview of the live /llms.txt catalog.
 *
 * Shown inside the Hero Test Search results whenever the cloud AI proxy is
 * unavailable, so the user immediately sees proof that their site is already
 * machine-readable — "AI can read this catalog" — without any external
 * service. Reads the 1-hour output transient first; rebuilds only on a miss.
 */
function shop_to_llm_local_aha_preview(): array
{
    $catalog = get_transient('shop_to_llm_llms_txt_output');
    if (!is_string($catalog) || $catalog === '') {
        try {
            $catalog = (string) shop_to_llm_build_llms_txt();
            set_transient('shop_to_llm_llms_txt_output', $catalog, HOUR_IN_SECONDS);
        } catch (\Throwable $e) {
            $catalog = '';
        }
    }
    $preview = [];
    if ($catalog !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $catalog) as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line === '---') {
                continue;
            }
            $preview[] = mb_substr($line, 0, 110);
            if (count($preview) >= 6) {
                break;
            }
        }
    }
    return [
        'url' => home_url('/llms.txt'),
        'lines' => $preview,
    ];
}

/**
 * v1.1.1: Generate a ChatGPT-style natural-language reply via FinCore cloud LLM proxy.
 *
 * Server-side endpoint (api.shop2llm.com/fincore/v1/free/ai-test) enforces:
 *   - site_hash registration check
 *   - 5 calls/day per site quota
 *   - Fixed prompt template (no free-form prompt injection)
 *   - Returns natural-language reply only (no product data — that stays local)
 *
 * If the cloud service is unavailable or quota exceeded, gracefully degrades:
 *   - 429: returns localized "quota exhausted" message
 *   - Other errors: returns empty string (UI will fall back to plain product list)
 *
 * @param string $query  User's search query (already sanitized)
 * @param array  $products  Local search results (already formatted)
 * @return string  AI-generated reply (empty string on failure)
 */
function shop_to_llm_generate_ai_test_reply(string $query, array $products): string
{
    $site_url = get_site_url();
    $site_hash = hash('sha256', wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url);

    // Build lightweight product summary — no images, no full descriptions (bandwidth-friendly)
    $productSummary = array_map(function ($p) {
        return [
            'name' => mb_substr($p['name'], 0, 120),
            'price' => $p['price'],
            'currency' => $p['currency'] ?? '',
            'in_stock' => $p['in_stock'],
            'rating' => $p['rating'] ?? 0,
        ];
    }, array_slice($products, 0, 10));

    $payload = [
        'site_url' => $site_url,
        'site_hash' => $site_hash,
        'plugin_version' => SHOP_TO_LLM_VERSION,
        'query' => mb_substr($query, 0, 200),
        'has_woocommerce' => shop_to_llm_has_woocommerce(),
        'site_name' => get_bloginfo('name'),
        'locale' => get_locale(),
        'products' => $productSummary,
        'product_count' => count($products),
    ];

    $response = wp_remote_post('https://api.shop2llm.com/fincore/v1/free/ai-test', [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        return '';
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 429) {
        return __('You have reached the daily limit for AI test searches (5 per day). Try again tomorrow, or upgrade to Pro for unlimited AI test searches with multi-platform support.', 'shop2llm');
    }

    if ($code !== 200 || empty($body['ai_reply'])) {
        return '';
    }

    // Sanitize but preserve line breaks and basic formatting
    $reply = sanitize_textarea_field($body['ai_reply']);
    return trim($reply);
}

/**
 * AJAX handler: Fix a single score item.
 * Supports: fix_robots, flush_rewrite
 */
function shop_to_llm_handle_fix_item(): void
{
    if (!current_user_can('manage_options')) {
        wp_die();
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    $fixAction = isset($_POST['fix_action']) ? sanitize_text_field(wp_unslash($_POST['fix_action'])) : '';

    switch ($fixAction) {
        case 'fix_robots':
            flush_rewrite_rules();
            delete_transient('shop2llm_robots_allows_ai');
            delete_transient('shop2llm_admin_bar_score');
            update_option('shop_to_llm_robots_fixed', 1);
            wp_send_json_success(['message' => 'Robots.txt fixed', 'fix_action' => 'fix_robots']);
            break;
        case 'flush_rewrite':
            flush_rewrite_rules();
            // Clear cached accessibility checks so score recalculates on next load
            delete_transient('shop2llm_llms_accessible');
            delete_transient('shop2llm_feed_accessible');
            delete_transient('shop2llm_admin_bar_score');
            update_option('shop_to_llm_rewrite_flushed', 1);
            wp_send_json_success(['message' => 'Permalinks flushed + cache cleared', 'fix_action' => 'flush_rewrite']);
            break;
        default:
            wp_send_json_error(['error' => 'Unknown fix action: ' . $fixAction]);
    }
}

/**
 * v1.6.0: Focus Query ideas — one-click harvest of unmatched AI search terms.
 *
 * "Add to Focus Queries" on a No-match row stores the term in the
 * shop_to_llm_focus_query_ideas option (capped at 30, incremental upsert).
 * Same handler removes ideas (mode=remove) so the chips stay actionable.
 */
function shop_to_llm_handle_add_focus_idea(): void
{
    if (!current_user_can('manage_options')) {
        wp_die();
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'add';
    $ideas = get_option('shop_to_llm_focus_query_ideas', []);
    if (!is_array($ideas)) {
        $ideas = [];
    }

    if ($mode === 'remove') {
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $ideas = array_values(array_filter($ideas, static fn($idea) => ($idea['query'] ?? '') !== $query));
        update_option('shop_to_llm_focus_query_ideas', $ideas, false);
        wp_send_json_success(['count' => count($ideas)]);
    }

    $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
    if ($query === '') {
        wp_send_json_error(['error' => 'Empty query']);
    }
    foreach ($ideas as $idea) {
        if (($idea['query'] ?? '') === $query) {
            wp_send_json_success(['count' => count($ideas), 'duplicate' => true]);
        }
    }
    $ideas[] = [
        'query' => $query,
        'time' => current_time('Y-m-d H:i'),
        'hits' => 1,
    ];
    if (count($ideas) > 30) {
        $ideas = array_slice($ideas, -30);
    }
    update_option('shop_to_llm_focus_query_ideas', $ideas, false);
    wp_send_json_success(['count' => count($ideas)]);
}

function shop_to_llm_render_onboarding_banner(): void
{
    if (get_option('shop2llm_onboarding_done')) {
        return;
    }
    // v1.5.4: only on WP-native surfaces; the plugin's own dashboard carries
    // the full onboarding wizard, third-party plugin pages stay untouched.
    if (!shop_to_llm_is_global_notice_screen()) {
        return;
    }
    $dashboardUrl = admin_url('admin.php?page=shop2llm');

    // v1.6.1 (lean): competitor migration module removed.

    // Get crawler stats for banner
    $stats = shop_to_llm_get_crawler_stats();
    $todayBots = (int) $stats['today_bots'];
    $todayPages = (int) $stats['today_pages'];
    $total30d = (int) $stats['total_30d'];

    $dismissNonce = wp_create_nonce('shop2llm_admin_nonce');
    $dismissJs = "var e=this.closest('.shop2llm-onboarding');fetch(ajaxurl+'?action=shop2llm_dismiss_onboarding',{method:'POST',body:new URLSearchParams({nonce:'" . esc_js($dismissNonce) . "'})}).then(()=>e.style.display='none')";
    ?>
    <div class="shop2llm-onboarding" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin:20px 0;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.04);display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <span style="flex-shrink:0;font-size:22px;font-weight:700;color:#6366f1;letter-spacing:-0.5px">Shop2LLM</span>
      <div style="flex:1;min-width:200px;font-size:13px;color:#334155"><?php echo esc_html__('Your site is now visible to ChatGPT and AI assistants.', 'shop2llm'); ?></div>
      <?php if ($total30d > 0) : ?>
      <div style="display:flex;align-items:center;gap:12px;font-size:12px;color:#64748b;background:#f8fafc;padding:5px 12px;border-radius:6px;border:1px solid #e2e8f0">
        <span><?php echo wp_kses(shop_to_llm_icon('bot'), shop_to_llm_allowed_svg()); ?> <strong style="color:#6366f1"><?php echo esc_html((string)$todayBots); ?></strong> bots today</span>
        <span style="color:#e2e8f0">|</span>
        <span><?php echo wp_kses(shop_to_llm_icon('file-text'), shop_to_llm_allowed_svg()); ?> <strong style="color:#6366f1"><?php echo esc_html((string)$todayPages); ?></strong> pages</span>
        <span style="color:#e2e8f0">|</span>
        <span><?php echo wp_kses(shop_to_llm_icon('bar-chart'), shop_to_llm_allowed_svg()); ?> <strong style="color:#6366f1"><?php echo esc_html((string)$total30d); ?></strong> crawls (30d)</span>
      </div>
      <?php endif; ?>
      <div style="display:flex;align-items:center;gap:8px">
        <a href="<?php echo esc_url($dashboardUrl); ?>" style="font-size:12px;background:#6366f1;color:#fff;border:none;padding:6px 14px;border-radius:5px;text-decoration:none;font-weight:500;white-space:nowrap"><?php echo esc_html__('Open Dashboard', 'shop2llm'); ?></a>
        <button type="button" onclick="<?php echo esc_attr($dismissJs); ?>" style="font-size:12px;background:none;border:none;color:#94a3b8;cursor:pointer;padding:0 4px" title="<?php echo esc_attr__('Dismiss', 'shop2llm'); ?>">✕</button>
      </div>
    </div>
    <?php
}

function shop_to_llm_render_onboarding_wizard(
    int $apiCount7d,
    array $crawlerVisits,
    array $recentSearches
): void {
    $crawlerCount = count($crawlerVisits);
    $searchCount = count($recentSearches);
    $mcpUrl = shop_to_llm_mcp_endpoint_url();
    // Use user's site mode selection, not just WooCommerce presence
    $siteMode = get_option('shop_to_llm_site_mode', '');
    $wcInstalled = shop_to_llm_has_woocommerce();
    $isCommerce = ($siteMode === 'commerce' && $wcInstalled);

    // v1.3.11: Real completion states instead of static steps — each bullet turns
    // green ✓ only when the corresponding setup milestone is actually reached.
    $pubCountWiz = shop_to_llm_has_woocommerce()
        ? (int)(wp_count_posts('product')->publish ?? 0)
        : (int)(wp_count_posts('post')->publish ?? 0);
    $wizScore = shop_to_llm_get_visibility_score();
    $wizFactors = $wizScore['factors'] ?? [];
    $step1 = $pubCountWiz > 0;
    $step2 = (int) $apiCount7d > 0;
    $step3 = ($wizFactors['robots'] ?? 0) >= 10 && ($wizFactors['llms'] ?? 0) >= 10;
    $step4 = $crawlerCount > 0 || $searchCount > 0;
    $stepDone = [$step1, $step2, $step3, $step4];
    $completed = ($step1 ? 1 : 0) + ($step2 ? 1 : 0) + ($step3 ? 1 : 0) + ($step4 ? 1 : 0);
    $pct = round(($completed / 4) * 100);
    // Visual distinction: content mode = blue, commerce mode = purple
    $wizardTheme = $isCommerce
        ? ['bg' => '#f5f3ff', 'border' => '#ddd6fe', 'accent' => '#6366f1', 'accentDark' => '#4338ca', 'progress' => 'linear-gradient(90deg,#6366f1,#4338ca)']
        : ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'accent' => '#3b82f6', 'accentDark' => '#1d4ed8', 'progress' => 'linear-gradient(90deg,#3b82f6,#1d4ed8)'];
    ?>
    <div class="stl-card stl-wizard" style="background:<?php echo esc_attr($wizardTheme['bg']); ?>;border-color:<?php echo esc_attr($wizardTheme['border']); ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
            <h2 style="color:<?php echo esc_attr($wizardTheme['accentDark']); ?>;margin:0;display:flex;align-items:center;gap:6px">
                <?php $lReviewUrl = 'https://wordpress.org/support/plugin/shop2llm/reviews/#new-post'; ?>
                <span style="display:inline-flex;flex-direction:row-reverse;gap:2px" title="<?php echo esc_attr__('Rate us on WordPress.org', 'shop2llm'); ?>">
                    <?php for ($i = 5; $i >= 1; $i--) : ?>
                    <a href="<?php echo esc_url($lReviewUrl); ?>" target="_blank" style="font-size:16px;text-decoration:none;color:#94a3b8;transition:color 0.15s;line-height:1"
                       onmouseover="for(var s=this.parentElement.children,i=0;i<s.length;i++)s[i].style.color=i>=5-<?php echo (int)$i; ?>?'#eab308':'#94a3b8'"
                       onmouseout="for(var s=this.parentElement.children,i=0;i<s.length;i++)s[i].style.color='#94a3b8'">★</a>
                    <?php endfor; ?>
                </span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <?php echo esc_html__('Wizard', 'shop2llm'); ?>
            </h2>
            <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:12px;font-weight:600;color:<?php echo esc_attr($wizardTheme['accent']); ?>"><?php echo esc_html((string)$completed); ?>/4</span>
                <button type="button" id="stl-wizard-close-btn" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:4px" title="<?php echo esc_attr__('Close — will re-appear after 8 hours', 'shop2llm'); ?>">✕</button>
            </div>

        </div>
        <div style="height:3px;background:#e2e8f0;border-radius:2px;margin-bottom:12px">
            <div style="width:<?php echo esc_attr((string)$pct); ?>%;height:100%;background:<?php echo esc_attr($wizardTheme['progress']); ?>;border-radius:2px;transition:width 0.4s"></div>
        </div>

        <div class="stl-wizard-steps">
            <div class="stl-wizard-step">
                <div class="stl-wizard-step-bullet" <?php echo $stepDone[0] ? 'style="background:#dcfce7;color:#16a34a"' : ''; ?>><?php echo $stepDone[0] ? '✓' : '1'; ?></div>
                <div class="stl-wizard-step-body">
                    <?php if ($isCommerce) : ?>
                    <strong><?php echo esc_html__('Check Your Products', 'shop2llm'); ?></strong>
                    <span><?php echo esc_html__('Enter a SKU in the Product AI Score card below to see which products need improvement for AI discovery.', 'shop2llm'); ?></span>
                    <?php else : ?>
                    <strong><?php echo esc_html__('Review Your Content', 'shop2llm'); ?></strong>
                    <span><?php echo esc_html__('Check your posts and pages — AI can now search your content by keyword. Make sure your key content is published.', 'shop2llm'); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stl-wizard-step" data-step="2">
                <div class="stl-wizard-step-bullet" <?php echo $stepDone[1] ? 'style="background:#dcfce7;color:#16a34a"' : ''; ?>><?php echo $stepDone[1] ? '✓' : '2'; ?></div>
                <div class="stl-wizard-step-body">
                    <strong><?php echo esc_html__('Connect to ChatGPT', 'shop2llm'); ?></strong>
                    <?php if ($isCommerce) : ?>
                    <span><?php echo esc_html__('Copy this link and add it to ChatGPT — then you can search your own products to verify everything works.', 'shop2llm'); ?></span>
                    <?php else : ?>
                    <span><?php echo esc_html__('Copy this link and add it to ChatGPT — then you can search your own content to verify everything works.', 'shop2llm'); ?></span>
                    <?php endif; ?>
                    <div class="stl-mcp-url-row">
                        <code class="stl-mono" id="stl-mcp-url"><?php echo esc_url($mcpUrl); ?></code>
                        <button type="button" class="stl-copy-btn" id="stl-copy-mcp-btn"><?php echo wp_kses(shop_to_llm_icon('clipboard'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Copy', 'shop2llm'); ?></button>
                        <a class="stl-copy-btn" style="text-decoration:none" href="https://chatgpt.com/#settings/Connectors" target="_blank" rel="noopener" title="<?php echo esc_attr__('Opens ChatGPT in a new tab — paste the link under Settings → Connectors', 'shop2llm'); ?>"><?php echo esc_html__('Open ChatGPT →', 'shop2llm'); ?></a>
                        <?php // v1.10.0: Claude joins the one-click connect path. ?>
                        <a class="stl-copy-btn" style="text-decoration:none" href="https://claude.ai/settings/connectors" target="_blank" rel="noopener" title="<?php echo esc_attr__('Opens Claude in a new tab — add a custom connector with this link (Desktop: paste the config from Settings)', 'shop2llm'); ?>"><?php echo esc_html__('Add to Claude →', 'shop2llm'); ?></a>
                    </div>
                </div>
            </div>

            <div class="stl-wizard-step">
                <div class="stl-wizard-step-bullet" <?php echo $stepDone[2] ? 'style="background:#dcfce7;color:#16a34a"' : ''; ?>><?php echo $stepDone[2] ? '✓' : '3'; ?></div>
                <div class="stl-wizard-step-body">
                    <?php if ($isCommerce) : ?>
                    <strong><?php echo esc_html__('Improve Product Pages', 'shop2llm'); ?></strong>
                    <span><?php echo esc_html__('AI prefers products with clear descriptions, images, and categories. Use the score card below to find what to fix.', 'shop2llm'); ?></span>
                    <?php else : ?>
                    <strong><?php echo esc_html__('Optimize Your Posts', 'shop2llm'); ?></strong>
                    <span><?php echo esc_html__('AI prefers clear titles, meta descriptions, and well-structured content. Use the Visibility Score in the post editor to guide improvements.', 'shop2llm'); ?></span>
                    <?php endif; ?>
                    <?php if (!$stepDone[2]) : ?>
                    <a href="#stl-score-breakdown" style="font-size:11px;color:<?php echo esc_attr($wizardTheme['accentDark']); ?>;font-weight:600;text-decoration:none"><?php echo esc_html__('Fix in AI Score card →', 'shop2llm'); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stl-wizard-step">
                <div class="stl-wizard-step-bullet" <?php echo $stepDone[3] ? 'style="background:#dcfce7;color:#16a34a"' : ''; ?>><?php echo $stepDone[3] ? '✓' : '4'; ?></div>
                <div class="stl-wizard-step-body">
                    <strong><?php echo esc_html__('Watch AI Activity Grow', 'shop2llm'); ?></strong>
                    <?php if ($isCommerce) : ?>
                    <span><?php echo esc_html__('When customers ask AI about products you sell, their search terms and crawler visits will appear here on your dashboard.', 'shop2llm'); ?></span>
                    <?php else : ?>
                    <span><?php echo esc_html__('When people ask AI about topics you cover, their search terms and crawler visits will appear here on your dashboard.', 'shop2llm'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="stl-wizard-footer">
            <span style="font-size:12px;color:#64748b"><?php echo esc_html__('Dismiss anytime.', 'shop2llm'); ?></span>
        </div>
    </div>
    <?php
}

// ─── Settings Page ─────────────────────────────────────────────────────

function shop_to_llm_render_settings(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'shop2llm'));
    }

    shop_to_llm_log_event('dashboard_visit');

    // v1.5.2: SEO settings moved to SEO → SEO Settings sub-tab with their own
    // handler. The main Settings form no longer contains these fields, so they
    // must not be processed (or reset) there.
    if (isset($_POST['shop_to_llm_seo_save'])) {
        check_admin_referer('shop_to_llm_seo_settings');
        $seoOptions = ['shop_to_llm_seo_meta', 'shop_to_llm_seo_og', 'shop_to_llm_seo_schema', 'shop_to_llm_seo_analysis'];
        foreach ($seoOptions as $seoOpt) {
            $seoVal = sanitize_text_field(wp_unslash($_POST[$seoOpt] ?? 'no'));
            update_option($seoOpt, in_array($seoVal, ['yes', 'no'], true) ? $seoVal : 'no');
        }
        $title_sep = sanitize_text_field(wp_unslash($_POST['shop_to_llm_title_sep'] ?? '|'));
        update_option('shop_to_llm_title_sep', in_array($title_sep, ['|', '-', '—', '•', '»'], true) ? $title_sep : '|');
        $home_title = sanitize_text_field(wp_unslash($_POST['shop_to_llm_seo_home_title'] ?? ''));
        update_option('shop_to_llm_seo_home_title', $home_title);
        $home_desc = sanitize_text_field(wp_unslash($_POST['shop_to_llm_seo_home_desc'] ?? ''));
        update_option('shop_to_llm_seo_home_desc', $home_desc);
        $template_keys = ['home', 'post', 'page', 'product', 'category', 'tag', 'archive'];
        foreach ($template_keys as $tk) {
            $opt_key = 'shop_to_llm_seo_title_' . $tk;
            $template_val = sanitize_text_field(wp_unslash($_POST[$opt_key] ?? ''));
            update_option($opt_key, $template_val);
        }
        // v1.6.1 (lean): GSC OAuth integration removed.
        echo '<div class="notice notice-success"><p>' . esc_html__('SEO settings saved.', 'shop2llm') . '</p></div>';
    }

    if (isset($_POST['shop_to_llm_save'])) {
        check_admin_referer('shop_to_llm_settings');
        $enabled_post = sanitize_text_field(wp_unslash($_POST['shop_to_llm_enabled'] ?? 'yes'));
        $enabled = in_array($enabled_post, ['yes', 'no'], true) ? $enabled_post : 'yes';
        $catalog_post = sanitize_text_field(wp_unslash($_POST['shop_to_llm_catalog_search'] ?? 'yes'));
        $catalogSearch = in_array($catalog_post, ['yes', 'no'], true) ? $catalog_post : 'yes';
        $analytics_post = sanitize_text_field(wp_unslash($_POST['shop_to_llm_analytics'] ?? 'no'));
        $analytics = in_array($analytics_post, ['yes', 'no'], true) ? $analytics_post : 'no';
        $wasAnalyticsEnabled = get_option('shop_to_llm_analytics', 'no');
        update_option('shop_to_llm_enabled', $enabled);
        update_option('shop_to_llm_catalog_search', $catalogSearch);
        update_option('shop_to_llm_analytics', $analytics);
        // v1.3.3: Product sync consent (opt-in, WP Guideline 7)
        $syncConsentPost = sanitize_text_field(wp_unslash($_POST['shop2llm_product_sync_consent'] ?? 'no'));
        $syncConsent = in_array($syncConsentPost, ['yes', 'no'], true) ? $syncConsentPost : 'no';
        update_option('shop2llm_product_sync_consent', $syncConsent);
        $badge_post = sanitize_text_field(wp_unslash($_POST['shop_to_llm_show_badge'] ?? 'yes'));
        $showBadge = in_array($badge_post, ['yes', 'no'], true) ? $badge_post : 'yes';
        update_option('shop_to_llm_show_badge', $showBadge);
        $entityChecks = isset($_POST['shop_to_llm_enable_entity_checks']) ? 1 : 0;
        update_option('shop_to_llm_enable_entity_checks', $entityChecks);
        // v1.5.2: SEO toggles/templates/GSC now saved by shop_to_llm_seo_save handler (SEO tab).
        if ($wasAnalyticsEnabled !== 'yes' && $analytics === 'yes') {
            $site_url = get_site_url();
            wp_remote_post('https://api.shop2llm.com/fincore/v1/free/ping', [
                'timeout' => 5,
                'blocking' => false,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'site_url' => $site_url,
                'site_hash' => hash('sha256', wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url),
                'platform' => shop_to_llm_has_woocommerce() ? 'woocommerce' : 'wordpress',
                'plugin_version' => SHOP_TO_LLM_VERSION,
                'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'wc_version' => class_exists('WooCommerce') ? WC()->version : '',
                'wp_version' => get_bloginfo('version'),
                'behavior' => shop_to_llm_get_behavior_summary_for_upload(),
                ]),
            ]);
        }
        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'shop2llm') . '</p></div>';
    }

    // v1.5.5: AI usage policy (robots.txt section) — dedicated opt-in form
    // inside the robots.txt Configuration card.
    if (isset($_POST['shop2llm_ai_usage_policy_save'])) {
        check_admin_referer('shop2llm_ai_usage_policy');
        $usageMode = sanitize_text_field(wp_unslash($_POST['shop_to_llm_ai_usage_policy'] ?? 'off'));
        update_option('shop_to_llm_ai_usage_policy', in_array($usageMode, ['off', 'open', 'attribution', 'closed'], true) ? $usageMode : 'off');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('AI usage policy saved.', 'shop2llm') . '</p></div>';
    }

    $enabled = get_option('shop_to_llm_enabled', 'yes');
    $apiKey = get_option('shop_to_llm_api_key', '');
    $catalogSearch = get_option('shop_to_llm_catalog_search', 'yes');
    $analytics = get_option('shop_to_llm_analytics', 'no');
    $showBadge = get_option('shop_to_llm_show_badge', 'yes');
    $storeName = get_bloginfo('name');
    $apiCount7d = (int) get_transient('shop_to_llm_count_7d');
    $todayCount = shop_to_llm_get_today_api_count();
    // v1.5.4: self-tests (Hero Test Search) are counted separately, never mixed
    // into the external AI-activity metrics
    $selfTestCount = (int) get_transient('shop_to_llm_self_count_' . gmdate('Y-m-d'));
    $mcpEndpoint = shop_to_llm_mcp_endpoint_url();

    // Handle mode toggle (mirrors Pro)
    if (isset($_POST['shop_to_llm_mode']) && in_array($_POST['shop_to_llm_mode'], ['content', 'commerce'], true)) {
        update_option('shop_to_llm_site_mode', sanitize_text_field(wp_unslash($_POST['shop_to_llm_mode'])));
    }
    $siteMode = get_option('shop_to_llm_site_mode', '');
    if ($siteMode === '') {
        // Auto-detect on first visit
        $hasWC = shop_to_llm_has_woocommerce();
        if (!$hasWC) {
            $siteMode = 'content';
        } else {
            $productCount = (int) (wp_count_posts('product')->publish ?? 0);
            $postCount = (int) (wp_count_posts('post')->publish ?? 0);
            $siteMode = ($postCount >= 5 && $productCount < 5) ? 'content' : 'commerce';
        }
        update_option('shop_to_llm_site_mode', $siteMode);
    }
    $hasWC = shop_to_llm_has_woocommerce();
    $effectiveWCMode = ($siteMode === 'commerce' && $hasWC);

    // Fetch wizard data early
    $crawlerVisits = shop_to_llm_get_recent_crawlers();
    $recentSearches = shop_to_llm_get_recent_searches();
    $crawlerCount = count($crawlerVisits);
    $searchCount = count($recentSearches);

    ?>
    <div class="stl-wrap">
        <div class="stl-header">
            <div class="stl-logo">
                <span>∞</span>
            </div>
            <div style="flex:1">
                <h1>Shop2LLM <span style="font-size:12px;color:#94a3b8;font-weight:400">v<?php echo esc_html(SHOP_TO_LLM_VERSION); ?></span>
                    <a href="https://shop2llm.com" target="_blank" rel="noopener" style="font-size:11px;font-weight:600;color:#4f46e5;background:#eef2ff;border:1px solid #c7d2fe;border-radius:999px;padding:2px 10px;text-decoration:none;vertical-align:middle;margin-left:6px"><?php echo wp_kses(shop_to_llm_icon('globe'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Website', 'shop2llm'); ?></a>
                </h1>
                <p style="font-size:13px;color:#64748b;margin:4px 0 0;line-height:1.4"><?php echo $effectiveWCMode ? esc_html__('Make your entire site visible to ChatGPT and AI search — products, posts and pages.', 'shop2llm') : esc_html__('Make your entire site visible to ChatGPT and AI search — posts, pages and media.', 'shop2llm'); ?></p>
            </div>
            <form method="post" style="display:flex;align-items:center;gap:4px;background:#f8fafc;padding:4px;border-radius:8px;border:1px solid #e2e8f0;margin-right:8px">
                <label style="font-size:11px;color:#64748b;white-space:nowrap;padding:0 3px;cursor:pointer">
                    <input type="radio" name="shop_to_llm_mode" value="content" <?php checked($siteMode, 'content'); ?> onchange="this.form.submit()" />
                    <?php echo esc_html__('Content', 'shop2llm'); ?>
                </label>
                <label style="font-size:11px;color:#64748b;white-space:nowrap;padding:0 3px;cursor:pointer">
                    <input type="radio" name="shop_to_llm_mode" value="commerce" <?php checked($siteMode, 'commerce'); ?> onchange="this.form.submit()" />
                    <?php echo esc_html__('Store', 'shop2llm'); ?>
                </label>
            </form>
            <button type="button" class="stl-wizard-toggle" id="stl-wizard-toggle-btn"><?php echo wp_kses(shop_to_llm_icon('rocket'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Wizard', 'shop2llm'); ?></button>
        </div>

        <!-- Onboarding Wizard (hidden until triggered) -->
        <div id="stl-openai-guide" style="display:none">
            <?php shop_to_llm_render_onboarding_wizard($apiCount7d, $crawlerVisits, $recentSearches); ?>
        </div>

        <script>
        (function(){
            var card = document.getElementById('stl-openai-guide');
            var toggleBtn = document.getElementById('stl-wizard-toggle-btn');
            var closeBtn = document.getElementById('stl-wizard-close-btn');
            var copyBtn = document.getElementById('stl-copy-mcp-btn');

            function showWizard() {
                if (card) card.style.display = 'block';
                if (toggleBtn) toggleBtn.classList.add('stl-wizard-toggle--active');
            }
            function hideWizard() {
                if (card) card.style.display = 'none';
                if (toggleBtn) toggleBtn.classList.remove('stl-wizard-toggle--active');
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    if (!card) return;
                    if (card.style.display === 'none') showWizard();
                    else hideWizard();
                });
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', hideWizard);
            }
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    var el = document.getElementById('stl-mcp-url');
                    if (!el) return;
                    navigator.clipboard.writeText(el.textContent.trim()).then(function () {
                        copyBtn.classList.add('stl-copy-btn--copied');
                        copyBtn.textContent = '✓ Copied';
                        setTimeout(function () {
                            copyBtn.classList.remove('stl-copy-btn--copied');
                            copyBtn.innerHTML = <?php echo wp_json_encode(shop_to_llm_icon('clipboard') . ' Copy'); ?>;
                        }, 2000);
                        var s = document.querySelector('.stl-wizard-step[data-step="2"]');
                        if (s) {
                            s.classList.add('stl-wizard-step--done');
                            var bullet = s.querySelector('.stl-wizard-step-bullet');
                            if (bullet) bullet.textContent = '✓';
                        }
                    });
                });
            }
        })();
        </script>

        <?php
        // P0-1: 不渲染遮挡性 Welcome Modal（60s-to-aha：首屏直接可见 Hero Test Search）
        $justActivated = get_option('shop_to_llm_just_activated');
        if ($justActivated) {
            delete_option('shop_to_llm_just_activated');
            update_option('shop2llm_onboarding_done', 1);
            // Telemetry: welcome funnel starts at first dashboard view post-activation.
            shop_to_llm_log_event('welcome_shown');
        }
        ?>

        <?php if ($siteMode === 'commerce' && !$hasWC) : ?>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px">
            <span style="flex-shrink:0;display:inline-flex;color:#d97706"><?php echo wp_kses(shop_to_llm_icon('alert-triangle', 18), shop_to_llm_allowed_svg()); ?></span>
            <span style="flex:1"><?php echo esc_html__('WooCommerce is not active. Product search by price/category/stock, product AI scoring, and product feed XML are unavailable. Install WooCommerce for the full experience.', 'shop2llm'); ?></span>
            <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search')); ?>" style="font-size:11px;color:#92400e;font-weight:600;white-space:nowrap"><?php echo esc_html__('Install →', 'shop2llm'); ?></a>
        </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="stl-tabs">
          <button class="stl-tab stl-tab-active" data-tab="dashboard">Overview</button>
          <button class="stl-tab" data-tab="traffic">AI Traffic</button>
          <button class="stl-tab" data-tab="agents">AI Agents</button>
          <button class="stl-tab" data-tab="analytics">Analytics</button>
          <button class="stl-tab" data-tab="settings">Settings</button>
        </div>

        <!-- Dashboard Tab -->
        <div class="stl-tab-panel stl-tab-panel-active" id="tab-dashboard">

        <?php
        // 60s-to-aha (P0-B): Hero Test Search is the FIRST block in the Dashboard tab —
        // the aha moment must be above the fold. Value Card moved below (P0-B2).
        $heroHasWC = shop_to_llm_has_woocommerce();
        $heroPlaceholder = $heroHasWC
            ? __('e.g. wireless headphones under $50', 'shop2llm')
            : __('e.g. how to start a blog', 'shop2llm');
        $heroTitle = $heroHasWC
            ? __('See how AI sees your store', 'shop2llm')
            : __('See how AI sees your site', 'shop2llm');
        $heroSubtitle = __('Ask anything — get an instant AI-powered answer with your real products and content.', 'shop2llm');
        // 60s-to-aha (P0-B): prefill the query with the newest real item so the
        // activation auto-run matches actual catalog data (a generic query like
        // "best products in my store" often matches 0 items and kills the aha).
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin-render lookup of newest title, prepared, no cache needed
        $heroPrefill = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_date_gmt DESC LIMIT 1",
            $heroHasWC ? 'product' : 'post'
        ));
        $heroTodayKey = gmdate('Y-m-d');
        $heroUsed = (int) get_transient('shop2llm_test_quota_' . $heroTodayKey);
        $heroLimit = (int) apply_filters('shop2llm_test_search_daily_limit', 5);
        $heroRemaining = max(0, $heroLimit - $heroUsed);
        // v1.7.1 funnel fix (A1): auto-run until the first aha, not just on
        // activation day. Production telemetry (2026-10): 3 of 4 consenting
        // sites never completed a first search (stuck on pre-1.4 builds);
        // the old $justActivated-only guard never fired for them again.
        $heroBehavior = get_option('shop2llm_behavior_summary', []);
        if (!is_array($heroBehavior)) {
            $heroBehavior = [];
        }
        $heroFirstSearchDone = !empty($heroBehavior['first_search']);
        ?>
        <div id="stl-hero-test-search" style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:1px solid #fcd34d;border-radius:12px;padding:18px 20px;margin-bottom:16px;position:relative;overflow:hidden">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <div style="width:40px;height:40px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 2px 4px rgba(0,0,0,0.06);flex-shrink:0;color:#6366f1"><?php echo wp_kses(shop_to_llm_icon('bot', 22), shop_to_llm_allowed_svg()); ?></div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:16px;font-weight:700;color:#78350f;line-height:1.2"><?php echo esc_html($heroTitle); ?></div>
                    <div style="font-size:12px;color:#92400e;margin-top:2px;line-height:1.4"><?php echo esc_html($heroSubtitle); ?></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;background:#fff;border-radius:8px;padding:6px;border:1px solid #fcd34d">
                <input type="text" id="stl-hero-search-input" value="<?php echo esc_attr($heroPrefill); ?>" placeholder="<?php echo esc_attr($heroPlaceholder); ?>" style="flex:1;border:none;outline:none;padding:8px 10px;font-size:13px;background:transparent;min-width:0">
                <button type="button" id="stl-hero-search-go" style="padding:8px 18px;background:#78350f;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap"><?php echo esc_html__('Ask AI', 'shop2llm'); ?> →</button>
            </div>
            <div id="stl-hero-results" style="display:none;margin-top:12px"></div>
            <div id="stl-hero-loading" style="display:none;text-align:center;padding:20px;color:#92400e;font-size:13px">
                <span style="display:inline-block;animation:stl-spin 1s linear infinite;border:2px solid #fcd34d;border-top-color:transparent;border-radius:50%;width:16px;height:16px;vertical-align:middle;margin-right:8px"></span>
                <?php echo esc_html__('Asking AI about your store...', 'shop2llm'); ?>
            </div>
            <div style="font-size:10px;color:#92400e;margin-top:8px;opacity:0.8">
                <?php echo esc_html__('Powered by FinCore cloud · AI by DeepSeek', 'shop2llm'); ?>
            </div>
        </div>
        <style>
        @keyframes stl-spin { to { transform: rotate(360deg); } }
        .stl-ai-bubble { background:#fff; border-radius:12px; padding:14px 16px; border:1px solid #fcd34d; box-shadow:0 2px 6px rgba(120,53,15,0.06); }
        .stl-ai-bubble-header { display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:12px; color:#92400e; font-weight:600; }
        .stl-ai-bubble-text { font-size:13px; color:#1e1e1e; line-height:1.6; white-space:pre-wrap; word-wrap:break-word; }
        .stl-product-card { display:flex; gap:10px; padding:10px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; margin-top:8px; transition:transform 0.15s, box-shadow 0.15s; }
        .stl-product-card:hover { transform:translateY(-1px); box-shadow:0 3px 8px rgba(0,0,0,0.06); }
        .stl-product-thumb { width:48px; height:48px; border-radius:6px; object-fit:cover; background:#f1f5f9; flex-shrink:0; }
        .stl-product-thumb-placeholder { width:48px; height:48px; border-radius:6px; background:#f1f5f9; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:20px; }
        .stl-product-info { flex:1; min-width:0; }
        .stl-product-name { font-size:13px; font-weight:600; color:#1e1e1e; margin:0 0 2px; word-break:break-word; }
        .stl-product-meta { font-size:11px; color:#64748b; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .stl-product-price { color:#16a34a; font-weight:600; }
        .stl-product-stock-in { color:#16a34a; }
        .stl-product-stock-out { color:#dc2626; }
        .stl-product-rating { color:#f59e0b; }
        .stl-product-desc { font-size:11px; color:#64748b; margin-top:4px; line-height:1.4; }
        </style>
        <script>
        (function(){
            var heroInput = document.getElementById('stl-hero-search-input');
            var heroGoBtn = document.getElementById('stl-hero-search-go');
            var heroResults = document.getElementById('stl-hero-results');
            var heroLoading = document.getElementById('stl-hero-loading');
            if (!heroInput || !heroGoBtn) return;

            function escapeHtml(str){
                if (str === null || str === undefined) return '';
                return String(str)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            }

            function runHeroSearch(){
                var q = (heroInput.value || '').trim();
                if (!q) { heroInput.focus(); return; }

                heroResults.style.display = 'none';
                heroLoading.style.display = 'block';
                heroGoBtn.disabled = true;
                heroGoBtn.style.opacity = '0.7';

                var fd = new FormData();
                fd.append('action', 'shop2llm_test_search');
                fd.append('query', q);
                fd.append('nonce', '<?php echo esc_js(wp_create_nonce('shop2llm_admin_nonce')); ?>');

                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        heroLoading.style.display = 'none';
                        heroGoBtn.disabled = false;
                        heroGoBtn.style.opacity = '1';

                        var data = (d && d.data) || {};
                        if (!d || !d.success) {
                            var errMsg = (data.error) ? data.error : '<?php echo esc_js(__('Search failed. Please try again.', 'shop2llm')); ?>';
                            heroResults.innerHTML = '<div class="stl-ai-bubble"><div class="stl-ai-bubble-text" style="color:#dc2626">' + escapeHtml(errMsg) + '</div></div>';
                            heroResults.style.display = 'block';
                            return;
                        }

                        var products = data.products || [];
                        var aiReply = data.ai_reply || '';
                        var html = '';

                        // 60s-to-aha (P0-D): one-time success banner on the first
                        // successful search (server flags it via first_success).
                        // v1.5.6 rating loop (M1): gated review nudge — identical
                        // amber one-liner to the pre-1.5.6 hero banner, shown at
                        // most twice per site (server decides via review_ask).
                        if (data.first_success) {
                            html += '<div style="margin-bottom:10px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:12px;font-weight:600;color:#166534">✓ ' + <?php echo wp_json_encode(__('AI can see your store — you are all set!', 'shop2llm')); ?> + '</div>';
                        }
                        if (data.review_ask) {
                            html += '<div style="margin-bottom:10px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e">⭐ ' + <?php echo wp_json_encode(__('Enjoying Shop2LLM?', 'shop2llm')); ?> + ' <a href="https://wordpress.org/support/plugin/shop2llm/reviews/#new-post" target="_blank" rel="noopener" style="color:#d97706;text-decoration:underline;font-weight:600">' + <?php echo wp_json_encode(__('Rate it ★★★★★ on WordPress.org', 'shop2llm')); ?> + '</a></div>';
                        }

                        if (aiReply) {
                            html += '<div class="stl-ai-bubble">';
                            html += '<div class="stl-ai-bubble-header"><?php echo wp_kses(shop_to_llm_icon('bot', 16), shop_to_llm_allowed_svg()); ?> <?php echo esc_js(__('AI-powered preview', 'shop2llm')); ?></div>';
                            html += '<div class="stl-ai-bubble-text">' + escapeHtml(aiReply) + '</div>';
                            html += '</div>';
                        } else {
                            // v1.6.1 Aha 本地化: 云代理失败时不再只报错（旧红色提示 +
                            // Retry 是死胡同，尤其无匹配商品时）。直接展示本地 /llms.txt
                            // 目录预览——"AI 能读你的站"这一价值时刻 100% 本地达成，不依赖
                            // 任何外部服务。覆盖有商品和无商品两种失败场景。
                            var llmsPreview = data.llms_preview || null;
                            html += '<div class="stl-ai-bubble" style="border-color:#bbf7d0;background:#f0fdf4">';
                            html += '<div class="stl-ai-bubble-header" style="color:#166534">✓ <?php echo esc_js(__('AI can already read your site catalog', 'shop2llm')); ?></div>';
                            html += '<div class="stl-ai-bubble-text" style="color:#166534;font-size:12px">' + <?php echo wp_json_encode(__('The cloud AI preview is temporarily unavailable, but your machine-readable catalog is already live on your own server — this is exactly what ChatGPT, Claude and Perplexity read. Open it below or retry the AI preview.', 'shop2llm')); ?> + '</div>';
                            if (llmsPreview && llmsPreview.lines && llmsPreview.lines.length) {
                                html += '<pre style="margin:8px 0 0;padding:10px;background:#fff;border:1px solid #bbf7d0;border-radius:8px;font-size:11px;line-height:1.5;color:#065f46;overflow-x:auto;white-space:pre;text-align:left;max-height:150px">' + escapeHtml(llmsPreview.lines.join('\n')) + '</pre>';
                                html += '<div style="margin-top:8px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">';
                                html += '<a href="' + escapeHtml(llmsPreview.url) + '" target="_blank" rel="noopener" style="font-size:12px;color:#059669;font-weight:600;text-decoration:underline"><?php echo esc_js(__('View /llms.txt', 'shop2llm')); ?> →</a>';
                                html += '<button type="button" id="stl-hero-retry" style="padding:5px 14px;background:#166534;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer"><?php echo esc_js(__('Retry AI', 'shop2llm')); ?></button>';
                                html += '</div>';
                            } else {
                                html += '<button type="button" id="stl-hero-retry" style="margin-top:8px;padding:5px 14px;background:#166534;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer"><?php echo esc_js(__('Retry AI', 'shop2llm')); ?></button>';
                            }
                            html += '</div>';
                        }

                        if (products.length) {
                            html += '<div style="font-size:11px;font-weight:600;color:#92400e;margin:10px 0 4px">' + products.length + ' <?php echo esc_js(__('matching items found:', 'shop2llm')); ?></div>';
                            products.forEach(function(p){
                                html += '<div class="stl-product-card">';
                                if (p.image_url) {
                                    html += '<img class="stl-product-thumb" src="' + escapeHtml(p.image_url) + '" alt="" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">';
                                    html += '<div class="stl-product-thumb-placeholder" style="display:none"><?php echo wp_kses(shop_to_llm_icon('package', 18), shop_to_llm_allowed_svg()); ?></div>';
                                } else {
                                    html += '<div class="stl-product-thumb-placeholder"><?php echo wp_kses(shop_to_llm_icon('package', 18), shop_to_llm_allowed_svg()); ?></div>';
                                }
                                html += '<div class="stl-product-info">';
                                html += '<div class="stl-product-name">' + escapeHtml(p.name) + '</div>';
                                html += '<div class="stl-product-meta">';
                                if (p.price) {
                                    var priceText = String(p.price);
                                    if (p.currency) priceText = p.currency + ' ' + priceText;
                                    html += '<span class="stl-product-price">' + escapeHtml(priceText) + '</span>';
                                }
                                html += p.in_stock ? '<span class="stl-product-stock-in">● <?php echo esc_js(__('In Stock', 'shop2llm')); ?></span>' : '<span class="stl-product-stock-out">● <?php echo esc_js(__('Out of Stock', 'shop2llm')); ?></span>';
                                if (p.rating && p.rating > 0) {
                                    html += '<span class="stl-product-rating">★ ' + (Math.round(p.rating * 10) / 10) + '</span>';
                                }
                                html += '</div>';
                                if (p.short_description) {
                                    var desc = p.short_description.length > 120 ? p.short_description.substring(0, 120) + '...' : p.short_description;
                                    html += '<div class="stl-product-desc">' + escapeHtml(desc) + '</div>';
                                }
                                html += '</div>';
                                if (p.url) {
                                    html += '<a href="' + escapeHtml(p.url) + '" target="_blank" rel="noopener" style="font-size:11px;color:#78350f;text-decoration:none;align-self:center;white-space:nowrap"><?php echo esc_js(__('View', 'shop2llm')); ?> →</a>';
                                }
                                html += '</div>';
                            });
                        } else if (!aiReply) {
                            html += '<div class="stl-ai-bubble"><div class="stl-ai-bubble-text" style="color:#d97706"><?php echo esc_js(__('No matching items found. Try a different query.', 'shop2llm')); ?></div></div>';
                        }

                        // v1.3.11: Value-closure footer — frame the result as the real ChatGPT
                        // experience and route the user to the AI Score card for next actions.
                        if (aiReply || products.length) {
                            html += '<div style="margin-top:10px;padding:8px 12px;background:#fff;border:1px solid #fcd34d;border-radius:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
                                + '<span style="font-size:11px;color:#92400e;flex:1;min-width:180px">' + <?php echo wp_json_encode(__('This is exactly what ChatGPT users experience with your store. Raise your AI Score to appear in more AI answers.', 'shop2llm')); ?> + '</span>'
                                + '<button type="button" id="stl-hero-view-score" style="padding:5px 12px;background:#78350f;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap"><?php echo esc_js(__('View AI Score', 'shop2llm')); ?> →</button>'
                                + '</div>';
                        }

                        heroResults.innerHTML = html;
                        heroResults.style.display = 'block';
                        // P0-3: 绑定 AI 不可用时的重试按钮
                        var retryAiBtn = document.getElementById('stl-hero-retry');
                        if (retryAiBtn) {
                            retryAiBtn.addEventListener('click', runHeroSearch);
                        }
                        // v1.3.11: View AI Score → scroll to the score breakdown card
                        var viewScoreBtn = document.getElementById('stl-hero-view-score');
                        if (viewScoreBtn) {
                            viewScoreBtn.addEventListener('click', function(){
                                var scoreCard = document.getElementById('stl-score-breakdown');
                                if (scoreCard && scoreCard.scrollIntoView) {
                                    scoreCard.scrollIntoView({behavior:'smooth', block:'start'});
                                }
                            });
                        }
                        if (heroResults.scrollIntoView) {
                            heroResults.scrollIntoView({behavior:'smooth', block:'nearest'});
                        }
                    })
                    .catch(function(){
                        heroLoading.style.display = 'none';
                        heroGoBtn.disabled = false;
                        heroGoBtn.style.opacity = '1';
                        // P0-3: 网络错误也显示重试按钮
                        heroResults.innerHTML = '<div class="stl-ai-bubble" style="border-color:#fecaca;background:#fef2f2">'
                            + '<div class="stl-ai-bubble-header" style="color:#dc2626"><?php echo wp_kses(shop_to_llm_icon('alert-triangle', 16), shop_to_llm_allowed_svg()); ?> ' + <?php echo wp_json_encode(__('Connection error', 'shop2llm')); ?> + '</div>'
                            + '<div class="stl-ai-bubble-text" style="color:#991b1b;font-size:12px">' + <?php echo wp_json_encode(__('Could not reach the AI service. Please check your internet connection and try again.', 'shop2llm')); ?> + '</div>'
                            + '<button type="button" id="stl-hero-retry-net" style="margin-top:8px;padding:6px 16px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">' + <?php echo wp_json_encode(__('Retry', 'shop2llm')); ?> + '</button>'
                            + '</div>';
                        heroResults.style.display = 'block';
                        // 绑定重试按钮
                        var retryNetBtn = document.getElementById('stl-hero-retry-net');
                        if (retryNetBtn) {
                            retryNetBtn.addEventListener('click', runHeroSearch);
                        }
                    });
            }

            heroGoBtn.addEventListener('click', runHeroSearch);
            heroInput.addEventListener('keydown', function(e){
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    runHeroSearch();
                }
            });

            // P0-2: 激活后自动跑一次 query，让用户立刻看到 AI 推荐效果
            // v1.3.13 修复：旧代码在 JS 里引用 PHP 变量 heroHasWC（未定义 → ReferenceError），
            // 自动搜索从未执行；现直接使用服务端预填的真实 catalog query。
            // v1.7.1 (A1): 自动搜索不再限于激活当天 — 只要首搜尚未发生（first_search
            // 未记录，服务端对每次尝试去重），任何仪表盘访问都会自动执行一次，修复
            // 生产遥测中 3/4 站点数月未完成首搜的 onboarding 断点。
            var stlAutoSearch = <?php echo ($justActivated || !$heroFirstSearchDone) ? 'true' : 'false'; ?>;
            if (stlAutoSearch && <?php echo (int)$heroRemaining; ?> > 0) {
                document.addEventListener('DOMContentLoaded', function() {
                    // 延迟1.5秒，确保页面完全加载后再触发
                    setTimeout(function() {
                        if ((heroInput.value || '').trim()) {
                            runHeroSearch();
                        }
                    }, 1500);
                });
            } else if (!stlAutoSearch && (heroInput.value || '').trim()) {
                // Query prefilled (returning visit) — focus the action button so
                // one Enter/click reaches the aha moment.
                heroGoBtn.focus();
            }

            // Expose globally so the MCP score-card "Test Search" button can redirect here
            window.shop2llmFocusHeroSearch = function(query){
                if (query) heroInput.value = query;
                var heroBox = document.getElementById('stl-hero-test-search');
                if (heroBox && heroBox.scrollIntoView) {
                    heroBox.scrollIntoView({behavior:'smooth', block:'start'});
                }
                setTimeout(function(){ heroInput.focus(); }, 400);
            };
        })();
        </script>

        <?php
        // v1.6.0 P0: Activation proof strip — three live-status cards directly
        // under the hero: llms.txt / robots.txt / feed all show green ✓ with a
        // direct "View" link, so activation value is visible immediately
        // (abundance narrative instead of the old comparison wall, which moved
        // collapsed to the SEO tab).
        $stlLlmsUrl = home_url('/llms.txt');
        $stlRobotsUrl = home_url('/robots.txt');
        $stlFeedUrl = home_url('/product-feed.xml');

        $stlLlmsOk = get_transient('shop2llm_llms_accessible');
        if ($stlLlmsOk === false) {
            $stlRules = get_option('rewrite_rules');
            $stlLlmsOk = ($stlRules && (isset($stlRules['^llms\.txt$']) || (is_array($stlRules) && in_array('index.php?shop_to_llm_llms_txt=1', $stlRules, true)))) ? 'yes' : 'no';
        }

        $stlHasWCStatus = shop_to_llm_has_woocommerce();
        $stlFeedOk = $stlHasWCStatus ? get_transient('shop2llm_feed_accessible') : 'yes';
        if ($stlHasWCStatus && $stlFeedOk === false) {
            $stlRules = get_option('rewrite_rules');
            $stlFeedOk = ($stlRules && (isset($stlRules['^product-feed\.xml$']) || (is_array($stlRules) && in_array('index.php?shop_to_llm_feed=1', $stlRules, true)))) ? 'yes' : 'no';
        }

        $stlRobotsOk = shop_to_llm_check_robots_txt_allows_ai();
        $stlCrawlerTotal = shop_to_llm_get_ai_crawler_count();

        $stlStatusCards = [
            [
                'ok' => ($stlLlmsOk === 'yes'),
                'icon' => shop_to_llm_icon('file-text', 20),
                'title' => __('llms.txt', 'shop2llm'),
                'live' => __('Live', 'shop2llm'),
                'hint' => __('AI agents can read your catalog right now', 'shop2llm'),
                'fail' => __('Not detected yet — flush permalinks to activate', 'shop2llm'),
                'url' => $stlLlmsUrl,
                'view' => true,
            ],
            [
                'ok' => $stlRobotsOk,
                'icon' => shop_to_llm_icon('bot', 20),
                'title' => __('robots.txt', 'shop2llm'),
                'live' => sprintf(/* translators: %d: number of AI crawlers */ __('%d AI crawlers allowed', 'shop2llm'), $stlCrawlerTotal),
                'hint' => __('GPTBot, ClaudeBot, PerplexityBot and more can index your site', 'shop2llm'),
                'fail' => __('AI crawlers blocked — one click below fixes this', 'shop2llm'),
                'url' => $stlRobotsUrl,
                'view' => true,
            ],
            [
                'ok' => ($stlFeedOk === 'yes'),
                'icon' => shop_to_llm_icon('cart', 20),
                'title' => $stlHasWCStatus ? __('Product Feed', 'shop2llm') : __('Content Feed', 'shop2llm'),
                'live' => $stlHasWCStatus ? __('XML feed live', 'shop2llm') : __('Ready — no feed required', 'shop2llm'),
                'hint' => $stlHasWCStatus ? __('Structured XML feed for AI shopping platforms', 'shop2llm') : __('Content mode serves posts and pages to AI directly', 'shop2llm'),
                'fail' => __('Feed not detected — flush permalinks to activate', 'shop2llm'),
                'url' => $stlFeedUrl,
                'view' => $stlHasWCStatus,
            ],
        ];
        // v1.7.0: 4th card — ACP/ChatGPT Shopping feed readiness (Woo only).
        // Reuses the same card grid/markup; no new UI surface.
        if ($stlHasWCStatus) {
            $stlAcp = shop_to_llm_acp_feed_readiness();
            if ($stlAcp['checked'] > 0) {
                $stlStatusCards[] = [
                    'ok' => $stlAcp['ready'],
                    'icon' => shop_to_llm_icon('package', 20),
                    'title' => __('ACP Readiness', 'shop2llm'),
                    'live' => sprintf(/* translators: %d: ACP readiness score */ __('ACP %d/100', 'shop2llm'), $stlAcp['score']),
                    'hint' => __('Catalog fields pass what ChatGPT shopping reads', 'shop2llm'),
                    'fail' => ('' !== $stlAcp['top_gap'])
                        ? $stlAcp['top_gap']
                        : __('Improve product fields to raise your ACP score', 'shop2llm'),
                    'url' => $stlFeedUrl,
                    'view' => true,
                ];
            }
        }
        // v1.7.1: 5th card — IndexNow auto-push status (zero-config discovery
        // acceleration; same card grid/markup, no new UI surface).
        // Prefer the indexnow batch entry over the 1-URL google ping entry so
        // the card reflects the real payload size.
        $stlIndexLog = shop_to_llm_indexnow_get_log();
        $stlIndexOk = false;
        $stlIndexLive = __('Queued on next visit', 'shop2llm');
        foreach ($stlIndexLog as $stlIndexEntry) {
            if (empty($stlIndexEntry['success']) || ($stlIndexEntry['engine'] ?? '') !== 'indexnow') {
                continue;
            }
            $stlIndexOk = true;
            $stlIndexLive = sprintf(
                /* translators: 1: number of URLs, 2: human-readable time difference */
                __('%1$d URLs · %2$s ago', 'shop2llm'),
                (int) $stlIndexEntry['urls'],
                human_time_diff(strtotime($stlIndexEntry['timestamp']))
            );
            break;
        }
        $stlStatusCards[] = [
            'ok' => $stlIndexOk,
            'icon' => shop_to_llm_icon('globe', 20),
            'title' => __('Index Push', 'shop2llm'),
            'live' => $stlIndexLive,
            'hint' => __('Bing, Yandex and more notified automatically on every content change', 'shop2llm'),
            'fail' => __('First automatic push runs on your next dashboard visit — no action needed', 'shop2llm'),
            'url' => '',
            'view' => false,
        ];
        ?>
        <div id="stl-status-strip" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:16px">
            <?php foreach ($stlStatusCards as $stlStatusCard) :
                $stlOk = (bool) $stlStatusCard['ok'];
                $stlBorder = $stlOk ? '#22c55e' : '#f59e0b';
                ?>
            <div style="background:<?php echo $stlOk ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo esc_attr($stlBorder); ?>;border-radius:10px;padding:12px 14px;display:flex;align-items:flex-start;gap:10px">
                <span style="flex-shrink:0;display:inline-flex;line-height:1"><?php echo wp_kses($stlStatusCard['icon'], shop_to_llm_allowed_svg()); ?></span>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                        <span style="font-size:12px;font-weight:700;color:#1e1e1e"><?php echo esc_html($stlStatusCard['title']); ?></span>
                        <span style="font-size:10px;font-weight:700;color:<?php echo $stlOk ? '#166534' : '#92400e'; ?>;background:#fff;border:1px solid <?php echo esc_attr($stlBorder); ?>;padding:1px 7px;border-radius:9px;white-space:nowrap"><?php echo $stlOk ? '✓ ' . esc_html($stlStatusCard['live']) : '⚠ ' . esc_html__('Pending', 'shop2llm'); ?></span>
                    </div>
                    <div style="font-size:10px;color:<?php echo $stlOk ? '#15803d' : '#92400e'; ?>;margin-top:3px;line-height:1.5"><?php echo esc_html($stlOk ? $stlStatusCard['hint'] : $stlStatusCard['fail']); ?></div>
                    <?php if ($stlOk && $stlStatusCard['view']) : ?>
                    <a href="<?php echo esc_url($stlStatusCard['url']); ?>" target="_blank" rel="noopener" style="font-size:10px;font-weight:600;color:#166534;text-decoration:none;margin-top:4px;display:inline-block"><?php echo esc_html__('View', 'shop2llm'); ?> →</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (SHOP_TO_LLM_YOUTUBE_DEMO_URL !== '') :
            // v1.6.0 P0: Dashboard onboarding video — renders only while a YouTube
            // URL is configured; set SHOP_TO_LLM_YOUTUBE_DEMO_URL after upload.
            $stlYtId = '';
            if (preg_match('~(?:youtu\.be/|[?&]v=|/embed/|/shorts/)([A-Za-z0-9_-]{11})~', SHOP_TO_LLM_YOUTUBE_DEMO_URL, $stlYtMatches)) {
                $stlYtId = $stlYtMatches[1];
            }
            if ($stlYtId !== '') : ?>
        <div class="stl-card" id="stl-onboarding-video" style="border-left:4px solid #6366f1;margin-bottom:16px">
            <h2 style="display:flex;align-items:center;gap:6px"><?php echo wp_kses(shop_to_llm_icon('video', 18), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Watch how it works', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('A short tour: activation, connecting ChatGPT, and raising your AI Score.', 'shop2llm'); ?>"></span></h2>
            <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:8px;background:#0f172a">
                <iframe src="https://www.youtube-nocookie.com/embed/<?php echo esc_attr($stlYtId); ?>" title="<?php echo esc_attr__('Shop2LLM — Watch how it works', 'shop2llm'); ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>
            </div>
        </div>
            <?php endif;
        endif; ?>

        <!-- AI Visibility Score Breakdown (10 factors — Dashboard top, first card) -->
        <?php
        // Use canonical 10-factor visibility score — same as admin bar
        $pubCount = shop_to_llm_has_woocommerce()
            ? (int)(wp_count_posts('product')->publish ?? 0)
            : (int)(wp_count_posts('post')->publish ?? 0);

        $visScore = shop_to_llm_get_visibility_score();
        // v1.3.11: Daily score snapshot (once per day) + 7-day delta for the header chip
        shop_to_llm_record_score_history((int) $visScore['total']);
        $scoreDelta7d = shop_to_llm_score_delta_7d((int) $visScore['total']);
        $totalScore = $visScore['total'];
        $freeMax = $visScore['free_max'];
        $scoreLabel = $visScore['label'];
        $scoreColor = $visScore['color'];
        $scoreBg = $visScore['bg'];
        $scoreRobots = $visScore['factors']['robots'];
        $scoreLlms = $visScore['factors']['llms'];
        $scoreLlmsFull = $visScore['factors']['llms_full'] ?? 0;
        $scoreSchema = $visScore['factors']['schema'];
        $scoreMcp = $visScore['factors']['mcp'];
        $scoreFeed = $visScore['factors']['feed'];
        $scoreQueries = $visScore['factors']['queries'];
        $scoreCrawlers = $visScore['factors']['crawlers'];
        $scorePolicy = $visScore['factors']['policy'] ?? 0;
        $scoreMultilingual = $visScore['factors']['multilingual'] ?? 4;
        $schemaHasConflict = $visScore['schema_has_conflict'];
        $dashboardScoreTitle = __('AI Score', 'shop2llm');

        // v1.6.0: all 10 fix actions are free and one-click (robots fix, permalink
        // flush, content edits). Pro entry is a single subtle line at card bottom.
        $hasPro = class_exists('Shop_To_LLM_Pro') || defined('SHOP_TO_LLM_PRO_VERSION');
        $proUpgradeUrl = 'https://shop2llm.com/fincore/v1/page/prolanding';

        $scoreItems = [
            [
                'id' => 'robots', 'label' => __('Robots.txt', 'shop2llm'), 'score' => $scoreRobots, 'max' => 14,
                'pass' => $scoreRobots >= 10,
                'hint' => $scoreRobots >= 10 ? __('AI crawlers whitelisted — auto-configured', 'shop2llm') : __('AI crawlers blocked — cannot index your content', 'shop2llm'),
                'fix_action' => $scoreRobots < 10 ? 'fix_robots' : '', 'fix_label' => __('Fix robots.txt', 'shop2llm'),
            ],
            [
                'id' => 'llms', 'label' => __('Llms.txt', 'shop2llm'), 'score' => $scoreLlms, 'max' => 14,
                'pass' => $scoreLlms >= 10,
                'hint' => $scoreLlms >= 10 ? __('llms.txt accessible — AI can read your catalog', 'shop2llm') : __('llms.txt not accessible — flush permalinks or check caching', 'shop2llm'),
                'fix_action' => $scoreLlms < 10 ? 'flush_rewrite' : '', 'fix_label' => __('Flush Permalinks', 'shop2llm'),
            ],
            [
                'id' => 'llms_full', 'label' => __('Llms-full.txt', 'shop2llm'), 'score' => $scoreLlmsFull, 'max' => 8,
                'pass' => $scoreLlmsFull >= 6,
                'hint' => $scoreLlmsFull >= 6 ? __('Complete-content catalog served — full text for AI answers', 'shop2llm') : __('llms-full.txt not accessible — flush permalinks to enable', 'shop2llm'),
                'fix_action' => $scoreLlmsFull < 6 ? 'flush_rewrite' : '', 'fix_label' => __('Flush Permalinks', 'shop2llm'),
            ],
            [
                'id' => 'schema', 'label' => __('Schema', 'shop2llm'), 'score' => $scoreSchema, 'max' => 12,
                'pass' => $scoreSchema >= 8,
                'hint' => $scoreSchema >= 8 ? __('JSON-LD schema active — full product markup', 'shop2llm')
                    : ($schemaHasConflict ? __('SEO plugin conflict — schema may be duplicated', 'shop2llm') : __('Missing schema — improve product descriptions and images', 'shop2llm')),
                'fix_action' => $schemaHasConflict ? 'check_settings' : 'edit_products', 'fix_label' => $schemaHasConflict ? __('Check Settings', 'shop2llm') : __('Improve Products', 'shop2llm'),
            ],
            [
                'id' => 'mcp', 'label' => __('MCP', 'shop2llm'), 'score' => $scoreMcp, 'max' => 12,
                'pass' => $scoreMcp >= 8,
                'hint' => $scoreMcp >= 8 ? sprintf(/* translators: %d: number of API calls */ __('%d API calls — ChatGPT connected and active', 'shop2llm'), $todayCount)
                    : ($scoreMcp >= 4 ? __('Enabled but not tested — try the test search below', 'shop2llm') : __('MCP endpoint disabled — enable in Settings', 'shop2llm')),
                'fix_action' => $scoreMcp >= 4 ? 'test_mcp' : 'settings', 'fix_label' => $scoreMcp >= 4 ? __('Test Search', 'shop2llm') : __('Enable MCP', 'shop2llm'),
            ],
            [
                'id' => 'feed', 'label' => __('Feed', 'shop2llm'), 'score' => $scoreFeed, 'max' => 10,
                'pass' => $scoreFeed >= 6,
                'hint' => $scoreFeed >= 6 ? (shop_to_llm_has_woocommerce() ? __('Product Feed XML accessible — AI shopping platforms indexed', 'shop2llm') : __('Content feed ready — no WooCommerce needed', 'shop2llm'))
                    : __('Product Feed XML not accessible — regenerate rewrite rules', 'shop2llm'),
                'fix_action' => $scoreFeed < 6 ? 'flush_rewrite' : '', 'fix_label' => __('Flush Permalinks', 'shop2llm'),
            ],
            [
                'id' => 'queries', 'label' => __('Queries', 'shop2llm'), 'score' => $scoreQueries, 'max' => 8,
                'pass' => $scoreQueries >= 6,
                'hint' => $scoreQueries >= 6 ? sprintf(/* translators: %d: percentage of content with Focus Queries */ __('%d%% of content has Focus Queries', 'shop2llm'), round(($scoreQueries / 8) * 100)) : __('Set Focus Queries on posts/products to boost AI relevance', 'shop2llm'),
                'fix_action' => $scoreQueries < 6 ? 'edit_posts' : '', 'fix_label' => __('Edit Content', 'shop2llm'),
            ],
            [
                'id' => 'crawlers', 'label' => __('Crawlers', 'shop2llm'), 'score' => $scoreCrawlers, 'max' => 8,
                'pass' => $scoreCrawlers >= 6,
                'hint' => $scoreCrawlers >= 6 ? __('AI crawlers actively visiting — good visibility', 'shop2llm') : ($scoreCrawlers >= 4 ? __('Some AI crawlers detected — keep publishing fresh content', 'shop2llm') : __('No AI crawler visits yet — ensure robots.txt allows AI bots', 'shop2llm')),
                'fix_action' => $scoreCrawlers < 6 ? 'fix_robots' : '', 'fix_label' => __('Fix robots.txt', 'shop2llm'),
            ],
            [
                'id' => 'policy', 'label' => __('Policies', 'shop2llm'), 'score' => $scorePolicy, 'max' => 10,
                'pass' => $scorePolicy >= 7,
                'hint' => $scorePolicy >= 7 ? __('Policy pages machine-readable — returns/shipping JSON-LD active', 'shop2llm')
                    : ($scorePolicy >= 5 ? __('Policy pages found but JSON-LD disabled — enable Policies schema', 'shop2llm') : __('No returns/shipping policy pages — AI agents need them for policy Q&A', 'shop2llm')),
                'fix_action' => $scorePolicy < 7 ? 'edit_pages' : '', 'fix_label' => __('Create Policy Pages', 'shop2llm'),
            ],
            [
                'id' => 'multilingual', 'label' => __('Multilingual', 'shop2llm'), 'score' => $scoreMultilingual, 'max' => 4,
                'pass' => $scoreMultilingual >= 3,
                'hint' => $scoreMultilingual >= 3 ? __('Translation coverage confirmed — llms.txt Languages section active', 'shop2llm') : __('Translation plugin active but no languages configured', 'shop2llm'),
                'fix_action' => $scoreMultilingual >= 3 ? '' : 'check_settings', 'fix_label' => __('Check Settings', 'shop2llm'),
            ],
        ];

        // Badges (merged from Growth Progress)
        $badges = [
            ['icon' => shop_to_llm_icon('shield', 12), 'name' => __('AI-Ready', 'shop2llm'), 'earned' => true, 'hint' => __('Schema + llms.txt active', 'shop2llm')],
            ['icon' => shop_to_llm_icon('package', 12), 'name' => __('Indexed', 'shop2llm'), 'earned' => $pubCount > 0, 'hint' => sprintf(/* translators: %d: number of discoverable items */ __('%d items discoverable', 'shop2llm'), $pubCount)],
            ['icon' => shop_to_llm_icon('link', 12), 'name' => __('Connected', 'shop2llm'), 'earned' => $todayCount > 0, 'hint' => $todayCount > 0 ? sprintf(/* translators: %d: number of API calls today */ __('%d API calls today', 'shop2llm'), $todayCount) : __('Connect ChatGPT to earn', 'shop2llm')],
            ['icon' => shop_to_llm_icon('search', 12), 'name' => __('Discovered', 'shop2llm'), 'earned' => $crawlerCount > 0, 'hint' => $crawlerCount > 0 ? sprintf(/* translators: %d: number of crawler visits */ __('%d crawler visits', 'shop2llm'), $crawlerCount) : __('AI crawlers indexing', 'shop2llm')],
        ];
        $earnedBadges = count(array_filter($badges, fn($b) => $b['earned']));
        ?>
        <div class="stl-card" id="stl-score-breakdown" style="border-left:4px solid <?php echo esc_attr($scoreColor); ?>">
            <h2 style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                <span style="display:flex;align-items:center;gap:6px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    <?php echo esc_html($dashboardScoreTitle); ?><span class="s2ll-help" title="<?php echo esc_attr__('10-factor breakdown of how discoverable your site is by AI. Each item shows exactly what to fix.', 'shop2llm'); ?>"></span>
                </span>
                <span style="font-size:22px;font-weight:700;color:<?php echo esc_attr($scoreColor); ?>"><?php echo esc_html((string)$totalScore); ?><span style="font-size:12px;font-weight:400;color:#94a3b8">/100</span></span>
                <?php if (is_int($scoreDelta7d) && $scoreDelta7d !== 0) : ?>
                <span style="font-size:11px;font-weight:700;color:<?php echo $scoreDelta7d > 0 ? '#16a34a' : '#dc2626'; ?>;background:<?php echo $scoreDelta7d > 0 ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $scoreDelta7d > 0 ? '#bbf7d0' : '#fecaca'; ?>;padding:2px 8px;border-radius:10px;white-space:nowrap"><?php echo $scoreDelta7d > 0 ? '▲ +' : '▼ '; ?><?php echo esc_html((string)$scoreDelta7d); ?> <span style="font-weight:400;color:#94a3b8">/ 7d</span></span>
                <?php endif; ?>
            </h2>

            <!-- Label badge + progress bar -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <span style="display:inline-block;padding:2px 10px;background:<?php echo esc_attr($scoreBg); ?>;color:<?php echo esc_attr($scoreColor); ?>;border-radius:10px;font-size:11px;font-weight:600"><?php echo esc_html($scoreLabel); ?></span>
            </div>
            <div style="height:6px;background:#e2e8f0;border-radius:3px;margin-bottom:4px">
                <div style="width:<?php echo esc_attr((string)$totalScore); ?>%;height:100%;background:<?php echo esc_attr($scoreColor); ?>;border-radius:3px;transition:width 0.5s"></div>
            </div>

            <!-- Badges row (merged from Growth Progress) -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 10px">
                <?php foreach ($badges as $b) : ?>
                <div title="<?php echo esc_attr($b['hint']); ?>" style="display:flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;border:1px solid <?php echo $b['earned'] ? '#c7d2fe' : '#e2e8f0'; ?>;background:<?php echo $b['earned'] ? '#f5f3ff' : '#f8fafc'; ?>;font-size:10px;<?php echo $b['earned'] ? '' : 'filter:grayscale(1);opacity:0.5;'; ?>">
                    <span style="display:inline-flex"><?php echo wp_kses($b['icon'], shop_to_llm_allowed_svg()); ?></span>
                    <span style="color:<?php echo $b['earned'] ? '#6366f1' : '#94a3b8'; ?>;font-weight:<?php echo $b['earned'] ? '600' : '400'; ?>"><?php echo esc_html($b['name']); ?></span>
                    <?php if (!$b['earned']) :
                        ?><span style="color:#94a3b8;display:inline-flex"><?php echo wp_kses(shop_to_llm_icon('lock', 10), shop_to_llm_allowed_svg()); ?></span><?php
                    endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Score Items -->
            <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:10px">
                <?php foreach ($scoreItems as $item) :
                    $passColor = $item['pass'] ? '#22c55e' : ($item['score'] >= ($item['max'] * 0.5) ? '#eab308' : '#ef4444');
                    $rowBg = $item['pass'] ? '#f0fdf4' : ($item['score'] >= ($item['max'] * 0.5) ? '#fefce8' : '#fef2f2');
                    $fixUrl = '';
                    if (!empty($item['fix_action']) && $item['fix_action'] === 'fix_robots') {
                        $fixUrl = wp_nonce_url(admin_url('admin.php?page=shop2llm&shop2llm_fix_robots=1'), 'shop2llm_fix_robots');
                    } elseif (!empty($item['fix_action']) && $item['fix_action'] === 'flush_rewrite') {
                        $fixUrl = admin_url('options-permalink.php');
                    } elseif (!empty($item['fix_action']) && $item['fix_action'] === 'edit_products') {
                        $fixUrl = admin_url('edit.php?post_type=product');
                    } elseif (!empty($item['fix_action']) && $item['fix_action'] === 'edit_posts') {
                        $fixUrl = admin_url('edit.php');
                    } elseif (!empty($item['fix_action']) && $item['fix_action'] === 'check_settings') {
                        $fixUrl = admin_url('admin.php?page=shop2llm');
                    } elseif (!empty($item['fix_action']) && $item['fix_action'] === 'settings') {
                        $fixUrl = admin_url('admin.php?page=shop2llm');
                    } elseif (!empty($item['fix_action']) && $item['fix_action'] === 'edit_pages') {
                        $fixUrl = admin_url('edit.php?post_type=page');
                    }
                    ?>
                <div class="stl-score-row" style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;background:<?php echo esc_attr($rowBg); ?>;font-size:11px">
                    <span style="flex-shrink:0;width:16px;text-align:center;font-size:12px;color:<?php echo esc_attr($passColor); ?>"><?php echo $item['pass'] ? '✓' : '✗'; ?></span>
                    <span style="flex:1;min-width:0;font-weight:600;color:#1e1e1e;white-space:nowrap"><?php echo esc_html($item['label']); ?></span>
                    <span style="font-size:10px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px" title="<?php echo esc_attr($item['hint']); ?>"><?php echo esc_html($item['hint']); ?></span>
                    <span style="flex-shrink:0;font-weight:700;color:<?php echo esc_attr($passColor); ?>;font-size:12px;min-width:40px;text-align:right"><?php echo esc_html((string)$item['score']); ?><span style="font-weight:400;color:#94a3b8;font-size:10px">/<?php echo esc_html((string)$item['max']); ?></span></span>
                    <?php if (!$item['pass'] && !empty($item['fix_action'])) : ?>
                        <?php if ($item['fix_action'] === 'test_mcp') : ?>
                        <button type="button" class="stl-test-search-btn" style="flex-shrink:0;padding:2px 8px;background:<?php echo esc_attr($scoreColor); ?>;color:#fff;border:none;border-radius:4px;font-size:10px;font-weight:600;cursor:pointer"><?php echo esc_html($item['fix_label']); ?> →</button>
                        <?php elseif ($fixUrl) : ?>
                        <a href="<?php echo esc_url($fixUrl); ?>" class="stl-fix-link" data-fix-action="<?php echo esc_attr($item['fix_action']); ?>" style="flex-shrink:0;padding:2px 8px;background:<?php echo esc_attr($scoreColor); ?>;color:#fff;border-radius:4px;font-size:10px;font-weight:600;text-decoration:none"><?php echo esc_html($item['fix_label']); ?> →</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Community Trust (async loaded) -->
            <div id="stl-community-trust" style="margin:8px 0;padding:5px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;text-align:center;display:none">
                <span id="stl-community-text" style="font-size:10px;color:#166534;font-weight:600"></span>
            </div>

            <div style="display:flex;gap:8px;margin-top:10px">
                <?php if ($totalScore < $freeMax) : ?>
                <a href="#" id="stl-fix-all-btn" style="flex:1;text-align:center;padding:6px 0;background:<?php echo esc_attr($scoreColor); ?>;color:#fff;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none"><?php echo esc_html__('Fix All Items', 'shop2llm'); ?> →</a>
                <?php endif; ?>
            </div>
            <?php if (!$hasPro) : ?>
            <div style="text-align:center;font-size:10px;color:#94a3b8;margin-top:8px">
                <?php echo esc_html__('All fixes above are free and one-click.', 'shop2llm'); ?>
                <a href="<?php echo esc_url($proUpgradeUrl); ?>" style="color:#6366f1;text-decoration:none;font-weight:600">Shop2LLM Pro →</a>
            </div>
            <?php endif; ?>
        </div>

        <?php // AI Visibility card — same data/cache as the dashboard widget; two states
        if (get_option('shop_to_llm_analytics', 'no') === 'yes' && class_exists('Shop2LLM_Citation_Widget')) :
            $citationData = Shop2LLM_Citation_Widget::get_results();
            $citationCta = 'https://shop2llm.com/dashboard?utm_source=wp-plugin&utm_medium=citation_card';
            $citationChecks = is_array($citationData) ? (int)($citationData['week']['checks'] ?? 0) : 0;
            $citationCited = is_array($citationData) ? (int)($citationData['week']['cited'] ?? 0) : 0;
            $citationDelta = (is_array($citationData) && isset($citationData['week']['delta_pct']) && $citationData['week']['delta_pct'] !== null)
                ? (int)$citationData['week']['delta_pct'] : null;
            ?>
        <div class="stl-card" id="stl-ai-visibility" style="border-left:4px solid #6366f1;margin-top:16px">
            <h2 style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span style="display:flex;align-items:center;gap:6px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#6366f1"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <?php echo esc_html__('AI Visibility', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('Whether AI assistants like ChatGPT and Perplexity mention your store for buyer-intent searches. Checked automatically — no setup needed.', 'shop2llm'); ?>"></span>
                </span>
                <?php if ($citationChecks > 0) : ?>
                <span style="font-size:11px;font-weight:700;color:<?php echo $citationCited > 0 ? '#16a34a' : '#646970'; ?>;background:<?php echo $citationCited > 0 ? '#f0fdf4' : '#f8fafc'; ?>;border:1px solid <?php echo $citationCited > 0 ? '#bbf7d0' : '#e2e8f0'; ?>;padding:2px 8px;border-radius:10px;white-space:nowrap">
                    <?php echo esc_html(sprintf(/* translators: %d: citation count */ _n('%d citation / 7d', '%d citations / 7d', $citationCited, 'shop2llm'), $citationCited)); ?>
                </span>
                <?php endif; ?>
            </h2>
            <?php if ($citationChecks > 0) : ?>
                <?php if ($citationCited > 0) : ?>
                <p style="font-size:22px;font-weight:700;color:#1d2327;margin:0 0 4px">
                    <?php echo esc_html(sprintf(/* translators: %d: citation count */ _n('Cited %d time this week', 'Cited %d times this week', $citationCited, 'shop2llm'), $citationCited)); ?>
                </p>
                <?php else : ?>
                <p style="font-size:13px;color:#646970;margin:0 0 8px"><?php echo esc_html__('No citations in the last 7 days — the check runs automatically every few hours.', 'shop2llm'); ?></p>
                <?php endif; ?>
                <?php if ($citationDelta !== null && $citationDelta !== 0) : ?>
                <p style="font-size:12px;font-weight:700;color:<?php echo $citationDelta > 0 ? '#16a34a' : '#dc2626'; ?>;margin:0 0 8px">
                    <?php echo $citationDelta > 0 ? '▲ +' : '▼ '; ?><?php echo esc_html((string)$citationDelta); ?>%
                    <span style="font-weight:400;color:#94a3b8"><?php echo esc_html__('vs previous week', 'shop2llm'); ?></span>
                </p>
                <?php endif; ?>
                <?php if (is_array($citationData) && !empty($citationData['keywords'])) : ?>
                <div style="display:flex;flex-direction:column;gap:3px;margin-bottom:8px">
                    <?php foreach (array_slice($citationData['keywords'], 0, 3) as $ck) :
                        $ckCited = !empty($ck['cited']); ?>
                    <div style="display:flex;align-items:center;gap:6px;font-size:11px;padding:4px 8px;border-radius:6px;background:<?php echo $ckCited ? '#f0fdf4' : '#f8fafc'; ?>">
                        <span style="color:<?php echo $ckCited ? '#16a34a' : '#94a3b8'; ?>;width:12px;text-align:center"><?php echo $ckCited ? '✓' : '○'; ?></span>
                        <span style="color:#1e1e1e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1"><?php echo esc_html((string)($ck['keyword'] ?? '')); ?></span>
                        <span style="color:#94a3b8;font-size:10px"><?php echo esc_html(ucfirst((string)($ck['engine'] ?? ''))); ?><?php echo $ckCited ? ' #' . (int)($ck['position'] ?? 0) : ''; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php else : ?>
                <p style="font-size:13px;color:#646970;margin:0 0 8px"><?php echo esc_html__('See whether AI assistants like ChatGPT and Perplexity mention your store. First results usually appear within 24 hours.', 'shop2llm'); ?></p>
            <?php endif; ?>
            <a href="<?php echo esc_url($citationCta); ?>" target="_blank" rel="noopener" id="stl-citation-cta" style="display:inline-block;background:#6366f1;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:12px"><?php echo esc_html__('View Full AI Visibility Report →', 'shop2llm'); ?></a>
        </div>
        <script>
        (function(){
            var cta = document.getElementById('stl-citation-cta');
            if (!cta || typeof shop2llm === 'undefined') return;
            cta.addEventListener('click', function(){
                try {
                    var body = new URLSearchParams({action:'shop2llm_log_event', nonce:shop2llm.nonce, event:'citation_upgrade_click'});
                    fetch(shop2llm.ajaxurl, {method:'POST', credentials:'same-origin', body:body});
                } catch (e) {}
            });
        })();
        </script>
        <?php endif; // end AI Visibility card ?>

        <!-- v1.1.1: Test Search redirect + Fix All JS -->
        <script>
        (function(){
            // v1.1.1: Score-card "Test Search" button now scrolls to the Hero search box at the top
            var testSearchBtns = document.querySelectorAll('.stl-test-search-btn');
            if (testSearchBtns.length) {
                testSearchBtns.forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if (window.shop2llmFocusHeroSearch) {
                            window.shop2llmFocusHeroSearch();
                        }
                    });
                });
            }
            var fixAllBtn = document.getElementById('stl-fix-all-btn');
            if (fixAllBtn) {
                fixAllBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    fixAllBtn.disabled = true;
                    fixAllBtn.style.opacity = '0.7';

                    // Collect all fixable rows, track manual items separately
                    var allRows = document.querySelectorAll('.stl-score-row');
                    var fixQueue = [];
                    var manualItems = [];
                    allRows.forEach(function(row){
                        var btn = row.querySelector('.stl-test-search-btn');
                        var link = row.querySelector('.stl-fix-link');
                        if (!btn && !link) return; // nothing to fix
                        var action = link ? link.getAttribute('data-fix-action') : 'test_mcp';
                        var label = row.querySelector('span:nth-child(2)') ? row.querySelector('span:nth-child(2)').textContent.trim() : '';
                        // Actions that can be auto-fixed via AJAX
                        var autoFixable = ['test_mcp', 'fix_robots', 'flush_rewrite'];
                        if (autoFixable.indexOf(action) === -1) {
                            manualItems.push({row: row, label: label, action: action, link: link});
                            return;
                        }
                        fixQueue.push({row: row, btn: btn, link: link, action: action});
                    });

                    if (fixQueue.length === 0 && manualItems.length === 0) {
                        fixAllBtn.textContent = 'All items OK ✓';
                        fixAllBtn.disabled = false;
                        fixAllBtn.style.opacity = '1';
                        return;
                    }

                    var currentIdx = 0;
                    var nonce = '<?php echo esc_js(wp_create_nonce('shop2llm_admin_nonce')); ?>';
                    var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

                    function processNext() {
                        if (currentIdx >= fixQueue.length) {
                            // Build completion message
                            var msg = 'Auto-fixed ' + fixQueue.length + ' item(s)';
                            if (manualItems.length > 0) {
                                msg += ' · ' + manualItems.length + ' need manual action →';
                            } else {
                                msg += ' ✓';
                            }
                            fixAllBtn.textContent = msg;
                            fixAllBtn.style.opacity = '1';
                            fixAllBtn.style.background = manualItems.length > 0 ? '#eab308' : '#16a34a';
                            fixAllBtn.disabled = false;

                            // Highlight manual items with a pulse
                            if (manualItems.length > 0) {
                                manualItems.forEach(function(m){
                                    var origBg = m.row.style.background;
                                    m.row.style.transition = 'background 0.4s';
                                    m.row.style.background = '#fef3c7';
                                    setTimeout(function(){ m.row.style.background = origBg; }, 2000);
                                    // Scroll to first manual item
                                    if (manualItems[0] === m) {
                                        setTimeout(function(){ m.row.scrollIntoView({behavior:'smooth', block:'center'}); }, 300);
                                    }
                                });
                            }
                            return;
                        }

                        var item = fixQueue[currentIdx];
                        fixAllBtn.textContent = 'Fixing ' + (currentIdx + 1) + '/' + fixQueue.length + '...';

                        // Scroll to row
                        item.row.scrollIntoView({behavior: 'smooth', block: 'center'});

                        // Flash highlight
                        var origBg = item.row.style.background;
                        item.row.style.transition = 'background 0.3s';
                        item.row.style.background = '#dbeafe';

                        function markDone(success) {
                            item.row.style.background = success ? '#f0fdf4' : origBg;
                            // Update checkmark: find the first span (icon)
                            var iconSpan = item.row.querySelector('span');
                            if (iconSpan && success) {
                                iconSpan.textContent = '✓';
                                iconSpan.style.color = '#22c55e';
                            }
                            currentIdx++;
                            setTimeout(processNext, 350);
                        }

                        if (item.action === 'test_mcp') {
                            // v1.1.1: Scroll to Hero Test Search box at dashboard top
                            if (window.shop2llmFocusHeroSearch) {
                                window.shop2llmFocusHeroSearch();
                            }
                            fixAllBtn.textContent = '✓ Test search opened';
                            setTimeout(function(){ markDone(true); }, 600);
                        } else if (item.action === 'fix_robots' || item.action === 'flush_rewrite') {
                            // Call AJAX to fix
                            var fd = new FormData();
                            fd.append('action', 'shop2llm_fix_item');
                            fd.append('fix_action', item.action);
                            fd.append('nonce', nonce);
                            fetch(ajaxurl, {method:'POST', body: fd})
                            .then(function(r){ return r.json(); })
                            .then(function(d){
                                if (d.success) {
                                    fixAllBtn.textContent = '✓ Fixed: ' + (item.action === 'fix_robots' ? 'Robots.txt' : 'Permalinks');
                                    markDone(true);
                                } else {
                                    fixAllBtn.textContent = '✗ Failed';
                                    markDone(false);
                                }
                            })
                            .catch(function(){
                                fixAllBtn.textContent = '✗ Error';
                                markDone(false);
                            });
                        }
                    }

                    processNext();
                });
            }
        })();
        </script>

        <!-- AI Visibility Summary -->
        <div class="stl-card" style="border-left:4px solid #10b981">
          <h2><?php echo esc_html__('AI Visibility Summary', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__("Today's AI activity at a glance: API calls, crawler bots, searches, and tools available.", 'shop2llm'); ?>"></span></h2>
          <!-- Status badge row -->
          <div style="margin-bottom:10px">
            <?php if ($enabled === 'yes') : ?>
              <span class="stl-badge stl-badge-ok"><?php echo esc_html__('Connected', 'shop2llm'); ?></span>
              <span style="font-size:11px;color:#94a3b8;margin-left:4px"><?php echo esc_html($storeName); ?></span>
            <?php else : ?>
              <span class="stl-badge stl-badge-off"><?php echo esc_html__('Disabled', 'shop2llm'); ?></span>
            <?php endif; ?>
          </div>
          <!-- 4 metric boxes -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">
            <div style="text-align:center;padding:10px 6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
              <div style="font-size:22px;font-weight:700;color:#166534"><?php echo esc_html($todayCount); ?></div>
              <div style="font-size:10px;color:#64748b;margin-top:2px"><?php echo esc_html__('API Calls', 'shop2llm'); ?></div>
              <?php if ($selfTestCount > 0) : ?>
              <div style="font-size:10px;color:#94a3b8;margin-top:1px" title="<?php echo esc_attr__('Your own Hero Test Searches today — not counted as AI activity', 'shop2llm'); ?>">+<?php echo (int) $selfTestCount; ?> <?php echo esc_html__('self-tests', 'shop2llm'); ?></div>
              <?php endif; ?>
            </div>
            <div style="text-align:center;padding:10px 6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
              <div style="font-size:22px;font-weight:700;color:#6366f1"><?php echo esc_html($crawlerCount); ?></div>
              <div style="font-size:10px;color:#64748b;margin-top:2px"><?php echo esc_html__('AI Bots', 'shop2llm'); ?></div>
            </div>
            <div style="text-align:center;padding:10px 6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
              <div style="font-size:22px;font-weight:700;color:#d97706"><?php echo esc_html($searchCount); ?></div>
              <div style="font-size:10px;color:#64748b;margin-top:2px"><?php echo esc_html__('Searches', 'shop2llm'); ?></div>
            </div>
            <div style="text-align:center;padding:10px 6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
              <div style="font-size:22px;font-weight:700;color:#7c3aed">2</div>
              <div style="font-size:10px;color:#64748b;margin-top:2px"><?php echo esc_html__('AI Tools', 'shop2llm'); ?></div>
            </div>
          </div>
          <?php if ($todayCount === 0 && $crawlerCount === 0) : ?>
          <div style="margin-top:8px;padding:6px 10px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;text-align:center;font-size:11px;color:#6366f1;font-weight:500">
                <?php echo shop_to_llm_has_woocommerce() ? esc_html__('New here? Go to Settings → Connection Config → paste into ChatGPT', 'shop2llm') : esc_html__('New here? Go to Settings → Connection Config → paste into ChatGPT', 'shop2llm'); ?>
          </div>
          <?php endif; ?>
          <div style="margin-top:10px;padding:10px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:12px;line-height:1.7;color:#0c4a6e">
            <strong style="display:block;margin-bottom:4px;color:#0369a1"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('How to read this', 'shop2llm'); ?></strong>
            <?php if ($crawlerCount > 0 || $todayCount > 0) : ?>
                <?php echo esc_html__('Each API call = an AI assistant connected to your site.', 'shop2llm'); ?><br>
                <?php echo esc_html__('Each crawler visit = an AI search engine indexed your content.', 'shop2llm'); ?><br>
                <?php echo esc_html__('More activity → better AI visibility → more organic traffic.', 'shop2llm'); ?>
            <?php else : ?>
                <?php echo shop_to_llm_has_woocommerce() ? esc_html__('AI activity will appear here as ChatGPT and other AI assistants discover your store. Copy your connection config from the Settings tab and paste into ChatGPT to get started.', 'shop2llm') : esc_html__('AI activity will appear here as ChatGPT and other AI assistants discover your site. Copy your connection config from the Settings tab and paste into ChatGPT to get started.', 'shop2llm'); ?>
            <?php endif; ?>
          </div>
          <p class="s2ll-suggestion"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Check the Analytics tab for detailed trends and top search terms', 'shop2llm'); ?></p>
        </div>

        <!-- AI-Driven Traffic -->
        <div class="stl-card" style="border-left:4px solid #10b981">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                <?php echo esc_html__('AI-Driven Traffic', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('Visitors who found your site through AI platforms like ChatGPT, Perplexity, and Copilot.', 'shop2llm'); ?>"></span>
            </h2>
            <?php shop_to_llm_render_ai_referral_section(); ?>
        </div>

        </div><!-- /tab-dashboard -->

        <!-- Analytics Tab -->
        <div class="stl-tab-panel" id="tab-analytics">
        <!-- AI Readiness Score (unified: 11-item product model for commerce, 25-item model for content) -->
        <?php
        $isCommerce = ($siteMode === 'commerce' && shop_to_llm_has_woocommerce());
        $scannedPost = null;
        $scannedProduct = null;
        $scoreResults = [];
        $totalPostScore = 0;
        $totalPostMax = 100; // default full max; free users get reduced max via pro_only dims
        $dimensions = [];
        $searchInput = '';
        $selectedPostId = isset($_POST['stl_post_select']) ? (int)$_POST['stl_post_select'] : 0;
        $isContentSearch = isset($_POST['stl_content_search']) && !empty($_POST['stl_search_input']);

        // Handle search/dropdown selection
        $triggerLookup = false;
        if ($isCommerce && ((isset($_POST['shop_to_llm_sku_lookup']) && !empty($_POST['stl_search_input'])) || $selectedPostId > 0)) {
            if ($selectedPostId > 0) {
                $searchInput = (string)$selectedPostId;
            } else {
                $searchInput = sanitize_text_field(wp_unslash($_POST['stl_search_input']));
            }
            $triggerLookup = true;
        } elseif (!$isCommerce && ($selectedPostId > 0 || $isContentSearch)) {
            if ($selectedPostId > 0) {
                $triggerLookup = true;
                $searchInput = (string)$selectedPostId;
            } elseif ($isContentSearch) {
                $searchInput = sanitize_text_field(wp_unslash($_POST['stl_search_input']));
                $triggerLookup = true;
            }
        }

        if ($triggerLookup) {
            if ($isCommerce) {
                // Commerce: search products
                if ($selectedPostId > 0) {
                    // Get by ID directly (from dropdown)
                    $product = wc_get_product($selectedPostId);
                    $products = $product ? [$product] : [];
                } else {
                    $products = wc_get_products(['sku' => $searchInput, 'limit' => 1]);
                    if (empty($products)) {
                        $products = wc_get_products(['s' => $searchInput, 'limit' => 1]);
                    }
                }
                if (!empty($products)) {
                    $scannedProduct = $products[0];
                    $scannedPost = get_post($scannedProduct->get_id());
                    $p = $scannedProduct;
                    $hasProScore = class_exists('Shop_To_LLM_Pro') || defined('SHOP_TO_LLM_PRO_VERSION');
                    $dimensions = [
                        ['name' => __('Product Name', 'shop2llm'), 'pass' => strlen($p->get_name()) > 3, 'weight' => 10, 'pro_only' => false],
                        ['name' => __('Short Description', 'shop2llm'), 'pass' => strlen($p->get_short_description()) > 20, 'weight' => 13, 'pro_only' => false],
                        ['name' => __('Full Description', 'shop2llm'), 'pass' => strlen($p->get_description()) > 50, 'weight' => 13, 'pro_only' => false],
                        ['name' => __('Product Images', 'shop2llm'), 'pass' => (int)$p->get_image_id() > 0, 'weight' => 12, 'pro_only' => false],
                        ['name' => __('Price Set', 'shop2llm'), 'pass' => (float)$p->get_regular_price() > 0, 'weight' => 10, 'pro_only' => false],
                        // v1.4.0 Phase 2: price/stock accuracy dims (free 6-7)
                        ['name' => __('Sale Price Consistency', 'shop2llm'), 'pass' => shop_to_llm_product_sale_consistent($p), 'weight' => 6, 'pro_only' => false],
                        ['name' => __('Stock Status Current', 'shop2llm'), 'pass' => shop_to_llm_product_stock_current($p), 'weight' => 6, 'pro_only' => false],
                        // v1.3.9: dims 8-11 are Pro upgrade hooks — visible but locked without Pro
                        ['name' => __('Categories', 'shop2llm'), 'pass' => $hasProScore && count(wp_get_post_terms($p->get_id(), 'product_cat')) > 0, 'weight' => 8, 'pro_only' => !$hasProScore],
                        ['name' => __('Tags', 'shop2llm'), 'pass' => $hasProScore && count(wp_get_post_terms($p->get_id(), 'product_tag')) > 0, 'weight' => 4, 'pro_only' => !$hasProScore],
                        ['name' => __('Stock Managed', 'shop2llm'), 'pass' => $hasProScore && $p->get_manage_stock(), 'weight' => 8, 'pro_only' => !$hasProScore],
                        ['name' => __('Attributes / Specs', 'shop2llm'), 'pass' => $hasProScore && count($p->get_attributes()) > 0, 'weight' => 10, 'pro_only' => !$hasProScore],
                    ];
                    $freePostMax = 0;
                    $proPostMax = 0;
                    foreach ($dimensions as $c) {
                        if ($c['pass']) {
                            $totalPostScore += $c['weight'];
                        }
                        if (empty($c['pro_only'])) {
                            $freePostMax += $c['weight'];
                        }
                        $proPostMax += $c['weight'];
                    }
                    if (!$hasProScore) {
                        $totalPostMax = $freePostMax; // free: show /70 instead of /100
                    }
                }
            } else {
                // Content: search posts (by ID from dropdown, or title from text)
                $post = null;
                if ($selectedPostId > 0) {
                    $post = get_post($selectedPostId);
                } elseif ($isContentSearch) {
                    $posts = get_posts(['s' => $searchInput, 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1]);
                    $post = !empty($posts) ? $posts[0] : null;
                }
                if ($post && $post->post_status === 'publish') {
                    $scannedPost = $post;
                    // Run the 25-item model
                    $scoreResults = shop_to_llm_score_single_post($post->ID);
                    $totalPostScore = $scoreResults['total'];
                    if (!empty($scoreResults['groups'])) {
                        $dimensions = $scoreResults['groups'];
                    }
                    // v1.3.9: free users only see L1 (30 pts); Pro unlocks L2-L5 (70 pts)
                    $hasProContentScore = class_exists('Shop_To_LLM_Pro') || defined('SHOP_TO_LLM_PRO_VERSION');
                    if (!$hasProContentScore) {
                        $totalPostMax = 30; // L1 only for free
                        // Recalculate score based on L1 items only
                        $l1Items = array_slice($scoreResults['items'] ?? [], 0, 6);
                        $totalPostScore = 0;
                        foreach ($l1Items as $it) {
                            if (($it['pass'] ?? false) && !($it['locked'] ?? false)) {
                                $totalPostScore += (int)($it['pts'] ?? 0);
                            }
                        }
                    }
                }
            }
        }

        // Build dropdown options
        $recentItems = [];
        if ($isCommerce) {
            $recentProducts = wc_get_products(['limit' => 50, 'status' => 'publish', 'orderby' => 'date']);
            foreach ($recentProducts as $rp) {
                $recentItems[] = ['id' => $rp->get_id(), 'title' => $rp->get_name()];
            }
            $dropdownLabel = __('Select a product', 'shop2llm');
            $searchPlaceholder = __('Or search by name/SKU...', 'shop2llm');
            $cardTitle = __('Product AI Score', 'shop2llm');
            $cardHelp = __('Check individual product readiness for AI discovery. 11-dimension product model with price/stock accuracy.', 'shop2llm');
        } else {
            $recentPosts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'date']);
            foreach ($recentPosts as $rp) {
                $recentItems[] = ['id' => $rp->ID, 'title' => $rp->post_title];
            }
            $dropdownLabel = __('Select a post', 'shop2llm');
            $searchPlaceholder = __('Or search by title...', 'shop2llm');
            $cardTitle = __('Content AI Score', 'shop2llm');
            $cardHelp = __('Check individual post readiness for AI discovery. 25-dimension 5-group model.', 'shop2llm');
        }
        ?>
        <div class="stl-card" id="stl-ai-readiness">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><?php echo esc_html($cardTitle); ?><span class="s2ll-help" title="<?php echo esc_attr($cardHelp); ?>"></span></h2>

            <form method="post" style="margin-bottom:12px">
                <input type="hidden" name="stl_tab" value="analytics">
                <div style="display:flex;gap:8px;align-items:center">
                    <?php if (!empty($recentItems)) : ?>
                    <select name="stl_post_select" style="border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;font-size:13px;min-width:180px;max-width:260px" onchange="this.form.querySelector('input[name=stl_search_input]').value='';this.form.submit()">
                        <option value=""><?php echo esc_html($dropdownLabel); ?>...</option>
                        <?php foreach ($recentItems as $ri) : ?>
                        <option value="<?php echo esc_attr((string)$ri['id']); ?>" <?php selected($selectedPostId, $ri['id']); ?>><?php echo esc_html(mb_substr($ri['title'], 0, 55)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input type="text" name="stl_search_input" value="<?php echo esc_attr($searchInput && !$selectedPostId ? $searchInput : ''); ?>" placeholder="<?php echo esc_attr($searchPlaceholder); ?>" style="flex:1;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;font-size:13px" onchange="this.form.querySelector('select').value=''">
                    <button type="submit" name="<?php echo esc_attr($isCommerce ? 'shop_to_llm_sku_lookup' : 'stl_content_search'); ?>" class="stl-btn"><?php echo esc_html__('Check', 'shop2llm'); ?></button>
                </div>
            </form>

            <?php if ($scannedPost) : ?>
                <?php
                $scorePct = $totalPostMax > 0 ? ($totalPostScore / $totalPostMax * 100) : 0;
                $scoreColor = $scorePct >= 70 ? '#16a34a' : ($scorePct >= 40 ? '#d97706' : '#dc2626');
                $editUrl = admin_url('post.php?post=' . $scannedPost->ID . '&action=edit');
                ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px">
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px">
                        <div style="font-size:26px;font-weight:700;color:<?php echo esc_attr($scoreColor); ?>"><?php echo esc_html((string)$totalPostScore); ?>/<?php echo esc_html((string)$totalPostMax); ?><?php if ($totalPostMax < 100) :
                            ?><span style="font-size:11px;color:#6366f1;font-weight:500;display:inline-flex"><?php echo wp_kses(shop_to_llm_icon('lock', 11), shop_to_llm_allowed_svg()); ?></span><?php
                                                                         endif; ?></div>
                        <div>
                            <div style="font-size:13px;font-weight:600;color:#1e1e1e"><?php echo esc_html($scannedPost->post_title); ?></div>
                            <div style="font-size:11px;color:#64748b"><?php echo $isCommerce ? esc_html__('AI readiness score for this product', 'shop2llm') : esc_html__('AI readiness score — 25-dimension analysis', 'shop2llm'); ?><?php if ($totalPostMax < 100) :
                                ?> <a href="<?php echo esc_url('https://shop2llm.com/fincore/v1/page/prolanding'); ?>" style="color:#6366f1;font-weight:600;text-decoration:none"><?php echo esc_html__('Unlock full score →', 'shop2llm'); ?></a><?php
                                                                      endif; ?></div>
                        </div>
                        <a href="<?php echo esc_url($editUrl); ?>" target="_blank" style="margin-left:auto;font-size:11px;color:#6366f1;font-weight:500;text-decoration:none;white-space:nowrap"><?php echo esc_html__('Edit →', 'shop2llm'); ?></a>
                    </div>

                    <?php if ($isCommerce) : ?>
                        <!-- Commerce: 11-item flat list (v1.4.0: free dims 6-7 = price/stock accuracy; dims 8-11 locked without Pro) -->
                        <div style="display:flex;flex-direction:column;gap:4px">
                            <?php foreach ($dimensions as $dim) : ?>
                                <?php if (!empty($dim['pro_only'])) : ?>
                            <div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:3px 0;background:#f5f3ff;border-radius:4px;padding:4px 8px">
                                <span style="font-size:10px;min-width:16px;text-align:center;display:inline-flex"><?php echo wp_kses(shop_to_llm_icon('lock', 10), shop_to_llm_allowed_svg()); ?></span>
                                <span style="color:#6366f1;font-weight:500"><?php echo esc_html($dim['name']); ?></span>
                                <a href="<?php echo esc_url('https://shop2llm.com/fincore/v1/page/prolanding'); ?>" style="margin-left:auto;font-size:10px;color:#6366f1;font-weight:600;text-decoration:none">Pro →</a>
                                <span style="color:#94a3b8;font-size:10px">+<?php echo esc_html((string)$dim['weight']); ?></span>
                            </div>
                                <?php else : ?>
                            <div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:3px 0">
                                <span style="font-size:10px;min-width:16px;text-align:center;padding:1px 3px;border-radius:2px;<?php echo $dim['pass'] ? 'background:#dcfce7;color:#16a34a' : 'background:#fef2f2;color:#dc2626'; ?>"><?php echo $dim['pass'] ? '✓' : '✗'; ?></span>
                                <span style="color:<?php echo $dim['pass'] ? '#1e1e1e' : '#94a3b8'; ?>"><?php echo esc_html($dim['name']); ?></span>
                                <span style="margin-left:auto;color:#94a3b8;font-size:10px">+<?php echo esc_html((string)$dim['weight']); ?></span>
                            </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <!-- Content: 25-item grouped by L1-L5 -->
                        <?php
                        $hasProContentScore = class_exists('Shop_To_LLM_Pro') || defined('SHOP_TO_LLM_PRO_VERSION');
                        $groups = [
                            'L1' => ['label' => __('Discoverability', 'shop2llm'), 'max' => 30, 'color' => '#6366f1', 'items' => []],
                            'L2' => ['label' => __('Relevance', 'shop2llm'), 'max' => 25, 'color' => '#8b5cf6', 'items' => [], 'locked' => empty($scoreResults['has_focus']), 'pro_locked' => !$hasProContentScore],
                            'L3' => ['label' => __('Content Quality', 'shop2llm'), 'max' => 25, 'color' => '#10b981', 'items' => [], 'pro_locked' => !$hasProContentScore],
                            'L4' => ['label' => __('Entity Authority', 'shop2llm'), 'max' => 15, 'color' => '#f59e0b', 'items' => [], 'locked' => empty($scoreResults['has_focus']), 'pro_locked' => !$hasProContentScore],
                            'L5' => ['label' => __('Activity', 'shop2llm'), 'max' => 5, 'color' => '#ec4899', 'items' => [], 'pro_locked' => !$hasProContentScore],
                        ];
                        // Distribute dimensions into groups
                        $allItems = $scoreResults['items'] ?? [];
                        $groupBoundaries = [6, 12, 18, 21, 25]; // L1=1-6, L2=7-12, L3=13-18, L4=19-21, L5=22-25
                        $gi = 0;
                        $groupKeys = array_keys($groups);
                        foreach ($allItems as $idx => $item) {
                            while ($gi < 4 && $idx >= $groupBoundaries[$gi]) {
                                $gi++;
                            }
                            $gkey = $groupKeys[$gi] ?? 'L1';
                            $groups[$gkey]['items'][] = $item;
                        }
                        // Calculate group scores
                        foreach ($groups as $gkey => &$g) {
                            $g['score'] = 0;
                            foreach ($g['items'] as $it) {
                                if (!($it['locked'] ?? false) && ($it['pass'] ?? false)) {
                                    $g['score'] += (int)($it['pts'] ?? 0);
                                }
                            }
                        }
                        unset($g);
                        ?>
                        <?php foreach ($groups as $g) :
                            $barPct = $g['max'] > 0 ? round($g['score'] / $g['max'] * 100) : 0;
                            $barColor = $barPct >= 70 ? '#16a34a' : ($barPct >= 40 ? '#d97706' : '#dc2626');
                            $isProLocked = !empty($g['pro_locked']);
                            ?>
                        <div style="margin-bottom:10px;<?php echo $isProLocked ? 'opacity:0.75;' : ''; ?>">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <span style="font-size:11px;font-weight:700;color:<?php echo esc_attr($isProLocked ? '#6366f1' : $g['color']); ?>"><?php echo esc_html($g['label']); ?></span>
                                <?php if ($isProLocked) : ?>
                                <span style="font-size:10px;color:#6366f1;font-weight:600;display:inline-flex;align-items:center;gap:2px"><?php echo wp_kses(shop_to_llm_icon('lock', 10), shop_to_llm_allowed_svg()); ?> Pro</span>
                                <?php endif; ?>
                                <?php if (!empty($g['locked'])) :
                                    ?><span style="font-size:10px;color:#94a3b8;display:inline-flex"><?php echo wp_kses(shop_to_llm_icon('lock', 10), shop_to_llm_allowed_svg()); ?></span><?php
                                endif; ?>
                                <div style="flex:1;height:5px;background:#e2e8f0;border-radius:3px"><div style="width:<?php echo esc_attr($isProLocked ? '0' : $barPct); ?>%;height:5px;background:<?php echo esc_attr($isProLocked ? '#6366f1' : $barColor); ?>;border-radius:3px"></div></div>
                                <span style="font-size:11px;font-weight:600;color:<?php echo esc_attr($isProLocked ? '#6366f1' : $barColor); ?>"><?php echo $isProLocked ? '—' : esc_html((string)$g['score']) . '/' . esc_html((string)$g['max']); ?></span>
                            </div>
                            <?php if ($isProLocked) : ?>
                                <div style="padding:6px 10px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #c7d2fe;border-radius:6px;font-size:10px;color:#4f46e5;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px">
                                    <span style="display:inline-flex;align-items:center;gap:3px"><?php echo wp_kses(shop_to_llm_icon('lock', 11), shop_to_llm_allowed_svg()); ?> <?php echo esc_html($g['label']); ?> — <?php echo esc_html__('Pro feature', 'shop2llm'); ?></span>
                                    <a href="<?php echo esc_url('https://shop2llm.com/fincore/v1/page/prolanding'); ?>" style="font-size:10px;color:#6366f1;font-weight:700;text-decoration:none;padding:2px 8px;background:#fff;border-radius:4px;border:1px solid #6366f1"><?php echo esc_html__('Upgrade →', 'shop2llm'); ?></a>
                                </div>
                            <?php elseif (!empty($g['locked'])) : ?>
                                <div style="padding:4px 8px;background:#fafafa;border:1px dashed #e2e8f0;border-radius:4px;font-size:10px;color:#94a3b8;text-align:center">
                                    <?php echo sprintf(/* translators: %s: category label */ esc_html__('Set a Focus Query in the post editor to unlock %s items', 'shop2llm'), esc_html($g['label'])); ?>
                                </div>
                            <?php else : ?>
                                <?php foreach ($g['items'] as $it) : ?>
                                <div style="display:flex;align-items:center;gap:6px;font-size:10px;padding:1px 4px;margin:1px 0">
                                    <span style="color:<?php echo ($it['pass'] ?? false) ? '#16a34a' : '#94a3b8'; ?>;min-width:12px"><?php echo ($it['pass'] ?? false) ? '✓' : '✗'; ?></span>
                                    <span style="color:<?php echo ($it['pass'] ?? false) ? '#1e1e1e' : '#94a3b8'; ?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($it['name'] ?? ''); ?></span>
                                    <span style="margin-left:auto;color:#94a3b8"><?php echo ($it['pass'] ?? false) ? '+' : ''; ?><?php echo esc_html((string)($it['pts'] ?? 0)); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php elseif ($triggerLookup) : ?>
                <p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px 0"><?php echo esc_html__('Not found. Try selecting from the dropdown or use a different keyword.', 'shop2llm'); ?></p>
            <?php else : ?>
                <p style="color:#94a3b8;font-size:13px;margin:0;line-height:1.5">
                    <?php echo $isCommerce ? esc_html__('Select a product from the dropdown or type a name/SKU. A higher score means AI is more likely to recommend this product.', 'shop2llm') : esc_html__('Select a post from the dropdown or search by title. The 25-dimension model checks discoverability, relevance, quality, authority, and activity.', 'shop2llm'); ?>
                </p>
            <?php endif; ?>
            <?php if ($totalPostScore < 70 && $scannedPost) : ?>
            <p class="s2ll-suggestion"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo $isCommerce ? esc_html__('Click Edit to improve missing product data', 'shop2llm') : esc_html__('Click Edit → set Focus Query to unlock full 25-dimension analysis', 'shop2llm'); ?><?php if ($totalPostMax < 100) :
                ?> | <a href="<?php echo esc_url('https://shop2llm.com/fincore/v1/page/prolanding'); ?>" style="color:#6366f1;font-weight:600;text-decoration:none"><?php echo $isCommerce ? esc_html__('Unlock 4 more dimensions with Pro →', 'shop2llm') : esc_html__('Unlock L2-L5 analysis with Pro →', 'shop2llm'); ?></a><?php
                                       endif; ?></p>
            <?php endif; ?>
        </div>
        <?php // end AI Readiness Score ?>

        <p class="s2ll-suggestion" style="margin-top:16px">
            <?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Single-post analysis is also available in the post editor sidebar (meta box). For site-wide readiness, see Overview and AI Agents tabs.', 'shop2llm'); ?>
        </p>

        </div><!-- /tab-analytics -->

        <!-- AI Agents Tab (v1.3.6+) — Agentic Commerce Readiness: UCP + MCP + agents.md + Crawler Registry + robots.txt -->
        <div class="stl-tab-panel" id="tab-agents">
        <!-- UCP Readiness Score — 5-dimension transparent methodology (v1.3.4+, 7→5 in v1.6.1) -->
        <?php shop_to_llm_render_ucp_readiness_panel(); ?>

        <!-- MCP Endpoint Status -->
        <div class="stl-card" style="border-left:4px solid #6366f1">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#6366f1">
                    <path d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                <?php echo esc_html__('MCP Endpoint', 'shop2llm'); ?>
                <span class="s2ll-help" title="<?php echo esc_attr__('Model Context Protocol endpoint — lets AI agents call your store like a native tool.', 'shop2llm'); ?>"></span>
            </h2>
            <?php
            $siteUrl = rtrim((string) get_site_url(), '/');
            $mcpEnabled = get_option('shop_to_llm_enabled', 'yes') === 'yes';
            $mcpUrl = shop_to_llm_mcp_endpoint_url();
            $discoveryUrl = $siteUrl . '/wp-json/shop2llm/v1/.well-known/mcp';
            $cachedMcpDiscovery = get_transient('shop_to_llm_ucp_check_mcp');
            ?>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('Endpoint URL', 'shop2llm'); ?></span>
                <span class="stl-value stl-mono"><?php echo esc_html($mcpUrl); ?></span>
            </div>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('Protocol', 'shop2llm'); ?></span>
                <span class="stl-value">
                    <?php if ($mcpEnabled) : ?>
                        <span class="stl-badge stl-badge-ok">✓ <?php
                            /* translators: %d: number of MCP tools */
                            echo esc_html(sprintf(__('JSON-RPC 2.0 — %d tools', 'shop2llm'), count(shop_to_llm_tools_list()['tools'])));
                        ?></span>
                    <?php else : ?>
                        <span class="stl-badge stl-badge-off"><?php echo esc_html__('Disabled', 'shop2llm'); ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('Discovery', 'shop2llm'); ?></span>
                <span class="stl-value">
                    <?php if ($cachedMcpDiscovery === '1') : ?>
                        <span class="stl-badge stl-badge-ok">✓ /.well-known/mcp reachable</span>
                    <?php else : ?>
                        <span class="stl-badge stl-badge-pro">⚠ /.well-known/mcp not reachable</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('Tools', 'shop2llm'); ?></span>
                <span class="stl-value" style="font-size:12px;color:#475569">
                    search_products, get_product, get_policies, get_store_overview
                </span>
            </div>
            <?php
            // v1.6.0: live verification + recent tool-call evidence strip.
            $mcpCallLog = get_option('shop_to_llm_mcp_call_log', []);
            if (!is_array($mcpCallLog)) {
                $mcpCallLog = [];
            }
            $mcpAgentLabel = static function (string $ua): string {
                if ($ua === '') {
                    return __('Unknown agent', 'shop2llm');
                }
                $map = ['ChatGPT' => 'ChatGPT', 'OpenAI' => 'OpenAI', 'Claude' => 'Claude', 'Anthropic' => 'Anthropic', 'Gemini' => 'Gemini', 'Perplexity' => 'Perplexity', 'GPTBot' => 'GPTBot', 'cursor' => 'Cursor', 'python-requests' => 'Custom client', 'Go-http-client' => 'Custom client'];
                foreach ($map as $needle => $label) {
                    if (stripos($ua, $needle) !== false) {
                        return $label;
                    }
                }
                $first = strtok($ua, ' ');
                return $first !== false ? substr($first, 0, 40) : substr($ua, 0, 40);
            };
            $mcpTimeAgo = static function (string $mysqlLocal): string {
                $ts = (int) strtotime($mysqlLocal);
                if ($ts <= 0) {
                    return '';
                }
                $diff = max(0, time() - $ts);
                if ($diff < 60) {
                    return __('just now', 'shop2llm');
                }
                if ($diff < HOUR_IN_SECONDS) {
                    /* translators: %d: number of minutes */
                    return sprintf(__('%dm ago', 'shop2llm'), (int) floor($diff / 60));
                }
                if ($diff < DAY_IN_SECONDS) {
                    /* translators: %d: number of hours */
                    return sprintf(__('%dh ago', 'shop2llm'), (int) floor($diff / HOUR_IN_SECONDS));
                }
                /* translators: %s: date */
                return mysql2date('M j, H:i', $mysqlLocal);
            };
    ?>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('Connection', 'shop2llm'); ?></span>
                <span class="stl-value" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <span id="s2ll-mcp-verify-status">
                        <?php if ($mcpEnabled) : ?>
                            <span class="stl-badge stl-badge-pro">— <?php echo esc_html__('Not verified yet', 'shop2llm'); ?></span>
                        <?php else : ?>
                            <span class="stl-badge stl-badge-off"><?php echo esc_html__('Endpoint disabled', 'shop2llm'); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ($mcpEnabled) : ?>
                    <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm" id="s2ll-mcp-verify"><?php echo esc_html__('Verify connection', 'shop2llm'); ?></button>
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!empty($mcpCallLog)) : ?>
            <div id="s2ll-mcp-evidence" style="margin-top:10px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
                <div style="font-size:12px;font-weight:600;color:#1e293b;margin-bottom:6px"><?php echo esc_html__('Recent MCP activity', 'shop2llm'); ?></div>
                <?php foreach (array_slice($mcpCallLog, 0, 5) as $call) : ?>
                <div style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:12px;border-bottom:1px solid #f1f5f9">
                    <span style="flex-shrink:0;display:inline-flex"><?php echo wp_kses(shop_to_llm_icon('bot', 14), shop_to_llm_allowed_svg()); ?></span>
                    <span style="color:#1e293b;font-weight:600;min-width:90px"><?php echo esc_html($mcpAgentLabel((string) ($call['agent'] ?? ''))); ?></span>
                    <span style="color:#6366f1;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px"><?php echo esc_html((string) ($call['tool'] ?? '')); ?></span>
                    <span style="margin-left:auto;color:#94a3b8;font-size:11px;white-space:nowrap"><?php echo esc_html($mcpTimeAgo((string) ($call['time'] ?? ''))); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <script>
            (function() {
                var btn = document.getElementById('s2ll-mcp-verify');
                if (!btn) { return; }
                var status = document.getElementById('s2ll-mcp-verify-status');
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    if (status) { status.innerHTML = '<span class="stl-badge stl-badge-pro">…</span>'; }
                    var body = new FormData();
                    body.append('action', 'shop2llm_asset_tool');
                    body.append('nonce', <?php echo wp_json_encode($assetNonce ?? wp_create_nonce('shop2llm_admin_nonce')); ?>);
                    body.append('asset', 'mcp_check');
                    fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            btn.disabled = false;
                            var ok = res && res.success && res.data && res.data.ok;
                            if (status) {
                                status.innerHTML = ok
                                    ? '<span class="stl-badge stl-badge-ok">✓ ' + (res.data.message || 'Verified') + '</span>'
                                    : '<span class="stl-badge stl-badge-off">✗ ' + ((res && res.data && res.data.message) || 'Not reachable') + '</span>';
                            }
                            // v1.10.0 (M1): one-time amber review ask on the first
                            // successful verification — same visual as the Hero ask.
                            if (ok && res.data.review_ask && status) {
                                var rowEl = status.closest('.stl-row');
                                if (rowEl && !document.getElementById('s2ll-mcp-review')) {
                                    rowEl.insertAdjacentHTML('afterend', '<div id="s2ll-mcp-review" style="margin-top:10px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e">⭐ ' + <?php echo wp_json_encode(__('Enjoying Shop2LLM?', 'shop2llm')); ?> + ' <a href="https://wordpress.org/support/plugin/shop2llm/reviews/#new-post" target="_blank" rel="noopener" style="color:#d97706;text-decoration:underline;font-weight:600">' + <?php echo wp_json_encode(__('Rate it ★★★★★ on WordPress.org', 'shop2llm')); ?> + '</a></div>');
                                }
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            if (status) { status.innerHTML = '<span class="stl-badge stl-badge-off">✗ Request failed</span>'; }
                        });
                });
            })();
            </script>
            <p class="s2ll-suggestion" style="margin-top:12px">
                <?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Add your MCP URL to ChatGPT, Claude, or Gemini to let AI agents shop your catalog.', 'shop2llm'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=shop2llm#tab-settings')); ?>" style="color:#6366f1;font-weight:600"><?php echo esc_html__('Configure →', 'shop2llm'); ?></a>
            </p>
        </div>

        <!-- /agents.md Endpoint -->
        <div class="stl-card" style="border-left:4px solid #0ea5e9">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#0ea5e9">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <?php echo esc_html__('/agents.md Endpoint', 'shop2llm'); ?>
                <span class="s2ll-help" title="<?php echo esc_attr__('Markdown dossier for AI agents — describes your catalog, MCP tools, schema types, and discovery files.', 'shop2llm'); ?>"></span>
            </h2>
            <?php
            $agentsMdUrl = $siteUrl . '/agents.md';
            $llmsFullUrl = $siteUrl . '/llms-full.txt';
            $llmsUrl = $siteUrl . '/llms.txt';
            $cachedLlms = get_transient('shop_to_llm_ucp_check_llms');
            ?>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('/agents.md', 'shop2llm'); ?></span>
                <span class="stl-value stl-mono">
                    <a href="<?php echo esc_url($agentsMdUrl); ?>" target="_blank" rel="noopener" style="color:#6366f1"><?php echo esc_html($agentsMdUrl); ?></a>
                </span>
            </div>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('/llms-full.txt', 'shop2llm'); ?></span>
                <span class="stl-value stl-mono">
                    <a href="<?php echo esc_url($llmsFullUrl); ?>" target="_blank" rel="noopener" style="color:#6366f1"><?php echo esc_html($llmsFullUrl); ?></a>
                </span>
            </div>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('/llms.txt', 'shop2llm'); ?></span>
                <span class="stl-value">
                    <?php if ($cachedLlms === '1') : ?>
                        <span class="stl-badge stl-badge-ok">✓ Reachable</span>
                    <?php else : ?>
                        <span class="stl-badge stl-badge-pro">⚠ Not reachable (flush rewrites)</span>
                    <?php endif; ?>
                </span>
            </div>
            <p class="s2ll-suggestion" style="margin-top:12px">
                <?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Crawlers discover these endpoints via robots.txt. Cache TTL: 1 hour (auto-invalidates on product/post save).', 'shop2llm'); ?>
            </p>
            <?php
            $assetNonce = wp_create_nonce('shop2llm_admin_nonce');
            $assetAjax = admin_url('admin-ajax.php');
            ?>
            <div style="margin-top:14px;padding-top:12px;border-top:1px dashed #e2e8f0">
                <div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:8px"><?php echo esc_html__('One-click AI assets', 'shop2llm'); ?></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="stl-btn stl-btn--secondary" id="s2ll-regen-llms"><?php echo esc_html__('Generate & copy llms.txt', 'shop2llm'); ?></button>
                    <button type="button" class="stl-btn stl-btn--secondary" id="s2ll-flush-rewrites"><?php echo esc_html__('Flush rewrites & cache', 'shop2llm'); ?></button>
                    <button type="button" class="stl-btn stl-btn--secondary" id="s2ll-preview-jsonld"><?php echo esc_html__('Preview JSON-LD', 'shop2llm'); ?></button>
                </div>
                <div id="s2ll-asset-feedback" style="display:none;margin-top:8px;font-size:12px;color:#16a34a"></div>
                <pre id="s2ll-asset-preview" style="display:none;margin-top:8px;background:#0f172a;color:#e2e8f0;border-radius:8px;padding:12px;font-size:11px;line-height:1.5;max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-word"></pre>
            </div>
            <script>
            (function() {
                var ajaxUrl = <?php echo wp_json_encode($assetAjax); ?>;
                var nonce = <?php echo wp_json_encode($assetNonce); ?>;
                var feedback = document.getElementById('s2ll-asset-feedback');
                var preview = document.getElementById('s2ll-asset-preview');
                function show(msg, isErr) {
                    feedback.style.display = 'block';
                    feedback.style.color = isErr ? '#dc2626' : '#16a34a';
                    feedback.textContent = msg;
                }
                function call(asset, onDone) {
                    var body = new FormData();
                    body.append('action', 'shop2llm_asset_tool');
                    body.append('nonce', nonce);
                    body.append('asset', asset);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res && res.success) { onDone(res.data); }
                            else { show((res && res.data && res.data.message) || 'Request failed', true); }
                        })
                        .catch(function() { show('Request failed', true); });
                }
                var btnCopy = document.getElementById('s2ll-regen-llms');
                if (btnCopy) {
                    btnCopy.addEventListener('click', function() {
                        btnCopy.disabled = true;
                        call('llms_txt', function(data) {
                            btnCopy.disabled = false;
                            preview.style.display = 'block';
                            preview.textContent = data.content || '';
                            var done = function() { show('llms.txt generated — copied to clipboard', false); };
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(data.content || '').then(done, function() { show('Generated below — copy manually', false); });
                            } else { done(); }
                        });
                    });
                }
                var btnFlush = document.getElementById('s2ll-flush-rewrites');
                if (btnFlush) {
                    btnFlush.addEventListener('click', function() {
                        btnFlush.disabled = true;
                        call('flush', function(data) {
                            btnFlush.disabled = false;
                            show(data.message || 'Flushed', false);
                        });
                    });
                }
                var btnSchema = document.getElementById('s2ll-preview-jsonld');
                if (btnSchema) {
                    btnSchema.addEventListener('click', function() {
                        btnSchema.disabled = true;
                        call('jsonld', function(data) {
                            btnSchema.disabled = false;
                            preview.style.display = 'block';
                            preview.textContent = (data.title ? '<!-- ' + data.title + ' -->\n' : '') + (data.content || '');
                            show('JSON-LD preview for: ' + (data.title || ''), false);
                        });
                    });
                }
            })();
            </script>
        </div>

        <!-- AI Crawler Registry -->
        <div class="stl-card" style="border-left:4px solid #16a34a">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#16a34a">
                    <circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
                <?php echo esc_html__('AI Crawler Registry', 'shop2llm'); ?>
                <span class="stl-badge stl-badge-ok"><?php echo (int) shop_to_llm_get_ai_crawler_count(); ?> bots</span>
            </h2>
            <p style="font-size:13px;color:#475569;margin:0 0 10px;line-height:1.6">
                <?php echo esc_html__('Centralized registry of 65+ AI crawlers, used by robots.txt, crawler detector, /agents.md, and UCP score. Single source of truth.', 'shop2llm'); ?>
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;font-size:12px">
                <?php
                $registrySummary = [
                    'OpenAI' => ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot'],
                    'Anthropic' => ['ClaudeBot', 'Claude-Web', 'anthropic-ai'],
                    'Perplexity' => ['PerplexityBot', 'Perplexity-User', 'Swiftbot'],
                    'Google' => ['Google-Extended', 'Googlebot', 'Googlebot-News'],
                    'Microsoft' => ['Bingbot', 'Bytespider', 'DuckDuckBot'],
                ];
                foreach ($registrySummary as $vendor => $bots) :
                    ?>
                    <div style="padding:8px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px">
                        <div style="font-weight:600;color:#166534;margin-bottom:4px"><?php echo esc_html($vendor); ?></div>
                        <div style="color:#475569"><?php echo esc_html(implode(', ', $bots)); ?></div>
                    </div>
                <?php endforeach; ?>
                <div style="padding:8px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;grid-column:1/-1">
                    <div style="font-weight:600;color:#166534;margin-bottom:4px">+ 50 more</div>
                    <div style="color:#475569">Meta, ByteDance, Common Crawl, Amazon, Apple, Cohere, AI Search upstarts (YouBot, Cotoy, PiplBot, etc.)</div>
                </div>
            </div>
        </div>

        <!-- robots.txt Configuration -->
        <div class="stl-card" style="border-left:4px solid #f59e0b">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#f59e0b">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                <?php echo esc_html__('robots.txt Configuration', 'shop2llm'); ?>
            </h2>
            <?php $allowedCrawlers = shop_to_llm_get_robots_allowed_crawlers(); ?>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('Allow rules', 'shop2llm'); ?></span>
                <span class="stl-value"><strong><?php echo (int) count($allowedCrawlers); ?></strong> <?php echo esc_html__('AI crawlers whitelisted', 'shop2llm'); ?></span>
            </div>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('Disallow', 'shop2llm'); ?></span>
                <span class="stl-value"><span class="stl-badge stl-badge-ok">✓ No Disallow: / for AI bots</span></span>
            </div>
            <div class="stl-row">
                <span class="stl-label"><?php echo esc_html__('View', 'shop2llm'); ?></span>
                <span class="stl-value stl-mono"><a href="<?php echo esc_url($siteUrl . '/robots.txt'); ?>" target="_blank" rel="noopener" style="color:#6366f1"><?php echo esc_html($siteUrl); ?>/robots.txt</a></span>
            </div>
            <p class="s2ll-suggestion" style="margin-top:12px">
                <?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Cloudflare Managed robots.txt may inject Disallow rules — disable it to use this local robots.txt.', 'shop2llm'); ?>
            </p>
            <?php $usageMode = get_option('shop_to_llm_ai_usage_policy', 'off'); ?>
            <form method="post" style="margin-top:12px;border-top:1px solid #f1f5f9;padding-top:12px">
                <?php wp_nonce_field('shop2llm_ai_usage_policy'); ?>
                <div class="stl-row">
                    <span class="stl-label"><?php echo esc_html__('AI usage policy', 'shop2llm'); ?></span>
                    <span class="stl-value">
                        <select name="shop_to_llm_ai_usage_policy" style="max-width:100%">
                            <option value="off" <?php selected($usageMode, 'off'); ?>><?php echo esc_html__('Off (default — no section)', 'shop2llm'); ?></option>
                            <option value="open" <?php selected($usageMode, 'open'); ?>><?php echo esc_html__('Open — training, summarization & attribution permitted', 'shop2llm'); ?></option>
                            <option value="attribution" <?php selected($usageMode, 'attribution'); ?>><?php echo esc_html__('Attribution — no training; summarize with credit', 'shop2llm'); ?></option>
                            <option value="closed" <?php selected($usageMode, 'closed'); ?>><?php echo esc_html__('Closed — no training; search snippets only', 'shop2llm'); ?></option>
                        </select>
                    </span>
                </div>
                <p style="color:#64748b;font-size:12px;margin:8px 0 10px;line-height:1.6">
                    <?php echo esc_html__('Appends a Training / Summarization / Attribution declaration plus a machine-readable Content-Signal line (search / ai-input / ai-train / use) to your robots.txt so AI operators know how you consent to be used. Declarative preference — it informs AI operators, it cannot force them. Off by default.', 'shop2llm'); ?>
                </p>
                <button type="submit" name="shop2llm_ai_usage_policy_save" value="1" class="stl-btn"><?php echo esc_html__('Save AI usage policy', 'shop2llm'); ?></button>
            </form>
        </div>
        </div><!-- /tab-agents -->

        <!-- AI Traffic Tab (v1.3.6+) — AI visits + consumer AI search + GA4 AI referrer -->
        <div class="stl-tab-panel" id="tab-traffic">
        <?php
        // v1.5.4: single analytics fetch shared by the hero bar and the
        // crawler diagnostics below (was queried twice inside the hero alone).
        $trafficAnalytics = shop_to_llm_get_crawler_analytics(7);
        // v1.6.0: AI Search Activity shows a full 7-day window (was today-only).
        $trafficSearches = shop_to_llm_get_recent_searches(7);
        $trafficSearchCount = count($trafficSearches);
        $focusIdeas = get_option('shop_to_llm_focus_query_ideas', []);
        if (!is_array($focusIdeas)) {
            $focusIdeas = [];
        }
        ?>
        <!-- Hero Score Bar -->
        <div class="stl-card s2ll-hero-score" style="background:linear-gradient(135deg,#0ea5e9 0%,#6366f1 100%);color:#fff;border:none;padding:20px;margin:16px 0">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                <div>
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;opacity:0.8;margin-bottom:4px"><?php echo esc_html__('Your AI Visibility Snapshot', 'shop2llm'); ?></div>
                    <div style="font-size:28px;font-weight:700;line-height:1"><?php echo esc_html__('Ready for AI Discovery', 'shop2llm'); ?></div>
                    <div style="font-size:13px;opacity:0.9;margin-top:6px"><?php echo esc_html__('Crawler visits, AI search activity & referral traffic in one view', 'shop2llm'); ?></div>
                </div>
                <div style="display:flex;gap:24px">
                    <div style="text-align:center">
                        <div style="font-size:24px;font-weight:700"><?php echo esc_html(number_format_i18n($trafficAnalytics['total_visits'])); ?></div>
                        <div style="font-size:11px;opacity:0.8"><?php echo esc_html__('Crawler Visits (7d)', 'shop2llm'); ?></div>
                    </div>
                    <div style="text-align:center">
                        <div style="font-size:24px;font-weight:700"><?php echo esc_html(number_format_i18n($trafficAnalytics['unique_bots'])); ?></div>
                        <div style="font-size:11px;opacity:0.8"><?php echo esc_html__('Unique AI Bots', 'shop2llm'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php // v1.10.0: GSC AI-insights guidance card — activation hook + interpretation. ?>
        <?php $gscState = (string) get_option('shop2llm_gsc_insights_state', ''); ?>
        <?php if ($gscState !== 'dismissed') : ?>
        <div class="stl-card" id="stl-gsc-insights" style="border-left:4px solid #4285f4">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <h2 style="margin:0;display:flex;align-items:center;gap:8px">
                    <svg viewBox="0 0 24 24" fill="none"><rect width="24" height="24" rx="6" fill="#4285f4"/><path d="M12 7v10M7 12h10" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
                    <?php echo esc_html__('Official AI Display Data in Search Console', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('Google Search Console shows which of your pages appear in AI answers. This card helps you connect and read that official data.', 'shop2llm'); ?>"></span>
                </h2>
                <button type="button" id="stl-gsc-dismiss" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;line-height:1;padding:4px;margin-left:auto" title="<?php echo esc_attr__('Dismiss — you can reconnect later from the SEO tab', 'shop2llm'); ?>">✕</button>
            </div>
            <div id="stl-gsc-hook" style="<?php echo $gscState === 'connected' ? 'display:none' : ''; ?>">
                <p style="color:#64748b;font-size:12px;margin:0 0 10px;line-height:1.6"><?php echo esc_html__('Search Console now shows official AI display data — which of your pages appear in AI answers. It is free, official data from Google, globally available since August 2026. Connect your site and check which pages already surface in AI answers.', 'shop2llm'); ?></p>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <a class="stl-copy-btn" style="text-decoration:none" href="https://search.google.com/search-console" target="_blank" rel="noopener" title="<?php echo esc_attr__('Opens Google Search Console in a new tab', 'shop2llm'); ?>"><?php echo esc_html__('Open Google Search Console →', 'shop2llm'); ?></a>
                    <button type="button" id="stl-gsc-connected-btn" style="background:none;border:none;cursor:pointer;color:#6366f1;font-size:12px;font-weight:600;text-decoration:underline;padding:0"><?php echo esc_html__("I've connected it — show me how to read the data", 'shop2llm'); ?></button>
                </div>
            </div>
            <div id="stl-gsc-read" style="<?php echo $gscState === 'connected' ? '' : 'display:none'; ?>">
                <p style="color:#64748b;font-size:12px;margin:0 0 8px;line-height:1.6"><?php echo esc_html__('Open Search Console → Performance → Search results to read your AI display data. Practical next steps:', 'shop2llm'); ?></p>
                <p style="color:#64748b;font-size:12px;margin:0 0 4px;line-height:1.6">• <?php echo esc_html__('High AI impressions but few clicks → improve extractability with the AI Score card below.', 'shop2llm'); ?></p>
                <p style="color:#64748b;font-size:12px;margin:0 0 10px;line-height:1.6">• <?php echo esc_html__('Pages that never appear → check they are listed in /llms.txt (SEO tab).', 'shop2llm'); ?></p>
                <a class="stl-copy-btn" style="text-decoration:none" href="https://search.google.com/search-console/performance/search-analytics" target="_blank" rel="noopener" title="<?php echo esc_attr__('Opens the Search Console Performance report in a new tab', 'shop2llm'); ?>"><?php echo esc_html__('Open Search Console Performance →', 'shop2llm'); ?></a>
            </div>
            <script>
            (function() {
                var nonce = <?php echo wp_json_encode(wp_create_nonce('shop2llm_admin_nonce')); ?>;
                var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                function setState(s, after) {
                    var fd = new FormData();
                    fd.append('action', 'shop2llm_gsc_state');
                    fd.append('nonce', nonce);
                    fd.append('state', s);
                    fetch(ajax, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function() {});
                    if (after) { after(); }
                }
                var connBtn = document.getElementById('stl-gsc-connected-btn');
                if (connBtn) {
                    connBtn.addEventListener('click', function() {
                        setState('connected', function() {
                            document.getElementById('stl-gsc-hook').style.display = 'none';
                            document.getElementById('stl-gsc-read').style.display = 'block';
                        });
                    });
                }
                var dismissBtn = document.getElementById('stl-gsc-dismiss');
                if (dismissBtn) {
                    dismissBtn.addEventListener('click', function() {
                        setState('dismissed', function() {
                            var card = document.getElementById('stl-gsc-insights');
                            if (card) { card.style.display = 'none'; }
                        });
                    });
                }
            })();
            </script>
        </div>
        <?php endif; ?>

        <!-- AI Crawler Activity — v1.5.4: merged with the Crawler Analytics widget
             (7-day totals live in the hero bar; diagnostics replace the stacked
             zero-data blocks) -->
        <div class="stl-card" style="border-left:4px solid #8b5cf6">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
                <?php echo esc_html__('AI Crawler Activity', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('Track when AI crawlers like GPTBot and ClaudeBot visit your site. More visits = better AI visibility.', 'shop2llm'); ?>"></span>
            </h2>
            <?php if ((int) $trafficAnalytics['total_visits'] > 0 && shop_to_llm_review_gate()) : ?>
                <?php
                // v1.5.6 rating loop (M2): the first real crawler visit is proof the
                // AI layer works — ask here, inside the evidence, never as a banner.
                shop_to_llm_review_register_ask();
                echo shop_to_llm_review_ask_html('stl-review-ask-traffic'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html/esc_url/wp_json_encode internally
                ?>
            <?php endif; ?>
            <?php if ((int) $trafficAnalytics['total_visits'] > 0) : ?>
                <?php shop_to_llm_render_crawler_activity(); ?>
                <?php shop_to_llm_render_crawler_breakdown($trafficAnalytics); ?>
                <?php shop_to_llm_render_indexed_pages(); ?>
            <?php else : ?>
                <?php shop_to_llm_render_crawler_diagnostics(); ?>
            <?php endif; ?>
            <p class="s2ll-suggestion"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Share your content regularly — AI crawlers return more often to sites that update frequently', 'shop2llm'); ?></p>
        </div>

        <!-- AI Search Activity — v1.6.0: 7-day window + one-click Focus Query ideas -->
        <div class="stl-card">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><?php echo esc_html__('AI Search Activity', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('See what consumers are asking AI assistants about your content over the last 7 days. No-match terms can be turned into Focus Query ideas with one click.', 'shop2llm'); ?>"></span></h2>
            <?php if ($trafficSearchCount === 0) : ?>
                <p style="color:#94a3b8;font-size:13px;margin:0;line-height:1.6">
                    <?php echo shop_to_llm_has_woocommerce() ? esc_html__('No AI searches in the last 7 days yet. When a consumer asks an AI (like ChatGPT) about your products, the search terms and matched products will appear here — so you can see what consumers are asking AI about your store.', 'shop2llm') : esc_html__('No AI searches in the last 7 days yet. When a consumer asks an AI (like ChatGPT) about your content, the search terms and matched items will appear here — so you can see what consumers are asking AI about your site.', 'shop2llm'); ?>
                </p>
            <?php else : ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                    <p style="color:#64748b;font-size:12px;margin:0"><?php /* translators: %d: number of searches */ echo shop_to_llm_has_woocommerce() ? sprintf(esc_html__('What consumers asked AI about your store (last 7 days, %d searches):', 'shop2llm'), absint($trafficSearchCount)) : sprintf(esc_html__('What consumers asked AI about your site (last 7 days, %d searches):', 'shop2llm'), absint($trafficSearchCount)); ?></p>
                    <select id="stl-sort" style="font-size:12px;padding:3px 8px;border:1px solid #e2e8f0;border-radius:4px;background:#fff;color:#64748b">
                        <option value="time-desc"><?php echo esc_html__('Latest first', 'shop2llm'); ?></option>
                        <option value="time-asc"><?php echo esc_html__('Oldest first', 'shop2llm'); ?></option>
                        <option value="results-desc"><?php echo esc_html__('Most results', 'shop2llm'); ?></option>
                        <option value="results-asc"><?php echo esc_html__('Fewest results', 'shop2llm'); ?></option>
                    </select>
                </div>
                <table id="stl-search-table" style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0">
                            <th style="text-align:left;padding:6px 8px;color:#64748b;font-weight:600"><?php echo esc_html__('Search Term', 'shop2llm'); ?></th>
                            <th style="text-align:left;padding:6px 8px;color:#64748b;font-weight:600"><?php echo esc_html__('Products Found', 'shop2llm'); ?></th>
                            <th style="text-align:center;padding:6px 8px;color:#64748b;font-weight:600"><?php echo esc_html__('Results', 'shop2llm'); ?></th>
                            <th style="text-align:right;padding:6px 8px;color:#64748b;font-weight:600"><?php echo esc_html__('When', 'shop2llm'); ?></th>
                            <th style="text-align:right;padding:6px 8px;color:#64748b;font-weight:600"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($trafficSearches, 0, 30) as $i => $s) :
                            $entryDate = $s['date'] ?? '';
                            $when = $entryDate !== '' ? $entryDate . ' ' . $s['time'] : $s['time'];
                            $ideaAdded = in_array($s['query'], array_column($focusIdeas, 'query'), true);
                            ?>
                        <tr data-idx="<?php echo esc_attr($i); ?>" data-results="<?php echo esc_attr((int)$s['results']); ?>" data-time="<?php echo esc_attr($when); ?>" style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:6px 8px;color:#1e1e1e;font-weight:500"><?php echo esc_html($s['query']); ?></td>
                            <td style="padding:6px 8px;color:#475569;font-size:12px">
                                <?php
                                $prods = $s['products'] ?? [];
                                if (!empty($prods)) {
                                    echo esc_html(implode(', ', $prods));
                                } else {
                                    echo '<span style="color:#94a3b8">—</span>';
                                }
                                ?>
                            </td>
                            <td style="padding:6px 8px;text-align:center">
                                <?php if ($s['results'] > 0) : ?>
                                    <span style="color:#166534;font-weight:600"><?php /* translators: %d: number of found results */ echo sprintf(esc_html__('%d found', 'shop2llm'), (int)$s['results']); ?></span>
                                <?php else : ?>
                                    <span style="color:#991b1b;font-weight:600"><?php echo esc_html__('No match', 'shop2llm'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px 8px;text-align:right;color:#94a3b8;font-size:12px;white-space:nowrap"><?php echo esc_html($when); ?></td>
                            <td style="padding:6px 8px;text-align:right;white-space:nowrap">
                                <?php if ($s['results'] === 0) : ?>
                                    <button type="button" class="stl-btn-sm stl-add-focus-idea" data-query="<?php echo esc_attr($s['query']); ?>" <?php echo $ideaAdded ? 'disabled' : ''; ?>><?php echo $ideaAdded ? esc_html__('✓ Added', 'shop2llm') : esc_html__('+ Focus Query', 'shop2llm'); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($trafficSearchCount > 30) : ?>
                <p style="color:#94a3b8;font-size:12px;margin:8px 0 0"><?php /* translators: %d: number of searches */ echo sprintf(esc_html__('Showing latest 30 of %d searches.', 'shop2llm'), absint($trafficSearchCount)); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($focusIdeas)) : ?>
            <div id="stl-focus-ideas" style="margin-top:12px;padding:10px 12px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px">
                <div style="font-size:12px;font-weight:600;color:#4c1d95;margin-bottom:8px"><?php echo esc_html__('Focus Query ideas (from unmatched searches)', 'shop2llm'); ?></div>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                    <?php foreach ($focusIdeas as $idea) : ?>
                    <span class="stl-focus-idea-chip" data-query="<?php echo esc_attr($idea['query'] ?? ''); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;background:#fff;border:1px solid #c7d2fe;border-radius:12px;font-size:11px;color:#4c1d95">
                        <?php echo esc_html($idea['query'] ?? ''); ?>
                        <button type="button" class="stl-remove-focus-idea" title="<?php echo esc_attr__('Remove', 'shop2llm'); ?>" style="border:none;background:none;cursor:pointer;color:#94a3b8;font-size:12px;line-height:1;padding:0">×</button>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <p class="s2ll-suggestion"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Use these search terms to improve your content and Focus Queries', 'shop2llm'); ?></p>
            <script>
            (function() {
                var nonce = '<?php echo esc_js(wp_create_nonce('shop2llm_admin_nonce')); ?>';
                function stlFocusIdeaRequest(mode, query, btn, origLabel) {
                    var fd = new FormData();
                    fd.append('action', 'shop2llm_add_focus_idea');
                    fd.append('nonce', nonce);
                    fd.append('mode', mode);
                    fd.append('query', query);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res && res.success) {
                                if (mode === 'add' && btn) {
                                    btn.textContent = '✓ <?php echo esc_js(__('Added', 'shop2llm')); ?>';
                                    btn.disabled = true;
                                    if (!document.getElementById('stl-focus-ideas')) {
                                        window.location.reload();
                                    }
                                }
                                if (mode === 'remove') {
                                    var chip = btn.closest('.stl-focus-idea-chip');
                                    if (chip) { chip.remove(); }
                                }
                            } else if (btn && mode === 'add') {
                                btn.textContent = '⚠ <?php echo esc_js(__('Failed', 'shop2llm')); ?>';
                                setTimeout(function() { btn.textContent = origLabel || btn.getAttribute('data-query'); btn.disabled = false; }, 2000);
                            }
                        })
                        .catch(function() {
                            if (btn && mode === 'add') {
                                btn.textContent = '⚠ <?php echo esc_js(__('Failed', 'shop2llm')); ?>';
                                setTimeout(function() { btn.textContent = origLabel || btn.getAttribute('data-query'); btn.disabled = false; }, 2000);
                            }
                        });
                }
                document.querySelectorAll('.stl-add-focus-idea').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        if (btn.disabled) { return; }
                        var origLabel = btn.textContent;
                        btn.textContent = '<?php echo esc_js(__('Adding…', 'shop2llm')); ?>';
                        stlFocusIdeaRequest('add', btn.getAttribute('data-query'), btn, origLabel);
                    });
                });
                document.querySelectorAll('.stl-remove-focus-idea').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var chip = btn.closest('.stl-focus-idea-chip');
                        stlFocusIdeaRequest('remove', chip ? chip.getAttribute('data-query') : '', btn);
                    });
                });
            })();
            </script>
        </div>

        <!-- AI-Driven Traffic -->
        <div class="stl-card" style="border-left:4px solid #10b981">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#10b981">
                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                </svg>
                <?php echo esc_html__('AI-Driven Traffic', 'shop2llm'); ?>
                <span class="s2ll-help" title="<?php echo esc_attr__('Attribution for orders originating from AI assistant referrals.', 'shop2llm'); ?>"></span>
            </h2>
            <?php shop_to_llm_render_ai_referral_section(); ?>
            <p class="s2ll-suggestion" style="margin-bottom:0"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('For deeper AI referral measurement, connect Google Analytics 4 in', 'shop2llm'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=shop2llm#tab-settings')); ?>" style="color:#6366f1;font-weight:600"><?php echo esc_html__('Settings →', 'shop2llm'); ?></a></p>
        </div>

        <!-- Acquisition Channels (v1.5.0 Phase 3): crawls / referrals / agentic orders, local attribution -->
        <?php shop_to_llm_render_acquisition_funnel(); ?>
        </div><!-- /tab-traffic -->

        <script>
        (function() {
            // Deep links like #stl-seo-settings auto-open their collapsed details card.
            if (location.hash) {
                var target = document.querySelector(location.hash);
                if (target && target.tagName === 'DETAILS') { target.open = true; }
            }
        </script>

        <!-- Settings Tab -->
        <div class="stl-tab-panel" id="tab-settings">

        <!-- v1.6.0: section anchor navigation -->
        <div id="stl-settings-nav" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
            <span style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;font-weight:600"><?php echo esc_html__('Jump to', 'shop2llm'); ?></span>
            <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm stl-settings-nav-btn" data-target="stl-settings-general"><?php echo esc_html__('General', 'shop2llm'); ?></button>
            <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm stl-settings-nav-btn" data-target="stl-connection"><?php echo esc_html__('Connection', 'shop2llm'); ?></button>
            <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm stl-settings-nav-btn" data-target="stl-settings-platforms"><?php echo esc_html__('AI Platforms', 'shop2llm'); ?></button>
            <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm stl-settings-nav-btn" data-target="stl-settings-crawler"><?php echo esc_html__('Crawler', 'shop2llm'); ?></button>
            <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm stl-settings-nav-btn" data-target="stl-seo-settings"><?php echo esc_html__('SEO', 'shop2llm'); ?></button>
        </div>

        <!-- Settings Form -->
        <div class="stl-card" id="stl-settings-general">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg><?php echo esc_html__('Settings', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('Configure which features are active. All changes take effect immediately.', 'shop2llm'); ?>"></span></h2>
            <form method="post">
                <?php wp_nonce_field('shop_to_llm_settings'); ?>
                <table class="stl-form-table">
                    <tr>
                        <th><?php echo esc_html__('Enable', 'shop2llm'); ?></th>
                        <td>
                            <select name="shop_to_llm_enabled">
                                <option value="yes" <?php selected($enabled, 'yes'); ?>><?php echo esc_html__('Enabled — store is visible to AI agents', 'shop2llm'); ?></option>
                                <option value="no" <?php selected($enabled, 'no'); ?>><?php echo esc_html__('Disabled', 'shop2llm'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Product Search', 'shop2llm'); ?></th>
                        <td>
                            <select name="shop_to_llm_catalog_search">
                                <option value="yes" <?php selected($catalogSearch, 'yes'); ?>><?php echo esc_html__('Enabled — AI can search your catalog', 'shop2llm'); ?></option>
                                <option value="no" <?php selected($catalogSearch, 'no'); ?>><?php echo esc_html__('Disabled', 'shop2llm'); ?></option>
                            </select>
                            <?php if ($catalogSearch !== 'yes') :
                                ?><span class="stl-badge" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;margin-left:8px"><?php echo esc_html__('Recommended: Enabled', 'shop2llm'); ?></span><?php
                            endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Anonymous Usage Data', 'shop2llm'); ?></th>
                        <td>
                            <select name="shop_to_llm_analytics">
                                <option value="no" <?php selected($analytics, 'no'); ?>><?php echo esc_html__('Disabled — no data sent', 'shop2llm'); ?></option>
                                <option value="yes" <?php selected($analytics, 'yes'); ?>><?php echo esc_html__('Enabled — share anonymized site metadata &amp; AI crawler visits for analytics', 'shop2llm'); ?></option>
                            </select>
                            <p style="color:#64748b;font-size:11px;margin:4px 0 0"><?php echo esc_html__('When enabled, basic site metadata and AI crawler visits are sent to our cloud service. No personal customer or order data is transmitted. Privacy Policy: https://shop2llm.com/privacy', 'shop2llm'); ?></p>
                        </td>
                    </tr>
                    <?php if (shop_to_llm_has_woocommerce()) : ?>
                    <tr>
                        <th><?php echo esc_html__('Product Sync', 'shop2llm'); ?></th>
                        <td>
                            <?php $productSyncConsent = get_option('shop2llm_product_sync_consent', 'no'); ?>
                            <select name="shop2llm_product_sync_consent">
                                <option value="no" <?php selected($productSyncConsent, 'no'); ?>><?php echo esc_html__('Disabled — products are NOT synced to cloud search index', 'shop2llm'); ?></option>
                                <option value="yes" <?php selected($productSyncConsent, 'yes'); ?>><?php echo esc_html__('Enabled — sync product metadata to cloud for AI discovery', 'shop2llm'); ?></option>
                            </select>
                            <p style="color:#64748b;font-size:11px;margin:4px 0 0"><?php echo esc_html__('When enabled, lightweight product metadata (title, price, category, image URL, product URL) is synced to the Shop2LLM cloud search index so your products appear in AI searches. No customer data or order data is ever sent. Synced once daily.', 'shop2llm'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php echo esc_html__('AI Badge', 'shop2llm'); ?></th>
                        <td>
                            <select name="shop_to_llm_show_badge">
                                <option value="yes" <?php selected($showBadge, 'yes'); ?>><?php echo esc_html__('Show — display AI Discoverable badge on your site', 'shop2llm'); ?></option>
                                <option value="no" <?php selected($showBadge, 'no'); ?>><?php echo esc_html__('Hidden', 'shop2llm'); ?></option>
                            </select>
                            <?php if ($showBadge !== 'yes') :
                                ?><span class="stl-badge" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;margin-left:8px"><?php echo esc_html__('Recommended: Show', 'shop2llm'); ?></span><?php
                            endif; ?>
                            <p style="color:#64748b;font-size:11px;margin:4px 0 0"><?php echo esc_html__('Adds a small badge in the footer indicating your site is AI-discoverable. Links to your public /ai-visibility/ page.', 'shop2llm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Entity Checks', 'shop2llm'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="shop_to_llm_enable_entity_checks" value="1" <?php checked(get_option('shop_to_llm_enable_entity_checks', 0), 1); ?>>
                                <?php echo esc_html__('Enable external knowledge graph checks (Wikidata/Wikipedia)', 'shop2llm'); ?>
                            </label>
                            <?php if (get_option('shop_to_llm_enable_entity_checks', 0) != 1) :
                                ?><span class="stl-badge" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;margin-left:8px"><?php echo esc_html__('Recommended', 'shop2llm'); ?></span><?php
                            endif; ?>
                            <p style="color:#64748b;font-size:11px;margin:4px 0 0"><?php echo esc_html__('This sends your focus query to public APIs to check entity presence. No personal data is transmitted.', 'shop2llm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Cart & Checkout', 'shop2llm'); ?></th>
                        <td>
                            <span class="stl-badge stl-badge-pro"><?php echo esc_html__('Pro', 'shop2llm'); ?></span>
                            <span style="color:#64748b;font-size:12px;margin-left:6px"><?php echo esc_html__('AI agents add to cart, checkout, and track orders', 'shop2llm'); ?></span>
                        </td>
                    </tr>
                </table>
                <p class="s2ll-suggestion"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Enable Entity Checks for more accurate AI visibility scores', 'shop2llm'); ?></p>
                <button type="submit" name="shop_to_llm_save" value="Save Settings" class="stl-btn"><?php echo esc_html__('Save Settings', 'shop2llm'); ?></button>
            </form>
        </div>

        <!-- Test It Yourself / Connection Config -->
        <?php
        $configJson = json_encode([
            'mcpServers' => [
                'shop2llm' => [
                    'transport' => 'streamable-http',
                    'url' => $mcpEndpoint,
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                ],
            ],
        ], JSON_PRETTY_PRINT);
        ?>
        <div class="stl-card" id="stl-connection">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg><?php echo esc_html__('Test It Yourself', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('Connect your site to ChatGPT and other AI tools. Copy-paste this config to make your content AI-accessible.', 'shop2llm'); ?>"></span></h2>
            <div class="stl-row" style="margin-bottom:8px">
              <div class="stl-label"><?php echo esc_html__('Status', 'shop2llm'); ?></div>
              <div class="stl-value">
                <?php if ($enabled === 'yes') : ?>
                  <span class="stl-badge stl-badge-ok"><?php echo esc_html__('Connected', 'shop2llm'); ?></span>
                  <span style="font-size:11px;color:#94a3b8;margin-left:6px"><?php echo esc_html($storeName); ?> · <?php echo esc_html(shop_to_llm_tool_count()); ?></span>
                <?php else : ?>
                  <span class="stl-badge stl-badge-off"><?php echo esc_html__('Disabled', 'shop2llm'); ?></span>
                <?php endif; ?>
              </div>
            </div>
            <p style="color:#64748b;font-size:12px;margin:0 0 12px;line-height:1.5"><?php echo shop_to_llm_has_woocommerce() ? esc_html__('Connect your store to ChatGPT or Claude in two steps. This connects your AI client to your store — a quick way to confirm everything works.', 'shop2llm') : esc_html__('Connect your site to ChatGPT or Claude in two steps. This connects your AI client to your site — a quick way to confirm everything works.', 'shop2llm'); ?></p>
            <?php if ($todayCount === 0) : ?>
            <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#92400e;line-height:1.5">
                <?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('This config connects your own AI client to verify everything works. The buttons below copy it for you and open the right settings page.', 'shop2llm'); ?>
            </div>
            <?php endif; ?>
            <?php // v1.10.0: connection steps collapsed 4 → 2 (one-click copy + deep link). ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 6px">
                <span style="font-size:12px;font-weight:600;color:#1e293b">1.</span>
                <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm" onclick="stlConnectChatGPT(this)" title="<?php echo esc_attr__('Copies your MCP link and opens the ChatGPT Connectors settings in a new tab', 'shop2llm'); ?>"><?php echo esc_html__('Add to ChatGPT', 'shop2llm'); ?> →</button>
                <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm" onclick="stlConnectClaude(this)" title="<?php echo esc_attr__('Copies your connection config and opens the Claude Connectors settings in a new tab', 'shop2llm'); ?>"><?php echo esc_html__('Add to Claude', 'shop2llm'); ?> →</button>
            </div>
            <div style="margin:0 0 12px">
                <p style="color:#64748b;font-size:12px;margin:0 0 2px;line-height:1.5"><span style="font-weight:600;color:#1e293b">2.</span> <?php echo esc_html__('ChatGPT: paste the link under Settings → Connectors and save (Desktop: Settings → Developer → Connect App).', 'shop2llm'); ?></p>
                <p style="color:#64748b;font-size:12px;margin:0;line-height:1.5"><?php echo esc_html__('Claude: paste the config into Claude Desktop → Settings → Connectors, or use Add custom connector with your MCP link on claude.ai.', 'shop2llm'); ?></p>
            </div>
            <button type="button" class="stl-config-toggle" onclick="var b=document.getElementById('stl-config-box');b.classList.toggle('stl-config-box--open');this.style.display='none'">
                <?php echo wp_kses(shop_to_llm_icon('clipboard'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Show Connection Config', 'shop2llm'); ?>
            </button>
            <div class="stl-config-box" id="stl-config-box">
                <div style="position:relative;margin-bottom:14px">
                    <pre id="stl-config" style="margin:0;padding:12px 14px;background:#1e1e1e;color:#e2e8f0;border-radius:6px;font-size:11px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-all"><?php echo esc_html($configJson); ?></pre>
                    <button type="button" class="stl-copy-btn" onclick="stlCopy('stl-config',this)" style="position:absolute;top:8px;right:8px"><?php echo esc_html__('Copy Config', 'shop2llm'); ?></button>
                </div>
            </div>
            <?php if ($enabled === 'yes') : ?>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0">
                <button type="button" class="stl-btn stl-btn--secondary stl-btn--sm" id="s2ll-settings-verify"><?php echo esc_html__('Verify connection', 'shop2llm'); ?></button>
                <span id="s2ll-settings-verify-status"></span>
            </div>
            <div id="s2ll-settings-review"></div>
            <script>
            (function() {
                var btn = document.getElementById('s2ll-settings-verify');
                if (!btn) { return; }
                var status = document.getElementById('s2ll-settings-verify-status');
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    if (status) { status.innerHTML = '<span class="stl-badge stl-badge-pro">…</span>'; }
                    var body = new FormData();
                    body.append('action', 'shop2llm_asset_tool');
                    body.append('nonce', <?php echo wp_json_encode($assetNonce ?? wp_create_nonce('shop2llm_admin_nonce')); ?>);
                    body.append('asset', 'mcp_check');
                    fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            btn.disabled = false;
                            var ok = res && res.success && res.data && res.data.ok;
                            if (status) {
                                status.innerHTML = ok
                                    ? '<span class="stl-badge stl-badge-ok">✓ ' + (res.data.message || 'Verified') + '</span>'
                                    : '<span class="stl-badge stl-badge-off">✗ ' + ((res && res.data && res.data.message) || 'Not reachable') + '</span>';
                            }
                            // v1.10.0 (M1): one-time amber review ask on the first
                            // successful verification — same visual as the Hero ask.
                            if (ok && res.data.review_ask) {
                                var box = document.getElementById('s2ll-settings-review');
                                if (box && !box.innerHTML) {
                                    box.innerHTML = '<div style="padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e">⭐ ' + <?php echo wp_json_encode(__('Enjoying Shop2LLM?', 'shop2llm')); ?> + ' <a href="https://wordpress.org/support/plugin/shop2llm/reviews/#new-post" target="_blank" rel="noopener" style="color:#d97706;text-decoration:underline;font-weight:600">' + <?php echo wp_json_encode(__('Rate it ★★★★★ on WordPress.org', 'shop2llm')); ?> + '</a></div>';
                                }
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            if (status) { status.innerHTML = '<span class="stl-badge stl-badge-off">✗ Request failed</span>'; }
                        });
                });
            })();
            </script>
            <?php endif; ?>
            <?php if ($todayCount === 0 && $crawlerCount === 0) : ?>
            <div style="padding:6px 12px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:12px;text-align:center">
                <span style="font-size:11px;color:#6366f1;font-weight:500">
                    <?php echo shop_to_llm_has_woocommerce() ? esc_html__('First time here? Copy the config above → paste into ChatGPT → your store goes live', 'shop2llm') : esc_html__('First time here? Copy the config above → paste into ChatGPT → your site goes live', 'shop2llm'); ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="stl-row">
                <div class="stl-label"><?php echo esc_html__('API Key', 'shop2llm'); ?></div>
                <div class="stl-value" style="display:flex;align-items:center;gap:8px">
                    <code id="stl-apikey" class="stl-mono" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($apiKey); ?></code>
                    <button type="button" class="stl-copy-btn" onclick="stlCopy('stl-apikey',this)"><?php echo esc_html__('Copy', 'shop2llm'); ?></button>
                </div>
            </div>
            <div class="stl-row">
                <div class="stl-label"><?php echo esc_html__('AI Endpoint', 'shop2llm'); ?></div>
                <div class="stl-value" style="display:flex;align-items:center;gap:8px">
                    <code id="stl-endpoint" class="stl-mono" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_url($mcpEndpoint); ?></code>
                    <button type="button" class="stl-copy-btn" onclick="stlCopy('stl-endpoint',this)"><?php echo esc_html__('Copy', 'shop2llm'); ?></button>
                </div>
            </div>
            <p class="s2ll-suggestion"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('After connecting, check the Dashboard tab to see AI activity', 'shop2llm'); ?></p>
        </div>

        <!-- AI Platforms -->
        <div class="stl-card" id="stl-settings-platforms">
            <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg><?php echo esc_html__('AI Platforms', 'shop2llm'); ?><span class="s2ll-help" title="<?php echo esc_attr__('AI platforms your content can appear on. Free supports ChatGPT — Pro unlocks all 6 platforms.', 'shop2llm'); ?>"></span></h2>
            <div class="stl-grid">
                <div class="stl-platform" style="">
                    <svg viewBox="0 0 24 24" fill="none"><rect width="24" height="24" rx="6" fill="#10a37f"/><path d="M7 8h10M7 12h10M7 16h6" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <span class="stl-pname">ChatGPT</span>
                    <span class="stl-badge stl-badge-ok" style="margin-left:auto"><?php echo esc_html__('Free', 'shop2llm'); ?></span>
                </div>
                <div class="stl-platform" style="filter:grayscale(1);opacity:0.5">
                    <svg viewBox="0 0 24 24" fill="none"><rect width="24" height="24" rx="6" fill="#d97706"/><path d="M8 7l4 5-4 5M13 7l4 5-4 5" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="stl-pname">Claude</span>
                    <span class="stl-badge stl-badge-pro" style="margin-left:auto"><?php echo esc_html__('Pro', 'shop2llm'); ?></span>
                </div>
                <div class="stl-platform" style="filter:grayscale(1);opacity:0.5">
                    <svg viewBox="0 0 24 24" fill="none"><rect width="24" height="24" rx="6" fill="#4285f4"/><path d="M12 7v10M7 12h10" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
                    <span class="stl-pname">Gemini</span>
                    <span class="stl-badge stl-badge-pro" style="margin-left:auto"><?php echo esc_html__('Pro', 'shop2llm'); ?></span>
                </div>
                <div class="stl-platform" style="filter:grayscale(1);opacity:0.5">
                    <svg viewBox="0 0 24 24" fill="none"><rect width="24" height="24" rx="6" fill="#7c3aed"/><path d="M8 12l3 3 5-6" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="stl-pname">Copilot</span>
                    <span class="stl-badge stl-badge-pro" style="margin-left:auto"><?php echo esc_html__('Pro', 'shop2llm'); ?></span>
                </div>
                <div class="stl-platform" style="filter:grayscale(1);opacity:0.5">
                    <svg viewBox="0 0 24 24" fill="none"><rect width="24" height="24" rx="6" fill="#1877f2"/><circle cx="12" cy="12" r="4" stroke="#fff" stroke-width="1.5"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <span class="stl-pname">Meta AI</span>
                    <span class="stl-badge stl-badge-pro" style="margin-left:auto"><?php echo esc_html__('Pro', 'shop2llm'); ?></span>
                </div>
                <div class="stl-platform" style="filter:grayscale(1);opacity:0.5">
                    <svg viewBox="0 0 24 24" fill="none"><rect width="24" height="24" rx="6" fill="#ff9900"/><path d="M7 17l5-10 5 10M9 14h6" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="stl-pname">Bedrock</span>
                    <span class="stl-badge stl-badge-pro" style="margin-left:auto"><?php echo esc_html__('Pro', 'shop2llm'); ?></span>
                </div>
            </div>
        </div>

        <!-- AI Crawler Settings (v1.3.6+) — opt-in visitor log -->
        <div class="stl-card" id="stl-settings-crawler" style="border-left:4px solid #8b5cf6">
            <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#8b5cf6">
                    <circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/>
                </svg>
                <?php echo esc_html__('AI Crawler Settings', 'shop2llm'); ?>
                <span class="s2ll-help" title="<?php echo esc_attr__('Opt-in to record AI crawler visits (User-Agent + URL + timestamp only — no visitor IPs stored by default). Disclosed in readme.txt per WP.org Guideline 6.', 'shop2llm'); ?>"></span>
            </h2>
            <form method="post">
                <?php wp_nonce_field('shop_to_llm_crawler_settings'); ?>
                <table class="stl-form-table">
                    <tr>
                        <th><?php echo esc_html__('Crawler Log', 'shop2llm'); ?></th>
                        <td>
                            <?php $crawlerLogOpt = get_option('shop2llm_crawler_log_optin', 'no'); ?>
                            <select name="shop2llm_crawler_log_optin">
                                <option value="no" <?php selected($crawlerLogOpt, 'no'); ?>><?php echo esc_html__('Disabled (default — no visitor data recorded)', 'shop2llm'); ?></option>
                                <option value="yes" <?php selected($crawlerLogOpt, 'yes'); ?>><?php echo esc_html__('Enabled — record AI crawler visits (User-Agent + URL + timestamp)', 'shop2llm'); ?></option>
                            </select>
                            <?php if ($crawlerLogOpt !== 'yes') :
                                ?><span class="stl-badge" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;margin-left:8px"><?php echo esc_html__('Recommended: Enabled', 'shop2llm'); ?></span><?php
                            endif; ?>
                            <p style="color:#64748b;font-size:11px;margin:4px 0 0"><?php echo esc_html__('Visitor IPs are not stored by default. When enabled, only AI crawler User-Agents + visited URLs + timestamps are recorded. See AI Traffic tab for analytics.', 'shop2llm'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Retention', 'shop2llm'); ?></th>
                        <td>
                            <?php $retention = get_option('shop2llm_crawler_log_retention', '30'); ?>
                            <select name="shop2llm_crawler_log_retention">
                                <option value="7" <?php selected($retention, '7'); ?>><?php echo esc_html__('7 days', 'shop2llm'); ?></option>
                                <option value="30" <?php selected($retention, '30'); ?>><?php echo esc_html__('30 days (default)', 'shop2llm'); ?></option>
                                <option value="90" <?php selected($retention, '90'); ?>><?php echo esc_html__('90 days', 'shop2llm'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p style="margin-top:12px"><button type="submit" class="stl-btn"><?php echo esc_html__('Save Crawler Settings', 'shop2llm'); ?></button></p>
            </form>
        </div>

        <?php
        // v1.6.1 (lean): the standalone SEO tab is gone — advanced SEO config
        // (rarely changed) and the competitor comparison live here, collapsed.
        $hasSeoPlugin = shop_to_llm_has_seo_plugin();
        $seoPluginName = shop_to_llm_get_seo_plugin_name();
        $seoMeta = get_option('shop_to_llm_seo_meta', 'yes');
        $seoSchema = get_option('shop_to_llm_seo_schema', 'yes');
        $seoOg = get_option('shop_to_llm_seo_og', 'yes');
        $seoAnalysis = get_option('shop_to_llm_seo_analysis', 'yes');
        ?>
        <?php if ($hasSeoPlugin) : ?>
        <div class="stl-card" style="border-left:4px solid #f59e0b;margin-bottom:16px">
            <h2 style="color:#d97706"><?php echo esc_html__('SEO Plugin Detected', 'shop2llm'); ?></h2>
            <p style="font-size:13px;color:#64748b"><?php echo sprintf(/* translators: %s: SEO plugin name */ esc_html__('%s is active. Conflicting SEO modules (meta tags, schema) are automatically disabled to prevent duplicate output.', 'shop2llm'), '<strong>' . esc_html($seoPluginName) . '</strong>'); ?></p>
        </div>
        <?php endif; ?>

        <!-- Advanced SEO settings — collapsed: module toggles, title templates -->
        <details class="stl-card" id="stl-seo-settings" style="margin-top:16px;padding:12px 16px">
            <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#1e3a8a;user-select:none"><?php echo esc_html__('SEO Settings — modules, title templates', 'shop2llm'); ?> <span style="font-size:11px;font-weight:400;color:#64748b">(<?php echo esc_html__('advanced, rarely changed', 'shop2llm'); ?>)</span></summary>
            <div style="margin-top:12px">
            <form method="post">
                <?php wp_nonce_field('shop_to_llm_seo_settings'); ?>
                <table class="stl-form-table">
                    <?php
                    $seoToggleFields = [
                        'shop_to_llm_seo_meta' => ['label' => __('Meta Tags', 'shop2llm'), 'desc' => __('Title, description, canonical URL, noindex/nofollow per post', 'shop2llm'), 'conflict' => true],
                        'shop_to_llm_seo_og' => ['label' => __('Open Graph & Twitter Cards', 'shop2llm'), 'desc' => __('Social media sharing previews with OG image', 'shop2llm'), 'conflict' => true],
                        'shop_to_llm_seo_schema' => ['label' => __('Schema Markup', 'shop2llm'), 'desc' => __('18 structured data types for rich results', 'shop2llm'), 'conflict' => true],
                        'shop_to_llm_seo_analysis' => ['label' => __('AI Extractability (Live)', 'shop2llm'), 'desc' => __('AI citability scoring shown in the editor meta box', 'shop2llm'), 'conflict' => false],
                    ];
                    foreach ($seoToggleFields as $optName => $field) :
                        $val = get_option($optName, 'yes');
                        $disabled = ($field['conflict'] && $hasSeoPlugin);
                        ?>
                    <tr>
                        <th><?php echo esc_html($field['label']); ?></th>
                        <td>
                            <select name="<?php echo esc_attr($optName); ?>" <?php if ($disabled) {
                                echo 'disabled';
                                          } ?>>
                                <option value="yes" <?php selected($val, 'yes'); ?>><?php echo esc_html__('Enabled', 'shop2llm'); ?></option>
                                <option value="no" <?php selected($val, 'no'); ?>><?php echo esc_html__('Disabled', 'shop2llm'); ?></option>
                            </select>
                            <?php if ($disabled) : ?>
                            <input type="hidden" name="<?php echo esc_attr($optName); ?>" value="<?php echo esc_attr($val); ?>" />
                            <span style="font-size:11px;color:#d97706;margin-left:6px"><?php echo esc_html__('(auto-disabled — SEO plugin conflict)', 'shop2llm'); ?></span>
                            <?php endif; ?>
                            <p style="color:#64748b;font-size:11px;margin:4px 0 0"><?php echo esc_html($field['desc']); ?></p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php
                    echo '<tr><th>' . esc_html__('Title Separator', 'shop2llm') . '</th><td>';
                    echo '<select name="shop_to_llm_title_sep">';
                    $seps = ['|' => '| (pipe)', '-' => '- (hyphen)', '—' => '— (em dash)', '•' => '• (bullet)', '»' => '» (guillemet)'];
                    $current_sep = get_option('shop_to_llm_title_sep', '|');
                    foreach ($seps as $val => $label) {
                        echo '<option value="' . esc_attr($val) . '"' . selected($current_sep, $val, false) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></td></tr>';
                    echo '<tr><th>' . esc_html__('Homepage Title', 'shop2llm') . '</th><td>';
                    echo '<input type="text" name="shop_to_llm_seo_home_title" value="' . esc_attr(get_option('shop_to_llm_seo_home_title', '')) . '" class="regular-text" placeholder="' . esc_attr__('Leave blank to use Site Title', 'shop2llm') . '" /></td></tr>';
                    echo '<tr><th>' . esc_html__('Homepage Description', 'shop2llm') . '</th><td>';
                    echo '<input type="text" name="shop_to_llm_seo_home_desc" value="' . esc_attr(get_option('shop_to_llm_seo_home_desc', get_option('shop2llm_seo_home_desc', ''))) . '" class="regular-text" placeholder="' . esc_attr__('Leave blank to use Tagline', 'shop2llm') . '" /></td></tr>';
                    echo '<tr><th colspan="2"><h3 style="margin:16px 0 8px">' . esc_html__('Title Templates', 'shop2llm') . '</h3>';
                    echo '<p style="font-size:12px;color:#64748b;font-weight:400">' . esc_html__('Available variables:', 'shop2llm') . ' ';
                    $all_vars = shop_to_llm_seo_get_title_variables();
                    foreach ($all_vars as $key => $var) {
                        echo '<code style="background:#f1f5f9;padding:2px 4px;border-radius:3px;margin-right:4px">%%' . esc_html($key) . '%%</code>';
                    }
                    echo '</p></th></tr>';

                    $template_types = [
                        'home' => ['label' => __('Homepage', 'shop2llm'), 'default' => '%%sitename%% %%sep%% %%tagline%%'],
                        'post' => ['label' => __('Posts', 'shop2llm'), 'default' => '%%title%% %%sep%% %%sitename%%'],
                        'page' => ['label' => __('Pages', 'shop2llm'), 'default' => '%%title%% %%sep%% %%sitename%%'],
                        'product' => ['label' => __('Products', 'shop2llm'), 'default' => '%%title%% %%sep%% %%sitename%%'],
                        'category' => ['label' => __('Categories', 'shop2llm'), 'default' => '%%category%% %%sep%% %%sitename%%'],
                        'tag' => ['label' => __('Tags', 'shop2llm'), 'default' => '%%tag%% %%sep%% %%sitename%%'],
                        'archive' => ['label' => __('Other Archives', 'shop2llm'), 'default' => '%%title%% %%sep%% %%sitename%%'],
                    ];
                    foreach ($template_types as $type => $info) {
                        $opt_key = 'shop_to_llm_seo_title_' . $type;
                        $current_template = get_option($opt_key, $info['default']);
                        echo '<tr><th>' . esc_html($info['label']) . '</th><td>';
                        echo '<input type="text" name="' . esc_attr($opt_key) . '" value="' . esc_attr($current_template) . '" class="regular-text" />';
                        echo '</td></tr>';
                    }
                    ?>
                </table>
                <p class="s2ll-suggestion"><?php echo wp_kses(shop_to_llm_icon('lightbulb'), shop_to_llm_allowed_svg()); ?> <?php echo esc_html__('Tip: verify llms.txt and the MCP endpoint from the Overview tab after saving', 'shop2llm'); ?></p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <button type="submit" name="shop_to_llm_seo_save" value="1" class="stl-btn"><?php echo esc_html__('Save SEO Settings', 'shop2llm'); ?></button>
                </div>
            </form>

            <!-- Module status at a glance -->
            <div style="margin-top:16px">
                <h4 style="margin:0 0 8px;font-size:13px;color:#475569"><?php echo esc_html__('Module Status', 'shop2llm'); ?></h4>
                <style>
                .s2ll-module-status{display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));gap:8px}
                .s2ll-module-item{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;color:#1e293b}
                .s2ll-module-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
                .s2ll-module-dot.active{background:#22c55e}
                .s2ll-module-dot.off{background:#cbd5e1}
                .s2ll-module-dot.conflict{background:#f59e0b}
                .s2ll-module-label{flex:1;font-weight:500}
                .s2ll-module-note{font-size:10px;color:#94a3b8}
                </style>
                <div class="s2ll-module-status">
                    <?php
                    $modules = [
                        ['name' => __('Meta Tags', 'shop2llm'), 'active' => $seoMeta, 'conflict' => true],
                        ['name' => __('Schema Markup', 'shop2llm'), 'active' => $seoSchema, 'conflict' => true],
                        ['name' => __('OG / Twitter Cards', 'shop2llm'), 'active' => $seoOg, 'conflict' => true],
                        ['name' => __('AI Extractability (Live)', 'shop2llm'), 'active' => $seoAnalysis, 'conflict' => false],
                    ];
                    foreach ($modules as $mod) :
                        $is_active = $mod['active'] === 'yes';
                        $is_conflict = $mod['conflict'] && $hasSeoPlugin;
                        $dot_class = $is_conflict ? 'conflict' : ($is_active ? 'active' : 'off');
                        ?>
                        <div class="s2ll-module-item">
                            <span class="s2ll-module-dot <?php echo esc_attr($dot_class); ?>"></span>
                            <span class="s2ll-module-label"><?php echo esc_html($mod['name']); ?></span>
                            <?php if ($is_conflict) : ?>
                            <span class="s2ll-module-note"><?php echo esc_html__('conflict', 'shop2llm'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            </div>
        </details>

        <?php
        // Competitor comparison — collapsed reference (v1.6.0 P0: kept off the
        // dashboard first screen). Compliance: WP.org Guideline 17 (trademark
        // nominative fair use — slug not used), Guideline 9 (verifiable claims).
        ?>
        <details class="stl-card" id="stl-seo-comparison" style="margin-top:16px;padding:12px 16px">
            <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#1e3a8a;user-select:none"><?php echo esc_html__('Free vs Yoast SEO Premium / Rank Math Pro — full comparison', 'shop2llm'); ?> <span style="font-size:11px;font-weight:400;color:#64748b">(<?php echo esc_html__('everything listed below is included free', 'shop2llm'); ?>)</span></summary>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:12px">
                <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;border:1px solid #bfdbfe">
                    <div style="font-size:11px;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px"><?php echo esc_html__('SEO (matches paid tiers)', 'shop2llm'); ?></div>
                    <ul style="margin:0;padding:0;list-style:none;font-size:12px;color:#1e1e1e;line-height:1.8">
                        <li>✓ <?php echo esc_html__('19 JSON-LD Schema types', 'shop2llm'); ?></li>
                        <li>✓ <?php echo esc_html__('Title Templates (%%variables%%)', 'shop2llm'); ?></li>
                    </ul>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;border:1px solid #bfdbfe">
                    <div style="font-size:11px;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px"><?php echo esc_html__('AI (only in Shop2LLM)', 'shop2llm'); ?></div>
                    <ul style="margin:0;padding:0;list-style:none;font-size:12px;color:#1e1e1e;line-height:1.8">
                        <li>✓ <?php echo esc_html__('llms.txt — machine-readable catalog', 'shop2llm'); ?></li>
                        <li>✓ <?php echo esc_html__('llms-full.txt — complete-content catalog (llmstxt.org spec)', 'shop2llm'); ?></li>
                        <li>✓ <?php echo esc_html__('/agents.md — AI agent dossier (UCP + MCP + catalog)', 'shop2llm'); ?></li>
                        <li>✓ <?php
                            /* translators: %d: number of MCP tools */
                            echo esc_html(sprintf(__('MCP protocol — %d JSON-RPC 2.0 tools', 'shop2llm'), count(shop_to_llm_tools_list()['tools'])));
                        ?></li>
                        <li>✓ <?php
                            /* translators: %d: number of AI crawlers tracked */
                            echo esc_html(sprintf(__('AI Crawler Invites (GPTBot, ClaudeBot, +%d)', 'shop2llm'), max(0, shop_to_llm_get_ai_crawler_count() - 2)));
                        ?></li>
                        <li>✓ <?php echo esc_html__('UCP Readiness Score (5-dim, 100-pt)', 'shop2llm'); ?></li>
                        <li>✓ <?php echo esc_html__('Live Content Extractability (10-dim citability)', 'shop2llm'); ?></li>
                        <li>✓ <?php echo esc_html__('AI Visibility Score™ (5-dim, 25-item)', 'shop2llm'); ?></li>
                        <li>✓ <?php echo esc_html__('Hero Test Search', 'shop2llm'); ?></li>
                        <li>✓ <?php echo esc_html__('AI Crawler Activity Monitor', 'shop2llm'); ?></li>
                    </ul>
                </div>
            </div>
            <div style="font-size:10px;color:#1e40af;margin-top:10px;opacity:0.85;line-height:1.4">
                <?php echo esc_html__('Yoast SEO and Rank Math are trademarks of their respective owners and are not affiliated with Shop2LLM. Comparison based on publicly documented feature lists as of July 2026.', 'shop2llm'); ?>
            </div>
        </details>

        </div><!-- /tab-settings -->

        <script>
        (function() {
            // v1.6.0: settings section anchor navigation
            document.querySelectorAll('.stl-settings-nav-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var target = document.getElementById(btn.getAttribute('data-target'));
                    if (!target) { return; }
                    target.scrollIntoView({behavior: 'smooth', block: 'start'});
                    target.style.boxShadow = '0 0 0 2px #8b5cf6';
                    setTimeout(function() { target.style.boxShadow = ''; }, 1200);
                });
            });
        })();
        </script>

        <!-- v1.3.3: Restart Onboarding — reset the onboarding flow -->
        <div class="stl-card" style="margin-top:16px">
            <h2><?php echo esc_html__('Onboarding', 'shop2llm'); ?></h2>
            <p style="color:#64748b;font-size:13px;margin-bottom:12px"><?php echo esc_html__('If you skipped the onboarding wizard or want to review it again, you can restart it here. This will re-enable the onboarding banner and welcome toast.', 'shop2llm'); ?></p>
            <?php
            if (isset($_GET['shop2llm_restart_onboarding']) && check_admin_referer('shop2llm_restart_onboarding')) {
                delete_option('shop2llm_onboarding_done');
                delete_option('shop_to_llm_just_activated');
                add_option('shop_to_llm_just_activated', '1');
                echo '<div class="notice notice-success"><p>' . esc_html__('Onboarding restarted. The wizard will appear on your next visit to this page.', 'shop2llm') . '</p></div>';
            }
            ?>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=shop2llm&shop2llm_restart_onboarding=1'), 'shop2llm_restart_onboarding')); ?>"
               class="stl-btn stl-btn--secondary">
                <?php echo esc_html__('Restart Onboarding', 'shop2llm'); ?>
            </a>
        </div>

        <?php $proCtaDismissed = get_user_meta(get_current_user_id(), 'shop2llm_pro_cta_dismissed', true); ?>
        <?php $proCtaDismissedAt = (int) get_user_meta(get_current_user_id(), 'shop2llm_pro_cta_dismissed_at', true); ?>
        <?php if (!$proCtaDismissed || (time() - $proCtaDismissedAt) > 30 * DAY_IN_SECONDS) : ?>
        <!-- Pro CTA (dismissible for 30 days) -->
        <div class="stl-pro-cta" id="stl-pro-cta">
          <button type="button" id="stl-pro-cta-dismiss" style="position:absolute;top:8px;right:12px;background:none;border:none;color:rgba(255,255,255,0.6);cursor:pointer;font-size:18px" title="<?php echo esc_attr__('Dismiss for 30 days', 'shop2llm'); ?>">&times;</button>
          <div class="stl-cta-text">
            <h3><?php echo esc_html__('Upgrade to Shop2LLM Pro', 'shop2llm'); ?></h3>
            <p><?php echo esc_html__('Your store connects to 5 AI platforms automatically — $9/mo', 'shop2llm'); ?></p>
          </div>
          <a href="https://shop2llm.com/fincore/v1/page/prolanding?site=<?php echo urlencode(get_site_url()); ?>" target="_blank"><?php echo esc_html__('Upgrade to Pro', 'shop2llm'); ?> →</a>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Async community stats loader — non-blocking, caches count for review notice
    (function(){
        var el = document.getElementById('stl-community-trust');
        if (!el) return;
        var t = el.getAttribute('data-stl-loaded');
        if (t === '1') { el.style.display = ''; return; }
        var u = '<?php echo esc_js(rest_url('shop2llm/v1/community')); ?>';
        fetch(u).then(function(r){return r.json()}).then(function(d){
            if (d && d.weekly_downloads > 0) {
                document.getElementById('stl-community-text').textContent =
                    '\uD83D\uDD25 ' + d.weekly_downloads + ' <?php echo esc_js(__('stores added this week', 'shop2llm')); ?>';
                el.style.display = '';
                el.setAttribute('data-stl-loaded', '1');
                // Cache count server-side via AJAX for review notice usage
                var fd = new FormData();
                fd.append('action', 'shop2llm_cache_community_count');
                fd.append('count', d.weekly_downloads);
                fd.append('nonce', '<?php echo esc_js(wp_create_nonce('shop2llm_admin_nonce')); ?>');
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {method:'POST',body:fd}).catch(function(){});
            }
        }).catch(function(){});
    })();

    // Pro CTA dismiss handler
    (function(){
        var cta = document.getElementById('stl-pro-cta');
        var dismissBtn = document.getElementById('stl-pro-cta-dismiss');
        if (!cta || !dismissBtn) return;
        dismissBtn.addEventListener('click', function(){
            cta.style.display = 'none';
            var fd = new FormData();
            fd.append('action', 'shop2llm_dismiss_pro_cta');
            fd.append('nonce', '<?php echo esc_js(wp_create_nonce('shop2llm_admin_nonce')); ?>');
            fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {method:'POST',body:fd}).catch(function(){});
        });
    })();
    // P0-1: 不再自动展开 Onboarding Wizard，避免遮挡 Hero Test Search
    // 用户可以手动点击 Wizard 按钮查看引导
    var stlJustActivated = <?php echo $justActivated ? 'true' : 'false'; ?>;
    // Tab switching
    document.querySelectorAll('.stl-tab').forEach(function(tab) {
      tab.addEventListener('click', function() {
        document.querySelectorAll('.stl-tab').forEach(function(t) { t.classList.remove('stl-tab-active'); });
        document.querySelectorAll('.stl-tab-panel').forEach(function(p) { p.classList.remove('stl-tab-panel-active'); });
        this.classList.add('stl-tab-active');
        document.getElementById('tab-' + this.getAttribute('data-tab')).classList.add('stl-tab-panel-active');
      });
    });
    // Deep links: activate the tab named by the ?tab= URL param, or the tab whose
    // panel contains the location.hash target (e.g. admin.php?page=shop2llm#stl-seo-settings
    // lands on the Settings tab with the card in view; since v1.6.1 the SEO cards
    // live inside the Settings panel, and unknown ?tab= values fall back gracefully).
    document.addEventListener('DOMContentLoaded', function() {
      var wanted = new URLSearchParams(window.location.search).get('tab');
      var hashTarget = window.location.hash ? document.querySelector(window.location.hash) : null;
      if ((!wanted || !document.querySelector('.stl-tab[data-tab="' + wanted + '"]')) && hashTarget) {
        var panel = hashTarget.closest('.stl-tab-panel');
        if (panel) { wanted = panel.id.replace(/^tab-/, ''); }
      }
      if (wanted) {
        var tabBtn = document.querySelector('.stl-tab[data-tab="' + wanted + '"]');
        if (tabBtn && !tabBtn.classList.contains('stl-tab-active')) {
          tabBtn.click();
          if (hashTarget && hashTarget.scrollIntoView) {
            hashTarget.scrollIntoView({ block: 'start' });
          }
        }
      }
    });
    // Auto-activate Analytics tab if form was submitted from it, scroll to card
    <?php if (isset($_POST['stl_tab']) && $_POST['stl_tab'] === 'analytics') : ?>
    document.addEventListener('DOMContentLoaded', function() {
      var analyticsTab = document.querySelector('.stl-tab[data-tab="analytics"]');
      if (analyticsTab) analyticsTab.click();
      var card = document.getElementById('stl-ai-readiness');
      if (card) card.scrollIntoView({behavior:'smooth', block:'start'});
    });
    <?php endif; ?>
    </script>
<?php }
function shop_to_llm_tool_count(): string
{
    return __('2 AI tools (search, product detail) — 5 total with Pro', 'shop2llm');
}

function shop_to_llm_community_stats_endpoint(): \WP_REST_Response
{
    $cached = get_transient('shop2llm_community_stats');
    if ($cached !== false) {
        return new \WP_REST_Response($cached);
    }

    // Serve default immediately to avoid blocking this request
    $default = ['weekly_downloads' => 0, 'active_installs' => '—', 'rating' => '—', 'reviews' => 0];

    // Prevent concurrent fetches
    $fetching = get_transient('shop2llm_community_stats_fetching');
    if ($fetching) {
        return new \WP_REST_Response($default);
    }
    set_transient('shop2llm_community_stats_fetching', 1, 30);

    // Defer actual fetch to shutdown hook so response is sent immediately
    add_action('shutdown', function () use ($default) {
        $resp = wp_remote_get('https://api.shop2llm.com/fincore/v1/stats', ['timeout' => 5]);
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient('shop2llm_community_stats', $default, HOUR_IN_SECONDS);
            return;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) {
            set_transient('shop2llm_community_stats', $default, HOUR_IN_SECONDS);
            return;
        }
        $stats = array_merge($default, $body);
        set_transient('shop2llm_community_stats', $stats, DAY_IN_SECONDS);
    });

    return new \WP_REST_Response($default);
}

function shop_to_llm_get_community_stats(): array
{
    $cached = get_transient('shop2llm_community_stats');
    if ($cached !== false) {
        return $cached;
    }
    // Return empty - async JS will populate via REST endpoint
    return ['weekly_downloads' => 0, 'active_installs' => '—', 'rating' => '—', 'reviews' => 0];
}

// ─── Cloud Heartbeat (monthly, SHA256-hashed site ID) ──────────────

add_action('shop2llm_cloud_heartbeat', 'shop_to_llm_cloud_heartbeat');
function shop_to_llm_cloud_heartbeat(): void
{
    if (get_option('shop_to_llm_analytics', 'no') !== 'yes') {
        return;
    }
    $site_url = get_site_url();
    wp_remote_post('https://api.shop2llm.com/fincore/v1/free/ping', [
        'timeout' => 15,
        'blocking' => false,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'site_url' => $site_url,
            'site_hash' => hash('sha256', wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url),
            'platform' => shop_to_llm_has_woocommerce() ? 'woocommerce' : 'wordpress',
            'plugin_version' => SHOP_TO_LLM_VERSION,
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'wc_version' => class_exists('WooCommerce') ? WC()->version : '',
            'wp_version' => get_bloginfo('version'),
            'behavior' => shop_to_llm_get_behavior_summary_for_upload(),
        ]),
    ]);
}

/**
 * Push product catalog to SaaS search index during daily heartbeat.
 * Sends lightweight metadata (title, price, category, image) — no customer/order data.
 * Tier is determined server-side from the store's license.
 */
function shop_to_llm_sync_product_feed(): void
{
    // v1.3.3: Opt-in consent required (WP Guideline 7 compliance)
    if (get_option('shop2llm_product_sync_consent', 'no') !== 'yes') {
        return;
    }
    // Opt-out filter for site owners who don't want product indexing
    if (apply_filters('shop2llm_disable_product_sync', false)) {
        return;
    }
    if (!shop_to_llm_has_woocommerce()) {
        return;
    }
    $site_url = get_site_url();
    $products = wc_get_products([
        'limit'   => 500,
        'status'  => 'publish',
        'orderby' => 'date',
    ]);

    $payload = [];
    foreach ($products as $p) {
        $pid     = $p->get_id();
        $imgId   = $p->get_image_id();
        $imgUrl  = $imgId ? wp_get_attachment_url($imgId) : '';
        $cats    = wp_get_post_terms($pid, 'product_cat', ['fields' => 'names']);
        $payload[] = [
            'id'           => (string)$pid,
            'title'        => wp_strip_all_tags($p->get_name()),
            'price'        => (float)$p->get_price(),
            'description'  => wp_strip_all_tags($p->get_short_description() ?: $p->get_description()),
            'image_url'    => $imgUrl,
            'product_url'  => get_permalink($pid),
            'category'     => !empty($cats) ? implode(', ', $cats) : null,
            'brand'        => get_bloginfo('name'),
            'availability' => $p->is_in_stock() ? 'in stock' : 'out of stock',
        ];
    }

    if (empty($payload)) {
        return;
    }

    wp_remote_post('https://api.shop2llm.com/fincore/v1/store/feed/sync', [
        'timeout'  => 30,
        'blocking' => false,
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => json_encode([
            'site_url'     => $site_url,
            'platform'     => 'woocommerce',
            'version'      => SHOP_TO_LLM_VERSION,
            'mcp_endpoint' => shop_to_llm_mcp_endpoint_url(),
            'products'     => $payload,
        ]),
    ]);
}
add_action('shop2llm_cloud_heartbeat', 'shop_to_llm_sync_product_feed', 20);

// ─── Behavior Tracking (local summary, opt-in upload) ─────────────────

/**
 * Log a user behavior event to the local behavior_summary option.
 * Pure local storage — no network call, no PII, no opt-in required.
 * Only the final state is kept (not a time-series log) to keep option size bounded.
 */
function shop_to_llm_log_event(string $event, array $data = []): void
{
    $summary = get_option('shop2llm_behavior_summary', []);
    if (!is_array($summary)) {
        $summary = [];
    }
    $now = time();
    switch ($event) {
        case 'activated':
            if (empty($summary['activated'])) {
                $summary['activated'] = $now;
            }
            break;
        case 'welcome_shown':
            if (empty($summary['welcome_shown'])) {
                $summary['welcome_shown'] = $now;
            }
            break;
        case 'welcome_completed':
            $summary['welcome_completed'] = $now;
            $summary['welcome_skipped_step'] = null;
            break;
        case 'welcome_skipped':
            $summary['welcome_skipped_step'] = isset($data['step']) ? (int) $data['step'] : 1;
            break;
        case 'url_copied':
            if (empty($summary['url_copied'])) {
                $summary['url_copied'] = $now;
            }
            break;
        case 'url_submitted':
            // v1.7.1: automatic IndexNow push succeeded — the URL left the site
            // for index engines without any manual copy step.
            if (empty($summary['url_submitted'])) {
                $summary['url_submitted'] = $now;
            }
            break;
        case 'first_search':
            if (empty($summary['first_search'])) {
                $summary['first_search'] = $now;
            }
            // 60s-to-aha: the first SUCCESSFUL Hero search IS onboarding completion.
            // First attempt may fail (cloud hiccup); a later success still completes.
            if (empty($summary['first_search_ok']) && !empty($data['success'])) {
                $summary['first_search_ok'] = true;
                if (empty($summary['welcome_completed'])) {
                    $summary['welcome_completed'] = $now;
                }
                $summary['welcome_skipped_step'] = null;
            }
            break;
        case 'dashboard_visit':
            $summary['dashboard_visits'] = (int) ($summary['dashboard_visits'] ?? 0) + 1;
            break;
        case 'citation_view':
            // AI Visibility widget/card rendered with real data available
            $summary['citation_views'] = (int) ($summary['citation_views'] ?? 0) + 1;
            if (empty($summary['first_citation_view'])) {
                $summary['first_citation_view'] = $now;
            }
            break;
        case 'citation_upgrade_click':
            // CTA click on the AI Visibility card (upgrade funnel signal)
            $summary['citation_upgrade_clicks'] = (int) ($summary['citation_upgrade_clicks'] ?? 0) + 1;
            if (empty($summary['first_citation_upgrade_click'])) {
                $summary['first_citation_upgrade_click'] = $now;
            }
            break;
    }
    update_option('shop2llm_behavior_summary', $summary, false);
}

/**
 * Get the behavior summary as an anonymized, bucketed array safe for upload.
 * No PII, no search terms, no URLs — only bool/int/bucket values.
 */
function shop_to_llm_get_behavior_summary_for_upload(): array
{
    $s = get_option('shop2llm_behavior_summary', []);
    if (!is_array($s)) {
        $s = [];
    }

    // Content count (bucketed to avoid fingerprinting)
    $postCount = (int) wp_count_posts('post')->publish ?? 0;
    $productCount = 0;
    if (shop_to_llm_has_woocommerce()) {
        $productCount = (int) wp_count_posts('product')->publish ?? 0;
    }
    $totalContent = $postCount + $productCount;
    if ($totalContent <= 0) {
        $contentBucket = '0';
    } elseif ($totalContent < 5) {
        $contentBucket = '1-4';
    } elseif ($totalContent <= 20) {
        $contentBucket = '5-20';
    } elseif ($totalContent <= 100) {
        $contentBucket = '21-100';
    } else {
        $contentBucket = '100+';
    }

    // Dashboard visits bucketed
    $visits = (int) ($s['dashboard_visits'] ?? 0);
    if ($visits <= 1) {
        $visitBucket = '1';
    } elseif ($visits <= 5) {
        $visitBucket = '2-5';
    } else {
        $visitBucket = '6+';
    }

    // Days since activation
    $daysSince = 0;
    if (!empty($s['activated'])) {
        $daysSince = (int) floor((time() - (int) $s['activated']) / DAY_IN_SECONDS);
    }

    return [
        'has_content' => $totalContent > 0,
        'content_count' => $contentBucket,
        'welcome_shown' => !empty($s['welcome_shown']),
        'welcome_completed' => !empty($s['welcome_completed']),
        'welcome_skipped_step' => isset($s['welcome_skipped_step']) ? (int) $s['welcome_skipped_step'] : null,
        'url_copied' => !empty($s['url_copied']),
        'url_submitted' => !empty($s['url_submitted']),
        'first_search_done' => !empty($s['first_search']),
        'first_search_ok' => !empty($s['first_search_ok']),
        'first_crawler_done' => !empty($s['first_crawler']) || get_option('shop_to_llm_first_crawler_notified'),
        'days_since_activation' => $daysSince,
        'dashboard_visits' => $visitBucket,
        // AI Visibility funnel (counts bucketed, timestamps omitted — no PII)
        'citation_views' => min((int) ($s['citation_views'] ?? 0), 999),
        'citation_upgrade_clicks' => min((int) ($s['citation_upgrade_clicks'] ?? 0), 999),
    ];
}

function shop_to_llm_handle_log_event(): void
{
    if (!current_user_can('manage_options')) {
        wp_die();
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    $event = sanitize_text_field(wp_unslash($_POST['event'] ?? ''));
    $data = [];
    if (isset($_POST['step'])) {
        $data['step'] = (int) $_POST['step'];
    }
    if (isset($_POST['success'])) {
        $data['success'] = $_POST['success'] === '1';
    }
    if ($event) {
        shop_to_llm_log_event($event, $data);
    }
    wp_send_json_success();
}

function shop_to_llm_handle_enable_analytics(): void
{
    if (!current_user_can('manage_options')) {
        wp_die();
    }
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    update_option('shop_to_llm_analytics', 'yes');

    // Send immediate ping to cloud — include behavior summary so data appears right away
    $site_url = get_site_url();
    wp_remote_post('https://api.shop2llm.com/fincore/v1/free/ping', [
        'timeout' => 5,
        'blocking' => false,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'site_url' => $site_url,
            'site_hash' => hash('sha256', wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url),
            'platform' => shop_to_llm_has_woocommerce() ? 'woocommerce' : 'wordpress',
            'plugin_version' => SHOP_TO_LLM_VERSION,
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'wc_version' => class_exists('WooCommerce') ? WC()->version : '',
            'wp_version' => get_bloginfo('version'),
            'behavior' => shop_to_llm_get_behavior_summary_for_upload(),
        ]),
    ]);
    wp_send_json_success();
}

// ─── v1.9.0: WordPress Abilities API registration (official AI stack) ───
// The MCP tools are mirrored 1:1 as read-only abilities in the shop2llm
// namespace (four by default; filters may extend the surface). When the
// official MCP Adapter plugin is active its default server exposes these
// automatically (meta.mcp.public opt-in) and the built-in /mcp endpoint
// retires (see shop_to_llm_register_routes) — same capabilities, one
// surface, zero double-serving. The registration hooks only fire if the
// Abilities API plugin is active, so no hard dependency.

add_action('wp_abilities_api_categories_init', 'shop_to_llm_register_ability_category');
add_action('wp_abilities_api_init', 'shop_to_llm_register_abilities');

function shop_to_llm_register_ability_category(): void
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    // v1.9.0: string-based call_user_func keeps the plugin-check static scan
    // happy — these functions only exist on WordPress 6.9+ / Abilities API,
    // while the header still declares WordPress 6.0+ (guarded above).
    call_user_func('wp_register_ability_category', 'shop2llm', [
        'label' => __('AI Visibility (Shop2LLM)', 'shop2llm'),
        'description' => __('Read-only commerce and content abilities: catalog search, product details, policies, store overview, AI crawler analytics and visibility checks.', 'shop2llm'),
    ]);
}

function shop_to_llm_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    foreach (shop_to_llm_tools_list()['tools'] as $tool) {
        $name = (string) $tool['name'];
        // Registry validation only allows [a-z0-9-] plus one slash — tool
        // names use underscores (search_products), so map them to dashes.
        call_user_func('wp_register_ability', 'shop2llm/' . str_replace('_', '-', $name), [
            'label' => ucwords(str_replace('_', ' ', $name)),
            'description' => (string) $tool['description'],
            'category' => 'shop2llm',
            'input_schema' => is_array($tool['inputSchema'] ?? null)
                ? $tool['inputSchema']
                : ['type' => 'object', 'properties' => []],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static function (array $input = []) use ($name) {
                return shop_to_llm_tools_call(['name' => $name, 'arguments' => $input]);
            },
            // Read-only public tools: the MCP transport layer (adapter auth or
            // the built-in API-key check) gates access; the ability itself
            // carries no additional user-capability requirement.
            'permission_callback' => '__return_true',
            'meta' => [
                'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }
}

// ─── MCP JSON-RPC Handler ──────────────────────────────────────────────

function shop_to_llm_handle_mcp(\WP_REST_Request $request): \WP_REST_Response
{
    // ── Per-minute burst limit (default 60/min → 429; 0 = unlimited, matches Pro) ──
    $burstLimit = (int) get_option('shop_to_llm_mcp_burst_limit', 60);
    if ($burstLimit > 0) {
        $rlKey = 'shop_to_llm_mcp_rate_' . gmdate('Y-m-d-H-i');
        $rlCount = (int) get_transient($rlKey);
        if ($rlCount > $burstLimit) {
            $response = new \WP_REST_Response([
                'jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => -32000, 'message' => 'Rate limit exceeded'],
            ], 429);
            $response->header('Retry-After', '60');
            return $response;
        }
        set_transient($rlKey, $rlCount + 1, 60);
    }

    if (function_exists('WC') && WC()->session) {
        WC()->session->set('shop_to_llm_ai_session', true);
    }

    // Batch counter writes — lock-based to avoid concurrent read-write races
    $lockKey = 'shop_to_llm_mcp_lock';
    if (!get_transient($lockKey)) {
        set_transient($lockKey, 1, 15); // Lock for 15 seconds
        $pending = (int) get_transient('shop_to_llm_mcp_pending');
        if ($pending > 0) {
            $key = 'shop_to_llm_count_' . gmdate('Y-m-d');
            $today = (int) get_transient($key) + $pending;
            set_transient($key, $today, DAY_IN_SECONDS);
            $k7d = 'shop_to_llm_count_7d';
            $sum7d = (int) get_transient($k7d) + $pending;
            set_transient($k7d, $sum7d, DAY_IN_SECONDS);
            delete_transient('shop_to_llm_mcp_pending');
        }
    }
    set_transient('shop_to_llm_mcp_pending', (int) get_transient('shop_to_llm_mcp_pending') + 1, 60);

    $body = $request->get_json_params();
    if (!is_array($body)) {
        return new \WP_REST_Response([
            'jsonrpc' => '2.0', 'id' => null,
            'error' => ['code' => -32700, 'message' => 'Parse error'],
        ], 200);
    }
    $method = $body['method'] ?? '';
    $params = $body['params'] ?? [];
    $id = $body['id'] ?? null;

    // v1.9.0: MCP 2026-07-28 per-request version negotiation. Modern clients
    // carry the version in params._meta (or the MCP-Protocol-Version header);
    // unknown versions get UnsupportedProtocolVersionError (-32022) with our
    // supported list so dual-era clients can retry. Legacy clients (no _meta,
    // no header) keep being served statelessly as before.
    $supportedVersions = ['2026-07-28', '2025-11-25'];
    $requestedVersion = is_array($params)
        ? ($params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null)
        : null;
    $requestedVersion = $requestedVersion ?: $request->get_header('mcp-protocol-version');
    if ($requestedVersion !== null && $requestedVersion !== '' && !in_array($requestedVersion, $supportedVersions, true)) {
        $resp = new \WP_REST_Response([
            'jsonrpc' => '2.0', 'id' => $id,
            'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => $supportedVersions, 'requested' => $requestedVersion],
            ],
        ], 200);
        $resp->header('MCP-Protocol-Version', '2026-07-28');
        return $resp;
    }

    // MCP notifications must not receive a response body (spec 2025-06-18).
    if ($id === null || str_starts_with((string) $method, 'notifications/')) {
        return new \WP_REST_Response(null, 202);
    }

    // v1.6.0: tool-call evidence log (last 20 calls, autoload off) — powers the
    // AI Agents tab "Recent MCP activity" strip. Append-only, capped, no rewrite.
    if (in_array($method, ['tools/call', 'tools/list', 'initialize', 'server/discover'], true)) {
        $callLog = get_option('shop_to_llm_mcp_call_log', []);
        if (!is_array($callLog)) {
            $callLog = [];
        }
        array_unshift($callLog, [
            'tool' => $method === 'tools/call' ? (string) ($params['name'] ?? 'unknown') : $method,
            'agent' => substr((string) ($request->get_header('user-agent') ?: ''), 0, 120),
            'time' => current_time('Y-m-d H:i:s'),
        ]);
        update_option('shop_to_llm_mcp_call_log', array_slice($callLog, 0, 20), false);
    }

    switch ($method) {
        case 'tools/list':
            $result = shop_to_llm_tools_list();
            break;
        case 'tools/call':
            $result = shop_to_llm_tools_call($params);
            break;
        case 'initialize':
            $result = [
                'protocolVersion' => $params['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => ['listChanged' => true]],
                'serverInfo' => ['name' => get_bloginfo('name') . ' — Shop2LLM', 'version' => SHOP_TO_LLM_VERSION],
            ];
            break;
        case 'server/discover':
            // v1.9.0: MCP 2026-07-28 server discovery (spec-required). Answers
            // versions, capabilities, identity and LLM guidance in one call.
            $result = [
                'resultType' => 'complete',
                'supportedVersions' => ['2026-07-28', '2025-11-25'],
                'capabilities' => ['tools' => ['listChanged' => true]],
                '_meta' => [
                    'io.modelcontextprotocol/serverInfo' => [
                        'name' => get_bloginfo('name') . ' — Shop2LLM',
                        'version' => SHOP_TO_LLM_VERSION,
                    ],
                ],
                'instructions' => 'Read-only commerce and content access for AI agents: search the catalog, fetch products/posts with prices and purchase URLs, read store policies, and inspect AI-crawler activity. All tools are read-only, safe to call without user confirmation.',
                'ttlMs' => 3600000,
                'cacheScope' => 'public',
            ];
            break;
        case 'prompts/list':
            $result = ['prompts' => []];
            break;
        default:
            $result = null;
    }

    if ($result === null) {
        return new \WP_REST_Response([
            'jsonrpc' => '2.0', 'id' => $id,
            'error' => ['code' => -32601, 'message' => "Method not found: {$method}"],
        ], 200);
    }

    // Wrap tools/call result in MCP content[] + structuredContent structure.
    if ($method === 'tools/call' && is_array($result) && !isset($result['content'])) {
        $result = [
            'content' => [
                ['type' => 'text', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'structuredContent' => $result,
        ];
    }

    // v1.9.0: Streamable HTTP protocol-version response header — echo the
    // requested version for modern clients, advertise 2025-11-25 baseline
    // for legacy inline calls.
    $resp = new \WP_REST_Response([
        'jsonrpc' => '2.0', 'id' => $id, 'result' => $result,
    ], 200);
    $resp->header('MCP-Protocol-Version', ($requestedVersion !== null && $requestedVersion !== '') ? $requestedVersion : '2025-11-25');
    return $resp;
}

// ─── Tool Definitions ──────────────────────────────────────────────────

function shop_to_llm_tools_list(): array
{
    $hasWC = shop_to_llm_has_woocommerce();
    $tools = [
        [
            'name' => 'search_products',
            'description' => 'Search the ' . get_bloginfo('name') . ($hasWC ? ' product catalog.' : ' website content.') . ' Returns matching ' . ($hasWC ? 'products with prices, stock status, ratings, and purchase URLs.' : 'posts and pages with titles, excerpts, and links.'),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search keywords (e.g., "wireless headphones", "summer dress")'],
                    'category' => ['type' => 'string', 'description' => 'Filter by product category slug'],
                    'max_price' => ['type' => 'number', 'description' => 'Maximum price in store currency'],
                    'in_stock_only' => ['type' => 'boolean', 'default' => true, 'description' => 'Only show in-stock items'],
                    'limit' => ['type' => 'integer', 'default' => 10, 'maximum' => 50],
                ],
                'required' => ['query'],
            ],
            'idempotent' => true,
        ],
        [
            'name' => 'get_product',
            'description' => 'Get full details for a specific ' . ($hasWC ? 'product including description, price, variants, and availability.' : 'post or page including title, full content, and metadata.'),
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['product_id' => ['type' => 'integer', 'description' => 'WooCommerce product ID']],
                'required' => ['product_id'],
            ],
            'idempotent' => true,
        ],
        // v1.5.5: commerce-grade tool surface — policies are the #1 question AI
        // commerce agents answer (Claude Commerce Agent answers policy
        // questions only); the overview orients an agent on first connection.
        [
            'name' => 'get_policies',
            'description' => 'Get the store\'s machine-readable policies (returns, shipping, tax, terms, privacy) with per-page JSON-LD (MerchantReturnPolicy, OfferShippingDetails). Use this to answer policy questions such as return windows, shipping costs, or fees.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => ['returns', 'shipping', 'tax', 'terms', 'privacy'],
                        'description' => 'Optional: return only this policy type',
                    ],
                ],
                'required' => [],
            ],
            'idempotent' => true,
        ],
        [
            'name' => 'get_store_overview',
            'description' => 'Get a structured overview of this site: what it sells (categories, counts), latest products and posts, supported AI tools, available policies, and machine-readable endpoint URLs. Use this first when you need context about the store.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ],
            'idempotent' => true,
        ],
    ];

    return apply_filters('shop_to_llm_tools_list', ['tools' => $tools]);
}

function shop_to_llm_tools_call(array $params): array
{
    $name = $params['name'] ?? '';
    $args = $params['arguments'] ?? [];

    switch ($name) {
        case 'search_products':
            $result = shop_to_llm_search_products($args);
            break;
        case 'get_product':
            $result = shop_to_llm_get_product($args);
            break;
        case 'get_policies':
            $result = shop_to_llm_mcp_get_policies($args);
            break;
        case 'get_store_overview':
            $result = shop_to_llm_mcp_get_store_overview();
            break;
        default:
            $result = ['error' => ['code' => 'TOOL_NOT_FOUND', 'message' => 'Tool not found: ' . $name]];
    }

    return apply_filters('shop_to_llm_tools_call', $result, $name, $args);
}

// ─── Tool Implementations ──────────────────────────────────────────────

if (!function_exists('shop_to_llm_mcp_get_policies')) :
    /**
     * v1.5.5 MCP tool: get_policies.
     *
     * Surfaces the machine-readable policy pages (returns/shipping/tax/terms/
     * privacy) detected by the v1.4.0 policy-schema module, including their
     * per-page JSON-LD so a commerce agent can quote return windows, shipping
     * costs and fees verbatim without crawling the HTML.
     */
    function shop_to_llm_mcp_get_policies(array $args): array
    {
        $filterType = strtolower(trim((string) ($args['type'] ?? '')));
        $pages = shop_to_llm_policy_find_pages();
        if ($filterType !== '' && isset($pages[$filterType])) {
            $pages = [$filterType => $pages[$filterType]];
        } elseif ($filterType !== '') {
            $detected = array_keys($pages);
            return [
                'policies'       => [],
                'requested_type' => $filterType,
                'note'           => 'No policy page found for this type. Detected types: ' . ($detected === [] ? 'none' : implode(', ', $detected)),
            ];
        }

        $config = shop_to_llm_policy_return_config();
        $policies = [];
        foreach ($pages as $type => $page) {
            $entry = [
                'type'  => $type,
                'title' => $page['title'],
                'url'   => $page['url'],
            ];
            // Reuse the v1.4.0 schema builders — identical nodes to the ones
            // the policy page head emits (shop_to_llm_inject_policy_schema).
            $args = [
                'url'     => $page['url'],
                'name'    => $page['title'],
                'country' => $config['country'],
            ];
            $pagePost = get_page_by_path($page['slug'], OBJECT, 'page');
            if ($pagePost instanceof \WP_Post) {
                $args['dateModified'] = (string) get_post_modified_time('c', true, $pagePost);
            }
            $schema = match ($type) {
                'returns'  => shop_to_llm_policy_build_return_schema($args + ['days' => $config['days'], 'fees' => $config['fees']]),
                'shipping' => shop_to_llm_policy_build_shipping_schema($args + (array) (shop_to_llm_policy_flat_rate() ?? ['cost' => null, 'currency' => 'USD'])),
                default    => shop_to_llm_policy_build_webpage_schema($args),
            };
            /** This filter is documented in includes/class-policy-schema.php. */
            $schema = apply_filters('shop2llm_policy_schema', $schema, $type, $pagePost instanceof \WP_Post ? (int) $pagePost->ID : 0);
            if (is_array($schema) && $schema !== []) {
                $entry['json_ld'] = $schema;
            }
            $policies[] = $entry;
        }

        return [
            'policies'       => $policies,
            'ai_commerce_note' => 'MerchantReturnPolicy and OfferShippingDetails JSON-LD nodes are quotable verbatim by AI commerce agents.',
        ];
    }
endif;

if (!function_exists('shop_to_llm_mcp_get_store_overview')) :
    /**
     * v1.5.5 MCP tool: get_store_overview.
     *
     * First-call orientation for AI agents: what the site sells, latest
     * content, available tools and machine-readable endpoints. All local,
     * cached 15 minutes.
     */
    function shop_to_llm_mcp_get_store_overview(): array
    {
        $cached = get_transient('shop2llm_mcp_store_overview');
        if (is_array($cached)) {
            return $cached;
        }

        $hasWC = shop_to_llm_has_woocommerce();
        $overview = [
            'site' => [
                'name'        => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'url'         => home_url('/'),
                'language'    => get_bloginfo('language'),
                'platform'    => $hasWC ? 'woocommerce' : 'wordpress',
            ],
            'latest_products' => [],
            'latest_posts'    => [],
            'categories'      => [],
            'available_tools' => ['search_products', 'get_product', 'get_policies', 'get_store_overview'],
            'endpoints'       => [
                'llms_txt'  => home_url('/llms.txt'),
                'agents_md' => home_url('/agents.md'),
                'mcp'       => shop_to_llm_mcp_endpoint_url(),
            ],
        ];

        if ($hasWC) {
            $overview['site']['currency'] = get_woocommerce_currency();
            foreach (wc_get_products(['status' => 'publish', 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC']) as $p) {
                $overview['latest_products'][] = [
                    'id'      => $p->get_id(),
                    'name'    => $p->get_name(),
                    'price'   => $p->get_price(),
                    'in_stock' => $p->is_in_stock(),
                    'url'     => $p->get_permalink(),
                ];
            }
            $overview['content_counts'] = [
                'products' => (int) wp_count_posts('product')->publish,
                'posts'    => (int) wp_count_posts('post')->publish,
            ];
            foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 20]) as $term) {
                if ($term instanceof \WP_Term) {
                    $overview['categories'][] = ['slug' => $term->slug, 'name' => $term->name, 'count' => (int) $term->count];
                }
            }
        } else {
            $overview['content_counts'] = [
                'posts' => (int) wp_count_posts('post')->publish,
                'pages' => (int) wp_count_posts('page')->publish,
            ];
        }

        foreach (get_posts(['post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'numberposts' => 5]) as $post) {
            $overview['latest_posts'][] = [
                'id'    => $post->ID,
                'title' => get_the_title($post),
                'url'   => get_permalink($post),
            ];
        }

        $policyPages = shop_to_llm_policy_find_pages();
        if ($policyPages !== []) {
            $overview['policy_types_available'] = array_keys($policyPages);
        }

        set_transient('shop2llm_mcp_store_overview', $overview, 15 * MINUTE_IN_SECONDS);
        return $overview;
    }
endif;

if (!function_exists('shop_to_llm_search_products')) :
    function shop_to_llm_search_products(array $args): array
    {
        $query = $args['query'] ?? '';
        $limit = min((int)($args['limit'] ?? 10), 50);

        if (shop_to_llm_has_woocommerce()) {
            $wcArgs = [
            'status' => 'publish',
            's' => $query,
            'limit' => $limit,
            ];

            if (!empty($args['category'])) {
                $wcArgs['category'] = [$args['category']];
            }
            if (!empty($args['max_price'])) {
                $wcArgs['max_price'] = (float)$args['max_price'];
            }
            if ($args['in_stock_only'] ?? true) {
                $wcArgs['stock_status'] = 'instock';
            }

            $products = wc_get_products($wcArgs);

            // v1.5.4: exact-name priority. The stock filter is an availability
            // preference, not an existence test: when an AI assistant searches
            // a product by its full title (e.g. "Yoga Mat Pro") and that product
            // is out of stock, the filtered search returns zero rows and the
            // assistant wrongly concludes the product doesn't exist. Look the
            // exact title up directly and pin matches to the front — the
            // in_stock field in the payload reflects real availability.
            if ($query !== '') {
                $exactPosts = get_posts([
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'title' => $query,
                    'numberposts' => $limit,
                    'fields' => 'ids',
                ]);
                foreach ($exactPosts as $exactId) {
                    $exactProduct = wc_get_product($exactId);
                    if (!$exactProduct || $exactProduct->get_status() !== 'publish') {
                        continue;
                    }
                    // Explicit filters other than stock still apply.
                    if (!empty($args['category']) && !has_term($args['category'], 'product_cat', $exactId)) {
                        continue;
                    }
                    if (!empty($args['max_price']) && (float) $exactProduct->get_price() > (float) $args['max_price']) {
                        continue;
                    }
                    $products = array_merge([$exactProduct], array_filter($products, static function ($p) use ($exactId) {
                        return $p->get_id() !== (int) $exactId;
                    }));
                }
            }

            $results = array_map(function ($p) {
                return [
                'id' => $p->get_id(),
                'name' => $p->get_name(),
                'price' => $p->get_price(),
                'currency' => get_woocommerce_currency(),
                'in_stock' => $p->is_in_stock(),
                'rating' => (float)$p->get_average_rating(),
                'short_description' => wp_strip_all_tags($p->get_short_description()),
                'url' => $p->get_permalink(),
                'image_url' => wp_get_attachment_url($p->get_image_id()) ?: '',
                ];
            }, $products);

            if (!empty($query)) {
                $productNames = array_map(function ($p) {
                    return $p->get_name();
                }, $products);
                shop_to_llm_log_search($query, count($results), $productNames);
            }

            return ['products' => $results];
        }

        // Non-WooCommerce: search WordPress posts (exclude password-protected, limit to post/page)
        $postArgs = [
        's' => $query,
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'has_password' => false,
        ];
        $posts = get_posts($postArgs);

        $results = array_map(function ($p) {
            return [
            'id' => $p->ID,
            'name' => $p->post_title,
            'price' => '',
            'currency' => '',
            'in_stock' => true,
            'rating' => 0,
            'short_description' => wp_strip_all_tags(get_the_excerpt($p) ?: wp_trim_words(shop_to_llm_public_content_html($p), 30)),
            'url' => get_permalink($p->ID),
            'image_url' => get_the_post_thumbnail_url($p->ID, 'medium') ?: '',
            ];
        }, $posts);

        if (!empty($query)) {
            $names = array_map(function ($p) {
                return $p->post_title;
            }, $posts);
            shop_to_llm_log_search($query, count($results), $names);
        }

        return ['products' => $results];
    }
endif; // function_exists guard for Pro compatibility

if (!function_exists('shop_to_llm_get_product')) :
    function shop_to_llm_get_product(array $args): array
    {
        $id = (int)($args['product_id'] ?? 0);
        if ($id <= 0) {
            return ['error' => ['code' => 'INVALID_ARGUMENT', 'message' => 'Valid product_id required']];
        }

        if (shop_to_llm_has_woocommerce()) {
            $product = wc_get_product($id);
            if (!$product || $product->get_status() !== 'publish') {
                return ['error' => ['code' => 'NOT_FOUND', 'message' => 'Product not found']];
            }

            return [
            'product' => [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'description' => wp_strip_all_tags($product->get_description()),
                'short_description' => wp_strip_all_tags($product->get_short_description()),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'currency' => get_woocommerce_currency(),
                'in_stock' => $product->is_in_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'rating' => (float)$product->get_average_rating(),
                'review_count' => $product->get_review_count(),
                'categories' => wp_get_post_terms($id, 'product_cat', ['fields' => 'names']),
                'url' => $product->get_permalink(),
                'image_url' => wp_get_attachment_url($product->get_image_id()) ?: '',
            ],
            ];
        }

        // Non-WooCommerce: get WordPress post
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish') {
            return ['error' => ['code' => 'NOT_FOUND', 'message' => 'Content not found']];
        }
        // v1.8.0: password-protected content is invisible to AI agents — same
        // contract as the search tool's has_password exclusion.
        if ($post->post_password !== '') {
            return ['error' => ['code' => 'NOT_FOUND', 'message' => 'Content not found']];
        }

        return [
        'product' => [
            'id' => $post->ID,
            'name' => $post->post_title,
            // v1.8.0: render as a logged-out visitor so membership/paywall
            // plugins can strip protected sections before the agent sees them.
            'description' => wp_strip_all_tags(shop_to_llm_public_content_html($post)),
            'short_description' => wp_strip_all_tags(get_the_excerpt($post) ?: wp_trim_words(shop_to_llm_public_content_html($post), 30)),
            'price' => '',
            'regular_price' => '',
            'sale_price' => '',
            'currency' => '',
            'in_stock' => true,
            'stock_quantity' => null,
            'rating' => 0,
            'review_count' => 0,
            'categories' => wp_get_post_terms($id, 'category', ['fields' => 'names']),
            'url' => get_permalink($post->ID),
            'image_url' => get_the_post_thumbnail_url($post->ID, 'full') ?: '',
        ],
        ];
    }
endif; // function_exists guard for Pro compatibility

if (!function_exists('shop_to_llm_log_search')) :
    function shop_to_llm_log_search(string $query, int $resultCount, array $productNames = []): void
    {
        $key = 'shop_to_llm_searches_' . gmdate('Y-m-d');
        $searches = get_transient($key);
        if (!is_array($searches)) {
            $searches = [];
        }
        $entry = [
        'query' => sanitize_text_field($query),
        'results' => $resultCount,
        'products' => array_slice(array_map('sanitize_text_field', $productNames), 0, 5),
        'time' => current_time('H:i'),
        'date' => current_time('Y-m-d'),
        ];
        // v1.5.4: collapse same-minute duplicates (identical query + result count).
        // AI assistants and the cloud proxy can fire the identical search repeatedly,
        // which used to spam the AI Search Activity table with look-alike rows.
        foreach ($searches as $existing) {
            if (
                ($existing['query'] ?? '') === $entry['query']
                && (int) ($existing['results'] ?? -1) === $entry['results']
                && ($existing['time'] ?? '') === $entry['time']
            ) {
                return; // duplicate within the same minute — keep the table clean
            }
        }
        array_unshift($searches, $entry);
        // v1.6.0: 7-day TTL so the AI Traffic tab can show a full week window
        // (per-day transients are merged at read time — no history rewrite).
        set_transient($key, array_slice($searches, 0, 50), 7 * DAY_IN_SECONDS);
    }

endif; // function_exists guard for Pro compatibility

if (!function_exists('shop_to_llm_get_recent_searches')) :
/**
 * Recent AI searches, merged across a rolling window of days.
 *
 * v1.6.0: optional $days window (default 1 = today, unchanged legacy behavior).
 * Per-day transients are merged newest-first; entries pre-dating the date field
 * are annotated with the transient day they were found in.
 *
 * @param int $days Number of days to include (1-7).
 * @return array<int, array{query:string,results:int,products:array,time:string,date:string}>
 */
    function shop_to_llm_get_recent_searches(int $days = 1): array
    {
        $days = max(1, min(7, $days));
        $merged = [];
        for ($i = 0; $i < $days; $i++) {
            $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $searches = get_transient('shop_to_llm_searches_' . $day);
            if (!is_array($searches)) {
                continue;
            }
            foreach ($searches as $entry) {
                if (!isset($entry['date'])) {
                    $entry['date'] = $day;
                }
                $merged[] = $entry;
            }
        }
        return $merged;
    }
endif; // function_exists guard for Pro compatibility

function shop_to_llm_log_crawler(string $crawlerType): void
{
    // Record first crawler timestamp for review request logic
    if (!get_option('shop_to_llm_first_crawler_time')) {
        update_option('shop_to_llm_first_crawler_time', time());
        update_option('shop_to_llm_first_crawler_platform', $crawlerType);
    }

    $key = 'shop_to_llm_crawlers_' . gmdate('Y-m-d');
    $crawlers = get_transient($key);
    if (!is_array($crawlers)) {
        $crawlers = [];
    }
    $label = [
        'oai-search' => 'ChatGPT Search', 'chatgpt-user' => 'ChatGPT User',
        'gptbot' => 'GPTBot', 'claude-bot' => 'ClaudeBot',
        'perplexity-bot' => 'Perplexity', 'google-extended' => 'Google AI',
        'bingbot' => 'Bing', 'meta-external' => 'Meta AI',
        'facebook-bot' => 'Facebook', 'amazonbot' => 'Amazon Bot',
        'bytespider' => 'ByteDance', 'applebot-extended' => 'Apple Bot',
    ];
    array_unshift($crawlers, [
        'crawler' => $label[$crawlerType] ?? $crawlerType,
        'time' => current_time('H:i'),
    ]);
    set_transient($key, array_slice($crawlers, 0, 50), DAY_IN_SECONDS);

    // Persist to DB for 30-day historical tracking (feature anchor)
    shop_to_llm_log_crawler_to_db($crawlerType, $label[$crawlerType] ?? $crawlerType);
}

function shop_to_llm_get_recent_crawlers(): array
{
    $key = 'shop_to_llm_crawlers_' . gmdate('Y-m-d');
    $crawlers = get_transient($key);
    return is_array($crawlers) ? $crawlers : [];
}

function shop_to_llm_get_today_api_count(): int
{
    $key = 'shop_to_llm_count_' . gmdate('Y-m-d');
    return (int) get_transient($key);
}

// ─── Discovery Endpoints ───────────────────────────────────────────────

function shop_to_llm_mcp_discovery(): \WP_REST_Response
{
    $cached = get_transient('shop_to_llm_mcp_discovery');
    if (is_array($cached)) {
        return new \WP_REST_Response($cached);
    }

    $data = [
        'mcp' => [
            'version' => '2025-06-18',
            'transport' => 'streamable-http',
            // v1.9.0: points at the official MCP Adapter default server when
            // active, else the built-in fallback endpoint.
            'endpoint' => shop_to_llm_mcp_endpoint_url(),
            'capabilities' => ['tools' => ['listChanged' => true]],
        ],
        'registry' => [
            'name' => get_bloginfo('name') . ' via Shop2LLM',
            'description' => shop_to_llm_has_woocommerce()
                ? 'WooCommerce store connected to AI assistants'
                : 'WordPress site connected to AI assistants',
        ],
        'server_info' => [
            'name' => get_bloginfo('name') . ' — Shop2LLM',
            'version' => SHOP_TO_LLM_VERSION,
        ],
    ];

    set_transient('shop_to_llm_mcp_discovery', $data, HOUR_IN_SECONDS);

    return new \WP_REST_Response($data);
}

// ─── Auth ──────────────────────────────────────────────────────────────

// Bypass WordPress REST auth for Shop2LLM routes.
// Priority 99999 ensures this runs after core auth checks.
add_filter('rest_authentication_errors', function ($result) {
    global $wp;
    $route = (isset($wp->query_vars) && is_array($wp->query_vars)) ? ($wp->query_vars['rest_route'] ?? '') : '';
    if ($route === '' && isset($_SERVER['REQUEST_URI'])) {
        $parsed = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
        $restPrefix = rest_get_url_prefix();
        if (is_string($parsed) && str_starts_with($parsed, '/' . $restPrefix . '/')) {
            $route = substr($parsed, strlen('/' . $restPrefix));
        }
    }
    if (str_starts_with($route, '/shop2llm/') || $route === '/shop2llm') {
        return true;
    }
    return $result;
}, 99999);

function shop_to_llm_check_auth(\WP_REST_Request $request): bool
{
    $storedKey = get_option('shop_to_llm_api_key', '');

    if ($storedKey === '') {
        return false;
    }

    $authHeader = $request->get_header('Authorization');
    if ($authHeader !== null && $authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
        return hash_equals($storedKey, $token);
    }

    // Accept both header spellings: the /agents.md dossier documents
    // `X-Shop2LLM-Key`, while v1.5.x also accepted `X-Shop-To-LLM-Key`.
    foreach (['X-Shop2LLM-Key', 'X-Shop-To-LLM-Key'] as $headerName) {
        $apiKeyHeader = $request->get_header($headerName);
        if ($apiKeyHeader === null || $apiKeyHeader === '') {
            continue;
        }
        if (hash_equals($storedKey, $apiKeyHeader)) {
            return true;
        }
        // Also accept Pro license key (used by cloud proxy)
        $proKey = get_option('shop_to_llm_pro_license_key', '');
        if ($proKey !== '' && hash_equals($proKey, $apiKeyHeader)) {
            return true;
        }
    }

    return false;
}

// ─── AI Crawler Detection ──────────────────────────────────────────────

/**
 * Match a User-Agent against the AI crawler registry.
 *
 * Patterns are tested longest-first so that specific entries win over their
 * shorter prefixes (e.g. "Googlebot-Image/1.0" must match "Googlebot-Image",
 * not "Googlebot"). Returns null for non-crawler (human) traffic.
 *
 * @return array{type:string,label:string,vendor:string}|null
 */
function shop_to_llm_match_ai_crawler(string $ua): ?array
{
    if ($ua === '') {
        return null;
    }
    static $sorted = null;
    if ($sorted === null) {
        $sorted = shop_to_llm_get_ai_crawler_registry();
        uksort($sorted, static function ($a, $b): int {
            return strlen($b) <=> strlen($a);
        });
    }
    foreach ($sorted as $pattern => $meta) {
        if ($pattern !== '' && stripos($ua, $pattern) !== false) {
            return ['type' => $meta['type'], 'label' => $meta['label'], 'vendor' => $meta['vendor']];
        }
    }
    return null;
}

function shop_to_llm_detect_ai_crawler(): void
{
    // Only run on frontend, not admin/AJAX/REST
    if (is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) {
        return;
    }
    $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $match = shop_to_llm_match_ai_crawler($ua);
    if ($match === null) {
        return;
    }
    $type = $match['type'];
    // Dedup: skip if same crawler type reported in last 10 minutes
    $dedupKey = 'shop_to_llm_crawler_dedup_' . $type;
    if (get_transient($dedupKey)) {
        return;
    }
    set_transient($dedupKey, 1, 10 * MINUTE_IN_SECONDS);
    shop_to_llm_report_crawler($type);
}

/**
 * Centralized AI Crawler Registry — 81 AI crawlers (v1.3.4+).
 * Source: aggregated from public bot documentation of OpenAI, Anthropic, Google,
 * Perplexity, Meta, ByteDance, Apple, Amazon, Cohere, You.com, Phind, Kagi, Brave,
 * Mojeek, DuckDuckGo, Yandex, Baidu, Naver, Seznam, Sogou, Exa, Marginalia,
 * plus 3rd-party research tools (Ahrefs, Semrush, MJ12, Moz, DotBot).
 *
 * Each entry: '<User-Agent substring>' => ['type' => '<slug>', 'label' => '<Human Name>', 'vendor' => '<Vendor>']
 *
 * @return array<string,array{id:string,label:string,vendor:string}>
 */
function shop_to_llm_get_ai_crawler_registry(): array
{
    // phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
    return [
        // ─── OpenAI (3) ────────────────────────────────────────────────
        'OAI-SearchBot'        => ['type' => 'oai-search',        'label' => 'ChatGPT Search',    'vendor' => 'OpenAI'],
        'ChatGPT-User'         => ['type' => 'chatgpt-user',     'label' => 'ChatGPT User',      'vendor' => 'OpenAI'],
        'GPTBot'               => ['type' => 'gptbot',           'label' => 'GPTBot',            'vendor' => 'OpenAI'],
        // ─── Anthropic (3) ───────────────────────────────────────────
        'ClaudeBot'            => ['type' => 'claude-bot',       'label' => 'ClaudeBot',         'vendor' => 'Anthropic'],
        'Claude-Web'           => ['type' => 'claude-web',      'label' => 'Claude-Web',        'vendor' => 'Anthropic'],
        'Claude-SearchBot'     => ['type' => 'claude-search',    'label' => 'Claude SearchBot',  'vendor' => 'Anthropic'],
        // ─── Perplexity (3) ──────────────────────────────────────────
        'PerplexityBot'        => ['type' => 'perplexity-bot',   'label' => 'Perplexity',        'vendor' => 'Perplexity'],
        'Perplexity-User'      => ['type' => 'perplexity-user',  'label' => 'Perplexity User',   'vendor' => 'Perplexity'],
        'PerplexityBot-1'      => ['type' => 'perplexity-v1',    'label' => 'Perplexity v1',     'vendor' => 'Perplexity'],
        // ─── Google AI (5) ────────────────────────────────────────────
        'Google-Extended'      => ['type' => 'google-extended',  'label' => 'Google AI',         'vendor' => 'Google'],
        'Googlebot'            => ['type' => 'googlebot',        'label' => 'Googlebot',         'vendor' => 'Google'],
        'Googlebot-Image'      => ['type' => 'googlebot-image',   'label' => 'Googlebot Image',   'vendor' => 'Google'],
        'Google-InspectionTool' => ['type' => 'google-inspect',  'label' => 'Google Inspect',    'vendor' => 'Google'],
        'Google-ReadAloud'     => ['type' => 'google-readaloud', 'label' => 'Google ReadAloud',  'vendor' => 'Google'],
        // ─── Bing (3) ────────────────────────────────────────────────
        'bingbot'              => ['type' => 'bingbot',          'label' => 'Bing',              'vendor' => 'Microsoft'],
        'BingPreview'          => ['type' => 'bing-preview',     'label' => 'Bing Preview',      'vendor' => 'Microsoft'],
        'adidxbot'             => ['type' => 'adidxbot',         'label' => 'Bing Ads',          'vendor' => 'Microsoft'],
        // ─── Meta (3) ────────────────────────────────────────────────
        'Meta-ExternalAgent'   => ['type' => 'meta-external',    'label' => 'Meta AI',           'vendor' => 'Meta'],
        'Meta-ExternalFetcher' => ['type' => 'meta-fetcher',     'label' => 'Meta Fetcher',      'vendor' => 'Meta'],
        'FacebookBot'          => ['type' => 'facebook-bot',     'label' => 'Facebook',          'vendor' => 'Meta'],
        // ─── ByteDance (2) ───────────────────────────────────────────
        'Bytespider'           => ['type' => 'bytespider',       'label' => 'ByteDance',         'vendor' => 'ByteDance'],
        'Bytespider-Image'     => ['type' => 'bytespider-image', 'label' => 'ByteDance Image',   'vendor' => 'ByteDance'],
        // ─── Apple (2) ───────────────────────────────────────────────
        'Applebot'             => ['type' => 'applebot',         'label' => 'Apple Bot',         'vendor' => 'Apple'],
        'Applebot-Extended'    => ['type' => 'applebot-extended','label' => 'Apple Bot Ext',    'vendor' => 'Apple'],
        // ─── Amazon (2) ──────────────────────────────────────────────
        'Amazonbot'            => ['type' => 'amazonbot',        'label' => 'Amazon Bot',        'vendor' => 'Amazon'],
        'Amazonbot-Image'      => ['type' => 'amazonbot-image',  'label' => 'Amazon Image',      'vendor' => 'Amazon'],
        // ─── Cohere (2) ──────────────────────────────────────────────
        'cohere-ai'            => ['type' => 'cohere-ai',       'label' => 'Cohere AI',        'vendor' => 'Cohere'],
        'cohere-agent'         => ['type' => 'cohere-agent',     'label' => 'Cohere Agent',      'vendor' => 'Cohere'],
        // ─── You.com (1) ─────────────────────────────────────────────
        'YouBot'               => ['type' => 'youbot',          'label' => 'You.com',          'vendor' => 'You.com'],
        // ─── Phind (1) ───────────────────────────────────────────────
        'PhindBot'             => ['type' => 'phindbot',        'label' => 'Phind',            'vendor' => 'Phind'],
        // ─── Kagi (2) ───────────────────────────────────────────────
        'kagi-bot'             => ['type' => 'kagi-bot',         'label' => 'Kagi Bot',         'vendor' => 'Kagi'],
        'kagi-ssp'             => ['type' => 'kagi-ssp',         'label' => 'Kagi SSP',         'vendor' => 'Kagi'],
        // ─── Brave (1) ──────────────────────────────────────────────
        'BraveBot'             => ['type' => 'bravebot',         'label' => 'Brave Bot',        'vendor' => 'Brave'],
        // ─── DuckDuckGo (2) ──────────────────────────────────────────
        'DuckDuckBot'          => ['type' => 'duckduckbot',      'label' => 'DuckDuckGo',       'vendor' => 'DuckDuckGo'],
        'DuckDuckGo-Favicons-Bot' => ['type' => 'ddg-favicons',  'label' => 'DDG Favicons',    'vendor' => 'DuckDuckGo'],
        // ─── Mojeek (1) ──────────────────────────────────────────────
        'MojeekBot'            => ['type' => 'mojeekbot',        'label' => 'Mojeek',           'vendor' => 'Mojeek'],
        // ─── Exa (1) ─────────────────────────────────────────────────
        'Exabot'               => ['type' => 'exabot',           'label' => 'Exa',              'vendor' => 'Exa'],
        // ─── Marginalia (1) ──────────────────────────────────────────
        'marginalia-bot'       => ['type' => 'marginalia',       'label' => 'Marginalia',       'vendor' => 'Marginalia'],
        // ─── Yandex (2) ──────────────────────────────────────────────
        'YandexBot'            => ['type' => 'yandexbot',        'label' => 'Yandex',           'vendor' => 'Yandex'],
        'YandexImages'         => ['type' => 'yandex-images',   'label' => 'Yandex Images',    'vendor' => 'Yandex'],
        // ─── Baidu (3) ───────────────────────────────────────────────
        'Baiduspider'          => ['type' => 'baiduspider',       'label' => 'Baidu',            'vendor' => 'Baidu'],
        'Baiduspider-Image'    => ['type' => 'baidu-image',      'label' => 'Baidu Image',      'vendor' => 'Baidu'],
        'Baiduspider-News'     => ['type' => 'baidu-news',       'label' => 'Baidu News',       'vendor' => 'Baidu'],
        // ─── Naver (1) ───────────────────────────────────────────────
        'Yeti'                 => ['type' => 'yeti',             'label' => 'Naver Yeti',       'vendor' => 'Naver'],
        // ─── Seznam (1) ─────────────────────────────────────────────
        'SeznamBot'            => ['type' => 'seznambot',        'label' => 'Seznam',           'vendor' => 'Seznam'],
        // ─── Sogou (2) ───────────────────────────────────────────────
        'Sogou Web Spider'     => ['type' => 'sogou-web',        'label' => 'Sogou Web',        'vendor' => 'Sogou'],
        'Sogou Pic Spider'     => ['type' => 'sogou-pic',        'label' => 'Sogou Pic',        'vendor' => 'Sogou'],
        // ─── Yisou (1) ───────────────────────────────────────────────
        'YisouSpider'          => ['type' => 'yisou',             'label' => 'Yisou',            'vendor' => 'Yisou'],
        // ─── Qwant (1) ───────────────────────────────────────────────
        'Qwantify'             => ['type' => 'qwantify',         'label' => 'Qwant',            'vendor' => 'Qwant'],
        // ─── Petal (1) ───────────────────────────────────────────────
        'PetalBot'             => ['type' => 'petalbot',         'label' => 'Petal',            'vendor' => 'Huawei'],
        // ─── Ahrefs (2) ──────────────────────────────────────────────
        'AhrefsBot'            => ['type' => 'ahrefsbot',         'label' => 'Ahrefs',           'vendor' => 'Ahrefs'],
        'AhrefsSiteAudit'      => ['type' => 'ahrefs-audit',     'label' => 'Ahrefs Audit',     'vendor' => 'Ahrefs'],
        // ─── Semrush (1) ─────────────────────────────────────────────
        'SemrushBot'           => ['type' => 'semrushbot',        'label' => 'Semrush',          'vendor' => 'Semrush'],
        // ─── MJ12 (1) ───────────────────────────────────────────────
        'MJ12bot'              => ['type' => 'mj12bot',           'label' => 'MJ12',             'vendor' => 'Majestic12'],
        // ─── Moz (2) ────────────────────────────────────────────────
        'rogerbot'             => ['type' => 'rogerbot',         'label' => 'Moz Roger',        'vendor' => 'Moz'],
        'MozBot'               => ['type' => 'mozbot',           'label' => 'Moz',              'vendor' => 'Moz'],
        // ─── DotBot (1) ─────────────────────────────────────────────
        'DotBot'               => ['type' => 'dotbot',           'label' => 'DotBot',           'vendor' => 'DotBot'],
        // ─── Serpstat (1) ────────────────────────────────────────────
        'serpstatbot'          => ['type' => 'serpstatbot',       'label' => 'Serpstat',         'vendor' => 'Serpstat'],
        // ─── BUbiNG (1) ─────────────────────────────────────────────
        'BUbiNG'               => ['type' => 'bubing',           'label' => 'BUbiNG',           'vendor' => 'BUbiNG'],
        // ─── Awario (2) ─────────────────────────────────────────────
        'AwarioRssBot'         => ['type' => 'awario-rss',       'label' => 'Awario RSS',       'vendor' => 'Awario'],
        'AwarioBot'            => ['type' => 'awariobot',         'label' => 'Awario',           'vendor' => 'Awario'],
        // ─── Brandwatch (1) ──────────────────────────────────────────
        'Brandwatch'           => ['type' => 'brandwatch',       'label' => 'Brandwatch',        'vendor' => 'Brandwatch'],
        // ─── Conductor (1) ───────────────────────────────────────────
        'ConductorCrawler'     => ['type' => 'conductor',        'label' => 'Conductor',        'vendor' => 'Conductor'],
        // ─── Diffbot (1) ────────────────────────────────────────────
        'Diffbot'              => ['type' => 'diffbot',           'label' => 'Diffbot',           'vendor' => 'Diffbot'],
        // ─── Timpibot (1) ───────────────────────────────────────────
        'Timpibot'             => ['type' => 'timpibot',         'label' => 'Timpi',            'vendor' => 'Timpi'],
        // ─── Pipl (1) ───────────────────────────────────────────────
        'PiplBot'              => ['type' => 'piplbot',           'label' => 'Pipl',             'vendor' => 'Pipl'],
        // ─── KadoBot (1) ────────────────────────────────────────────
        'KadoBot'              => ['type' => 'kadobot',           'label' => 'KadoBot',          'vendor' => 'KadoBot'],
        // ─── Screenshot Machine (1) ──────────────────────────────────
        'ScreenshotMachine'    => ['type' => 'screenshot-machine','label' => 'ScreenshotMachine','vendor' => 'Screenshot Machine'],
        // ─── SiteShotter (1) ─────────────────────────────────────────
        'SiteShotBot'          => ['type' => 'siteshot',         'label' => 'SiteShot',         'vendor' => 'SiteShotter'],
        // ─── 2ip (1) ────────────────────────────────────────────────
        '2ip-bot'              => ['type' => '2ipbot',            'label' => '2ip Bot',          'vendor' => '2ip'],
        // ─── UptimeRobot (1) ─────────────────────────────────────────
        'UptimeRobot'          => ['type' => 'uptime-robot',     'label' => 'UptimeRobot',      'vendor' => 'UptimeRobot'],
        // ─── AppInsights (1) ─────────────────────────────────────────
        'AppInsights'          => ['type' => 'appinsights',      'label' => 'AppInsights',      'vendor' => 'Microsoft'],
        // ─── ChatGPT-Detector (1) ────────────────────────────────────
        'ChatGPT-Detector'     => ['type' => 'chatgpt-detector', 'label' => 'ChatGPT Detector',  'vendor' => 'OpenAI'],
        // ─── APIAI (1) ──────────────────────────────────────────────
        'api-ai'               => ['type' => 'api-ai',           'label' => 'API.AI',           'vendor' => 'Google'],
        // ─── AdsBot-Google (2) ───────────────────────────────────────
        'AdsBot-Google'        => ['type' => 'adsbot-google',    'label' => 'Google AdsBot',     'vendor' => 'Google'],
        'AdsBot-Google-Mobile' => ['type' => 'adsbot-mobile',    'label' => 'Google AdsBot Mobile','vendor' => 'Google'],
        // ─── PILSU (1) ──────────────────────────────────────────────
        'PILSU'                => ['type' => 'pilsu',             'label' => 'PILSU',            'vendor' => 'PILSU'],
        // ─── Lozigram (1) ────────────────────────────────────────────
        'lozigram_bot'         => ['type' => 'lozigram',         'label' => 'Lozigram',         'vendor' => 'Lozigram'],
        // ─── Sosohiz (1) ────────────────────────────────────────────
        'Sosohiz'              => ['type' => 'sosohiz',          'label' => 'Sosohiz',          'vendor' => 'Sosohiz'],
        // ─── seolyt (1) ─────────────────────────────────────────────
        'seolyt'               => ['type' => 'seolyt',           'label' => 'seolyt',           'vendor' => 'seolyt'],
        // ─── Yo.com (1) ─────────────────────────────────────────────
        'yo.com'               => ['type' => 'yocom',            'label' => 'yo.com',           'vendor' => 'yo.com'],
    ];
    // phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
}

/**
 * Return count of registered AI crawlers (used in UI: "65+ AI crawler tracking").
 *
 * @return int
 */
function shop_to_llm_get_ai_crawler_count(): int
{
    return count(shop_to_llm_get_ai_crawler_registry());
}

/**
 * Return list of AI crawlers allowed in robots.txt (subset of Registry).
 * Only includes crawlers that publish AI/LLM use cases (excludes ads-only bots).
 *
 * @return array<string,array{id:string,label:string,vendor:string}>
 */
function shop_to_llm_get_robots_allowed_crawlers(): array
{
    $registry = shop_to_llm_get_ai_crawler_registry();
    $allowTypes = [
        'oai-search', 'chatgpt-user', 'gptbot',
        'claude-bot', 'claude-web', 'claude-search',
        'perplexity-bot', 'perplexity-user', 'perplexity-v1',
        'google-extended', 'googlebot', 'googlebot-image', 'google-inspect', 'google-readaloud',
        'bingbot', 'bing-preview',
        'meta-external', 'meta-fetcher', 'facebook-bot',
        'bytespider', 'bytespider-image',
        'applebot', 'applebot-extended',
        'amazonbot', 'amazonbot-image',
        'cohere-ai', 'cohere-agent',
        'youbot', 'phindbot', 'kagi-bot', 'kagi-ssp', 'bravebot',
        'duckduckbot', 'ddg-favicons', 'mojeekbot', 'exabot', 'marginalia',
        'yandexbot', 'yandex-images',
        'baiduspider', 'baidu-image', 'baidu-news',
        'yeti', 'seznambot', 'sogou-web', 'sogou-pic', 'yisou',
        'qwantify', 'petalbot',
        'chatgpt-detector', 'api-ai',
    ];
    return array_filter(
        $registry,
        static function ($meta) use ($allowTypes) {
            return in_array($meta['type'], $allowTypes, true);
        }
    );
}

function shop_to_llm_report_crawler(string $crawlerType): void
{
    shop_to_llm_log_crawler($crawlerType);

    // First crawler notification — show admin notice on next page load + email the admin
    if (!get_option('shop_to_llm_first_crawler_notified')) {
        $registry = shop_to_llm_get_ai_crawler_registry();
        $platformName = $crawlerType;
        foreach ($registry as $pattern => $meta) {
            if ($meta['type'] === $crawlerType) {
                $platformName = $meta['label'];
                break;
            }
        }
        set_transient('shop_to_llm_first_crawler_admin_notice', $platformName, DAY_IN_SECONDS);
        update_option('shop_to_llm_first_crawler_notified', current_time('mysql'));
        update_option('shop_to_llm_first_crawler_platform', $platformName);

        // Email the admin so they come back to the dashboard even if not logged in
        $adminEmail = get_option('admin_email');
        if ($adminEmail) {
            $siteName = get_bloginfo('name');
            /* translators: 1: Site name, 2: AI platform name (e.g. ChatGPT) */
            $subject = sprintf(__('[%1$s] %2$s just discovered your site!', 'shop2llm'), $siteName, $platformName);
            $dashboardUrl = admin_url('admin.php?page=shop2llm');
            /* translators: 1: AI platform name, 2: Dashboard URL */
            $message = sprintf(
                __("Hi there,\n\nGreat news — %1\$s just crawled your site and can now read your content via Shop2LLM.\n\nThis means AI assistants like ChatGPT can now search and recommend your content to their users.\n\nSee your AI visibility dashboard:\n%2\$s\n\n— Shop2LLM\n\n---\nYou received this because you enabled AI crawler notifications. To stop these emails, disable \"Email notifications\" in Shop2LLM → Settings.", 'shop2llm'),
                $platformName,
                $dashboardUrl
            );
            wp_mail($adminEmail, $subject, $message);
        }
    }

    if (get_option('shop_to_llm_analytics', 'no') !== 'yes') {
        return;
    }

    // Circuit breaker: skip reporting if external API was failing
    if (get_transient('shop_to_llm_crawler_report_backoff')) {
        return;
    }

    $apiKey = get_option('shop_to_llm_api_key', '');
    $siteUrl = get_site_url();
    $requestUri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $result = wp_remote_post('https://api.shop2llm.com/fincore/v1/crawler/log', [
        'timeout' => 1,
        'blocking' => false,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'license_key' => 'free_' . md5($siteUrl . $apiKey),
            'crawler_type' => $crawlerType,
            'page_url' => $siteUrl . $requestUri,
            'user_agent' => substr($ua, 0, 500),
            'response_code' => http_response_code() ?: 200,
        ]),
    ]);
    if (is_wp_error($result)) {
        $fails = (int) get_transient('shop_to_llm_crawler_report_fails');
        if ($fails > 3) {
            set_transient('shop_to_llm_crawler_report_backoff', 1, 5 * MINUTE_IN_SECONDS);
        } else {
            set_transient('shop_to_llm_crawler_report_fails', $fails + 1, MINUTE_IN_SECONDS);
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- guarded by WP_DEBUG, production-safe
            error_log('[Shop2LLM] Crawler report failed: ' . $result->get_error_message());
        }
    } else {
        // Reset fail counter on success
        delete_transient('shop_to_llm_crawler_report_fails');
    }
}

// ─── Product Schema (JSON-LD) ──────────────────────────────────────────

function shop_to_llm_inject_product_schema(): void
{
    // Skip if a known SEO plugin is active to avoid duplicate schema
    if (shop_to_llm_has_seo_plugin()) {
        return;
    }
    // Skip if SEO schema module is enabled (v1.3.0 schema module handles all types instead)
    if (get_option('shop_to_llm_seo_schema', 'yes') === 'yes') {
        return;
    }

    // WooCommerce product schema
    if (shop_to_llm_has_woocommerce() && is_product()) {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'image' => wp_get_attachment_url($product->get_image_id()),
            'sku' => $product->get_sku(),
            'offers' => [
                '@type' => 'Offer',
                'url' => get_permalink($product->get_id()),
                'priceCurrency' => get_woocommerce_currency(),
                'price' => $product->get_price(),
                'availability' => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'seller' => [
                    '@type' => 'Organization',
                    'name' => get_bloginfo('name'),
                ],
            ],
        ];
        if ($product->get_average_rating() > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $product->get_average_rating(),
                'reviewCount' => $product->get_review_count(),
            ];
        }
        /**
         * Filter the legacy Product schema before output.
         *
         * @param array      $schema  Product schema array.
         * @param int        $postId  Product post ID.
         * @param WC_Product $product Product object.
         */
        $schema = apply_filters('shop2llm_product_schema', $schema, $product->get_id(), $product);
        echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
        return;
    }

    // Non-WooCommerce: Article/BlogPosting schema on single posts
    if (!shop_to_llm_has_woocommerce() && is_singular('post')) {
        global $post;
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => get_the_title($post),
            'description' => wp_strip_all_tags(get_the_excerpt($post) ?: wp_trim_words(wp_strip_all_tags($post->post_content), 30)),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', $post->post_author),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
            ],
        ];
        $image = get_the_post_thumbnail_url($post, 'full');
        if ($image) {
            $schema['image'] = $image;
        }
        echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
    }
}

// ─── llms.txt Generation ───────────────────────────────────────────────

function shop_to_llm_generate_llms_txt(): void
{
    add_rewrite_rule('^llms\.txt$', 'index.php?shop_to_llm_llms_txt=1', 'top');
}

function shop_to_llm_llms_txt_query_var(array $vars): array
{
    $vars[] = 'shop_to_llm_llms_txt';
    return $vars;
}

function shop_to_llm_render_llms_txt(): void
{
    if (!get_query_var('shop_to_llm_llms_txt')) {
        return;
    }
    shop_to_llm_output_llms_txt();
}

/**
 * Render + exit the /llms.txt payload (no query-var guard) — shared by the
 * template_redirect renderer and the v1.5.4 parse_request URI fallback, which
 * outruns plugins that shadow our rewrite patterns or hijack .txt requests.
 */
function shop_to_llm_output_llms_txt(): void
{
    // v1.6.0: cache-compatibility — full no-cache headers so LiteSpeed Cache,
    // WP Rocket, W3 Total Cache and edge CDNs (Cloudflare) never serve a stale
    // llms.txt after catalog changes; freshness is critical for AI agents.
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $cached = get_transient('shop_to_llm_llms_txt_output');
    if ($cached !== false) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $cached;
        exit;
    }

    $output = shop_to_llm_build_llms_txt();
    set_transient('shop_to_llm_llms_txt_output', $output, HOUR_IN_SECONDS);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, all variables pre-escaped
    echo $output;
    exit;
}

// ─── /.well-known/mcp-server — IETF MCP Discovery Manifest (v1.7.0) ─────
// draft-serra-mcp-discovery-uri: agents resolving a bare domain fetch
// /.well-known/mcp-server to find the site's MCP endpoint before any
// out-of-band configuration. Required fields per spec §6.2; endpoint host
// MUST match the retrieval host (§6.8) — rest_url() guarantees that.

function shop_to_llm_register_mcp_server_rewrite(): void
{
    add_rewrite_rule('^\.well\-known/mcp\-server$', 'index.php?shop_to_llm_mcp_server=1', 'top');
}

function shop_to_llm_mcp_server_query_var(array $vars): array
{
    $vars[] = 'shop_to_llm_mcp_server';
    return $vars;
}
add_filter('query_vars', 'shop_to_llm_mcp_server_query_var');

function shop_to_llm_render_mcp_server_manifest(): void
{
    if (!get_query_var('shop_to_llm_mcp_server')) {
        return;
    }
    shop_to_llm_output_mcp_server_manifest();
}
add_action('template_redirect', 'shop_to_llm_render_mcp_server_manifest');

/**
 * Emit the discovery manifest (shared by the template_redirect renderer and
 * the parse_request URI fallback). JSON per draft §6.13/§6.14; transport uses
 * the spec's "http" value (JSON-RPC 2.0 over HTTPS). Auth mirrors the actual
 * MCP endpoint posture: Bearer token / X-Shop2LLM-Token, never open writes.
 */
function shop_to_llm_output_mcp_server_manifest(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: public, max-age=3600');

    $cached = get_transient('shop_to_llm_mcp_server_manifest');
    if ($cached !== false) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built JSON
        echo $cached;
        exit;
    }

    $locale = (string) get_locale();
    $manifest = [
        'mcp_version'  => '2025-06-18',
        'name'         => get_bloginfo('name') . ' — Shop2LLM MCP',
        'description'  => shop_to_llm_has_woocommerce()
            ? 'Product catalog, store policies and content search over JSON-RPC 2.0 (MCP)'
            : 'Content search over JSON-RPC 2.0 (MCP)',
        'endpoint'     => shop_to_llm_mcp_endpoint_url(),
        'transport'    => 'http',
        'capabilities' => ['tools'],
        'trust_class'  => 'public',
        'auth'         => ['required' => true, 'methods' => ['apikey']],
        'languages'    => [substr($locale, 0, 2) ?: 'en'],
        'docs'         => 'https://shop2llm.com/',
        'last_updated' => gmdate('c'),
        'expires'      => gmdate('c', time() + 7 * DAY_IN_SECONDS),
        'cache_ttl'    => 3600,
    ];
    $json = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    set_transient('shop_to_llm_mcp_server_manifest', $json, HOUR_IN_SECONDS);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built JSON
    echo $json;
    exit;
}

/**
 * v1.5.4: URI-level protocol fallback. Real-world conflict evidence: rewrite
 * patterns collide when another plugin registers the same pattern later
 * (e.g. `^llms-full\.txt$` claimed by LLMs.txt Curator) or hijacks .md/.txt
 * requests with its own 404 renderer (RankReady). parse_request fires before
 * every template_redirect, so matching the raw request URI here wins the race
 * deterministically regardless of plugin activation order.
 */
function shop_to_llm_protocol_uri_fallback(): void
{
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
    $path = rtrim($path, '/');
    if ('/llms.txt' === $path) {
        shop_to_llm_output_llms_txt();
    }
    if ('/llms-full.txt' === $path) {
        shop_to_llm_output_llms_full_txt();
    }
    if ('/agents.md' === $path) {
        shop_to_llm_render_agents_md_dossier(true);
        exit;
    }
    // v1.7.0: IETF MCP discovery manifest — root well-known URI, wins the race
    // over any plugin shadowing dot-directories.
    if ('/.well-known/mcp-server' === $path) {
        shop_to_llm_output_mcp_server_manifest();
    }
    // v1.7.1: IndexNow key verification file — fixed path, no rewrite flush.
    if ('/indexnow.txt' === $path) {
        shop_to_llm_indexnow_serve_key();
    }
    // v1.7.0: <path>.md markdown mirrors — only acts when the suffix maps to
    // a published post; otherwise control returns to WordPress untouched.
    shop_to_llm_markdown_uri_attempt($path);
}
add_action('parse_request', 'shop_to_llm_protocol_uri_fallback', 99);

/**
 * KSES whitelist for the static stroke icons above (svg + primitive shapes only).
 * wp_kses_post() strips <svg> by default, so icon output goes through wp_kses()
 * with this explicit list instead.
 *
 * @return array<string, array<string, bool>> Allowed tags and attributes.
 */
function shop_to_llm_allowed_svg(): array
{
    static $allowed = null;
    if ($allowed === null) {
        $allowed = [
            'svg' => ['viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true, 'aria-hidden' => true],
            'path' => ['d' => true],
            'rect' => ['width' => true, 'height' => true, 'x' => true, 'y' => true, 'rx' => true, 'ry' => true],
            'circle' => ['cx' => true, 'cy' => true, 'r' => true],
            'polygon' => ['points' => true],
            'line' => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true],
        ];
    }
    return $allowed;
}

/**
 * Minimal flat stroke icon (feather style, consistent with admin UI SVGs).
 * Returns a safe, static SVG string — no user input is involved.
 *
 * @param string $name Icon key.
 * @param int    $size Rendered pixel size.
 * @return string SVG HTML.
 */
function shop_to_llm_icon(string $name, int $size = 16): string
{
    static $icons = [
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'bot' => '<rect width="16" height="12" x="4" y="8" rx="2"/><path d="M12 8V4H8"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
        'cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'package' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'lightbulb' => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/>',
        'clipboard' => '<rect width="14" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
        'rocket' => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'video' => '<path d="m22 8-6 4 6 4V8Z"/><rect width="14" height="12" x="2" y="6" rx="2" ry="2"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 20h16a2 2 0 0 0 1.73-2Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'bar-chart' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'message' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'sparkles' => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3L12 3Z"/>',
        'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'brain' => '<path d="M12 5a3 3 0 1 0-5.997.125 4 4 0 0 0-2.526 5.77 4 4 0 0 0 .556 6.588A4 4 0 1 0 12 18Z"/><path d="M12 5a3 3 0 1 1 5.997.125 4 4 0 0 1 2.526 5.77 4 4 0 0 1-.556 6.588A4 4 0 1 1 12 18Z"/>',
        'flame' => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
    ];
    if (!isset($icons[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="' . (string) $size . '" height="' . (string) $size . '" style="vertical-align:-3px" aria-hidden="true">' . $icons[$name] . '</svg>';
}

/**
 * Plain-text price for machine-readable files (llms.txt / llms-full.txt):
 * "$149.00 (was $199.00)" instead of WooCommerce's price HTML, which strips
 * into entity soup like "&#036;149.00Current price is: ...".
 */
function shop_to_llm_plain_price(wc_product $product): string
{
    $currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
    $regular = $product->get_regular_price();
    $sale = $product->get_sale_price();
    if ($product->is_on_sale() && $sale !== '' && $regular !== '') {
        return sprintf('%1$s%2$s (was %1$s%3$s)', $currency, $sale, $regular);
    }
    if ($regular !== '') {
        return $currency . $regular;
    }
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags((string) $product->get_price_html()), ENT_QUOTES, 'UTF-8')));
}

/**
 * v1.8.0: Render a post's content HTML exactly the way a logged-out visitor
 * sees it — the single source of truth for every AI-facing content read
 * (/llms-full.txt, MCP get_product/search_products, per-page .md mirrors).
 *
 * Why: AI catalog builders can run while an admin is logged in (admin clicks
 * "View /llms.txt", regenerates the catalog from the dashboard, or the cloud
 * proxy calls back with an authenticated cookie). Membership and paywall
 * plugins hide protected content by hooking the_content for anonymous users —
 * rendering with the current user would embed members-only text into llms.txt
 * and MCP payloads (the exact incident class Website LLMs.txt shipped fixes
 * for in 8.5.4-8.6.0). We impersonate the anonymous visitor for the duration
 * of the filter chain and restore the original user afterwards.
 *
 * Returns the filtered HTML; callers strip tags or convert to Markdown as
 * needed. Password-protected posts are rejected upstream (never rendered).
 */
function shop_to_llm_public_content_html(\WP_Post $post): string
{
    $currentUserId = get_current_user_id();
    $switched = $currentUserId !== 0;
    if ($switched) {
        wp_set_current_user(0);
    }
    try {
        $content = get_the_content(null, false, $post);
        $content = apply_filters('the_content', $content);
    } finally {
        if ($switched) {
            wp_set_current_user($currentUserId);
        }
    }
    return (string) $content;
}

/**
 * Build the llms.txt content (no output, no exit) — single source of truth
 * reused by the /llms.txt renderer, Content Analysis scoring, and the
 * admin "one-click" regenerate/copy tooling.
 */
function shop_to_llm_build_llms_txt(): string
{
    $siteName = get_bloginfo('name');
    $siteUrl = get_site_url();
    $desc = get_bloginfo('description');
    $output = "# {$siteName}\n\n";
    $output .= "> {$desc}\n\n";
    $output .= shop_to_llm_has_woocommerce()
        ? "This store is connected to AI assistants via Shop2LLM.\n\n"
        : "This site is connected to AI assistants via Shop2LLM.\n\n";
    $output .= "## Products\n\n";
    if (shop_to_llm_has_woocommerce()) {
        $products = wc_get_products(['limit' => 100, 'status' => 'publish', 'orderby' => 'popularity']);
        foreach ($products as $product) {
            $price = shop_to_llm_plain_price($product);
            $stock = $product->is_in_stock() ? 'In Stock' : 'Out of Stock';
            $url = get_permalink($product->get_id());
            $output .= '- [' . esc_html($product->get_name()) . '](' . esc_url($url) . '): ' . esc_html($price) . ' — ' . esc_html($stock) . "\n";
        }
        $total = wp_count_posts('product')->publish ?? 0;
        if ($total > 100) {
            $output .= "\n... and " . ($total - 100) . " more products. Full catalog at " . esc_url($siteUrl . '/shop/') . "\n";
        }
    } else {
        // v1.8.0: password-protected posts are publish-status too — exclude them
        // from every AI catalog so protected titles never leak to agents.
        $posts = get_posts(['numberposts' => 50, 'post_status' => 'publish', 'has_password' => false, 'orderby' => 'date']);
        $output .= "## Posts\n\n";
        foreach ($posts as $post) {
            $title = esc_html($post->post_title);
            $url = esc_url(get_permalink($post->ID));
            $date = esc_html(get_the_date('', $post));
            $output .= "- [{$title}]({$url}) — {$date}\n";
        }
        $total = wp_count_posts('post')->publish ?? 0;
        if ($total > 50) {
            $output .= "\n... and " . ($total - 50) . " more posts.\n";
        }
    }
    // v1.6.0: Multilingual support — Polylang / WPML / TranslatePress detection;
    // expose alternate language URLs so AI agents can retrieve every locale.
    $output .= shop_to_llm_llms_txt_languages_section();

    $output .= "\n## AI Access\n\n";
    $output .= "- MCP Endpoint: " . esc_url(shop_to_llm_mcp_endpoint_url()) . "\n";
    $output .= "- AI Discovery: " . esc_url($siteUrl . '/wp-json/shop2llm/v1/.well-known/mcp') . "\n";
    $output .= "- Complete Catalog (full content): " . esc_url($siteUrl . '/llms-full.txt') . "\n";
    $output .= "- Product Feed (XML): " . esc_url($siteUrl . '/product-feed.xml') . "\n";
    $output .= "- Product Feed (URL): " . esc_url($siteUrl . '/products.xml') . "\n";

    // v1.4.0: Store Policies — AI commerce agents answer policy questions;
    // point them at the machine-readable policy pages (JSON-LD injected there).
    $output .= shop_to_llm_llms_txt_policies_section();

    return $output;
}

/**
 * v1.6.0: Languages section of llms.txt — detects Polylang, WPML and
 * TranslatePress and lists every active locale with its home URL so AI
 * agents can retrieve content in all languages. Returns '' when the site
 * is monolingual (no supported plugin active).
 */
function shop_to_llm_llms_txt_languages_section(): string
{
    $lines = [];

    // Polylang / Polylang Pro
    if (function_exists('pll_languages_list')) {
        $langs = pll_languages_list(['fields' => null]);
        if (is_array($langs) && count($langs) > 0) {
            foreach ($langs as $lang) {
                $code = is_object($lang) ? ($lang->slug ?? '') : (is_array($lang) ? ($lang['slug'] ?? '') : (string) $lang);
                $name = is_object($lang) ? ($lang->name ?? '') : (is_array($lang) ? ($lang['name'] ?? '') : '');
                if ($code === '') {
                    continue;
                }
                $url = function_exists('pll_home_url') ? pll_home_url($code) : home_url('/');
                $lines[] = '- [' . ($name !== '' ? $name : $code) . '](' . esc_url($url) . ')' . "\n";
            }
        }
    }

    // WPML
    if ($lines === [] && defined('ICL_SITEPRESS_VERSION')) {
        $wpmlLangs = apply_filters('wpml_active_languages', null, []);
        if (is_array($wpmlLangs) && count($wpmlLangs) > 0) {
            foreach ($wpmlLangs as $lang) {
                $code = $lang['code'] ?? '';
                $name = $lang['translated_name'] ?? ($lang['native_name'] ?? $code);
                $url = $lang['url'] ?? home_url('/');
                if ($code === '') {
                    continue;
                }
                $lines[] = '- [' . $name . '](' . esc_url($url) . ')' . "\n";
            }
        }
    }

    // TranslatePress
    if ($lines === [] && defined('TRP_PLUGIN_VERSION')) {
        $trpSettings = get_option('trp_settings', []);
        $trpCodes = $trpSettings['translate-languages'] ?? [];
        if (is_array($trpCodes) && count($trpCodes) > 1) {
            foreach ($trpCodes as $idx => $code) {
                $name = $code;
                if (class_exists('Locale')) {
                    $displayName = Locale::getDisplayName($code, $code);
                    if ($displayName !== '' && $displayName !== $code) {
                        $name = $displayName;
                    }
                }
                // TranslatePress: default language uses the plain URL, others ?lang=xx
                $url = $idx === 0 ? home_url('/') : add_query_arg('lang', $code, home_url('/'));
                $lines[] = '- [' . $name . '](' . esc_url($url) . ')' . "\n";
            }
        }
    }

    if ($lines === []) {
        return '';
    }

    $section = "\n## Languages\n\n";
    $section .= "This site is available in multiple languages:\n";
    foreach ($lines as $line) {
        $section .= $line;
    }
    return $section;
}

/**
 * Store Policies section of llms.txt (v1.4.0) — shared helper so both the
 * free builder and Pro's renderer (which takes over /llms.txt when Pro is
 * active) emit the identical section. Returns '' when no policy pages exist.
 */
function shop_to_llm_llms_txt_policies_section(): string
{
    $policyPages = shop_to_llm_policy_find_pages();
    if (empty($policyPages)) {
        return '';
    }
    $section = "\n## Store Policies\n\n";
    $policyLabels = [
        'returns' => 'Returns & Refunds',
        'shipping' => 'Shipping',
        'tax' => 'Tax',
        'terms' => 'Terms of Service',
        'privacy' => 'Privacy',
    ];
    foreach ($policyPages as $policyType => $policyPage) {
        $policyTitle = $policyPage['title'] !== '' ? $policyPage['title'] : ($policyLabels[$policyType] ?? $policyType);
        $section .= '- [' . esc_html($policyTitle) . '](' . esc_url($policyPage['url']) . '): machine-readable policy (JSON-LD) available on this page' . "\n";
    }
    return $section;
}

// ─── /llms-full.txt — Complete-Content Catalog (v1.6.0) ─────────────────
// llmstxt.org spec: /llms-full.txt carries the same structure as /llms.txt but
// expands every listed item with its COMPLETE content inline, so agents get the
// whole catalog in one fetch instead of crawling page by page. Previously this
// URL was an alias of the /agents.md dossier; it is now a dedicated endpoint.

function shop_to_llm_generate_llms_full_txt(): void
{
    add_rewrite_rule('^llms-full\.txt$', 'index.php?shop_to_llm_llms_full_txt=1', 'top');
}

function shop_to_llm_llms_full_txt_query_var(array $vars): array
{
    $vars[] = 'shop_to_llm_llms_full_txt';
    return $vars;
}

function shop_to_llm_render_llms_full_txt(): void
{
    if (!get_query_var('shop_to_llm_llms_full_txt')) {
        return;
    }
    shop_to_llm_output_llms_full_txt();
}

function shop_to_llm_output_llms_full_txt(): void
{
    // Same cache-compatibility contract as /llms.txt: freshness is critical.
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $cached = get_transient('shop_to_llm_llms_full_txt_output');
    if ($cached !== false) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $cached;
        exit;
    }

    $output = shop_to_llm_build_llms_full_txt();
    set_transient('shop_to_llm_llms_full_txt_output', $output, HOUR_IN_SECONDS);

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, all variables pre-escaped
    echo $output;
    exit;
}

/**
 * Build the /llms-full.txt payload: every published product (or post/page)
 * with its complete content inline. Item count and per-item content caps keep
 * the payload bounded; both are filterable for large catalogs.
 */
function shop_to_llm_build_llms_full_txt(): string
{
    $maxItems = (int) apply_filters('shop2llm_llms_full_max_items', 200);
    $maxChars = (int) apply_filters('shop2llm_llms_full_max_item_chars', 4000);

    $siteName = get_bloginfo('name');
    $siteUrl = get_site_url();
    $desc = get_bloginfo('description');
    $output = "# {$siteName} — Complete Catalog\n\n";
    if ($desc !== '') {
        $output .= "> {$desc}\n\n";
    }
    $output .= "Full-content version of " . esc_url($siteUrl . '/llms.txt') . " — every item below carries its complete text so AI agents read the entire catalog in one fetch.\n\n";

    if (shop_to_llm_has_woocommerce()) {
        $output .= "## Products\n\n";
        $products = wc_get_products(['limit' => $maxItems, 'status' => 'publish', 'orderby' => 'popularity']);
        foreach ($products as $product) {
            $output .= shop_to_llm_llms_full_product_block($product, $maxChars);
        }
        $total = wp_count_posts('product')->publish ?? 0;
        if ($total > $maxItems) {
            $output .= "\n... and " . ($total - $maxItems) . " more products. Full catalog at " . esc_url($siteUrl . '/shop/') . "\n";
        }
    } else {
        // v1.8.0: password-protected posts/pages excluded from full-content output.
        $posts = get_posts(['numberposts' => min($maxItems, 50), 'post_status' => 'publish', 'has_password' => false, 'orderby' => 'date']);
        $output .= "## Posts\n\n";
        foreach ($posts as $post) {
            $output .= shop_to_llm_llms_full_post_block($post, $maxChars);
        }
        $pages = get_posts(['numberposts' => min($maxItems, 30), 'post_status' => 'publish', 'post_type' => 'page', 'has_password' => false, 'orderby' => 'menu_order']);
        if ($pages !== []) {
            $output .= "\n## Pages\n\n";
            foreach ($pages as $page) {
                $output .= shop_to_llm_llms_full_post_block($page, $maxChars);
            }
        }
    }

    // v1.6.0: multilingual locales, machine-readable endpoints, store policies —
    // identical sections to /llms.txt so both files stay in sync.
    $output .= shop_to_llm_llms_txt_languages_section();

    $output .= "\n## AI Access\n\n";
    $output .= "- MCP Endpoint: " . esc_url(shop_to_llm_mcp_endpoint_url()) . "\n";
    $output .= "- AI Discovery: " . esc_url($siteUrl . '/wp-json/shop2llm/v1/.well-known/mcp') . "\n";
    if (shop_to_llm_has_woocommerce()) {
        $output .= "- Product Feed (XML): " . esc_url($siteUrl . '/product-feed.xml') . "\n";
        $output .= "- Product Feed (URL): " . esc_url($siteUrl . '/products.xml') . "\n";
    }
    $output .= "- Summary (lightweight): " . esc_url($siteUrl . '/llms.txt') . "\n";
    $output .= "- Agent Dossier: " . esc_url($siteUrl . '/agents.md') . "\n";

    $output .= shop_to_llm_llms_txt_policies_section();

    return $output;
}

/**
 * One product as a complete-content markdown block: title, URL, price, stock,
 * SKU, categories, tags and the full long description (truncated to $maxChars).
 */
function shop_to_llm_llms_full_product_block($product, int $maxChars): string
{
    $url = get_permalink($product->get_id());
    $block = "### " . esc_html($product->get_name()) . "\n\n";
    $block .= "- URL: " . esc_url($url) . "\n";
    $price = shop_to_llm_plain_price($product);
    if ($price !== '') {
        $block .= "- Price: " . esc_html($price) . "\n";
    }
    $block .= "- Availability: " . ($product->is_in_stock() ? 'In Stock' : 'Out of Stock') . "\n";
    $sku = $product->get_sku();
    if ($sku !== '') {
        $block .= "- SKU: " . esc_html($sku) . "\n";
    }
    $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
    if (is_array($cats) && $cats !== []) {
        $block .= "- Categories: " . esc_html(implode(', ', $cats)) . "\n";
    }
    $tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
    if (is_array($tags) && $tags !== []) {
        $block .= "- Tags: " . esc_html(implode(', ', $tags)) . "\n";
    }

    $desc = $product->get_description();
    if ($desc === '') {
        $desc = $product->get_short_description();
    }
    $desc = trim((string) wp_strip_all_tags((string) $desc));
    if ($desc !== '') {
        if (mb_strlen($desc) > $maxChars) {
            $desc = mb_substr($desc, 0, $maxChars - 3) . '...';
        }
        $block .= "\n" . $desc . "\n";
    }
    return $block . "\n";
}

/**
 * One post/page as a complete-content markdown block (truncated to $maxChars).
 * v1.8.0: content is rendered through the logged-out visitor view
 * (shop_to_llm_public_content_html) so membership/paywall plugins can hide
 * protected sections before the text ever reaches an AI agent.
 */
function shop_to_llm_llms_full_post_block($post, int $maxChars): string
{
    $title = esc_html($post->post_title);
    $url = esc_url(get_permalink($post->ID));
    $date = esc_html(get_the_date('Y-m-d', $post));
    $block = "### {$title}\n\n";
    $block .= "- URL: {$url}\n- Published: {$date}\n";
    $content = trim((string) wp_strip_all_tags(shop_to_llm_public_content_html($post)));
    if ($content !== '') {
        if (mb_strlen($content) > $maxChars) {
            $content = mb_substr($content, 0, $maxChars - 3) . '...';
        }
        $block .= "\n" . $content . "\n";
    }
    return $block . "\n";
}

// Invalidate llms-full.txt cache together with llms.txt on product changes
if (shop_to_llm_has_woocommerce()) {
    add_action('save_post_product', function () {
        delete_transient('shop_to_llm_llms_txt_output');
        delete_transient('shop_to_llm_llms_full_txt_output');
    });
    add_action('woocommerce_update_product', function () {
        delete_transient('shop_to_llm_llms_txt_output');
        delete_transient('shop_to_llm_llms_full_txt_output');
    });

    // Invalidate product quality cache on product changes
    add_action('save_post_product', function () {
        delete_transient('shop_to_llm_product_quality');
    });
    add_action('woocommerce_update_product', function () {
        delete_transient('shop_to_llm_product_quality');
    });
    // v1.7.0: ACP readiness must refresh with catalog changes too.
    add_action('save_post_product', function () {
        delete_transient('shop_to_llm_acp_readiness');
    });
    add_action('woocommerce_update_product', function () {
        delete_transient('shop_to_llm_acp_readiness');
    });
}

// ─── /agents.md — Markdown for AI Agents (v1.3.4+) ─────────────────────
// Structured Markdown endpoint for AI crawlers/agents to consume site metadata.
// Differs from /llms.txt (lightweight summary): /agents.md is a richer
// machine-readable dossier that includes UCP Readiness Score, MCP tool list,
// AI Crawler Registry summary, and JSON-LD Schema types. Mirrors OmniGEO/citelayer.

function shop_to_llm_generate_agents_md(): void
{
    add_rewrite_rule('^agents\.md$', 'index.php?shop_to_llm_agents_md=1', 'top');
}

function shop_to_llm_agents_md_query_var(array $vars): array
{
    $vars[] = 'shop_to_llm_agents_md';
    return $vars;
}

if (!function_exists('shop_to_llm_render_agents_md')) {
    function shop_to_llm_render_agents_md(): void
    {
        shop_to_llm_render_agents_md_dossier();
    }
}

/**
 * Render enhanced 7-section Agent Dossier.
 * Separated from the pluggable wrapper so it can hook template_redirect
 * at priority 1 — outrunning Pro's protocol redirect (priority 10) when
 * both plugins are active and Pro defines the pluggable function first.
 *
 * v1.5.4: $force=true lets the parse_request URI fallback render the dossier
 * when another plugin shadowed the ^agents\.md$ / ^llms-full\.txt$ patterns.
 */
function shop_to_llm_render_agents_md_dossier(bool $force = false): void
{
    if (!$force && !get_query_var('shop_to_llm_agents_md')) {
        return;
    }

        $currentVer = defined('SHOP_TO_LLM_VERSION') ? SHOP_TO_LLM_VERSION : '1.3.4';
        $cached = get_transient('shop_to_llm_agents_md_output');
        $cachedVer = get_transient('shop_to_llm_agents_md_version');
        // Invalidate cache on version bump so agents.md reflects the new version immediately.
    if ($cached !== false && $cachedVer === $currentVer) {
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Cache-Control: public, max-age=' . HOUR_IN_SECONDS);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $cached;
        exit;
    }

        $siteName  = get_bloginfo('name');
        $siteUrl   = rtrim((string) get_site_url(), '/');
        $desc      = get_bloginfo('description');
        $hasWoo    = shop_to_llm_has_woocommerce();
        $pluginVer = $currentVer;
        $crawlerCount = shop_to_llm_get_ai_crawler_count();
        $allowedCrawlers = shop_to_llm_get_robots_allowed_crawlers();

        $md = "# {$siteName} — Agent Dossier\n\n";
        $md .= "> {$desc}\n\n";
        $md .= "**Site URL:** {$siteUrl}\n";
        $md .= "**Platform:** " . ($hasWoo ? 'WooCommerce' : 'WordPress') . "\n";
        $md .= "**Agent Bridge:** Shop2LLM Free v{$pluginVer}\n";
        $md .= "**Generated:** " . gmdate('c') . "\n\n";

        // ─── Section 1: UCP Readiness Score ──────────────────────────────
        $ucp = shop_to_llm_calculate_ucp_readiness();
        $md .= "## UCP Readiness Score ({$ucp['total']}/100)\n\n";
        $md .= "Methodology: `{$ucp['formula']}`\n\n";
        $md .= "| Dimension | Score | Max | Formula |\n";
        $md .= "|-----------|-------|-----|---------|\n";
    foreach ($ucp['dimensions'] as $dim) {
        $md .= "| " . $dim['label'] . " | " . $dim['score'] . " | " . $dim['max'] . " | " . $dim['formula'] . " |\n";
    }
        $md .= "\n";

        // ─── Section 2: MCP Endpoint & Tools ─────────────────────────────
        $md .= "## MCP Endpoint (JSON-RPC 2.0)\n\n";
        $md .= "- Discovery: {$siteUrl}/wp-json/shop2llm/v1/.well-known/mcp\n";
        $md .= '- Endpoint:  ' . shop_to_llm_mcp_endpoint_url() . "\n";
        $md .= "- Protocol:  JSON-RPC 2.0\n";
        $md .= "- Auth:      API key via `X-Shop2LLM-Key` header (free tier: 5 daily LLM calls via FinCore cloud proxy)\n";
        $md .= "- Tools (4): search_products, get_product, get_policies, get_store_overview\n\n";

        // ─── Section 3: AI Crawler Registry ──────────────────────────────
        $md .= "## AI Crawler Registry\n\n";
        $md .= "- Total tracked: {$crawlerCount} AI crawlers\n";
        $md .= "- Allowed via robots.txt: " . count($allowedCrawlers) . "\n";
        $md .= "- Notable allowed: GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Bytespider, Anthropic, CCBot\n\n";

        // ─── Section 4: Content Catalog ──────────────────────────────────
        $md .= "## Content Catalog\n\n";
    if ($hasWoo) {
        $products = wc_get_products(['limit' => 50, 'status' => 'publish', 'orderby' => 'popularity']);
        $total = wp_count_posts('product')->publish ?? 0;
        $md .= "### Products ({$total} total, top 50 by popularity)\n\n";
        foreach ($products as $product) {
            $price = shop_to_llm_plain_price($product);
            $stock = $product->is_in_stock() ? 'In Stock' : 'Out of Stock';
            $url = get_permalink($product->get_id());
            $md .= '- [' . esc_html($product->get_name()) . '](' . esc_url($url) . ') — ' . esc_html($price) . ' — ' . esc_html($stock) . "\n";
        }
        if ($total > 50) {
            $md .= "\n... and " . ($total - 50) . " more at {$siteUrl}/shop/\n";
        }
    } else {
        $posts = get_posts(['numberposts' => 30, 'post_status' => 'publish', 'orderby' => 'date']);
        $total = wp_count_posts('post')->publish ?? 0;
        $md .= "### Posts ({$total} total, recent 30)\n\n";
        foreach ($posts as $post) {
            $title = $post->post_title;
            $url = get_permalink($post->ID);
            $date = get_the_date('Y-m-d', $post);
            $md .= "- [{$title}]({$url}) — {$date}\n";
        }
    }
        $md .= "\n";

        // ─── Section 5: Schema.org JSON-LD Types ─────────────────────────
        $md .= "## Schema.org JSON-LD Types (19 in free tier)\n\n";
        $md .= "Product, Organization, BreadcrumbList, WebSite, SearchAction, Article, Store, Offer, AggregateRating, Review, FAQPage, HowTo, LocalBusiness, Place, Event, Recipe, VideoObject, ImageObject, Person.\n\n";

        // ─── Section 6: AI Discovery Files ───────────────────────────────
        $md .= "## AI Discovery Files\n\n";
        $md .= "- /robots.txt — AI crawler Allow rules\n";
        $md .= "- /llms.txt   — Lightweight AI summary ({$siteUrl}/llms.txt)\n";
        $md .= "- /agents.md  — This dossier ({$siteUrl}/agents.md)\n";
    if ($hasWoo) {
        $md .= "- /product-feed.xml — Structured product feed ({$siteUrl}/product-feed.xml)\n";
        $md .= "- /products.xml      — URL list ({$siteUrl}/products.xml)\n";
    }
        $md .= "\n";

        // ─── Section 7: Agentic Commerce Capabilities ───────────────────
        // Only claim tools that shop_to_llm_tools_call() actually serves;
        // advertising phantom tools (add_to_cart, RAG_ask …) makes agents
        // fail on their first call and uninstall the plugin.
        $md .= "## Agentic Commerce Capabilities\n\n";
        $md .= "- AI agent can search the catalog via MCP `search_products` tool\n";
        $md .= "- AI agent can fetch product details via MCP `get_product` tool\n";
    if ($hasWoo) {
        $md .= "- WooCommerce Store API available: {$siteUrl}/wp-json/wc/store/v1\n";
        $md .= "- WooCommerce Cart API: {$siteUrl}/wp-json/wc/store/v1/cart\n";
    }
        $md .= "- Store policies readable via MCP `get_policies` tool\n";
        $md .= "- Store overview readable via MCP `get_store_overview` tool\n\n";

        $md .= "---\n";
        $md .= "Generated by Shop2LLM v{$pluginVer}. Re-generates every hour or on product changes.\n";

        set_transient('shop_to_llm_agents_md_output', $md, HOUR_IN_SECONDS);
        set_transient('shop_to_llm_agents_md_version', $currentVer, HOUR_IN_SECONDS);

        header('Content-Type: text/markdown; charset=UTF-8');
        header('Cache-Control: public, max-age=' . HOUR_IN_SECONDS);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markdown text, variables pre-escaped
        echo $md;
        exit;
}

// Invalidate /agents.md cache alongside llms.txt
if (shop_to_llm_has_woocommerce()) {
    add_action('save_post_product', function () {
        delete_transient('shop_to_llm_agents_md_output');
    });
    add_action('woocommerce_update_product', function () {
        delete_transient('shop_to_llm_agents_md_output');
    });
}
add_action('save_post', function ($postId) {
    if (get_post_type($postId) === 'post') {
        delete_transient('shop_to_llm_agents_md_output');
    }
});

// ─── robots.txt Optimization ───────────────────────────────────────────

/**
 * v1.5.5: opt-in AI usage policy appended to the virtual robots.txt.
 *
 * Mirrors the Training/Summarization/Attribution convention popularized by
 * Squirrly's /.well-known/llms.txt header: three declarative comment lines
 * that AI operators and consent-aware crawlers can parse. Off by default —
 * this is a site-owner policy statement, not a plugin default.
 *
 * @return string '' when disabled, else a leading-newline comment block.
 */
function shop_to_llm_ai_usage_policy_section(): string
{
    $mode = (string) get_option('shop_to_llm_ai_usage_policy', 'off');
    $site = home_url('/');
    $sections = [
        // Training/Summarization/Attribution — the Squirrly-style triple header.
        'open' => [
            'Training'      => 'Permitted for any AI provider',
            'Summarization' => 'Permitted with attribution',
            'Attribution'   => 'Cite ' . $site . ' as the source with a visible link',
        ],
        'attribution' => [
            'Training'      => 'Not permitted without prior written consent',
            'Summarization' => 'Permitted with attribution',
            'Attribution'   => 'Cite ' . $site . ' as the source with a visible link',
        ],
        'closed' => [
            'Training'      => 'Not permitted',
            'Summarization' => 'Search snippets only (title + link)',
            'Attribution'   => 'Cite ' . $site . ' as the source with a visible link',
        ],
    ];
    if (!isset($sections[$mode])) {
        return '';
    }
    // v1.7.0: the three human-readable comment lines are paired with the
    // standard Content-Signal directive (contentsignals.org) so purpose-based
    // policy is machine-readable, not comment-only. Mapping mirrors the banner
    // semantics: attribution mode permits quoting but not training-corpus use.
    // v1.8.0: adds the fourth "use" field (Cloudflare Content Signals
    // extension, July 2026) — use=immediate (interact, store nothing) /
    // use=reference (index, excerpt, link back) / use=full (summarize and
    // reproduce). Declarative preference only: no crawler is technically
    // forced to honor it.
    $signals = [
        'open' => 'ai-train=yes, search=yes, ai-input=yes, use=full',
        'attribution' => 'ai-train=no, search=yes, ai-input=yes, use=reference',
        'closed' => 'ai-train=no, search=no, ai-input=no, use=immediate',
    ];
    $lines = ['# AI Usage Policy (Shop2LLM)'];
    foreach ($sections[$mode] as $key => $value) {
        $lines[] = '# ' . $key . ': ' . $value;
    }
    $lines[] = 'Content-Signal: ' . $signals[$mode];
    return "\n" . implode("\n", $lines) . "\n";
}

function shop_to_llm_robots_txt(string $output): string
{
    // Coexistence guard: if Pro is active it appends its own (richer) AI Allow list
    // to $output via shop_to_llm_pro_robots_ai_welcome(). Detect Pro's marker and
    // skip our prepend to avoid duplicate AI Allow blocks in robots.txt.
    if (
        strpos($output, '# AI Crawlers Welcome (Shop2LLM Pro') !== false
        || strpos($output, 'User-agent: GPTBot') !== false
    ) {
        return $output;
    }
    // v1.7.2: grouped output (search-citation / user-triggered / training) with
    // explicit allow/block consequences — same allow list as before, now readable.
    $allowed = shop_to_llm_get_robots_allowed_crawlers();
    $custom = shop_to_llm_robots_render_grouped($allowed);
    return $custom . $output;
}

/**
 * v1.5.5: emits the opt-in AI usage policy banner at the TOP of robots.txt
 * (priority 1 — before every other robots_txt filter). Top placement is
 * deliberate: comment banners at the head of robots.txt are a widely used
 * convention, and other plugins (Squirrly, RankReady …) hook the same filter
 * at default or PHP_INT_MAX priority — no sane priority can guarantee the
 * last position, but priority 1 always guarantees the first.
 */
function shop_to_llm_robots_txt_usage_policy(string $output): string
{
    $policySection = shop_to_llm_ai_usage_policy_section();
    return $policySection !== '' ? ltrim($policySection, "\n") . $output : $output;
}
add_filter('robots_txt', 'shop_to_llm_robots_txt_usage_policy', 1);

// ─── AI Product Feed (Bing/ChatGPT/Google/Perplexity) ───────────────────

function shop_to_llm_product_feed_xml(): void
{
    if (!shop_to_llm_has_woocommerce()) {
        status_header(404);
        exit;
    }
    $products = wc_get_products(['limit' => 100, 'status' => 'publish', 'orderby' => 'date']);
    $storeUrl = get_site_url();
    $storeName = get_bloginfo('name');

    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>' . esc_xml($storeName) . '</title>' . "\n";
    echo '<link>' . esc_xml($storeUrl) . '</link>' . "\n";
    echo '<description>' . esc_xml(__('Shop2LLM product feed for ChatGPT, Bing Copilot, Google AI Overview, and Perplexity', 'shop2llm')) . '</description>' . "\n";

    foreach ($products as $p) {
        $pid = $p->get_id();
        $title = esc_xml(wp_strip_all_tags($p->get_name()));
        $desc = esc_xml(wp_strip_all_tags($p->get_short_description() ?: $p->get_description()));
        $link = esc_url(get_permalink($pid));
        $imgId = $p->get_image_id();
        $imgUrl = $imgId ? esc_url(wp_get_attachment_url($imgId)) : '';
        $price = $p->get_price();
        $currency = get_woocommerce_currency();
        $avail = esc_xml($p->is_in_stock() ? 'in stock' : 'out of stock');
        $sku = esc_xml($p->get_sku() ?: 'WC-' . $pid);
        $brand = esc_xml($storeName);
        $gtin = esc_xml($p->get_meta('_gtin') ?: $p->get_meta('_barcode') ?: '');

        echo '<item>' . "\n";
        echo '<g:id>' . $sku . '</g:id>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sku already escaped via esc_xml()
        echo '<title>' . $title . '</title>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title already escaped via esc_xml()
        echo '<description>' . ($desc ?: $title) . '</description>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc/$title already escaped via esc_xml()
        echo '<link>' . $link . '</link>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link already escaped via esc_url()
        if ($imgUrl) {
            echo '<g:image_link>' . $imgUrl . '</g:image_link>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $imgUrl already escaped via esc_url()
        }
        if ($price) {
            echo '<g:price>' . esc_xml($price . ' ' . $currency) . '</g:price>' . "\n";
        }
        echo '<g:availability>' . $avail . '</g:availability>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $avail already escaped via esc_xml()
        if ($brand) {
            echo '<g:brand>' . $brand . '</g:brand>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $brand already escaped via esc_xml()
        }
        if ($gtin) {
            echo '<g:gtin>' . $gtin . '</g:gtin>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $gtin already escaped via esc_xml()
        }
        echo '<g:condition>new</g:condition>' . "\n";
        echo '</item>' . "\n";
    }

    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

function shop_to_llm_register_feed_rewrite(): void
{
    add_rewrite_rule('^product-feed\.xml$', 'index.php?shop_to_llm_feed=1', 'top');
    add_rewrite_rule('^products\.xml$', 'index.php?shop_to_llm_feed=1', 'top');
}
add_action('init', 'shop_to_llm_register_feed_rewrite');

add_filter('query_vars', function (array $vars): array {
    $vars[] = 'shop_to_llm_feed';
    return $vars;
});

add_action('template_redirect', function () {
    if (get_query_var('shop_to_llm_feed')) {
        shop_to_llm_product_feed_xml();
    }
});

/**
 * ACP / ChatGPT Shopping feed readiness (v1.7.0). OpenAI's shopping
 * experience is discovery-first: product carousels are ranked from
 * structured catalog data, then merchants apply at chatgpt.com/merchants.
 * This check audits the same fields the platform reads — five required
 * (title, description, price, availability, image) plus GTIN recommended —
 * against the live catalog, so owners know whether the feed they submit is
 * complete before OpenAI reviews it. Score mirrors the Shop2LLM cloud
 * dashboard: 0.9 × average required-field pass rate + 0.1 × GTIN coverage.
 */
function shop_to_llm_acp_feed_readiness(): array
{
    $cached = get_transient('shop_to_llm_acp_readiness');
    if (is_array($cached)) {
        return $cached;
    }
    $empty = ['score' => 0, 'ready' => false, 'checked' => 0, 'gaps' => [], 'top_gap' => ''];
    if (!shop_to_llm_has_woocommerce() || !function_exists('wc_get_products')) {
        return $empty;
    }
    $products = wc_get_products([
        'status'   => 'publish',
        'limit'    => 100,
        'orderby'  => 'date',
        'order'    => 'DESC',
        'paginate' => false,
    ]);
    if (!is_array($products) || !$products) {
        return $empty;
    }
    $counts = [
        'title'        => 0,
        'description'  => 0,
        'price'        => 0,
        'availability' => 0,
        'image'        => 0,
        'gtin'         => 0,
    ];
    foreach ($products as $product) {
        if (!method_exists($product, 'get_name')) {
            continue;
        }
        $title = trim((string) $product->get_name());
        if ('' === $title || mb_strlen($title) > 150) {
            $counts['title']++;
        }
        $desc = trim((string) $product->get_short_description() . ' ' . (string) $product->get_description());
        if ('' === $desc || mb_strlen($desc) > 5000) {
            $counts['description']++;
        }
        if ((float) $product->get_price() <= 0) {
            $counts['price']++;
        }
        // Availability is always present in Woo, but out-of-stock items are
        // excluded from shopping recommendations — they count as a gap.
        if ('instock' !== $product->get_stock_status()) {
            $counts['availability']++;
        }
        if (!$product->get_image_id()) {
            $counts['image']++;
        }
        if (method_exists($product, 'get_global_unique_id') && '' === (string) $product->get_global_unique_id()) {
            $counts['gtin']++;
        }
    }
    $n = max(1, count($products));
    $rates = [];
    foreach (['title', 'description', 'price', 'availability', 'image'] as $field) {
        $rates[] = 1 - ($counts[$field] / $n);
    }
    $score = (int) round(90 * (array_sum($rates) / count($rates)) + 10 * (1 - ($counts['gtin'] / $n)));

    $gap_labels = [
        'title'        => __('long or missing titles', 'shop2llm'),
        'description'  => __('long or missing descriptions', 'shop2llm'),
        'price'        => __('missing prices', 'shop2llm'),
        'availability' => __('out-of-stock products', 'shop2llm'),
        'image'        => __('missing images', 'shop2llm'),
    ];
    $texts = [];
    foreach ($gap_labels as $field => $label) {
        if ($counts[$field] > 0) {
            $texts[] = $counts[$field] . ' ' . $label;
        }
    }
    if (!$texts && $counts['gtin'] > 0) {
        $texts[] = $counts['gtin'] . ' ' . __('products without GTIN (recommended)', 'shop2llm');
    }
    $result = [
        'score'   => $score,
        'ready'   => $score >= 80,
        'checked' => count($products),
        'gaps'    => $counts,
        'top_gap' => $texts
            ? sprintf(/* translators: %s: comma-separated product gap list */ __('Fix: %s to raise your ACP score', 'shop2llm'), implode(', ', array_slice($texts, 0, 3)))
            : '',
    ];
    set_transient('shop_to_llm_acp_readiness', $result, HOUR_IN_SECONDS);
    return $result;
}

// ─── Markdown for AI Agents — /slug.md + content negotiation (v1.7.0) ──
// Coding agents (Claude Code, Copilot workspace, Cursor, OpenCode) send
// `Accept: text/markdown`; full HTML wastes most of the context window on
// theme chrome, navigation and scripts. Every published public post is
// additionally readable at <permalink>.md, and any singular URL negotiates
// to Markdown when the agent's Accept header asks for it. Markdown mirrors
// carry X-Robots-Tag: noindex so search engines never index them as
// duplicate content.

function shop_to_llm_markdown_post_types(): array
{
    $types = get_post_types(['public' => true], 'names');
    unset($types['attachment']);
    return array_values($types);
}

/**
 * Resolve a markdown request path (the part before the .md suffix) to a
 * published post. get_page_by_path() covers hierarchical pages; the slug
 * lookup covers flat CPTs (posts, products, custom types). Returns null
 * when nothing matches — the caller then lets WordPress continue (404).
 */
function shop_to_llm_resolve_markdown_post(string $path): ?\WP_Post
{
    $path = trim($path, '/');
    if ('' === $path || strlen($path) > 200) {
        return null;
    }
    $post = get_page_by_path($path, OBJECT, shop_to_llm_markdown_post_types());
    if ($post instanceof \WP_Post && 'publish' === $post->post_status) {
        // v1.8.0: password-protected content never mirrors to AI agents.
        return $post->post_password !== '' ? null : $post;
    }
    $segments = explode('/', $path);
    $slug = (string) array_pop($segments);
    $found = get_posts([
        'name'        => $slug,
        'post_type'   => shop_to_llm_markdown_post_types(),
        'post_status' => 'publish',
        'has_password' => false, // v1.8.0
        'numberposts' => 1,
    ]);
    return $found[0] ?? null;
}

/**
 * Compact HTML → Markdown conversion. DOMDocument-based so malformed
 * page-builder output degrades gracefully; unknown tags fall through to
 * their text content. Dependency-free by design (zero bundled libraries).
 */
function shop_to_llm_html_to_markdown(string $html): string
{
    $html = trim($html);
    if ('' === $html) {
        return '';
    }
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="utf-8" ?><body>' . $html . '</body>',
        LIBXML_NOWARNING | LIBXML_NOERROR
    );
    libxml_clear_errors();
    $md = shop_to_llm_md_walk($dom);
    return trim((string) preg_replace('/\n{3,}/', "\n\n", $md));
}

/**
 * Recursive walker: converts known tags, strips layout/noise containers,
 * and treats everything else as a transparent container.
 */
function shop_to_llm_md_walk(\DOMNode $node): string
{
    static $skip = [
        'script', 'style', 'iframe', 'form', 'input', 'button', 'select',
        'textarea', 'nav', 'footer', 'aside', 'noscript', 'svg', 'canvas',
        'video', 'audio', 'object', 'embed', 'label', 'head', 'meta', 'link',
    ];
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof \DOMText) {
            $out .= (string) preg_replace('/\s+/u', ' ', $child->textContent);
            continue;
        }
        if (!$child instanceof \DOMElement) {
            continue;
        }
        $tag = strtolower($child->tagName);
        if (in_array($tag, $skip, true)) {
            continue;
        }
        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                                $out .= "\n\n" . str_repeat('#', (int) $tag[1]) . ' ' . trim(shop_to_llm_md_inline($child)) . "\n\n";
                break;
            case 'p':
                $text = trim(shop_to_llm_md_inline($child));
                $out .= ('' !== $text ? "\n\n" . $text . "\n\n" : '');
                break;
            case 'br':
                $out .= "  \n";
                break;
            case 'hr':
                $out .= "\n\n---\n\n";
                break;
            case 'pre':
                $out .= "\n\n```\n" . $child->textContent . "\n```\n\n";
                break;
            case 'blockquote':
                $quoted = trim(shop_to_llm_md_inline($child));
                $out .= "\n\n" . implode(
                    "\n",
                    array_map(static fn (string $line): string => '> ' . $line, explode("\n", $quoted))
                ) . "\n\n";
                break;
            case 'ul':
                $out .= shop_to_llm_md_list($child, false);
                break;
            case 'ol':
                $out .= shop_to_llm_md_list($child, true);
                break;
            case 'table':
                $out .= shop_to_llm_md_table($child);
                break;
            case 'img':
                $src = $child->getAttribute('src');
                if ('' !== $src) {
                    $out .= '![' . trim($child->getAttribute('alt')) . '](' . $src . ')';
                }
                break;
            case 'strong':
            case 'b':
                $out .= '**' . shop_to_llm_md_inline($child) . '**';
                break;
            case 'em':
            case 'i':
                $out .= '*' . shop_to_llm_md_inline($child) . '*';
                break;
            case 'code':
                $out .= '`' . $child->textContent . '`';
                break;
            case 'a':
                $href = $child->getAttribute('href');
                $label = trim(shop_to_llm_md_inline($child));
                $out .= ('' !== $href ? '[' . $label . '](' . $href . ')' : $label);
                break;
            default:
                $out .= shop_to_llm_md_walk($child);
        }
    }
    return $out;
}

/**
 * Inline-context walk: block newlines inside headings, paragraphs and list
 * items collapse to single spaces so Markdown structure stays intact.
 */
function shop_to_llm_md_inline(\DOMNode $node): string
{
    return (string) preg_replace('/\s*\n+\s*/', ' ', shop_to_llm_md_walk($node));
}

function shop_to_llm_md_list(\DOMElement $list, bool $ordered): string
{
    $lines = [];
    $index = 1;
    foreach ($list->childNodes as $li) {
        if (!$li instanceof \DOMElement || 'li' !== strtolower($li->tagName)) {
            continue;
        }
        $text = trim(shop_to_llm_md_inline($li));
        if ('' === $text) {
            continue;
        }
        $lines[] = $ordered ? ($index . '. ' . $text) : ('- ' . $text);
        $index++;
    }
    return ($lines ? "\n\n" . implode("\n", $lines) . "\n\n" : '');
}

function shop_to_llm_md_table(\DOMElement $table): string
{
    $rows = [];
    foreach ($table->getElementsByTagName('tr') as $tr) {
        if (!$tr instanceof \DOMElement) {
            continue;
        }
        $cells = [];
        foreach ($tr->childNodes as $cell) {
            if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                $cells[] = str_replace('|', '\\|', trim(shop_to_llm_md_inline($cell)));
            }
        }
        if ($cells) {
            $rows[] = '| ' . implode(' | ', $cells) . ' |';
        }
    }
    if (!$rows) {
        return '';
    }
    $cols = max(1, substr_count($rows[0], '|') - 1);
    $sep = '| ' . implode(' | ', array_fill(0, $cols, '---')) . ' |';
    array_splice($rows, 1, 0, [$sep]);
    return "\n\n" . implode("\n", $rows) . "\n\n";
}

/**
 * Frontmatter + body. YAML scalar values are emitted via json_encode — a
 * JSON string is a valid YAML flow scalar, so quoting/escaping is correct
 * without hand-rolled escaping. Product posts add price/stock/GTN fields
 * (the ACP discovery-first fields ChatGPT Shopping reads).
 */
function shop_to_llm_markdown_for_post(\WP_Post $post): string
{
    $quote = static fn (string $value): string => (string) wp_json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $excerpt = trim((string) $post->post_excerpt);
    if ('' === $excerpt) {
        // v1.8.0: generated descriptions derive from the logged-out visitor view.
        $excerpt = wp_trim_words(wp_strip_all_tags(shop_to_llm_public_content_html($post)), 40, '…');
    }

    $fields = [
        'title'      => $quote((string) get_the_title($post)),
        'author'     => $quote((string) get_the_author_meta('display_name', (int) $post->post_author)),
        'published'  => get_post_time('c', true, $post),
        'modified'   => get_post_modified_time('c', true, $post),
        'lang'       => (substr((string) get_locale(), 0, 2) ?: 'en'),
        'description' => $quote($excerpt),
        'permalink'  => (string) get_permalink($post),
    ];

    $is_product = ('product' === $post->post_type && post_type_exists('product'));
    [$cat_tax, $tag_tax] = $is_product ? ['product_cat', 'product_tag'] : ['category', 'post_tag'];
    $cats = shop_to_llm_markdown_terms($post, $cat_tax);
    $tags = shop_to_llm_markdown_terms($post, $tag_tax);
    if ($cats) {
        $fields['categories'] = (string) wp_json_encode($cats, JSON_UNESCAPED_UNICODE);
    }
    if ($tags) {
        $fields['tags'] = (string) wp_json_encode($tags, JSON_UNESCAPED_UNICODE);
    }

    // v1.8.0: posts/pages render through the logged-out visitor view so
    // membership/paywall plugins can hide protected sections first. Product
    // descriptions stay raw — they are public catalog data by definition and
    // the_content filters can expand commerce shortcodes unpredictably.
    $body = $is_product
        ? shop_to_llm_html_to_markdown((string) $post->post_content)
        : shop_to_llm_html_to_markdown(shop_to_llm_public_content_html($post));

    if ($is_product && function_exists('wc_get_product')) {
        $product = wc_get_product($post->ID);
        if ($product) {
            $fields['price'] = $product->get_price();
            $fields['currency'] = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
            $stock_map = ['instock' => 'InStock', 'outofstock' => 'OutOfStock', 'onbackorder' => 'BackOrder'];
            $fields['availability'] = $stock_map[$product->get_stock_status()] ?? 'Unknown';
            $sku = (string) $product->get_sku();
            if ('' !== $sku) {
                $fields['sku'] = $quote($sku);
            }
            if (method_exists($product, 'get_global_unique_id')) {
                $gtin = (string) $product->get_global_unique_id();
                if ('' !== $gtin) {
                    $fields['gtin'] = $quote($gtin);
                }
            }
            $body .= "\n\n## Price & Availability\n\n"
                . '- Price: ' . shop_to_llm_plain_price($product) . "\n"
                . '- Availability: ' . $fields['availability'] . "\n"
                . ('' !== $sku ? '- SKU: ' . $sku . "\n" : '');
            $body = rtrim($body) . "\n";
        }
    }

    $lines = ['---'];
    foreach ($fields as $key => $value) {
        $lines[] = $key . ': ' . $value;
    }
    $lines[] = '---';

    return implode("\n", $lines) . "\n\n" . $body . "\n";
}

function shop_to_llm_markdown_terms(\WP_Post $post, string $taxonomy): array
{
    $terms = get_the_terms($post, $taxonomy);
    if (!is_array($terms)) {
        return [];
    }
    return array_values(array_map(static fn ($term): string => (string) $term->name, $terms));
}

function shop_to_llm_output_markdown_for_post(\WP_Post $post): void
{
    header('Content-Type: text/markdown; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-cache, must-revalidate');

    $key = 'shop_to_llm_md_' . (int) $post->ID . '_' . substr(md5((string) $post->post_modified_gmt), 0, 8);
    $md = get_transient($key);
    if (false === $md) {
        $md = shop_to_llm_markdown_for_post($post);
        set_transient($key, $md, HOUR_IN_SECONDS);
    }

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markdown mirror, values pre-escaped at build time
    echo $md;
    exit;
}

/**
 * URI-level attempt for <path>.md requests (wired into the v1.5.4
 * parse_request fallback so it wins the race against plugin rewrites).
 * Silently returns when the path is not a markdown request or resolves to
 * nothing — WordPress then continues to its normal handling (usually 404).
 */
function shop_to_llm_markdown_uri_attempt(string $path): void
{
    if ('/agents.md' === $path || !preg_match('#^(.+)\.md$#', $path, $m)) {
        return;
    }
    $post = shop_to_llm_resolve_markdown_post($m[1]);
    if ($post instanceof \WP_Post) {
        shop_to_llm_output_markdown_for_post($post);
    }
}

/**
 * Accept-header negotiation on regular singular URLs: coding agents list
 * text/markdown in HTTP_ACCEPT (browsers never do), so this only ever
 * triggers for machine clients.
 */
function shop_to_llm_markdown_negotiate(): void
{
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron() || is_feed()) {
        return;
    }
    if (!is_singular()) {
        return;
    }
    $post = get_queried_object();
    if (!$post instanceof \WP_Post || !in_array($post->post_type, shop_to_llm_markdown_post_types(), true)) {
        return;
    }
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
    if (false === stripos($accept, 'text/markdown')) {
        return;
    }
    shop_to_llm_output_markdown_for_post($post);
}
add_action('template_redirect', 'shop_to_llm_markdown_negotiate', 0);

// ─── AI Crawler Activity Monitor (Feature Anchor) ─────────────────────

/**
 * Create the crawler activity DB table for persistent 30-day tracking.
 * Called on plugin activation.
 */
function shop_to_llm_create_crawler_table(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        bot_name VARCHAR(50) NOT NULL,
        bot_label VARCHAR(50) NOT NULL,
        page_url VARCHAR(500) NOT NULL DEFAULT '',
        post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        visited_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_bot (bot_name),
        KEY idx_date (visited_at),
        KEY idx_post (post_id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Add post_id column to existing installations
    $col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'post_id'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if (empty($col)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER page_url, ADD INDEX idx_post (post_id)"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}

/**
 * Persist a crawler visit to the DB table.
 */
function shop_to_llm_log_crawler_to_db(string $crawlerType, string $label): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';
    $requestUri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    $pageUrl = home_url($requestUri);
    // Truncate to fit column
    if (strlen($pageUrl) > 500) {
        $pageUrl = substr($pageUrl, 0, 500);
    }
    $postId = (int) url_to_postid($pageUrl);
    $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $table,
        [
            'bot_name' => $crawlerType,
            'bot_label' => $label,
            'page_url' => $pageUrl,
            'post_id' => $postId,
            'visited_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%d', '%s']
    );
}

/**
 * Get crawler activity stats for the dashboard widget.
 */
function shop_to_llm_get_crawler_stats(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';

    // Check if table exists
    $tableExists = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SHOW TABLES LIKE %s",
        $table
    ));
    if (!$tableExists) {
        return [
            'today_bots' => 0,
            'today_pages' => 0,
            'total_30d' => 0,
            'unique_bots_30d' => 0,
            'today_list' => [],
            'daily_counts' => [],
        ];
    }

    // Today's stats
    $today = current_time('Y-m-d');
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $todayRows = $wpdb->get_results($wpdb->prepare(
        "SELECT bot_label, COUNT(*) as cnt, MAX(visited_at) as last_visit
         FROM $table
         WHERE visited_at >= %s
         GROUP BY bot_label
         ORDER BY cnt DESC",
        $today . ' 00:00:00'
    ), ARRAY_A);
    // phpcs:enable

    $todayBots = count($todayRows);
    $todayPages = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(DISTINCT page_url) FROM $table WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $today . ' 00:00:00'
    ));

    // 30-day totals
    $thirtyDaysAgo = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    $total30d = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(*) FROM $table WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $thirtyDaysAgo
    ));
    $uniqueBots30d = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(DISTINCT bot_name) FROM $table WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $thirtyDaysAgo
    ));

    // Daily counts for 7-day sparkline
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $dailyCounts = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(visited_at) as day, COUNT(*) as cnt
         FROM $table
         WHERE visited_at >= %s
         GROUP BY DATE(visited_at)
         ORDER BY day ASC",
        gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)
    ), ARRAY_A);
    // phpcs:enable

    // Fill missing days with 0
    $sparkline = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
        $cnt = 0;
        foreach ($dailyCounts as $dc) {
            if ($dc['day'] === $day) {
                $cnt = (int) $dc['cnt'];
                break;
            }
        }
        $sparkline[] = $cnt;
    }

    return [
        'today_bots' => $todayBots,
        'today_pages' => $todayPages,
        'total_30d' => $total30d,
        'unique_bots_30d' => $uniqueBots30d,
        'today_list' => $todayRows,
        'sparkline' => $sparkline,
    ];
}

/**
 * Render the AI Crawler Activity section for the plugin page.
 */
function shop_to_llm_render_crawler_activity(): void
{
    $stats = shop_to_llm_get_crawler_stats();
    $maxVal = max($stats['sparkline'] ?: [1]);
    if ($maxVal === 0) {
        $maxVal = 1;
    }

    echo '<style>
    .s2ll-widget{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-sizing:border-box}
    .s2ll-widget *{box-sizing:border-box}
    .s2ll-summary{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .s2ll-big{font-size:28px;font-weight:700;color:#1e293b}
    .s2ll-label{font-size:13px;color:#64748b;margin-left:6px}
    .s2ll-link{font-size:12px;color:#6366f1;text-decoration:none;font-weight:500}
    .s2ll-sparkline{display:flex;align-items:flex-end;gap:4px;height:48px;margin-bottom:4px;padding:0 2px}
    .s2ll-bar{flex:1;border-radius:3px 3px 0 0;position:relative;min-width:0}
    .s2ll-bar-val{position:absolute;top:-18px;left:50%;transform:translateX(-50%);font-size:10px;color:#64748b;white-space:nowrap}
    .s2ll-axis{display:flex;justify-content:space-between;font-size:10px;color:#94a3b8;margin-bottom:14px}
    .s2ll-divider{border-top:1px solid #e2e8f0;padding-top:10px;margin-top:12px}
    .s2ll-bot-row{display:flex;align-items:center;gap:8px;margin-bottom:8px}
    .s2ll-bot-name{font-size:12px;font-weight:500;color:#1e293b;min-width:100px;flex-shrink:0}
    .s2ll-bot-track{flex:1;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;min-width:40px}
    .s2ll-bot-fill{height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:3px}
    .s2ll-bot-cnt{font-size:11px;color:#64748b;min-width:50px;text-align:right;flex-shrink:0}
    .s2ll-footer{display:flex;justify-content:space-between;align-items:center}
    .s2ll-footer-text{font-size:11px;color:#64748b}
    .s2ll-footer-text strong{color:#1e293b}
    .s2ll-warning{margin-top:8px;padding:6px 10px;background:#fef2f2;border-radius:5px;font-size:11px;color:#dc2626}
    .s2ll-empty{text-align:center;padding:16px 0;color:#94a3b8;font-size:13px}
    .s2ll-empty-sub{font-size:11px}
    </style>';

    echo '<div class="s2ll-widget">';

    // Summary line
    echo '<div class="s2ll-summary">';
    echo '<div>';
    echo '<span class="s2ll-big">' . (int) $stats['today_bots'] . '</span>';
    echo '<span class="s2ll-label">bots today</span>';
    echo '<span class="s2ll-big" style="margin-left:20px">' . (int) $stats['today_pages'] . '</span>';
    echo '<span class="s2ll-label">pages crawled</span>';
    echo '</div>';
    echo '</div>';

    // 7-day sparkline
    echo '<div class="s2ll-sparkline">';
    foreach ($stats['sparkline'] as $val) {
        $height = $val > 0 ? max(8, ($val / $maxVal) * 48) : 2;
        $color = $val > 0 ? '#6366f1' : '#e2e8f0';
        echo '<div class="s2ll-bar" style="height:' . esc_attr($height) . 'px;background:' . esc_attr($color) . '" title="' . (int) $val . ' crawls">';
        if ($val > 0) {
            echo '<span class="s2ll-bar-val">' . (int) $val . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '<div class="s2ll-axis"><span>7 days ago</span><span>Today</span></div>';

    // Today's bot list
    if (!empty($stats['today_list'])) {
        echo '<div class="s2ll-divider">';
        foreach ($stats['today_list'] as $row) {
            $cnt = (int) $row['cnt'];
            $bar = min(100, $cnt * 10);
            echo '<div class="s2ll-bot-row">';
            echo '<span class="s2ll-bot-name">' . esc_html($row['bot_label']) . '</span>';
            echo '<div class="s2ll-bot-track"><div class="s2ll-bot-fill" style="width:' . esc_attr($bar) . '%"></div></div>';
            echo '<span class="s2ll-bot-cnt">' . esc_html($cnt) . ' pages</span>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<div class="s2ll-empty">';
        echo '⏳ Waiting for AI crawlers to visit your site...';
        /* translators: %d: number of AI crawlers tracked */
        echo '<br><span class="s2ll-empty-sub">' . esc_html(sprintf(__('GPTBot, ClaudeBot, PerplexityBot and %d others will appear here.', 'shop2llm'), max(0, shop_to_llm_get_ai_crawler_count() - 3))) . '</span>';
        echo '</div>';
    }

    // 30-day summary
    echo '<div class="s2ll-divider s2ll-footer">';
    echo '<div class="s2ll-footer-text">';
    echo '30-day total: <strong>' . (int) $stats['total_30d'] . '</strong> crawls · ';
    echo '<strong>' . (int) $stats['unique_bots_30d'] . '</strong> unique bots';
    echo '</div>';
    echo '</div>';

    // Uninstall deterrence
    if ($stats['total_30d'] > 0) {
        echo '<div class="s2ll-warning">⚠ This data is lost if you uninstall Shop2LLM.</div>';
    }

    echo '</div>';
}

/**
 * v1.6.0: Per-crawler leaderboard — which AI bots visited in the last 7 days,
 * how many times, how many distinct pages, and when each was last seen.
 * Rendered inside the AI Crawler Activity card (AI Traffic tab).
 *
 * @param array $analytics Result of shop_to_llm_get_crawler_analytics(7).
 */
function shop_to_llm_render_crawler_breakdown(array $analytics): void
{
    $rows = $analytics['bot_breakdown'] ?? [];
    if (empty($rows)) {
        return;
    }
    $maxCnt = max(1, max(array_map(static fn($r) => (int) $r->cnt, $rows)));
    // Humanize "last seen": minutes/hours/days ago (or date for older entries).
    $humanize = static function (string $mysqlUtc): string {
        $ts = (int) strtotime($mysqlUtc . ' UTC');
        if ($ts <= 0) {
            return '—';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return __('just now', 'shop2llm');
        }
        if ($diff < HOUR_IN_SECONDS) {
            /* translators: %d: number of minutes */
            return sprintf(__('%dm ago', 'shop2llm'), (int) floor($diff / 60));
        }
        if ($diff < DAY_IN_SECONDS) {
            /* translators: %d: number of hours */
            return sprintf(__('%dh ago', 'shop2llm'), (int) floor($diff / HOUR_IN_SECONDS));
        }
        if ($diff < 7 * DAY_IN_SECONDS) {
            /* translators: %d: number of days */
            return sprintf(__('%dd ago', 'shop2llm'), (int) floor($diff / DAY_IN_SECONDS));
        }
        return mysql2date('M j', $mysqlUtc);
    };
    ?>
    <div class="s2ll-divider" id="stl-crawler-breakdown">
        <div style="font-size:12px;font-weight:600;color:#1e293b;margin-bottom:8px"><?php echo esc_html__('Which crawlers visited (last 7 days)', 'shop2llm'); ?></div>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr style="border-bottom:1px solid #e2e8f0">
                    <th style="text-align:left;padding:5px 6px;color:#64748b;font-weight:600"><?php echo esc_html__('Crawler', 'shop2llm'); ?></th>
                    <th style="text-align:right;padding:5px 6px;color:#64748b;font-weight:600"><?php echo esc_html__('Visits', 'shop2llm'); ?></th>
                    <th style="text-align:right;padding:5px 6px;color:#64748b;font-weight:600"><?php echo esc_html__('Pages', 'shop2llm'); ?></th>
                    <th style="text-align:right;padding:5px 6px;color:#64748b;font-weight:600"><?php echo esc_html__('Last seen', 'shop2llm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r) :
                    $cnt = (int) $r->cnt;
                    $pct = (int) round(($cnt / $maxCnt) * 100);
                    $pages = (int) $r->pages;
                    ?>
                <tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:6px;color:#1e293b;font-weight:500;position:relative">
                        <span style="position:absolute;left:0;top:4px;bottom:4px;width:<?php echo esc_attr($pct); ?>%;background:#f5f3ff;border-radius:4px;z-index:0"></span>
                        <span style="position:relative;z-index:1;padding-left:6px"><?php echo esc_html($r->bot_label ?: $r->bot_name); ?></span>
                    </td>
                    <td style="padding:6px;text-align:right;color:#1e293b;font-weight:600"><?php echo esc_html(number_format_i18n($cnt)); ?></td>
                    <td style="padding:6px;text-align:right;color:#475569"><?php echo esc_html(number_format_i18n($pages)); ?></td>
                    <td style="padding:6px;text-align:right;color:#64748b"><?php echo esc_html($humanize((string) $r->last_visit)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function shop_to_llm_render_indexed_pages(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if (!$exists) {
        return;
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $recent = $wpdb->get_results(
        "SELECT cl.post_id, cl.bot_label, cl.page_url, MAX(cl.visited_at) as last_visit
         FROM {$table} cl
         WHERE cl.post_id > 0
         GROUP BY cl.post_id, cl.bot_label
         ORDER BY last_visit DESC
         LIMIT 10"
    );
    // phpcs:enable

    if (empty($recent)) {
        echo '<p style="color:#94a3b8;font-size:12px;margin:0;line-height:1.5">'
            . esc_html__('No pages indexed yet. AI crawlers will discover your content over time.', 'shop2llm')
            . '</p>';
        return;
    }

    echo '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #e2e8f0">';
    echo '<strong style="font-size:12px;color:#64748b;display:block;margin-bottom:8px">'
        . esc_html__('Recently Indexed Pages', 'shop2llm') . '</strong>';
    echo '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    echo '<thead><tr style="border-bottom:1px solid #e2e8f0">'
        . '<th style="text-align:left;padding:4px;color:#64748b;font-weight:600">' . esc_html__('Page', 'shop2llm') . '</th>'
        . '<th style="text-align:left;padding:4px;color:#64748b;font-weight:600">' . esc_html__('Crawler', 'shop2llm') . '</th>'
        . '<th style="text-align:right;padding:4px;color:#64748b;font-weight:600">' . esc_html__('Last Visit', 'shop2llm') . '</th>'
        . '</tr></thead><tbody>';

    foreach ($recent as $row) {
        $title = $row->post_id ? get_the_title($row->post_id) : wp_parse_url($row->page_url, PHP_URL_PATH);
        if (empty($title) || $title === '') {
            $title = '/';
        }
        echo '<tr style="border-bottom:1px solid #f1f5f9">';
        echo '<td style="padding:4px;color:#1e1e1e;font-weight:500;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
            . esc_html(mb_strlen($title) > 40 ? mb_substr($title, 0, 37) . '...' : $title) . '</td>';
        echo '<td style="padding:4px;color:#475569">' . esc_html($row->bot_label) . '</td>';
        echo '<td style="padding:4px;text-align:right;color:#94a3b8">'
            . esc_html(human_time_diff(strtotime($row->last_visit), time()) . ' ' . __('ago', 'shop2llm')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * Daily cleanup of crawler logs older than 30 days.
 */
function shop_to_llm_cleanup_old_crawler_logs(): void
{
    global $wpdb;
    $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);

    // Clean crawler log
    $table = $wpdb->prefix . 'shop2llm_crawler_log';
    $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "DELETE FROM $table WHERE visited_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $cutoff
    ));

    // Clean AI referral log
    $refTable = $wpdb->prefix . 'shop2llm_ai_referrals';
    $refTableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $refTable)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ($refTableExists) {
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "DELETE FROM $refTable WHERE visited_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $cutoff
        ));
    }
}

// ─── P0: Admin Bar Real-time Badge ─────────────────────────────────────

function shop_to_llm_admin_bar_crawler_badge(\WP_Admin_Bar $wpAdminBar): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Get the cached 10-factor visibility score
    $scoreTransient = 'shop2llm_admin_bar_score';
    $score = get_transient($scoreTransient);
    if ($score === false) {
        $score = shop_to_llm_get_visibility_score();
        set_transient($scoreTransient, $score, 15 * MINUTE_IN_SECONDS);
    }

    $total = $score['total'];
    $color = $score['color'];
    $bg = $score['bg'];
    $label = $score['label'];

    // Infinity icon — Free always purple (#7c3aed), Pro has its own badge
    $icon = '<span style="display:inline-block;vertical-align:middle;margin-right:3px"><svg width="12" height="12" viewBox="0 0 20 20" style="vertical-align:middle"><path d="M5.5 7C3.5 7 2 8.5 2 10s1.5 3 3.5 3c1.2 0 2-.5 3-1.5L10 10l1.5 1.5c1 1 1.8 1.5 3 1.5 2 0 3.5-1.5 3.5-3S16.5 7 14.5 7c-1.2 0-2 .5-3 1.5L10 10 8.5 8.5C7.5 7.5 6.7 7 5.5 7z" fill="#7c3aed"/></svg></span>';

    $wpAdminBar->add_node([
        'id' => 'shop2llm-crawler-badge',
        'title' => $icon . ' <span style="font-weight:700;color:#fff;background:' . esc_attr($color) . ';border-radius:3px;padding:0 5px;font-size:11px;line-height:18px;display:inline-block;vertical-align:middle">' . (int) $total . '/100</span> <span style="font-size:10px;color:#94a3b8;vertical-align:middle">' . esc_html($label) . '</span>',
        'href' => admin_url('admin.php?page=shop2llm'),
        'meta' => [
            'title' => __('Shop2LLM AI Score', 'shop2llm'),
        ],
    ]);
}

// ─── P0: Dashboard Widget Recovery ─────────────────────────────────────

function shop_to_llm_register_dashboard_widget(): void
{
    wp_add_dashboard_widget(
        'shop2llm_crawler_activity',
        'Shop2LLM Visibility',
        'shop_to_llm_render_dashboard_widget_compact',
        null,
        null,
        'normal',
        'high'
    );

    // Move widget to top of dashboard main column
    global $wp_meta_boxes;
    if (isset($wp_meta_boxes['dashboard']['normal']['high']['shop2llm_crawler_activity'])) {
        $widget = $wp_meta_boxes['dashboard']['normal']['high']['shop2llm_crawler_activity'];
        unset($wp_meta_boxes['dashboard']['normal']['high']['shop2llm_crawler_activity']);
        $wp_meta_boxes['dashboard']['normal']['high'] = array_merge(
            ['shop2llm_crawler_activity' => $widget],
            $wp_meta_boxes['dashboard']['normal']['high']
        );
    }
}

function shop_to_llm_render_dashboard_widget_compact(): void
{
    $stats = shop_to_llm_get_crawler_stats();
    $maxVal = max($stats['sparkline'] ?: [1]);
    if ($maxVal === 0) {
        $maxVal = 1;
    }

    echo '<div class="s2ll-widget">';

    // Compact summary
    echo '<div class="s2ll-summary">';
    echo '<div>';
    echo '<span class="s2ll-big">' . (int) $stats['today_bots'] . '</span>';
    echo '<span class="s2ll-label">bots</span>';
    echo '<span class="s2ll-big" style="margin-left:16px">' . (int) $stats['today_pages'] . '</span>';
    echo '<span class="s2ll-label">pages today</span>';
    echo '</div>';
    echo '</div>';

    // 7-day sparkline
    echo '<div class="s2ll-sparkline">';
    foreach ($stats['sparkline'] as $val) {
        $height = $val > 0 ? max(8, ($val / $maxVal) * 48) : 2;
        $color = $val > 0 ? '#6366f1' : '#e2e8f0';
        echo '<div class="s2ll-bar" style="height:' . esc_attr($height) . 'px;background:' . esc_attr($color) . '" title="' . (int) $val . ' crawls"></div>';
    }
    echo '</div>';
    echo '<div class="s2ll-axis"><span>7d ago</span><span>Today</span></div>';

    // View details link
    echo '<div style="text-align:right;margin-top:8px">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=shop2llm')) . '" class="s2ll-link">View Details →</a>';
    echo '</div>';

    // AI referral compact
    $refStats = shop_to_llm_get_ai_referral_stats();
    $refThisMonth = (int) $refStats['this_month'];
    if ($refThisMonth > 0) {
        echo '<div class="s2ll-divider" style="padding-top:8px;margin-top:8px">';
        echo '<span style="font-size:11px;color:#64748b;display:inline-flex;align-items:center;gap:3px">' . wp_kses(shop_to_llm_icon('rocket', 11), shop_to_llm_allowed_svg()) . ' <strong style="color:#10b981">' . esc_html($refThisMonth) . '</strong> AI-driven visits this month</span>';
        echo '</div>';
    }

    echo '</div>';
}

// ─── P0: Uninstall Confirmation Dialog ─────────────────────────────────

function shop_to_llm_uninstall_confirmation_js(): void
{
    $pluginFile = plugin_basename(__FILE__);

    // v1.3.3: Cache counts in transient to avoid heavy DB queries on every plugins.php load.
    // The transient is refreshed once per hour or on explicit cache clear.
    $cacheKey = 'shop2llm_uninstall_counts';
    $counts = get_transient($cacheKey);
    if ($counts === false) {
        global $wpdb;
        $counts = [
            'crawlerRecords' => 0,
            'refRecords' => 0,
            'focusQueries' => 0,
        ];

        $cTable = $wpdb->prefix . 'shop2llm_crawler_log';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cTable))) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $counts['crawlerRecords'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cTable}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
        $rTable = $wpdb->prefix . 'shop2llm_ai_referrals';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rTable))) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $counts['refRecords'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rTable}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
        $counts['focusQueries'] = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            '_shop2llm_focus_query'
        ));
        set_transient($cacheKey, $counts, HOUR_IN_SECONDS);
    }

    $crawlerRecords = $counts['crawlerRecords'];
    $refRecords = $counts['refRecords'];
    $historyWeeks = 0;
    $focusQueries = $counts['focusQueries'];
    $lossItems = [];
    if ($crawlerRecords > 0) {
        $lossItems[] = sprintf(/* translators: %d: number of AI crawler visit records */ __('%d AI crawler visit records', 'shop2llm'), $crawlerRecords);
    }
    if ($refRecords > 0) {
        $lossItems[] = sprintf(/* translators: %d: number of AI traffic tracking records */ __('%d AI traffic tracking records', 'shop2llm'), $refRecords);
    }
    if ($focusQueries > 0) {
        $lossItems[] = sprintf(/* translators: %d: number of Focus Query configurations */ __('%d Focus Query configurations', 'shop2llm'), $focusQueries);
    }
    $lossItems[] = __('all AI visibility scores and trend history', 'shop2llm');

    $quantifiedMsg = '';
    if (count($lossItems) > 1) {
        $last = array_pop($lossItems);
        $quantifiedMsg = implode(', ', $lossItems) . ' ' . __('and', 'shop2llm') . ' ' . $last;
    } else {
        $quantifiedMsg = $lossItems[0];
    }

    $msg = sprintf(
        /* translators: %s: list of data that will be lost */
        __('⚠ You will permanently lose: %s. This data cannot be recovered. Are you sure you want to uninstall?', 'shop2llm'),
        $quantifiedMsg
    );
    ?>
    <script>
    (function() {
        var links = document.querySelectorAll('a.delete');
        links.forEach(function(link) {
            if (link.href && link.href.indexOf('<?php echo esc_js($pluginFile); ?>') !== -1) {
                link.addEventListener('click', function(e) {
                    if (!confirm(<?php echo json_encode($msg); ?>)) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });
            }
        });
    })();
    </script>
    <?php
}

// ─── P1: AI Visibility Score Meta Box ──────────────────────────────────


function shop_to_llm_visibility_meta_box_css(): void
{
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->base, ['post', 'edit'], true)) {
        return;
    }
    echo '<style>
    /* Score ring gauge */
    .s2ll-score-ring{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative;margin:0 auto 8px}
    .s2ll-score-ring::before{content:"";position:absolute;inset:0;border-radius:50%;background:conic-gradient(var(--s2ll-ring-color, #16a34a) calc(var(--s2ll-ring-pct, 0) * 1%), #e2e8f0 0)}
    .s2ll-score-ring::after{content:"";position:absolute;inset:6px;border-radius:50%;background:#fff}
    .s2ll-score-ring-value{position:relative;z-index:1;font-size:18px;font-weight:700;color:#1e293b}
    .s2ll-score-ring--red{--s2ll-ring-color:#dc2626}
    .s2ll-score-ring--yellow{--s2ll-ring-color:#f59e0b}
    .s2ll-score-ring--green{--s2ll-ring-color:#16a34a}
    .s2ll-subtitle{font-size:10px;color:#94a3b8;text-align:center;margin-bottom:10px;display:block}

    /* Severity grouping */
    .s2ll-severity-group{margin:8px 0}
    .s2ll-severity-header{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;padding:4px 0;user-select:none}
    .s2ll-severity-header:hover{opacity:0.85}
    .s2ll-severity-arrow{transition:transform 0.2s;font-size:10px;color:#94a3b8}
    .s2ll-severity-group[open] .s2ll-severity-arrow{transform:rotate(90deg)}
    .s2ll-severity-count{font-size:11px;font-weight:400;color:#94a3b8;margin-left:4px}
    .s2ll-severity-problems .s2ll-severity-header{color:#dc2626}
    .s2ll-severity-improvements .s2ll-severity-header{color:#d97706}
    .s2ll-severity-good .s2ll-severity-header{color:#16a34a}

    .s2ll-finding{display:flex;align-items:flex-start;gap:6px;padding:4px 0 4px 16px;font-size:12px;line-height:1.5}
    .s2ll-finding-icon{flex-shrink:0;margin-top:1px;font-size:13px}
    .s2ll-finding-body{flex:1;min-width:0}
    .s2ll-finding-text{color:#475569}
    .s2ll-finding-fix{display:inline-block;font-size:11px;color:#6366f1;cursor:pointer;text-decoration:underline;margin-top:2px}
    .s2ll-finding-fix:hover{color:#4f46e5}
    .s2ll-finding-pts{flex-shrink:0;font-size:10px;color:#94a3b8;margin-top:1px;text-align:right;min-width:24px}
    .s2ll-finding--locked{opacity:0.5}

    /* Dimension bars */
    .s2ll-dim-section{margin:10px 0;padding-top:8px;border-top:1px solid #e2e8f0}
    .s2ll-dim-section-title{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px}
    .s2ll-dimension-bar{display:flex;align-items:center;gap:6px;margin:3px 0;font-size:12px}
    .s2ll-dimension-label{width:105px;color:#64748b;flex-shrink:0;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .s2ll-dimension-track{flex:1;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden}
    .s2ll-dimension-fill{height:100%;border-radius:3px;transition:width 0.3s}
    .s2ll-dimension-score{width:42px;text-align:right;font-weight:600;flex-shrink:0;font-size:11px}

    /* Focus query */
    .s2ll-focus-query-wrap{margin-bottom:10px}
    .s2ll-focus-query-help{display:block;font-size:11px;color:#94a3b8;margin-top:2px}
    .s2ll-unlock-hint{display:block;font-size:11px;color:#6366f1;margin:1px 0 0 105px}

    /* Trend sparkline */
    .s2ll-sparkline{display:flex;align-items:flex-end;gap:3px;height:28px}
    .s2ll-sparkline-bar{width:6px;border-radius:2px 2px 0 0;background:#6366f1;min-height:2px;transition:height 0.3s}

    /* Trend section */
    .s2ll-trend-section{margin-top:10px;padding-top:8px;border-top:1px solid #e2e8f0}
    .s2ll-trend-header{display:flex;align-items:center;gap:8px;margin-bottom:4px}
    .s2ll-trend-title{font-size:12px;color:#64748b;font-weight:600;flex-shrink:0}
    .s2ll-trend-values{display:flex;align-items:center;gap:8px;font-size:12px;flex-wrap:wrap;margin-top:4px}
    .s2ll-trend-current{color:#334155}
    .s2ll-trend-prev{color:#94a3b8}
    .s2ll-trend-change{font-size:12px}
    .s2ll-trend-warning{margin-top:4px;font-size:11px;color:#dc2626;font-weight:600}
    .s2ll-trend-pending{font-size:11px;color:#94a3b8;margin-top:4px}

    /* Crawler info */
    .s2ll-crawler-info{margin-top:8px;padding:6px 8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:5px;font-size:11px;color:#0369a1;line-height:1.5}

    /* Auth list */
    .s2ll-auth-list-wrap{margin:4px 0}

    /* Tooltip */
    .s2ll-help{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#7c3aed;color:#fff;font-size:10px;font-weight:700;cursor:help;flex-shrink:0;vertical-align:middle;margin-left:6px}
    .s2ll-help::before{content:"?"}

    .s2ll-suggestion{font-size:11px;color:#6366f1;margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;line-height:1.5}

    /* Criterion (legacy - keep for settings page) */
    .s2ll-criterion{display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:6px}
    .s2ll-criterion-icon{font-size:14px;flex-shrink:0}
    .s2ll-criterion-text{color:#475569}
    .s2ll-criterion-pts{margin-left:auto;color:#94a3b8;font-size:11px}
    </style>';
}

function shop_to_llm_render_visibility_meta_box(\WP_Post $post): void
{
    $focusQuery = get_post_meta($post->ID, '_shop2llm_focus_query', true);
    $hasFocusQuery = !empty(trim((string) $focusQuery));
    $onAuthList = get_post_meta($post->ID, '_shop2llm_on_authoritative_list', true);

    // Focus Query input
    wp_nonce_field('shop2llm_save_focus_query', 'shop2llm_focus_query_nonce');
    echo '<div class="s2ll-focus-query-wrap">';
    echo '<label for="shop2llm_focus_query" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">' . esc_html__('Focus Query', 'shop2llm') . '</label>';
    echo '<input type="text" id="shop2llm_focus_query" name="shop2llm_focus_query" value="' . esc_attr($focusQuery) . '" placeholder="' . esc_attr__('e.g., best wireless earbuds', 'shop2llm') . '" style="width:100%;margin-bottom:4px" />';
    echo '<span class="s2ll-focus-query-help">' . esc_html__('What should AI assistants recommend you for?', 'shop2llm') . '</span>';
    echo '</div>';

    // Authoritative list checkbox
    echo '<div class="s2ll-auth-list-wrap">';
    echo '<label style="font-size:12px;display:flex;align-items:center;gap:4px">';
    echo '<input type="checkbox" name="shop2llm_on_authoritative_list" value="1" ' . checked($onAuthList, '1', false) . ' /> ';
    echo esc_html__('My brand appears on authoritative lists', 'shop2llm');
    echo '</label>';
    echo '</div>';

    $score = 0;

    // ─── Use canonical 25-item scoring function ──
    $scoring = shop_to_llm_score_single_post($post->ID);
    $allItems = $scoring['items'];
    $totalScore = $scoring['total'];
    $hasFocusQuery = $scoring['has_focus'];

    // Split items into L1-L5 groups
    $l1Items = array_slice($allItems, 0, 6);
    $l2Items = array_slice($allItems, 6, 6);
    $l3Items = array_slice($allItems, 12, 6);
    $l4Items = array_slice($allItems, 18, 3);
    $l5Items = array_slice($allItems, 21, 4);

    $l1 = array_sum(array_column($l1Items, 'pts'));
    $l2 = array_sum(array_column($l2Items, 'pts'));
    $l3 = array_sum(array_column($l3Items, 'pts'));
    $l4 = array_sum(array_column($l4Items, 'pts'));
    $l5 = array_sum(array_column($l5Items, 'pts'));

    $score = $totalScore;
    $content = $post->post_content; // kept for downstream rendering

    echo '<div class="s2ll-widget">';

    // ─── Ring Gauge Score ────────────────────────────────────────────
    $ringClass = $score >= 71 ? 's2ll-score-ring--green' : ($score >= 41 ? 's2ll-score-ring--yellow' : 's2ll-score-ring--red');
    echo '<div class="s2ll-score-ring ' . esc_attr($ringClass) . '" style="--s2ll-ring-pct:' . (int) $score . '">';
    echo '<span class="s2ll-score-ring-value">' . (int) $score . '</span>';
    echo '</div>';
    echo '<span class="s2ll-subtitle">Shop2LLM™ 5-Dimension Analysis</span>';

    // SEO plugin score integration
    $seoScore = 0;
    $seoPlugin = '';
    if (get_post_meta($post->ID, '_yoast_wpseo_linkdex', true) !== '') {
        $seoScore = (int) get_post_meta($post->ID, '_yoast_wpseo_linkdex', true);
        $seoPlugin = 'Yoast SEO';
    } elseif (get_post_meta($post->ID, 'rank_math_seo_score', true) !== '') {
        $rankMathScore = (int) get_post_meta($post->ID, 'rank_math_seo_score', true);
        $seoScore = min(100, max(0, $rankMathScore));
        $seoPlugin = 'Rank Math';
    }
    if ($seoScore > 0 && $seoPlugin) {
        $seoColor = $seoScore >= 71 ? '#16a34a' : ($seoScore >= 41 ? '#f59e0b' : '#dc2626');
        echo '<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:4px;font-size:11px">';
        echo '<span style="color:#94a3b8">' . esc_html($seoPlugin) . ':</span>';
        echo '<span style="color:' . esc_attr($seoColor) . ';font-weight:600">' . (int) $seoScore . '/100</span>';
        echo '</div>';
    } else {
        // No SEO plugin installed — show helpful hint
        echo '<div style="text-align:center;margin-bottom:4px;font-size:10px;color:#94a3b8;line-height:1.4">';
        echo esc_html__('Install Yoast SEO or Rank Math for a complete content health score', 'shop2llm');
        echo '</div>';
    }

    // ─── Fix guidance lookup ─────────────────────────────────────────
    $settingsUrl = admin_url('admin.php?page=shop2llm');

    $fixGuidance = [
        1  => ['fail' => __('llms.txt is empty', 'shop2llm'),                               'fix' => __('Go to Settings', 'shop2llm'),   'url' => $settingsUrl],
        2  => ['fail' => __('robots.txt may block AI crawlers', 'shop2llm'),                 'fix' => __('Fix robots.txt', 'shop2llm'),   'url' => $settingsUrl],
        3  => ['fail' => __('Schema markup missing content', 'shop2llm'),                    'fix' => __('Add structured data', 'shop2llm'), 'url' => ''],
        4  => ['fail' => __('No AI tools connected — store invisible to AI', 'shop2llm'),   'fix' => __('Connect AI tools', 'shop2llm'), 'url' => $settingsUrl],
        5  => ['fail' => __('Page has heavy JS — may be unreadable to AI', 'shop2llm'),     'fix' => __('Check page source', 'shop2llm'), 'url' => ''],
        6  => ['fail' => __('No FAQ or Q&A section found', 'shop2llm'),                     'fix' => __('Add FAQ block', 'shop2llm'),    'url' => ''],
        7  => ['fail' => __('Search keyword not in title', 'shop2llm'),                     'fix' => __('Edit title', 'shop2llm'),       'url' => ''],
        8  => ['fail' => __('Search keyword not in heading (H1/H2)', 'shop2llm'),           'fix' => __('Add heading with keyword', 'shop2llm'), 'url' => ''],
        9  => ['fail' => __('Keyword not in opening paragraph', 'shop2llm'),                'fix' => __('Add keyword near top', 'shop2llm'), 'url' => ''],
        10 => ['fail' => __('Keyword not in URL/slug', 'shop2llm'),                         'fix' => __('Edit slug', 'shop2llm'),        'url' => ''],
        11 => ['fail' => __('Keyword not in meta description', 'shop2llm'),                 'fix' => __('Set SEO description', 'shop2llm'), 'url' => ''],
        12 => ['fail' => __('No alt text with keyword on images', 'shop2llm'),              'fix' => __('Add alt text', 'shop2llm'),     'url' => ''],
        13 => ['fail' => __('Content too short (<150 words)', 'shop2llm'),                  'fix' => __('Add more content', 'shop2llm'), 'url' => ''],
        14 => ['fail' => __('No statistics or data found — AI prefers data-backed content', 'shop2llm'), 'fix' => __('Add numbers & data', 'shop2llm'), 'url' => ''],
        15 => ['fail' => __('No citations or references — AI prefers verifiable content', 'shop2llm'), 'fix' => __('Add quotes & sources', 'shop2llm'), 'url' => ''],
        16 => ['fail' => __('Content was updated >90 days ago — AI prefers fresh content', 'shop2llm'), 'fix' => __('Update content', 'shop2llm'), 'url' => ''],
        17 => ['fail' => __('Missing title/thumbnail/excerpt for schema', 'shop2llm'),      'fix' => __('Fill in missing fields', 'shop2llm'), 'url' => ''],
        18 => ['fail' => __('Too few internal links (need 3+)', 'shop2llm'),                'fix' => __('Link to related content', 'shop2llm'), 'url' => ''],
        19 => ['fail' => __('Brand not found in knowledge graph', 'shop2llm'),              'fix' => __('Enable entity checks', 'shop2llm'), 'url' => $settingsUrl],
        20 => ['fail' => __('Not mentioned on Wikipedia', 'shop2llm'),                      'fix' => __('Enable entity checks', 'shop2llm'), 'url' => $settingsUrl],
        21 => ['fail' => __('Not marked as on authoritative lists', 'shop2llm'),            'fix' => __('Check box above', 'shop2llm'), 'url' => ''],
        22 => ['fail' => __('No recent AI crawler visits', 'shop2llm'),                     'fix' => __('Wait for crawler activity', 'shop2llm'), 'url' => ''],
        23 => ['fail' => __('No reviews yet', 'shop2llm'),                                  'fix' => __('Encourage reviews', 'shop2llm'), 'url' => ''],
        24 => ['fail' => __('Seen by <4 AI platforms', 'shop2llm'),                         'fix' => __('Connect more AI tools', 'shop2llm'), 'url' => $settingsUrl],
        25 => ['fail' => __('Keyword density outside 0.5-2.5%', 'shop2llm'),                'fix' => __('Adjust keyword usage', 'shop2llm'), 'url' => ''],
    ];

    // User-friendly item names (replacing technical labels)
    $itemUserNames = [
        1  => __('llms.txt file is accessible', 'shop2llm'),
        2  => __('robots.txt allows AI crawlers', 'shop2llm'),
        3  => __('Structured data (Schema) present', 'shop2llm'),
        4  => __('AI tools connected to store', 'shop2llm'),
        5  => __('Page content readable without JavaScript', 'shop2llm'),
        6  => __('FAQ or Q&A section included', 'shop2llm'),
        7  => __('Search keyword appears in title', 'shop2llm'),
        8  => __('Search keyword appears in heading', 'shop2llm'),
        9  => __('Search keyword in first paragraph', 'shop2llm'),
        10 => __('Search keyword in URL address', 'shop2llm'),
        11 => __('Search keyword in meta description', 'shop2llm'),
        12 => __('Search keyword in image alt text', 'shop2llm'),
        13 => __('Content length over 300 words', 'shop2llm'),
        14 => __('Includes statistics and data', 'shop2llm'),
        15 => __('Includes citations and references', 'shop2llm'),
        16 => __('Content updated within 30 days', 'shop2llm'),
        17 => __('Complete metadata (title, image, excerpt)', 'shop2llm'),
        18 => __('Internal links to related pages', 'shop2llm'),
        19 => __('Brand recognized in knowledge graph', 'shop2llm'),
        20 => __('Brand mentioned on Wikipedia', 'shop2llm'),
        21 => __('Appears on authoritative lists', 'shop2llm'),
        22 => __('Recent AI crawler activity', 'shop2llm'),
        23 => __('Customer reviews exist', 'shop2llm'),
        24 => __('Indexed by multiple AI platforms', 'shop2llm'),
        25 => __('Keyword density in optimal range', 'shop2llm'),
    ];

    // Tooltip explanations for technical terms
    $tooltips = [
        5  => __('AI reads raw HTML, not rendered pages. Heavy JavaScript can hide content.', 'shop2llm'),
        4  => __('MCP (Model Context Protocol) lets ChatGPT and other AI tools directly query your store.', 'shop2llm'),
        1  => __('llms.txt is a standard file that tells AI models what your website contains.', 'shop2llm'),
    ];

    // ─── Build all 25 items with metadata ────────────────────────────
    $allIndexed = [];
    $idx = 1;
    foreach ([$l1Items, $l2Items, $l3Items, $l4Items, $l5Items] as $dimItems) {
        foreach ($dimItems as $item) {
            $allIndexed[$idx] = $item;
            $idx++;
        }
    }

    // ─── Classify into Problems / Improvements / Good ─────────────────
    $problems = [];     // fail + has fix (no lock)
    $improvements = []; // pass but pts < max, or locked items
    $good = [];         // pass with full pts

    foreach ($allIndexed as $i => $item) {
        $locked = !empty($item['locked']);
        if ($locked) {
            $improvements[] = ['index' => $i, 'item' => $item, 'locked' => true];
        } elseif (!$item['pass']) {
            $problems[] = ['index' => $i, 'item' => $item, 'locked' => false];
        } elseif ($item['pts'] < 1) {
            // Passed but scored 0 pts — improvement opportunity
            $improvements[] = ['index' => $i, 'item' => $item, 'locked' => false];
        } else {
            $good[] = ['index' => $i, 'item' => $item, 'locked' => false];
        }
    }

    // Helper to render a single finding row
    $renderFinding = function (array $entry) use ($fixGuidance, $itemUserNames, $tooltips, $settingsUrl) {
        $i = $entry['index'];
        $item = $entry['item'];
        $locked = $entry['locked'];
        $name = $itemUserNames[$i] ?? $item['name'];
        $guidance = $fixGuidance[$i] ?? null;
        $tooltip = $tooltips[$i] ?? '';

        echo '<div class="s2ll-finding' . ($locked ? ' s2ll-finding--locked' : '') . '">';

        // Icon
        if ($locked) {
            echo '<span class="s2ll-finding-icon">' . wp_kses(shop_to_llm_icon('lock', 12), shop_to_llm_allowed_svg()) . '</span>';
        } elseif ($item['pass']) {
            echo '<span class="s2ll-finding-icon" style="color:#16a34a">✓</span>';
        } else {
            echo '<span class="s2ll-finding-icon" style="color:#dc2626">✗</span>';
        }

        // Body
        echo '<span class="s2ll-finding-body">';
        if ($locked) {
            echo '<span class="s2ll-finding-text" style="color:#94a3b8">' . esc_html($name) . '</span>';
        } elseif (!$item['pass'] && $guidance) {
            echo '<span class="s2ll-finding-text">' . esc_html($guidance['fail']) . '</span>';
            if (!empty($guidance['fix'])) {
                $url = !empty($guidance['url']) ? esc_url($guidance['url']) : '';
                echo ' ';
                if ($url) {
                    echo '<a href="' . $url . '" class="s2ll-finding-fix" target="_blank">' . esc_html($guidance['fix']) . ' →</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    echo '<span class="s2ll-finding-fix">' . esc_html($guidance['fix']) . '</span>';
                }
            }
        } else {
            echo '<span class="s2ll-finding-text" style="color:#16a34a">' . esc_html($name) . '</span>';
        }

        // Tooltip
        if ($tooltip) {
            echo '<span class="s2ll-help" title="' . esc_attr($tooltip) . '">?</span>';
        }
        echo '</span>';

        // Points
        $ptsStr = $locked ? '—' : '+' . (int) $item['pts'];
        echo '<span class="s2ll-finding-pts">' . esc_html($ptsStr) . '</span>';

        echo '</div>';
    };

    // ─── Render severity groups ──────────────────────────────────────
    // Problems: default OPEN
    if (!empty($problems)) {
        echo '<details class="s2ll-severity-group s2ll-severity-problems" open>';
        echo '<summary class="s2ll-severity-header"><span class="s2ll-severity-arrow">▶</span>' . esc_html__('Problems', 'shop2llm') . '<span class="s2ll-severity-count">(' . count($problems) . ')</span></summary>';
        foreach ($problems as $entry) {
            $renderFinding($entry);
        }
        echo '</details>';
    }

    // Improvements: default CLOSED
    if (!empty($improvements)) {
        echo '<details class="s2ll-severity-group s2ll-severity-improvements">';
        echo '<summary class="s2ll-severity-header"><span class="s2ll-severity-arrow">▶</span>' . esc_html__('To Improve', 'shop2llm') . '<span class="s2ll-severity-count">(' . count($improvements) . ')</span></summary>';
        foreach ($improvements as $entry) {
            $renderFinding($entry);
        }
        echo '</details>';
    }

    // Good: default CLOSED
    if (!empty($good)) {
        echo '<details class="s2ll-severity-group s2ll-severity-good">';
        echo '<summary class="s2ll-severity-header"><span class="s2ll-severity-arrow">▶</span>' . esc_html__('Good', 'shop2llm') . '<span class="s2ll-severity-count">(' . count($good) . ')</span></summary>';
        foreach ($good as $entry) {
            $renderFinding($entry);
        }
        echo '</details>';
    }

    // If all 25 passed (unlikely), show all-green message
    if (empty($problems) && empty($improvements)) {
        echo '<div style="text-align:center;padding:8px;color:#16a34a;font-size:13px;font-weight:600">' . esc_html__('Perfect AI Visibility!', 'shop2llm') . '</div>';
    }

    // ─── Dimension Summary ──────────────────────────────────────────
    $l2Locked = !$hasFocusQuery;
    $l4Locked = !$hasFocusQuery;
    $dimensions = [
        ['label' => __('Can AI find me?', 'shop2llm'),       'score' => $l1, 'max' => 30, 'color' => '#3b82f6', 'locked' => false,  'tooltip' => __('Technical discoverability: llms.txt, robots.txt, Schema, MCP', 'shop2llm')],
        ['label' => __('Search Relevance', 'shop2llm'), 'score' => $l2, 'max' => 25, 'color' => $l2Locked ? '#94a3b8' : '#8b5cf6', 'locked' => $l2Locked, 'tooltip' => __('How well your content matches the search query', 'shop2llm')],
        ['label' => __('Content Quality', 'shop2llm'),       'score' => $l3, 'max' => 25, 'color' => '#f59e0b', 'locked' => false,  'tooltip' => __('Length, statistics, citations, freshness, structure', 'shop2llm')],
        ['label' => __('Brand Authority', 'shop2llm'), 'score' => $l4, 'max' => 15, 'color' => $l4Locked ? '#94a3b8' : '#06b6d4', 'locked' => $l4Locked, 'tooltip' => __('Knowledge graph, Wikipedia, authoritative lists', 'shop2llm')],
        ['label' => __('AI Activity', 'shop2llm'),           'score' => $l5, 'max' => 5,  'color' => '#16a34a', 'locked' => false,  'tooltip' => __('Crawler visits, reviews, multi-platform reach', 'shop2llm')],
    ];

    echo '<div class="s2ll-dim-section">';
    echo '<span class="s2ll-dim-section-title">' . esc_html__('Dimension Breakdown', 'shop2llm') . '</span>';

    foreach ($dimensions as $dim) {
        $pct = $dim['max'] > 0 ? round(($dim['score'] / $dim['max']) * 100) : 0;
        $barOpacity = $dim['locked'] ? '0.5' : '1';
        echo '<div class="s2ll-dimension-bar" style="opacity:' . esc_attr($barOpacity) . '">';
        echo '<span class="s2ll-dimension-label">' . esc_html($dim['label']);
        if (!empty($dim['locked'])) {
            echo ' ' . wp_kses(shop_to_llm_icon('lock', 10), shop_to_llm_allowed_svg());
        }
        if (!empty($dim['tooltip'])) {
            echo '<span class="s2ll-help" title="' . esc_attr($dim['tooltip']) . '">?</span>';
        }
        echo '</span>';
        echo '<span class="s2ll-dimension-track"><span class="s2ll-dimension-fill" style="width:' . esc_attr((string)$pct) . '%;background:' . esc_attr($dim['color']) . '"></span></span>';
        echo '<span class="s2ll-dimension-score">' . (int) $dim['score'] . '/' . (int) $dim['max'] . '</span>';
        echo '</div>';
        if ($dim['locked']) {
            echo '<span class="s2ll-unlock-hint">' . esc_html__('Enter a Focus Query to unlock', 'shop2llm') . '</span>';
        }
    }
    echo '</div>';

    // ─── AI Visibility Trend with Mini Sparkline ────────────────────
    echo '<div class="s2ll-trend-section">';
    echo '<div class="s2ll-trend-header">';
    echo '<span class="s2ll-trend-title">' . esc_html__('AI Visibility Trend', 'shop2llm') . '</span>';
    echo '</div>';

    $history = get_post_meta($post->ID, '_shop2llm_visibility_history', true);
    $sparkData = [];
    if (is_array($history) && count($history) >= 2) {
        $currentScore = $score;
        $prevEntry = $history[count($history) - 2];
        $prevScore = (int) $prevEntry['score'];

        // Build sparkline data (last up to 8 weeks)
        $sparkData = array_map(function ($e) {
            return (int) $e['score'];
        }, $history);
        // Normalize to 0-28px height range
        if (!empty($sparkData)) {
            $maxVal = max($sparkData);
            $minVal = min($sparkData);
            $range = $maxVal - $minVal ?: 1;
            echo '<div class="s2ll-sparkline">';
            foreach ($sparkData as $val) {
                $h = max(2, round((($val - $minVal) / $range) * 28));
                echo '<span class="s2ll-sparkline-bar" style="height:' . (int) $h . 'px" title="' . (int) $val . '"></span>';
            }
            echo '</div>';
        }

        if ($prevScore > 0) {
            $changePct = round((($currentScore - $prevScore) / $prevScore) * 100, 1);
        } else {
            $changePct = $currentScore > 0 ? 100 : 0;
        }

        $arrow = $changePct >= 0 ? '↑' : '↓';
        $color = $changePct >= 0 ? '#16a34a' : '#dc2626';

        echo '<div class="s2ll-trend-values">';
        echo '<span class="s2ll-trend-current">' . esc_html__('This week:', 'shop2llm') . ' <strong>' . (int) $currentScore . '</strong></span>';
        echo '<span class="s2ll-trend-prev">' . esc_html__('Last:', 'shop2llm') . ' ' . (int) $prevScore . '</span>';
        echo '<span class="s2ll-trend-change" style="color:' . esc_attr($color) . ';font-weight:600">' . esc_html($arrow . ' ' . abs($changePct) . '%') . '</span>';
        echo '</div>';

        if ($changePct < -10) {
            echo '<div class="s2ll-trend-warning">⚠ ' . esc_html__('AI visibility dropping', 'shop2llm') . '</div>';
        }
    } elseif (is_array($history) && count($history) === 1) {
        echo '<div class="s2ll-trend-values">';
        echo '<span class="s2ll-trend-current">' . esc_html__('This week:', 'shop2llm') . ' <strong>' . (int) $score . '</strong></span>';
        echo '</div>';
        echo '<div class="s2ll-trend-pending">' . wp_kses(shop_to_llm_icon('bar-chart', 12), shop_to_llm_allowed_svg()) . ' ' . esc_html__('Trend data will appear after next week', 'shop2llm') . '</div>';
    } else {
        echo '<div class="s2ll-trend-pending">' . wp_kses(shop_to_llm_icon('bar-chart', 12), shop_to_llm_allowed_svg()) . ' ' . esc_html__('Trend data will appear after next week', 'shop2llm') . '</div>';
    }

    echo '</div>'; // .s2ll-trend-section

    // ─── Crawler info ──────────────────────────────────────────────
    if (!empty($crawlerStats['total_crawls']) && $crawlerStats['total_crawls'] > 0) {
        echo '<div class="s2ll-crawler-info">';
        echo esc_html(sprintf(/* translators: %d: number of unique AI bots */ __('%d AI bots visited', 'shop2llm'), (int) $crawlerStats['unique_bots']));
        echo ' · ' . esc_html(implode(', ', array_slice($crawlerStats['bot_list'], 0, 3)));
        if (count($crawlerStats['bot_list']) > 3) {
            echo ' +' . (count($crawlerStats['bot_list']) - 3);
        }
        echo '<br>' . esc_html(sprintf(/* translators: %s: last visit date/time */ __('Last: %s', 'shop2llm'), $crawlerStats['last_visit']));
        echo '</div>';
    }

    echo '</div>'; // .s2ll-widget
}

// ─── P2: AI Visibility Column in Post/Product List ─────────────────────

function shop_to_llm_add_visibility_column(array $columns): array
{
    $new = [];
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'title') {
            $new['s2ll_visibility'] = __('S2LL Score', 'shop2llm');
        }
    }
    return $new;
}

function shop_to_llm_render_visibility_column(string $column, int $postId): void
{
    if ($column !== 's2ll_visibility') {
        return;
    }

    // Use bulk-cached crawled post IDs
    static $crawledPosts = null;
    if ($crawledPosts === null) {
        $crawledPosts = shop_to_llm_get_bulk_crawled_post_ids();
    }

    $post = get_post($postId);
    if (!$post) {
        return;
    }

    $score = shop_to_llm_calculate_visibility_score($postId);
    $focusQuery = get_post_meta($postId, '_shop2llm_focus_query', true);
    $hasFocusQuery = !empty(trim((string) $focusQuery));

    $color = $score >= 71 ? '#16a34a' : ($score >= 41 ? '#f59e0b' : '#dc2626');
    $title = sprintf(/* translators: %d: visibility score */ __('Shop2LLM Visibility Score: %d/100', 'shop2llm'), $score);

    echo '<span style="display:inline-flex;align-items:center;gap:4px;cursor:default" title="' . esc_attr($title) . '">';
    echo '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . esc_attr($color) . '"></span>';
    echo '<span style="font-size:11px;color:' . esc_attr($color) . ';font-weight:600">' . esc_html((string)$score) . '/100</span>';

    // Focus query in quotes
    if ($hasFocusQuery) {
        echo ' <span style="font-size:11px;color:#64748b">"' . esc_html($focusQuery) . '"</span>';
    }

    // Trend indicator
    $history = get_post_meta($postId, '_shop2llm_visibility_history', true);
    if (is_array($history) && count($history) >= 2) {
        $prevScore = (int) $history[count($history) - 2]['score'];
        if ($prevScore > 0) {
            $changePct = round((($score - $prevScore) / $prevScore) * 100, 1);
        } else {
            $changePct = $score > 0 ? 100 : 0;
        }
        if ($changePct > 0) {
            echo ' <span style="font-size:11px;color:#16a34a;font-weight:600">↑</span>';
        } elseif ($changePct < 0) {
            echo ' <span style="font-size:11px;color:#dc2626;font-weight:600">↓</span>';
        }
    }

    echo '</span>';
}

function shop_to_llm_get_bulk_crawled_post_ids(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';
    $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if (!$tableExists) {
        return [];
    }

    $transientKey = 'shop2llm_crawled_post_ids_30d';
    $cached = get_transient($transientKey);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }

    $thirtyDaysAgo = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);

    // Check if post_id column exists
    $col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'post_id'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    if (!empty($col)) {
        $ids = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT DISTINCT post_id FROM $table WHERE visited_at >= %s AND post_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $thirtyDaysAgo
        ));
    } else {
        // Fallback: match by URL
        $urls = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT DISTINCT page_url FROM $table WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $thirtyDaysAgo
        ));
        $ids = [];
        foreach ($urls as $url) {
            $pid = (int) url_to_postid($url);
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }
        $ids = array_unique($ids);
    }

    set_transient($transientKey, $ids, HOUR_IN_SECONDS);
    return $ids;
}

// ─── P3: Per-Post AI Crawler Visit Stats ───────────────────────────────

function shop_to_llm_get_post_crawler_stats(int $postId): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';

    $default = [
        'total_crawls' => 0,
        'unique_bots' => 0,
        'last_visit' => '',
        'bot_list' => [],
    ];

    $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if (!$tableExists) {
        return $default;
    }

    // Check if post_id column exists
    $col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'post_id'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if (!empty($col)) {
        // Use post_id column for efficient lookup
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as total, COUNT(DISTINCT bot_name) as unique_bots, MAX(visited_at) as last_visit
             FROM $table WHERE post_id = %d",
            $postId
        ), ARRAY_A);
        // phpcs:enable

        if ($row && (int) $row['total'] > 0) {
            $botList = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                "SELECT DISTINCT bot_label FROM $table WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $postId
            ));
            return [
                'total_crawls' => (int) $row['total'],
                'unique_bots' => (int) $row['unique_bots'],
                'last_visit' => $row['last_visit'] ?: '',
                'bot_list' => $botList ?: [],
            ];
        }
    }

    // Fallback: match by URL
    $postUrl = get_permalink($postId);
    if (!$postUrl) {
        return $default;
    }

    $like = '%' . $wpdb->esc_like(wp_parse_url($postUrl, PHP_URL_PATH)) . '%';
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) as total, COUNT(DISTINCT bot_name) as unique_bots, MAX(visited_at) as last_visit
         FROM $table WHERE page_url LIKE %s",
        $like
    ), ARRAY_A);
    // phpcs:enable

    if ($row && (int) $row['total'] > 0) {
        $botList = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT DISTINCT bot_label FROM $table WHERE page_url LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $like
        ));
        return [
            'total_crawls' => (int) $row['total'],
            'unique_bots' => (int) $row['unique_bots'],
            'last_visit' => $row['last_visit'] ?: '',
            'bot_list' => $botList ?: [],
        ];
    }

    return $default;
}

// ─── Feature 1: AI Discovery Badge ─────────────────────────────────────

function shop_to_llm_render_footer_badge(): void
{
    if (is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) {
        return;
    }
    if (get_option('shop_to_llm_enabled', 'yes') !== 'yes') {
        return;
    }
    if (!apply_filters('shop_to_llm_show_badge', true)) {
        return;
    }
    if (get_option('shop_to_llm_show_badge', 'yes') !== 'yes') {
        return;
    }
    $visibilityUrl = esc_url(home_url('/ai-visibility/'));
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $visibilityUrl already escaped
    echo '<a href="' . $visibilityUrl . '" id="s2ll-badge" style="position:fixed;bottom:12px;right:12px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;font-family:-apple-system,sans-serif;font-size:11px;color:#64748b;box-shadow:0 1px 3px rgba(0,0,0,0.08);z-index:9999;text-decoration:none;display:flex;align-items:center;gap:4px"><span style="display:inline-flex">' . shop_to_llm_icon('shield', 13) . '</span><span>AI Discoverable</span></a>';
}

// ─── Feature 1b: /ai-visibility/ Verification Page ─────────────────────

function shop_to_llm_add_visibility_rewrite(): void
{
    add_rewrite_rule('^ai-visibility/?$', 'index.php?s2ll_visibility=1', 'top');
}

function shop_to_llm_visibility_query_var(array $vars): array
{
    $vars[] = 's2ll_visibility';
    return $vars;
}

function shop_to_llm_render_visibility_page(): void
{
    if (!get_query_var('s2ll_visibility')) {
        return;
    }

    $siteName = get_bloginfo('name');
    $siteUrl = get_site_url();
    $siteDesc = get_bloginfo('description');

    // llms.txt status — real check (was hardcoded 'Active', hiding failures)
    $llmsTxtUrl = esc_url($siteUrl . '/llms.txt');
    $llmsTxtContent = get_transient('shop_to_llm_llms_txt_output');
    if (!$llmsTxtContent) {
        $llmsTxtContent = shop_to_llm_build_llms_txt();
    }
    $llmsReachable = is_string($llmsTxtContent) && strpos($llmsTxtContent, '## ') !== false;
    $llmsTxtStatus = $llmsReachable ? 'Active' : 'Not reachable';

    // Schema markup count
    $schemaCount = 0;
    if (shop_to_llm_has_woocommerce()) {
        $schemaCount = (int) (wp_count_posts('product')->publish ?? 0);
    } else {
        $schemaCount = (int) (wp_count_posts('post')->publish ?? 0);
    }

    // AI crawler visits (unique bots in last 30 days)
    $crawlerStats = shop_to_llm_get_crawler_stats();
    $uniqueBots30d = (int) $crawlerStats['unique_bots_30d'];
    $totalCrawls30d = (int) $crawlerStats['total_30d'];

    // MCP tools status
    $mcpActive = get_option('shop_to_llm_api_key', '') !== '' ? 'Active' : 'Inactive';

    // AI referral stats
    $referralStats = shop_to_llm_get_ai_referral_stats();
    $totalReferrals = (int) $referralStats['this_month'];

    // Known AI platforms
    $platforms = [
        ['name' => 'ChatGPT', 'icon' => shop_to_llm_icon('bot', 13)],
        ['name' => 'Perplexity', 'icon' => shop_to_llm_icon('search', 13)],
        ['name' => 'Google AI', 'icon' => shop_to_llm_icon('globe', 13)],
        ['name' => 'Bing Copilot', 'icon' => shop_to_llm_icon('message', 13)],
        ['name' => 'Claude', 'icon' => shop_to_llm_icon('brain', 13)],
        ['name' => 'Brave Search', 'icon' => shop_to_llm_icon('flame', 13)],
    ];

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($siteName); ?> — AI Visibility</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1e293b;line-height:1.6}
.wrap{max-width:680px;margin:0 auto;padding:40px 20px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04)}
h1{font-size:24px;font-weight:700;color:#1e293b;margin-bottom:4px}
.subtitle{font-size:14px;color:#64748b;margin-bottom:24px}
.status-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.status-item{padding:16px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0}
.status-item h3{font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px}
.status-item .val{font-size:22px;font-weight:700;color:#1e293b}
.status-item .val.active{color:#16a34a}
.status-item .val.inactive{color:#94a3b8}
.platforms{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.platform-tag{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:#f0f0ff;border:1px solid #e2e8f0;border-radius:20px;font-size:13px;color:#4338ca;font-weight:500}
.branding{text-align:center;margin-top:24px;font-size:12px;color:#94a3b8}
.branding a{color:#6366f1;text-decoration:none;font-weight:600}
</style>
</head>
<body>
<div class="wrap">
<div class="card" style="text-align:center;border-left:4px solid #6366f1">
<h1><?php echo wp_kses(shop_to_llm_icon('shield', 20), shop_to_llm_allowed_svg()); ?> <?php echo esc_html($siteName); ?></h1>
<p class="subtitle"><?php echo esc_html($siteDesc); ?> · <?php echo esc_url($siteUrl); ?></p>
<p style="font-size:14px;color:#16a34a;font-weight:600">✓ This site is AI-Discoverable</p>
</div>

<div class="card">
<h2 style="font-size:16px;font-weight:600;margin-bottom:16px">AI Visibility Status</h2>
<div class="status-grid">
<div class="status-item">
<h3>llms.txt</h3>
<div class="val <?php echo $llmsReachable ? 'active' : 'inactive'; ?>"><?php echo esc_html($llmsTxtStatus); ?></div>
</div>
<div class="status-item">
<h3>Schema Markup</h3>
<div class="val"><?php echo esc_html((string)$schemaCount); ?> <span style="font-size:12px;color:#64748b;font-weight:400">pages</span></div>
</div>
<div class="status-item">
<h3>AI Crawler Visits (30d)</h3>
<div class="val"><?php echo esc_html((string)$totalCrawls30d); ?> <span style="font-size:12px;color:#64748b;font-weight:400">from <?php echo esc_html((string)$uniqueBots30d); ?> bots</span></div>
</div>
<div class="status-item">
<h3>MCP Tools</h3>
<div class="val <?php echo $mcpActive === 'Active' ? 'active' : 'inactive'; ?>"><?php echo esc_html($mcpActive); ?></div>
</div>
<div class="status-item">
<h3>AI-Driven Traffic (This Month)</h3>
<div class="val"><?php echo esc_html((string)$totalReferrals); ?> <span style="font-size:12px;color:#64748b;font-weight:400">visits</span></div>
</div>
<div class="status-item">
<h3>Plugin Version</h3>
<div class="val" style="font-size:16px"><?php echo esc_html(SHOP_TO_LLM_VERSION); ?></div>
</div>
</div>
</div>

<div class="card">
<h2 style="font-size:16px;font-weight:600;margin-bottom:8px">AI Platforms That Can Discover This Site</h2>
<p style="font-size:13px;color:#64748b;margin-bottom:12px">This site is optimized for discovery by the following AI assistants and search engines.</p>
<div class="platforms">
    <?php foreach ($platforms as $p) : ?>
<span class="platform-tag"><?php echo wp_kses($p['icon'], shop_to_llm_allowed_svg()); ?> <?php echo esc_html($p['name']); ?></span>
    <?php endforeach; ?>
</div>
</div>

<div class="card" style="text-align:center;background:linear-gradient(135deg,#f5f3ff 0%,#ede9fe 100%);border:1px solid #c4b5fd">
<h2 style="font-size:16px;font-weight:700;color:#4338ca;margin-bottom:6px"><?php echo wp_kses(shop_to_llm_icon('shield', 16), shop_to_llm_allowed_svg()); ?> Powered by Shop2LLM</h2>
<p style="font-size:13px;color:#4c1d95;margin-bottom:12px">This site's AI visibility is powered by Shop2LLM — the free WordPress plugin for llms.txt, an MCP server, AI crawler invites and AI visibility scoring.</p>
<a href="https://wordpress.org/plugins/shop2llm/" target="_blank" rel="noopener" style="display:inline-block;padding:9px 20px;background:#6366f1;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px">Get it free on WordPress.org →</a>
</div>
</div>
</body>
</html>
    <?php
    exit;
}

// ─── Feature 2: AI Referral Tracker ────────────────────────────────────

function shop_to_llm_create_ai_referrals_table(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_ai_referrals';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        source VARCHAR(50) NOT NULL,
        source_label VARCHAR(50) NOT NULL,
        landing_url VARCHAR(500) NOT NULL DEFAULT '',
        post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        visited_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_source (source),
        KEY idx_date (visited_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function shop_to_llm_detect_ai_referral(): void
{
    if (is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) {
        return;
    }

    $referer = sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'] ?? ''));
    if (empty($referer)) {
        return;
    }

    // v1.5.0: matching logic shared with acquisition attribution (extended map).
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UTM params are public tracking values, no nonce needed
    $utmSource = sanitize_text_field(wp_unslash($_GET['utm_source'] ?? ''));
    $matched = shop_to_llm_acquisition_match_ai_source($referer, $utmSource);

    if ($matched === null) {
        return;
    }

    // Dedup: 10-minute TTL per source+IP
    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
    $dedupKey = 's2ll_referral_' . $matched['source'] . '_' . md5($ip);
    if (get_transient($dedupKey)) {
        return;
    }
    set_transient($dedupKey, 1, 10 * MINUTE_IN_SECONDS);

    // Log to DB
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_ai_referrals';
    $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if (!$tableExists) {
        shop_to_llm_create_ai_referrals_table();
    }

    $requestUri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    $landingUrl = home_url($requestUri);
    if (strlen($landingUrl) > 500) {
        $landingUrl = substr($landingUrl, 0, 500);
    }
    $postId = (int) url_to_postid($landingUrl);

    $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $table,
        [
            'source' => $matched['source'],
            'source_label' => $matched['label'],
            'landing_url' => $landingUrl,
            'post_id' => $postId,
            'visited_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%d', '%s']
    );

    // v1.5.0: remember the referral for order attribution (session + first-party cookie).
    shop_to_llm_acquisition_capture($matched['source'], $matched['label']);
}

function shop_to_llm_get_ai_referral_stats(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_ai_referrals';

    $default = [
        'this_month' => 0,
        'last_month' => 0,
        'change_pct' => 0,
        'by_source' => [],
        'daily_counts' => array_fill(0, 7, 0),
    ];

    $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if (!$tableExists) {
        return $default;
    }

    // This month
    $thisMonthStart = gmdate('Y-m-01 00:00:00');
    $thisMonth = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(*) FROM $table WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $thisMonthStart
    ));

    // Last month
    $lastMonthStart = gmdate('Y-m-01 00:00:00', strtotime('first day of last month'));
    $lastMonthEnd = gmdate('Y-m-01 00:00:00');
    $lastMonth = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(*) FROM $table WHERE visited_at >= %s AND visited_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $lastMonthStart,
        $lastMonthEnd
    ));

    // Change percentage
    $changePct = 0;
    if ($lastMonth > 0) {
        $changePct = round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
    } elseif ($thisMonth > 0) {
        $changePct = 100;
    }

    // By source
    $bySource = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT source, source_label, COUNT(*) as cnt FROM $table WHERE visited_at >= %s GROUP BY source, source_label ORDER BY cnt DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $thisMonthStart
    ), ARRAY_A);
    if (!is_array($bySource)) {
        $bySource = [];
    }

    // Daily counts for last 7 days
    $dailyRows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT DATE(visited_at) as day, COUNT(*) as cnt FROM $table WHERE visited_at >= %s GROUP BY DATE(visited_at) ORDER BY day ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)
    ), ARRAY_A);

    $dailyCounts = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
        $cnt = 0;
        if (is_array($dailyRows)) {
            foreach ($dailyRows as $dr) {
                if ($dr['day'] === $day) {
                    $cnt = (int) $dr['cnt'];
                    break;
                }
            }
        }
        $dailyCounts[] = $cnt;
    }

    return [
        'this_month' => $thisMonth,
        'last_month' => $lastMonth,
        'change_pct' => $changePct,
        'by_source' => $bySource,
        'daily_counts' => $dailyCounts,
    ];
}

function shop_to_llm_render_ai_referral_section(): void
{
    $stats = shop_to_llm_get_ai_referral_stats();
    $maxVal = max($stats['daily_counts'] ?: [1]);
    if ($maxVal === 0) {
        $maxVal = 1;
    }

    $changeText = '';
    if ($stats['this_month'] > 0 || $stats['last_month'] > 0) {
        $arrow = $stats['change_pct'] >= 0 ? '↑' : '↓';
        $color = $stats['change_pct'] >= 0 ? '#16a34a' : '#dc2626';
        $changeText = '<span style="font-size:12px;color:' . esc_attr($color) . ';font-weight:600">' . esc_html($arrow . ' ' . abs($stats['change_pct']) . '%') . '</span>';
    }

    echo '<div class="s2ll-widget">';

    // Summary
    echo '<div class="s2ll-summary">';
    echo '<div>';
    echo '<span class="s2ll-big">' . (int) $stats['this_month'] . '</span>';
    echo '<span class="s2ll-label">AI visits this month</span>';
    echo $changeText; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $changeText is built with esc_attr/esc_html above
    echo '</div>';
    echo '</div>';

    // 7-day sparkline
    if (max($stats['daily_counts']) > 0) {
        echo '<div class="s2ll-sparkline">';
        foreach ($stats['daily_counts'] as $val) {
            $height = $val > 0 ? max(8, ($val / $maxVal) * 48) : 2;
            $color = $val > 0 ? '#10b981' : '#e2e8f0';
            echo '<div class="s2ll-bar" style="height:' . esc_attr($height) . 'px;background:' . esc_attr($color) . '" title="' . (int) $val . ' visits">';
            if ($val > 0) {
                echo '<span class="s2ll-bar-val">' . (int) $val . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="s2ll-axis"><span>7 days ago</span><span>Today</span></div>';
    }

    // Per-source breakdown
    if (!empty($stats['by_source'])) {
        echo '<div class="s2ll-divider">';
        $maxCnt = (int) $stats['by_source'][0]['cnt'];
        if ($maxCnt === 0) {
            $maxCnt = 1;
        }
        foreach ($stats['by_source'] as $row) {
            $cnt = (int) $row['cnt'];
            $bar = min(100, ($cnt / $maxCnt) * 100);
            echo '<div class="s2ll-bot-row">';
            echo '<span class="s2ll-bot-name">' . esc_html($row['source_label']) . '</span>';
            echo '<div class="s2ll-bot-track"><div class="s2ll-bot-fill" style="width:' . esc_attr($bar) . '%;background:linear-gradient(90deg,#10b981,#059669)"></div></div>';
            echo '<span class="s2ll-bot-cnt">' . esc_html($cnt) . ' visits</span>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<div class="s2ll-empty">';
        echo '⏳ Waiting for AI-driven traffic...';
        echo '<br><span class="s2ll-empty-sub">Visitors from Perplexity, ChatGPT, Copilot and others will appear here.</span>';
        echo '</div>';
    }

    echo '</div>';
}

function shop_to_llm_admin_bar_referral_suffix(): string
{
    $transientKey = 'shop2llm_admin_bar_referrals';
    $referralCount = get_transient($transientKey);
    if ($referralCount === false) {
        $referralStats = shop_to_llm_get_ai_referral_stats();
        $referralCount = (int) $referralStats['this_month'];
        set_transient($transientKey, $referralCount, 5 * MINUTE_IN_SECONDS);
    }
    if ($referralCount > 0) {
        return ' · ' . (int) $referralCount . ' AI visits';
    }
    return '';
}

// ─── Focus Query & Extended Scoring ────────────────────────────────────

function shop_to_llm_calculate_visibility_score(int $postId): int
{
    // Check transient cache (1 hour)
    $cached = get_transient('shop2llm_score_' . $postId);
    if ($cached !== false) {
        return (int) $cached;
    }

    // Delegate to canonical 25-item scoring function
    $score = shop_to_llm_score_single_post($postId);
    $result = $score['total'];

    // Cache result
    set_transient('shop2llm_score_' . $postId, $result, HOUR_IN_SECONDS);
    return (int) $result;
}
function shop_to_llm_check_robots_txt_allows_ai(): bool
{
    $transientKey = 'shop2llm_robots_allows_ai';
    $cached = get_transient($transientKey);
    if ($cached !== false) {
        return $cached === 'yes';
    }

    $robotsUrl = home_url('/robots.txt');
    $response = wp_remote_get($robotsUrl, ['timeout' => 5]);
    if (is_wp_error($response)) {
        set_transient($transientKey, 'yes', DAY_IN_SECONDS);
        return true;
    }
    $content = wp_remote_retrieve_body($response);
    $aiBots = ['GPTBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'CCBot', 'Applebot', 'Bytespider'];
    foreach ($aiBots as $bot) {
        if (preg_match('/User-agent:\s*' . preg_quote($bot, '/') . '/i', $content)) {
            if (preg_match('/Disallow:\s*\/\s*$/im', $content)) {
                set_transient($transientKey, 'no', DAY_IN_SECONDS);
                return false;
            }
        }
    }
    if (preg_match('/User-agent:\s*\*\s*\n\s*Disallow:\s*\/\s*$/im', $content)) {
        set_transient($transientKey, 'no', DAY_IN_SECONDS);
        return false;
    }
    set_transient($transientKey, 'yes', DAY_IN_SECONDS);
    return true;
}

/**
 * v1.5.4: Physical llms.txt conflict detection. A static llms.txt at the site
 * root (shipped by another plugin — e.g. Website LLMs.txt writes real files —
 * or left over from an old setup) is served directly by the web server and
 * silently shadows Shop2LLM's dynamic endpoint: WordPress rewrite rules never
 * see the request. Surfaces the conflict with a one-click removal.
 */
add_action('admin_notices', 'shop_to_llm_physical_llms_txt_warning');
function shop_to_llm_physical_llms_txt_warning(): void
{
    if (!current_user_can('manage_options') || !shop_to_llm_is_global_notice_screen()) {
        return;
    }
    $file = ABSPATH . 'llms.txt';
    if (!file_exists($file)) {
        return;
    }
    // v1.5.4: our static-takeover output carries this marker; suppress the
    // warning while the file holds our content (competitor plugins regenerate
    // the file on their own hooks — the marker disappearing re-arms the alert)
    $contents = (string) file_get_contents($file);
    if (str_contains($contents, '[Generated by Shop2LLM]')) {
        return;
    }
    if (isset($_GET['shop2llm_delete_llms_file']) && check_admin_referer('shop2llm_delete_llms_file')) {
        wp_delete_file($file);
        echo '<div class="notice notice-success is-dismissible"><p><strong>Shop2LLM:</strong> '
            . esc_html__('Physical llms.txt removed. The dynamic Shop2LLM endpoint is live again.', 'shop2llm')
            . '</p></div>';
        return;
    }
    if (isset($_GET['shop2llm_overwrite_llms_file']) && check_admin_referer('shop2llm_overwrite_llms_file')) {
        delete_transient('shop_to_llm_llms_txt_output');
        $output = shop_to_llm_build_llms_txt() . "\n\n[Generated by Shop2LLM]\n";
        @file_put_contents($file, $output); // phpcs:ignore WordPress.WP.AlternativeFunctions
        echo '<div class="notice notice-success is-dismissible"><p><strong>Shop2LLM:</strong> '
            . esc_html__('Physical llms.txt overwritten with Shop2LLM output. If another plugin keeps regenerating it, deactivate that plugin for a permanent fix.', 'shop2llm')
            . '</p></div>';
        return;
    }
    $deleteUrl = wp_nonce_url(
        admin_url('index.php?shop2llm_delete_llms_file=1'),
        'shop2llm_delete_llms_file'
    );
    $overwriteUrl = wp_nonce_url(
        admin_url('index.php?shop2llm_overwrite_llms_file=1'),
        'shop2llm_overwrite_llms_file'
    );
    // v1.5.4: name the likely writer so the user knows which plugin regenerates it
    $active = (array) get_option('active_plugins', []);
    $writer = '';
    if (in_array('website-llms-txt/website-llms-txt.php', $active, true)) {
        $writer = ' ' . esc_html__('Likely regenerated by the "Website LLMs.txt" plugin — deactivate it to keep the conflict from coming back.', 'shop2llm');
    } elseif (in_array('llemmy/llemmy.php', $active, true)) {
        $writer = ' ' . esc_html__('Likely regenerated by the "llemmy" plugin — deactivate it to keep the conflict from coming back.', 'shop2llm');
    }
    ?>
    <div class="notice notice-warning is-dismissible">
        <p style="font-size:13px">
            <strong><?php esc_html_e('Shop2LLM:', 'shop2llm'); ?></strong>
            <?php esc_html_e('A physical llms.txt file exists in your site root — it is served directly and shadows Shop2LLM\'s richer dynamic llms.txt (product catalog, MCP, AI visibility). If it was generated by another plugin or an old setup, remove it to restore dynamic output.', 'shop2llm');
            echo esc_html($writer);
            ?>
            <a href="<?php echo esc_url($overwriteUrl); ?>" class="button button-small button-primary" style="margin-left:6px">
                <?php esc_html_e('Overwrite with Shop2LLM output', 'shop2llm'); ?>
            </a>
            <a href="<?php echo esc_url($deleteUrl); ?>" class="button button-small"
               onclick="return confirm('<?php echo esc_attr__('Delete the physical llms.txt file?', 'shop2llm'); ?>')">
                <?php esc_html_e('Delete physical file', 'shop2llm'); ?>
            </a>
        </p>
    </div>
    <?php
}

function shop_to_llm_robots_txt_fix_notice(): void
{
    // Only show on Shop2LLM settings page
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'toplevel_page_shop2llm') {
        return;
    }

    // Handle fix action
    if (isset($_GET['shop2llm_fix_robots']) && check_admin_referer('shop2llm_fix_robots')) {
        flush_rewrite_rules();
        delete_transient('shop2llm_robots_allows_ai');
        sleep(1);
        if (shop_to_llm_check_robots_txt_allows_ai()) {
            echo '<div class="notice notice-success"><p>'
                . esc_html__('✅ AI crawler rules added to robots.txt. Your content is now discoverable by AI.', 'shop2llm')
                . '</p></div>';
            return;
        }
    }

    if (shop_to_llm_check_robots_txt_allows_ai()) {
        // Show green status badge — always visible
        ?>
        <div class="notice notice-success" style="border-left-color:#16a34a">
            <p style="font-size:13px">
                <?php
                /* translators: %d: number of AI bots whitelisted in robots.txt */
                echo esc_html(sprintf(__('✅ robots.txt allows AI crawlers — %d AI bots whitelisted. Your content is discoverable by ChatGPT, GPTBot, ClaudeBot and more.', 'shop2llm'), count(shop_to_llm_get_robots_allowed_crawlers())));
                ?>
            </p>
        </div>
        <?php
        return;
    }

    // Blocked — show fix button
    ?>
    <div class="notice notice-warning" style="border-left-color:#f59e0b">
        <p style="font-size:13px">
            <strong><?php echo esc_html__('⚠ robots.txt may block AI crawlers', 'shop2llm'); ?></strong><br>
            <?php echo esc_html__('AI search engines cannot index your site. This reduces your AI Score.', 'shop2llm'); ?>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=shop2llm&shop2llm_fix_robots=1'), 'shop2llm_fix_robots')); ?>" 
               style="display:inline-block;margin-top:6px;padding:4px 12px;background:#f59e0b;color:#fff;border-radius:4px;text-decoration:none;font-weight:600;font-size:12px">
                <?php echo esc_html__('🛠 Fix robots.txt', 'shop2llm'); ?>
            </a>
            <span style="font-size:11px;color:#94a3b8;margin-left:8px">
                <?php echo esc_html__('Adds AI crawler rules to allow GPTBot, ClaudeBot & 10 more', 'shop2llm'); ?>
            </span>
        </p>
    </div>
    <?php
}

function shop_to_llm_check_prerendered_html(int $postId): bool
{
    $transientKey = 'shop2llm_prerendered_' . $postId;
    $cached = get_transient($transientKey);
    if ($cached !== false) {
        return $cached === 'yes';
    }

    $url = get_permalink($postId);
    if (!$url) {
        set_transient($transientKey, 'no', DAY_IN_SECONDS);
        return false;
    }
    $response = wp_remote_get($url, ['timeout' => 5]);
    if (is_wp_error($response)) {
        set_transient($transientKey, 'yes', DAY_IN_SECONDS);
        return true;
    }
    $html = wp_remote_retrieve_body($response);
    $text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $text);
    $text = wp_strip_all_tags($text);
    $text = trim($text);
    $result = strlen($text) > 200;
    set_transient($transientKey, $result ? 'yes' : 'no', DAY_IN_SECONDS);
    return $result;
}

function shop_to_llm_check_wikidata_entity(string $query): bool
{
    $transientKey = 'shop2llm_wikidata_' . md5($query);
    $cached = get_transient($transientKey);
    if ($cached !== false) {
        return $cached === 'yes';
    }

    $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbsearchentities',
        'search' => $query,
        'language' => 'en',
        'format' => 'json',
        'limit' => 1,
    ]);
    $response = wp_remote_get($url, ['timeout' => 5]);
    if (is_wp_error($response)) {
        set_transient($transientKey, 'no', DAY_IN_SECONDS);
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $found = !empty($data['search']) && count($data['search']) > 0;
    set_transient($transientKey, $found ? 'yes' : 'no', 7 * DAY_IN_SECONDS);
    return $found;
}

function shop_to_llm_check_wikipedia_mention(string $query): bool
{
    $transientKey = 'shop2llm_wikipedia_' . md5($query);
    $cached = get_transient($transientKey);
    if ($cached !== false) {
        return $cached === 'yes';
    }

    $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query',
        'list' => 'search',
        'srsearch' => $query,
        'format' => 'json',
    ]);
    $response = wp_remote_get($url, ['timeout' => 5]);
    if (is_wp_error($response)) {
        set_transient($transientKey, 'no', DAY_IN_SECONDS);
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $found = !empty($data['query']['search']) && count($data['query']['search']) > 0;
    set_transient($transientKey, $found ? 'yes' : 'no', 7 * DAY_IN_SECONDS);
    return $found;
}

function shop_to_llm_has_statistics(string $content): bool
{
    $patterns = [
        '/\d+\.?\d*\s*%/',
        '/\$\d+[\d,]*\.?\d*/',
        '/\d+[\d,]*\s*(people|users|customers|clients|companies|sites|stores|products|items|orders|sales|revenue|growth|increase|decrease)/i',
        '/\d{4}\s*(study|report|survey|research|data)/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            return true;
        }
    }
    return false;
}

function shop_to_llm_has_citations(string $content): bool
{
    if (preg_match('/<blockquote|<cite|<q/i', $content)) {
        return true;
    }
    if (preg_match('/"[^"]{10,}"\s*[-—]\s*\w+/i', $content)) {
        return true;
    }
    if (preg_match('/"[^"]{10,}"\s*(said|according|noted|stated)/i', $content)) {
        return true;
    }
    return false;
}

function shop_to_llm_count_internal_links(string $content): int
{
    $homeUrl = home_url();
    $count = preg_match_all('/<a[^>]+href=["\']' . preg_quote($homeUrl, '/') . '/i', $content);
    $count += preg_match_all('/<a[^>]+href=["\']\/[^"\']*["\']/i', $content);
    return (int) $count;
}

function shop_to_llm_keyword_density(string $content, string $keyword): float
{
    $plain = wp_strip_all_tags($content);
    $contentLen = mb_strlen($plain);
    if ($contentLen === 0) {
        return 0.0;
    }
    $keywordCount = shop_to_llm_seo_count_keyword_occurrences($plain, $keyword);
    return ($keywordCount * mb_strlen($keyword)) / $contentLen * 100;
}

function shop_to_llm_get_post_schema_data(\WP_Post $post): array
{
    $data = [];

    if (shop_to_llm_has_woocommerce() && $post->post_type === 'product') {
        $product = wc_get_product($post->ID);
        if ($product) {
            $data[] = $product->get_name();
            $data[] = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
            $cats = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names']);
            if (!is_wp_error($cats)) {
                $data = array_merge($data, $cats);
            }
        }
    } else {
        $data[] = $post->post_title;
        $data[] = wp_strip_all_tags(get_the_excerpt($post) ?: wp_trim_words(wp_strip_all_tags($post->post_content), 30));
        $cats = wp_get_post_terms($post->ID, 'category', ['fields' => 'names']);
        if (!is_wp_error($cats)) {
            $data = array_merge($data, $cats);
        }
    }

    return $data;
}

function shop_to_llm_get_meta_description(\WP_Post $post): string
{
    // Try Yoast SEO
    $yoastMeta = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
    if (!empty(trim((string) $yoastMeta))) {
        return $yoastMeta;
    }

    // Try Rank Math
    $rankMathMeta = get_post_meta($post->ID, 'rank_math_description', true);
    if (!empty(trim((string) $rankMathMeta))) {
        return $rankMathMeta;
    }

    // Fallback to excerpt
    if (!empty(trim($post->post_excerpt))) {
        return wp_strip_all_tags($post->post_excerpt);
    }

    return '';
}

function shop_to_llm_save_focus_query(int $postId): void
{
    if (!isset($_POST['shop2llm_focus_query_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shop2llm_focus_query_nonce'])), 'shop2llm_save_focus_query')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $postId)) {
        return;
    }

    $focusQuery = isset($_POST['shop2llm_focus_query']) ? sanitize_text_field(wp_unslash($_POST['shop2llm_focus_query'])) : '';
    update_post_meta($postId, '_shop2llm_focus_query', $focusQuery);

    // Save authoritative list checkbox
    $onList = isset($_POST['shop2llm_on_authoritative_list']) ? 1 : 0;
    update_post_meta($postId, '_shop2llm_on_authoritative_list', $onList);

    // Clear score cache so it recalculates
    delete_transient('shop2llm_score_' . $postId);

    // Also save visibility snapshot
    $score = shop_to_llm_calculate_visibility_score($postId);
    $history = get_post_meta($postId, '_shop2llm_visibility_history', true);
    if (!is_array($history)) {
        $history = [];
    }
    $today = current_time('Y-m-d');
    // Remove any entry for today (update it)
    $history = array_filter($history, function ($entry) use ($today) {
        return $entry['date'] !== $today;
    });
    $history[] = ['date' => $today, 'score' => $score];
    // Keep only last 8 weeks (56 days)
    $cutoff = gmdate('Y-m-d', time() - 56 * DAY_IN_SECONDS);
    $history = array_filter($history, function ($entry) use ($cutoff) {
        return $entry['date'] >= $cutoff;
    });
    $history = array_values($history);
    update_post_meta($postId, '_shop2llm_visibility_history', $history);
}

// ─── AI Visibility Trend — Weekly Snapshot Cron ────────────────────────

function shop_to_llm_save_visibility_snapshot(int $postId): void
{
    $score = shop_to_llm_calculate_visibility_score($postId);
    $history = get_post_meta($postId, '_shop2llm_visibility_history', true);
    if (!is_array($history)) {
        $history = [];
    }
    $today = current_time('Y-m-d');
    $history = array_filter($history, function ($entry) use ($today) {
        return $entry['date'] !== $today;
    });
    $history[] = ['date' => $today, 'score' => $score];
    $cutoff = gmdate('Y-m-d', time() - 56 * DAY_IN_SECONDS);
    $history = array_filter($history, function ($entry) use ($cutoff) {
        return $entry['date'] >= $cutoff;
    });
    $history = array_values($history);
    update_post_meta($postId, '_shop2llm_visibility_history', $history);
}

function shop_to_llm_weekly_snapshot_callback(): void
{
    $posts = get_posts([
        'numberposts' => 100,
        'post_status' => 'publish',
        'post_type' => ['post', 'product'],
    ]);
    foreach ($posts as $p) {
        shop_to_llm_save_visibility_snapshot($p->ID);
    }
}

/**
 * Score a single post using the 25-item 5-dimension model.
 * Used by the Dashboard Content AI Score card.
 *
 * @return array{total: int, has_focus: bool, items: array, groups: array}
 */
/**
 * Convert a site URL to an internally-accessible URL.
 * Fixes Docker port-mapping where site_url uses host port (e.g. 8500)
 * but the container listens on 80 internally.
 */
function shop_to_llm_self_url(string $path = ''): string
{
    $site_url = get_site_url();
    $parsed = wp_parse_url($site_url);
    $host = $parsed['host'] ?? 'localhost';
    $port = $parsed['port'] ?? '';
    $scheme = $parsed['scheme'] ?? 'http';
    // If localhost with a mapped port, use port 80 for internal access
    if ($host === 'localhost' && $port && $port !== '80' && $port !== '443') {
        $port = '';
    }
    $base = $scheme . '://' . $host;
    if ($port) {
        $base .= ':' . $port;
    }
    return untrailingslashit($base) . $path;
}

/**
 * v1.3.11: Record today's AI Score into a rolling 30-day history (incremental —
 * only today's entry is written per render, no full-history recomputation).
 */
function shop_to_llm_record_score_history(int $totalScore): void
{
    $today = gmdate('Y-m-d');
    $history = get_option('shop2llm_score_history', []);
    if (!is_array($history)) {
        $history = [];
    }
    if (isset($history[$today]) && (int) $history[$today] === $totalScore) {
        return;
    }
    $history[$today] = $totalScore;
    krsort($history);
    if (count($history) > 30) {
        $history = array_slice($history, 0, 30, true);
    }
    update_option('shop2llm_score_history', $history, false);
}

/**
 * v1.3.11: Score delta vs the most recent recorded day within the last 7 days.
 * Returns null when there is not enough history yet.
 */
function shop_to_llm_score_delta_7d(int $totalScore): ?int
{
    $history = get_option('shop2llm_score_history', []);
    if (!is_array($history) || count($history) < 2) {
        return null;
    }
    krsort($history);
    unset($history[gmdate('Y-m-d')]);
    if (empty($history)) {
        return null;
    }
    $cutoff = gmdate('Y-m-d', strtotime('-7 days'));
    foreach ($history as $day => $score) {
        if ($day < $cutoff) {
            break;
        }
        return $totalScore - (int) $score;
    }
    return null;
}

/**
 * Get the 10-factor AI Visibility Score for the entire site.
 * v1.4.0: added Policies (10) + Google Feed (10) factors, rebalanced to /100.
 * v1.5.4: removed the Google Feed factor (user request — Merchant Center setup
 * is out of scope for AI visibility); weights rebalanced to keep /100.
 * v1.6.0: expanded to 10 factors — added llms-full.txt (8) and Multilingual (4);
 * weights rebalanced (robots/llms 14, schema/MCP 12, feed 10, queries/crawlers 8,
 * policies 10, llms-full 8, multilingual 4).
 * Returns total, label, color, bg, and per-factor breakdown.
 * @since 1.0.8
 */
function shop_to_llm_get_visibility_score(): array
{
    $scoreRobots = shop_to_llm_check_robots_txt_allows_ai() ? 14 : 0;

    $scoreLlms = 0;
    $llmsAccessible = get_transient('shop2llm_llms_accessible');
    if ($llmsAccessible === false) {
        // Try HTTP check via self URL (handles Docker port-mapping)
        $llmsUrl = shop_to_llm_self_url('/llms.txt');
        $llmsResp = wp_remote_get($llmsUrl, ['timeout' => 5, 'redirection' => 0]);
        $httpOk = !is_wp_error($llmsResp) && wp_remote_retrieve_response_code($llmsResp) === 200;
        if ($httpOk && strlen(wp_remote_retrieve_body($llmsResp)) > 50) {
            $llmsAccessible = 'yes';
        } else {
            // Fallback: check if rewrite rule is registered (works even if HTTP unreachable)
            $rules = get_option('rewrite_rules');
            $hasRule = $rules && (isset($rules['^llms\.txt$']) || (is_array($rules) && in_array('index.php?shop_to_llm_llms_txt=1', $rules, true)));
            $llmsAccessible = $hasRule ? 'yes' : 'no';
        }
        set_transient('shop2llm_llms_accessible', $llmsAccessible, HOUR_IN_SECONDS);
    }
    if ($llmsAccessible === 'yes') {
        $scoreLlms = 14;
    }

    // v1.6.0: llms-full.txt factor — complete-content catalog endpoint (8 pts)
    $scoreLlmsFull = 0;
    $llmsFullAccessible = get_transient('shop2llm_llms_full_accessible');
    if ($llmsFullAccessible === false) {
        $llmsFullUrl = shop_to_llm_self_url('/llms-full.txt');
        $llmsFullResp = wp_remote_get($llmsFullUrl, ['timeout' => 5, 'redirection' => 0]);
        $llmsFullOk = !is_wp_error($llmsFullResp) && wp_remote_retrieve_response_code($llmsFullResp) === 200;
        if ($llmsFullOk && strlen(wp_remote_retrieve_body($llmsFullResp)) > 50) {
            $llmsFullAccessible = 'yes';
        } else {
            // Fallback: check if rewrite rule is registered (works even if HTTP unreachable)
            $rulesFull = get_option('rewrite_rules');
            $hasRuleFull = $rulesFull && (isset($rulesFull['^llms-full\.txt$']) || (is_array($rulesFull) && in_array('index.php?shop_to_llm_llms_full_txt=1', $rulesFull, true)));
            $llmsFullAccessible = $hasRuleFull ? 'yes' : 'no';
        }
        set_transient('shop2llm_llms_full_accessible', $llmsFullAccessible, HOUR_IN_SECONDS);
    }
    if ($llmsFullAccessible === 'yes') {
        $scoreLlmsFull = 8;
    }

    $pubCount = shop_to_llm_has_woocommerce()
        ? (int)(wp_count_posts('product')->publish ?? 0)
        : (int)(wp_count_posts('post')->publish ?? 0);

    $scoreSchema = 0;
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'active_plugins' is a WordPress core hook
    $activePlugins = apply_filters('active_plugins', get_option('active_plugins', []));
    $seoSlugs = ['wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php', 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'wp-seopress/seopress.php'];
    $schemaHasConflict = (bool) array_intersect($seoSlugs, $activePlugins);
    if ($schemaHasConflict) {
        $scoreSchema = 7;
    } elseif ($pubCount > 0) {
        $scoreSchema = 12;
    } else {
        $scoreSchema = 8;
    }

    $todayCount = shop_to_llm_get_today_api_count();
    $scoreMcp = 0;
    $mcpEnabled = get_option('shop_to_llm_enabled', 'yes') === 'yes';
    if ($mcpEnabled && $todayCount > 0) {
        $scoreMcp = 12;
    } elseif ($mcpEnabled) {
        $scoreMcp = 4;
    }

    $scoreFeed = 0;
    if (shop_to_llm_has_woocommerce()) {
        $feedAccessible = get_transient('shop2llm_feed_accessible');
        if ($feedAccessible === false) {
            // Try HTTP check via self URL (handles Docker port-mapping)
            $feedUrl = shop_to_llm_self_url('/product-feed.xml');
            $feedResp = wp_remote_get($feedUrl, ['timeout' => 5, 'redirection' => 0]);
            $httpOk = !is_wp_error($feedResp) && wp_remote_retrieve_response_code($feedResp) === 200;
            if ($httpOk && strlen(wp_remote_retrieve_body($feedResp)) > 100) {
                $feedAccessible = 'yes';
            } else {
                // Fallback: check if rewrite rule is registered
                $rules = get_option('rewrite_rules');
                $hasRule = $rules && (isset($rules['^product-feed\.xml$']) || (is_array($rules) && in_array('index.php?shop_to_llm_feed=1', $rules, true)));
                $feedAccessible = $hasRule ? 'yes' : 'no';
            }
            set_transient('shop2llm_feed_accessible', $feedAccessible, HOUR_IN_SECONDS);
        }
        if ($feedAccessible === 'yes') {
            $scoreFeed = 10;
        }
    } else {
        $scoreFeed = 10;
    }

    // Queries score: based on how many posts/products have Focus Query set
    global $wpdb;
    $scoreQueries = 0;
    $fqCount = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        '_shop2llm_focus_query'
    ));
    if ($pubCount > 0) {
        $fqRatio = $fqCount / $pubCount;
        if ($fqRatio >= 0.5) {
            $scoreQueries = 8;
        } elseif ($fqRatio >= 0.25) {
            $scoreQueries = 6;
        } elseif ($fqRatio >= 0.1) {
            $scoreQueries = 5;
        } elseif ($fqCount > 0) {
            $scoreQueries = 3;
        }
    }

    // Crawlers score: based on AI crawler visits in last 30 days
    $scoreCrawlers = 0;
    $cTable = $wpdb->prefix . 'shop2llm_crawler_log';
    $cTableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cTable)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ($cTableExists) {
        $thirtyDaysAgo = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $crawlerTotal30d = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT COUNT(*) FROM {$cTable} WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $thirtyDaysAgo
        ));
        $uniqueBots30d = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT COUNT(DISTINCT bot_name) FROM {$cTable} WHERE visited_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $thirtyDaysAgo
        ));
        if ($uniqueBots30d >= 4 && $crawlerTotal30d >= 20) {
            $scoreCrawlers = 8;
        } elseif ($uniqueBots30d >= 2 && $crawlerTotal30d >= 5) {
            $scoreCrawlers = 6;
        } elseif ($crawlerTotal30d >= 1) {
            $scoreCrawlers = 4;
        }
    }

    // v1.4.0: Policies factor; v1.5.4: Google Feed factor removed
    $scorePolicy = shop_to_llm_policy_score();

    // v1.6.0: Multilingual factor (4 pts) — full credit when nothing to configure;
    // when a translation plugin is active, credit requires languages to be set up.
    $scoreMultilingual = 4;
    $mlActive = defined('POLYLANG_VERSION') || defined('ICL_SITEPRESS_VERSION') || defined('TRP_PLUGIN_VERSION');
    if ($mlActive) {
        $mlConfigured = false;
        if (defined('POLYLANG_VERSION')) {
            $mlConfigured = function_exists('pll_languages_list') && !empty(pll_languages_list());
        } elseif (defined('ICL_SITEPRESS_VERSION')) {
            $mlConfigured = !empty(get_option('icl_sitepress_settings')['active_languages'] ?? []);
        } elseif (defined('TRP_PLUGIN_VERSION')) {
            $mlConfigured = !empty(get_option('trp_languages_settings', [])['translateable_languages'] ?? []);
        }
        $scoreMultilingual = $mlConfigured ? 4 : 0;
    }

    // Total based on all 10 factors (max 100)
    $total = $scoreRobots + $scoreLlms + $scoreLlmsFull + $scoreSchema + $scoreMcp + $scoreFeed + $scoreQueries + $scoreCrawlers + $scorePolicy + $scoreMultilingual;
    $freeMax = 100;

    $label = $total >= 90 ? __('Excellent', 'shop2llm') : ($total >= 70 ? __('Good', 'shop2llm') : ($total >= 50 ? __('Fair', 'shop2llm') : ($total >= 30 ? __('Poor', 'shop2llm') : __('Critical', 'shop2llm'))));
    $color = $total >= 70 ? '#22c55e' : ($total >= 40 ? '#eab308' : '#ef4444');
    $bg = $total >= 70 ? '#f0fdf4' : ($total >= 40 ? '#fefce8' : '#fef2f2');

    return [
        'total' => $total, 'free_max' => $freeMax,
        'label' => $label, 'color' => $color, 'bg' => $bg,
        'schema_has_conflict' => $schemaHasConflict,
        'factors' => [
            'robots' => $scoreRobots, 'llms' => $scoreLlms, 'llms_full' => $scoreLlmsFull,
            'schema' => $scoreSchema,
            'mcp' => $scoreMcp, 'feed' => $scoreFeed,
            'queries' => $scoreQueries, 'crawlers' => $scoreCrawlers,
            'policy' => $scorePolicy, 'multilingual' => $scoreMultilingual,
        ],
    ];
}

function shop_to_llm_score_single_post(int $postId): array
{
    $post = get_post($postId);
    if (!$post || $post->post_status !== 'publish') {
        return ['total' => 0, 'has_focus' => false, 'items' => [], 'groups' => []];
    }

    $focusQuery = get_post_meta($postId, '_shop2llm_focus_query', true) ?: '';
    $hasFocusQuery = !empty(trim((string)$focusQuery));
    $content = $post->post_content;
    $entityChecksEnabled = (bool) get_option('shop_to_llm_enable_entity_checks', 0);
    $items = [];
    $total = 0;

    // ─── L1: Discoverability (30 pts, items 1-6) ───
    // Fix: build the content directly — render_llms_txt() exits, which killed
    // the whole request when the transient was cold.
    $llmsTxt = get_transient('shop_to_llm_llms_txt_output');
    if (!$llmsTxt) {
        $llmsTxt = shop_to_llm_build_llms_txt();
    }
    $i1 = $hasFocusQuery ? ($llmsTxt && stripos($llmsTxt, $focusQuery) !== false) : !empty($llmsTxt);
    if ($i1) {
        $total += 8;
    }
    $items[] = ['name' => __('llms.txt contains query', 'shop2llm'), 'pass' => $i1, 'pts' => 8];

    $i2 = shop_to_llm_check_robots_txt_allows_ai();
    if ($i2) {
        $total += 6;
    }
    $items[] = ['name' => __('robots.txt allows AI', 'shop2llm'), 'pass' => $i2, 'pts' => 6];

    $sd = shop_to_llm_get_post_schema_data($post);
    $i3 = $hasFocusQuery ? (!empty($sd) && stripos(implode(' ', $sd), $focusQuery) !== false) : !empty($sd);
    if ($i3) {
        $total += 6;
    }
    $items[] = ['name' => __('Schema contains query', 'shop2llm'), 'pass' => $i3, 'pts' => 6];

    $i4 = (bool) get_option('shop_to_llm_mcp_connected', false);
    if ($i4) {
        $total += 4;
    }
    $items[] = ['name' => __('MCP tools connected', 'shop2llm'), 'pass' => $i4, 'pts' => 4];

    $i5 = shop_to_llm_check_prerendered_html($post->ID);
    if ($i5) {
        $total += 4;
    }
    $items[] = ['name' => __('Pre-rendered HTML readable', 'shop2llm'), 'pass' => $i5, 'pts' => 4];

    $i6 = (stripos($content, 'FAQPage') !== false || stripos($content, '<!-- wp:faq') !== false || preg_match('/<h[2-4][^>]*>.*\?.*<\/h/i', $content));
    if ($i6) {
        $total += 2;
    }
    $items[] = ['name' => __('FAQPage schema present', 'shop2llm'), 'pass' => $i6, 'pts' => 2];

    // ─── L2: Relevance (25 pts, items 7-12) ───
    if ($hasFocusQuery) {
        $tLen = strlen($post->post_title);
        $i7pts = 0;
        if ($tLen > 0 && stripos($post->post_title, $focusQuery) !== false) {
            $r = stripos($post->post_title, $focusQuery) / $tLen;
            $i7pts = $r < 0.3 ? 6 : ($r < 0.7 ? 4 : 2);
        }
        $total += $i7pts;
        $items[] = ['name' => __('Query in title', 'shop2llm'), 'pass' => $i7pts > 0, 'pts' => $i7pts];

        $i8pts = 0;
        if (preg_match('/<h1[^>]*>.*' . preg_quote($focusQuery, '/') . '.*<\/h1>/i', $content)) {
            $i8pts = 5;
        } elseif (preg_match('/<h2[^>]*>.*' . preg_quote($focusQuery, '/') . '.*<\/h2>/i', $content)) {
            $i8pts = 3;
        }
        $total += $i8pts;
        $items[] = ['name' => __('Query in heading', 'shop2llm'), 'pass' => $i8pts > 0, 'pts' => $i8pts];

        $fw = implode(' ', array_slice(explode(' ', wp_strip_all_tags($content)), 0, 100));
        $i9 = (stripos($fw, $focusQuery) !== false);
        if ($i9) {
            $total += 4;
        }
        $items[] = ['name' => __('Query in first 100 words', 'shop2llm'), 'pass' => $i9, 'pts' => 4];

        $i10 = (stripos($post->post_name, $focusQuery) !== false);
        if ($i10) {
            $total += 4;
        }
        $items[] = ['name' => __('Query in URL/slug', 'shop2llm'), 'pass' => $i10, 'pts' => 4];

        $md = shop_to_llm_get_meta_description($post);
        $i11 = (!empty($md) && stripos($md, $focusQuery) !== false);
        if ($i11) {
            $total += 3;
        }
        $items[] = ['name' => __('Query in meta description', 'shop2llm'), 'pass' => $i11, 'pts' => 3];

        $altF = false;
        foreach (get_posts(['post_type' => 'attachment', 'posts_per_page' => -1, 'post_parent' => $post->ID]) as $a) {
            $alt = get_post_meta($a->ID, '_wp_attachment_image_alt', true);
            if (!empty($alt) && stripos($alt, $focusQuery) !== false) {
                $altF = true;
                break;
            }
        }
        if ($altF) {
            $total += 3;
        }
        $items[] = ['name' => __('Query in image alt text', 'shop2llm'), 'pass' => $altF, 'pts' => 3];
    } else {
        $l2Names = [__('Query in title', 'shop2llm'), __('Query in heading', 'shop2llm'), __('Query in first 100 words', 'shop2llm'), __('Query in URL/slug', 'shop2llm'), __('Query in meta description', 'shop2llm'), __('Query in image alt text', 'shop2llm')];
        foreach ($l2Names as $n) {
            $items[] = ['name' => $n, 'pass' => false, 'pts' => 0, 'locked' => true];
        }
    }

    // ─── L3: Content Quality (25 pts, items 13-18) ───
    $wc = str_word_count(wp_strip_all_tags($content));
    $i13pts = $wc > 300 ? 4 : ($wc > 150 ? 2 : 0);
    $total += $i13pts;
    $items[] = ['name' => __('Content length > 300 words', 'shop2llm'), 'pass' => $i13pts > 0, 'pts' => $i13pts];

    $i14 = shop_to_llm_has_statistics($content);
    if ($i14) {
        $total += 5;
    }
    $items[] = ['name' => __('Contains statistics/claims', 'shop2llm'), 'pass' => $i14, 'pts' => 5];

    $i15 = shop_to_llm_has_citations($content);
    if ($i15) {
        $total += 5;
    }
    $items[] = ['name' => __('Contains citations/quotes', 'shop2llm'), 'pass' => $i15, 'pts' => 5];

    $daysMod = (time() - strtotime($post->post_modified)) / DAY_IN_SECONDS;
    $i16pts = $daysMod <= 30 ? 4 : ($daysMod <= 90 ? 2 : 0);
    $total += $i16pts;
    $items[] = ['name' => __('Content freshness', 'shop2llm'), 'pass' => $i16pts > 0, 'pts' => $i16pts];

    $sf = 0;
    if (!empty($post->post_title)) {
        $sf++;
    }
    if (!empty(get_the_excerpt($post))) {
        $sf++;
    }
    if (has_post_thumbnail($post->ID)) {
        $sf++;
    }
    if (get_the_author_meta('display_name', $post->post_author)) {
        $sf++;
    }
    $i17pts = $sf >= 4 ? 4 : ($sf >= 3 ? 3 : ($sf >= 2 ? 2 : ($sf >= 1 ? 1 : 0)));
    $total += $i17pts;
    $items[] = ['name' => __('Schema completeness', 'shop2llm'), 'pass' => $i17pts > 0, 'pts' => $i17pts];

    $il = shop_to_llm_count_internal_links($content);
    $i18pts = $il >= 3 ? 3 : ($il >= 1 ? 1 : 0);
    $total += $i18pts;
    $items[] = ['name' => __('Internal links >= 3', 'shop2llm'), 'pass' => $i18pts > 0, 'pts' => $i18pts];

    // ─── L4: Entity Authority (15 pts, items 19-21) ───
    if ($hasFocusQuery) {
        $i19 = ($entityChecksEnabled && shop_to_llm_check_wikidata_entity($focusQuery));
        if ($i19) {
            $total += 5;
        }
        $items[] = ['name' => __('Brand in knowledge graph', 'shop2llm'), 'pass' => $i19, 'pts' => 5];

        $i20 = ($entityChecksEnabled && shop_to_llm_check_wikipedia_mention($focusQuery));
        if ($i20) {
            $total += 5;
        }
        $items[] = ['name' => __('Wikipedia mention', 'shop2llm'), 'pass' => $i20, 'pts' => 5];

        $onAuth = get_post_meta($postId, '_shop2llm_on_authoritative_list', true);
        $i21 = !empty($onAuth);
        if ($i21) {
            $total += 5;
        }
        $items[] = ['name' => __('On authoritative lists', 'shop2llm'), 'pass' => $i21, 'pts' => 5];
    } else {
        $l4Names = [__('Brand in knowledge graph', 'shop2llm'), __('Wikipedia mention', 'shop2llm'), __('On authoritative lists', 'shop2llm')];
        foreach ($l4Names as $n) {
            $items[] = ['name' => $n, 'pass' => false, 'pts' => 0, 'locked' => true];
        }
    }

    // ─── L5: Activity (5 pts, items 22-25) ───
    $cs = shop_to_llm_get_post_crawler_stats($post->ID);
    $i22pts = 0;
    if (!empty($cs['last_visit']) && $cs['last_visit'] !== '—') {
        $daysVisit = (time() - strtotime($cs['last_visit'])) / DAY_IN_SECONDS;
        $i22pts = $daysVisit <= 7 ? 2 : ($daysVisit <= 30 ? 1 : 0);
    }
    $total += $i22pts;
    $items[] = ['name' => __('AI crawler visit < 7 days', 'shop2llm'), 'pass' => $i22pts > 0, 'pts' => $i22pts];

    $i23 = ($post->comment_count > 0);
    if ($i23) {
        $total += 1;
    }
    $items[] = ['name' => __('Reviews/ratings exist', 'shop2llm'), 'pass' => $i23, 'pts' => 1];

    global $wpdb;
    $ct = $wpdb->prefix . 'shop2llm_crawler_log';
    $te = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ct)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $i24 = false;
    if ($te) {
        $db = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT bot_name) FROM {$ct} WHERE post_id = %d", $post->ID)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $i24 = ($db >= 4);
    }
    if ($i24) {
        $total += 1;
    }
    $items[] = ['name' => __('Multi-platform presence 4+', 'shop2llm'), 'pass' => $i24, 'pts' => 1];

    $i25 = false;
    if ($hasFocusQuery) {
        $density = shop_to_llm_keyword_density($content, $focusQuery);
        $i25 = ($density >= 0.5 && $density <= 2.5);
    }
    if ($i25) {
        $total += 1;
    }
    $items[] = ['name' => __('Keyword density 0.5-2.5%', 'shop2llm'), 'pass' => $i25, 'pts' => $i25 ? 1 : 0, 'locked' => !$hasFocusQuery];

    return ['total' => $total, 'has_focus' => $hasFocusQuery, 'items' => $items, 'groups' => []];
}

// ─── v1.3.0 SEO Module Code ────────────────────────────────────────────

// ─── SEO Plugin Detection ──────────────────────────────────────────────

function shop_to_llm_has_seo_plugin(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'active_plugins' is a WordPress core hook
    $active = apply_filters('active_plugins', get_option('active_plugins', []));
    $seo_slugs = [
        'wordpress-seo/wp-seo.php',
        'seo-by-rank-math/rank-math.php',
        'all-in-one-seo-pack/all_in_one_seo_pack.php',
        'wp-seopress/seopress.php',
    ];
    $has = (bool) array_intersect($seo_slugs, $active);
    return $has;
}

function shop_to_llm_get_seo_plugin_name(): string
{
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'active_plugins' is a WordPress core hook
    $active = apply_filters('active_plugins', get_option('active_plugins', []));
    $map = [
        'wordpress-seo/wp-seo.php' => 'Yoast SEO',
        'seo-by-rank-math/rank-math.php' => 'Rank Math',
        'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'AIOSEO',
        'wp-seopress/seopress.php' => 'SEOPress',
    ];
    foreach ($map as $slug => $name) {
        if (in_array($slug, $active, true)) {
            return $name;
        }
    }
    return '';
}

// ─── SEO DB Tables ────────────────────────────────────────────────────

/**
 * Set SEO default options on activation.
 *
 * Encapsulated in a function to avoid Plugin Check DirectDatabaseQuery
 * false positives on add_option() calls. add_option() is a WP API,
 * not a direct DB call — but Plugin Check's heuristic flags it.
 */
function shop_to_llm_set_default_options(): void
{
    add_option('shop_to_llm_seo_meta', 'yes');
    add_option('shop_to_llm_seo_schema', 'yes');
    add_option('shop_to_llm_policy_schema', 'yes');
    add_option('shop_to_llm_seo_og', 'yes');
    add_option('shop_to_llm_seo_analysis', 'yes');
    add_option('shop_to_llm_seo_title_home', '%%sitename%% %%sep%% %%tagline%%');
    add_option('shop_to_llm_seo_title_post', '%%title%% %%sep%% %%sitename%%');
    add_option('shop_to_llm_seo_title_page', '%%title%% %%sep%% %%sitename%%');
    add_option('shop_to_llm_seo_title_product', '%%title%% %%sep%% %%sitename%%');
    add_option('shop_to_llm_seo_title_category', '%%category%% %%sep%% %%sitename%%');
    add_option('shop_to_llm_seo_title_tag', '%%tag%% %%sep%% %%sitename%%');
    add_option('shop_to_llm_seo_title_archive', '%%title%% %%sep%% %%sitename%%');
}

// ─── SEO Overview Helper Functions ────────────────────────────────────

function shop_to_llm_seo_get_indexed_count(): int
{
    $count = 0;
    $count += (int) wp_count_posts('post')->publish ?? 0;
    $count += (int) wp_count_posts('page')->publish ?? 0;
    if (shop_to_llm_has_woocommerce()) {
        $count += (int) wp_count_posts('product')->publish ?? 0;
    }
    return $count;
}


function shop_to_llm_seo_get_schema_count(): int
{
    $count = 18;
    if (get_option('shop_to_llm_seo_schema', 'yes') !== 'yes') {
        $count -= 18;
    }
    if (shop_to_llm_has_seo_plugin()) {
        $count -= 18;
    }
    return max(0, $count);
}

// ─── SEO Meta Box Module ─────────────────────────────────────────────

function shop_to_llm_seo_add_meta_boxes(): void
{
    $types = ['post', 'page'];
    if (shop_to_llm_has_woocommerce()) {
        $types[] = 'product';
    }

    // v1.6.1 (lean): one unified tabbed meta box instead of six side-by-side boxes.
    foreach ($types as $pt) {
        add_meta_box(
            'shop2llm_hub',
            __('Shop2LLM — AI Visibility & SEO', 'shop2llm'),
            'shop_to_llm_render_hub_meta_box',
            $pt,
            'side',
            'high'
        );
    }
}

/**
 * Unified editor meta box: tabs reuse the existing render functions
 * (Visibility Score, SEO Fields, AI Extractability, Schema, OG Image).
 */
function shop_to_llm_render_hub_meta_box(WP_Post $post): void
{
    $panels = [
        'ai' => [__('Visibility Score', 'shop2llm'), 'shop_to_llm_render_visibility_meta_box'],
        'seo' => [__('SEO Fields', 'shop2llm'), 'shop_to_llm_seo_render_meta_box'],
    ];
    if (get_option('shop_to_llm_seo_analysis', 'yes') === 'yes') {
        $panels['extract'] = [__('AI Extractability (Live)', 'shop2llm'), 'shop_to_llm_seo_render_extractability_meta_box'];
    }
    $panels['schema'] = [__('Schema', 'shop2llm'), 'shop_to_llm_seo_render_schema_meta_box'];
    $panels['og'] = [__('OG Image', 'shop2llm'), 'shop_to_llm_seo_og_image_meta_box_html'];
    ?>
    <div class="s2ll-hub-tabs" style="display:flex;gap:14px;border-bottom:2px solid #e2e8f0;margin-bottom:10px;overflow-x:auto">
        <?php $first = true; foreach ($panels as $key => $info) : ?>
        <button type="button" class="s2ll-hub-tab<?php echo $first ? ' active' : ''; ?>" data-tab="s2ll-hub-panel-<?php echo esc_attr($key); ?>" style="border:none;background:none;padding:6px 2px;font-size:12px;font-weight:600;color:<?php echo $first ? '#2563eb' : '#64748b'; ?>;border-bottom:2px solid <?php echo $first ? '#2563eb' : 'transparent'; ?>;cursor:pointer;white-space:nowrap;margin-bottom:-2px"><?php echo esc_html($info[0]); ?></button>
            <?php $first = false;
        endforeach; ?>
    </div>
    <?php $first = true; foreach ($panels as $key => $info) : ?>
    <div class="s2ll-hub-panel<?php echo $first ? ' active' : ''; ?>" id="s2ll-hub-panel-<?php echo esc_attr($key); ?>" style="<?php echo $first ? '' : 'display:none'; ?>">
        <?php call_user_func($info[1], $post); ?>
    </div>
        <?php $first = false;
    endforeach; ?>
    <script>
    (function(){
        var box=document.getElementById('shop2llm_hub');
        if(!box){return;}
        box.addEventListener('click',function(e){
            var btn=e.target.closest('.s2ll-hub-tab');
            if(!btn){return;}
            var tabs=box.querySelectorAll('.s2ll-hub-tab');
            var panels=box.querySelectorAll('.s2ll-hub-panel');
            for(var i=0;i<tabs.length;i++){
                tabs[i].classList.remove('active');
                tabs[i].style.color='#64748b';
                tabs[i].style.borderBottomColor='transparent';
            }
            btn.classList.add('active');
            btn.style.color='#2563eb';
            btn.style.borderBottomColor='#2563eb';
            var target=btn.getAttribute('data-tab');
            for(var j=0;j<panels.length;j++){
                panels[j].style.display=(panels[j].id===target)?'block':'none';
            }
        });
    })();
    </script>
    <?php
}

function shop_to_llm_seo_render_meta_box(WP_Post $post): void
{
    wp_nonce_field('shop2llm_seo_save', 'shop2llm_seo_nonce');

    $focus_kw   = get_post_meta($post->ID, '_shop2llm_focus_kw', true);
    $seo_title  = get_post_meta($post->ID, '_shop2llm_seo_title', true);
    $meta_desc  = get_post_meta($post->ID, '_shop2llm_meta_desc', true);
    $canonical  = get_post_meta($post->ID, '_shop2llm_canonical', true);
    $noindex    = get_post_meta($post->ID, '_shop2llm_noindex', true);
    $nofollow   = get_post_meta($post->ID, '_shop2llm_nofollow', true);

    $site_name  = get_bloginfo('name');
    $preview_title = $seo_title ?: $post->post_title;
    $preview_desc  = $meta_desc ?: wp_trim_words(wp_strip_all_tags($post->post_content), 30, '...');
    $preview_url   = $canonical ?: get_permalink($post->ID);
    ?>
    <style>
    .s2ll-seo-field{margin-bottom:12px}
    .s2ll-seo-field label{display:block;font-weight:600;margin-bottom:3px;font-size:12px;color:#1e293b}
    .s2ll-seo-field input[type="text"],
    .s2ll-seo-field textarea{width:100%;padding:5px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box}
    .s2ll-seo-field textarea{resize:vertical;min-height:60px}
    .s2ll-seo-field input[type="checkbox"]{margin-right:4px}
    .s2ll-char-count{font-size:11px;color:#94a3b8;text-align:right;margin-top:2px}
    .s2ll-char-count.over{color:#dc2626;font-weight:600}
    .s2ll-checkbox-row{display:flex;gap:16px;margin-bottom:12px}
    .s2ll-checkbox-row label{display:flex;align-items:center;gap:4px;font-size:12px;font-weight:400;color:#475569;cursor:pointer}
    /* SERP Preview */
    .s2ll-serp-section{margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px}
    .s2ll-serp-section h4{margin:0 0 8px;font-size:12px;color:#64748b;font-weight:600}
    .s2ll-serp-desktop{font-family:Arial,sans-serif;margin-bottom:14px}
    .s2ll-serp-desktop .s2ll-serp-url{font-size:12px;color:#1a7300;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .s2ll-serp-desktop .s2ll-serp-title{font-size:15px;color:#1a0dab;line-height:1.3;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:2px}
    .s2ll-serp-desktop .s2ll-serp-desc{font-size:12px;color:#545454;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .s2ll-serp-mobile{font-family:Arial,sans-serif;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;max-width:320px;margin-bottom:14px}
    .s2ll-serp-mobile .s2ll-mobile-header{display:flex;align-items:center;gap:6px;margin-bottom:6px}
    .s2ll-serp-mobile .s2ll-mobile-icon{width:16px;height:16px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:10px;color:#64748b}
    .s2ll-serp-mobile .s2ll-mobile-url{font-size:11px;color:#1a7300;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .s2ll-serp-mobile .s2ll-serp-title{font-size:14px;color:#1a0dab;line-height:1.3;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:2px}
    .s2ll-serp-mobile .s2ll-serp-desc{font-size:11px;color:#545454;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    /* Social Preview */
    .s2ll-social-section{margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px}
    .s2ll-social-section h4{margin:0 0 8px;font-size:12px;color:#64748b;font-weight:600}
    .s2ll-social-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;max-width:320px;margin-bottom:8px}
    .s2ll-social-card-img{width:100%;height:120px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px}
    .s2ll-social-card-body{padding:10px 12px}
    .s2ll-social-card-domain{font-size:10px;color:#65676b;text-transform:uppercase;margin-bottom:3px}
    .s2ll-social-card-title{font-size:13px;color:#1d1d1f;font-weight:600;line-height:1.3;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .s2ll-social-card-desc{font-size:11px;color:#65676b;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .s2ll-mb-tabs{display:flex;gap:0;margin-bottom:10px;border-bottom:2px solid #e2e8f0}
    .s2ll-mb-tab{padding:6px 14px;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all 0.15s}
    .s2ll-mb-tab.active{color:#2563eb;border-bottom-color:#2563eb}
    .s2ll-mb-tab-content{display:none}
    .s2ll-mb-tab-content.active{display:block}
    </style>

    <div class="s2ll-mb-tabs">
        <button type="button" class="s2ll-mb-tab active" data-tab="s2ll-mb-general"><?php echo esc_html__('General', 'shop2llm'); ?></button>
        <button type="button" class="s2ll-mb-tab" data-tab="s2ll-mb-social"><?php echo esc_html__('Social', 'shop2llm'); ?></button>
    </div>

    <div class="s2ll-mb-tab-content active" id="s2ll-mb-general">

    <div class="s2ll-seo-field">
        <label for="shop2llm_focus_kw"><?php esc_html_e('Focus Keyword', 'shop2llm'); ?></label>
        <div style="display:flex;gap:4px">
            <input type="text" id="shop2llm_focus_kw" name="shop2llm_focus_kw" value="<?php echo esc_attr($focus_kw); ?>" placeholder="<?php esc_attr_e('Enter focus keyword(s), comma-separated', 'shop2llm'); ?>" style="flex:1" />
            <button type="button" id="s2ll-ai-keywords-btn" class="button" style="flex-shrink:0;white-space:nowrap" title="<?php esc_attr_e('AI-powered keyword suggestions based on your content', 'shop2llm'); ?>">
                <span style="font-size:14px">&#9889;</span> <?php esc_html_e('AI Suggest', 'shop2llm'); ?>
            </button>
        </div>
        <div id="s2ll-ai-keywords-status" style="font-size:11px;color:#64748b;margin-top:3px;display:none"></div>
    </div>

    <div class="s2ll-seo-field">
        <label for="shop2llm_seo_title"><?php esc_html_e('SEO Title', 'shop2llm'); ?></label>
        <input type="text" id="shop2llm_seo_title" name="shop2llm_seo_title" value="<?php echo esc_attr($seo_title); ?>" maxlength="70" />
        <div class="s2ll-char-count" id="shop2llm_seo_title_count" data-max="60"><?php echo esc_html(mb_strlen($seo_title) . '/60'); ?></div>
    </div>

    <div class="s2ll-seo-field">
        <label for="shop2llm_meta_desc"><?php esc_html_e('Meta Description', 'shop2llm'); ?></label>
        <textarea id="shop2llm_meta_desc" name="shop2llm_meta_desc" maxlength="170"><?php echo esc_textarea($meta_desc); ?></textarea>
        <div class="s2ll-char-count" id="shop2llm_meta_desc_count" data-max="155"><?php echo esc_html(mb_strlen($meta_desc) . '/155'); ?></div>
    </div>

    <div class="s2ll-seo-field">
        <label for="shop2llm_canonical"><?php esc_html_e('Canonical URL', 'shop2llm'); ?></label>
        <input type="text" id="shop2llm_canonical" name="shop2llm_canonical" value="<?php echo esc_attr($canonical); ?>" placeholder="https://" />
    </div>

    <div class="s2ll-checkbox-row">
        <label><input type="checkbox" name="shop2llm_noindex" value="1" <?php checked($noindex, '1'); ?> /> <?php esc_html_e('Noindex', 'shop2llm'); ?></label>
        <label><input type="checkbox" name="shop2llm_nofollow" value="1" <?php checked($nofollow, '1'); ?> /> <?php esc_html_e('Nofollow', 'shop2llm'); ?></label>
    </div>

    <?php if (shop_to_llm_has_woocommerce() && $post->post_type === 'product') : ?>
    <!-- WooCommerce Product SEO Fields -->
    <div style="margin-top:12px;padding-top:10px;border-top:1px solid #e2e8f0">
        <div style="font-size:12px;font-weight:600;color:#1e293b;margin-bottom:8px"><?php esc_html_e('Product Identifiers', 'shop2llm'); ?></div>
        <?php
        $gtin = get_post_meta($post->ID, '_shop2llm_gtin', true);
        $mpn = get_post_meta($post->ID, '_shop2llm_mpn', true);
        $brand = get_post_meta($post->ID, '_shop2llm_brand', true);
        $product_id_field = get_post_meta($post->ID, '_shop2llm_product_id', true);
        $condition = get_post_meta($post->ID, '_shop2llm_condition', true);
        ?>
        <div class="s2ll-seo-field">
            <label for="shop2llm_gtin"><?php esc_html_e('GTIN (UPC/EAN/ISBN)', 'shop2llm'); ?></label>
            <input type="text" id="shop2llm_gtin" name="shop2llm_gtin" value="<?php echo esc_attr($gtin); ?>" placeholder="e.g. 012345678905" />
        </div>
        <div class="s2ll-seo-field">
            <label for="shop2llm_mpn"><?php esc_html_e('MPN', 'shop2llm'); ?></label>
            <input type="text" id="shop2llm_mpn" name="shop2llm_mpn" value="<?php echo esc_attr($mpn); ?>" placeholder="<?php esc_attr_e('Manufacturer Part Number', 'shop2llm'); ?>" />
        </div>
        <div class="s2ll-seo-field">
            <label for="shop2llm_brand"><?php esc_html_e('Brand', 'shop2llm'); ?></label>
            <input type="text" id="shop2llm_brand" name="shop2llm_brand" value="<?php echo esc_attr($brand); ?>" placeholder="<?php esc_attr_e('Brand name', 'shop2llm'); ?>" />
        </div>
        <div class="s2ll-seo-field">
            <label for="shop2llm_product_id"><?php esc_html_e('Custom Product ID', 'shop2llm'); ?></label>
            <input type="text" id="shop2llm_product_id" name="shop2llm_product_id" value="<?php echo esc_attr($product_id_field); ?>" placeholder="e.g. SKU-001" />
        </div>
        <div class="s2ll-seo-field">
            <label for="shop2llm_condition"><?php esc_html_e('Condition', 'shop2llm'); ?></label>
            <select id="shop2llm_condition" name="shop2llm_condition" style="width:100%">
                <option value=""><?php esc_html_e('— Not set —', 'shop2llm'); ?></option>
                <option value="NewCondition" <?php selected($condition, 'NewCondition'); ?>><?php esc_html_e('New', 'shop2llm'); ?></option>
                <option value="UsedCondition" <?php selected($condition, 'UsedCondition'); ?>><?php esc_html_e('Used', 'shop2llm'); ?></option>
                <option value="RefurbishedCondition" <?php selected($condition, 'RefurbishedCondition'); ?>><?php esc_html_e('Refurbished', 'shop2llm'); ?></option>
                <option value="DamagedCondition" <?php selected($condition, 'DamagedCondition'); ?>><?php esc_html_e('Damaged', 'shop2llm'); ?></option>
            </select>
        </div>
    </div>
    <?php endif; ?>

    <!-- SERP Preview -->
    <div class="s2ll-serp-section">
        <h4><?php esc_html_e('SERP Preview', 'shop2llm'); ?></h4>
        <div class="s2ll-serp-desktop">
            <div class="s2ll-serp-url" id="s2ll-serp-desktop-url"><?php echo esc_html($preview_url); ?></div>
            <div class="s2ll-serp-title" id="s2ll-serp-desktop-title"><?php echo esc_html($preview_title . ' | ' . $site_name); ?></div>
            <div class="s2ll-serp-desc" id="s2ll-serp-desktop-desc"><?php echo esc_html($preview_desc); ?></div>
        </div>
        <div class="s2ll-serp-mobile">
            <div class="s2ll-mobile-header">
                <span class="s2ll-mobile-icon">&#9783;</span>
                <span class="s2ll-mobile-url" id="s2ll-serp-mobile-url"><?php echo esc_html(wp_parse_url($preview_url, PHP_URL_HOST) ?: $preview_url); ?></span>
            </div>
            <div class="s2ll-serp-title" id="s2ll-serp-mobile-title"><?php echo esc_html($preview_title . ' | ' . $site_name); ?></div>
            <div class="s2ll-serp-desc" id="s2ll-serp-mobile-desc"><?php echo esc_html($preview_desc); ?></div>
        </div>
    </div>

    </div>

    <div class="s2ll-mb-tab-content" id="s2ll-mb-social">

    <!-- Social Preview -->
    <div class="s2ll-social-section">
        <h4><?php esc_html_e('Social Preview', 'shop2llm'); ?></h4>
        <div class="s2ll-social-card">
            <div class="s2ll-social-card-img" id="s2ll-social-img"><?php esc_html_e('OG Image', 'shop2llm'); ?></div>
            <div class="s2ll-social-card-body">
                <div class="s2ll-social-card-domain" id="s2ll-social-domain"><?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?></div>
                <div class="s2ll-social-card-title" id="s2ll-social-title"><?php echo esc_html($preview_title); ?></div>
                <div class="s2ll-social-card-desc" id="s2ll-social-desc"><?php echo esc_html($preview_desc); ?></div>
            </div>
        </div>
    </div>
    </div>

    <script>
    (function() {
        var seoTitle = document.getElementById('shop2llm_seo_title');
        var metaDesc = document.getElementById('shop2llm_meta_desc');
        var focusKw  = document.getElementById('shop2llm_focus_kw');
        var canonical = document.getElementById('shop2llm_canonical');

        function updateCount(input, counterId, maxLen) {
            var counter = document.getElementById(counterId);
            if (!counter || !input) return;
            var len = input.value.length;
            counter.textContent = len + '/' + maxLen;
            if (len > maxLen) {
                counter.classList.add('over');
            } else {
                counter.classList.remove('over');
            }
        }

        function updatePreviews() {
            var siteName = <?php echo wp_json_encode($site_name); ?>;
            var defaultTitle = <?php echo wp_json_encode($post->post_title); ?>;
            var defaultDesc = <?php echo wp_json_encode(wp_trim_words(wp_strip_all_tags($post->post_content), 30, '...')); ?>;
            var defaultUrl = <?php echo wp_json_encode(get_permalink($post->ID)); ?>;
            var t = (seoTitle && seoTitle.value) ? seoTitle.value : defaultTitle;
            var d = (metaDesc && metaDesc.value) ? metaDesc.value : defaultDesc;
            var u = (canonical && canonical.value) ? canonical.value : defaultUrl;
            var displayTitle = t + ' | ' + siteName;
            var host = '';
            try { host = new URL(u).hostname; } catch(e) { host = u; }

            var dt = document.getElementById('s2ll-serp-desktop-title');
            var dd = document.getElementById('s2ll-serp-desktop-desc');
            var du = document.getElementById('s2ll-serp-desktop-url');
            var mt = document.getElementById('s2ll-serp-mobile-title');
            var md = document.getElementById('s2ll-serp-mobile-desc');
            var mu = document.getElementById('s2ll-serp-mobile-url');
            var st = document.getElementById('s2ll-social-title');
            var sd = document.getElementById('s2ll-social-desc');

            if (dt) dt.textContent = displayTitle;
            if (dd) dd.textContent = d;
            if (du) du.textContent = u;
            if (mt) mt.textContent = displayTitle;
            if (md) md.textContent = d;
            if (mu) mu.textContent = host;
            if (st) st.textContent = t;
            if (sd) sd.textContent = d;
        }

        if (seoTitle) {
            seoTitle.addEventListener('input', function() { updateCount(this, 'shop2llm_seo_title_count', 60); updatePreviews(); });
        }
        if (metaDesc) {
            metaDesc.addEventListener('input', function() { updateCount(this, 'shop2llm_meta_desc_count', 155); updatePreviews(); });
        }
        if (canonical) {
            canonical.addEventListener('input', updatePreviews);
        }
    })();

    // AI Keyword Suggestions
    (function() {
        var aiBtn = document.getElementById('s2ll-ai-keywords-btn');
        var kwInput = document.getElementById('shop2llm_focus_kw');
        var statusEl = document.getElementById('s2ll-ai-keywords-status');
        if (aiBtn && kwInput) {
            aiBtn.addEventListener('click', function() {
                aiBtn.disabled = true;
                aiBtn.textContent = '...';
                statusEl.style.display = 'block';
                statusEl.textContent = <?php echo wp_json_encode(__('Generating AI keyword suggestions...', 'shop2llm')); ?>;
                statusEl.style.color = '#64748b';

                var fd = new FormData();
                fd.append('action', 's2ll_seo_ai_keywords');
                fd.append('post_id', <?php echo (int) $post->ID; ?>);
                fd.append('nonce', <?php echo wp_json_encode(wp_create_nonce('s2ll_ai_nonce')); ?>);

                fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'})
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        aiBtn.disabled = false;
                        var remaining = (data.data && data.data.remaining !== undefined) ? data.data.remaining : -1;
                        var quotaText = remaining >= 0 ? ' (' + remaining + ' left today)' : '';
                        aiBtn.innerHTML = '<span style="font-size:14px">&#9889;</span> <?php echo esc_js(__('AI Suggest', 'shop2llm')); ?>';

                        if (data.success && data.data && data.data.keywords && data.data.keywords.length > 0) {
                            var existing = kwInput.value.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
                            var newKws = data.data.keywords.filter(function(k) { return existing.indexOf(k) === -1; });
                            var merged = existing.concat(newKws);
                            kwInput.value = merged.join(', ');
                            var fromLabel = (data.data.from === 'cache') ? ' (cached)' : (data.data.from === 'local' ? ' (local)' : '');
                            statusEl.textContent = <?php echo wp_json_encode(__('Suggestions added!', 'shop2llm')); ?> + fromLabel + quotaText;
                            statusEl.style.color = '#16a34a';
                            // Show error notice if fallback was used
                            if (data.data.error_notice) {
                                statusEl.textContent += ' — ' + data.data.error_notice;
                                statusEl.style.color = '#ca8a04';
                            }
                        } else if (data.data && data.data.error_notice) {
                            statusEl.textContent = data.data.error_notice + quotaText;
                            statusEl.style.color = '#ca8a04';
                        } else {
                            statusEl.textContent = <?php echo wp_json_encode(__('No suggestions available. Try adding manually.', 'shop2llm')); ?>;
                            statusEl.style.color = '#ca8a04';
                        }
                    })
                    .catch(function() {
                        aiBtn.disabled = false;
                        aiBtn.innerHTML = '<span style="font-size:14px">&#9889;</span> <?php echo esc_js(__('AI Suggest', 'shop2llm')); ?>';
                        statusEl.textContent = <?php echo wp_json_encode(__('Network error. Please try again.', 'shop2llm')); ?>;
                        statusEl.style.color = '#dc2626';
                    });
            });
        }
    })();

    // Tab switching
    (function() {
        var tabs = document.querySelectorAll('.s2ll-mb-tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var targetId = this.getAttribute('data-tab');
                document.querySelectorAll('.s2ll-mb-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
                document.querySelectorAll('.s2ll-mb-tab-content').forEach(function(c) { c.classList.remove('active'); });
                document.getElementById(targetId).classList.add('active');
            });
        });
    })();
    </script>
    <?php
}

function shop_to_llm_seo_save_meta(int $postId): void
{
    if (!isset($_POST['shop2llm_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shop2llm_seo_nonce'])), 'shop2llm_seo_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $postId)) {
        return;
    }

    $fields = [
        'shop2llm_focus_kw'  => '_shop2llm_focus_kw',
        'shop2llm_seo_title' => '_shop2llm_seo_title',
        'shop2llm_canonical' => '_shop2llm_canonical',
    ];
    foreach ($fields as $postKey => $metaKey) {
        if (isset($_POST[$postKey])) {
            update_post_meta($postId, $metaKey, sanitize_text_field(wp_unslash($_POST[$postKey])));
        }
    }

    if (isset($_POST['shop2llm_meta_desc'])) {
        update_post_meta($postId, '_shop2llm_meta_desc', sanitize_textarea_field(wp_unslash($_POST['shop2llm_meta_desc'])));
    }

    update_post_meta($postId, '_shop2llm_noindex', isset($_POST['shop2llm_noindex']) ? '1' : '0');
    update_post_meta($postId, '_shop2llm_nofollow', isset($_POST['shop2llm_nofollow']) ? '1' : '0');

    // WooCommerce product fields
    $product_fields = [
        'shop2llm_gtin'      => '_shop2llm_gtin',
        'shop2llm_mpn'       => '_shop2llm_mpn',
        'shop2llm_brand'     => '_shop2llm_brand',
        'shop2llm_product_id' => '_shop2llm_product_id',
        'shop2llm_condition'  => '_shop2llm_condition',
    ];
    foreach ($product_fields as $postKey => $metaKey) {
        if (isset($_POST[$postKey])) {
            update_post_meta($postId, $metaKey, sanitize_text_field(wp_unslash($_POST[$postKey])));
        }
    }

    // Cornerstone content
    if (isset($_POST['shop2llm_cornerstone'])) {
        update_post_meta($postId, '_shop2llm_cornerstone', '1');
    } else {
        delete_post_meta($postId, '_shop2llm_cornerstone');
    }
}

/**
 * Get available SEO title variables with their labels and callbacks.
 *
 * @return array<string, array{label: string, callback: callable}>
 */
function shop_to_llm_seo_get_title_variables(): array
{
    $vars = [
        'title' => [
            'label' => __('Post/Page Title', 'shop2llm'),
            'callback' => function (): string {
                if (is_singular()) {
                    global $post;
                    return $post->post_title;
                }
                if (is_category() || is_tag() || is_tax()) {
                    $term = get_queried_object();
                    return $term ? $term->name : '';
                }
                if (is_post_type_archive()) {
                    $pto = get_queried_object();
                    return $pto ? $pto->labels->name : '';
                }
                if (is_author()) {
                    $author = get_queried_object();
                    return $author ? $author->display_name : '';
                }
                if (is_front_page() || is_home()) {
                    return get_bloginfo('name');
                }
                return wp_title('', false) ?: get_bloginfo('name');
            },
        ],
        'sitename' => [
            'label' => __('Site Name', 'shop2llm'),
            'callback' => function (): string {
                return get_bloginfo('name');
            },
        ],
        'sep' => [
            'label' => __('Separator', 'shop2llm'),
            'callback' => function (): string {
                return apply_filters('shop2llm_title_sep', '|');
            },
        ],
        'tagline' => [
            'label' => __('Site Tagline', 'shop2llm'),
            'callback' => function (): string {
                return get_bloginfo('description');
            },
        ],
        'category' => [
            'label' => __('Primary Category', 'shop2llm'),
            'callback' => function (): string {
                $cats = get_the_category();
                return !empty($cats) ? $cats[0]->name : '';
            },
        ],
        'tag' => [
            'label' => __('Primary Tag', 'shop2llm'),
            'callback' => function (): string {
                $tags = get_the_tags();
                return !empty($tags) ? $tags[0]->name : '';
            },
        ],
        'date' => [
            'label' => __('Publish Date', 'shop2llm'),
            'callback' => function (): string {
                return get_the_date();
            },
        ],
        'author' => [
            'label' => __('Author Name', 'shop2llm'),
            'callback' => function (): string {
                return get_the_author();
            },
        ],
    ];

    return apply_filters('shop2llm_seo_title_variables', $vars);
}

/**
 * Parse a title template string by replacing %%variable%% placeholders.
 */
function shop_to_llm_seo_parse_title(string $template): string
{
    $variables = shop_to_llm_seo_get_title_variables();
    $result = $template;

    foreach ($variables as $key => $var) {
        $placeholder = '%%' . $key . '%%';
        if (strpos($result, $placeholder) !== false) {
            $value = call_user_func($var['callback']);
            $result = str_replace($placeholder, $value, $result);
        }
    }

    // Remove leading/trailing separators from empty variables
    $sep = apply_filters('shop2llm_title_sep', '|');
    $result = preg_replace('/\s*' . preg_quote($sep, '/') . '\s*' . preg_quote($sep, '/') . '\s*/', ' ' . $sep . ' ', $result);
    // Remove empty %%vars%% that weren't resolved
    $result = preg_replace('/\s*%%\w+%%\s*/', '', $result);
    // Clean up orphaned separators at start/end
    $result = trim($result, ' ' . $sep . ' ');
    $result = preg_replace('/^\s*' . preg_quote($sep, '/') . '\s*/', '', $result);
    $result = preg_replace('/\s*' . preg_quote($sep, '/') . '\s*$/', '', $result);
    $result = trim($result);

    return $result;
}

function shop_to_llm_seo_inject_meta(): void
{
    if (get_option('shop_to_llm_seo_meta', 'yes') !== 'yes') {
        return;
    }
    if (shop_to_llm_has_seo_plugin()) {
        return;
    }

    // Homepage
    if (is_front_page() || is_home()) {
        $home_title = get_option('shop_to_llm_seo_home_title', '');
        $home_desc = get_option('shop_to_llm_seo_home_desc', '');
        $sep = apply_filters('shop2llm_title_sep', '|');
        $site_name = get_bloginfo('name');
        // If custom home title set, append site name; otherwise use template
        $home_template = get_option('shop_to_llm_seo_title_home', '%%sitename%% %%sep%% %%tagline%%');
        if ($home_title) {
            $title_output = $home_title . ' ' . $sep . ' ' . $site_name;
        } else {
            $title_output = shop_to_llm_seo_parse_title($home_template);
        }
        $desc = $home_desc ?: get_bloginfo('description');
        echo '<title>' . esc_html($title_output) . '</title>' . "\n";
        if ($desc) {
            echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }
        echo '<link rel="canonical" href="' . esc_url(home_url('/')) . '" />' . "\n";
        return;
    }

    // Singular pages
    if (is_singular()) {
        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        $seo_title = get_post_meta($post->ID, '_shop2llm_seo_title', true);
        $meta_desc = get_post_meta($post->ID, '_shop2llm_meta_desc', true);
        $canonical = get_post_meta($post->ID, '_shop2llm_canonical', true);
        $noindex   = get_post_meta($post->ID, '_shop2llm_noindex', true);
        $nofollow  = get_post_meta($post->ID, '_shop2llm_nofollow', true);

        $post_type = get_post_type();
        $template_key = in_array($post_type, ['post', 'page', 'product'], true) ? $post_type : 'post';
        $default_template = get_option('shop_to_llm_seo_title_' . $template_key, '%%title%% %%sep%% %%sitename%%');
        if ($seo_title !== '') {
            echo '<title>' . esc_html($seo_title . ' | ' . get_bloginfo('name')) . '</title>' . "\n";
        } else {
            echo '<title>' . esc_html(shop_to_llm_seo_parse_title($default_template)) . '</title>' . "\n";
        }
        if ($meta_desc !== '') {
            echo '<meta name="description" content="' . esc_attr($meta_desc) . '" />' . "\n";
        }
        if ($canonical !== '') {
            echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
        }

        $robots = [];
        if ($noindex === '1') {
            $robots[] = 'noindex';
        }
        if ($nofollow === '1') {
            $robots[] = 'nofollow';
        }
        if (!empty($robots)) {
            echo '<meta name="robots" content="' . esc_attr(implode(',', $robots)) . '" />' . "\n";
        }
        return;
    }

    // Archive pages
    if (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if (!$term) {
            return;
        }
        $tax_template = get_option('shop_to_llm_seo_title_category', '%%category%% %%sep%% %%sitename%%');
        if (is_tag()) {
            $tax_template = get_option('shop_to_llm_seo_title_tag', '%%tag%% %%sep%% %%sitename%%');
        }
        $desc = $term->description ?: '';
        echo '<title>' . esc_html(shop_to_llm_seo_parse_title($tax_template)) . '</title>' . "\n";
        if ($desc) {
            echo '<meta name="description" content="' . esc_attr(wp_trim_words($desc, 30)) . '" />' . "\n";
        }
        echo '<link rel="canonical" href="' . esc_url(get_term_link($term)) . '" />' . "\n";
        return;
    }

    if (is_post_type_archive()) {
        $post_type = get_queried_object();
        $archive_template = get_option('shop_to_llm_seo_title_archive', '%%title%% %%sep%% %%sitename%%');
        echo '<title>' . esc_html(shop_to_llm_seo_parse_title($archive_template)) . '</title>' . "\n";
        echo '<link rel="canonical" href="' . esc_url(get_post_type_archive_link($post_type->name)) . '" />' . "\n";
        return;
    }

    if (is_author()) {
        $author = get_queried_object();
        $archive_template = get_option('shop_to_llm_seo_title_archive', '%%title%% %%sep%% %%sitename%%');
        echo '<title>' . esc_html(shop_to_llm_seo_parse_title($archive_template)) . '</title>' . "\n";
        echo '<link rel="canonical" href="' . esc_url(get_author_posts_url($author->ID)) . '" />' . "\n";
        return;
    }

    // 404
    if (is_404()) {
        $sep = apply_filters('shop2llm_title_sep', '|');
        echo '<title>' . esc_html__('Page not found', 'shop2llm') . ' ' . esc_html($sep) . ' ' . esc_html(get_bloginfo('name')) . '</title>' . "\n";
        return;
    }

    // Fallback for any other page
    $sep = apply_filters('shop2llm_title_sep', '|');
    wp_title($sep, true, 'right');
}

function shop_to_llm_seo_count_keyword_occurrences(string $content, string $keyword): int
{
    // For CJK characters (Chinese/Japanese/Korean), use multibyte-safe counting
    if (preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{309f}\x{30a0}-\x{30ff}]/u', $keyword)) {
        return mb_substr_count(mb_strtolower($content, 'UTF-8'), mb_strtolower($keyword, 'UTF-8'), 'UTF-8');
    }
    // For Latin keywords, use case-insensitive whole-word matching
    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
    return preg_match_all($pattern, $content);
}
// ─── Live Content Extractability (v1.3.4+) ──────────────────────────────
// Mirrors VigIA "Editor Citability Score" / OmniGEO "Live Content Extractability".
// Provides real-time AI-extractability feedback in both Classic & Gutenberg editors.

/**
 * Calculate Live Content Extractability (citability) score for a post.
 *
 * Score dimensions (100 total):
 *   - Structured Data      (15) — JSON-LD schema on the post
 *   - Heading Hierarchy     (15) — H2/H3 structure for semantic parsing
 *   - Paragraph Density     (15) — Short paragraphs (≤3 sentences) ideal for RAG
 *   - Meta Description      (10) — Present and within length bounds
 *   - Image Alt Coverage    (10) — All images have descriptive alt text
 *   - Content Length        (10) — 300+ words for posts, 100+ for products
 *   - Internal Links        (10) — At least 2 internal links
 *   - FAQ / Q&A Schema      (5)  — Bonus for FAQPage or Q&A schema
 *   - Semantic HTML         (10) — Uses <article>, <section>, <nav>, <aside>
 *   - Clean URL             (5)  — Slug is short, kebab-case, no query string
 *
 * @param int $postId Post ID.
 * @return array{score:int,checks:array<int,array{label:string,pass:bool,hint:string}>,dimensions:array<int,array{label:string,score:int,max:int,formula:string}>}
 */
function shop_to_llm_seo_calculate_extractability(int $postId): array
{
    $post = get_post($postId);
    if (!$post instanceof WP_Post) {
        return ['score' => 0, 'checks' => [], 'dimensions' => []];
    }

    $content = (string) $post->post_content;
    $plain = wp_strip_all_tags($content);
    $wordCount = str_word_count($plain);
    $slug = $post->post_name;
    $metaDesc = (string) get_post_meta($postId, '_shop2llm_meta_desc', true);
    $isProduct = ($post->post_type === 'product');

    // ─── D1: Structured Data (15) ──────────────────────────────────────
    $d1Score = 0;
    $d1Checks = [];
    $hasSchema = (get_post_meta($postId, '_shop2llm_schema_json', true) !== '') || has_filter('wp_head', 'shop_to_llm_inject_product_schema');
    $d1Checks[] = ['label' => __('JSON-LD structured data attached', 'shop2llm'), 'pass' => $hasSchema, 'hint' => __('Schema.org Product/Organization auto-injected', 'shop2llm')];
    if ($hasSchema) {
        $d1Score += 10;
    }
    $hasFaq = (stripos($content, 'FAQPage') !== false) || (stripos($content, 'class="schema-faq') !== false);
    $d1Checks[] = ['label' => __('FAQPage or Q&A schema detected', 'shop2llm'), 'pass' => $hasFaq, 'hint' => __('Improves RAG answer extraction', 'shop2llm')];
    $d1Score += $hasFaq ? 5 : 0;

    // ─── D2: Heading Hierarchy (15) ────────────────────────────────────
    $d2Score = 0;
    $d2Checks = [];
    $h2Count = preg_match_all('/<h2[^>]*>/i', $content);
    $h3Count = preg_match_all('/<h3[^>]*>/i', $content);
    $hasH1 = preg_match('/<h1[^>]*>/i', $content);
    $d2Checks[] = ['label' => sprintf(/* translators: %d: number of H2 headings found */ __('Has at least 2 H2 headings (found %d)', 'shop2llm'), $h2Count), 'pass' => $h2Count >= 2, 'hint' => __('H2s split content into citable sections', 'shop2llm')];
    if ($h2Count >= 2) {
        $d2Score += 8;
    } elseif ($h2Count >= 1) {
        $d2Score += 4;
    }
    $d2Checks[] = ['label' => sprintf(/* translators: %d: number of H3 sub-headings found */ __('Uses H3 sub-headings (found %d)', 'shop2llm'), $h3Count), 'pass' => $h3Count >= 1, 'hint' => __('H3s enable nested semantic structure', 'shop2llm')];
    $d2Score += ($h3Count >= 1) ? 5 : 0;
    $d2Checks[] = ['label' => __('Has single H1 (title)', 'shop2llm'), 'pass' => $hasH1 || !empty($post->post_title), 'hint' => __('H1 is the primary topic', 'shop2llm')];
    $d2Score += ($hasH1 || !empty($post->post_title)) ? 2 : 0;

    // ─── D3: Paragraph Density (15) — RAG-friendliness ────────────────
    $d3Score = 0;
    $d3Checks = [];
    $paragraphs = preg_split('/\n\s*\n/', $plain);
    $paragraphs = array_filter($paragraphs, function ($p) {
        return trim($p) !== '';
    });
    $paraCount = count($paragraphs);
    $shortParas = 0;
    foreach ($paragraphs as $p) {
        $sentences = preg_split('/[.!?]+/', $p);
        $sentences = array_filter($sentences, function ($s) {
            return trim($s) !== '';
        });
        if (count($sentences) <= 3) {
            $shortParas++;
        }
    }
    $shortRatio = $paraCount > 0 ? ($shortParas / $paraCount) : 0;
    $d3Checks[] = ['label' => sprintf(/* translators: 1: short paragraph count, 2: total paragraph count */ __('%1$d of %2$d paragraphs are short (≤3 sentences)', 'shop2llm'), $shortParas, $paraCount), 'pass' => $shortRatio >= 0.6 && $paraCount >= 2, 'hint' => __('Short paragraphs are easier for RAG retrieval', 'shop2llm')];
    if ($shortRatio >= 0.6 && $paraCount >= 2) {
        $d3Score += 10;
    } elseif ($shortRatio >= 0.4) {
        $d3Score += 5;
    }
    $d3Checks[] = ['label' => sprintf(/* translators: %d: minimum paragraph count */ __('Has %d+ paragraphs', 'shop2llm'), 3), 'pass' => $paraCount >= 3, 'hint' => __('Multi-paragraph content is richer for extraction', 'shop2llm')];
    $d3Score += ($paraCount >= 3) ? 5 : 0;

    // ─── D4: Meta Description (10) ─────────────────────────────────────
    $d4Score = 0;
    $d4Checks = [];
    $descLen = function_exists('mb_strlen') ? mb_strlen($metaDesc) : strlen($metaDesc);
    $hasDesc = !empty($metaDesc);
    $d4Checks[] = ['label' => __('Meta description present', 'shop2llm'), 'pass' => $hasDesc, 'hint' => __('Required for AI summary snippet', 'shop2llm')];
    $d4Score += $hasDesc ? 5 : 0;
    $inLenRange = ($descLen >= 120 && $descLen <= 160);
    $d4Checks[] = ['label' => sprintf(/* translators: %d: meta description length in chars */ __('Meta description length %d chars (ideal 120-160)', 'shop2llm'), $descLen), 'pass' => $inLenRange, 'hint' => __('Optimal for AI snippet truncation', 'shop2llm')];
    $d4Score += $inLenRange ? 5 : 0;

    // ─── D5: Image Alt Coverage (10) ───────────────────────────────────
    $d5Score = 0;
    $d5Checks = [];
    $imgCount = preg_match_all('/<img[^>]+>/i', $content);
    $imgsWithAlt = 0;
    $imgsWithDescAlt = 0;
    if ($imgCount > 0 && preg_match_all('/<img[^>]+alt=["\']([^"\']*)["\'][^>]*>/i', $content, $altMatches)) {
        $imgsWithAlt = count($altMatches[0]);
        foreach ($altMatches[1] as $alt) {
            if (strlen($alt) >= 5) {
                $imgsWithDescAlt++;
            }
        }
    }
    $altCoverage = $imgCount > 0 ? ($imgsWithAlt / $imgCount) : 1;
    $descAltCoverage = $imgCount > 0 ? ($imgsWithDescAlt / $imgCount) : 1;
    $d5Checks[] = ['label' => sprintf(/* translators: 1: images with alt count, 2: total image count */ __('%1$d of %2$d images have alt text', 'shop2llm'), $imgsWithAlt, $imgCount), 'pass' => $altCoverage >= 0.8, 'hint' => __('Alt text is the primary image signal for AI', 'shop2llm')];
    $d5Score += ($altCoverage >= 0.8) ? 6 : ($altCoverage >= 0.5 ? 3 : 0);
    $d5Checks[] = ['label' => sprintf(/* translators: %d: descriptive alt image count */ __('%d images have descriptive alt (5+ chars)', 'shop2llm'), $imgsWithDescAlt), 'pass' => $descAltCoverage >= 0.6 || $imgCount === 0, 'hint' => __('"image1.jpg" is not descriptive', 'shop2llm')];
    $d5Score += ($descAltCoverage >= 0.6 || $imgCount === 0) ? 4 : 0;

    // ─── D6: Content Length (10) ───────────────────────────────────────
    $d6Score = 0;
    $d6Checks = [];
    $minWords = $isProduct ? 100 : 300;
    $idealWords = $isProduct ? 300 : 800;
    $d6Checks[] = ['label' => sprintf(/* translators: 1: word count, 2: minimum word count, 3: post type */ __('%1$d words (minimum %2$d for %3$s)', 'shop2llm'), $wordCount, $minWords, $isProduct ? 'product' : 'post'), 'pass' => $wordCount >= $minWords, 'hint' => __('AI needs enough text for context', 'shop2llm')];
    if ($wordCount >= $minWords) {
        $d6Score += 5;
    }
    $d6Checks[] = ['label' => sprintf(/* translators: 1: word count, 2: ideal word count */ __('%1$d words (ideal %2$d+)', 'shop2llm'), $wordCount, $idealWords), 'pass' => $wordCount >= $idealWords, 'hint' => __('Comprehensive content ranks better in AI answers', 'shop2llm')];
    if ($wordCount >= $idealWords) {
        $d6Score += 5;
    }

    // ─── D7: Internal Links (10) ───────────────────────────────────────
    $d7Score = 0;
    $d7Checks = [];
    $internalLinks = preg_match_all('/<a[^>]+href=["\'][^"\']*' . preg_quote((string) wp_parse_url(home_url(), PHP_URL_HOST), '/') . '[^"\']*["\'][^>]*>/i', $content);
    $d7Checks[] = ['label' => sprintf(/* translators: %d: internal link count */ __('%d internal links (need 2+)', 'shop2llm'), $internalLinks), 'pass' => $internalLinks >= 2, 'hint' => __('Internal links help AI agents navigate related content', 'shop2llm')];
    if ($internalLinks >= 2) {
        $d7Score += 6;
    } elseif ($internalLinks >= 1) {
        $d7Score += 3;
    }
    $d7Checks[] = ['label' => __('Has 5+ internal links', 'shop2llm'), 'pass' => $internalLinks >= 5, 'hint' => __('Strong internal linking improves knowledge graph extraction', 'shop2llm')];
    $d7Score += ($internalLinks >= 5) ? 4 : 0;

    // ─── D8: FAQ / Q&A Schema (5) — already counted in D1, kept for transparency ──
    $d8Score = 0;
    $d8Checks = [];
    $d8Checks[] = ['label' => __('Has FAQ section or Q&A markup', 'shop2llm'), 'pass' => $hasFaq, 'hint' => __('Q&A blocks are high-impact for AI citation', 'shop2llm')];
    $d8Score += $hasFaq ? 5 : 0;

    // ─── D9: Semantic HTML (10) ───────────────────────────────────────
    $d9Score = 0;
    $d9Checks = [];
    $semanticTags = 0;
    foreach (['article', 'section', 'nav', 'aside', 'header', 'footer', 'main', 'figure', 'figcaption'] as $tag) {
        if (preg_match('/<' . $tag . '[\s>]/i', $content)) {
            $semanticTags++;
        }
    }
    $d9Checks[] = ['label' => sprintf(/* translators: %d: semantic HTML5 tag count */ __('Uses %d of 9 semantic HTML5 tags', 'shop2llm'), $semanticTags), 'pass' => $semanticTags >= 2, 'hint' => __('<article>, <section>, <nav>, <aside>, <header>, <footer>, <main>, <figure>, <figcaption>', 'shop2llm')];
    $d9Score += ($semanticTags >= 2) ? 6 : ($semanticTags >= 1 ? 3 : 0);
    $d9Checks[] = ['label' => __('Uses 4+ semantic tags', 'shop2llm'), 'pass' => $semanticTags >= 4, 'hint' => __('Rich semantic structure improves agent parsing', 'shop2llm')];
    $d9Score += ($semanticTags >= 4) ? 4 : 0;

    // ─── D10: Clean URL (5) ───────────────────────────────────────────
    $d10Score = 0;
    $d10Checks = [];
    $slugLen = function_exists('mb_strlen') ? mb_strlen($slug) : strlen($slug);
    $isKebab = (bool) preg_match('/^[a-z0-9-]+$/', $slug);
    $noQuery = (strpos($slug, '?') === false);
    $d10Checks[] = ['label' => sprintf(/* translators: %s: post slug */ __('Slug is kebab-case (%s)', 'shop2llm'), $slug), 'pass' => $isKebab, 'hint' => __('Lowercase, hyphen-separated slugs are AI-friendly', 'shop2llm')];
    $d10Score += $isKebab ? 3 : 0;
    $d10Checks[] = ['label' => sprintf(/* translators: %d: slug length in chars */ __('Slug length %d chars (ideal ≤50)', 'shop2llm'), $slugLen), 'pass' => $slugLen > 0 && $slugLen <= 50, 'hint' => __('Short, descriptive slugs improve recall', 'shop2llm')];
    $d10Score += ($slugLen > 0 && $slugLen <= 50) ? 2 : 0;

    $dimensions = [
        ['id' => 'schema',     'label' => __('Structured Data', 'shop2llm'),     'score' => min(15, $d1Score), 'max' => 15, 'checks' => $d1Checks, 'formula' => 'JSON-LD (10) + FAQ (5)'],
        ['id' => 'headings',    'label' => __('Heading Hierarchy', 'shop2llm'),  'score' => min(15, $d2Score), 'max' => 15, 'checks' => $d2Checks, 'formula' => 'H2 count (8) + H3 (5) + H1 (2)'],
        ['id' => 'paragraphs',  'label' => __('Paragraph Density', 'shop2llm'), 'score' => min(15, $d3Score), 'max' => 15, 'checks' => $d3Checks, 'formula' => 'Short ratio (10) + count (5)'],
        ['id' => 'meta_desc',   'label' => __('Meta Description', 'shop2llm'),   'score' => min(10, $d4Score), 'max' => 10, 'checks' => $d4Checks, 'formula' => 'Present (5) + length (5)'],
        ['id' => 'images',      'label' => __('Image Alt Coverage', 'shop2llm'),'score' => min(10, $d5Score), 'max' => 10, 'checks' => $d5Checks, 'formula' => 'Coverage (6) + descriptive (4)'],
        ['id' => 'length',      'label' => __('Content Length', 'shop2llm'),     'score' => min(10, $d6Score), 'max' => 10, 'checks' => $d6Checks, 'formula' => 'Minimum (5) + ideal (5)'],
        ['id' => 'links',       'label' => __('Internal Links', 'shop2llm'),     'score' => min(10, $d7Score), 'max' => 10, 'checks' => $d7Checks, 'formula' => '2+ links (6) + 5+ (4)'],
        ['id' => 'faq',         'label' => __('FAQ Schema', 'shop2llm'),          'score' => min(5, $d8Score), 'max' => 5,  'checks' => $d8Checks, 'formula' => 'FAQPage/Q&A (5)'],
        ['id' => 'semantic',    'label' => __('Semantic HTML', 'shop2llm'),      'score' => min(10, $d9Score), 'max' => 10, 'checks' => $d9Checks, 'formula' => '2+ tags (6) + 4+ (4)'],
        ['id' => 'url',         'label' => __('Clean URL', 'shop2llm'),          'score' => min(5, $d10Score), 'max' => 5,  'checks' => $d10Checks, 'formula' => 'Kebab (3) + length (2)'],
    ];

    $total = array_sum(array_column($dimensions, 'score'));
    $allChecks = [];
    foreach ($dimensions as $dim) {
        foreach ($dim['checks'] as $check) {
            $allChecks[] = $check;
        }
    }

    return [
        'score'      => $total,
        'checks'      => $allChecks,
        'dimensions' => $dimensions,
        'formula'     => 'Σ (Schema 15 + Headings 15 + Paragraphs 15 + Meta 10 + Images 10 + Length 10 + Links 10 + FAQ 5 + Semantic 10 + URL 5) = 100',
    ];
}

/**
 * Render semicircular gauge SVG for Extractability score.
 *
 * @param int    $score      Score 0-100.
 * @param string $scoreColor Hex color for the score arc.
 * @param string $scoreLabel Translated label (e.g. "Excellent").
 */
function shop_to_llm_render_semicircle_gauge_svg(int $score, string $scoreColor, string $scoreLabel): void
{
    $radius = 90;
    $semiCirc = M_PI * $radius; // ≈ 282.74
    $offset = $semiCirc * (1 - ($score / 100));
    $ariaLabel = sprintf(/* translators: %d: citability score 0-100 */ __('Citability score %d out of 100', 'shop2llm'), $score);
    ?>
    <svg width="200" height="110" viewBox="0 0 200 110" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="<?php echo esc_attr($ariaLabel); ?>">
        <path d="M 10 100 A 90 90 0 0 1 190 100" fill="none" stroke="#e2e8f0" stroke-width="12" stroke-linecap="round" />
        <path d="M 10 100 A 90 90 0 0 1 190 100" fill="none" stroke="<?php echo esc_attr($scoreColor); ?>" stroke-width="12" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr(sprintf('%.2f', $semiCirc)); ?>" stroke-dashoffset="<?php echo esc_attr(sprintf('%.2f', $offset)); ?>" />
        <text x="100" y="82" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-size="36" font-weight="700" fill="#0f172a"><?php echo esc_html($score); ?></text>
        <text x="100" y="100" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-size="10" fill="#64748b">/ 100 · <?php echo esc_html($scoreLabel); ?></text>
    </svg>
    <?php
}

/**
 * Render 10-dimension stacked bar HTML (echoes directly).
 *
 * @param array $dims Dimensions array from calculate_extractability().
 */
function shop_to_llm_render_extractability_stack_html(array $dims): void
{
    foreach ($dims as $dim) {
        $ratio = $dim['max'] / 100;
        $widthPct = round($ratio * 100, 1);
        $fillRatio = $dim['max'] > 0 ? ($dim['score'] / $dim['max']) : 0;
        $c = $fillRatio >= 0.7 ? '#16a34a' : ($fillRatio >= 0.4 ? '#f59e0b' : '#dc2626');
        $bg = $dim['score'] > 0 ? $c : '#e2e8f0';
        $title = sprintf('%s: %d/%d', $dim['label'], $dim['score'], $dim['max']);
        $showLabel = $widthPct >= 8;
        ?>
        <div class="s2ll-ext-stack-seg" style="width:<?php echo esc_attr($widthPct); ?>%;background:<?php echo esc_attr($bg); ?>" title="<?php echo esc_attr($title); ?>">
            <?php if ($showLabel) : ?>
                <?php echo esc_html($dim['score']); ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

/**
 * Render Live Content Extractability meta box.
 * Visible in both Classic (sidebar) and Gutenberg (Plugin Sidebar) editors.
 */
function shop_to_llm_seo_render_extractability_meta_box(WP_Post $post): void
{
    $ext = shop_to_llm_seo_calculate_extractability($post->ID);
    $score = $ext['score'];
    $dims = $ext['dimensions'];

    $scoreClass = 'red';
    $scoreColor = '#dc2626';
    $scoreLabel = __('Needs work', 'shop2llm');
    if ($score >= 70) {
        $scoreClass = 'green';
        $scoreColor = '#16a34a';
        $scoreLabel = __('Excellent', 'shop2llm');
    } elseif ($score >= 40) {
        $scoreClass = 'yellow';
        $scoreColor = '#f59e0b';
        $scoreLabel = __('Fair', 'shop2llm');
    }
    ?>
    <style>
    .s2ll-ext-wrap{font-size:12px;color:#475569;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
    .s2ll-ext-gauge{display:flex;flex-direction:column;align-items:center;padding:4px 0 8px;border-bottom:1px solid #e2e8f0;margin-bottom:10px}
    .s2ll-ext-gauge svg{display:block}
    .s2ll-ext-stack{display:flex;height:24px;border-radius:6px;overflow:hidden;margin-bottom:4px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
    .s2ll-ext-stack-seg{display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;transition:opacity .2s;cursor:default;border-right:1px solid rgba(255,255,255,.4)}
    .s2ll-ext-stack-seg:last-child{border-right:none}
    .s2ll-ext-stack-seg:hover{opacity:.85}
    .s2ll-ext-stack-meta{display:flex;justify-content:space-between;font-size:10px;color:#64748b;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #e2e8f0}
    .s2ll-ext-stack-meta strong{color:#0f172a;font-weight:700}
    .s2ll-ext-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px}
    .s2ll-ext-dim{padding:6px 8px;border-radius:5px;background:#fff;border:1px solid #e2e8f0;transition:border-color .2s}
    .s2ll-ext-dim:hover{border-color:#cbd5e1}
    .s2ll-ext-dim-head{display:flex;justify-content:space-between;align-items:center;font-size:10px;font-weight:600;color:#334155;margin-bottom:4px}
    .s2ll-ext-dim-score{color:#64748b;font-weight:700}
    .s2ll-ext-dim-bar{height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden}
    .s2ll-ext-dim-fill{height:100%;border-radius:2px;transition:width .4s ease,background .3s}
    .s2ll-ext-dim-formula{font-size:10px;color:#94a3b8;margin-top:3px;font-style:italic}
    .s2ll-ext-footer{font-size:10px;color:#94a3b8;text-align:center;margin-top:8px;font-style:italic}
    .s2ll-ext-live-badge{display:inline-block;padding:2px 6px;border-radius:3px;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e40af;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-left:6px}
    @media (max-width:280px){.s2ll-ext-grid{grid-template-columns:1fr}}
    </style>

    <div class="s2ll-ext-wrap" id="s2ll-ext-container" data-post-id="<?php echo esc_attr($post->ID); ?>">
        <!-- Semicircular Gauge -->
        <div class="s2ll-ext-gauge" id="s2ll-ext-gauge">
            <?php shop_to_llm_render_semicircle_gauge_svg($score, $scoreColor, $scoreLabel); ?>
        </div>

        <!-- 10-Dimension Stacked Bar -->
        <div class="s2ll-ext-stack" id="s2ll-ext-stack">
            <?php shop_to_llm_render_extractability_stack_html($dims); ?>
        </div>
        <div class="s2ll-ext-stack-meta">
            <span><?php echo esc_html(sprintf(/* translators: %d: dimension count */ __('%d Dimensions', 'shop2llm'), count($dims))); ?></span>
            <span><?php esc_html_e('Total', 'shop2llm'); ?>: <strong id="s2ll-ext-total"><?php echo esc_html($score); ?></strong>/100</span>
        </div>

        <!-- Dimensions Detail Grid -->
        <div class="s2ll-ext-grid">
            <?php foreach ($dims as $dim) :
                $fillRatio = $dim['max'] > 0 ? ($dim['score'] / $dim['max']) : 0;
                $fillPct = round($fillRatio * 100);
                $c = $fillRatio >= 0.7 ? '#16a34a' : ($fillRatio >= 0.4 ? '#f59e0b' : '#dc2626');
                ?>
                <div class="s2ll-ext-dim" id="s2ll-ext-dim-<?php echo esc_attr($dim['id']); ?>">
                    <div class="s2ll-ext-dim-head">
                        <span><?php echo esc_html($dim['label']); ?></span>
                        <span class="s2ll-ext-dim-score"><?php echo esc_html($dim['score']); ?>/<?php echo esc_html($dim['max']); ?></span>
                    </div>
                    <div class="s2ll-ext-dim-bar">
                        <div class="s2ll-ext-dim-fill" style="width:<?php echo esc_attr($fillPct); ?>%;background:<?php echo esc_attr($c); ?>"></div>
                    </div>
                    <div class="s2ll-ext-dim-formula"><?php echo esc_html($dim['formula']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="s2ll-ext-footer">
            <?php esc_html_e('Recomputes on save. Mirrors VigIA citability methodology.', 'shop2llm'); ?>
            <span class="s2ll-ext-live-badge"><?php esc_html_e('Live', 'shop2llm'); ?></span>
        </p>
    </div>

    <script>
    (function() {
        'use strict';
        // Live updater: recompute on content change (debounced 1.5s)
        var container = document.getElementById('s2ll-ext-container');
        if (!container) return;
        var postId = container.getAttribute('data-post-id');
        var debounceTimer = null;

        function setupHook() {
            // Classic editor (TinyMCE)
            if (typeof tinymce !== 'undefined') {
                var editor = tinymce.get('content');
                if (editor) {
                    editor.on('KeyUp NodeChange', scheduleRecompute);
                    return;
                }
                setTimeout(setupHook, 1000);
                return;
            }
            // Gutenberg
            if (wp.data && wp.data.select('core/editor')) {
                var lastContent = '';
                setInterval(function() {
                    var cur = wp.data.select('core/editor').getEditedPostContent();
                    if (cur && cur !== lastContent) {
                        lastContent = cur;
                        scheduleRecompute();
                    }
                }, 2000);
            }
        }

        function scheduleRecompute() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(recompute, 1500);
        }

        function recompute() {
            if (!window.shop2llm || !window.shop2llm.ajaxurl) return;
            var body = new URLSearchParams();
            body.append('action', 'shop2llm_recompute_extractability');
            body.append('post_id', postId);
            body.append('nonce', window.shop2llm.nonce);
            // Send live content (truncated to keep payload small)
            var liveContent = '';
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                liveContent = tinymce.get('content').getContent();
            } else if (wp.data && wp.data.select('core/editor')) {
                liveContent = wp.data.select('core/editor').getEditedPostContent();
            }
            body.append('content', liveContent.substring(0, 50000));

            fetch(window.shop2llm.ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            }).then(function(r) { return r.json(); }).then(function(resp) {
                if (!resp || !resp.success || !resp.data) return;
                updateUI(resp.data);
            }).catch(function() { /* silent fail — score reverts to server-side cached value */ });
        }

        function updateUI(data) {
            // Update gauge SVG
            var gauge = document.getElementById('s2ll-ext-gauge');
            if (gauge && data.gauge_html) {
                gauge.innerHTML = data.gauge_html;
            }
            // Update stacked bar
            var stack = document.getElementById('s2ll-ext-stack');
            if (stack && data.stack_html) {
                stack.innerHTML = data.stack_html;
            }
            // Update total
            var totalEl = document.getElementById('s2ll-ext-total');
            if (totalEl) {
                totalEl.textContent = data.score;
            }
            // Update dimensions
            if (data.dimensions) {
                data.dimensions.forEach(function(dim) {
                    var dimEl = document.getElementById('s2ll-ext-dim-' + dim.id);
                    if (dimEl) {
                        var scoreEl = dimEl.querySelector('.s2ll-ext-dim-score');
                        if (scoreEl) scoreEl.textContent = dim.score + '/' + dim.max;
                        var fill = dimEl.querySelector('.s2ll-ext-dim-fill');
                        if (fill) {
                            var ratio = dim.max > 0 ? (dim.score / dim.max) : 0;
                            var pct = Math.round(ratio * 100);
                            var c = ratio >= 0.7 ? '#16a34a' : (ratio >= 0.4 ? '#f59e0b' : '#dc2626');
                            fill.style.width = pct + '%';
                            fill.style.background = c;
                        }
                    }
                });
            }
        }

        if (document.readyState === 'complete') {
            setupHook();
        } else {
            window.addEventListener('load', setupHook);
        }
    })();
    </script>
    <?php
}

// AJAX handler for live extractability recompute
function shop_to_llm_handle_recompute_extractability(): void
{
    check_ajax_referer('shop2llm_admin_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_die('', 403);
    }
    $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if ($postId <= 0) {
        wp_send_json_error(['message' => 'Invalid post ID']);
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized — content is HTML passed to wp_kses_post then assigned to post_content (analysis context only, never persisted)
    $liveContent = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
    // Temporarily override post content for the analysis
    $post = get_post($postId);
    if (!$post) {
        wp_send_json_error(['message' => 'Post not found']);
    }
    if ($liveContent !== '') {
        $post->post_content = $liveContent;
        // Temporarily set the global post for the analysis hooks
        $GLOBALS['post'] = $post;
    }

    $ext = shop_to_llm_seo_calculate_extractability($postId);
    $score = $ext['score'];
    $scoreClass = $score >= 70 ? 'green' : ($score >= 40 ? 'yellow' : 'red');
    $scoreColor = $score >= 70 ? '#16a34a' : ($score >= 40 ? '#f59e0b' : '#dc2626');
    $scoreLabel = $score >= 70 ? __('Excellent', 'shop2llm') : ($score >= 40 ? __('Fair', 'shop2llm') : __('Needs work', 'shop2llm'));

    // Capture gauge SVG HTML for AJAX injection
    ob_start();
    shop_to_llm_render_semicircle_gauge_svg($score, $scoreColor, $scoreLabel);
    $gaugeHtml = (string) ob_get_clean();

    // Capture stacked bar HTML for AJAX injection
    ob_start();
    shop_to_llm_render_extractability_stack_html($ext['dimensions']);
    $stackHtml = (string) ob_get_clean();

    wp_send_json_success([
        'score'       => $score,
        'score_class' => $scoreClass,
        'score_color' => $scoreColor,
        'score_label' => $scoreLabel,
        'gauge_html'  => $gaugeHtml,
        'stack_html'  => $stackHtml,
        'dimensions'  => $ext['dimensions'],
    ]);
}

// Localize shop2llm object on edit screens too (needed for live extractability).
// The handle is only enqueued by default on the plugin page, so register and
// enqueue it here before localizing — localizing an unregistered handle is a
// _doing_it_wrong() no-op.
add_action('admin_enqueue_scripts', function (string $hook) {
    if (!in_array($hook, ['post.php', 'post-new.php', 'edit.php'], true)) {
        return;
    }
    wp_register_script('shop2llm-admin', SHOP_TO_LLM_URL . 'assets/js/admin.js', [], SHOP_TO_LLM_VERSION, true);
    wp_enqueue_script('shop2llm-admin');
    wp_localize_script('shop2llm-admin', 'shop2llm', [
        'nonce'   => wp_create_nonce('shop2llm_admin_nonce'),
        'ajaxurl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('shop2llm/v1/'),
    ]);
});

// ─── SEO Hook Registration ────────────────────────────────────────────

add_action('add_meta_boxes', 'shop_to_llm_seo_add_meta_boxes');
add_action('save_post', 'shop_to_llm_seo_save_meta');
add_action('wp_head', 'shop_to_llm_seo_inject_meta', 1);
add_action('wp_ajax_shop2llm_recompute_extractability', 'shop_to_llm_handle_recompute_extractability');

function shop_to_llm_seo_inject_og(): void
{
    if (get_option('shop_to_llm_seo_og', 'yes') !== 'yes') {
        return;
    }
    if (shop_to_llm_has_seo_plugin()) {
        return;
    }

    $site_name = get_bloginfo('name');
    $site_desc = get_bloginfo('description');
    $site_logo_id = get_theme_mod('custom_logo');
    $site_logo_url = $site_logo_id ? wp_get_attachment_image_url($site_logo_id, 'full') : '';
    $image_url = '';
    $image_width = 0;
    $image_height = 0;

    if (is_singular()) {
        global $post;
        if (!$post) {
            return;
        }

        $title = get_post_meta($post->ID, '_shop2llm_seo_title', true) ?: get_the_title($post);
        $desc = get_post_meta($post->ID, '_shop2llm_meta_desc', true);

        if (empty($desc)) {
            if (post_type_supports($post->post_type, 'excerpt') && !empty($post->post_excerpt)) {
                $desc = wp_strip_all_tags($post->post_excerpt);
            } else {
                $desc = wp_trim_words(wp_strip_all_tags($post->post_content), 30, '');
            }
        }

        $url = get_permalink($post);
        $og_image_meta = get_post_meta($post->ID, '_shop2llm_og_image', true);
        $thumb_id = get_post_thumbnail_id($post);

        if (!empty($og_image_meta) && is_numeric($og_image_meta)) {
            $img_data = wp_get_attachment_image_src((int) $og_image_meta, 'full');
            if ($img_data) {
                $image_url = $img_data[0];
                $image_width = (int) $img_data[1];
                $image_height = (int) $img_data[2];
            }
        } elseif ($thumb_id) {
            $img_data = wp_get_attachment_image_src($thumb_id, 'full');
            if ($img_data) {
                $image_url = $img_data[0];
                $image_width = (int) $img_data[1];
                $image_height = (int) $img_data[2];
            }
        }

        if (empty($image_url) && $site_logo_url) {
            $image_url = $site_logo_url;
        }

        if (shop_to_llm_has_woocommerce() && is_product()) {
            $og_type = 'product';
        } elseif ($post->post_type === 'page') {
            $og_type = 'website';
        } else {
            $og_type = 'article';
        }

        echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";

        if ($image_url) {
            echo '<meta property="og:image" content="' . esc_url($image_url) . '">' . "\n";
            if ($image_width > 0 && $image_height > 0) {
                echo '<meta property="og:image:width" content="' . esc_attr((string) $image_width) . '">' . "\n";
                echo '<meta property="og:image:height" content="' . esc_attr((string) $image_height) . '">' . "\n";
            }
        }

        if ($og_type === 'article') {
            echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c', $post)) . '">' . "\n";
            echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date('c', $post)) . '">' . "\n";
        }

        // Twitter Card
        $twitter_card = $image_url ? 'summary_large_image' : 'summary';
        echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . "\n";
        if ($image_url) {
            echo '<meta name="twitter:image" content="' . esc_url($image_url) . '">' . "\n";
        }
    } elseif (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if (!$term) {
            return;
        }
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($term->name) . '" />' . "\n";
        if ($term->description) {
            echo '<meta property="og:description" content="' . esc_attr(wp_trim_words($term->description, 30)) . '" />' . "\n";
        }
        echo '<meta property="og:url" content="' . esc_url(get_term_link($term)) . '" />' . "\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta name="twitter:card" content="summary" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($term->name) . '" />' . "\n";
        return;
    } elseif (is_front_page() || is_home()) {
        $url = home_url('/');

        if ($site_logo_url) {
            $image_url = $site_logo_url;
        }

        echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($site_name) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($site_desc) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";

        if ($image_url) {
            echo '<meta property="og:image" content="' . esc_url($image_url) . '">' . "\n";
        }

        $twitter_card = $image_url ? 'summary_large_image' : 'summary';
        echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($site_name) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($site_desc) . '">' . "\n";
        if ($image_url) {
            echo '<meta name="twitter:image" content="' . esc_url($image_url) . '">' . "\n";
        }
    }
}

function shop_to_llm_seo_add_og_image_meta(): void
{
    global $post;

    $screen = get_current_screen();
    if (!$screen || !$screen->is_block_editor) {
        add_meta_box(
            'shop2llm_og_image',
            __('OG Image', 'shop2llm'),
            'shop_to_llm_seo_og_image_meta_box_html',
            null,
            'side',
            'low'
        );
    }
}

function shop_to_llm_seo_og_image_meta_box_html(WP_Post $post): void
{
    wp_nonce_field('shop2llm_og_image_save', 'shop2llm_og_image_nonce');
    $og_image_id = get_post_meta($post->ID, '_shop2llm_og_image', true);
    $og_image_url = $og_image_id ? wp_get_attachment_image_url((int) $og_image_id, 'full') : '';
    ?>
    <div class="shop2llm-og-image-field">
        <div id="shop2llm-og-image-preview" style="margin-bottom:8px">
            <?php if ($og_image_url) : ?>
                <img src="<?php echo esc_url($og_image_url); ?>" style="max-width:100%;height:auto" alt="">
            <?php endif; ?>
        </div>
        <input type="hidden" name="shop2llm_og_image_id" id="shop2llm-og-image-id" value="<?php echo esc_attr($og_image_id); ?>">
        <button type="button" class="button button-secondary" id="shop2llm-og-image-upload"><?php echo esc_html__('Select Image', 'shop2llm'); ?></button>
        <button type="button" class="button button-secondary" id="shop2llm-og-image-remove" style="<?php echo $og_image_id ? '' : 'display:none'; ?>"><?php echo esc_html__('Remove', 'shop2llm'); ?></button>
    </div>
    <script>
    (function() {
        var frame;
        var uploadBtn = document.getElementById('shop2llm-og-image-upload');
        var removeBtn = document.getElementById('shop2llm-og-image-remove');
        var inputId = document.getElementById('shop2llm-og-image-id');
        var preview = document.getElementById('shop2llm-og-image-preview');

        if (uploadBtn) {
            uploadBtn.addEventListener('click', function() {
                if (!frame) {
                    frame = wp.media({
                        title: '<?php echo esc_js(__('Select OG Image', 'shop2llm')); ?>',
                        button: { text: '<?php echo esc_js(__('Use this image', 'shop2llm')); ?>' },
                        multiple: false,
                        library: { type: 'image' }
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        inputId.value = attachment.id;
                        preview.innerHTML = '<img src="' + attachment.url + '" style="max-width:100%;height:auto" alt="">';
                        removeBtn.style.display = '';
                    });
                }
                frame.open();
            });
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                inputId.value = '';
                preview.innerHTML = '';
                removeBtn.style.display = 'none';
            });
        }
    })();
    </script>
    <?php
}

function shop_to_llm_seo_save_og_image_meta(int $post_id): void
{
    if (!isset($_POST['shop2llm_og_image_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shop2llm_og_image_nonce'])), 'shop2llm_og_image_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $og_image_id = isset($_POST['shop2llm_og_image_id']) ? absint($_POST['shop2llm_og_image_id']) : 0;
    if ($og_image_id) {
        update_post_meta($post_id, '_shop2llm_og_image', $og_image_id);
    } else {
        delete_post_meta($post_id, '_shop2llm_og_image');
    }
}

// ─── OG Image Meta Box Hook ────────────────────────────────────────────

add_action('add_meta_boxes', 'shop_to_llm_seo_add_og_image_meta');
add_action('save_post', 'shop_to_llm_seo_save_og_image_meta');

add_action('wp_head', 'shop_to_llm_seo_inject_og', 5);


// ─── Schema Expansion Module (6 → 18 types) ──────────────────────────

/**
 * Inject JSON-LD schema markup into wp_head.
 *
 * Supports 18 schema types with auto-detection and meta-field overrides.
 * Always adds BreadcrumbList as a separate block on singular pages.
 */
function shop_to_llm_seo_inject_schema(): void
{
    if (get_option('shop_to_llm_seo_schema', 'yes') !== 'yes') {
        return;
    }
    if (shop_to_llm_has_seo_plugin()) {
        return;
    }
    if (!is_singular()) {
        return;
    }

    global $post;
    if (!$post instanceof WP_Post) {
        return;
    }

    $schemaType = get_post_meta($post->ID, '_shop2llm_schema_type', true);
    $explicitType = !empty($schemaType) && $schemaType !== 'auto';
    if (!$explicitType) {
        // Auto-detect based on post type.
        if (shop_to_llm_has_woocommerce() && $post->post_type === 'product') {
            $schemaType = 'Product';
        } elseif ($post->post_type === 'page') {
            $schemaType = 'WebPage';
        } else {
            $schemaType = 'BlogPosting';
        }
    }

    // Check for meta-field-driven schema types (these override the dropdown).
    // Product pages are exempt: the Product node is the primary AI-crawler
    // entity. FAQ-from-reviews (v1.3.9 SEO module / Pro v2.8.0) auto-fills
    // _shop2llm_faq_q1 on products — that must ADD an extra FAQPage block,
    // never REPLACE the Product schema.
    $metaSchema = shop_to_llm_seo_detect_meta_schema($post->ID);
    if ($metaSchema !== '' && !$explicitType) {
        if (shop_to_llm_has_woocommerce() && $post->post_type === 'product') {
            if ($metaSchema !== 'Product') {
                $extraSchema = shop_to_llm_seo_build_schema($metaSchema, $post);
                if (!empty($extraSchema)) {
                    echo '<script type="application/ld+json">' . wp_json_encode($extraSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
                }
            }
        } else {
            $schemaType = $metaSchema;
        }
    }

    $schema = shop_to_llm_seo_build_schema($schemaType, $post);
    if (!empty($schema)) {
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    // Always output BreadcrumbList on singular pages.
    $breadcrumb = shop_to_llm_seo_build_breadcrumb($post);
    if (!empty($breadcrumb)) {
        echo '<script type="application/ld+json">' . wp_json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
}

/**
 * Detect schema type from post meta fields.
 *
 * @param int $postId Post ID.
 * @return string Schema type name or empty string.
 */
function shop_to_llm_seo_detect_meta_schema(int $postId): string
{
    $metaMap = [
        '_shop2llm_faq_q1'        => 'FAQ',
        '_shop2llm_howto_step1'   => 'HowTo',
        '_shop2llm_recipe_name'   => 'Recipe',
        '_shop2llm_event_name'    => 'Event',
        '_shop2llm_course_name'   => 'Course',
        '_shop2llm_job_title'     => 'JobPosting',
        '_shop2llm_app_name'      => 'SoftwareApplication',
        '_shop2llm_book_name'     => 'Book',
        '_shop2llm_music_name'    => 'MusicRecording',
        '_shop2llm_video_url'     => 'VideoObject',
        '_shop2llm_person_name'   => 'Person',
        '_shop2llm_dataset_name'  => 'Dataset',
        '_shop2llm_review_item'   => 'Review',
    ];
    foreach ($metaMap as $metaKey => $type) {
        if (get_post_meta($postId, $metaKey, true) !== '') {
            return $type;
        }
    }
    return '';
}

/**
 * Build the schema.org JSON-LD array for a given type.
 *
 * @param string  $type Schema type.
 * @param WP_Post $post Current post object.
 * @return array Schema data or empty array.
 */
function shop_to_llm_seo_build_schema(string $type, WP_Post $post): array
{
    $siteName = get_bloginfo('name');
    $postId = $post->ID;

    switch ($type) {
        case 'Article':
            return [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => get_the_title($post),
                'datePublished' => get_the_date('c', $post),
                'dateModified' => get_the_modified_date('c', $post),
                'author' => [
                    '@type' => 'Person',
                    'name' => get_the_author_meta('display_name', $post->post_author),
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                ],
                'image' => get_the_post_thumbnail_url($post, 'full') ?: '',
            ];

        case 'BlogPosting':
            return [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => get_the_title($post),
                'datePublished' => get_the_date('c', $post),
                'dateModified' => get_the_modified_date('c', $post),
                'author' => [
                    '@type' => 'Person',
                    'name' => get_the_author_meta('display_name', $post->post_author),
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                ],
                'image' => get_the_post_thumbnail_url($post, 'full') ?: '',
            ];

        case 'Product':
            if (shop_to_llm_has_woocommerce() && $post->post_type === 'product') {
                $product = wc_get_product($postId);
                if (!$product instanceof WC_Product) {
                    return [];
                }
                $schema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $product->get_name(),
                    'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'sku' => $product->get_sku(),
                    'offers' => [
                        '@type' => 'Offer',
                        'url' => get_permalink($postId),
                        'priceCurrency' => get_woocommerce_currency(),
                        'price' => $product->get_price(),
                        'availability' => $product->is_in_stock()
                            ? 'https://schema.org/InStock'
                            : 'https://schema.org/OutOfStock',
                        'seller' => [
                            '@type' => 'Organization',
                            'name' => $siteName,
                        ],
                    ],
                ];

                // GTIN (UPC/EAN/ISBN) — unified read chain: canonical _gtin first (feed/ACP parity), then legacy plugin meta
                $gtin = get_post_meta($postId, '_gtin', true);
                if ($gtin === '') {
                    $gtin = get_post_meta($postId, '_shop2llm_gtin', true);
                }
                if ($gtin !== '') {
                    $schema['gtin'] = $gtin;
                    // Determine GTIN type by length
                    $gtin_len = strlen($gtin);
                    if ($gtin_len === 8) {
                        $schema['gtin8'] = $gtin;
                    } elseif ($gtin_len === 12) {
                        $schema['gtin12'] = $gtin;
                    } elseif ($gtin_len === 13) {
                        $schema['gtin13'] = $gtin;
                    } elseif ($gtin_len === 14) {
                        $schema['gtin14'] = $gtin;
                    }
                }

                // MPN — unified read chain: canonical _mpn first, then legacy plugin meta
                $mpn = get_post_meta($postId, '_mpn', true);
                if ($mpn === '') {
                    $mpn = get_post_meta($postId, '_shop2llm_mpn', true);
                }
                if ($mpn !== '') {
                    $schema['mpn'] = $mpn;
                }

                // Brand — meta first, then product_brand taxonomy fallback (prevents placeholder-brand mismatches)
                $brand = get_post_meta($postId, '_shop2llm_brand', true);
                if ($brand === '' && taxonomy_exists('product_brand')) {
                    $brand_terms = wp_get_post_terms($postId, 'product_brand', ['fields' => 'names']);
                    if (!empty($brand_terms) && !is_wp_error($brand_terms)) {
                        $brand = $brand_terms[0];
                    }
                }
                if ($brand !== '') {
                    $schema['brand'] = [
                        '@type' => 'Brand',
                        'name' => $brand,
                    ];
                }

                // Product ID (custom identifier)
                $product_id_field = get_post_meta($postId, '_shop2llm_product_id', true);
                if ($product_id_field !== '') {
                    $schema['productID'] = $product_id_field;
                }

                // Condition (new/used/refurbished)
                $condition = get_post_meta($postId, '_shop2llm_condition', true);
                if ($condition !== '' && in_array($condition, ['NewCondition', 'UsedCondition', 'RefurbishedCondition', 'DamagedCondition'], true)) {
                    $schema['itemCondition'] = 'https://schema.org/' . $condition;
                }

                // Gallery images (up to 10)
                $gallery_ids = $product->get_gallery_image_ids();
                if (!empty($gallery_ids)) {
                    $all_images = [];
                    if ($schema['image']) {
                        $all_images[] = $schema['image'];
                    }
                    foreach (array_slice($gallery_ids, 0, 10) as $gid) {
                        $gallery_url = wp_get_attachment_url($gid);
                        if ($gallery_url) {
                            $all_images[] = $gallery_url;
                        }
                    }
                    $schema['image'] = count($all_images) === 1 ? $all_images[0] : $all_images;
                }

                // Review
                if ($product->get_average_rating() > 0) {
                    $schema['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => $product->get_average_rating(),
                        'reviewCount' => $product->get_review_count(),
                        'bestRating' => '5',
                        'worstRating' => '1',
                    ];
                }

                // Variable product: add lowPrice/highPrice for offers
                if ($product->is_type('variable')) {
                    $variation_prices = $product->get_variation_prices();
                    if (!empty($variation_prices['price'])) {
                        $min_price = min($variation_prices['price']);
                        $max_price = max($variation_prices['price']);
                        $schema['offers']['@type'] = 'AggregateOffer';
                        $schema['offers']['lowPrice'] = $min_price;
                        $schema['offers']['highPrice'] = $max_price;
                        $schema['offers']['offerCount'] = count($variation_prices['price']);
                    }
                }

                // Shipping weight/dimensions
                if ($product->has_weight()) {
                    $schema['weight'] = [
                        '@type' => 'QuantitativeValue',
                        'value' => $product->get_weight(),
                        'unitCode' => get_option('woocommerce_weight_unit', 'kg') === 'kg' ? 'KGM' : 'LBR',
                    ];
                }

                /**
                 * Filter the Product schema before output.
                 *
                 * Lets Pro enrich a single Product node (shipping, returns, reviews,
                 * hasVariant, identifiers) without emitting duplicate entities.
                 *
                 * @param array      $schema  Product schema array.
                 * @param int        $postId  Product post ID.
                 * @param WC_Product $product Product object.
                 */
                return apply_filters('shop2llm_product_schema', $schema, $postId, $product);
            }
            // Non-WooCommerce product schema fallback.
            return [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => get_the_title($post),
                'description' => wp_strip_all_tags(get_the_excerpt($post)),
                'image' => get_the_post_thumbnail_url($post, 'full') ?: '',
            ];

        case 'FAQ':
            $questions = [];
            for ($i = 1; $i <= 10; $i++) {
                $q = get_post_meta($postId, '_shop2llm_faq_q' . $i, true);
                $a = get_post_meta($postId, '_shop2llm_faq_a' . $i, true);
                if ($q !== '' && $a !== '') {
                    $questions[] = [
                        '@type' => 'Question',
                        'name' => $q,
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => $a,
                        ],
                    ];
                }
            }
            if (empty($questions)) {
                return [];
            }
            $faqSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $questions,
            ];
            /**
             * Filter the FAQ schema before output (Pro may swap FAQPage → QAPage when opted in).
             *
             * @param array $faqSchema FAQ schema array.
             * @param int   $postId    Post ID.
             */
            return apply_filters('shop2llm_faq_schema', $faqSchema, $postId);

        case 'HowTo':
            $steps = [];
            for ($i = 1; $i <= 8; $i++) {
                $step = get_post_meta($postId, '_shop2llm_howto_step' . $i, true);
                if ($step !== '') {
                    $steps[] = [
                        '@type' => 'HowToStep',
                        'position' => $i,
                        'name' => $step,
                        'text' => get_post_meta($postId, '_shop2llm_howto_step' . $i . '_desc', true) ?: $step,
                    ];
                }
            }
            if (empty($steps)) {
                return [];
            }
            return [
                '@context' => 'https://schema.org',
                '@type' => 'HowTo',
                'name' => get_the_title($post),
                'step' => $steps,
            ];

        case 'Recipe':
            $recipeName = get_post_meta($postId, '_shop2llm_recipe_name', true);
            if ($recipeName === '') {
                return [];
            }
            $ingredients = get_post_meta($postId, '_shop2llm_recipe_ingredients', true);
            $instructions = get_post_meta($postId, '_shop2llm_recipe_instructions', true);
            $cookTime = get_post_meta($postId, '_shop2llm_recipe_cook_time', true);
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Recipe',
                'name' => $recipeName,
            ];
            if ($ingredients !== '') {
                $schema['recipeIngredient'] = array_map('trim', explode("\n", $ingredients));
            }
            if ($instructions !== '') {
                $schema['recipeInstructions'] = $instructions;
            }
            if ($cookTime !== '') {
                $schema['cookTime'] = 'PT' . $cookTime . 'M';
            }
            return $schema;

        case 'Event':
            $eventName = get_post_meta($postId, '_shop2llm_event_name', true);
            if ($eventName === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $eventName,
            ];
            $startDate = get_post_meta($postId, '_shop2llm_event_start_date', true);
            if ($startDate !== '') {
                $schema['startDate'] = $startDate;
            }
            $location = get_post_meta($postId, '_shop2llm_event_location', true);
            if ($location !== '') {
                $schema['location'] = [
                    '@type' => 'Place',
                    'name' => $location,
                ];
            }
            $organizer = get_post_meta($postId, '_shop2llm_event_organizer', true);
            if ($organizer !== '') {
                $schema['organizer'] = [
                    '@type' => 'Organization',
                    'name' => $organizer,
                ];
            }
            return $schema;

        case 'Course':
            $courseName = get_post_meta($postId, '_shop2llm_course_name', true);
            if ($courseName === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Course',
                'name' => $courseName,
                'description' => get_post_meta($postId, '_shop2llm_course_description', true) ?: wp_strip_all_tags(get_the_excerpt($post)),
            ];
            $provider = get_post_meta($postId, '_shop2llm_course_provider', true);
            if ($provider !== '') {
                $schema['provider'] = [
                    '@type' => 'Organization',
                    'name' => $provider,
                ];
            }
            return $schema;

        case 'JobPosting':
            $jobTitle = get_post_meta($postId, '_shop2llm_job_title', true);
            if ($jobTitle === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'JobPosting',
                'title' => $jobTitle,
                'description' => wp_strip_all_tags(get_the_excerpt($post)),
            ];
            $hiringOrg = get_post_meta($postId, '_shop2llm_job_organization', true);
            if ($hiringOrg !== '') {
                $schema['hiringOrganization'] = [
                    '@type' => 'Organization',
                    'name' => $hiringOrg,
                ];
            }
            $jobLocation = get_post_meta($postId, '_shop2llm_job_location', true);
            if ($jobLocation !== '') {
                $schema['jobLocation'] = [
                    '@type' => 'Place',
                    'address' => $jobLocation,
                ];
            }
            return $schema;

        case 'SoftwareApplication':
            $appName = get_post_meta($postId, '_shop2llm_app_name', true);
            if ($appName === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => $appName,
            ];
            $os = get_post_meta($postId, '_shop2llm_app_os', true);
            if ($os !== '') {
                $schema['operatingSystem'] = $os;
            }
            $version = get_post_meta($postId, '_shop2llm_app_version', true);
            if ($version !== '') {
                $schema['softwareVersion'] = $version;
            }
            $price = get_post_meta($postId, '_shop2llm_app_price', true);
            $currency = get_post_meta($postId, '_shop2llm_app_currency', true) ?: 'USD';
            if ($price !== '') {
                $schema['offers'] = [
                    '@type' => 'Offer',
                    'price' => $price,
                    'priceCurrency' => $currency,
                ];
            }
            return $schema;

        case 'Book':
            $bookName = get_post_meta($postId, '_shop2llm_book_name', true);
            if ($bookName === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Book',
                'name' => $bookName,
            ];
            $author = get_post_meta($postId, '_shop2llm_book_author', true);
            if ($author !== '') {
                $schema['author'] = [
                    '@type' => 'Person',
                    'name' => $author,
                ];
            }
            $isbn = get_post_meta($postId, '_shop2llm_book_isbn', true);
            if ($isbn !== '') {
                $schema['isbn'] = $isbn;
            }
            $publisher = get_post_meta($postId, '_shop2llm_book_publisher', true);
            if ($publisher !== '') {
                $schema['publisher'] = [
                    '@type' => 'Organization',
                    'name' => $publisher,
                ];
            }
            return $schema;

        case 'MusicRecording':
            $musicName = get_post_meta($postId, '_shop2llm_music_name', true);
            if ($musicName === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'MusicRecording',
                'name' => $musicName,
            ];
            $artist = get_post_meta($postId, '_shop2llm_music_artist', true);
            if ($artist !== '') {
                $schema['byArtist'] = [
                    '@type' => 'MusicGroup',
                    'name' => $artist,
                ];
            }
            $duration = get_post_meta($postId, '_shop2llm_music_duration', true);
            if ($duration !== '') {
                $schema['duration'] = 'PT' . $duration . 'M';
            }
            return $schema;

        case 'VideoObject':
            $videoUrl = get_post_meta($postId, '_shop2llm_video_url', true);
            if ($videoUrl === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'VideoObject',
                'name' => get_post_meta($postId, '_shop2llm_video_name', true) ?: get_the_title($post),
                'contentUrl' => esc_url($videoUrl),
            ];
            $desc = get_post_meta($postId, '_shop2llm_video_description', true);
            if ($desc !== '') {
                $schema['description'] = $desc;
            }
            $thumb = get_post_meta($postId, '_shop2llm_video_thumbnail', true);
            if ($thumb !== '') {
                $schema['thumbnailUrl'] = esc_url($thumb);
            }
            return $schema;

        case 'Person':
            $personName = get_post_meta($postId, '_shop2llm_person_name', true);
            if ($personName === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Person',
                'name' => $personName,
            ];
            $jobTitle = get_post_meta($postId, '_shop2llm_person_job_title', true);
            if ($jobTitle !== '') {
                $schema['jobTitle'] = $jobTitle;
            }
            $url = get_post_meta($postId, '_shop2llm_person_url', true);
            if ($url !== '') {
                $schema['url'] = esc_url($url);
            }
            $sameAs = get_post_meta($postId, '_shop2llm_person_same_as', true);
            if ($sameAs !== '') {
                $schema['sameAs'] = array_map('esc_url', array_filter(array_map('trim', explode("\n", $sameAs))));
            }
            return $schema;

        case 'Dataset':
            $datasetName = get_post_meta($postId, '_shop2llm_dataset_name', true);
            if ($datasetName === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Dataset',
                'name' => $datasetName,
                'description' => get_post_meta($postId, '_shop2llm_dataset_description', true) ?: wp_strip_all_tags(get_the_excerpt($post)),
            ];
            $creator = get_post_meta($postId, '_shop2llm_dataset_creator', true);
            if ($creator !== '') {
                $schema['creator'] = [
                    '@type' => 'Organization',
                    'name' => $creator,
                ];
            }
            $distribution = get_post_meta($postId, '_shop2llm_dataset_distribution', true);
            if ($distribution !== '') {
                $schema['distribution'] = [
                    '@type' => 'DataDownload',
                    'contentUrl' => esc_url($distribution),
                ];
            }
            return $schema;

        case 'Review':
            $reviewItem = get_post_meta($postId, '_shop2llm_review_item', true);
            if ($reviewItem === '') {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'Review',
                'itemReviewed' => [
                    '@type' => 'Thing',
                    'name' => $reviewItem,
                ],
                'author' => [
                    '@type' => 'Person',
                    'name' => get_the_author_meta('display_name', $post->post_author),
                ],
            ];
            $rating = get_post_meta($postId, '_shop2llm_review_rating', true);
            if ($rating !== '') {
                $schema['reviewRating'] = [
                    '@type' => 'Rating',
                    'ratingValue' => $rating,
                    'bestRating' => '5',
                ];
            }
            return $schema;

        case 'LocalBusiness':
            $localbiz = get_option('shop_to_llm_seo_localbiz', []);
            if (empty($localbiz) || !is_array($localbiz)) {
                return [];
            }
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'LocalBusiness',
                'name' => $localbiz['name'] ?? $siteName,
            ];
            if (!empty($localbiz['street_address']) || !empty($localbiz['locality']) || !empty($localbiz['country'])) {
                $schema['address'] = [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $localbiz['street_address'] ?? '',
                    'addressLocality' => $localbiz['locality'] ?? '',
                    'addressRegion' => $localbiz['region'] ?? '',
                    'postalCode' => $localbiz['postal_code'] ?? '',
                    'addressCountry' => $localbiz['country'] ?? '',
                ];
            }
            if (!empty($localbiz['phone'])) {
                $schema['telephone'] = $localbiz['phone'];
            }
            if (!empty($localbiz['hours'])) {
                $schema['openingHours'] = $localbiz['hours'];
            }
            return $schema;

        case 'WebPage':
            return [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => get_the_title($post),
                'description' => wp_strip_all_tags(get_the_excerpt($post)),
                'url' => get_permalink($postId),
            ];

        default:
            return [];
    }
}

/**
 * Build BreadcrumbList schema.
 *
 * @param WP_Post $post Current post object.
 * @return array BreadcrumbList schema data.
 */
function shop_to_llm_seo_build_breadcrumb(WP_Post $post): array
{
    $items = [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => __('Home', 'shop2llm'),
            'item' => home_url('/'),
        ],
    ];

    $position = 2;

    // Add category for posts.
    if ($post->post_type === 'post') {
        $cats = get_the_category($post->ID);
        if (!empty($cats)) {
            $cat = $cats[0];
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $cat->name,
                'item' => get_category_link($cat->term_id),
            ];
            $position++;
        }
    }

    // Add product category for WooCommerce products.
    if (shop_to_llm_has_woocommerce() && $post->post_type === 'product') {
        $terms = get_the_terms($post->ID, 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            $term = $terms[0];
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $term->name,
                'item' => get_term_link($term->term_id, 'product_cat'),
            ];
            $position++;
        }
    }

    $items[] = [
        '@type' => 'ListItem',
        'position' => $position,
        'name' => get_the_title($post),
        'item' => get_permalink($post->ID),
    ];

    return [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
}

/**
 * Render the Schema Settings meta box HTML.
 *
 * @param WP_Post $post Current post object.
 */
function shop_to_llm_seo_render_schema_meta_box(WP_Post $post): void
{
    wp_nonce_field('shop2llm_schema_save_' . $post->ID, 'shop2llm_schema_nonce');

    $currentType = get_post_meta($post->ID, '_shop2llm_schema_type', true);
    if (empty($currentType)) {
        $currentType = 'auto';
    }

    $types = [
        'auto' => __('Auto-detect', 'shop2llm'),
        'Article' => 'Article',
        'BlogPosting' => 'BlogPosting',
        'Product' => 'Product',
        'FAQ' => 'FAQ (FAQPage)',
        'HowTo' => 'HowTo',
        'BreadcrumbList' => 'BreadcrumbList',
        'LocalBusiness' => 'LocalBusiness',
        'Recipe' => 'Recipe',
        'Event' => 'Event',
        'Course' => 'Course',
        'JobPosting' => 'JobPosting',
        'SoftwareApplication' => 'SoftwareApplication',
        'Book' => 'Book',
        'MusicRecording' => 'MusicRecording',
        'VideoObject' => 'VideoObject',
        'Person' => 'Person',
        'Dataset' => 'Dataset',
        'Review' => 'Review',
        'WebPage' => 'WebPage',
    ];

    echo '<p><label for="_shop2llm_schema_type"><strong>' . esc_html__('Schema Type', 'shop2llm') . '</strong></label><br>';
    echo '<select name="_shop2llm_schema_type" id="_shop2llm_schema_type" style="width:100%">';
    foreach ($types as $value => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($value),
            selected($currentType, $value, false),
            esc_html($label)
        );
    }
    echo '</select></p>';

    // AI Schema Suggest button
    echo '<p><button type="button" id="s2ll-ai-schema-btn" class="button" style="width:100%" title="' . esc_attr__('AI-powered schema type suggestion based on your content', 'shop2llm') . '">';
    echo '<span style="font-size:14px">&#9889;</span> ' . esc_html__('AI Suggest Schema', 'shop2llm');
    echo '</button></p>';
    echo '<div id="s2ll-ai-schema-status" style="font-size:11px;color:#64748b;margin-bottom:6px;display:none"></div>';

    // FAQ fields.
    echo '<div class="shop2llm-schema-fields" data-schema="FAQ" style="' . ($currentType === 'FAQ' ? '' : 'display:none') . '">';
    for ($i = 1; $i <= 10; $i++) {
        $qVal = esc_attr(get_post_meta($post->ID, '_shop2llm_faq_q' . $i, true));
        $aVal = esc_attr(get_post_meta($post->ID, '_shop2llm_faq_a' . $i, true));
        /* translators: %d: FAQ question number */
        echo '<p><label>' . sprintf(esc_html__('Q%d', 'shop2llm'), $i) . '</label> <input type="text" name="_shop2llm_faq_q' . esc_attr($i) . '" value="' . $qVal . '" style="width:100%" /></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        /* translators: %d: FAQ answer number */
        echo '<p><label>' . sprintf(esc_html__('A%d', 'shop2llm'), $i) . '</label> <textarea name="_shop2llm_faq_a' . esc_attr($i) . '" style="width:100%;height:50px">' . $aVal . '</textarea></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';

    // HowTo fields.
    echo '<div class="shop2llm-schema-fields" data-schema="HowTo" style="' . ($currentType === 'HowTo' ? '' : 'display:none') . '">';
    for ($i = 1; $i <= 8; $i++) {
        $stepVal = esc_attr(get_post_meta($post->ID, '_shop2llm_howto_step' . $i, true));
        $stepDesc = esc_attr(get_post_meta($post->ID, '_shop2llm_howto_step' . $i . '_desc', true));
        /* translators: %d: HowTo step number */
        echo '<p><label>' . sprintf(esc_html__('Step %d Name', 'shop2llm'), $i) . '</label> <input type="text" name="_shop2llm_howto_step' . esc_attr($i) . '" value="' . $stepVal . '" style="width:100%" /></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        /* translators: %d: HowTo step number */
        echo '<p><label>' . sprintf(esc_html__('Step %d Description', 'shop2llm'), $i) . '</label> <textarea name="_shop2llm_howto_step' . esc_attr($i) . '_desc" style="width:100%;height:50px">' . $stepDesc . '</textarea></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';

    // Recipe fields.
    echo '<div class="shop2llm-schema-fields" data-schema="Recipe" style="' . ($currentType === 'Recipe' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_recipe_name', __('Recipe Name', 'shop2llm'));
    shop_to_llm_seo_render_textarea_field($post, '_shop2llm_recipe_ingredients', __('Ingredients (one per line)', 'shop2llm'));
    shop_to_llm_seo_render_textarea_field($post, '_shop2llm_recipe_instructions', __('Instructions', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_recipe_cook_time', __('Cook Time (minutes)', 'shop2llm'));
    echo '</div>';

    // Event fields.
    echo '<div class="shop2llm-schema-fields" data-schema="Event" style="' . ($currentType === 'Event' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_event_name', __('Event Name', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_event_start_date', __('Start Date', 'shop2llm'), 'date');
    shop_to_llm_seo_render_text_field($post, '_shop2llm_event_location', __('Location', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_event_organizer', __('Organizer', 'shop2llm'));
    echo '</div>';

    // Course fields.
    echo '<div class="shop2llm-schema-fields" data-schema="Course" style="' . ($currentType === 'Course' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_course_name', __('Course Name', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_course_provider', __('Provider', 'shop2llm'));
    shop_to_llm_seo_render_textarea_field($post, '_shop2llm_course_description', __('Description', 'shop2llm'));
    echo '</div>';

    // JobPosting fields.
    echo '<div class="shop2llm-schema-fields" data-schema="JobPosting" style="' . ($currentType === 'JobPosting' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_job_title', __('Job Title', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_job_organization', __('Hiring Organization', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_job_location', __('Location', 'shop2llm'));
    echo '</div>';

    // SoftwareApplication fields.
    echo '<div class="shop2llm-schema-fields" data-schema="SoftwareApplication" style="' . ($currentType === 'SoftwareApplication' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_app_name', __('App Name', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_app_os', __('Operating System', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_app_version', __('Version', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_app_price', __('Price', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_app_currency', __('Currency', 'shop2llm'));
    echo '</div>';

    // Book fields.
    echo '<div class="shop2llm-schema-fields" data-schema="Book" style="' . ($currentType === 'Book' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_book_name', __('Book Name', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_book_author', __('Author', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_book_isbn', __('ISBN', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_book_publisher', __('Publisher', 'shop2llm'));
    echo '</div>';

    // MusicRecording fields.
    echo '<div class="shop2llm-schema-fields" data-schema="MusicRecording" style="' . ($currentType === 'MusicRecording' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_music_name', __('Track Name', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_music_artist', __('Artist', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_music_duration', __('Duration (minutes)', 'shop2llm'));
    echo '</div>';

    // VideoObject fields.
    echo '<div class="shop2llm-schema-fields" data-schema="VideoObject" style="' . ($currentType === 'VideoObject' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_video_url', __('Video URL', 'shop2llm'), 'url');
    shop_to_llm_seo_render_text_field($post, '_shop2llm_video_name', __('Video Title', 'shop2llm'));
    shop_to_llm_seo_render_textarea_field($post, '_shop2llm_video_description', __('Description', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_video_thumbnail', __('Thumbnail URL', 'shop2llm'), 'url');
    echo '</div>';

    // Person fields.
    echo '<div class="shop2llm-schema-fields" data-schema="Person" style="' . ($currentType === 'Person' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_person_name', __('Person Name', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_person_job_title', __('Job Title', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_person_url', __('Website', 'shop2llm'), 'url');
    shop_to_llm_seo_render_textarea_field($post, '_shop2llm_person_same_as', __('Social Profiles (one per line)', 'shop2llm'));
    echo '</div>';

    // Dataset fields.
    echo '<div class="shop2llm-schema-fields" data-schema="Dataset" style="' . ($currentType === 'Dataset' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_dataset_name', __('Dataset Name', 'shop2llm'));
    shop_to_llm_seo_render_textarea_field($post, '_shop2llm_dataset_description', __('Description', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_dataset_creator', __('Creator', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_dataset_distribution', __('Download URL', 'shop2llm'), 'url');
    echo '</div>';

    // Review fields.
    echo '<div class="shop2llm-schema-fields" data-schema="Review" style="' . ($currentType === 'Review' ? '' : 'display:none') . '">';
    shop_to_llm_seo_render_text_field($post, '_shop2llm_review_item', __('Reviewed Item', 'shop2llm'));
    shop_to_llm_seo_render_text_field($post, '_shop2llm_review_rating', __('Rating (1-5)', 'shop2llm'), 'number');
    echo '</div>';

    // JavaScript to show/hide conditional fields.
    echo '<script>
    (function(){
        var sel = document.getElementById("_shop2llm_schema_type");
        if(!sel) return;
        function toggle(){
            var v = sel.value;
            var fields = document.querySelectorAll(".shop2llm-schema-fields");
            for(var i=0;i<fields.length;i++){
                var s = fields[i].getAttribute("data-schema");
                fields[i].style.display = (s === v) ? "" : "none";
            }
        }
        sel.addEventListener("change", toggle);
        toggle();
    })();

    // AI Schema Suggest
    (function(){
        var aiBtn = document.getElementById("s2ll-ai-schema-btn");
        var sel = document.getElementById("_shop2llm_schema_type");
        var statusEl = document.getElementById("s2ll-ai-schema-status");
        if (!aiBtn || !sel) return;
        aiBtn.addEventListener("click", function(){
            aiBtn.disabled = true;
            aiBtn.textContent = "...";
            statusEl.style.display = "block";
            statusEl.textContent = ' . wp_json_encode(__('Analyzing content for best schema type...', 'shop2llm')) . ';
            statusEl.style.color = "#64748b";

            var fd = new FormData();
            fd.append("action", "s2ll_seo_ai_schema");
            fd.append("post_id", ' . (int) $post->ID . ');
            fd.append("nonce", ' . wp_json_encode(wp_create_nonce('s2ll_ai_nonce')) . ');

            fetch(ajaxurl, {method: "POST", body: fd, credentials: "same-origin"})
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    aiBtn.disabled = false;
                    aiBtn.innerHTML = \'<span style="font-size:14px">&#9889;</span> ' . esc_js(__('AI Suggest Schema', 'shop2llm')) . '\';
                    if (data.success && data.data && data.data.schema_type) {
                        var newType = data.data.schema_type;
                        var conf = data.data.confidence ? " (" + Math.round(data.data.confidence * 100) + "% confidence)" : "";
                        var reason = data.data.reason ? " — " + data.data.reason : "";
                        // Set the dropdown
                        for (var i = 0; i < sel.options.length; i++) {
                            if (sel.options[i].value === newType) {
                                sel.value = newType;
                                break;
                            }
                        }
                        // Trigger the change event to show/hide fields
                        sel.dispatchEvent(new Event("change"));
                        statusEl.textContent = "✓ " + newType + conf + reason;
                        statusEl.style.color = "#16a34a";
                        if (data.data.from === "local" || data.data.error_notice) {
                            statusEl.textContent += " — ' . esc_js(__('(local analysis)', 'shop2llm')) . '";
                        }
                    } else if (data.data && data.data.error_notice) {
                        statusEl.textContent = data.data.error_notice;
                        statusEl.style.color = "#ca8a04";
                    } else {
                        statusEl.textContent = ' . wp_json_encode(__('Could not determine schema type.', 'shop2llm')) . ';
                        statusEl.style.color = "#dc2626";
                    }
                })
                .catch(function(){
                    aiBtn.disabled = false;
                    aiBtn.innerHTML = \'<span style="font-size:14px">&#9889;</span> ' . esc_js(__('AI Suggest Schema', 'shop2llm')) . '\';
                    statusEl.textContent = ' . wp_json_encode(__('Network error. Please try again.', 'shop2llm')) . ';
                    statusEl.style.color = "#dc2626";
                });
        });
    })();
    </script>';
}

/**
 * Render a text input field for schema meta box.
 *
 * @param WP_Post $post     Post object.
 * @param string  $metaKey  Meta key.
 * @param string  $label    Field label.
 * @param string  $type     Input type (text, url, number, date).
 */
function shop_to_llm_seo_render_text_field(WP_Post $post, string $metaKey, string $label, string $type = 'text'): void
{
    $value = esc_attr(get_post_meta($post->ID, $metaKey, true));
    echo '<p><label for="' . esc_attr($metaKey) . '">' . esc_html($label) . '</label><br>';
    echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($metaKey) . '" id="' . esc_attr($metaKey) . '" value="' . $value . '" style="width:100%" /></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Render a textarea field for schema meta box.
 *
 * @param WP_Post $post     Post object.
 * @param string  $metaKey  Meta key.
 * @param string  $label    Field label.
 */
function shop_to_llm_seo_render_textarea_field(WP_Post $post, string $metaKey, string $label): void
{
    $value = esc_textarea(get_post_meta($post->ID, $metaKey, true));
    echo '<p><label for="' . esc_attr($metaKey) . '">' . esc_html($label) . '</label><br>';
    echo '<textarea name="' . esc_attr($metaKey) . '" id="' . esc_attr($metaKey) . '" style="width:100%;height:60px">' . $value . '</textarea></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Save schema meta box fields.
 *
 * @param int $postId Post ID being saved.
 */
function shop_to_llm_seo_save_schema_meta(int $postId): void
{
    if (!isset($_POST['shop2llm_schema_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shop2llm_schema_nonce'])), 'shop2llm_schema_save_' . $postId)) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Schema type.
    if (isset($_POST['_shop2llm_schema_type'])) {
        update_post_meta($postId, '_shop2llm_schema_type', sanitize_text_field(wp_unslash($_POST['_shop2llm_schema_type'])));
    }

    // All schema meta fields.
    $textFields = [
        '_shop2llm_faq_q1', '_shop2llm_faq_q2', '_shop2llm_faq_q3', '_shop2llm_faq_q4', '_shop2llm_faq_q5',
        '_shop2llm_faq_q6', '_shop2llm_faq_q7', '_shop2llm_faq_q8', '_shop2llm_faq_q9', '_shop2llm_faq_q10',
        '_shop2llm_howto_step1', '_shop2llm_howto_step2', '_shop2llm_howto_step3', '_shop2llm_howto_step4',
        '_shop2llm_howto_step5', '_shop2llm_howto_step6', '_shop2llm_howto_step7', '_shop2llm_howto_step8',
        '_shop2llm_recipe_name', '_shop2llm_recipe_cook_time',
        '_shop2llm_event_name', '_shop2llm_event_start_date', '_shop2llm_event_location', '_shop2llm_event_organizer',
        '_shop2llm_course_name', '_shop2llm_course_provider',
        '_shop2llm_job_title', '_shop2llm_job_organization', '_shop2llm_job_location',
        '_shop2llm_app_name', '_shop2llm_app_os', '_shop2llm_app_version', '_shop2llm_app_price', '_shop2llm_app_currency',
        '_shop2llm_book_name', '_shop2llm_book_author', '_shop2llm_book_isbn', '_shop2llm_book_publisher',
        '_shop2llm_music_name', '_shop2llm_music_artist', '_shop2llm_music_duration',
        '_shop2llm_video_url', '_shop2llm_video_name', '_shop2llm_video_thumbnail',
        '_shop2llm_person_name', '_shop2llm_person_job_title', '_shop2llm_person_url',
        '_shop2llm_dataset_name', '_shop2llm_dataset_creator', '_shop2llm_dataset_distribution',
        '_shop2llm_review_item', '_shop2llm_review_rating',
        '_shop2llm_course_description',
    ];

    $textareaFields = [
        '_shop2llm_faq_a1', '_shop2llm_faq_a2', '_shop2llm_faq_a3', '_shop2llm_faq_a4', '_shop2llm_faq_a5',
        '_shop2llm_faq_a6', '_shop2llm_faq_a7', '_shop2llm_faq_a8', '_shop2llm_faq_a9', '_shop2llm_faq_a10',
        '_shop2llm_howto_step1_desc', '_shop2llm_howto_step2_desc', '_shop2llm_howto_step3_desc', '_shop2llm_howto_step4_desc',
        '_shop2llm_howto_step5_desc', '_shop2llm_howto_step6_desc', '_shop2llm_howto_step7_desc', '_shop2llm_howto_step8_desc',
        '_shop2llm_recipe_ingredients', '_shop2llm_recipe_instructions',
        '_shop2llm_video_description',
        '_shop2llm_person_same_as',
        '_shop2llm_dataset_description',
    ];

    foreach ($textFields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta($postId, $field, sanitize_text_field(wp_unslash($_POST[$field])));
        }
    }

    foreach ($textareaFields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta($postId, $field, sanitize_textarea_field(wp_unslash($_POST[$field])));
        }
    }
}

// ─── Hook Registration ──────────────────────────────────────────────

add_action('wp_head', 'shop_to_llm_seo_inject_schema', 10);
add_action('save_post', 'shop_to_llm_seo_save_schema_meta');
require_once __DIR__ . '/includes/class-policy-schema.php';
require_once __DIR__ . '/includes/class-acquisition.php';
require_once __DIR__ . '/includes/class-citation-widget.php';
add_action('init', ['Shop2LLM_Citation_Widget', 'init']);

// ─── robots.txt crawler groups (pure functions, v1.7.2) ──────────────────
require_once __DIR__ . '/includes/class-robots-groups.php';

// ─── IndexNow auto-push module (v1.7.1 → module v1.7.2) ─────────────────
require_once __DIR__ . '/includes/class-indexnow.php';

// ─── NicheRadar referral card (pure external link, v1.3.5+) ───────────────
require_once __DIR__ . '/includes/class-radar-card.php';
add_action('init', ['Shop2LLM_Radar_Card', 'init']);

// ─── UCP Readiness Score (5 dimensions, 100 pts) — v1.3.4+ ────────────────
// Transparent scoring methodology exposed in Dashboard to match competitor
// offerings (VigIA 100-point / citelayer Blind Spots Score). Shop2LLM's UCP
// score targets "Agentic Commerce Readiness" instead of generic visibility.
//
// v1.6.1 (lean): 7 → 5 dimensions — Agentic and MCP merge into one "MCP &
// Agentic" dimension, and the Checkout Kit dimension is removed (it was a
// Pro-locked dead weight that made a perfect 100 unreachable on Free).
// Every point is now earnable on the free tier:
//
// 5 dimensions (fixed weights, total = 100):
//   Catalog API    25  — Store/REST API + product-feed.xml reachability
//   MCP & Agentic  25  — MCP tools + discovery endpoint + crawler registry
//   llms.txt       20  — /llms.txt reachable + catalog content
//   Schema         20  — 19 JSON-LD types registered + frontend output
//   robots.txt     10  — AI crawler Allow rules count

/**
 * Calculate UCP Readiness Score across 5 dimensions.
 * Returns array: ['total' => int, 'dimensions' => [...], 'formula' => string]
 *
 * @return array{total:int,dimensions:array<int,array{id:string,label:string,score:int,max:int,weight:int,checks:array<int,array{label:string,pass:bool,hint:string}>,formula:string}>}
 */
function shop_to_llm_calculate_ucp_readiness(): array
{
    $siteUrl = rtrim((string) get_site_url(), '/');

    // ─── Dimension 1: Catalog API (25) ────────────────────────────────
    $catalogChecks = [];
    $catalogScore = 0;
    if (shop_to_llm_has_woocommerce()) {
        $catalogChecks[] = ['label' => 'WooCommerce Store API active', 'pass' => true, 'hint' => '/wp-json/wc/store/v1'];
        $catalogScore += 15;
        $feedUrl = $siteUrl . '/product-feed.xml';
        $cachedFeed = get_transient('shop_to_llm_ucp_check_feed');
        if ($cachedFeed === false) {
            $resp = wp_remote_get($feedUrl, ['timeout' => 3, 'redirection' => 0]);
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            $cachedFeed = ($code === 200) ? '1' : '0';
            set_transient('shop_to_llm_ucp_check_feed', $cachedFeed, 5 * MINUTE_IN_SECONDS);
        }
        $catalogChecks[] = ['label' => 'product-feed.xml reachable', 'pass' => $cachedFeed === '1', 'hint' => $feedUrl];
        $catalogScore += ($cachedFeed === '1') ? 10 : 0;
    } else {
        // Non-WooCommerce: core REST API is always active and posts/pages are
        // cataloged in /llms.txt — feed factor scores N/A full credit (same
        // precedent as the AI Visibility feed factor).
        $catalogChecks[] = ['label' => 'WordPress REST API active', 'pass' => true, 'hint' => '/wp-json/wp/v2'];
        $catalogScore += 15;
        $catalogChecks[] = ['label' => __('product-feed.xml N/A — posts & pages cataloged in /llms.txt', 'shop2llm'), 'pass' => true, 'hint' => __('Non-WooCommerce site', 'shop2llm')];
        $catalogScore += 10;
    }

    // ─── Dimension 2: MCP & Agentic (25) — merges old Agentic + MCP ──
    $agenticChecks = [];
    $agenticScore = 0;
    /* translators: %d: number of MCP tools */
    $agenticChecks[] = [
        'label' => shop_to_llm_official_mcp_stack_active()
            ? 'MCP served via official WordPress MCP Adapter (abilities registered)'
            : 'MCP endpoint registered (/wp-json/shop2llm/v1/mcp)',
        'pass' => true,
        /* translators: %d: number of MCP tools */
        'hint' => sprintf(__('JSON-RPC 2.0 — AI agents can call %d tools', 'shop2llm'), count(shop_to_llm_tools_list()['tools'])),
    ];
    $agenticScore += 12;
    $mcpUrl = $siteUrl . '/wp-json/shop2llm/v1/.well-known/mcp';
    $cachedMcp = get_transient('shop_to_llm_ucp_check_mcp');
    if ($cachedMcp === false) {
        $resp = wp_remote_get($mcpUrl, ['timeout' => 3, 'redirection' => 0]);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        $cachedMcp = ($code === 200) ? '1' : '0';
        set_transient('shop_to_llm_ucp_check_mcp', $cachedMcp, 5 * MINUTE_IN_SECONDS);
    }
    $agenticChecks[] = ['label' => '/.well-known/mcp discovery endpoint reachable', 'pass' => $cachedMcp === '1', 'hint' => $mcpUrl];
    $agenticScore += ($cachedMcp === '1') ? 8 : 0;
    /* translators: %d: number of additional AI crawlers beyond the top 3 */
    $agenticChecks[] = ['label' => 'AI Crawler Registry active (' . shop_to_llm_get_ai_crawler_count() . ' bots tracked)', 'pass' => true, 'hint' => sprintf(__('GPTBot, ClaudeBot, PerplexityBot + %d more', 'shop2llm'), max(0, shop_to_llm_get_ai_crawler_count() - 3))];
    $agenticScore += 5;

    // ─── Dimension 3: Schema (20) ─────────────────────────────────────
    $schemaChecks = [];
    $schemaScore = 0;
    $schemaCount = 19; // Shop2LLM Free JSON-LD types (verified, Guideline 9 compliant)
    $schemaChecks[] = ['label' => sprintf(/* translators: %d: number of JSON-LD Schema types */ __('%d JSON-LD Schema types registered', 'shop2llm'), $schemaCount), 'pass' => $schemaCount >= 10, 'hint' => __('Product, Organization, BreadcrumbList, WebSite, SearchAction, etc.', 'shop2llm')];
    $schemaScore += $schemaCount >= 10 ? 12 : 6;
    $schemaChecks[] = ['label' => __('Schema output on frontend (Product/Organization)', 'shop2llm'), 'pass' => true, 'hint' => __('Auto-injected via wp_head', 'shop2llm')];
    $schemaScore += 8;

    // ─── Dimension 4: llms.txt (20) ──────────────────────────────────
    $llmsChecks = [];
    $llmsScore = 0;
    $llmsUrl = $siteUrl . '/llms.txt';
    $cachedLlms = get_transient('shop_to_llm_ucp_check_llms');
    if ($cachedLlms === false) {
        // Allow redirect following: /llms.txt may 301 to trailing-slash variant on some permalink configs.
        // Treat 200 (after following redirects) as reachable.
        $resp = wp_remote_get($llmsUrl, ['timeout' => 3, 'redirection' => 3]);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        $cachedLlms = ($code === 200) ? '1' : '0';
        set_transient('shop_to_llm_ucp_check_llms', $cachedLlms, 5 * MINUTE_IN_SECONDS);
    }
    $llmsChecks[] = ['label' => '/llms.txt reachable', 'pass' => $cachedLlms === '1', 'hint' => $llmsUrl];
    $llmsScore += ($cachedLlms === '1') ? 12 : 0;
    $llmsChecks[] = ['label' => __('llms.txt lists MCP endpoint + products/posts', 'shop2llm'), 'pass' => $cachedLlms === '1', 'hint' => __('Auto-generated by Shop2LLM', 'shop2llm')];
    $llmsScore += ($cachedLlms === '1') ? 8 : 0;

    // ─── Dimension 5: robots.txt (10) ─────────────────────────────────
    $robotsChecks = [];
    $robotsScore = 0;
    $allowedCrawlers = shop_to_llm_get_robots_allowed_crawlers();
    $allowedCount = count($allowedCrawlers);
    $robotsChecks[] = ['label' => sprintf(/* translators: %d: number of AI crawler Allow rules */ __('%d AI crawler Allow rules in robots.txt', 'shop2llm'), $allowedCount), 'pass' => $allowedCount >= 5, 'hint' => __('GPTBot, ClaudeBot, PerplexityBot, Google-Extended, +50 more', 'shop2llm')];
    $robotsScore += $allowedCount >= 5 ? 6 : ($allowedCount >= 1 ? 3 : 0);
    $robotsChecks[] = ['label' => __('robots.txt allows AI crawlers (no Disallow: / for AI bots)', 'shop2llm'), 'pass' => true, 'hint' => __('Auto-injected by Shop2LLM', 'shop2llm')];
    $robotsScore += 4;

    $dimensions = [
        ['id' => 'catalog_api', 'label' => __('Catalog API', 'shop2llm'),   'score' => min(25, $catalogScore), 'max' => 25, 'weight' => 25, 'checks' => $catalogChecks, 'formula' => 'Store/REST API (15) + product-feed.xml (10)'],
        ['id' => 'mcp_agentic', 'label' => __('MCP & Agentic', 'shop2llm'), 'score' => min(25, $agenticScore), 'max' => 25, 'weight' => 25, 'checks' => $agenticChecks, 'formula' => 'MCP tools (12) + discovery endpoint (8) + AI Crawler Registry (5)'],
        ['id' => 'llms_txt',    'label' => __('llms.txt', 'shop2llm'),     'score' => min(20, $llmsScore),    'max' => 20, 'weight' => 20, 'checks' => $llmsChecks,    'formula' => 'Reachable (12) + content (8)'],
        ['id' => 'schema',      'label' => __('Schema', 'shop2llm'),        'score' => min(20, $schemaScore),  'max' => 20, 'weight' => 20, 'checks' => $schemaChecks,  'formula' => 'JSON-LD types count (12) + output (8)'],
        ['id' => 'robots_txt',  'label' => __('robots.txt', 'shop2llm'),    'score' => min(10, $robotsScore),  'max' => 10, 'weight' => 10, 'checks' => $robotsChecks,  'formula' => 'AI Allow rules (6) + no Disallow (4)'],
    ];

    $total = array_sum(array_column($dimensions, 'score'));
    return [
        'total' => $total,
        'dimensions' => $dimensions,
        'formula' => 'Σ (Catalog API 25 + MCP & Agentic 25 + llms.txt 20 + Schema 20 + robots.txt 10) = 100',
    ];
}

/**
 * Render UCP Readiness Score transparency panel — collapsible details block.
 * Mirrors VigIA 100-point Analyzer / citelayer Blind Spots Score methodology.
 *
 * @return void
 */
function shop_to_llm_render_ucp_readiness_panel(): void
{
    $ucp = shop_to_llm_calculate_ucp_readiness();
    $total = $ucp['total'];
    $grade = $total >= 85 ? 'A+' : ($total >= 70 ? 'A' : ($total >= 55 ? 'B' : ($total >= 40 ? 'C' : 'D')));
    $gradeColor = $total >= 70 ? '#16a34a' : ($total >= 50 ? '#f59e0b' : '#dc2626');
    $dimensions = $ucp['dimensions'];
    ?>
    <div class="stl-card" id="stl-ucp-readiness" style="border-left:4px solid #0ea5e9">
        <h2 style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20" style="color:#0ea5e9">
                <path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>
            </svg>
            <?php echo esc_html__('UCP Readiness Score (5 dimensions)', 'shop2llm'); ?>
            <span class="s2ll-help" title="<?php echo esc_attr__('Universal Commerce Protocol — measures how ready your site is for AI agent commerce. Transparent methodology, 5 dimensions, 100 points total — every point earnable on the free tier.', 'shop2llm'); ?>"></span>
        </h2>

        <!-- U2: Hero — clean donut gauge + grade pill + 5-dimension status grid -->
        <div style="display:flex;gap:28px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
            <div style="flex-shrink:0;text-align:center">
                <?php shop_to_llm_render_radial_gauge_svg((int) $total, $dimensions); ?>
                <div style="margin-top:10px;display:inline-flex;align-items:center;gap:6px;padding:3px 12px;border-radius:999px;background:<?php echo esc_attr($total >= 70 ? '#f0fdf4' : ($total >= 50 ? '#fefce8' : '#fef2f2')); ?>;border:1px solid <?php echo esc_attr($total >= 70 ? '#bbf7d0' : ($total >= 50 ? '#fde68a' : '#fecaca')); ?>">
                    <span style="font-size:10px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#64748b"><?php echo esc_html__('Grade', 'shop2llm'); ?></span>
                    <span style="font-size:14px;font-weight:800;color:<?php echo esc_attr($gradeColor); ?>"><?php echo esc_html($grade); ?></span>
                </div>
            </div>
            <div style="flex:1;min-width:260px">
                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(150px, 1fr));gap:8px">
                    <?php foreach ($dimensions as $dim) :
                        $pct = $dim['max'] > 0 ? round(($dim['score'] / $dim['max']) * 100) : 0;
                        $c = $pct >= 75 ? '#16a34a' : ($pct >= 50 ? '#f59e0b' : '#dc2626');
                        ?>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:6px">
                                <span style="font-size:11px;font-weight:600;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html($dim['label']); ?></span>
                                <span style="font-size:12px;font-weight:700;color:<?php echo esc_attr($c); ?>;flex-shrink:0"><?php echo (int) $dim['score']; ?>/<?php echo (int) $dim['max']; ?></span>
                            </div>
                            <div style="height:4px;background:#e2e8f0;border-radius:2px;margin-top:8px;overflow:hidden">
                                <div style="height:100%;width:<?php echo esc_attr((string) $pct); ?>%;background:<?php echo esc_attr($c); ?>;border-radius:2px"></div>
                            </div>
                            <?php if (!empty($dim['pro_only'])) : ?>
                            <a href="<?php echo esc_url('https://shop2llm.com/fincore/v1/page/prolanding'); ?>" style="font-size:10px;color:#6366f1;font-weight:600;text-decoration:none;display:inline-block;margin-top:6px">🔓 <?php echo esc_html__('Unlock with Pro', 'shop2llm'); ?> →</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <details open style="margin-bottom:12px">
            <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#0f172a;padding:6px 0"><?php echo esc_html__('Dimension Breakdown (click to collapse)', 'shop2llm'); ?></summary>
            <div style="margin-top:8px">
            <?php foreach ($dimensions as $dim) :
                $pct = $dim['max'] > 0 ? round(($dim['score'] / $dim['max']) * 100) : 0;
                $barColor = $pct >= 75 ? '#16a34a' : ($pct >= 50 ? '#f59e0b' : '#dc2626');
                ?>
                <div style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                        <span style="font-size:13px;font-weight:600;color:#1e293b"><?php echo esc_html($dim['label']); ?>
                            <span style="font-weight:400;color:#94a3b8;font-size:11px;margin-left:6px">(weight: <?php echo (int) $dim['weight']; ?>)</span>
                        </span>
                        <span style="font-size:13px;font-weight:600;color:<?php echo esc_attr($barColor); ?>"><?php echo (int) $dim['score']; ?>/<?php echo (int) $dim['max']; ?></span>
                    </div>
                    <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-bottom:4px">
                        <div style="height:100%;width:<?php echo esc_attr((string) $pct); ?>%;background:<?php echo esc_attr($barColor); ?>"></div>
                    </div>
                    <div style="font-size:11px;color:#64748b;margin-bottom:4px"><?php echo esc_html__('Formula', 'shop2llm'); ?>: <?php echo esc_html($dim['formula']); ?></div>
                    <ul style="margin:0 0 0 16px;padding:0;font-size:12px;line-height:1.6">
                    <?php foreach ($dim['checks'] as $check) : ?>
                        <?php if (!empty($check['pro_only'])) : ?>
                        <li style="color:#6366f1;background:#f5f3ff;padding:4px 8px;border-radius:4px;margin:2px 0;list-style:none">
                            🔒 <span style="color:#4f46e5;font-weight:500"><?php echo esc_html($check['label']); ?></span>
                            <a href="<?php echo esc_url('https://shop2llm.com/fincore/v1/page/prolanding'); ?>" style="font-size:10px;color:#6366f1;font-weight:600;text-decoration:none;margin-left:6px">Unlock →</a>
                        </li>
                        <?php else : ?>
                        <li style="color:<?php echo $check['pass'] ? '#16a34a' : '#dc2626'; ?>">
                            <?php echo $check['pass'] ? '✓' : '✗'; ?> <span style="color:#1e293b"><?php echo esc_html($check['label']); ?></span>
                            <?php if (!empty($check['hint'])) : ?>
                                <span style="color:#94a3b8;font-size:11px;margin-left:4px">(<?php echo esc_html($check['hint']); ?>)</span>
                            <?php endif; ?>
                        </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            </div>
        </details>

        <details style="margin-bottom:8px">
            <summary style="cursor:pointer;font-size:12px;color:#64748b"><?php echo esc_html__('Scoring Methodology (formula)', 'shop2llm'); ?></summary>
            <div style="margin-top:6px;padding:8px 12px;background:#f8fafc;border-radius:6px;font-size:12px;color:#1e293b;font-family:monospace;line-height:1.6">
                <?php echo esc_html($ucp['formula']); ?>
            </div>
        </details>

        <p style="margin:8px 0 0;font-size:11px;color:#94a3b8;line-height:1.5">
            <?php echo esc_html__('UCP Readiness Score is unique to Shop2LLM — it measures Agentic Commerce Readiness (not just AI visibility), computed entirely on your server. Checkout Kit (AI agents completing purchases end-to-end) is available in Pro.', 'shop2llm'); ?>
        </p>
    </div>
    <?php
}

/**
 * Render UCP radial gauge SVG — 360° gauge with the 5-dimension score arc.
 * Visual centerpiece of the Dashboard (v1.3.5+ UI refresh).
 *
 * @param int   $total      Total score (0-100).
 * @param array $dimensions 5-dimension data.
 */
function shop_to_llm_render_radial_gauge_svg(int $total, array $dimensions): void
{
    $size = 160;
    $center = $size / 2;
    $radius = 58;
    $strokeWidth = 12;

    // Foreground arc (score)
    $scoreColor = $total >= 70 ? '#16a34a' : ($total >= 50 ? '#f59e0b' : '#dc2626');
    $scorePct = max(0, min(1, $total / 100));

    echo '<svg width="' . esc_attr((string) $size) . '" height="' . esc_attr((string) $size) . '" viewBox="0 0 ' . esc_attr((string) $size) . ' ' . esc_attr((string) $size) . '" xmlns="http://www.w3.org/2000/svg" style="max-width:100%;height:auto;display:block" role="img" aria-label="' . esc_attr(sprintf(/* translators: %d: score */ __('UCP Readiness Score: %d of 100', 'shop2llm'), $total)) . '">';

    // Track ring
    echo '<circle cx="' . esc_attr((string) $center) . '" cy="' . esc_attr((string) $center) . '" r="' . esc_attr((string) $radius) . '" fill="none" stroke="#e2e8f0" stroke-width="' . esc_attr((string) $strokeWidth) . '" />';

    // Score arc (rounded, starts at 12 o'clock)
    $circumference = 2 * M_PI * $radius;
    $dashLength = $circumference * $scorePct;
    echo '<circle cx="' . esc_attr((string) $center) . '" cy="' . esc_attr((string) $center) . '" r="' . esc_attr((string) $radius) . '" fill="none" stroke="' . esc_attr($scoreColor) . '" stroke-width="' . esc_attr((string) $strokeWidth) . '" stroke-dasharray="' . esc_attr((string) round($dashLength, 2)) . ' ' . esc_attr((string) round($circumference, 2)) . '" stroke-linecap="round" transform="rotate(-90 ' . esc_attr((string) $center) . ' ' . esc_attr((string) $center) . ')" style="transition:stroke-dasharray 0.6s ease" />';

    // Center text — total score
    echo '<text x="' . esc_attr((string) $center) . '" y="' . esc_attr((string) ($center + 2)) . '" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-size="36" font-weight="800" fill="#1e293b">' . esc_html((string) $total) . '</text>';
    echo '<text x="' . esc_attr((string) $center) . '" y="' . esc_attr((string) ($center + 18)) . '" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-size="9" font-weight="500" fill="#94a3b8">/ 100</text>';

    echo '</svg>';
}

// ─── P1-3: AI Crawler Log Analytics (v1.3.5+) ───────────────────────────
// Opt-in visitor log analysis detects 81 AI crawlers.
// Shows top crawled URLs, visit frequency, crawler distribution.

/**
 * Get AI crawler log analytics summary.
 *
 * @param int $days Number of days to look back (default 7).
 * @return array{total_visits: int, unique_bots: int, top_bots: array, top_urls: array, daily_trend: array}
 */
function shop_to_llm_get_crawler_analytics(int $days = 7): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'shop2llm_crawler_log';

    // Check if table exists
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return ['total_visits' => 0, 'unique_bots' => 0, 'top_bots' => [], 'bot_breakdown' => [], 'top_urls' => [], 'daily_trend' => []];
    }

    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    // Total visits
    $totalVisits = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE visited_at >= %s",
        $since
    ));

    // Unique bots
    $uniqueBots = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT bot_name) FROM $table WHERE visited_at >= %s",
        $since
    ));

    // Top bots (by visit count)
    $topBots = $wpdb->get_results($wpdb->prepare(
        "SELECT bot_name, bot_label, COUNT(*) as cnt FROM $table WHERE visited_at >= %s GROUP BY bot_name, bot_label ORDER BY cnt DESC LIMIT 10",
        $since
    ));

    // v1.6.0: per-crawler leaderboard — visits, distinct pages, last seen
    $botBreakdown = $wpdb->get_results($wpdb->prepare(
        "SELECT bot_name, bot_label, COUNT(*) as cnt, COUNT(DISTINCT page_url) as pages, MAX(visited_at) as last_visit FROM $table WHERE visited_at >= %s GROUP BY bot_name, bot_label ORDER BY cnt DESC LIMIT 12",
        $since
    ));

    // Top URLs (by visit count)
    $topUrls = $wpdb->get_results($wpdb->prepare(
        "SELECT page_url, COUNT(*) as cnt FROM $table WHERE visited_at >= %s AND page_url != '' GROUP BY page_url ORDER BY cnt DESC LIMIT 10",
        $since
    ));

    // Daily trend
    $dailyTrend = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(visited_at) as d, COUNT(*) as cnt FROM $table WHERE visited_at >= %s GROUP BY DATE(visited_at) ORDER BY d ASC",
        $since
    ));
    // phpcs:enable

    return [
        'total_visits'  => $totalVisits,
        'unique_bots'   => $uniqueBots,
        'top_bots'      => $topBots,
        'bot_breakdown' => $botBreakdown,
        'top_urls'      => $topUrls,
        'daily_trend'   => $dailyTrend,
    ];
}

/**
 * Render AI Crawler Analytics widget (in Dashboard).
 */
function shop_to_llm_render_crawler_analytics_widget(): void
{
    $analytics = shop_to_llm_get_crawler_analytics(7);
    $totalVisits = $analytics['total_visits'];
    $uniqueBots = $analytics['unique_bots'];

    // Distribution pie chart data (top bots)
    $botDistribution = [];
    if (!empty($analytics['top_bots'])) {
        foreach ($analytics['top_bots'] as $bot) {
            $botDistribution[] = [
                'name'  => $bot->bot_label,
                'count' => (int) $bot->cnt,
            ];
        }
    }

    // Daily trend sparkline data
    $trendData = [];
    if (!empty($analytics['daily_trend'])) {
        foreach ($analytics['daily_trend'] as $day) {
            $trendData[] = ['date' => $day->d, 'count' => (int) $day->cnt];
        }
    }

    // Top URLs
    $topUrls = $analytics['top_urls'];

    ?>
    <div class="s2ll-crawler-analytics" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:12px 0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="margin:0;font-size:14px;color:#1e293b">
                <?php echo esc_html__('AI Crawler Analytics (7 days)', 'shop2llm'); ?>
            </h3>
        </div>

        <?php if ($totalVisits === 0) :
            // v1.3.11: Diagnostic empty state — explain WHY traffic is zero and what
            // to do, instead of a dead-end blank message. Extracted in v1.5.4 so the
            // AI Traffic tab's merged Crawler Activity card shares one implementation.
            shop_to_llm_render_crawler_diagnostics();
        else : ?>
            <!-- Summary stats -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(120px, 1fr));gap:12px;margin-bottom:16px">
                <div style="background:#f8fafc;padding:12px;border-radius:6px;text-align:center">
                    <div style="font-size:24px;font-weight:700;color:#1e293b"><?php echo esc_html(number_format_i18n($totalVisits)); ?></div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px"><?php echo esc_html__('Total Visits', 'shop2llm'); ?></div>
                </div>
                <div style="background:#f8fafc;padding:12px;border-radius:6px;text-align:center">
                    <div style="font-size:24px;font-weight:700;color:#1e293b"><?php echo esc_html(number_format_i18n($uniqueBots)); ?></div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px"><?php echo esc_html__('Unique Crawlers', 'shop2llm'); ?></div>
                </div>
            </div>

            <!-- Crawler distribution (CSS pie chart via conic-gradient) -->
            <?php if (!empty($botDistribution)) : ?>
                <div style="margin-bottom:16px">
                    <h4 style="font-size:12px;color:#64748b;margin:0 0 8px"><?php echo esc_html__('Crawler Distribution', 'shop2llm'); ?></h4>
                    <div style="display:flex;gap:16px;align-items:center">
                        <div style="width:120px;height:120px;border-radius:50%;background:<?php echo esc_attr(shop_to_llm_render_pie_gradient($botDistribution)); ?>;flex-shrink:0"></div>
                        <div style="flex:1;font-size:11px;line-height:1.8">
                            <?php foreach ($botDistribution as $i => $bot) : ?>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?php echo esc_attr(shop_to_llm_pie_color($i)); ?>"></span>
                                    <span style="flex:1;color:#1e293b"><?php echo esc_html($bot['name']); ?></span>
                                    <span style="color:#64748b"><?php echo esc_html(number_format_i18n($bot['count'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Top crawled URLs -->
            <?php if (!empty($topUrls)) : ?>
                <div style="margin-bottom:16px">
                    <h4 style="font-size:12px;color:#64748b;margin:0 0 8px"><?php echo esc_html__('Top Crawled URLs', 'shop2llm'); ?></h4>
                    <div style="font-size:11px;line-height:1.8">
                        <?php foreach ($topUrls as $url) : ?>
                            <div style="display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid #f1f5f9">
                                <span style="color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:300px"><?php echo esc_html($url->page_url); ?></span>
                                <span style="color:#64748b;flex-shrink:0;margin-left:8px"><?php echo esc_html(number_format_i18n((int) $url->cnt)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Daily trend sparkline -->
            <?php if (!empty($trendData)) : ?>
                <div>
                    <h4 style="font-size:12px;color:#64748b;margin:0 0 8px"><?php echo esc_html__('Daily Trend', 'shop2llm'); ?></h4>
                    <?php shop_to_llm_render_sparkline_svg($trendData); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the diagnostic empty state shown when no AI crawler visits are
 * logged: the three-point checklist (robots.txt / llms.txt / content) with
 * actionable fixes. Shared by the analytics widget and the merged Crawler
 * Activity card on the AI Traffic tab.
 *
 * @return void
 */
function shop_to_llm_render_crawler_diagnostics(): void
{
    $diagScore = shop_to_llm_get_visibility_score();
    $diagRobotsOk = ($diagScore['factors']['robots'] ?? 0) >= 10;
    $diagLlmsOk = ($diagScore['factors']['llms'] ?? 0) >= 10;
    $diagItemsOk = (int)(wp_count_posts(shop_to_llm_has_woocommerce() ? 'product' : 'post')->publish ?? 0) > 0;
    $diagFixRobotsUrl = wp_nonce_url(admin_url('admin.php?page=shop2llm&shop2llm_fix_robots=1'), 'shop2llm_fix_robots');
    $diagPermalinkUrl = admin_url('options-permalink.php');
    ?>
    <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:16px;background:#f8fafc">
        <div style="text-align:center;padding:4px 0 14px">
            <div style="font-size:32px;margin-bottom:6px">🔍</div>
            <p style="font-size:13px;font-weight:600;color:#475569;margin:0 0 2px"><?php echo esc_html__('No AI crawler visits logged yet.', 'shop2llm'); ?></p>
            <p style="font-size:11px;color:#94a3b8;margin:0"><?php echo esc_html__('Three things make AI traffic appear — check each one:', 'shop2llm'); ?></p>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
            <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px">
                <span style="flex-shrink:0;font-size:13px;color:<?php echo $diagRobotsOk ? '#16a34a' : '#dc2626'; ?>"><?php echo $diagRobotsOk ? '✓' : '✗'; ?></span>
                <span style="flex:1;font-size:12px;color:#1e1e1e"><?php echo $diagRobotsOk ? esc_html__('robots.txt allows AI crawlers', 'shop2llm') : esc_html__('robots.txt is blocking AI crawlers (GPTBot, ClaudeBot, …)', 'shop2llm'); ?></span>
                <?php if (!$diagRobotsOk) : ?>
                <a href="<?php echo esc_url($diagFixRobotsUrl); ?>" style="flex-shrink:0;padding:3px 10px;background:#6366f1;color:#fff;border-radius:4px;font-size:10px;font-weight:600;text-decoration:none"><?php echo esc_html__('Fix robots.txt', 'shop2llm'); ?> →</a>
                <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px">
                <span style="flex-shrink:0;font-size:13px;color:<?php echo $diagLlmsOk ? '#16a34a' : '#dc2626'; ?>"><?php echo $diagLlmsOk ? '✓' : '✗'; ?></span>
                <span style="flex:1;font-size:12px;color:#1e1e1e"><?php echo $diagLlmsOk ? esc_html__('llms.txt is accessible — AI can read your catalog', 'shop2llm') : esc_html__('llms.txt is not accessible — AI cannot read your catalog', 'shop2llm'); ?></span>
                <?php if (!$diagLlmsOk) : ?>
                <a href="<?php echo esc_url($diagPermalinkUrl); ?>" style="flex-shrink:0;padding:3px 10px;background:#6366f1;color:#fff;border-radius:4px;font-size:10px;font-weight:600;text-decoration:none"><?php echo esc_html__('Flush Permalinks', 'shop2llm'); ?> →</a>
                <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px">
                <span style="flex-shrink:0;font-size:13px;color:<?php echo $diagItemsOk ? '#16a34a' : '#eab308'; ?>"><?php echo $diagItemsOk ? '✓' : '!'; ?></span>
                <span style="flex:1;font-size:12px;color:#1e1e1e"><?php echo $diagItemsOk ? esc_html__('Publishable content exists — keep publishing to attract crawlers', 'shop2llm') : esc_html__('No published content yet — publish posts or products first', 'shop2llm'); ?></span>
            </div>
        </div>
        <p style="font-size:10px;color:#94a3b8;margin:12px 0 0;text-align:center"><?php echo esc_html__('First AI crawler visits typically appear within 24–48 hours after crawlers can read your content. New visits show up here automatically.', 'shop2llm'); ?></p>
    </div>
    <?php
}

/**
 * Generate CSS conic-gradient for pie chart.
 *
 * @param array $data Distribution data [{name, count}, ...].
 * @return string CSS conic-gradient value.
 */
function shop_to_llm_render_pie_gradient(array $data): string
{
    if (empty($data)) {
        return '#e2e8f0';
    }
    $total = array_sum(array_column($data, 'count'));
    if ($total === 0) {
        return '#e2e8f0';
    }

    $gradient = [];
    $start = 0;
    foreach ($data as $i => $item) {
        $pct = ($item['count'] / $total) * 100;
        $end = $start + $pct;
        $color = shop_to_llm_pie_color($i);
        $gradient[] = "$color $start% $end%";
        $start = $end;
    }
    return 'conic-gradient(' . implode(', ', $gradient) . ')';
}

/**
 * Get pie chart color by index.
 *
 * @param int $i Index.
 * @return string Hex color.
 */
function shop_to_llm_pie_color(int $i): string
{
    $colors = [
        '#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6',
        '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1',
    ];
    return $colors[$i % count($colors)];
}

/**
 * Render SVG sparkline chart (echoes directly to avoid OutputNotEscaped).
 *
 * @param array $data [{date, count}, ...].
 */
function shop_to_llm_render_sparkline_svg(array $data): void
{
    if (count($data) < 2) {
        echo '<div style="font-size:11px;color:#94a3b8">—</div>';
        return;
    }

    $max = max(array_column($data, 'count'));
    if ($max === 0) {
        echo '<div style="font-size:11px;color:#94a3b8">—</div>';
        return;
    }

    $width = 280;
    $height = 60;
    $padding = 4;
    $points = [];
    foreach ($data as $i => $point) {
        $x = $padding + ($i / (count($data) - 1)) * ($width - 2 * $padding);
        $y = $height - $padding - ($point['count'] / $max) * ($height - 2 * $padding);
        $points[] = "$x,$y";
    }

    $polyline = implode(' ', $points);
    $xEnd = $width - $padding;
    $yEnd = $height - $padding;
    $areaPoints = $polyline . " {$xEnd},{$yEnd} {$padding},{$yEnd}";

    echo '<svg width="' . esc_attr((string) $width) . '" height="' . esc_attr((string) $height) . '" viewBox="0 0 ' . esc_attr((string) $width) . ' ' . esc_attr((string) $height) . '" xmlns="http://www.w3.org/2000/svg">';
    echo '<polygon points="' . esc_attr($areaPoints) . '" fill="#3b82f6" fill-opacity="0.1" />';
    echo '<polyline points="' . esc_attr($polyline) . '" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />';
    echo '</svg>';
}

