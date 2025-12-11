(function($) {
    'use strict';

    var WC_Wallet_Partial = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.restoreAmount();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            $(document).on('change', '#wallet_partial_amount', this.handleAmountChange.bind(this));
            $(document).on('click', '#wallet_partial_clear', this.handleClearAmount.bind(this));
            $(document.body).on('updated_checkout', this.handleCheckoutUpdate.bind(this));
            $(document.body).on('updated_cart_totals', this.handleCheckoutUpdate.bind(this));
        },

        /**
         * Validate amount
         */
        validateAmount: function(amount) {
            amount = parseFloat(amount);

            if (isNaN(amount)) {
                return {
                    valid: false,
                    message: wc_wallet_partial_params.i18n.amount_invalid
                };
            }

            if (amount < 0) {
                return {
                    valid: false,
                    message: wc_wallet_partial_params.i18n.amount_invalid
                };
            }

            var maxAmount = Math.min(
                parseFloat(wc_wallet_partial_params.user_balance),
                parseFloat(wc_wallet_partial_params.cart_total)
            );

            if (amount > maxAmount) {
                return {
                    valid: false,
                    message: wc_wallet_partial_params.i18n.amount_too_high
                };
            }

            return {
                valid: true,
                message: ''
            };
        },

        /**
         * Set amount via AJAX
         */
        setAmount: function(amount) {
            var self = this;

            // Validate first
            var validation = this.validateAmount(amount);

            if (!validation.valid && amount > 0) {
                this.showError(validation.message);
                return;
            }

            this.hideError();
            this.showLoading(true);

            $.ajax({
                url: wc_wallet_partial_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_wallet_set_partial_amount',
                    amount: amount,
                    nonce: wc_wallet_partial_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateUI(response.data);
                        $(document.body).trigger('update_checkout');
                    } else {
                        self.showError(response.data.message);
                    }
                },
                error: function() {
                    self.showError(wc_wallet_partial_params.i18n.amount_invalid);
                },
                complete: function() {
                    self.showLoading(false);
                }
            });
        },

        /**
         * Update UI with new amounts
         */
        updateUI: function(data) {
            if (data.formatted_wallet) {
                $('.wallet-breakdown-amount').html(data.formatted_wallet);
            }

            if (data.formatted_remaining) {
                $('.wallet-remaining-amount').html(data.formatted_remaining);
            }

            if (data.formatted_cart_total) {
                $('.wallet-cart-total').html(data.formatted_cart_total);
            }

            // Show/hide clear button
            if (data.wallet_amount > 0) {
                if ($('#wallet_partial_clear').length === 0) {
                    var clearButton = '<div class="wallet-clear-section"><button type="button" id="wallet_partial_clear" class="button">' +
                        'Clear Wallet Payment' +
                        '</button></div>';
                    $('.wc-wallet-partial-payment-section').append(clearButton);
                }
                $('.wallet-clear-section').show();
            } else {
                $('.wallet-clear-section').hide();
            }
        },

        /**
         * Handle amount input change
         */
        handleAmountChange: function(e) {
            var amount = $(e.target).val();
            this.setAmount(amount);
        },

        /**
         * Handle clear amount button click
         */
        handleClearAmount: function(e) {
            e.preventDefault();
            $('#wallet_partial_amount').val('');
            this.setAmount(0);
        },

        /**
         * Handle checkout update
         */
        handleCheckoutUpdate: function() {
            // Fetch updated cart totals when checkout updates (e.g., shipping changes)
            this.fetchUpdatedTotals();
        },

        /**
         * Fetch updated cart totals from server
         */
        fetchUpdatedTotals: function() {
            var self = this;

            $.ajax({
                url: wc_wallet_partial_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'wc_wallet_get_updated_totals',
                    nonce: wc_wallet_partial_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateTotals(response.data);
                    }
                }
            });
        },

        /**
         * Update totals after cart/shipping changes
         */
        updateTotals: function(data) {
            // Update script params with new values
            wc_wallet_partial_params.user_balance = data.user_balance;
            wc_wallet_partial_params.cart_total = data.cart_total;

            // Update input field max attribute and value if needed
            var $input = $('#wallet_partial_amount');
            $input.attr('max', data.max_amount);

            if (data.current_amount !== parseFloat($input.val())) {
                $input.val(data.current_amount);
            }

            // Update breakdown display
            if (data.formatted_cart_total) {
                $('.wallet-cart-total').html(data.formatted_cart_total);
            }

            if (data.formatted_wallet) {
                $('.wallet-breakdown-amount').html(data.formatted_wallet);
            }

            if (data.formatted_remaining) {
                $('.wallet-remaining-amount').html(data.formatted_remaining);
            }

            if (data.formatted_balance) {
                $('.wallet-balance-display').html(data.formatted_balance);
            }

            // Re-validate current amount
            var currentAmount = $input.val();
            if (currentAmount) {
                var validation = this.validateAmount(currentAmount);

                if (!validation.valid) {
                    this.showError(validation.message);
                } else {
                    this.hideError();
                }
            }
        },

        /**
         * Restore amount from previous state
         */
        restoreAmount: function() {
            var savedAmount = $('#wallet_partial_amount').val();

            if (savedAmount && parseFloat(savedAmount) > 0) {
                // Amount already set in template
                this.hideError();
            }
        },

        /**
         * Show error message
         */
        showError: function(message) {
            var $input = $('#wallet_partial_amount');
            var $error = $('.wallet-error-message');

            $input.addClass('error');
            $error.text(message).addClass('show');
        },

        /**
         * Hide error message
         */
        hideError: function() {
            var $input = $('#wallet_partial_amount');
            var $error = $('.wallet-error-message');

            $input.removeClass('error');
            $error.text('').removeClass('show');
        },

        /**
         * Show/hide loading state
         */
        showLoading: function(show) {
            if (show) {
                $('.wc-wallet-partial-payment-section').addClass('processing');
            } else {
                $('.wc-wallet-partial-payment-section').removeClass('processing');
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        if ($('.wc-wallet-partial-payment-section').length > 0) {
            WC_Wallet_Partial.init();
        }
    });

})(jQuery);
