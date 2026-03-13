<?php
/**
 * ASAE Explore — Admin Settings
 *
 * Registers the admin menu page under the ASAE top-level menu
 * and provides UI for selecting which CDN version to enqueue.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_Explore_Admin {

    /** @var ASAE_Explore_CDN CDN handler instance */
    private $cdn;

    /**
     * @param ASAE_Explore_CDN $cdn CDN handler instance.
     */
    public function __construct(ASAE_Explore_CDN $cdn) {
        $this->cdn = $cdn;

        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_refresh_versions'));
    }

    /**
     * Register the ASAE top-level menu and Explore submenu page.
     *
     * This plugin always owns the top-level ASAE menu. The parent menu slug
     * points to the Explore settings page. Other ASAE plugins should add their
     * own submenu items under the 'asae-explore' parent slug.
     */
    public function add_settings_page() {
        // Top-level ASAE menu — clicking it goes to the Explore settings page
        add_menu_page(
            'ASAE',
            'ASAE',
            'manage_options',
            'asae',
            array($this, 'render_settings_page'),
            'dashicons-building',
            30
        );

        // "Explore" submenu item (different slug so WP shows it)
        add_submenu_page(
            'asae',
            'ASAE Explore Settings',
            'Explore',
            'manage_options',
            'asae-explore',
            array($this, 'render_settings_page')
        );

        // Remove the auto-generated duplicate first submenu that matches the parent
        remove_submenu_page('asae', 'asae');
    }

    /**
     * Register the plugin setting, settings section, and version field
     * with the WordPress Settings API.
     */
    public function register_settings() {
        register_setting(
            'asae_explore_options',
            $this->cdn->get_option_name(),
            array(
                'type'              => 'string',
                'sanitize_callback' => array($this, 'sanitize_version'),
                'default'           => '',
            )
        );

        add_settings_section(
            'asae_explore_main_section',
            'CDN Version Settings',
            array($this, 'render_section_description'),
            'asae-explore'
        );

        // Use label_for arg so WP wraps the title in a <label> pointing at the field
        add_settings_field(
            'asae_explore_version_field',
            'Explore Version',
            array($this, 'render_version_field'),
            'asae-explore',
            'asae_explore_main_section',
            array('label_for' => $this->cdn->get_option_name())
        );
    }

    /**
     * Handle the "Refresh Version List" action.
     *
     * Runs on admin_init so the cache is cleared before the page renders.
     * Verifies capability and nonce before processing.
     */
    public function handle_refresh_versions() {
        if (!isset($_GET['refresh_versions']) || $_GET['refresh_versions'] !== '1') {
            return;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== 'asae-explore') {
            return;
        }

        // Verify the user has permission before processing
        if (!current_user_can('manage_options')) {
            return;
        }

        // Verify nonce — wp_die()s on failure, which is the correct behavior here
        check_admin_referer('asae_refresh_versions');

        $this->cdn->clear_version_cache();

        add_settings_error(
            'asae_explore_messages',
            'asae_explore_refresh',
            'Version list refreshed from CDN.',
            'updated'
        );
    }

    /**
     * Validate that a value matches the CDN two-part version format (vX.Y).
     *
     * @param string $value Version string to validate.
     * @return bool True if valid.
     */
    public function validate_version_format($value) {
        return preg_match('/^v\d+\.\d+$/', $value) === 1;
    }

    /**
     * Sanitize the version option value before saving to the database.
     *
     * Rejects values that don't match the expected CDN version format (vX.Y)
     * and returns the previously saved value on failure.
     *
     * @param string $value Raw input value.
     * @return string Sanitized version string, or previous value on failure.
     */
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
            return get_option($this->cdn->get_option_name(), '');
        }

        return $value;
    }

    /**
     * Render the description text for the CDN settings section.
     */
    public function render_section_description() {
        echo '<p>Configure the version number for the ASAE Explore files loaded from the CDN.</p>';
    }

    /**
     * Render the version selection field.
     *
     * Shows a dropdown of available CDN versions when the API is reachable,
     * or falls back to a manual text input when unavailable.
     */
    public function render_version_field() {
        $current_version = $this->cdn->get_current_version();
        $saved_version   = get_option($this->cdn->get_option_name());
        $versions        = $this->cdn->get_available_versions();
        $option_name     = esc_attr($this->cdn->get_option_name());

        if (is_array($versions) && !isset($versions['error']) && !empty($versions)) {
            // Dropdown of available CDN versions
            echo '<select id="' . $option_name . '" name="' . $option_name . '" class="regular-text" aria-describedby="asae-explore-version-desc">';

            foreach ($versions as $version) {
                $selected = selected($version, $current_version, false);
                $label    = esc_html($version);
                if ($version === $versions[0]) {
                    $label .= ' (latest)';
                }
                echo '<option value="' . esc_attr($version) . '"' . $selected . '>' . $label . '</option>';
            }

            echo '</select>';

            if (empty($saved_version)) {
                echo '<p class="description" id="asae-explore-version-desc">No version selected. Using the latest version by default.</p>';
            } else {
                echo '<p class="description" id="asae-explore-version-desc">Select a version from the available releases.</p>';
            }
        } else {
            // Fallback: manual text input when the API is unavailable
            echo '<input type="text" id="' . $option_name . '" name="' . $option_name . '" value="' . esc_attr($current_version) . '" class="regular-text" placeholder="v1.0" pattern="v\d+\.\d+" aria-describedby="asae-explore-version-desc" />';
            echo '<p class="description" id="asae-explore-version-desc"><strong>Required format:</strong> vX.Y (e.g., v1.0, v7.99). Must start with &ldquo;v&rdquo; followed by major.minor version numbers.</p>';

            if (isset($versions['error'])) {
                // Use WP notice markup instead of inline color styles for accessibility
                echo '<div class="notice notice-error inline"><p>' . esc_html('Could not fetch available versions from CDN: ' . $versions['error']) . '</p></div>';
            }
        }
    }

    /**
     * Render the full admin settings page.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $current_version = $this->cdn->get_current_version();
        $css_url         = $this->cdn->get_asset_url('asae-explore.min.css', $current_version);
        $js_url          = $this->cdn->get_asset_url('asae-explore.min.js', $current_version);
        $refresh_url     = wp_nonce_url(
            admin_url('admin.php?page=asae-explore&refresh_versions=1'),
            'asae_refresh_versions'
        );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php
            // Show settings-saved and validation messages scoped to this page
            settings_errors('asae_explore_messages');
            settings_errors('asae_explore_version');
            ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('asae_explore_options');
                do_settings_sections('asae-explore');
                submit_button('Save Settings');
                ?>
            </form>

            <p>
                <a href="<?php echo esc_url($refresh_url); ?>" class="button" aria-label="Refresh the CDN version list"><?php esc_html_e('Refresh Version List', 'asae-explore'); ?></a>
                <span class="description" style="margin-left: 10px;"><?php esc_html_e('Versions are cached for 1 hour. Click to fetch the latest list from the CDN.', 'asae-explore'); ?></span>
            </p>

            <div class="card" style="margin-top: 20px;">
                <h2><?php esc_html_e('Current CDN URLs', 'asae-explore'); ?></h2>
                <p><?php esc_html_e('The following files will be loaded on all frontend pages:', 'asae-explore'); ?></p>
                <table class="widefat" role="presentation">
                    <tr>
                        <th scope="row"><strong><?php esc_html_e('CSS', 'asae-explore'); ?></strong></th>
                        <td><code><?php echo esc_url($css_url); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><strong><?php esc_html_e('JavaScript', 'asae-explore'); ?></strong></th>
                        <td><code><?php echo esc_url($js_url); ?></code></td>
                    </tr>
                </table>
            </div>

            <p class="description" style="margin-top: 20px;">
                <?php
                /* translators: %s: plugin version number */
                printf(esc_html__('ASAE Explore Plugin v%s', 'asae-explore'), esc_html(ASAE_EXPLORE_VERSION));
                ?>
            </p>
        </div>
        <?php
    }
}
