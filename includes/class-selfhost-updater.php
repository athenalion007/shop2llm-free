<?php

/**
 * Shop2LLM — Self-hosted update channel (GitHub distribution only).
 *
 * Ships ONLY in GitHub-channel zips (deploy/build-shop-to-llm-zip.sh
 * --channel=github writes the selfhost-channel.php marker next to the
 * main plugin file). The WordPress.org package contains neither the
 * marker nor this class, so wp.org installs are served exclusively by
 * the official wordpress.org update channel and nothing here ever runs
 * there.
 *
 * Marker builds check api.shop2llm.com for updates — the same pattern
 * Shop to LLM Pro uses — and each check reports the site URL plus
 * plugin/WP/PHP versions, which is the unbiased install census for the
 * self-hosted channel (the wp.org build contains no telemetry code).
 *
 * The GitHub zip also carries an "Update URI" header pointing at the
 * cloud endpoint, so WordPress core excludes these installs from the
 * wordpress.org update check and the two channels can never fight over
 * the same plugin row.
 */

declare(strict_types=1);

namespace Shop2LLM;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SHOP2LLM_SELFHOST_UPDATE_URL')) {
    define('SHOP2LLM_SELFHOST_UPDATE_URL', 'https://api.shop2llm.com/fincore/v1/free-plugin/info');
}

final class SelfhostUpdater
{
    private const CACHE_KEY = 'shop2llm_selfhost_update_check';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'injectUpdate']);
        add_filter('plugins_api', [self::class, 'pluginDetails'], 10, 3);
    }

    /**
     * Query the cloud update server. Returns the update payload when a
     * newer version exists, or null otherwise (negative results are
     * cached so the cloud is hit ~2x/day, matching WP core cadence).
     */
    public static function checkForUpdate(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return is_array($cached) ? $cached : null;
        }

        $info = self::fetchCloudInfo();
        if ($info === null || version_compare((string) $info['version'], SHOP_TO_LLM_VERSION, '<=')) {
            set_transient(self::CACHE_KEY, 0, self::CACHE_TTL);
            return null;
        }

        $update = [
            'slug'          => 'shop-to-llm',
            'plugin'        => self::pluginFile(),
            'new_version'   => (string) $info['version'],
            'url'           => (string) ($info['homepage'] ?? 'https://shop2llm.com/'),
            'package'       => (string) ($info['download_url'] ?? SHOP2LLM_SELFHOST_UPDATE_URL),
            'tested'        => (string) ($info['tested'] ?? '7.1'),
            'requires'      => (string) ($info['requires'] ?? '6.0'),
            'requires_php'  => (string) ($info['requires_php'] ?? '8.2'),
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'compatibility' => new \stdClass(),
        ];
        set_transient(self::CACHE_KEY, $update, self::CACHE_TTL);
        return $update;
    }

    public static function injectUpdate($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $pluginFile = self::pluginFile();
        $update = self::checkForUpdate();

        if ($update !== null) {
            $transient->response[$pluginFile] = (object) $update;
        } else {
            // Cloud is at-or-below the installed version: clear any stale
            // response entry left over from an earlier check, then register
            // as "no update available" so the automatic-updates toggle works.
            unset($transient->response[$pluginFile]);
            if (!isset($transient->no_update[$pluginFile])) {
                $transient->no_update[$pluginFile] = (object) [
                    'id'            => 'shop2llm/shop-to-llm',
                    'slug'          => 'shop-to-llm',
                    'plugin'        => $pluginFile,
                    'new_version'   => SHOP_TO_LLM_VERSION,
                    'url'           => 'https://shop2llm.com/',
                    'package'       => SHOP2LLM_SELFHOST_UPDATE_URL,
                    'icons'         => [],
                    'banners'       => [],
                    'banners_rtl'   => [],
                    'tested'        => '7.1',
                    'requires_php'  => '8.2',
                    'compatibility' => new \stdClass(),
                ];
            }
        }

        return $transient;
    }

    public static function pluginDetails($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'shop-to-llm') {
            return $result;
        }

        $info = self::fetchCloudInfo() ?? [];
        $version = (string) ($info['version'] ?? SHOP_TO_LLM_VERSION);

        return (object) [
            'name'           => (string) ($info['name'] ?? 'Shop2LLM — AI Visibility & LLMs.txt Generator'),
            'slug'           => 'shop-to-llm',
            'version'        => $version,
            'author'         => (string) ($info['author'] ?? 'FinCore'),
            'author_profile' => (string) ($info['author_profile'] ?? 'https://shop2llm.com/'),
            'homepage'       => (string) ($info['homepage'] ?? 'https://shop2llm.com/'),
            'requires'       => (string) ($info['requires'] ?? '6.0'),
            'tested'         => (string) ($info['tested'] ?? '7.1'),
            'requires_php'   => (string) ($info['requires_php'] ?? '8.2'),
            'download_link'  => (string) ($info['download_url'] ?? SHOP2LLM_SELFHOST_UPDATE_URL),
            'sections'       => [
                'description' => (string) ($info['description'] ?? 'AI Search Visibility & GEO for WordPress.'),
                'changelog'   => (string) ($info['changelog'] ?? "<h4>{$version}</h4><ul><li>Latest release.</li></ul>"),
            ],
        ];
    }

    private static function fetchCloudInfo(): ?array
    {
        $url = add_query_arg([
            'version'  => SHOP_TO_LLM_VERSION,
            'site_url' => home_url(),
            'wp'       => get_bloginfo('version'),
            'php'      => PHP_VERSION,
            'channel'  => 'github',
        ], SHOP2LLM_SELFHOST_UPDATE_URL);

        $response = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $info = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($info) || empty($info['version'])) {
            return null;
        }

        return $info;
    }

    private static function pluginFile(): string
    {
        return plugin_basename(SHOP_TO_LLM_DIR . 'shop-to-llm.php');
    }
}
