<?php
/**
 * Plugin Name: WooCommerce Payment Method Surcharge
 * Description: Adds payment method specific surcharges to WooCommerce orders.
 * Version: 2.3.5
 * Author: Steve Zeal
 * Text Domain: wc-payment-surcharge
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('WC_PAYMENT_SURCHARGE_VERSION', '2.3.5');
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

/**
 * Initialize the plugin only after all plugins are loaded.
 * This ensures Stripe and its sub-gateways (like Afterpay) are registered.
 */
add_action('plugins_loaded', 'wc_payment_surcharge_init', 20);

function wc_payment_surcharge_init() {
    // Load the main class only once
    if (!class_exists('WC_Payment_Surcharge')) {
        require_once WC_PAYMENT_SURCHARGE_PLUGIN_DIR . 'includes/class-wc-payment-surcharge.php';
    }
    return WC_Payment_Surcharge::instance();
}

// Check if Stripe is active (for admin notice)
add_action('plugins_loaded', 'wc_payment_surcharge_check_stripe', 10);
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

// AJAX handler to fetch surcharge dynamically
add_action('wp_ajax_get_payment_surcharge', 'wc_payment_surcharge_ajax_handler');
add_action('wp_ajax_nopriv_get_payment_surcharge', 'wc_payment_surcharge_ajax_handler');

function wc_payment_surcharge_ajax_handler() {
    if (!class_exists('WC_Payment_Surcharge')) {
        require_once WC_PAYMENT_SURCHARGE_PLUGIN_DIR . 'includes/class-wc-payment-surcharge.php';
    }
    
    $instance = WC_Payment_Surcharge::instance();
    $instance->get_payment_surcharge_ajax();
}

// Filter for Stripe express checkout labels (improved display)
add_filter('woocommerce_stripe_payment_request_total_label', 'wc_payment_surcharge_stripe_express_label', 10, 2);
function wc_payment_surcharge_stripe_express_label($label, $total) {
    $surcharge_data = WC()->session->get('payment_surcharge_data');
    if ($surcharge_data && isset($surcharge_data['amount']) && $surcharge_data['amount'] > 0) {
        // Append the surcharge information to the payment button label
        $label .= sprintf(
            ' (%s: %s)',
            esc_html($surcharge_data['label']),
            wc_price($surcharge_data['amount'])
        );
    }
    return $label;
}
