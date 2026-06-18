<?php
/**
 * Main class for WooCommerce Payment Method Surcharge
 *
 * @package WC_Payment_Surcharge
 */

defined('ABSPATH') || exit;

/**
 * Class WC_Payment_Surcharge
 */
class WC_Payment_Surcharge {

    /**
     * The single instance of the class.
     *
     * @var WC_Payment_Surcharge
     */
    protected static $instance = null;

    /**
     * Payment gateways surcharge rates.
     *
     * @var array
     */
    private $surcharge_rates = array();

    /**
     * Surcharge amount.
     *
     * @var float
     */
    private $surcharge_amount = 0;

    /**
     * Surcharge label.
     *
     * @var string
     */
    private $surcharge_label = '';

    /**
     * Main instance.
     *
     * @return WC_Payment_Surcharge
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        // Load settings
        $this->surcharge_rates = get_option('wc_payment_surcharge_rates', array());
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_surcharge_fee'));
        add_action('woocommerce_checkout_update_order_review', array($this, 'update_surcharge_on_checkout'));
        
        // AJAX handlers
        add_action('wp_ajax_get_payment_surcharge', array($this, 'ajax_get_payment_surcharge'));
        add_action('wp_ajax_nopriv_get_payment_surcharge', array($this, 'ajax_get_payment_surcharge'));
        
        // Order hooks
        add_action('woocommerce_checkout_create_order', array($this, 'add_surcharge_to_order'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'update_surcharge_on_status_change'), 10, 3);
        
        // Display hooks
        add_action('woocommerce_review_order_before_payment', array($this, 'display_surcharge_notice'));
        add_action('woocommerce_cart_totals_before_order_total', array($this, 'display_surcharge_notice'));
        
        // Stripe express checkout hooks
        add_filter('woocommerce_stripe_payment_request_total_label', array($this, 'modify_stripe_express_label'), 10, 2);
        add_filter('wc_stripe_payment_request_cart_total', array($this, 'modify_stripe_express_total'), 10, 2);
        
        // HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // Tax compatibility
        add_filter('woocommerce_fee_tax_class', array($this, 'set_surcharge_tax_class'), 10, 2);
        add_filter('woocommerce_cart_totals_fee_html', array($this, 'modify_surcharge_display'), 10, 2);
    }

    /**
     * Declare HPOS compatibility.
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_options_page(
            __('Payment Surcharge Settings', 'wc-payment-surcharge'),
            __('Payment Surcharge', 'wc-payment-surcharge'),
            'manage_options',
            'wc-payment-surcharge',
            array($this, 'admin_settings_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting(
            'wc_payment_surcharge_settings',
            'wc_payment_surcharge_rates',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_rates')
            )
        );
        
        register_setting(
            'wc_payment_surcharge_settings',
            'wc_payment_surcharge_settings',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings')
            )
        );
    }

    /**
     * Sanitize rates input.
     *
     * @param array $input The input rates.
     * @return array Sanitized rates.
     */
    public function sanitize_rates($input) {
        $sanitized = array();
        if (is_array($input)) {
            foreach ($input as $gateway_id => $rate) {
                $sanitized[sanitize_key($gateway_id)] = floatval($rate);
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize settings input.
     *
     * @param array $input The input settings.
     * @return array Sanitized settings.
     */
    public function sanitize_settings($input) {
        $defaults = array(
            'tax_status' => 'none',
            'display_location' => 'both',
            'surcharge_label' => __('Payment Method Surcharge', 'wc-payment-surcharge'),
        );
        
        $sanitized = wp_parse_args($input, $defaults);
        $sanitized['tax_status'] = sanitize_text_field($input['tax_status'] ?? 'none');
        $sanitized['display_location'] = sanitize_text_field($input['display_location'] ?? 'both');
        $sanitized['surcharge_label'] = sanitize_text_field($input['surcharge_label'] ?? $defaults['surcharge_label']);
        
        return $sanitized;
    }

    /**
     * Admin settings page.
     */
    public function admin_settings_page() {
        $settings = get_option('wc_payment_surcharge_settings', array());
        $rates = get_option('wc_payment_surcharge_rates', array());
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Method Surcharge Settings', 'wc-payment-surcharge'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php esc_html_e('Configure surcharge rates for each payment method. The surcharge will be automatically added to the cart total.', 'wc-payment-surcharge'); ?></p>
                <p><strong><?php esc_html_e('Note:', 'wc-payment-surcharge'); ?></strong> <?php esc_html_e('Stripe Afterpay will automatically apply a 6% surcharge.', 'wc-payment-surcharge'); ?></p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('wc_payment_surcharge_settings'); ?>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Display Options', 'wc-payment-surcharge'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="wc_payment_surcharge_settings[display_location]" value="both" <?php checked(isset($settings['display_location']) ? $settings['display_location'] : 'both', 'both'); ?> />
                                        <?php esc_html_e('Show on Cart and Checkout', 'wc-payment-surcharge'); ?>
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="wc_payment_surcharge_settings[tax_status]" value="taxable" <?php checked(isset($settings['tax_status']) ? $settings['tax_status'] : 'none', 'taxable'); ?> />
                                        <?php esc_html_e('Apply tax to surcharge', 'wc-payment-surcharge'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Surcharge Label', 'wc-payment-surcharge'); ?></th>
                            <td>
                                <input type="text" 
                                       name="wc_payment_surcharge_settings[surcharge_label]" 
                                       value="<?php echo esc_attr($settings['surcharge_label'] ?? __('Payment Method Surcharge', 'wc-payment-surcharge')); ?>" 
                                       class="regular-text" />
                                <p class="description"><?php esc_html_e('This label will appear next to the surcharge amount.', 'wc-payment-surcharge'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Surcharge Rates', 'wc-payment-surcharge'); ?></th>
                            <td>
                                <?php foreach ($gateways as $gateway_id => $gateway) : ?>
                                    <?php 
                                    // Skip if this is Stripe Afterpay (it's forced to 6%)
                                    $is_afterpay = strpos($gateway_id, 'stripe_afterpay') !== false;
                                    $rate_value = $is_afterpay ? 6.0 : (isset($rates[$gateway_id]) ? $rates[$gateway_id] : '');
                                    ?>
                                    <p>
                                        <label for="rate_<?php echo esc_attr($gateway_id); ?>">
                                            <?php echo esc_html($gateway->get_title()); ?> 
                                            <span class="description">(<?php echo esc_html($gateway_id); ?>)</span>
                                        </label>
                                        <input type="number" 
                                               step="0.01" 
                                               min="0" 
                                               max="100" 
                                               id="rate_<?php echo esc_attr($gateway_id); ?>" 
                                               name="wc_payment_surcharge_rates[<?php echo esc_attr($gateway_id); ?>]" 
                                               value="<?php echo esc_attr($rate_value); ?>" 
                                               class="small-text" 
                                               <?php echo $is_afterpay ? 'readonly style="background:#f0f0f0;"' : ''; ?> />
                                        <span class="description">%</span>
                                        <?php if ($is_afterpay) : ?>
                                            <span class="description"><strong><?php esc_html_e('(Fixed 6% fee)', 'wc-payment-surcharge'); ?></strong></span>
                                        <?php endif; ?>
                                    </p>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_wc-payment-surcharge' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'wc-payment-surcharge-admin',
            WC_PAYMENT_SURCHARGE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WC_PAYMENT_SURCHARGE_VERSION
        );
    }

    /**
     * Enqueue frontend scripts.
     */
    public function enqueue_frontend_scripts() {
        if (is_cart() || is_checkout()) {
            wp_enqueue_script(
                'wc-payment-surcharge-frontend',
                WC_PAYMENT_SURCHARGE_PLUGIN_URL . 'assets/js/checkout.js',
                array('jquery', 'woocommerce'),
                WC_PAYMENT_SURCHARGE_VERSION,
                true
            );
            
            wp_localize_script('wc-payment-surcharge-frontend', 'wc_payment_surcharge_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_payment_surcharge_nonce'),
                'i18n' => array(
                    'surcharge_label' => __('Payment Method Surcharge', 'wc-payment-surcharge'),
                ),
            ));
        }
    }

    /**
     * Get the surcharge rate for a gateway.
     *
     * @param string $gateway_id The gateway ID.
     * @return float The surcharge rate.
     */
    private function get_surcharge_rate($gateway_id) {
        // Force 6% for Stripe Afterpay
        if (strpos($gateway_id, 'stripe_afterpay') !== false) {
            return 6.0;
        }
        
        return isset($this->surcharge_rates[$gateway_id]) ? floatval($this->surcharge_rates[$gateway_id]) : 0;
    }

    /**
     * Add surcharge fee to cart.
     *
     * @param WC_Cart $cart The cart object.
     */
    public function add_surcharge_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if ($cart->is_empty()) {
            return;
        }
        
        $chosen_gateway = WC()->session->get('chosen_payment_method');
        if (empty($chosen_gateway)) {
            return;
        }
        
        $rate = $this->get_surcharge_rate($chosen_gateway);
        if ($rate <= 0) {
            return;
        }
        
        // Calculate surcharge
        $subtotal = $cart->subtotal_ex_tax ?: $cart->subtotal;
        $surcharge = $subtotal * ($rate / 100);
        
        if ($surcharge <= 0) {
            return;
        }
        
        $gateway_title = $this->get_payment_method_title($chosen_gateway);
        $settings = get_option('wc_payment_surcharge_settings', array());
        $label = isset($settings['surcharge_label']) ? $settings['surcharge_label'] : __('Payment Method Surcharge', 'wc-payment-surcharge');
        $label = sprintf('%s (%s)', $label, $gateway_title);
        
        // Add fee to cart
        $tax_status = (isset($settings['tax_status']) && $settings['tax_status'] === 'taxable') ? 'taxable' : 'none';
        $cart->add_fee($label, $surcharge, $tax_status);
        
        // Store surcharge data
        $this->surcharge_amount = $surcharge;
        $this->surcharge_label = $label;
        
        WC()->session->set('wc_payment_surcharge_amount', $surcharge);
        WC()->session->set('wc_payment_surcharge_label', $label);
        WC()->session->set('wc_payment_surcharge_gateway', $chosen_gateway);
    }

    /**
     * Update surcharge on checkout update.
     *
     * @param array $post_data The POST data.
     */
    public function update_surcharge_on_checkout($post_data) {
        // Parse payment method from POST data
        parse_str($post_data, $parsed_data);
        if (isset($parsed_data['payment_method'])) {
            WC()->session->set('chosen_payment_method', $parsed_data['payment_method']);
        }
    }

    /**
     * Get payment method title.
     *
     * @param string $gateway_id The gateway ID.
     * @return string
     */
    private function get_payment_method_title($gateway_id) {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if (isset($gateways[$gateway_id])) {
            return $gateways[$gateway_id]->get_title();
        }
        return ucfirst(str_replace('_', ' ', $gateway_id));
    }

    /**
     * AJAX handler for getting payment surcharge.
     */
    public function ajax_get_payment_surcharge() {
        check_ajax_referer('wc_payment_surcharge_nonce', 'nonce');
        
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
        if (empty($payment_method)) {
            wp_send_json_error(array('message' => 'No payment method selected'));
            return;
        }
        
        WC()->session->set('chosen_payment_method', $payment_method);
        
        // Get the current surcharge amount
        $surcharge = WC()->session->get('wc_payment_surcharge_amount', 0);
        $label = WC()->session->get('wc_payment_surcharge_label', '');
        
        wp_send_json_success(array(
            'amount' => $surcharge,
            'label' => $label,
            'formatted_amount' => wc_price($surcharge),
            'rate' => $this->get_surcharge_rate($payment_method),
        ));
    }

    /**
     * Display surcharge notice.
     */
    public function display_surcharge_notice() {
        $surcharge = WC()->session->get('wc_payment_surcharge_amount', 0);
        if ($surcharge <= 0) {
            return;
        }
        
        $label = WC()->session->get('wc_payment_surcharge_label', '');
        $gateway = WC()->session->get('wc_payment_surcharge_gateway', '');
        
        if (empty($label) || empty($gateway)) {
            return;
        }
        
        echo '<div class="wc-payment-surcharge-notice">';
        echo '<p><small>';
        echo sprintf(
            __('A %s surcharge of %s will be applied to your order.', 'wc-payment-surcharge'),
            esc_html($this->get_payment_method_title($gateway)),
            wc_price($surcharge)
        );
        echo '</small></p>';
        echo '</div>';
    }

    /**
     * Add surcharge to order meta.
     *
     * @param WC_Order $order The order object.
     * @param array    $data  The posted data.
     */
    public function add_surcharge_to_order($order, $data) {
        $surcharge = WC()->session->get('wc_payment_surcharge_amount', 0);
        $label = WC()->session->get('wc_payment_surcharge_label', '');
        $gateway = WC()->session->get('wc_payment_surcharge_gateway', '');
        
        if ($surcharge > 0 && !empty($label)) {
            $order->add_meta_data('_payment_surcharge_amount', $surcharge);
            $order->add_meta_data('_payment_surcharge_label', $label);
            $order->add_meta_data('_payment_surcharge_gateway', $gateway);
            $order->add_meta_data('_payment_surcharge_rate', $this->get_surcharge_rate($gateway));
        }
    }

    /**
     * Update surcharge on order status change.
     *
     * @param int    $order_id   Order ID.
     * @param string $old_status Old status.
     * @param string $new_status New status.
     */
    public function update_surcharge_on_status_change($order_id, $old_status, $new_status) {
        // If order is refunded, we could adjust the surcharge
        if ('refunded' === $new_status) {
            $order = wc_get_order($order_id);
            if ($order) {
                $surcharge = $order->get_meta('_payment_surcharge_amount', true);
                if ($surcharge > 0) {
                    // Log or handle refund of surcharge
                    $order->add_order_note(
                        sprintf(
                            __('Payment surcharge of %s was applied to this order.', 'wc-payment-surcharge'),
                            wc_price($surcharge)
                        )
                    );
                }
            }
        }
    }

    /**
     * Modify Stripe express checkout label.
     *
     * @param string   $label The label.
     * @param WC_Cart  $cart  The cart object.
     * @return string
     */
    public function modify_stripe_express_label($label, $cart) {
        $surcharge = WC()->session->get('wc_payment_surcharge_amount', 0);
        if ($surcharge > 0) {
            $label .= sprintf(
                ' (%s)',
                wc_price($surcharge)
            );
        }
        return $label;
    }

    /**
     * Modify Stripe express checkout total.
     *
     * @param array    $total The total array.
     * @param WC_Cart  $cart  The cart object.
     * @return array
     */
    public function modify_stripe_express_total($total, $cart) {
        $surcharge = WC()->session->get('wc_payment_surcharge_amount', 0);
        if ($surcharge > 0) {
            $total['label'] .= sprintf(
                ' (%s)',
                wc_price($surcharge)
            );
        }
        return $total;
    }

    /**
     * Set surcharge tax class.
     *
     * @param string $tax_class Tax class.
     * @param string $fee_name  Fee name.
     * @return string
     */
    public function set_surcharge_tax_class($tax_class, $fee_name) {
        $settings = get_option('wc_payment_surcharge_settings', array());
        if (isset($settings['tax_status']) && $settings['tax_status'] === 'taxable') {
            return get_option('woocommerce_tax_default', '');
        }
        return $tax_class;
    }

    /**
     * Modify surcharge display in cart.
     *
     * @param string   $html Fee HTML.
     * @param WC_Order $fee  Fee object.
     * @return string
     */
    public function modify_surcharge_display($html, $fee) {
        $surcharge = WC()->session->get('wc_payment_surcharge_amount', 0);
        if ($surcharge > 0) {
            $gateway = WC()->session->get('wc_payment_surcharge_gateway', '');
            if (!empty($gateway)) {
                $gateway_title = $this->get_payment_method_title($gateway);
                $html = str_replace(
                    $fee->get_name(),
                    sprintf('%s (%s)', $fee->get_name(), $gateway_title),
                    $html
                );
            }
        }
        return $html;
    }

    /**
     * Get the surcharge amount for a gateway.
     *
     * @param string $gateway_id The gateway ID.
     * @param float  $subtotal   Cart subtotal.
     * @return float
     */
    public function get_surcharge_for_gateway($gateway_id, $subtotal = null) {
        if (null === $subtotal) {
            $cart = WC()->cart;
            if (!$cart || $cart->is_empty()) {
                return 0;
            }
            $subtotal = $cart->subtotal_ex_tax ?: $cart->subtotal;
        }
        
        $rate = $this->get_surcharge_rate($gateway_id);
        if ($rate <= 0) {
            return 0;
        }
        
        return $subtotal * ($rate / 100);
    }

    /**
     * Get available payment gateways with surcharges.
     *
     * @return array
     */
    public function get_gateways_with_surcharges() {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $result = array();
        
        foreach ($gateways as $gateway_id => $gateway) {
            $rate = $this->get_surcharge_rate($gateway_id);
            if ($rate > 0) {
                $result[$gateway_id] = array(
                    'title' => $gateway->get_title(),
                    'rate' => $rate,
                    'id' => $gateway_id,
                );
            }
        }
        
        return $result;
    }

    /**
     * Log surcharge information.
     *
     * @param string $message The message to log.
     * @param string $level   The log level.
     */
    private function log_surcharge_info($message, $level = 'info') {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        
        $logger = wc_get_logger();
        $context = array('source' => 'wc-payment-surcharge');
        
        switch ($level) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger->warning($message, $context);
                break;
            default:
                $logger->info($message, $context);
                break;
        }
    }
}

// Initialize the plugin
function WC_Payment_Surcharge() {
    return WC_Payment_Surcharge::instance();
}

// Initialize early to catch all hooks
add_action('woocommerce_init', 'WC_Payment_Surcharge', 5);
