<?php
/**
 * Plugin Name: ASAE Explore
 * Plugin URI: https://www.asaecenter.org
 * Description: Loads ASAE Explore CSS and JavaScript files from CDN on all frontend pages with configurable version control.
 * Version: 0.0.10
 * Author: Keith M. Soares
 * Author URI: https://keithmsoares.com
 * Author Email: ksoares@asaecenter.org
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: asae-explore
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin version constant (three-part M.m.p per version-numbering.md)
define('ASAE_EXPLORE_VERSION', '0.0.10');
define('ASAE_EXPLORE_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Load plugin classes
require_once ASAE_EXPLORE_PLUGIN_DIR . 'includes/class-asae-explore-cdn.php';
require_once ASAE_EXPLORE_PLUGIN_DIR . 'includes/class-asae-explore-admin.php';

/**
 * Initialize the plugin after all plugins are loaded.
 * The CDN class handles frontend asset enqueuing; the Admin class handles the settings page.
 */
function asae_explore_init() {
    $cdn = new ASAE_Explore_CDN();
    new ASAE_Explore_Admin($cdn);
}
add_action('plugins_loaded', 'asae_explore_init');
