/**
 * WooCommerce Payment Method Surcharge - Frontend JavaScript
 * 
 * Handles dynamic surcharge updates when payment method changes
 * Supports both cart and checkout pages
 * 
 * @package WC_Payment_Surcharge
 * @version 2.3.5
 */

(function($) {
    'use strict';

    /**
     * Payment Surcharge Handler
     */
    var WCPaymentSurcharge = {
        /**
         * Initialize the handler
         */
        init: function() {
            this.bindEvents();
            this.initialCheck();
        },

        /**
         * Bind event listeners
         */
        bindEvents: function() {
            var self = this;

            // When payment method changes on checkout
            $(document).on('change', 'input[name="payment_method"]', function() {
                self.updateSurcharge($(this).val());
            });

            // When cart is updated
            $(document.body).on('updated_cart_totals', function() {
                self.checkSurchargeStatus();
            });

            // When checkout is updated
            $(document.body).on('updated_checkout', function() {
                self.checkSurchargeStatus();
            });

            // When payment method is selected in cart (if applicable)
            $(document).on('change', '.wc-payment-method-selector', function() {
                self.updateSurcharge($(this).val());
            });

            // Handle express checkout buttons
            $(document).on('click', '.wc-stripe-payment-request-button', function() {
                // Store current payment method for express checkout
                var paymentMethod = 'stripe_express';
                self.storePaymentMethod(paymentMethod);
            });

            // Listen for checkout errors
            $(document.body).on('checkout_error', function() {
                self.handleCheckoutError();
            });
        },

        /**
         * Initial check for existing surcharge
         */
        initialCheck: function() {
            var selectedPayment = $('input[name="payment_method"]:checked').val();
            if (selectedPayment) {
                this.updateSurcharge(selectedPayment);
            }
        },

        /**
         * Update surcharge based on payment method
         * 
         * @param {string} paymentMethod The selected payment method ID
         */
        updateSurcharge: function(paymentMethod) {
            var self = this;

            if (!paymentMethod) {
                return;
            }

            // Store the selected payment method
            this.storePaymentMethod(paymentMethod);

            // Show loading state
            this.showLoadingState();

            // Make AJAX request to get surcharge info
            $.ajax({
                url: wc_payment_surcharge_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_payment_surcharge',
                    payment_method: paymentMethod,
                    nonce: wc_payment_surcharge_params.nonce
                },
                success: function(response) {
                    self.handleSurchargeResponse(response);
                },
                error: function(xhr, status, error) {
                    self.handleSurchargeError(error);
                },
                complete: function() {
                    self.hideLoadingState();
                    // Trigger checkout update to refresh totals
                    $(document.body).trigger('update_checkout');
                }
            });
        },

        /**
         * Handle surcharge response from server
         * 
         * @param {object} response The AJAX response
         */
        handleSurchargeResponse: function(response) {
            if (response.success) {
                var data = response.data;
                
                // Update surcharge notice if present
                this.updateSurchargeNotice(data);
                
                // Store surcharge data
                this.storeSurchargeData(data);
                
                // Log surcharge info for debugging
                if (data.amount > 0) {
                    console.log('Payment Surcharge: ' + data.rate + '% (' + data.formatted_amount + ')');
                }
            } else {
                this.handleSurchargeError(response.data.message);
            }
        },

        /**
         * Update surcharge notice on page
         * 
         * @param {object} data Surcharge data
         */
        updateSurchargeNotice: function(data) {
            var noticeContainer = $('.wc-payment-surcharge-notice');
            
            if (data.amount > 0 && data.label) {
                var noticeHtml = '<p><small>' +
                    'A surcharge of ' + data.formatted_amount + ' will be applied to your order.' +
                    '</small></p>';
                
                if (noticeContainer.length) {
                    noticeContainer.html(noticeHtml).show();
                } else {
                    // Create notice if it doesn't exist
                    var newNotice = $('<div class="wc-payment-surcharge-notice">' + noticeHtml + '</div>');
                    if ($('.woocommerce-checkout-review-order').length) {
                        newNotice.insertBefore('.woocommerce-checkout-review-order');
                    } else if ($('.cart_totals').length) {
                        newNotice.insertBefore('.cart_totals');
                    }
                }
            } else {
                if (noticeContainer.length) {
                    noticeContainer.hide();
                }
            }
        },

        /**
         * Show loading state
         */
        showLoadingState: function() {
            $('.wc-payment-surcharge-notice').addClass('loading');
            $('.payment-method-surcharge-loading').show();
        },

        /**
         * Hide loading state
         */
        hideLoadingState: function() {
            $('.wc-payment-surcharge-notice').removeClass('loading');
            $('.payment-method-surcharge-loading').hide();
        },

        /**
         * Store payment method in session
         * 
         * @param {string} paymentMethod Payment method ID
         */
        storePaymentMethod: function(paymentMethod) {
            try {
                sessionStorage.setItem('wc_payment_method', paymentMethod);
            } catch (e) {
                // Session storage not available
            }
        },

        /**
         * Store surcharge data
         * 
         * @param {object} data Surcharge data
         */
        storeSurchargeData: function(data) {
            try {
                sessionStorage.setItem('wc_surcharge_amount', data.amount);
                sessionStorage.setItem('wc_surcharge_label', data.label);
                sessionStorage.setItem('wc_surcharge_rate', data.rate);
            } catch (e) {
                // Session storage not available
            }
        },

        /**
         * Check surcharge status
         */
        checkSurchargeStatus: function() {
            var self = this;
            
            // Check if we have stored payment method
            var storedPayment = sessionStorage.getItem('wc_payment_method');
            if (storedPayment) {
                var currentPayment = $('input[name="payment_method"]:checked').val();
                if (currentPayment && currentPayment !== storedPayment) {
                    this.updateSurcharge(currentPayment);
                }
            }
            
            // Check if surcharge notice is still visible
            var surchargeAmount = sessionStorage.getItem('wc_surcharge_amount');
            if (surchargeAmount && parseFloat(surchargeAmount) > 0) {
                var noticeContainer = $('.wc-payment-surcharge-notice');
                if (noticeContainer.length && !noticeContainer.is(':visible')) {
                    // Re-trigger update if notice disappeared
                    $(document.body).trigger('update_checkout');
                }
            }
        },

        /**
         * Handle checkout error
         */
        handleCheckoutError: function() {
            // Clear stored data on error
            this.clearStoredData();
        },

        /**
         * Handle surcharge error
         * 
         * @param {string} error Error message
         */
        handleSurchargeError: function(error) {
            console.warn('Payment Surcharge Error:', error);
            
            // Show error in notice if available
            var noticeContainer = $('.wc-payment-surcharge-notice');
            if (noticeContainer.length) {
                noticeContainer.html('<p><small>Error loading surcharge information.</small></p>').show();
            }
        },

        /**
         * Clear stored data
         */
        clearStoredData: function() {
            try {
                sessionStorage.removeItem('wc_payment_method');
                sessionStorage.removeItem('wc_surcharge_amount');
                sessionStorage.removeItem('wc_surcharge_label');
                sessionStorage.removeItem('wc_surcharge_rate');
            } catch (e) {
                // Session storage not available
            }
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Check if we're on cart or checkout page
        if ($('.woocommerce-cart').length || $('.woocommerce-checkout').length) {
            WCPaymentSurcharge.init();
        }
    });

    /**
     * Re-initialize on AJAX updates
     */
    $(document.body).on('updated_checkout', function() {
        // Ensure surcharge is updated after checkout refresh
        var selectedPayment = $('input[name="payment_method"]:checked').val();
        if (selectedPayment) {
            WCPaymentSurcharge.updateSurcharge(selectedPayment);
        }
    });

    /**
     * Handle theme-specific events
     */
    $(document.body).on('wc_fragments_loaded', function() {
        // Re-initialize after fragments are loaded (for cart updates)
        if ($('.woocommerce-cart').length) {
            WCPaymentSurcharge.checkSurchargeStatus();
        }
    });

})(jQuery);
