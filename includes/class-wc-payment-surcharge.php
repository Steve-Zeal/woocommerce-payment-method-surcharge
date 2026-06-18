<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Payment_Surcharge {
    private static $instance = null;
    private $settings = null;
    private $stripe_payment_request_types = array(
        'apple_pay' => 'Apple Pay',
        'google_pay' => 'Google Pay',
        'payment_request_api' => 'Payment Request API'
    );

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = get_option('wc_payment_surcharge_settings', array());

        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_order_surcharge_admin'));
        add_action('woocommerce_order_action_add_payment_surcharge', array($this, 'process_order_surcharge_admin'));

        // Frontend hooks
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_payment_surcharge'));
        add_action('woocommerce_checkout_create_order', array($this, 'add_surcharge_to_order'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('init', array($this, 'load_textdomain'));

        // Express checkout support
        add_filter('woocommerce_pay_order_button_html', array($this, 'maybe_add_surcharge_to_pay_order'));
        add_action('woocommerce_before_pay_action', array($this, 'add_surcharge_to_pay_order'));

        // AJAX for updating surcharge
        add_action('wp_ajax_get_payment_surcharge', array($this, 'get_payment_surcharge_ajax'));
        add_action('wp_ajax_nopriv_get_payment_surcharge', array($this, 'get_payment_surcharge_ajax'));
    }

    public function load_textdomain() {
        load_plugin_textdomain('wc-payment-surcharge', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function enqueue_scripts() {
        if (is_checkout() || is_checkout_pay_page()) {
            wp_enqueue_script(
                'wc-payment-surcharge',
                WC_PAYMENT_SURCHARGE_PLUGIN_URL . 'assets/js/checkout.js',
                array('jquery', 'wc-checkout'),
                WC_PAYMENT_SURCHARGE_VERSION,
                true
            );

            wp_enqueue_style(
                'wc-payment-surcharge',
                WC_PAYMENT_SURCHARGE_PLUGIN_URL . 'assets/css/checkout.css',
                array(),
                WC_PAYMENT_SURCHARGE_VERSION
            );

            wp_localize_script(
                'wc-payment-surcharge',
                'wc_payment_surcharge_params',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wc-payment-surcharge-nonce'),
                    'is_pay_page' => is_checkout_pay_page(),
                    'stripe_express_enabled' => (class_exists('WC_Stripe') && isset($this->settings['stripe_express_checkout_enable']) && $this->settings['stripe_express_checkout_enable'] === 'yes') ? '1' : '0'
                )
            );
        }
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Payment Method Surcharge', 'wc-payment-surcharge'),
            __('Payment Surcharge', 'wc-payment-surcharge'),
            'manage_woocommerce',
            'wc-payment-surcharge',
            array($this, 'settings_page')
        );
    }

    public function settings_init() {
        register_setting('wc_payment_surcharge', 'wc_payment_surcharge_settings');

        add_settings_section(
            'wc_payment_surcharge_section',
            __('Payment Method Surcharge Settings', 'wc-payment-surcharge'),
            array($this, 'settings_section_callback'),
            'wc_payment_surcharge'
        );

        $payment_gateways = WC()->payment_gateways->payment_gateways();

        foreach ($payment_gateways as $gateway) {
            if ($gateway->enabled === 'yes') {
                add_settings_field(
                    'surcharge_' . $gateway->id,
                    sprintf(__('%s Surcharge Percentage', 'wc-payment-surcharge'), $gateway->title),
                    array($this, 'payment_method_field_callback'),
                    'wc_payment_surcharge',
                    'wc_payment_surcharge_section',
                    array(
                        'gateway_id' => $gateway->id,
                        'gateway_title' => $gateway->title,
                    )
                );
            }
        }

        add_settings_field(
            'surcharge_label',
            __('Surcharge Label', 'wc-payment-surcharge'),
            array($this, 'text_field_callback'),
            'wc_payment_surcharge',
            'wc_payment_surcharge_section',
            array(
                'id' => 'surcharge_label',
                'default' => __('Handling Fee', 'wc-payment-surcharge'),
            )
        );

        add_settings_field(
            'surcharge_tax_status',
            __('Apply Tax to Surcharge', 'wc-payment-surcharge'),
            array($this, 'checkbox_field_callback'),
            'wc_payment_surcharge',
            'wc_payment_surcharge_section',
            array(
                'id' => 'surcharge_tax_status',
                'label' => __('Enable if surcharge should be taxable', 'wc-payment-surcharge'),
            )
        );

        add_settings_field(
            'surcharge_minimum',
            __('Minimum Surcharge Amount', 'wc-payment-surcharge'),
            array($this, 'number_field_callback'),
            'wc_payment_surcharge',
            'wc_payment_surcharge_section',
            array(
                'id' => 'surcharge_minimum',
                'default' => 0,
                'description' => __('Set the minimum surcharge amount (leave 0 for no minimum)', 'wc-payment-surcharge'),
            )
        );

        add_settings_field(
            'enable_rounding',
            __('Enable Rounding', 'wc-payment-surcharge'),
            array($this, 'checkbox_field_callback'),
            'wc_payment_surcharge',
            'wc_payment_surcharge_section',
            array(
                'id' => 'enable_rounding',
                'label' => __('Enable rounding of surcharge amount', 'wc-payment-surcharge'),
                'default' => 'no'
            )
        );

        add_settings_field(
            'rounding_method',
            __('Rounding Method', 'wc-payment-surcharge'),
            array($this, 'select_field_callback'),
            'wc_payment_surcharge',
            'wc_payment_surcharge_section',
            array(
                'id' => 'rounding_method',
                'options' => array(
                    'up' => __('Round Up', 'wc-payment-surcharge'),
                    'down' => __('Round Down', 'wc-payment-surcharge'),
                    'nearest' => __('Round to Nearest', 'wc-payment-surcharge'),
                    'none' => __('No Rounding', 'wc-payment-surcharge')
                ),
                'default' => 'nearest',
                'description' => __('How to round the calculated surcharge amount', 'wc-payment-surcharge')
            )
        );

        add_settings_field(
            'rounding_precision',
            __('Rounding Precision', 'wc-payment-surcharge'),
            array($this, 'number_field_callback'),
            'wc_payment_surcharge',
            'wc_payment_surcharge_section',
            array(
                'id' => 'rounding_precision',
                'default' => 2,
                'description' => __('Number of decimal places to round to (typically 0 or 2)', 'wc-payment-surcharge'),
                'min' => 0,
                'max' => 4,
                'step' => 1
            )
        );

        // Add Stripe express checkout settings if Stripe is active
        if (class_exists('WC_Stripe')) {
            add_settings_field(
                'stripe_express_checkout',
                __('Stripe Express Checkout', 'wc-payment-surcharge'),
                array($this, 'stripe_express_checkout_callback'),
                'wc_payment_surcharge',
                'wc_payment_surcharge_section',
                array(
                    'label' => __('Enable surcharge for Stripe express checkout methods', 'wc-payment-surcharge')
                )
            );
            
            foreach ($this->stripe_payment_request_types as $type => $label) {
                add_settings_field(
                    'stripe_' . $type . '_surcharge',
                    sprintf(__('%s Surcharge Percentage', 'wc-payment-surcharge'), $label),
                    array($this, 'number_field_callback'),
                    'wc_payment_surcharge',
                    'wc_payment_surcharge_section',
                    array(
                        'id' => 'stripe_' . $type . '_surcharge',
                        'default' => 0,
                        'step' => 0.1,
                        'min' => 0,
                        'max' => 100,
                        'description' => sprintf(__('Surcharge percentage for %s payments', 'wc-payment-surcharge'), $label)
                    )
                );
            }
        }
    }

    public function stripe_express_checkout_callback($args) {
        ?>
        <fieldset>
            <legend class="screen-reader-text"><span><?php echo esc_html($args['label']); ?></span></legend>
            <label for="stripe_express_checkout_enable">
                <input type="checkbox" 
                       name="wc_payment_surcharge_settings[stripe_express_checkout_enable]" 
                       id="stripe_express_checkout_enable" 
                       value="yes" <?php checked(isset($this->settings['stripe_express_checkout_enable']) && $this->settings['stripe_express_checkout_enable'] === 'yes'); ?> />
                <?php echo esc_html($args['label']); ?>
            </label>
        </fieldset>
        <?php
    }

    public function settings_section_callback() {
        echo '<p>' . __('Set the surcharge percentage for each payment method. Enter numbers only (e.g., 2.5 for 2.5%).', 'wc-payment-surcharge') . '</p>';
    }

    public function payment_method_field_callback($args) {
        $value = isset($this->settings[$args['gateway_id']]) ? $this->settings[$args['gateway_id']] : '';
        ?>
        <input type="number" step="0.1" min="0" max="100" 
               name="wc_payment_surcharge_settings[<?php echo esc_attr($args['gateway_id']); ?>]" 
               value="<?php echo esc_attr($value); ?>" /> %
        <?php
    }

    public function text_field_callback($args) {
        $value = isset($this->settings[$args['id']]) ? $this->settings[$args['id']] : $args['default'];
        ?>
        <input type="text" 
               name="wc_payment_surcharge_settings[<?php echo esc_attr($args['id']); ?>]" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <?php
    }

    public function checkbox_field_callback($args) {
        $value = isset($this->settings[$args['id']]) ? $this->settings[$args['id']] : (isset($args['default']) ? $args['default'] : 'no');
        ?>
        <input type="checkbox" 
               name="wc_payment_surcharge_settings[<?php echo esc_attr($args['id']); ?>]" 
               value="yes" <?php checked($value, 'yes'); ?> />
        <label for="<?php echo esc_attr($args['id']); ?>"><?php echo esc_html($args['label']); ?></label>
        <?php
    }

    public function number_field_callback($args) {
        $value = isset($this->settings[$args['id']]) ? $this->settings[$args['id']] : $args['default'];
        $min = isset($args['min']) ? $args['min'] : 0;
        $max = isset($args['max']) ? $args['max'] : '';
        $step = isset($args['step']) ? $args['step'] : 'any';
        ?>
        <input type="number" 
               name="wc_payment_surcharge_settings[<?php echo esc_attr($args['id']); ?>]" 
               value="<?php echo esc_attr($value); ?>" 
               min="<?php echo esc_attr($min); ?>"
               <?php if ($max) echo 'max="' . esc_attr($max) . '"'; ?>
               step="<?php echo esc_attr($step); ?>" />
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
        <?php
    }

    public function select_field_callback($args) {
        $value = isset($this->settings[$args['id']]) ? $this->settings[$args['id']] : $args['default'];
        ?>
        <select name="wc_payment_surcharge_settings[<?php echo esc_attr($args['id']); ?>]">
            <?php foreach ($args['options'] as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
        <?php
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Method Surcharge Settings', 'wc-payment-surcharge'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_payment_surcharge');
                do_settings_sections('wc_payment_surcharge');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function add_payment_surcharge() {
        // Avoid running in admin area unless doing AJAX
        if (is_admin() && !defined('DOING_AJAX') && !is_checkout_pay_page()) {
            return;
        }

        // Skip if on the cart page
        if (is_cart()) {
            return;
        }

        $chosen_payment_method = $this->get_current_payment_method();
        $surcharge_percentage = 0;
        $is_stripe_express = false;
        $express_type = false;

    // Check for Stripe express checkout methods
    if ($chosen_payment_method === 'stripe') {
        $is_stripe_express = true;
        
        // Determine if it's Google Pay or Apple Pay
        $express_type = $this->get_stripe_express_type();
        if ($express_type && isset($this->settings['stripe_' . $express_type . '_surcharge'])) {
            // Use the percentage set in backend for specific express payment type
            $surcharge_percentage = floatval($this->settings['stripe_' . $express_type . '_surcharge']);
        } elseif (isset($this->settings['stripe'])) {
            // Fallback to regular Stripe percentage if express type not found
            $surcharge_percentage = floatval($this->settings['stripe']);
        }
    }

    // Fall back to regular payment method surcharge
    if (!$is_stripe_express && !empty($chosen_payment_method) && isset($this->settings[$chosen_payment_method])) {
        $surcharge_percentage = floatval($this->settings[$chosen_payment_method]);
    }

    if ($surcharge_percentage <= 0) {
        return;
    }

    $cart = WC()->cart;
    
    // Calculate surcharge based on cart contents total only
    $total = (float) $cart->get_cart_contents_total();
    $surcharge_amount = $total * ($surcharge_percentage / 100);

    // Apply minimum surcharge if set
    $minimum_surcharge = isset($this->settings['surcharge_minimum']) ? floatval($this->settings['surcharge_minimum']) : 0;
    if ($minimum_surcharge > 0 && $surcharge_amount < $minimum_surcharge) {
        $surcharge_amount = $minimum_surcharge;
    }

    // Apply rounding if enabled
    if (isset($this->settings['enable_rounding']) && $this->settings['enable_rounding'] === 'yes') {
        $rounding_method = isset($this->settings['rounding_method']) ? $this->settings['rounding_method'] : 'nearest';
        $precision = isset($this->settings['rounding_precision']) ? (int)$this->settings['rounding_precision'] : 2;
        $surcharge_amount = $this->round_amount($surcharge_amount, $rounding_method, $precision);
    }

    $surcharge_label = sprintf(__('Payment Surcharge (%s%%)', 'wc-payment-surcharge'), number_format($surcharge_percentage, 1));
    
    $taxable = isset($this->settings['surcharge_tax_status']) && $this->settings['surcharge_tax_status'] === 'yes';

    $cart->add_fee($surcharge_label, $surcharge_amount, $taxable);
    
    // Store surcharge data in session
    WC()->session->set('payment_surcharge_data', [
        'amount' => $surcharge_amount,
        'payment_method' => $chosen_payment_method,
        'percentage' => $surcharge_percentage,
        'label' => $surcharge_label,
        'is_stripe_express' => $is_stripe_express,
        'express_type' => $express_type
    ]);
}

    private function round_amount($amount, $method, $precision = 2) {
        $factor = pow(10, $precision); // Default factor = 100 for 2 decimals
        
        // For 0.05 increments (special case)
        if ($precision == 2) { 
            $factor = 20; // 1/0.05 = 20
        }
        
        switch ($method) {
            case 'up':
                return ceil($amount * $factor) / $factor;
            case 'down':
                return floor($amount * $factor) / $factor;
            case 'nearest':
                return round($amount * $factor) / $factor;
            default:
                return $amount;
        }
    }

   private function get_stripe_express_type() {
    // Check for direct express checkout submission
    if (isset($_POST['payment_request_type']) && in_array($_POST['payment_request_type'], array_keys($this->stripe_payment_request_types))) {
        return sanitize_text_field($_POST['payment_request_type']);
    }
    
    // Check for Stripe Payment Request data
    if (isset($_POST['wc-stripe-payment-method']) && $_POST['wc-stripe-payment-method'] === 'stripe_express_checkout') {
        if (isset($_POST['wc-stripe-source'])) {
            $source = json_decode(stripslashes($_POST['wc-stripe-source']), true);
            if (isset($source['type'])) {
                switch ($source['type']) {
                    case 'card':
                        return 'payment_request_api';
                    case 'apple_pay':
                        return 'apple_pay';
                    case 'google_pay':
                        return 'google_pay';
                }
            }
        }
    }
    
    // Check for Stripe Payment Request button click via AJAX
    if (defined('DOING_AJAX') && DOING_AJAX && isset($_SERVER['HTTP_REFERER'])) {
        $referer = parse_url($_SERVER['HTTP_REFERER']);
        parse_str($referer['query'] ?? '', $query);
        if (isset($query['wc-ajax']) && $query['wc-ajax'] === 'update_order_review') {
            if (isset($_POST['payment_request_type'])) {
                return sanitize_text_field($_POST['payment_request_type']);
            }
        }
    }
    
    return false;
}
    private function get_current_payment_method() {
        if (is_checkout_pay_page()) {
            global $wp;
            $order_id = absint($wp->query_vars['order-pay']);
            $order = wc_get_order($order_id);
            return $order->get_payment_method();
        }
        
        $method = WC()->session->get('chosen_payment_method');
        
        // Check if this is a Stripe express checkout
        if ($method === 'stripe' && 
            isset($this->settings['stripe_express_checkout_enable']) && 
            $this->settings['stripe_express_checkout_enable'] === 'yes' &&
            WC()->session->get('stripe_express_type')
        ) {
            return $method; // Still 'stripe' but we'll handle the surcharge differently
        }
        
        return $method;
    }

    public function get_payment_surcharge_ajax() {
        check_ajax_referer('wc-payment-surcharge-nonce', 'nonce');

        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
        $is_initial_load = isset($_POST['is_initial_load']) ? (bool)$_POST['is_initial_load'] : false;
        
        if (empty($payment_method)) {
            wp_send_json_error(__('Payment method not specified', 'wc-payment-surcharge'));
        }

        // Handle Stripe express checkout
        if ($payment_method === 'stripe' && 
            isset($this->settings['stripe_express_checkout_enable']) && 
            $this->settings['stripe_express_checkout_enable'] === 'yes'
        ) {
            $express_type = isset($_POST['payment_request_type']) ? sanitize_text_field($_POST['payment_request_type']) : false;
            if ($express_type) {
                // Store the express type in session
                WC()->session->set('stripe_express_type', $express_type);
            }
        }

        WC()->session->set('chosen_payment_method', $payment_method);
        
        // Force recalculate totals
        WC()->cart->calculate_totals();

        wp_send_json_success();
    }

    public function add_surcharge_to_order($order, $data) {
        $surcharge_data = WC()->session->get('payment_surcharge_data');
        if (!$surcharge_data) {
            return;
        }

        $order->update_meta_data('_payment_surcharge_amount', $surcharge_data['amount']);
        $order->update_meta_data('_payment_surcharge_method', $surcharge_data['payment_method']);
        $order->update_meta_data('_payment_surcharge_percentage', $surcharge_data['percentage']);
        $order->update_meta_data('_payment_surcharge_label', $surcharge_data['label']);
        if ($surcharge_data['is_stripe_express']) {
            $order->update_meta_data('_payment_surcharge_express_type', $surcharge_data['express_type']);
        }
        
        WC()->session->__unset('payment_surcharge_data');
        WC()->session->__unset('stripe_express_type');
    }

    public function display_order_surcharge_admin($order) {
        $surcharge_amount = $order->get_meta('_payment_surcharge_amount');
        $surcharge_method = $order->get_meta('_payment_surcharge_method');
        
        if ($surcharge_amount) {
            $surcharge_percentage = $order->get_meta('_payment_surcharge_percentage');
            $surcharge_label = $order->get_meta('_payment_surcharge_label');
            $express_type = $order->get_meta('_payment_surcharge_express_type');
            
            echo '<p><strong>' . esc_html($surcharge_label) . ':</strong> ' . wc_price($surcharge_amount) . ' (' . esc_html($surcharge_percentage) . '%)';
            if ($express_type) {
                echo ' <em>(' . esc_html($this->stripe_payment_request_types[$express_type]) . ')</em>';
            }
            echo '</p>';
        } else {
            $payment_method = $order->get_payment_method();
            if (isset($this->settings[$payment_method])) {
                echo '<p><strong>' . __('Surcharge:', 'wc-payment-surcharge') . '</strong> ' . __('Not applied yet', 'wc-payment-surcharge') . '</p>';
                echo '<button type="button" class="button calculate-action" onclick="jQuery(\'#wc_payment_surcharge_action\').val(\'1\'); jQuery(\'button.save_order\').click();">' . __('Add Surcharge', 'wc-payment-surcharge') . '</button>';
                echo '<input type="hidden" name="wc_payment_surcharge_action" id="wc_payment_surcharge_action" value="0">';
            }
        }
    }

    public function process_order_surcharge_admin($order) {
        $payment_method = $order->get_payment_method();
        $is_stripe_express = false;
        $express_type = false;
        $surcharge_percentage = 0;

        // Check for Stripe express checkout
        if ($payment_method === 'stripe' && 
            isset($this->settings['stripe_express_checkout_enable']) && 
            $this->settings['stripe_express_checkout_enable'] === 'yes'
        ) {
            $express_type = $order->get_meta('_payment_surcharge_express_type');
            if ($express_type && isset($this->settings['stripe_' . $express_type . '_surcharge'])) {
                $surcharge_percentage = floatval($this->settings['stripe_' . $express_type . '_surcharge']);
                $is_stripe_express = true;
            }
        }

        // Fall back to regular payment method surcharge
        if (!$is_stripe_express && isset($this->settings[$payment_method])) {
            $surcharge_percentage = floatval($this->settings[$payment_method]);
        }

        if ($surcharge_percentage <= 0) {
            return;
        }

        $total = $order->get_subtotal() + $order->get_shipping_total();
        $surcharge_amount = ($total * $surcharge_percentage) / 100;

        $minimum_surcharge = isset($this->settings['surcharge_minimum']) ? floatval($this->settings['surcharge_minimum']) : 0;
        if ($minimum_surcharge > 0 && $surcharge_amount < $minimum_surcharge) {
            $surcharge_amount = $minimum_surcharge;
        }

        $surcharge_label = isset($this->settings['surcharge_label']) ? $this->settings['surcharge_label'] : __('Handling Fee', 'wc-payment-surcharge');
        $taxable = isset($this->settings['surcharge_tax_status']) && $this->settings['surcharge_tax_status'] === 'yes';

        // Add fee to order
        $item = new WC_Order_Item_Fee();
        $item->set_name($surcharge_label);
        $item->set_amount($surcharge_amount);
        $item->set_tax_status($taxable ? 'taxable' : 'none');
        $item->set_total($surcharge_amount);
        
        if ($taxable) {
            $item->set_tax_class('');
            $item->calculate_taxes();
        }
        
        $order->add_item($item);
        $order->calculate_totals();
        $order->save();

        // Store meta
        $order->update_meta_data('_payment_surcharge_amount', $surcharge_amount);
        $order->update_meta_data('_payment_surcharge_method', $payment_method);
        $order->update_meta_data('_payment_surcharge_percentage', $surcharge_percentage);
        $order->update_meta_data('_payment_surcharge_label', $surcharge_label);
        if ($is_stripe_express) {
            $order->update_meta_data('_payment_surcharge_express_type', $express_type);
        }
        $order->save();
    }

    public function maybe_add_surcharge_to_pay_order($button) {
        global $wp;
        $order_id = absint($wp->query_vars['order-pay']);
        $order = wc_get_order($order_id);
        
        if ($order->get_meta('_payment_surcharge_amount')) {
            return $button;
        }

        $payment_method = $order->get_payment_method();
        if (isset($this->settings[$payment_method])) {
            $button = str_replace('name="woocommerce_pay"', 'name="woocommerce_pay" onclick="jQuery(\'input[name=\\\'wc_payment_surcharge_apply\\\']\').val(\'1\');"', $button);
            $button .= '<input type="hidden" name="wc_payment_surcharge_apply" value="0">';
        }
        
        return $button;
    }

    public function add_surcharge_to_pay_order($order) {
        if (!isset($_POST['wc_payment_surcharge_apply']) || !$_POST['wc_payment_surcharge_apply']) {
            return;
        }

        $payment_method = $order->get_payment_method();
        $is_stripe_express = false;
        $express_type = false;
        $surcharge_percentage = 0;

        // Check for Stripe express checkout
        if ($payment_method === 'stripe' && 
            isset($this->settings['stripe_express_checkout_enable']) && 
            $this->settings['stripe_express_checkout_enable'] === 'yes'
        ) {
            $express_type = $order->get_meta('_payment_surcharge_express_type');
            if ($express_type && isset($this->settings['stripe_' . $express_type . '_surcharge'])) {
                $surcharge_percentage = floatval($this->settings['stripe_' . $express_type . '_surcharge']);
                $is_stripe_express = true;
            }
        }

        // Fall back to regular payment method surcharge
        if (!$is_stripe_express && isset($this->settings[$payment_method])) {
            $surcharge_percentage = floatval($this->settings[$payment_method]);
        }

        if ($surcharge_percentage <= 0) {
            return;
        }

        $total = $order->get_subtotal() + $order->get_shipping_total();
        $surcharge_amount = ($total * $surcharge_percentage) / 100;

        $minimum_surcharge = isset($this->settings['surcharge_minimum']) ? floatval($this->settings['surcharge_minimum']) : 0;
        if ($minimum_surcharge > 0 && $surcharge_amount < $minimum_surcharge) {
            $surcharge_amount = $minimum_surcharge;
        }

        $surcharge_label = isset($this->settings['surcharge_label']) ? $this->settings['surcharge_label'] : __('Handling Fee', 'wc-payment-surcharge');
        $taxable = isset($this->settings['surcharge_tax_status']) && $this->settings['surcharge_tax_status'] === 'yes';

        // Add fee to order
        $item = new WC_Order_Item_Fee();
        $item->set_name($surcharge_label);
        $item->set_amount($surcharge_amount);
        $item->set_tax_status($taxable ? 'taxable' : 'none');
        $item->set_total($surcharge_amount);
        
        if ($taxable) {
            $item->set_tax_class('');
            $item->calculate_taxes();
        }
        
        $order->add_item($item);
        $order->calculate_totals();
        $order->save();

        // Store meta
        $order->update_meta_data('_payment_surcharge_amount', $surcharge_amount);
        $order->update_meta_data('_payment_surcharge_method', $payment_method);
        $order->update_meta_data('_payment_surcharge_percentage', $surcharge_percentage);
        $order->update_meta_data('_payment_surcharge_label', $surcharge_label);
        if ($is_stripe_express) {
            $order->update_meta_data('_payment_surcharge_express_type', $express_type);
        }
        $order->save();
        }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-payment-surcharge') . '">' . __('Settings', 'wc-payment-surcharge') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}