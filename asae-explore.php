<?php
/**
 * Plugin Name: ASAE Explore
 * Plugin URI: https://www.asaecenter.org
 * Description: Loads ASAE Explore CSS and JavaScript files from CDN on all frontend pages with configurable version control.
 * Version: 0.0.7
 * Author: Keith M. Soares
 * Author URI: https://www.asaecenter.org
 * Author Email: ksoares@asaecenter.org
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: asae-explore
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_Explore {
    
    private $plugin_version = '0.0.7';
    private $option_name = 'asae_explore_version';
    private $transient_name = 'asae_explore_versions_cache';
    private $cache_duration = 3600;
    private $api_url = 'https://data.jsdelivr.com/v1/package/gh/ksoaresasae/asae-explore';
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function get_available_versions() {
        $cached_versions = get_transient($this->transient_name);
        
        if ($cached_versions !== false) {
            return $cached_versions;
        }
        
        $response = wp_remote_get($this->api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json'
            )
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
        
        $versions = array_filter($data['versions'], function($version) {
            return preg_match('/^\d+\.\d+$/', $version) === 1;
        });
        
        $versions = array_map(function($version) {
            return 'v' . $version;
        }, $versions);
        
        usort($versions, function($a, $b) {
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
    
    public function get_latest_version() {
        $versions = $this->get_available_versions();
        
        if (is_array($versions) && !isset($versions['error']) && !empty($versions)) {
            return $versions[0];
        }
        
        return 'v1.0';
    }
    
    public function get_current_version() {
        $saved_version = get_option($this->option_name);
        
        if (empty($saved_version)) {
            return $this->get_latest_version();
        }
        
        return $saved_version;
    }
    
    public function enqueue_assets() {
        $version = $this->get_current_version();
        
        wp_enqueue_style(
            'asae-explore-css',
            'https://cdn.jsdelivr.net/gh/ksoaresasae/asae-explore@' . esc_attr($version) . '/asae-explore.min.css',
            array(),
            $version,
            'all'
        );
        
        wp_enqueue_script(
            'asae-explore-js',
            'https://cdn.jsdelivr.net/gh/ksoaresasae/asae-explore@' . esc_attr($version) . '/asae-explore.min.js',
            array(),
            $version,
            true
        );
    }
    
    public function add_settings_page() {
        add_options_page(
            'ASAE Explore Settings',
            'ASAE Explore',
            'manage_options',
            'asae-explore',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting(
            'asae_explore_options',
            $this->option_name,
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_version'),
                'default' => ''
            )
        );
        
        add_settings_section(
            'asae_explore_main_section',
            'CDN Version Settings',
            array($this, 'render_section_description'),
            'asae-explore'
        );
        
        add_settings_field(
            'asae_explore_version_field',
            'Explore Version',
            array($this, 'render_version_field'),
            'asae-explore',
            'asae_explore_main_section'
        );
    }
    
    public function validate_version_format($value) {
        return preg_match('/^v\d+\.\d+$/', $value) === 1;
    }
    
    public function sanitize_version($value) {
        $value = sanitize_text_field($value);
        
        if (empty($value)) {
            return '';
        }
        
        if (!$this->validate_version_format($value)) {
            add_settings_error(
                'asae_explore_version',
                'invalid_version_format',
                'Invalid version format. Version must be in the format "vX.Y" (e.g., v1.0, v7.99). Your changes were not saved.',
                'error'
            );
            return get_option($this->option_name, '');
        }
        
        return $value;
    }
    
    public function render_section_description() {
        echo '<p>Configure the version number for the ASAE Explore files loaded from the CDN.</p>';
    }
    
    public function render_version_field() {
        $current_version = $this->get_current_version();
        $saved_version = get_option($this->option_name);
        $versions = $this->get_available_versions();
        
        if (is_array($versions) && !isset($versions['error']) && !empty($versions)) {
            echo '<select id="' . esc_attr($this->option_name) . '" name="' . esc_attr($this->option_name) . '" class="regular-text">';
            
            foreach ($versions as $version) {
                $selected = ($version === $current_version) ? ' selected="selected"' : '';
                $is_latest = ($version === $versions[0]);
                $label = esc_html($version);
                if ($is_latest) {
                    $label .= ' (latest)';
                }
                echo '<option value="' . esc_attr($version) . '"' . $selected . '>' . $label . '</option>';
            }
            
            echo '</select>';
            
            if (empty($saved_version)) {
                echo '<p class="description">No version selected. Using the latest version by default.</p>';
            } else {
                echo '<p class="description">Select a version from the available releases.</p>';
            }
        } else {
            echo '<input type="text" id="' . esc_attr($this->option_name) . '" name="' . esc_attr($this->option_name) . '" value="' . esc_attr($current_version) . '" class="regular-text" placeholder="v1.0" pattern="v\d+\.\d+" />';
            echo '<p class="description"><strong>Required format:</strong> vX.Y (e.g., v1.0, v7.99). Must start with "v" followed by major.minor version numbers.</p>';
            
            if (isset($versions['error'])) {
                echo '<p class="description" style="color: #d63638;"><strong>Note:</strong> Could not fetch available versions from CDN. ' . esc_html($versions['error']) . '</p>';
            }
        }
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_GET['refresh_versions']) && $_GET['refresh_versions'] === '1') {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'asae_refresh_versions')) {
                delete_transient($this->transient_name);
                add_settings_error(
                    'asae_explore_messages',
                    'asae_explore_refresh',
                    'Version list refreshed from CDN.',
                    'updated'
                );
            }
        }
        
        settings_errors();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('asae_explore_options');
                do_settings_sections('asae-explore');
                submit_button('Save Settings');
                ?>
            </form>
            
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=asae-explore&refresh_versions=1'), 'asae_refresh_versions')); ?>" class="button">Refresh Version List</a>
                <span class="description" style="margin-left: 10px;">Versions are cached for 1 hour. Click to fetch the latest list from the CDN.</span>
            </p>
            
            <div class="card" style="margin-top: 20px;">
                <h2>Current CDN URLs</h2>
                <p>The following files will be loaded on all frontend pages:</p>
                <?php $current_version = $this->get_current_version(); ?>
                <ul>
                    <li><strong>CSS:</strong> <code>https://cdn.jsdelivr.net/gh/ksoaresasae/asae-explore@<?php echo esc_html($current_version); ?>/asae-explore.min.css</code></li>
                    <li><strong>JavaScript:</strong> <code>https://cdn.jsdelivr.net/gh/ksoaresasae/asae-explore@<?php echo esc_html($current_version); ?>/asae-explore.min.js</code></li>
                </ul>
            </div>
            
            <p class="description" style="margin-top: 20px; color: #666;">ASAE Explore Plugin v<?php echo esc_html($this->plugin_version); ?></p>
        </div>
        <?php
    }
}

new ASAE_Explore();
