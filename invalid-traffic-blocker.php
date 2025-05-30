<?php

/**
 * Plugin Name: Invalid Traffic Blocker
 * Plugin URI: https://wordpress.org/plugins/invalid-traffic-blocker
 * Description: Blocks unwanted traffic using the IPHub.info API to protect AdSense publishers from invalid traffic. This is not an official plugin for IPHub.info.
 * Short Description: Protect your site from invalid traffic by blocking suspicious IPs using the IPHub.info API.
 * Version: 1.3
 * Author: Michael Akinwumi
 * Author URI: https://michaelakinwumi.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: invalid-traffic-blocker
 * Requires at least: 4.5
 * Requires PHP: 7.2
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class INVATRBL_Plugin
{

    private $option_group = 'invatrbl_options';
    private $option_name  = 'invatrbl_options';

    public function __construct()
    {
        // Load admin settings and enqueue scripts.
        add_action('admin_menu', [$this, 'invatrbl_add_settings_page']);
        add_action('admin_init', [$this, 'invatrbl_register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'invatrbl_admin_enqueue_scripts']);

        // AJAX callback for testing API connectivity.
        add_action('wp_ajax_invatrbl_test_api', [$this, 'invatrbl_test_api_connectivity']);

        // Frontend: Check and block invalid IPs.
        add_action('init', [$this, 'invatrbl_check_visitor_ip']);
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
            '1.2.1',
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
    }

    /**
     * Add settings page under Settings menu.
     */
    public function invatrbl_add_settings_page()
    {
        add_options_page(
            'Invalid Traffic Blocker Settings',
            'Invalid Traffic Blocker',
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
        // Use a literal callback function.
        register_setting($this->option_group, $this->option_name, 'invatrbl_sanitize_settings');

        add_settings_section(
            'invatrbl_main_section',
            'Main Settings',
            null,
            'invalid_traffic_blocker'
        );

        // API Key field.
        add_settings_field(
            'api_key',
            'IPHub API Key',
            [$this, 'invatrbl_render_api_key_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Enable/disable toggle.
        add_settings_field(
            'enabled',
            'Enable Invalid Traffic Blocker',
            [$this, 'invatrbl_render_enabled_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Blocking mode checkboxes.
        add_settings_field(
            'blocking_modes',
            'Blocking Options (Select one)',
            [$this, 'invatrbl_render_blocking_modes_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Custom Mode: Select specific block types.
        add_settings_field(
            'custom_block_options',
            'Custom Block Options',
            [$this, 'invatrbl_render_custom_block_options_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Whitelisted IP addresses.
        add_settings_field(
            'whitelisted_ips',
            'Whitelist IP Addresses',
            [$this, 'invatrbl_render_whitelist_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Cache Duration.
        add_settings_field(
            'cache_duration',
            'Cache Duration (Hours)',
            [$this, 'invatrbl_render_cache_duration_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Allow Known Crawlers
        add_settings_field(
            'allow_crawlers',
            'Allow Known Crawlers',
            [$this, 'invatrbl_render_allow_crawlers_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        add_settings_field(
            'additional_crawlers',
            'Additional Crawler Patterns',
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

        $new_input['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        $new_input['enabled'] = isset($input['enabled']) ? absint($input['enabled']) : 0;

        // Blocking modes.
        $new_input['safe_mode']   = isset($input['safe_mode']) ? 1 : 0;
        $new_input['strict_mode'] = isset($input['strict_mode']) ? 1 : 0;
        $new_input['custom_mode'] = isset($input['custom_mode']) ? 1 : 0;

        // For custom mode, store allowed block types.
        if (! empty($new_input['custom_mode'])) {
            $custom = array();
            if (isset($input['custom_block_options']) && is_array($input['custom_block_options'])) {
                foreach ($input['custom_block_options'] as $block_option) {
                    $custom[] = absint($block_option);
                }
            }
            $new_input['custom_block_options'] = $custom;
        } else {
            $new_input['custom_block_options'] = array();
        }

        // Ensure only one blocking mode is active.
        $modes_active = (int)$new_input['safe_mode'] + (int)$new_input['strict_mode'] + (int)$new_input['custom_mode'];
        if ($modes_active > 1) {
            add_settings_error('invatrbl_options', 'mode_error', 'Please select only one blocking mode option.', 'error');
            $new_input['safe_mode']   = 1;
            $new_input['strict_mode'] = 0;
            $new_input['custom_mode'] = 0;
            $new_input['custom_block_options'] = array();
        }

        // Sanitize the whitelist.
        if (isset($input['whitelisted_ips'])) {
            $lines = explode("\n", $input['whitelisted_ips']);
            $ips   = array();
            foreach ($lines as $line) {
                $ip = trim(sanitize_text_field($line));
                if (! empty($ip)) {
                    $ips[] = $ip;
                }
            }
            $new_input['whitelisted_ips'] = implode("\n", $ips);
        } else {
            $new_input['whitelisted_ips'] = '';
        }

        // Cache duration (default 1 hour).
        $new_input['cache_duration'] = isset($input['cache_duration']) ? absint($input['cache_duration']) : 1;
        if ($new_input['cache_duration'] < 1) {
            $new_input['cache_duration'] = 1;
        }

        // Default: allow known crawlers
        $new_input['allow_crawlers'] = isset($input['allow_crawlers']) ? 1 : 0;

        // Sanitize admin’s extra patterns (one per line)
        if (! empty($input['additional_crawlers'])) {
            $lines = explode("\n", $input['additional_crawlers']);
            $patterns = array();
            foreach ($lines as $line) {
                $p = trim(sanitize_text_field($line));
                if ($p) {
                    $patterns[] = $p;
                }
            }
            $new_input['additional_crawlers'] = implode("\n", $patterns);
        } else {
            $new_input['additional_crawlers'] = '';
        }


        return $new_input;
    }

    /**
     * Render the API Key field.
     */
    public function invatrbl_render_api_key_field()
    {
        $options = get_option($this->option_name);
?>
        <input type="text" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="<?php echo isset($options['api_key']) ? esc_attr($options['api_key']) : ''; ?>" size="40" />
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
    ?>
        <textarea
            name="<?php echo esc_attr($this->option_name); ?>[additional_crawlers]"
            rows="3" cols="50"
            placeholder="<?php esc_attr_e('One regex per line, e.g. ^MyCustomBot', 'invalid-traffic-blocker'); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('Add any extra User-Agent patterns (one per line) to whitelist.', 'invalid-traffic-blocker'); ?>
        </p>
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
     * Render the plugin settings page.
     */
    public function invatrbl_render_settings_page()
    {
        $admin_ip = $this->invatrbl_get_user_ip();
    ?>
        <div class="wrap">
            <h1>Invalid Traffic Blocker Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_group);
                do_settings_sections('invalid_traffic_blocker');
                submit_button();
                ?>
            </form>
            <p>
                <a href="https://iphub.info/register" target="_blank" class="button button-secondary">Register for IPHub.info</a>
            </p>
            <!-- Buttons will be handled by admin.js -->
            <p>
                <button id="invatrbl-test-api" class="button">Test API Connectivity (Using Your IP)</button>
                <button id="invatrbl-whitelist-my-ip" class="button">Whitelist My IP</button>
            </p>
            <div id="invatrbl-test-result" style="margin-top:10px;"></div>
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
        $transient_key = 'invatrbl_check_' . md5($visitor_ip);
        $ip_data = get_transient($transient_key);

        if (false === $ip_data) {
            $response = wp_remote_get("http://v2.api.iphub.info/ip/" . $visitor_ip, array(
                'headers' => array('X-Key' => $api_key),
                'timeout' => 5,
            ));

            if (is_wp_error($response)) {
                return; // Allow access if API connection fails.
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return;
            }

            $body = wp_remote_retrieve_body($response);
            $ip_data = json_decode($body, true);
            set_transient($transient_key, $ip_data, $cache_duration);
        }

        $block_ip = false;
        if (! empty($options['safe_mode'])) {
            if (isset($ip_data['block']) && (int)$ip_data['block'] === 1) {
                $block_ip = true;
            }
        } elseif (! empty($options['strict_mode'])) {
            if (isset($ip_data['block']) && in_array((int)$ip_data['block'], array(1, 2))) {
                $block_ip = true;
            }
        } elseif (! empty($options['custom_mode'])) {
            $custom_options = isset($options['custom_block_options']) ? (array)$options['custom_block_options'] : array();
            if (isset($ip_data['block']) && in_array((int)$ip_data['block'], $custom_options)) {
                $block_ip = true;
            }
        }

        if ($block_ip) {
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
}

// Global sanitization function.
if (! function_exists('invatrbl_sanitize_settings')) {
    function invatrbl_sanitize_settings($input)
    {
        return INVATRBL_Plugin::invatrbl_sanitize_settings($input);
    }
}

new INVATRBL_Plugin();
