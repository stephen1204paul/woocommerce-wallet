/**
 * WooCommerce Wallet JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // Wallet top-up form validation
        $('.wallet-topup-form').on('submit', function(e) {
            var amount = parseFloat($('#topup_amount').val());
            var minAmount = parseFloat(wc_wallet_params.min_topup);
            var maxAmount = parseFloat(wc_wallet_params.max_topup);

            if (isNaN(amount) || amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount.');
                return false;
            }

            if (amount < minAmount) {
                e.preventDefault();
                alert('Minimum top-up amount is ' + wc_wallet_params.currency_symbol + minAmount);
                return false;
            }

            if (amount > maxAmount) {
                e.preventDefault();
                alert('Maximum top-up amount is ' + wc_wallet_params.currency_symbol + maxAmount);
                return false;
            }

            return true;
        });

        // Format amount input
        $('#topup_amount').on('blur', function() {
            var amount = parseFloat($(this).val());
            if (!isNaN(amount)) {
                $(this).val(amount.toFixed(2));
            }
        });

        // Add quick amount buttons (optional enhancement)
        if ($('.wallet-topup-form').length) {
            var quickAmounts = [10, 25, 50, 100, 250, 500];
            var $quickAmountDiv = $('<div class="wallet-quick-amounts" style="margin: 15px 0;"></div>');
            $quickAmountDiv.append('<label style="display: block; margin-bottom: 8px; font-weight: 600;">Quick Amounts:</label>');

            quickAmounts.forEach(function(amount) {
                var $btn = $('<button type="button" class="wallet-quick-amount-btn" style="margin: 5px; padding: 8px 15px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">' + wc_wallet_params.currency_symbol + amount + '</button>');

                $btn.on('click', function() {
                    $('#topup_amount').val(amount);
                });

                $quickAmountDiv.append($btn);
            });

            $('.wallet-topup-form .form-row:first').after($quickAmountDiv);
        }

        // Highlight quick amount button on hover
        $(document).on('mouseenter', '.wallet-quick-amount-btn', function() {
            $(this).css({
                'background': '#667eea',
                'color': '#fff',
                'border-color': '#667eea'
            });
        }).on('mouseleave', '.wallet-quick-amount-btn', function() {
            $(this).css({
                'background': '#f5f5f5',
                'color': '#000',
                'border-color': '#ddd'
            });
        });

        // Wallet payment gateway - update balance display on checkout
        if ($('input[name="payment_method"][value="wallet"]').length) {
            $('body').on('updated_checkout', function() {
                // Checkout updated, balance display will refresh
            });
        }

    });

})(jQuery);
