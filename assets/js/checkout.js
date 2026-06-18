jQuery(function($) {
    'use strict';

    // Track if we're processing an express checkout
    var isProcessingExpressCheckout = false;

    // Function to update surcharge
    function updateSurcharge(paymentMethod, expressType = null) {
        var $paymentMethodContainer = $('input[name="payment_method"][value="' + paymentMethod + '"]').closest('.wc_payment_method');
        var ajaxData = {
            action: 'get_payment_surcharge',
            payment_method: paymentMethod,
            nonce: wc_payment_surcharge_params.nonce,
            is_initial_load: true
        };

        // Handle express checkout specifically
        if (expressType) {
            ajaxData.payment_request_type = expressType;
            isProcessingExpressCheckout = true;
            
            // Find the express button container
            $paymentMethodContainer = $('.stripe-' + expressType + '-button').closest('.wc_payment_method');
            if (!$paymentMethodContainer.length) {
                $paymentMethodContainer = $('.payment_method_stripe').first();
            }
        }

        // Show loading state
        $paymentMethodContainer.addClass('processing').block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
        
        // Update UI
        $('.wc_payment_method').removeClass('has-surcharge');
        $paymentMethodContainer.addClass('has-surcharge');
        
        $.ajax({
            type: 'POST',
            url: wc_payment_surcharge_params.ajax_url,
            data: ajaxData,
            success: function(response) {
                // Trigger totals refresh
                $(document.body).trigger('update_checkout');
                
                // For express checkout, we need to update the payment request display
                if (expressType && typeof wc_stripe_payment_request_params !== 'undefined') {
                    setTimeout(function() {
                        if (typeof stripePaymentRequest !== 'undefined') {
                            stripePaymentRequest.update({
                                total: getUpdatedStripeTotal()
                            });
                        }
                    }, 500);
                }
            },
            complete: function() {
                $paymentMethodContainer.removeClass('processing').unblock();
                isProcessingExpressCheckout = false;
            },
            error: function() {
                isProcessingExpressCheckout = false;
            }
        });
    }

    // Get updated total for Stripe Payment Request
    function getUpdatedStripeTotal() {
        var total = parseFloat($('.order-total .amount').last().text().replace(/[^\d.-]/g, ''));
        return {
            label: wc_stripe_payment_request_params.total_label,
            amount: Math.round(total * 100) // Convert to cents
        };
    }

    // Handle payment method changes
    $(document.body).on('change', 'input[name="payment_method"]', function() {
        if (!isProcessingExpressCheckout) {
            updateSurcharge($(this).val());
        }
    });

    // Initialize on page load
    function initSurcharge() {
        var initialPaymentMethod = $('input[name="payment_method"]:checked').val();
        if (initialPaymentMethod && !$('body').hasClass('surcharge-initialized')) {
            // Force update after a small delay
            setTimeout(function() {
                updateSurcharge(initialPaymentMethod);
                $('body').addClass('surcharge-initialized');
            }, 500);
        }
    }

    // Handle express checkout buttons
    function handleExpressCheckout(buttonClass, expressType) {
        $(document.body).on('click', buttonClass, function(e) {
            if (wc_payment_surcharge_params.stripe_express_enabled === '1') {
                e.preventDefault();
                updateSurcharge('stripe', expressType);
                
                // Re-trigger the payment request after updating surcharge
                setTimeout(function() {
                    if (typeof stripePaymentRequest !== 'undefined') {
                        stripePaymentRequest.show();
                    }
                }, 1000);
            }
        });
    }

    // Initialize handlers for each express checkout type
    handleExpressCheckout('.stripe-apple-pay-button', 'apple_pay');
    handleExpressCheckout('.stripe-google-pay-button', 'google_pay');

    // Run on document ready
    $(function() {
        if (!wc_payment_surcharge_params.is_pay_page) {
            initSurcharge();
        }
    });

    // Also run when checkout updates
    $(document.body).on('updated_checkout', function() {
        if (!wc_payment_surcharge_params.is_pay_page && !$('body').hasClass('surcharge-initialized')) {
            initSurcharge();
        }
        
        // Update express checkout buttons with surcharge info
        if (wc_payment_surcharge_params.stripe_express_enabled === '1') {
            $('.stripe-apple-pay-button, .stripe-google-pay-button').each(function() {
                var $button = $(this);
                var expressType = $button.hasClass('stripe-apple-pay-button') ? 'apple_pay' : 'google_pay';
                var surcharge = $('.payment_method_stripe').data('surcharge-' + expressType);
                
                if (surcharge) {
                    $button.attr('data-surcharge', surcharge);
                }
            });
        }
        
        // Unblock all payment methods after update
        $('.wc_payment_method').removeClass('processing').unblock();
    });

    // Intercept Stripe Payment Request display to ensure surcharge is applied
    $(document.body).on('stripe-payment-show', function() {
        if (wc_payment_surcharge_params.stripe_express_enabled === '1') {
            updateSurcharge('stripe', 'payment_request_api');
        }
    });
});