<?php
/**
 * Plugin Name: WooCommerce Payment Method Surcharge
 * Description: Adds payment method specific surcharges to WooCommerce orders.
 * Version: 2.3.4
 * Author: Steve Zeal
 * Text Domain: wc-payment-surcharge
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('WC_PAYMENT_SURCHARGE_VERSION', '2.3.2');
define('WC_PAYMENT_SURCHARGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_PAYMENT_SURCHARGE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'wc_payment_surcharge_woocommerce_missing_notice');
    return;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Check if Stripe is active
add_action('plugins_loaded', 'wc_payment_surcharge_check_stripe');
function wc_payment_surcharge_check_stripe() {
    if (!class_exists('WC_Stripe')) {
        add_action('admin_notices', 'wc_payment_surcharge_stripe_missing_notice');
    }
}

function wc_payment_surcharge_stripe_missing_notice() {
    echo '<div class="error"><p>';
    printf(
        esc_html__('WooCommerce Payment Method Surcharge recommends %s to be installed for Apple Pay/Google Pay support.', 'wc-payment-surcharge'),
        '<a href="https://wordpress.org/plugins/woocommerce-gateway-stripe/" target="_blank">WooCommerce Stripe Gateway</a>'
    );
    echo '</p></div>';
}

function wc_payment_surcharge_woocommerce_missing_notice() {
    echo '<div class="error"><p>';
    printf(
        esc_html__('WooCommerce Payment Method Surcharge requires %s to be installed and active.', 'wc-payment-surcharge'),
        '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>'
    );
    echo '</p></div>';
}

// Include the main plugin class
require_once WC_PAYMENT_SURCHARGE_PLUGIN_DIR . 'includes/class-wc-payment-surcharge.php';

// Initialize the plugin
function wc_payment_surcharge_init() {
    return WC_Payment_Surcharge::instance();
}
add_action('plugins_loaded', 'wc_payment_surcharge_init');

// Add this new function to handle the AJAX request
add_action('wp_ajax_get_payment_surcharge', 'wc_payment_surcharge_ajax_handler');
add_action('wp_ajax_nopriv_get_payment_surcharge', 'wc_payment_surcharge_ajax_handler');

function wc_payment_surcharge_ajax_handler() {
    if (!class_exists('WC_Payment_Surcharge')) {
        require_once WC_PAYMENT_SURCHARGE_PLUGIN_DIR . 'includes/class-wc-payment-surcharge.php';
    }
    
    $instance = WC_Payment_Surcharge::instance();
    $instance->get_payment_surcharge_ajax();
}
// Add filter to detect Stripe express checkout
add_filter('woocommerce_stripe_payment_request_total_label', function($label, $total) {
    $surcharge_data = WC()->session->get('payment_surcharge_data');
    if ($surcharge_data && $surcharge_data['is_stripe_express']) {
        $label .= ' (' . $surcharge_data['label'] . ': ' . wc_price($surcharge_data['amount']) . ')';
    }
    return $label;
}, 10, 2);