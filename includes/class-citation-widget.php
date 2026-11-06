<?php

/**
 * AI Visibility Dashboard Widget
 * Shows the site's AI citation summary from the cloud Citation Monitor.
 * Only loads when the user has enabled analytics (opt-in).
 *
 * Auth: Pro sites send their license key; free sites identify via a
 * SHA256 site hash (same scheme as the daily cloud heartbeat).
 * Data is cached for 6h — the cloud cron re-checks every 6h, so the
 * widget never triggers AI checks itself (no server load).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Shop2LLM_Citation_Widget
{
    const CACHE_KEY = 'shop2llm_citation_data';
    const REGISTER_KEY = 'shop2llm_citation_registered';
    const API_BASE = 'https://api.shop2llm.com/fincore/v1';

    public static function init(): void
    {
        if (get_option('shop_to_llm_analytics', 'no') !== 'yes') {
            return;
        }
        add_action('wp_dashboard_setup', [self::class, 'add_dashboard_widget']);
    }

    public static function add_dashboard_widget(): void
    {
        wp_add_dashboard_widget(
            'shop2llm_citation_widget',
            'AI Visibility — Shop2LLM',
            [self::class, 'render']
        );
    }

    /**
     * Whether this site has an active Pro license (drives the auth mode).
     */
    private static function license_key(): string
    {
        $key = get_option('shop_to_llm_pro_license_key', '');
        if ($key !== '' && get_option('shop_to_llm_pro_license_valid', 'no') === 'yes') {
            return $key;
        }
        return '';
    }

    /**
     * Register this site with the cloud Citation Monitor (idempotent, fire-and-forget).
     * Retries every 12h until the cloud confirms.
     */
    public static function ensure_registered(): void
    {
        if (get_transient(self::REGISTER_KEY)) {
            return;
        }
        $payload = [
            'site_url' => get_site_url(),
            'plugin_version' => defined('SHOP_TO_LLM_VERSION') ? SHOP_TO_LLM_VERSION : '',
        ];
        $licenseKey = self::license_key();
        if ($licenseKey !== '') {
            $payload['license_key'] = $licenseKey;
        }
        wp_remote_post(self::API_BASE . '/citation/site/register', [
            'timeout' => 5,
            'blocking' => false,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);
        set_transient(self::REGISTER_KEY, 1, 12 * HOUR_IN_SECONDS);
    }

    /**
     * Fetch aggregated AI visibility results (cached 6h; failures cached 5min
     * so admin page loads never hammer the cloud API).
     * @return array|null null on any failure — callers show the no-data state
     */
    public static function get_results(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached === []) {
            return null; // recent failure — don't retry yet
        }
        if (is_array($cached)) {
            return $cached;
        }

        self::ensure_registered();

        $licenseKey = self::license_key();
        if ($licenseKey !== '') {
            $url = add_query_arg('license_key', rawurlencode($licenseKey), self::API_BASE . '/citation/site/results');
        } else {
            $siteUrl = get_site_url();
            $host = (string)(wp_parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl);
            $url = add_query_arg('site_hash', hash('sha256', $host), self::API_BASE . '/citation/site/results');
        }

        $response = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, [], 5 * MINUTE_IN_SECONDS);
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['site'])) {
            set_transient(self::CACHE_KEY, [], 5 * MINUTE_IN_SECONDS);
            return null;
        }
        set_transient(self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    public static function render(): void
    {
        $data = self::get_results();
        $ctaUrl = 'https://shop2llm.com/dashboard?utm_source=wp-plugin&utm_medium=citation_widget';

        echo '<div style="padding:8px 0;">';

        if (is_array($data)) {
            // Telemetry: a real data render is an activation-quality signal
            if (function_exists('shop_to_llm_log_event')) {
                shop_to_llm_log_event('citation_view');
            }

            $cited = (int)($data['week']['cited'] ?? 0);
            $checks = (int)($data['week']['checks'] ?? 0);
            $delta = isset($data['week']['delta_pct']) && $data['week']['delta_pct'] !== null
                ? (int)$data['week']['delta_pct'] : null;
            $engines = is_array($data['engines'] ?? null) ? $data['engines'] : [];

            if ($checks > 0) {
                echo '<p style="font-size:24px;font-weight:700;margin:0 0 4px;color:#1d2327;">'
                    . esc_html((string)$cited)
                    . ' <span style="font-size:14px;color:#646970;">citations this week</span></p>';
                if ($delta !== null && $delta !== 0) {
                    $arrow = $delta > 0 ? '↑' : '↓';
                    $color = $delta > 0 ? '#16a34a' : '#dc2626';
                    echo '<p style="color:' . esc_attr($color) . ';margin:0 0 8px;font-weight:600;">'
                        . esc_html($arrow . ' ' . abs($delta) . '%')
                        . ' <span style="color:#646970;font-weight:400;">vs last week</span></p>';
                }
                if ($engines !== []) {
                    $parts = [];
                    foreach ($engines as $eng => $stat) {
                        $parts[] = ucfirst((string)$eng) . ' '
                            . (int)($stat['cited'] ?? 0) . '/' . (int)($stat['total'] ?? 0);
                    }
                    echo '<p style="color:#646970;font-size:12px;margin:0 0 12px;">'
                        . esc_html(implode(' · ', $parts)) . '</p>';
                }
            } else {
                echo '<p style="color:#646970;">See whether AI assistants like ChatGPT and Perplexity mention your store. First results usually appear within 24 hours.</p>';
            }
        } else {
            echo '<p style="color:#646970;">See whether AI assistants like ChatGPT and Perplexity mention your store.</p>';
        }

        echo '<a href="' . esc_url($ctaUrl) . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;background:#6366f1;color:#fff;padding:8px 16px;border-radius:6px;'
            . 'text-decoration:none;font-weight:600;font-size:13px;">View AI Visibility Report →</a>';
        echo '</div>';
    }
}
