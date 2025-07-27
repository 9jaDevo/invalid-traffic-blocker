<?php

/**
 * Plugin Name: Invalid Traffic Blocker
 * Plugin URI: https://wordpress.org/plugins/invalid-traffic-blocker
 * Description: Blocks unwanted traffic using multiple IP detection providers to protect AdSense publishers from invalid traffic. Premium version includes analytics, multiple providers, and advanced features.
 * Short Description: Protect your site from invalid traffic by blocking suspicious IPs using advanced detection methods.
 * Version: 2.0.0
 * Author: Michael Akinwumi
 * Author URI: https://michaelakinwumi.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: invalid-traffic-blocker
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.4
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class INVATRBL_Plugin
{

    private $option_group = 'invatrbl_options';
    private $option_name  = 'invatrbl_options';
    private $version = '2.0.0';

    public function __construct()
    {
        // Load admin settings and enqueue scripts.
        add_action('admin_menu', [$this, 'invatrbl_add_settings_page']);
        add_action('admin_init', [$this, 'invatrbl_register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'invatrbl_admin_enqueue_scripts']);

        // AJAX callbacks
        add_action('wp_ajax_invatrbl_test_api', [$this, 'invatrbl_test_api_connectivity']);
        add_action('wp_ajax_invatrbl_validate_license', [$this, 'invatrbl_validate_license_ajax']);

        // Frontend: Check and block invalid IPs.
        add_action('init', [$this, 'invatrbl_check_visitor_ip']);

        // Create analytics table on activation
        register_activation_hook(__FILE__, [$this, 'create_analytics_table']);
    }

    /**
     * Check if user has premium license
     */
    private function is_premium_active()
    {
        $options = get_option($this->option_name);
        $license_key = $options['license_key'] ?? '';

        if (empty($license_key)) {
            return false;
        }

        return $this->validate_license($license_key);
    }

    /**
     * Validate license key
     */
    private function validate_license($license_key)
    {
        $cached_status = get_transient('invatrbl_license_status_' . md5($license_key));
        if ($cached_status !== false) {
            return $cached_status === 'valid';
        }

        // For demo purposes, accept any key that starts with 'PRO-'
        // In production, this would validate against your server
        $is_valid = strpos($license_key, 'PRO-') === 0 && strlen($license_key) > 10;

        // Cache for 24 hours
        set_transient('invatrbl_license_status_' . md5($license_key), $is_valid ? 'valid' : 'invalid', DAY_IN_SECONDS);

        return $is_valid;
    }

    /**
     * Create analytics table
     */
    public function create_analytics_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'invatrbl_logs';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            reason varchar(100) NOT NULL,
            provider varchar(50) NOT NULL,
            blocked_at datetime DEFAULT CURRENT_TIMESTAMP,
            user_agent text,
            url text,
            country varchar(3),
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY blocked_at (blocked_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log blocked IP for analytics
     */
    private function log_blocked_ip($ip, $reason, $provider, $country = '')
    {
        if (!$this->is_premium_active()) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'invatrbl_logs';

        $wpdb->insert(
            $table_name,
            [
                'ip_address' => $ip,
                'reason' => $reason,
                'provider' => $provider,
                'blocked_at' => current_time('mysql'),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'url' => sanitize_text_field(filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL) ?? ''),
                'country' => $country
            ]
        );
    }

    /**
     * Enqueue admin JavaScript.
     */
    public function invatrbl_admin_enqueue_scripts($hook)
    {
        // Only enqueue scripts on our plugin settings page.
        if ('settings_page_invalid_traffic_blocker' !== $hook) {
            return;
        }
        wp_register_script(
            'invatrbl-admin-js',
            plugin_dir_url(__FILE__) . 'js/admin.js',
            array('jquery'),
            '1.4.0',
            true
        );
        // Pass some variables to our script.
        wp_localize_script('invatrbl-admin-js', 'invatrblVars', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('invatrbl_test_api_nonce'),
            'adminIP'   => $this->invatrbl_get_user_ip(),
            'optionName' => $this->option_name,
        ));
        wp_enqueue_script('invatrbl-admin-js');

        // CSS for admin settings page.
        wp_register_style(
            'invatrbl-admin-css',
            plugin_dir_url(__FILE__) . 'css/admin.css',
            [],
            '1.4.0'
        );
        wp_enqueue_style('invatrbl-admin-css');
    }

    /**
     * Add settings page under Settings menu.
     */
    public function invatrbl_add_settings_page()
    {
        add_options_page(
            esc_html__('Invalid Traffic Blocker Settings', 'invalid-traffic-blocker'),
            esc_html__('Invalid Traffic Blocker', 'invalid-traffic-blocker'),
            'manage_options',
            'invalid_traffic_blocker',
            [$this, 'invatrbl_render_settings_page']
        );
    }

    /**
     * Register plugin settings.
     */
    public function invatrbl_register_settings()
    {
        register_setting(
            $this->option_group,
            $this->option_name,
            'invatrbl_sanitize_settings'
        );

        add_settings_section(
            'invatrbl_main_section',
            esc_html__('Main Settings', 'invalid-traffic-blocker'),
            null,
            'invalid_traffic_blocker'
        );

        // License Key
        add_settings_field(
            'license_key',
            esc_html__('Premium License Key', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_license_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Provider (Pro, view-only)
        add_settings_field(
            'provider',
            esc_html__('IP Check Provider', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_provider_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // API Key
        add_settings_field(
            'api_key',
            esc_html__('IPHub API Key', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_api_key_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Enable toggle
        add_settings_field(
            'enabled',
            esc_html__('Enable Invalid Traffic Blocker', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_enabled_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Blocking modes
        add_settings_field(
            'blocking_modes',
            esc_html__('Blocking Options (Select one)', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_blocking_modes_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Custom block
        add_settings_field(
            'custom_block_options',
            esc_html__('Custom Block Options', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_custom_block_options_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Whitelist IPs
        add_settings_field(
            'whitelisted_ips',
            esc_html__('Whitelist IP Addresses', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_whitelist_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Cache duration
        add_settings_field(
            'cache_duration',
            esc_html__('Cache Duration (Hours)', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_cache_duration_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Allow Known Crawlers
        add_settings_field(
            'allow_crawlers',
            esc_html__('Allow Known Crawlers', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_allow_crawlers_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Additional Crawlers (Pro, view-only)
        add_settings_field(
            'additional_crawlers',
            esc_html__('Additional Crawler Patterns', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_additional_crawlers_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );
    }

    /**
     * Sanitize and validate settings input.
     */
    public static function invatrbl_sanitize_settings($input)
    {
        $new_input = array();

        // License key
        $new_input['license_key'] = isset($input['license_key']) ? sanitize_text_field($input['license_key']) : '';

        // Provider selection (free version defaults to IPHub, premium can choose)
        $instance = new self();
        if ($instance->is_premium_active()) {
            $allowed_providers = ['iphub', 'ipqualityscore', 'ipapi', 'proxycheck'];
            $new_input['provider'] = isset($input['provider']) && in_array($input['provider'], $allowed_providers)
                ? $input['provider'] : 'iphub';
        } else {
            $new_input['provider'] = 'iphub';
        }

        // API key & enable toggle
        $new_input['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        $new_input['enabled'] = isset($input['enabled']) ? absint($input['enabled']) : 0;

        // Blocking modes
        $new_input['safe_mode']   = isset($input['safe_mode']) ? 1 : 0;
        $new_input['strict_mode'] = isset($input['strict_mode']) ? 1 : 0;
        $new_input['custom_mode'] = isset($input['custom_mode']) ? 1 : 0;

        // Custom block options
        if (! empty($new_input['custom_mode'])) {
            $custom = array();
            if (! empty($input['custom_block_options']) && is_array($input['custom_block_options'])) {
                foreach ($input['custom_block_options'] as $opt) {
                    $custom[] = absint($opt);
                }
            }
            $new_input['custom_block_options'] = $custom;
        } else {
            $new_input['custom_block_options'] = array();
        }

        // Only one mode active
        $count = $new_input['safe_mode'] + $new_input['strict_mode'] + $new_input['custom_mode'];
        if ($count > 1) {
            add_settings_error(
                'invatrbl_options',
                'mode_error',
                esc_html__('Please select only one blocking mode option.', 'invalid-traffic-blocker'),
                'error'
            );
            $new_input['safe_mode']   = 1;
            $new_input['strict_mode'] = 0;
            $new_input['custom_mode'] = 0;
            $new_input['custom_block_options'] = array();
        }

        // Whitelisted IPs
        $ips = array();
        if (! empty($input['whitelisted_ips'])) {
            foreach (explode("\n", $input['whitelisted_ips']) as $line) {
                $ip = trim(sanitize_text_field($line));
                if ($ip) {
                    $ips[] = $ip;
                }
            }
        }
        $new_input['whitelisted_ips'] = implode("\n", $ips);

        // Cache duration
        $new_input['cache_duration'] = max(1, absint($input['cache_duration'] ?? 1));

        // Allow known crawlers
        $new_input['allow_crawlers'] = isset($input['allow_crawlers']) ? 1 : 0;

        // Additional crawlers cleared in free version, enabled for premium
        if ($instance->is_premium_active()) {
            $new_input['additional_crawlers'] = isset($input['additional_crawlers']) ? sanitize_textarea_field($input['additional_crawlers']) : '';
        } else {
            $new_input['additional_crawlers'] = '';
        }

        return $new_input;
    }

    /**
     * Render Field Callbacks 
     */
    public function invatrbl_render_license_field()
    {
        $options = get_option($this->option_name);
        $license_key = $options['license_key'] ?? '';
        $is_valid = $this->is_premium_active();
?>
        <div class="invatrbl-license-field">
            <input type="text"
                name="<?php echo esc_attr($this->option_name); ?>[license_key]"
                value="<?php echo esc_attr($license_key); ?>"
                size="40"
                placeholder="Enter your premium license key" />
            <?php if ($license_key): ?>
                <span class="license-status <?php echo $is_valid ? 'valid' : 'invalid'; ?>">
                    <?php echo $is_valid ? '✓ Valid License' : '✗ Invalid License'; ?>
                </span>
            <?php endif; ?>
            <p class="description">
                Enter your premium license key to unlock additional features.
                <a href="https://yourdomain.com/premium" target="_blank">Get Premium License</a>
            </p>
        </div>
    <?php
    }

    public function invatrbl_render_provider_field()
    {
        $options  = get_option($this->option_name);
        $selected = $options['provider'] ?? 'iphub';
        $is_premium = $this->is_premium_active();
    ?>
        <div class="invatrbl-provider-field">
            <select name="<?php echo esc_attr($this->option_name); ?>[provider]" <?php echo $is_premium ? '' : 'disabled'; ?>>
                <option value="iphub" <?php selected($selected, 'iphub'); ?>>IPHub.info (Free)</option>
                <?php if ($is_premium): ?>
                    <option value="ipqualityscore" <?php selected($selected, 'ipqualityscore'); ?>>IPQualityScore (Premium)</option>
                    <option value="ipapi" <?php selected($selected, 'ipapi'); ?>>IPAPI (Premium)</option>
                    <option value="proxycheck" <?php selected($selected, 'proxycheck'); ?>>ProxyCheck.io (Premium)</option>
                <?php else: ?>
                    <option value="ipqualityscore" disabled>IPQualityScore (Premium) 🔒</option>
                    <option value="ipapi" disabled>IPAPI (Premium) 🔒</option>
                    <option value="proxycheck" disabled>ProxyCheck.io (Premium) 🔒</option>
                <?php endif; ?>
            </select>
            <?php if (!$is_premium): ?>
                <p class="description premium-notice">
                    🚀 <strong>Upgrade to Premium</strong> to unlock multiple IP check providers for better accuracy and redundancy.
                </p>
            <?php else: ?>
                <p class="description">
                    Multiple providers available with your premium license. Choose the best one for your needs.
                </p>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Render the API Key field.
     */
    public function invatrbl_render_api_key_field()
    {
        $options = get_option($this->option_name);
    ?>
        <input type="text"
            name="<?php echo esc_attr($this->option_name); ?>[api_key]"
            value="<?php echo esc_attr($options['api_key'] ?? ''); ?>"
            size="40" />
    <?php
    }

    /**
     * Render the enabled toggle.
     */
    public function invatrbl_render_enabled_field()
    {
        $options = get_option($this->option_name);
        $enabled = isset($options['enabled']) ? (int)$options['enabled'] : 0;
    ?>
        <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[enabled]" value="1" <?php checked($enabled, 1); ?> />
    <?php
    }

    /**
     * Render blocking mode options.
     */
    public function invatrbl_render_blocking_modes_field()
    {
        $options = get_option($this->option_name);
        $safe   = isset($options['safe_mode']) ? (int)$options['safe_mode'] : 0;
        $strict = isset($options['strict_mode']) ? (int)$options['strict_mode'] : 0;
        $custom = isset($options['custom_mode']) ? (int)$options['custom_mode'] : 0;
    ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[safe_mode]" value="1" <?php checked($safe, 1); ?> /> Safe Mode (Block only non‑residential IPs: block==1)
        </label><br />
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[strict_mode]" value="1" <?php checked($strict, 1); ?> /> Strict Mode (Block both non‑residential and residential IPs: block==1 or block==2)
        </label><br />
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[custom_mode]" value="1" <?php checked($custom, 1); ?> /> Custom Mode (Select specific block types below)
        </label>
        <p><em>Please select only one mode. Safe Mode is recommended.</em></p>
    <?php
    }

    /**
     * Render custom block options (for custom mode).
     */
    public function invatrbl_render_custom_block_options_field()
    {
        $options = get_option($this->option_name);
        $custom_options = isset($options['custom_block_options']) ? (array)$options['custom_block_options'] : array();
    ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[custom_block_options][]" value="1" <?php checked(in_array(1, $custom_options)); ?> /> Block type 1 (Non‑residential)
        </label><br />
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[custom_block_options][]" value="2" <?php checked(in_array(2, $custom_options)); ?> /> Block type 2 (Residential suspicious)
        </label>
    <?php
    }

    /**
     * Render whitelist input field.
     */
    public function invatrbl_render_whitelist_field()
    {
        $options = get_option($this->option_name);
        $whitelist = isset($options['whitelisted_ips']) ? $options['whitelisted_ips'] : '';
    ?>
        <textarea name="<?php echo esc_attr($this->option_name); ?>[whitelisted_ips]" rows="5" cols="50"><?php echo esc_textarea($whitelist); ?></textarea>
        <p class="description">Enter one IP address per line. These IPs will bypass the block checks.</p>
    <?php
    }

    /**
     * Render cache duration field.
     */
    public function invatrbl_render_cache_duration_field()
    {
        $options = get_option($this->option_name);
        $cache_duration = isset($options['cache_duration']) ? (int)$options['cache_duration'] : 1;
    ?>
        <input type="number" name="<?php echo esc_attr($this->option_name); ?>[cache_duration]" value="<?php echo esc_attr($cache_duration); ?>" min="1" />
        <p class="description">Set the number of hours to cache API responses. Default is 1 hour.</p>
    <?php
    }

    /**
     * Allow Known Bot field.
     */
    public function invatrbl_render_allow_crawlers_field()
    {
        $options = get_option($this->option_name);
        $checked = ! empty($options['allow_crawlers']) ? 1 : 0;
    ?>
        <label>
            <input type="checkbox"
                name="<?php echo esc_attr($this->option_name); ?>[allow_crawlers]"
                value="1" <?php checked($checked, 1); ?> />
            <?php esc_html_e('Skip IP check for known crawler User-Agents', 'invalid-traffic-blocker'); ?>
        </label>
    <?php
    }

    public function invatrbl_render_additional_crawlers_field()
    {
        $options = get_option($this->option_name);
        $value = isset($options['additional_crawlers']) ? $options['additional_crawlers'] : '';
        $is_premium = $this->is_premium_active();
    ?>
        <textarea
            name="<?php echo esc_attr($this->option_name); ?>[additional_crawlers]"
            rows="3" cols="50"
            <?php echo $is_premium ? '' : 'disabled'; ?>
            placeholder="<?php esc_attr_e('One regex per line, e.g. ^MyCustomBot', 'invalid-traffic-blocker'); ?>"><?php echo esc_textarea($value); ?></textarea>
        <?php if (!$is_premium): ?>
            <p class="description premium-notice">
                🚀 <strong>Premium Feature:</strong> Add custom crawler patterns with your premium license.
            </p>
        <?php else: ?>
            <p class="description">
                <?php esc_html_e('Add any extra User-Agent patterns (one per line) to whitelist.', 'invalid-traffic-blocker'); ?>
            </p>
        <?php endif; ?>
    <?php
    }


    /**
     * Retrieve the user's IP considering proxy headers.
     */
    private function invatrbl_get_user_ip()
    {
        if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $x_forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            $ips = explode(',', $x_forwarded);
            return sanitize_text_field(trim($ips[0]));
        } elseif (! empty($_SERVER['HTTP_CLIENT_IP'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (! empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return '0.0.0.0';
    }

    /**
     * Render the plugin settings page with modern tabbed interface.
     */
    public function invatrbl_render_settings_page()
    {
        $admin_ip = $this->invatrbl_get_user_ip();
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        $is_premium = $this->is_premium_active();
    ?>
        <div class="wrap invatrbl-admin-wrap">
            <h1 class="invatrbl-main-title">
                <span class="dashicons dashicons-shield-alt"></span>
                Invalid Traffic Blocker
                <?php if ($is_premium): ?>
                    <span class="premium-badge">PRO</span>
                <?php endif; ?>
            </h1>

            <nav class="nav-tab-wrapper invatrbl-nav-tabs">
                <a href="<?php echo admin_url('admin.php?page=invalid_traffic_blocker&tab=settings'); ?>"
                    class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings"></span>
                    Settings
                </a>
                <?php if ($is_premium): ?>
                    <a href="<?php echo admin_url('admin.php?page=invalid_traffic_blocker&tab=analytics'); ?>"
                        class="nav-tab <?php echo $active_tab === 'analytics' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-chart-area"></span>
                        Analytics
                    </a>
                <?php endif; ?>
                <a href="<?php echo admin_url('admin.php?page=invalid_traffic_blocker&tab=tools'); ?>"
                    class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-tools"></span>
                    Tools
                </a>
                <?php if (!$is_premium): ?>
                    <a href="<?php echo admin_url('admin.php?page=invalid_traffic_blocker&tab=premium'); ?>"
                        class="nav-tab nav-tab-premium <?php echo $active_tab === 'premium' ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-star-filled"></span>
                        Go Premium
                    </a>
                <?php endif; ?>
            </nav>

            <div class="invatrbl-tab-content">
                <?php
                switch ($active_tab) {
                    case 'analytics':
                        $this->render_analytics_tab();
                        break;
                    case 'tools':
                        $this->render_tools_tab();
                        break;
                    case 'premium':
                        $this->render_premium_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
    <?php
    }

    /**
     * Render main settings tab
     */
    private function render_settings_tab()
    {
    ?>
        <div class="invatrbl-settings-container">
            <form method="post" action="options.php" class="invatrbl-settings-form">
                <?php
                settings_fields($this->option_group);
                do_settings_sections('invalid_traffic_blocker');
                submit_button('Save Settings', 'primary', 'submit', false, ['class' => 'button-hero']);
                ?>
            </form>
        </div>
    <?php
    }

    /**
     * Render tools tab
     */
    private function render_tools_tab()
    {
    ?>
        <div class="invatrbl-tools-container">
            <div class="invatrbl-card">
                <h2><span class="dashicons dashicons-admin-tools"></span> API Testing & Tools</h2>
                <p class="description">Test your API connectivity and manage your IP whitelist.</p>

                <div class="invatrbl-tools-grid">
                    <div class="tool-item">
                        <h3>API Connectivity Test</h3>
                        <p>Test your API connection using your current IP address: <code><?php echo esc_html($this->invatrbl_get_user_ip()); ?></code></p>
                        <button id="invatrbl-test-api" class="button button-secondary">
                            <span class="dashicons dashicons-networking"></span>
                            Test API Connection
                        </button>
                    </div>

                    <div class="tool-item">
                        <h3>Quick IP Whitelist</h3>
                        <p>Add your current IP to the whitelist to prevent being blocked during testing.</p>
                        <button id="invatrbl-whitelist-my-ip" class="button button-secondary">
                            <span class="dashicons dashicons-unlock"></span>
                            Whitelist My IP
                        </button>
                    </div>
                </div>

                <div id="invatrbl-test-result" class="tool-result"></div>
            </div>

            <div class="invatrbl-card">
                <h2><span class="dashicons dashicons-external"></span> External Resources</h2>
                <div class="external-links">
                    <a href="https://iphub.info/register" target="_blank" class="button button-secondary">
                        <span class="dashicons dashicons-external"></span>
                        Register for IPHub.info
                    </a>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render analytics tab (Premium only)
     */
    private function render_analytics_tab()
    {
        if (!$this->is_premium_active()) {
            $this->render_premium_upgrade_notice();
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'invatrbl_logs';

        // Get stats for last 30 days
        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(blocked_at) as date,
                COUNT(*) as blocks,
                COUNT(DISTINCT ip_address) as unique_ips
            FROM $table_name 
            WHERE blocked_at >= %s 
            GROUP BY DATE(blocked_at) 
            ORDER BY date DESC
            LIMIT 30
        ", date('Y-m-d', strtotime('-30 days'))));

        $total_blocks = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $table_name WHERE blocked_at >= %s
        ", date('Y-m-d', strtotime('-30 days'))));

    ?>
        <div class="invatrbl-analytics-container">
            <div class="invatrbl-stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-shield-alt"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($total_blocks); ?></h3>
                        <p>Blocked Requests (30 days)</p>
                    </div>
                </div>
            </div>

            <div class="invatrbl-card">
                <h2><span class="dashicons dashicons-chart-area"></span> Daily Blocking Statistics</h2>
                <table class="wp-list-table widefat fixed striped invatrbl-analytics-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Blocks</th>
                            <th>Unique IPs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats)): ?>
                            <tr>
                                <td colspan="3" class="no-data">No blocking data available yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stats as $stat): ?>
                                <tr>
                                    <td><?php echo esc_html(date('M j, Y', strtotime($stat->date))); ?></td>
                                    <td><strong><?php echo esc_html($stat->blocks); ?></strong></td>
                                    <td><?php echo esc_html($stat->unique_ips); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php
    }

    /**
     * Render premium upgrade tab
     */
    private function render_premium_tab()
    {
    ?>
        <div class="invatrbl-premium-container">
            <div class="premium-hero">
                <div class="premium-hero-content">
                    <h2><span class="dashicons dashicons-star-filled"></span> Upgrade to Premium</h2>
                    <p class="premium-subtitle">Unlock powerful features to better protect your website</p>
                </div>
            </div>

            <div class="premium-features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <span class="dashicons dashicons-networking"></span>
                    </div>
                    <h3>Multiple IP Providers</h3>
                    <p>Access to IPQualityScore, IPAPI, and ProxyCheck.io for better accuracy and redundancy.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <span class="dashicons dashicons-chart-area"></span>
                    </div>
                    <h3>Advanced Analytics</h3>
                    <p>Detailed blocking statistics, trends, and insights to understand your traffic patterns.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <span class="dashicons dashicons-admin-users"></span>
                    </div>
                    <h3>Custom Crawler Patterns</h3>
                    <p>Add your own User-Agent patterns to whitelist legitimate crawlers and bots.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <span class="dashicons dashicons-sos"></span>
                    </div>
                    <h3>Priority Support</h3>
                    <p>Get faster response times and priority assistance from our support team.</p>
                </div>
            </div>

            <div class="premium-cta">
                <a href="https://yourdomain.com/premium" target="_blank" class="button button-primary button-hero">
                    <span class="dashicons dashicons-cart"></span>
                    Get Premium License
                </a>
                <p class="premium-cta-note">30-day money-back guarantee • Instant activation</p>
            </div>
        </div>
    <?php
    }

    /**
     * Render premium upgrade notice
     */
    private function render_premium_upgrade_notice()
    {
    ?>
        <div class="invatrbl-premium-notice">
            <div class="premium-notice-content">
                <h2><span class="dashicons dashicons-lock"></span> Premium Feature</h2>
                <p>This feature is available with a premium license.</p>
                <a href="<?php echo admin_url('admin.php?page=invalid_traffic_blocker&tab=premium'); ?>" class="button button-primary">
                    Learn More About Premium
                </a>
            </div>
        </div>
<?php
    }

    /**
     * AJAX callback: Test API connectivity using the admin's IP.
     */
    public function invatrbl_test_api_connectivity()
    {
        // Verify nonce.
        check_ajax_referer('invatrbl_test_api_nonce');

        // Verify user permissions.
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'invalid-traffic-blocker'));
        }

        $options = get_option($this->option_name);
        if (empty($options['api_key'])) {
            echo '<div style="border: 1px solid red; padding:10px; background-color:#f2dede; color:#a94442;">'
                . esc_html__('Error: API key is not set.', 'invalid-traffic-blocker')
                . '</div>';
            wp_die();
        }

        $api_key = $options['api_key'];
        $test_ip = $this->invatrbl_get_user_ip();

        $response = wp_remote_get("http://v2.api.iphub.info/ip/" . $test_ip, array(
            'headers' => array('X-Key' => $api_key),
            'timeout' => 5,
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            echo '<div style="border: 1px solid red; padding:10px; background-color:#f2dede; color:#a94442;">'
                . esc_html__('Error: API Connection Error: ', 'invalid-traffic-blocker')
                . esc_html($error_message)
                . '</div>';
            wp_die();
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            echo '<div style="border: 1px solid red; padding:10px; background-color:#f2dede; color:#a94442;">'
                . esc_html__('Error: API Error: HTTP Code ', 'invalid-traffic-blocker')
                . esc_html($code)
                . '</div>';
            wp_die();
        }

        $body = wp_remote_retrieve_body($response);
        echo '<div style="border: 1px solid green; padding:10px; background-color:#dff0d8; color:#3c763d;">'
            . esc_html__('Success: API Response: ', 'invalid-traffic-blocker')
            . esc_html($body)
            . '</div>';
        wp_die();
    }


    /**
     * Check visitor's IP against the IPHub API and block if necessary.
     */
    public function invatrbl_check_visitor_ip()
    {
        // Do not run check in the admin area.
        if (is_admin()) {
            return;
        }

        // 1) Optionally skip known crawlers:
        $options = get_option($this->option_name);
        if (! empty($options['allow_crawlers'])) {

            // Default known crawler patterns:
            $patterns = array(
                'Googlebot',
                'bingbot',
                'Slurp',
                'DuckDuckBot',
                'Baiduspider',
                'YandexBot',
            );

            // Merge admin’s additional patterns:
            if (! empty($options['additional_crawlers'])) {
                $extra = explode("\n", $options['additional_crawlers']);
                $patterns = array_merge($patterns, $extra);
            }

            // Sanitize the User-Agent before using in preg_match()
            $ua_raw = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_UNSAFE_RAW);
            $ua     = sanitize_text_field($ua_raw ?: '');

            foreach ($patterns as $pat) {
                if (preg_match('/' . trim($pat) . '/i', $ua)) {
                    return;  // Allow this crawler
                }
            }
        }

        $options = get_option($this->option_name);
        if (empty($options['enabled']) || empty($options['api_key'])) {
            return;
        }
        $api_key = $options['api_key'];
        $provider = $options['provider'] ?? 'iphub';
        $visitor_ip = $this->invatrbl_get_user_ip();

        // Check whitelist.
        if (! empty($options['whitelisted_ips'])) {
            $whitelist = array_filter(array_map('trim', explode("\n", $options['whitelisted_ips'])));
            if (in_array($visitor_ip, $whitelist)) {
                return;
            }
        }

        // Determine cache duration.
        $cache_hours = isset($options['cache_duration']) ? absint($options['cache_duration']) : 1;
        $cache_duration = $cache_hours * HOUR_IN_SECONDS;

        // Use a prefixed transient key.
        $transient_key = 'invatrbl_check_' . md5($visitor_ip . $provider);
        $ip_data = get_transient($transient_key);

        if (false === $ip_data) {
            $ip_data = $this->query_ip_provider($visitor_ip, $provider, $api_key);
            if ($ip_data !== false) {
                set_transient($transient_key, $ip_data, $cache_duration);
            } else {
                return; // Allow access if API connection fails.
            }
        }

        $block_ip = $this->should_block_ip($ip_data, $options);

        if ($block_ip) {
            // Log blocked IP for analytics (premium feature)
            $this->log_blocked_ip(
                $visitor_ip,
                $this->get_block_reason($ip_data, $options),
                $provider,
                $ip_data['country'] ?? ''
            );
            // Force output as HTML.
            header('Content-Type: text/html; charset=UTF-8');
            wp_die(
                '<h1>Access Restricted</h1>
                <p>Your access has been restricted because your IP address has been flagged as suspicious (e.g., use of VPN or invalid traffic).</p>
                <p>Please disable your VPN or contact your network administrator if you believe this is an error.</p>',
                esc_html__('Access Restricted', 'invalid-traffic-blocker'),
                array(
                    'response'  => 403,
                    'back_link' => false,
                    'exit'      => true
                )
            );
        }
    }

    /**
     * Query IP information from the selected provider
     */
    private function query_ip_provider($ip, $provider, $api_key)
    {
        $endpoint = $this->get_api_endpoint($provider, $ip, $api_key);
        $headers = $this->get_api_headers($provider, $api_key);

        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return $this->parse_provider_response($body, $provider);
    }

    /**
     * Get API endpoint for the selected provider
     */
    private function get_api_endpoint($provider, $ip, $api_key)
    {
        switch ($provider) {
            case 'ipqualityscore':
                return "https://ipqualityscore.com/api/json/ip/{$api_key}/{$ip}";
            case 'ipapi':
                return "http://ip-api.com/json/{$ip}?fields=status,proxy,hosting,country";
            case 'proxycheck':
                return "https://proxycheck.io/v2/{$ip}?key={$api_key}&vpn=1&asn=1";
            default: // iphub
                return "http://v2.api.iphub.info/ip/{$ip}";
        }
    }

    /**
     * Get API headers for the selected provider
     */
    private function get_api_headers($provider, $api_key)
    {
        switch ($provider) {
            case 'iphub':
                return array('X-Key' => $api_key);
            case 'ipqualityscore':
            case 'ipapi':
            case 'proxycheck':
            default:
                return array();
        }
    }

    /**
     * Parse provider response to standardized format
     */
    private function parse_provider_response($body, $provider)
    {
        $data = json_decode($body, true);
        if (!$data) {
            return false;
        }

        switch ($provider) {
            case 'ipqualityscore':
                return [
                    'block' => ($data['proxy'] || $data['vpn'] || $data['tor']) ? 1 : 0,
                    'country' => $data['country_code'] ?? '',
                    'isp' => $data['ISP'] ?? ''
                ];

            case 'ipapi':
                return [
                    'block' => ($data['proxy'] || $data['hosting']) ? 1 : 0,
                    'country' => $data['countryCode'] ?? '',
                    'isp' => $data['isp'] ?? ''
                ];

            case 'proxycheck':
                $ip_key = array_keys($data)[0] ?? '';
                $ip_data = $data[$ip_key] ?? [];
                return [
                    'block' => ($ip_data['proxy'] === 'yes') ? 1 : 0,
                    'country' => $ip_data['country'] ?? '',
                    'isp' => $ip_data['isp'] ?? ''
                ];

            default: // iphub
                return [
                    'block' => intval($data['block'] ?? 0),
                    'country' => $data['countryCode'] ?? '',
                    'isp' => $data['isp'] ?? ''
                ];
        }
    }

    /**
     * Determine if IP should be blocked based on settings
     */
    private function should_block_ip($ip_data, $options)
    {
        if (! empty($options['safe_mode'])) {
            return isset($ip_data['block']) && (int)$ip_data['block'] === 1;
        } elseif (! empty($options['strict_mode'])) {
            return isset($ip_data['block']) && in_array((int)$ip_data['block'], array(1, 2));
        } elseif (! empty($options['custom_mode'])) {
            $custom_options = isset($options['custom_block_options']) ? (array)$options['custom_block_options'] : array();
            return isset($ip_data['block']) && in_array((int)$ip_data['block'], $custom_options);
        }

        return false;
    }

    /**
     * Get block reason for logging
     */
    private function get_block_reason($ip_data, $options)
    {
        if (! empty($options['safe_mode'])) {
            return 'Safe Mode: Non-residential IP';
        } elseif (! empty($options['strict_mode'])) {
            return 'Strict Mode: Suspicious IP';
        } elseif (! empty($options['custom_mode'])) {
            return 'Custom Mode: Block type ' . $ip_data['block'];
        }

        return 'Unknown';
    }

    /**
     * AJAX callback for license validation
     */
    public function invatrbl_validate_license_ajax()
    {
        check_ajax_referer('invatrbl_license_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $license_key = sanitize_text_field($_POST['license_key'] ?? '');
        $is_valid = $this->validate_license($license_key);

        wp_send_json([
            'valid' => $is_valid,
            'message' => $is_valid ? 'Valid license' : 'Invalid license'
        ]);
    }
}

// Global sanitization function.
if (! function_exists('invatrbl_sanitize_settings')) {
    function invatrbl_sanitize_settings($input)
    {
        return INVATRBL_Plugin::invatrbl_sanitize_settings($input);
    }
}

new INVATRBL_Plugin();
