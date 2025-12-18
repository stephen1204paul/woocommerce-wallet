(function($) {
    'use strict';

    var WC_Wallet_Partial = {
        /**
         * Flag to prevent infinite loops during sync
         */
        isSyncing: false,

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
            // Skip if we're in the middle of syncing to prevent infinite loops
            if (this.isSyncing) {
                return;
            }

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

            // Update input field max attribute
            var $input = $('#wallet_partial_amount');
            $input.attr('max', data.max_amount);

            // Only update the input value if it's empty or exceeds the new maximum
            var currentInputValue = parseFloat($input.val()) || 0;
            var preserveUserInput = false;

            // If user has entered an amount and it's within valid limits, keep it
            // Only update if:
            // 1. The input is empty/zero, OR
            // 2. The current input exceeds the new max amount
            if (currentInputValue === 0 || currentInputValue > data.max_amount) {
                $input.val(data.current_amount);
                currentInputValue = data.current_amount;
            } else {
                // User's input is being preserved
                preserveUserInput = true;
            }

            // Update cart total display
            if (data.formatted_cart_total) {
                $('.wallet-cart-total').html(data.formatted_cart_total);
            }

            if (data.formatted_balance) {
                $('.wallet-balance-display').html(data.formatted_balance);
            }

            // Update breakdown display based on actual input value
            // If we preserved user input, recalculate the breakdown
            if (preserveUserInput && currentInputValue > 0) {
                var walletAmount = currentInputValue;
                var remainingAmount = data.cart_total - walletAmount;

                // Format the amounts
                var currencyFormat = wc_wallet_partial_params.currency_format || '%s%v';
                var symbol = wc_wallet_partial_params.currency_symbol || '$';
                var decimals = wc_wallet_partial_params.decimals || 2;
                var decimalSep = wc_wallet_partial_params.decimal_separator || '.';
                var thousandSep = wc_wallet_partial_params.thousand_separator || ',';

                var formattedWallet = this.formatPrice(walletAmount, symbol, decimals, thousandSep, decimalSep, currencyFormat);
                var formattedRemaining = this.formatPrice(remainingAmount, symbol, decimals, thousandSep, decimalSep, currencyFormat);

                $('.wallet-breakdown-amount').html(formattedWallet);
                $('.wallet-remaining-amount').html(formattedRemaining);
            } else {
                // Use server's formatted values
                if (data.formatted_wallet) {
                    $('.wallet-breakdown-amount').html(data.formatted_wallet);
                }

                if (data.formatted_remaining) {
                    $('.wallet-remaining-amount').html(data.formatted_remaining);
                }
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

            // If we preserved user input and it differs from server's current_amount,
            // sync it to the server to keep session in sync
            if (preserveUserInput && currentInputValue !== data.current_amount) {
                this.syncAmountToServer(currentInputValue);
            }
        },

        /**
         * Sync amount to server and update checkout
         */
        syncAmountToServer: function(amount) {
            var self = this;
            self.isSyncing = true;

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
                        // Trigger checkout update to recalculate with correct wallet amount
                        $(document.body).trigger('update_checkout');
                    }
                },
                complete: function() {
                    // Clear flag after a short delay to ensure update_checkout completes
                    setTimeout(function() {
                        self.isSyncing = false;
                    }, 100);
                }
            });
        },

        /**
         * Format price for display
         */
        formatPrice: function(amount, symbol, decimals, thousandSep, decimalSep, format) {
            var value = parseFloat(amount).toFixed(decimals);
            var parts = value.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
            value = parts.join(decimalSep);

            return format.replace('%s', symbol).replace('%v', value);
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
