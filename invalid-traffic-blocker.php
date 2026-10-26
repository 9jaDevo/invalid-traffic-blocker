<?php

/**
 * Plugin Name: Invalid Traffic Blocker
 * Plugin URI: https://wordpress.org/plugins/invalid-traffic-blocker
 * Description: Blocks unwanted traffic using the IPHub.info API to protect AdSense publishers from invalid traffic. This is not an official plugin for IPHub.info.
 * Short Description: Protect your site from invalid traffic by blocking suspicious IPs using the IPHub.info API.
 * Version: 1.5.0
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

    const API_URL = 'https://v2.api.iphub.info/ip/';

    // IVTB, the enterprise version for sites not built on WordPress.
    const ENTERPRISE_URL = 'https://ivtb.uk/';

    // After an API failure, skip lookups for this long so visitors don't wait on a failing API.
    const API_BACKOFF_SECONDS = 300;

    private $option_group = 'invatrbl_options';
    private $option_name  = 'invatrbl_options';

    /**
     * Crawlers that can be confirmed with a reverse DNS lookup, mapped to the
     * hostname suffixes their operators publish.
     */
    private static $verified_crawlers = array(
        'Googlebot'   => array('.googlebot.com', '.google.com'),
        'bingbot'     => array('.search.msn.com'),
        'Slurp'       => array('.crawl.yahoo.net'),
        'Baiduspider' => array('.baidu.com', '.baidu.jp'),
        'YandexBot'   => array('.yandex.ru', '.yandex.net', '.yandex.com'),
    );

    /**
     * Cloudflare edge ranges (https://www.cloudflare.com/ips/). In automatic mode,
     * CF-Connecting-IP is trusted only when the connection comes from one of these.
     */
    private static $cloudflare_ranges = array(
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    );

    public function __construct()
    {
        // Load admin settings and enqueue scripts.
        add_action('admin_menu', [$this, 'invatrbl_add_settings_page']);
        add_action('admin_init', [$this, 'invatrbl_register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'invatrbl_admin_enqueue_scripts']);

        // Plugins screen: link to the enterprise version next to "View details".
        add_filter('plugin_row_meta', [$this, 'invatrbl_plugin_row_meta'], 10, 2);

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
            '1.5.0',
            true
        );
        // Pass some variables to our script.
        wp_localize_script('invatrbl-admin-js', 'invatrblVars', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('invatrbl_test_api_nonce'),
            'adminIP'   => $this->invatrbl_get_user_ip(),
            'optionName' => $this->option_name,
            'testing' => __('Testing saved connection…', 'invalid-traffic-blocker'),
            'testError' => __('The test could not finish. Check your connection and try again.', 'invalid-traffic-blocker'),
            'added' => __('Your IP was added. Save changes to apply it.', 'invalid-traffic-blocker'),
            'exists' => __('Your IP is already in the list.', 'invalid-traffic-blocker'),
            'missingIP' => __('Your IP could not be detected. Add a trusted IP manually.', 'invalid-traffic-blocker'),
            'unsaved' => __('You have unsaved changes.', 'invalid-traffic-blocker'),
            'show' => __('Show key', 'invalid-traffic-blocker'),
            'hide' => __('Hide key', 'invalid-traffic-blocker'),
        ));
        wp_enqueue_script('invatrbl-admin-js');
        wp_enqueue_style('invatrbl-admin', plugin_dir_url(__FILE__) . 'css/admin.css', array(), '1.5.0');
    }

    /**
     * Add a link to IVTB (the enterprise version for non-WordPress sites) on the Plugins screen.
     */
    public function invatrbl_plugin_row_meta($links, $file)
    {
        if (plugin_basename(__FILE__) === $file) {
            $links[] = '<a href="' . esc_url(self::ENTERPRISE_URL) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Enterprise version for non-WordPress sites', 'invalid-traffic-blocker')
                . '</a>';
        }
        return $links;
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
            __('Main Settings', 'invalid-traffic-blocker'),
            null,
            'invalid_traffic_blocker'
        );

        // API Key field.
        add_settings_field(
            'api_key',
            __('IPHub API Key', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_api_key_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Enable/disable toggle.
        add_settings_field(
            'enabled',
            __('Enable Invalid Traffic Blocker', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_enabled_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Blocking mode radios.
        add_settings_field(
            'blocking_mode',
            __('Blocking Mode', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_blocking_modes_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Custom Mode: Select specific block types.
        add_settings_field(
            'custom_block_options',
            __('Custom Block Options', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_custom_block_options_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Whitelisted IP addresses.
        add_settings_field(
            'whitelisted_ips',
            __('Whitelist IP Addresses', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_whitelist_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Where the visitor IP is read from.
        add_settings_field(
            'ip_source',
            __('Visitor IP Source', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_ip_source_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Cache Duration.
        add_settings_field(
            'cache_duration',
            __('Cache Duration (Hours)', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_cache_duration_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        // Allow Known Crawlers
        add_settings_field(
            'allow_crawlers',
            __('Allow Known Crawlers', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_allow_crawlers_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );

        add_settings_field(
            'additional_crawlers',
            __('Additional Crawler Patterns', 'invalid-traffic-blocker'),
            [$this, 'invatrbl_render_additional_crawlers_field'],
            'invalid_traffic_blocker',
            'invatrbl_main_section'
        );
    }

    /**
     * Sanitize and validate settings input.
     *
     * WordPress can run this twice on one save (update_option() then add_option()
     * on the first save), so running it on its own output must not change anything.
     */
    public static function invatrbl_sanitize_settings($input)
    {
        $input     = is_array($input) ? $input : array();
        $new_input = array();

        $new_input['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        $new_input['enabled'] = ! empty($input['enabled']) ? 1 : 0;

        // Blocking mode: safe, strict or custom.
        $new_input['blocking_mode'] = self::invatrbl_get_blocking_mode($input);

        // For custom mode, store the selected block types (1 and/or 2).
        $custom = array();
        if ('custom' === $new_input['blocking_mode'] && isset($input['custom_block_options']) && is_array($input['custom_block_options'])) {
            foreach ($input['custom_block_options'] as $block_option) {
                $block_option = absint($block_option);
                if (in_array($block_option, array(1, 2), true) && ! in_array($block_option, $custom, true)) {
                    $custom[] = $block_option;
                }
            }
        }
        $new_input['custom_block_options'] = $custom;

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

        // Visitor IP source (default automatic).
        $ip_source = isset($input['ip_source']) ? sanitize_key($input['ip_source']) : 'auto';
        $new_input['ip_source'] = array_key_exists($ip_source, self::invatrbl_get_ip_sources()) ? $ip_source : 'auto';

        // Cache duration (default 1 hour).
        $new_input['cache_duration'] = isset($input['cache_duration']) ? absint($input['cache_duration']) : 1;
        if ($new_input['cache_duration'] < 1) {
            $new_input['cache_duration'] = 1;
        }

        $new_input['allow_crawlers'] = ! empty($input['allow_crawlers']) ? 1 : 0;

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
     * Get the blocking mode, falling back to the separate checkboxes used before 1.4.
     */
    private static function invatrbl_get_blocking_mode($options)
    {
        if (isset($options['blocking_mode']) && in_array($options['blocking_mode'], array('safe', 'strict', 'custom'), true)) {
            return $options['blocking_mode'];
        }
        if (! empty($options['safe_mode'])) {
            return 'safe';
        }
        if (! empty($options['strict_mode'])) {
            return 'strict';
        }
        if (! empty($options['custom_mode'])) {
            return 'custom';
        }
        return 'safe';
    }

    /**
     * Available visitor IP sources and their labels.
     */
    private static function invatrbl_get_ip_sources()
    {
        return array(
            'auto'            => __('Automatic (recommended)', 'invalid-traffic-blocker'),
            'remote_addr'     => __('Direct connection only (REMOTE_ADDR)', 'invalid-traffic-blocker'),
            'cloudflare'      => __('Cloudflare (CF-Connecting-IP header)', 'invalid-traffic-blocker'),
            'x_forwarded_for' => __('Reverse proxy or load balancer (X-Forwarded-For header)', 'invalid-traffic-blocker'),
        );
    }

    /**
     * Render the API Key field.
     */
    public function invatrbl_render_api_key_field()
    {
        $options = get_option($this->option_name);
?>
        <input id="invatrbl-api-key" type="password" autocomplete="off" spellcheck="false" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="<?php echo isset($options['api_key']) ? esc_attr($options['api_key']) : ''; ?>" size="40" />
        <button type="button" id="invatrbl-show-key" class="button invatrbl-js-only" aria-controls="invatrbl-api-key" aria-pressed="false"><?php esc_html_e('Show key', 'invalid-traffic-blocker'); ?></button>
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
        <label><input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[enabled]" value="1" <?php checked($enabled, 1); ?> /> <?php esc_html_e('Enable protection for visitors', 'invalid-traffic-blocker'); ?></label>
    <?php
    }

    /**
     * Render blocking mode options.
     */
    public function invatrbl_render_blocking_modes_field()
    {
        $options = get_option($this->option_name);
        $mode    = self::invatrbl_get_blocking_mode($options);
    ?>
        <label>
            <input type="radio" name="<?php echo esc_attr($this->option_name); ?>[blocking_mode]" value="safe" <?php checked($mode, 'safe'); ?> /> Safe Mode (Block only non‑residential IPs: block==1)
        </label><br />
        <label>
            <input type="radio" name="<?php echo esc_attr($this->option_name); ?>[blocking_mode]" value="strict" <?php checked($mode, 'strict'); ?> /> Strict Mode (Block both non‑residential and residential IPs: block==1 or block==2)
        </label><br />
        <label>
            <input type="radio" name="<?php echo esc_attr($this->option_name); ?>[blocking_mode]" value="custom" <?php checked($mode, 'custom'); ?> /> Custom Mode (Select specific block types below)
        </label>
        <p><em>Safe Mode is recommended.</em></p>
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
        <textarea id="invatrbl-whitelisted_ips" name="<?php echo esc_attr($this->option_name); ?>[whitelisted_ips]" rows="5" cols="50"><?php echo esc_textarea($whitelist); ?></textarea>
        <p class="description">Enter one IP address per line. These IPs will bypass the block checks.</p>
    <?php
    }

    /**
     * Render visitor IP source field, with what the plugin sees for the current request.
     */
    public function invatrbl_render_ip_source_field()
    {
        $options     = get_option($this->option_name);
        $source      = isset($options['ip_source']) ? $options['ip_source'] : 'auto';
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $forwarded   = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])) : '';
        $cf_ip       = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])) : '';
        $detected_ip = $this->invatrbl_get_user_ip();
    ?>
        <select id="invatrbl-ip_source" name="<?php echo esc_attr($this->option_name); ?>[ip_source]">
            <?php foreach (self::invatrbl_get_ip_sources() as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($source, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e('Automatic uses the direct connection address, and only trusts proxy headers when the request comes from Cloudflare or from a private network address. Choose a header option only if every request to your site passes through that proxy, otherwise visitors can fake their IP.', 'invalid-traffic-blocker'); ?>
        </p>
        <p class="description">
            <?php
            printf(
                /* translators: 1: IP the plugin uses, 2: REMOTE_ADDR value, 3: X-Forwarded-For value, 4: CF-Connecting-IP value. */
                esc_html__('Your current request: the plugin sees your IP as %1$s (REMOTE_ADDR: %2$s, X-Forwarded-For: %3$s, CF-Connecting-IP: %4$s).', 'invalid-traffic-blocker'),
                '<code>' . esc_html('' !== $detected_ip ? $detected_ip : '-') . '</code>',
                '<code>' . esc_html('' !== $remote_addr ? $remote_addr : '-') . '</code>',
                '<code>' . esc_html('' !== $forwarded ? $forwarded : '-') . '</code>',
                '<code>' . esc_html('' !== $cf_ip ? $cf_ip : '-') . '</code>'
            );
            ?>
        </p>
        <?php if (('' !== $forwarded || '' !== $cf_ip) && $detected_ip === $remote_addr) : ?>
            <p class="description" style="color:#b32d2e;">
                <?php esc_html_e('Your request carries proxy headers that the current setting ignores. If your site is behind a CDN or proxy, choose the matching option, or every visitor will be checked using the proxy’s IP.', 'invalid-traffic-blocker'); ?>
            </p>
        <?php endif; ?>
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
        <input id="invatrbl-cache_duration" type="number" name="<?php echo esc_attr($this->option_name); ?>[cache_duration]" value="<?php echo esc_attr($cache_duration); ?>" min="1" />
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
            <?php esc_html_e('Skip the IP check for verified search engine crawlers (Googlebot, Bingbot, Yahoo Slurp, Baiduspider, YandexBot)', 'invalid-traffic-blocker'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Each crawler is confirmed with a reverse DNS lookup, so a visitor who only fakes a crawler User-Agent is still checked.', 'invalid-traffic-blocker'); ?>
        </p>
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
            placeholder="<?php esc_attr_e('One User-Agent fragment per line, e.g. MyCustomBot', 'invalid-traffic-blocker'); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('Visitors whose User-Agent contains any of these (plain text, not case-sensitive) skip the IP check. These are not verified and can be faked, so only add crawlers you need.', 'invalid-traffic-blocker'); ?>
        </p>
    <?php
    }


    /**
     * Retrieve the visitor's IP address.
     *
     * Proxy headers can be sent by anyone, so they are only trusted when the admin
     * chose that source, or in automatic mode when the connection itself comes from
     * Cloudflare or a private network address. Returns '' if no valid IP is found.
     */
    private function invatrbl_get_user_ip()
    {
        $options     = get_option($this->option_name);
        $source      = isset($options['ip_source']) ? $options['ip_source'] : 'auto';
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $this->invatrbl_validate_ip(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';

        $use_cloudflare = 'cloudflare' === $source
            || ('auto' === $source && '' !== $remote_addr && $this->invatrbl_is_cloudflare_ip($remote_addr));
        $use_forwarded  = 'x_forwarded_for' === $source
            || ('auto' === $source && '' !== $remote_addr && ! $this->invatrbl_is_public_ip($remote_addr));

        if ($use_cloudflare && ! empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $this->invatrbl_validate_ip(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])));
            if ('' !== $ip) {
                return $ip;
            }
        }

        if ($use_forwarded && ! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Read from the right: proxies append to this header, and entries further left were sent by the client.
            $forwarded = array_reverse(explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))));
            foreach ($forwarded as $candidate) {
                $ip = $this->invatrbl_validate_ip(trim($candidate));
                if ('' !== $ip && $this->invatrbl_is_public_ip($ip) && ! $this->invatrbl_is_cloudflare_ip($ip)) {
                    return $ip;
                }
            }
        }

        return $remote_addr;
    }

    /**
     * Return the IP if it is a valid IPv4/IPv6 address, otherwise ''.
     */
    private function invatrbl_validate_ip($ip)
    {
        $valid = filter_var($ip, FILTER_VALIDATE_IP);
        return false === $valid ? '' : $valid;
    }

    /**
     * Whether the IP is outside private and reserved ranges.
     */
    private function invatrbl_is_public_ip($ip)
    {
        return false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Whether the IP belongs to a Cloudflare edge range.
     */
    private function invatrbl_is_cloudflare_ip($ip)
    {
        foreach (self::$cloudflare_ranges as $range) {
            if ($this->invatrbl_ip_in_range($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a valid IP falls inside a CIDR range (IPv4 or IPv6).
     */
    private function invatrbl_ip_in_range($ip, $cidr)
    {
        list($subnet, $bits) = explode('/', $cidr);
        $ip_bin     = inet_pton($ip);
        $subnet_bin = inet_pton($subnet);
        if (false === $ip_bin || false === $subnet_bin || strlen($ip_bin) !== strlen($subnet_bin)) {
            return false;
        }

        $bits      = (int) $bits;
        $full      = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (substr($ip_bin, 0, $full) !== substr($subnet_bin, 0, $full)) {
            return false;
        }
        if (0 === $remainder) {
            return true;
        }
        $mask = chr((0xff << (8 - $remainder)) & 0xff);
        return ($ip_bin[$full] & $mask) === ($subnet_bin[$full] & $mask);
    }

    /**
     * Render the plugin settings page.
     */
    public function invatrbl_render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $options = (array) get_option($this->option_name, array());
        $configured = ! empty($options['api_key']);
        $enabled = ! empty($options['enabled']);
        $active = $configured && $enabled && ! empty($this->invatrbl_get_block_types($options));
        $status = $active ? __('Protection enabled', 'invalid-traffic-blocker') : __('Protection inactive', 'invalid-traffic-blocker');
        $groups = array(
            array('connection', __('Connect IPHub', 'invalid-traffic-blocker'), __('Add your key, save changes, then test your connection.', 'invalid-traffic-blocker'), array('api_key')),
            array('protection', __('Protection rules', 'invalid-traffic-blocker'), __('Safe Mode is recommended. Strict Mode can block legitimate visitors on shared networks.', 'invalid-traffic-blocker'), array('enabled', 'blocking_mode', 'custom_block_options')),
            array('exceptions', __('Trusted visitors & crawlers', 'invalid-traffic-blocker'), __('Choose who can bypass IP checks. Only add exceptions you trust.', 'invalid-traffic-blocker'), array('whitelisted_ips', 'allow_crawlers', 'additional_crawlers')),
            array('network', __('Network & performance', 'invalid-traffic-blocker'), __('Check your visitor IP source and reuse cached results to reduce API requests.', 'invalid-traffic-blocker'), array('ip_source', 'cache_duration')),
        );
        global $wp_settings_fields;
        $fields = isset($wp_settings_fields['invalid_traffic_blocker']['invatrbl_main_section']) ? $wp_settings_fields['invalid_traffic_blocker']['invatrbl_main_section'] : array();
    ?>
        <div class="wrap invatrbl-admin">
            <header class="invatrbl-header">
                <div><p class="invatrbl-eyebrow"><?php esc_html_e('Visitor protection', 'invalid-traffic-blocker'); ?></p>
                <h1><?php esc_html_e('Invalid Traffic Blocker', 'invalid-traffic-blocker'); ?></h1>
                <p><?php esc_html_e('Clear rules. Trusted visitors. Less unwanted traffic.', 'invalid-traffic-blocker'); ?></p></div>
                <span class="invatrbl-status <?php echo $active ? 'is-active' : ''; ?>"><?php echo esc_html($status); ?></span>
            </header>
            <div class="invatrbl-summary">
                <strong><?php esc_html_e('Saved configuration', 'invalid-traffic-blocker'); ?></strong>
                <span><?php echo $configured ? esc_html__('API key saved · Connection not verified on this page', 'invalid-traffic-blocker') : esc_html__('Start by adding your IPHub API key below.', 'invalid-traffic-blocker'); ?></span>
                <span><?php esc_html_e('If IPHub is unavailable, visitors are allowed through.', 'invalid-traffic-blocker'); ?></span>
            </div>
            <nav class="invatrbl-nav" aria-label="<?php esc_attr_e('Settings sections', 'invalid-traffic-blocker'); ?>">
                <?php foreach ($groups as $group) : ?><a href="#invatrbl-<?php echo esc_attr($group[0]); ?>"><?php echo esc_html($group[1]); ?></a><?php endforeach; ?>
            </nav>
            <form id="invatrbl-settings-form" method="post" action="options.php">
                <?php settings_fields($this->option_group); ?>
                <?php foreach ($groups as $group) : ?>
                    <section class="invatrbl-card" id="invatrbl-<?php echo esc_attr($group[0]); ?>" aria-labelledby="invatrbl-title-<?php echo esc_attr($group[0]); ?>">
                        <h2 id="invatrbl-title-<?php echo esc_attr($group[0]); ?>"><?php echo esc_html($group[1]); ?></h2>
                        <p class="invatrbl-intro"><?php echo esc_html($group[2]); ?></p>
                        <?php foreach ($group[3] as $field_id) : if (! isset($fields[$field_id])) { continue; } $field = $fields[$field_id]; ?>
                            <div class="invatrbl-field" id="invatrbl-field-<?php echo esc_attr($field_id); ?>">
                                <fieldset><legend><?php if (in_array($field_id, array('api_key', 'whitelisted_ips', 'additional_crawlers', 'ip_source', 'cache_duration'), true)) : ?><label for="invatrbl-<?php echo esc_attr('api_key' === $field_id ? 'api-key' : $field_id); ?>"><?php echo esc_html($field['title']); ?></label><?php else : ?><?php echo esc_html($field['title']); ?><?php endif; ?></legend>
                                <?php call_user_func($field['callback'], $field['args']); ?></fieldset>
                            </div>
                        <?php endforeach; ?>
                        <?php if ('connection' === $group[0]) : ?>
                            <p class="description"><?php esc_html_e('Enabling protection sends visitor IP addresses and your API key to IPHub for uncached lookups. Testing sends your current IP using the saved key. Save a changed key before testing.', 'invalid-traffic-blocker'); ?></p>
                            <p><a href="https://iphub.info/register" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get an IPHub API key', 'invalid-traffic-blocker'); ?></a> · <a href="https://iphub.info/legal/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('IPHub privacy policy', 'invalid-traffic-blocker'); ?></a> · <a href="https://iphub.info/legal/tos" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Terms of service', 'invalid-traffic-blocker'); ?></a></p>
                            <button type="button" id="invatrbl-test-api" class="button invatrbl-js-only"><?php esc_html_e('Test saved connection', 'invalid-traffic-blocker'); ?></button>
                            <div id="invatrbl-test-result" role="status" aria-live="polite" aria-atomic="true"></div>
                        <?php elseif ('exceptions' === $group[0]) : ?>
                            <button type="button" id="invatrbl-whitelist-my-ip" class="button invatrbl-js-only"><?php esc_html_e('Add my current IP to trusted visitors', 'invalid-traffic-blocker'); ?></button>
                            <p id="invatrbl-whitelist-result" role="status" aria-live="polite"></p>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
                <div class="invatrbl-save"><?php submit_button(__('Save changes', 'invalid-traffic-blocker'), 'primary', 'submit', false); ?>
                    <span id="invatrbl-save-status" role="status" aria-live="polite"><?php esc_html_e('Changes apply after saving.', 'invalid-traffic-blocker'); ?></span>
                </div>
            </form>
            <p class="description invatrbl-footer"><?php esc_html_e('Protecting a non-WordPress site?', 'invalid-traffic-blocker'); ?> <a href="<?php echo esc_url(self::ENTERPRISE_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Explore IVTB', 'invalid-traffic-blocker'); ?></a></p>
        </div>
<?php
    }

    /**
     * Look up an IP address with the IPHub API.
     */
    private function invatrbl_api_request($ip, $api_key)
    {
        return wp_remote_get(self::API_URL . $ip, array(
            'headers' => array('X-Key' => $api_key),
            'timeout' => 5,
        ));
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
        if ('' === $test_ip) {
            echo '<div style="border: 1px solid red; padding:10px; background-color:#f2dede; color:#a94442;">'
                . esc_html__('Error: Could not determine a valid IP address for your connection.', 'invalid-traffic-blocker')
                . '</div>';
            wp_die();
        }

        $response = $this->invatrbl_api_request($test_ip, $api_key);

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

        // The API works again, so resume front-end lookups straight away.
        delete_transient('invatrbl_api_backoff');

        $body = wp_remote_retrieve_body($response);
        echo '<div style="border: 1px solid green; padding:10px; background-color:#dff0d8; color:#3c763d;">'
            . esc_html__('Success: API Response: ', 'invalid-traffic-blocker')
            . esc_html($body)
            . '</div>';
        wp_die();
    }

    /**
     * Requests that are never checked: admin screens, WP-Cron and WP-CLI (server-to-server
     * traffic from datacenter IPs), and the login screen so admins on a VPN can still sign in.
     * Uses constants and $pagenow instead of wp_doing_cron()/is_login() to keep WordPress 4.5 support.
     */
    private function invatrbl_should_skip_request()
    {
        global $pagenow;

        if (is_admin()) {
            return true;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }
        return 'wp-login.php' === $pagenow;
    }

    /**
     * IPHub block values that lead to a block in the chosen mode.
     */
    private function invatrbl_get_block_types($options)
    {
        $mode = self::invatrbl_get_blocking_mode($options);
        if ('strict' === $mode) {
            return array(1, 2);
        }
        if ('custom' === $mode) {
            return isset($options['custom_block_options']) ? array_map('absint', (array)$options['custom_block_options']) : array();
        }
        return array(1);
    }

    /**
     * Whether the visitor is a crawler that should skip the IP check.
     *
     * Built-in crawlers must pass a reverse DNS check, because anyone can send a
     * crawler User-Agent. The admin's additional patterns are plain-text matches.
     */
    private function invatrbl_is_allowed_crawler($ip, $options)
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ('' === $ua) {
            return false;
        }

        if (! empty($options['additional_crawlers'])) {
            foreach (explode("\n", $options['additional_crawlers']) as $pattern) {
                $pattern = trim($pattern);
                if ('' !== $pattern && false !== stripos($ua, $pattern)) {
                    return true;
                }
            }
        }

        foreach (self::$verified_crawlers as $token => $suffixes) {
            if (false !== stripos($ua, $token)) {
                return $this->invatrbl_verify_crawler_ip($ip, $suffixes);
            }
        }
        return false;
    }

    /**
     * Confirm a crawler IP: its reverse DNS name must end in one of the crawler's
     * domains and resolve back to the same IP. The result is cached for a day.
     */
    private function invatrbl_verify_crawler_ip($ip, $suffixes)
    {
        $transient_key = 'invatrbl_crawler_' . md5($ip);
        $cached = get_transient($transient_key);
        if (false !== $cached) {
            return 'yes' === $cached;
        }

        $verified = false;
        $host = function_exists('gethostbyaddr') ? gethostbyaddr($ip) : false;
        if ($host && $host !== $ip) {
            $host = strtolower($host);
            foreach ($suffixes as $suffix) {
                if (substr($host, -strlen($suffix)) === $suffix) {
                    $verified = in_array(inet_pton($ip), $this->invatrbl_resolve_host($host, $ip), true);
                    break;
                }
            }
        }

        set_transient($transient_key, $verified ? 'yes' : 'no', DAY_IN_SECONDS);
        return $verified;
    }

    /**
     * Forward-resolve a hostname to packed addresses of the same family as $ip.
     */
    private function invatrbl_resolve_host($host, $ip)
    {
        $addresses = array();
        if (false === strpos($ip, ':')) {
            $ipv4 = gethostbynamel($host);
            foreach (is_array($ipv4) ? $ipv4 : array() as $address) {
                $addresses[] = inet_pton($address);
            }
        } elseif (function_exists('dns_get_record')) {
            $records = dns_get_record($host, DNS_AAAA);
            foreach (is_array($records) ? $records : array() as $record) {
                if (! empty($record['ipv6'])) {
                    $addresses[] = inet_pton($record['ipv6']);
                }
            }
        }
        return $addresses;
    }


    /**
     * Check visitor's IP against the IPHub API and block if necessary.
     */
    public function invatrbl_check_visitor_ip()
    {
        // Do not run check in the admin area, cron, WP-CLI or on the login screen.
        if ($this->invatrbl_should_skip_request()) {
            return;
        }

        $options = get_option($this->option_name);
        if (empty($options['enabled']) || empty($options['api_key'])) {
            return;
        }

        // Custom mode with no block types ticked blocks nothing, so skip the lookup.
        $block_types = $this->invatrbl_get_block_types($options);
        if (empty($block_types)) {
            return;
        }

        // IPHub can't classify private or reserved addresses; '' means no valid IP was found.
        $api_key = $options['api_key'];
        $visitor_ip = $this->invatrbl_get_user_ip();
        if ('' === $visitor_ip || ! $this->invatrbl_is_public_ip($visitor_ip)) {
            return;
        }

        // Check whitelist.
        if (! empty($options['whitelisted_ips'])) {
            $whitelist = array_filter(array_map('trim', explode("\n", $options['whitelisted_ips'])));
            if (in_array($visitor_ip, $whitelist)) {
                return;
            }
        }

        // Optionally skip verified crawlers and the admin's extra patterns.
        if (! empty($options['allow_crawlers']) && $this->invatrbl_is_allowed_crawler($visitor_ip, $options)) {
            return;
        }

        // Determine cache duration.
        $cache_hours = isset($options['cache_duration']) ? absint($options['cache_duration']) : 1;
        $cache_duration = $cache_hours * HOUR_IN_SECONDS;

        // Use a prefixed transient key.
        $transient_key = 'invatrbl_check_' . md5($visitor_ip);
        $ip_data = get_transient($transient_key);

        if (false === $ip_data) {
            // The API failed recently: allow access rather than make every visitor wait on it.
            if (get_transient('invatrbl_api_backoff')) {
                return;
            }

            $response = $this->invatrbl_api_request($visitor_ip, $api_key);
            $ip_data = null;
            if (! is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $ip_data = json_decode(wp_remote_retrieve_body($response), true);
            }

            if (! is_array($ip_data)) {
                // Allow access if the API fails, and pause lookups for a few minutes.
                set_transient('invatrbl_api_backoff', 1, self::API_BACKOFF_SECONDS);
                return;
            }
            set_transient($transient_key, $ip_data, $cache_duration);
        }

        $block_ip = isset($ip_data['block']) && in_array((int)$ip_data['block'], $block_types, true);

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
