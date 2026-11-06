<?php

/**
 * NicheRadar Referral Card
 * Pure external link only — zero radar functionality is embedded here.
 * This keeps NicheRadar an independent product (no perception/license/price lock).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Shop2LLM_Radar_Card
{
    public static function init(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'add_dashboard_widget']);
    }

    public static function add_dashboard_widget(): void
    {
        wp_add_dashboard_widget(
            'shop2llm_radar_card',
            'Market Radar — NicheRadar',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        echo '<div style="padding:8px 0;">';
        echo '<p style="margin:0 0 10px;color:#646970;font-size:13px;">Daily supply-side market signals — category heat, stockout ratios and price-band gaps across independent stores. Provided by <strong>NicheRadar</strong>, an independent product.</p>';
        echo '<a href="https://www.shop2llm.com/fincore/v1/page/radar" target="_blank" style="display:inline-block;background:#10b981;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;">Open Market Radar →</a>';
        echo '</div>';
    }
}
