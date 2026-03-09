<?php
/**
 * ASAE Explore — CDN Handler
 *
 * Fetches available versions from the jsDelivr API and enqueues
 * the selected CSS/JS assets on all frontend pages.
 *
 * CDN versions use a two-part format (vX.Y), which is distinct
 * from the plugin's own three-part version (M.m.p).
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_Explore_CDN {

    /** @var string WordPress option key for the admin-selected CDN version */
    private $option_name = 'asae_explore_version';

    /** @var string Transient key for caching the version list from jsDelivr */
    private $transient_name = 'asae_explore_versions_cache';

    /** @var int Cache duration in seconds (1 hour) */
    private $cache_duration = 3600;

    /** @var string jsDelivr API endpoint for listing available versions */
    private $api_url = 'https://data.jsdelivr.com/v1/package/gh/ksoaresasae/asae-explore';

    /** @var string Base URL template for CDN asset delivery */
    private $cdn_base_url = 'https://cdn.jsdelivr.net/gh/ksoaresasae/asae-explore@';

    public function __construct() {
        // Enqueue CDN assets on all frontend pages
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Fetch available CDN versions from the jsDelivr API.
     *
     * Results are cached as a WordPress transient for one hour.
     * Returns an array of version strings sorted newest-first (e.g., ['v7.99', 'v7.98', ...])
     * or an associative array with an 'error' key on failure.
     *
     * @return array Version list or error array.
     */
    public function get_available_versions() {
        $cached_versions = get_transient($this->transient_name);

        if ($cached_versions !== false) {
            return $cached_versions;
        }

        $response = wp_remote_get($this->api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return array('error' => 'API returned status code: ' . $status_code);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'Failed to parse API response');
        }

        if (!isset($data['versions']) || !is_array($data['versions'])) {
            return array('error' => 'No versions found in API response');
        }

        // CDN versions use two-part format (X.Y); filter out anything else
        $versions = array_filter($data['versions'], function ($version) {
            return preg_match('/^\d+\.\d+$/', $version) === 1;
        });

        // Prefix with 'v' to match the expected CDN URL format (vX.Y)
        $versions = array_map(function ($version) {
            return 'v' . $version;
        }, $versions);

        // Sort descending so the latest version appears first
        usort($versions, function ($a, $b) {
            return version_compare(
                str_replace('v', '', $b),
                str_replace('v', '', $a)
            );
        });

        $versions = array_values($versions);

        if (!empty($versions)) {
            set_transient($this->transient_name, $versions, $this->cache_duration);
        }

        return $versions;
    }

    /**
     * Get the latest available CDN version.
     *
     * @return string Version string (e.g., 'v1.0').
     */
    public function get_latest_version() {
        $versions = $this->get_available_versions();

        if (is_array($versions) && !isset($versions['error']) && !empty($versions)) {
            return $versions[0];
        }

        return 'v1.0';
    }

    /**
     * Get the currently selected CDN version.
     * Falls back to the latest available version if none has been saved.
     *
     * @return string Version string (e.g., 'v7.99').
     */
    public function get_current_version() {
        $saved_version = get_option($this->option_name);

        if (empty($saved_version)) {
            return $this->get_latest_version();
        }

        return $saved_version;
    }

    /**
     * Build the full CDN URL for a given asset file and version.
     *
     * Uses rawurlencode on the version segment for safe URL construction.
     *
     * @param string $filename Asset filename (e.g., 'asae-explore.min.css').
     * @param string $version  CDN version (e.g., 'v1.0').
     * @return string Full CDN URL.
     */
    public function get_asset_url($filename, $version) {
        return $this->cdn_base_url . rawurlencode($version) . '/' . $filename;
    }

    /**
     * Enqueue ASAE Explore CSS and JS on all frontend pages.
     */
    public function enqueue_assets() {
        $version = $this->get_current_version();

        wp_enqueue_style(
            'asae-explore-css',
            $this->get_asset_url('asae-explore.min.css', $version),
            array(),
            $version,
            'all'
        );

        wp_enqueue_script(
            'asae-explore-js',
            $this->get_asset_url('asae-explore.min.js', $version),
            array(),
            $version,
            true
        );
    }

    /**
     * Delete the cached version list transient, forcing a fresh API fetch.
     */
    public function clear_version_cache() {
        delete_transient($this->transient_name);
    }

    /** @return string The option name for the selected CDN version. */
    public function get_option_name() {
        return $this->option_name;
    }

    /** @return string The CDN base URL for asset delivery. */
    public function get_cdn_base_url() {
        return $this->cdn_base_url;
    }
}
