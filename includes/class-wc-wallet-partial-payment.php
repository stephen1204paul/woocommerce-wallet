<?php
/**
 * WooCommerce Wallet Partial Payment Handler
 *
 * Handles partial wallet payments where customers can use a custom amount from their wallet
 * combined with another payment gateway.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Partial_Payment {

    /**
     * The single instance of the class
     */
    protected static $_instance = null;

    /**
     * Session key for storing partial wallet amount
     */
    const SESSION_KEY = 'wc_partial_wallet_amount';

    /**
     * Main Instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Frontend UI
        add_action('woocommerce_review_order_before_payment', array($this, 'render_partial_payment_section'));

        // AJAX handlers
        add_action('wp_ajax_wc_wallet_set_partial_amount', array($this, 'ajax_set_partial_amount'));
        add_action('wp_ajax_nopriv_wc_wallet_set_partial_amount', array($this, 'ajax_set_partial_amount'));

        // Cart/Checkout modifications
        add_filter('woocommerce_calculated_total', array($this, 'apply_wallet_discount'), 10, 2);
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_wallet_fee_line'), 10, 1);
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_payment_gateways'), 20);

        // Order processing
        add_action('woocommerce_checkout_order_processed', array($this, 'process_partial_payment'), 5, 1);
        add_action('woocommerce_order_status_failed', array($this, 'rollback_wallet_payment'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array($this, 'rollback_wallet_payment'), 10, 1);

        // Session management
        add_action('woocommerce_cart_emptied', array($this, 'clear_partial_amount'));
        add_action('woocommerce_cart_updated', array($this, 'handle_cart_update'));
    }

    /**
     * Set partial wallet amount in session
     *
     * @param float $amount Amount to set
     * @return bool Success
     */
    public function set_partial_amount($amount) {
        $amount = floatval($amount);

        if ($amount < 0) {
            $amount = 0;
        }

        if (WC()->session) {
            WC()->session->set(self::SESSION_KEY, $amount);
            return true;
        }

        return false;
    }

    /**
     * Get partial wallet amount from session
     *
     * @return float Amount
     */
    public function get_partial_amount() {
        if (WC()->session) {
            return floatval(WC()->session->get(self::SESSION_KEY, 0));
        }

        return 0;
    }

    /**
     * Clear partial wallet amount from session
     */
    public function clear_partial_amount() {
        if (WC()->session) {
            WC()->session->set(self::SESSION_KEY, null);
        }
    }

    /**
     * Validate wallet amount
     *
     * @param float $amount Amount to validate
     * @param int|null $user_id User ID (defaults to current user)
     * @return array ['valid' => bool, 'message' => string]
     */
    public function validate_amount($amount, $user_id = null) {
        $amount = floatval($amount);

        if ($amount < 0) {
            return array(
                'valid' => false,
                'message' => __('Amount cannot be negative.', 'wc-wallet')
            );
        }

        if ($amount == 0) {
            return array('valid' => true, 'message' => '');
        }

        if ($amount < 0.01) {
            return array(
                'valid' => false,
                'message' => __('Minimum amount is 0.01', 'wc-wallet')
            );
        }

        // Get user ID
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return array(
                    'valid' => false,
                    'message' => __('You must be logged in to use wallet payment.', 'wc-wallet')
                );
            }
            $user_id = get_current_user_id();
        }

        // Check wallet balance
        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        if ($amount > $balance) {
            return array(
                'valid' => false,
                'message' => sprintf(
                    __('Amount exceeds your wallet balance of %s', 'wc-wallet'),
                    wc_price($balance)
                )
            );
        }

        // Check cart total
        if (WC()->cart) {
            $cart_total = WC()->cart->get_total('edit');

            if ($amount > $cart_total) {
                return array(
                    'valid' => false,
                    'message' => sprintf(
                        __('Amount exceeds cart total of %s', 'wc-wallet'),
                        wc_price($cart_total)
                    )
                );
            }
        }

        return array('valid' => true, 'message' => '');
    }

    /**
     * Get maximum wallet amount that can be used
     *
     * @param int|null $user_id User ID
     * @return float Maximum amount
     */
    public function get_max_wallet_amount($user_id = null) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return 0;
            }
            $user_id = get_current_user_id();
        }

        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        if (WC()->cart) {
            $cart_total = WC()->cart->get_total('edit');
            return min($balance, $cart_total);
        }

        return $balance;
    }

    /**
     * Render partial payment section on checkout page
     */
    public function render_partial_payment_section() {
        // Only show for logged-in users
        if (!is_user_logged_in()) {
            return;
        }

        // Check if cart exists and has items
        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $user_id = get_current_user_id();
        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        // Don't show if no balance
        if ($balance <= 0) {
            return;
        }

        $cart_total = WC()->cart->get_total('edit');
        $max_amount = min($balance, $cart_total);
        $current_amount = $this->get_partial_amount();

        // Calculate quick amounts
        $quick_25 = round($max_amount * 0.25, 2);
        $quick_50 = round($max_amount * 0.50, 2);
        $quick_75 = round($max_amount * 0.75, 2);
        $quick_100 = $max_amount;

        // Calculate breakdown
        $wallet_payment = $current_amount > 0 ? $current_amount : 0;
        $remaining = $cart_total - $wallet_payment;

        // Load template
        include(WC_WALLET_PLUGIN_DIR . 'templates/checkout/partial-payment-section.php');
    }

    /**
     * AJAX handler to set partial wallet amount
     */
    public function ajax_set_partial_amount() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_wallet_partial_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed.', 'wc-wallet')
            ));
        }

        // Get amount
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

        // Validate amount
        $validation = $this->validate_amount($amount);

        if (!$validation['valid']) {
            wp_send_json_error(array(
                'message' => $validation['message']
            ));
        }

        // Set amount
        $this->set_partial_amount($amount);

        // Get updated totals
        $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;
        $remaining_total = $cart_total - $amount;

        wp_send_json_success(array(
            'wallet_amount' => $amount,
            'cart_total' => $cart_total,
            'remaining_total' => $remaining_total,
            'formatted_wallet' => wc_price($amount),
            'formatted_remaining' => wc_price($remaining_total),
            'formatted_cart_total' => wc_price($cart_total)
        ));
    }

    /**
     * Apply wallet discount to cart total
     *
     * @param float $total Cart total
     * @param WC_Cart $cart Cart object
     * @return float Modified total
     */
    public function apply_wallet_discount($total, $cart) {
        $wallet_amount = $this->get_partial_amount();

        if ($wallet_amount > 0 && $wallet_amount <= $total) {
            return $total - $wallet_amount;
        }

        return $total;
    }

    /**
     * Add wallet payment as a negative fee for display
     *
     * @param WC_Cart $cart Cart object
     */
    public function add_wallet_fee_line($cart) {
        $wallet_amount = $this->get_partial_amount();

        if ($wallet_amount > 0) {
            $cart->add_fee(__('Wallet Payment', 'wc-wallet'), -$wallet_amount, false);
        }
    }

    /**
     * Filter payment gateways - hide wallet gateway when partial payment is active
     *
     * @param array $gateways Available gateways
     * @return array Filtered gateways
     */
    public function filter_payment_gateways($gateways) {
        $wallet_amount = $this->get_partial_amount();

        // Hide wallet gateway if partial amount is set
        if ($wallet_amount > 0 && isset($gateways['wallet'])) {
            unset($gateways['wallet']);
        }

        return $gateways;
    }

    /**
     * Process partial wallet payment before gateway payment
     *
     * @param int $order_id Order ID
     */
    public function process_partial_payment($order_id) {
        $wallet_amount = $this->get_partial_amount();

        // No partial payment to process
        if ($wallet_amount <= 0) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();

        if (!$user_id) {
            wc_add_notice(__('Invalid user for wallet payment.', 'wc-wallet'), 'error');
            return;
        }

        // Prevent concurrent processing
        $lock_key = 'wallet_partial_lock_' . $user_id;

        if (get_transient($lock_key)) {
            throw new Exception(__('Another order is being processed. Please wait.', 'wc-wallet'));
        }

        set_transient($lock_key, true, 60);

        try {
            // Re-validate balance
            $wallet_manager = WC_Wallet_Manager::instance();
            $current_balance = $wallet_manager->get_wallet_balance($user_id);

            if ($current_balance < $wallet_amount) {
                $this->clear_partial_amount();
                delete_transient($lock_key);

                throw new Exception(
                    sprintf(
                        __('Your wallet balance has changed. Current balance: %s. Please review and try again.', 'wc-wallet'),
                        wc_price($current_balance)
                    )
                );
            }

            // Get original total before any modifications
            $original_total = $order->get_total() + $wallet_amount;

            // Debit wallet
            $transaction_id = $wallet_manager->debit(
                $user_id,
                $wallet_amount,
                sprintf(
                    __('Partial payment for order #%s (%s of %s total)', 'wc-wallet'),
                    $order->get_order_number(),
                    wc_price($wallet_amount),
                    wc_price($original_total)
                ),
                $order_id
            );

            if (is_wp_error($transaction_id)) {
                delete_transient($lock_key);
                throw new Exception($transaction_id->get_error_message());
            }

            // Store order meta
            $order->update_meta_data('_partial_wallet_used', 'yes');
            $order->update_meta_data('_partial_wallet_amount', $wallet_amount);
            $order->update_meta_data('_partial_wallet_transaction_id', $transaction_id);
            $order->update_meta_data('_original_order_total', $original_total);
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(
                    __('Partial wallet payment: %s debited from wallet. Remaining %s to be paid via %s.', 'wc-wallet'),
                    wc_price($wallet_amount),
                    wc_price($order->get_total()),
                    $order->get_payment_method_title()
                )
            );

            // Clear session
            $this->clear_partial_amount();

        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Rollback wallet payment for failed/cancelled orders
     *
     * @param int $order_id Order ID
     */
    public function rollback_wallet_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if partial wallet was used
        $wallet_used = $order->get_meta('_partial_wallet_used');

        if ($wallet_used !== 'yes') {
            return;
        }

        // Check if already rolled back
        $rolled_back = $order->get_meta('_wallet_payment_rolled_back');

        if ($rolled_back === 'yes') {
            return;
        }

        // Get transaction details
        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));
        $user_id = $order->get_user_id();

        if (!$user_id || $wallet_amount <= 0) {
            return;
        }

        // Credit wallet (refund)
        $wallet_manager = WC_Wallet_Manager::instance();
        $refund_transaction = $wallet_manager->credit(
            $user_id,
            $wallet_amount,
            sprintf(
                __('Refund for failed/cancelled order #%s', 'wc-wallet'),
                $order->get_order_number()
            ),
            $order_id
        );

        if (is_wp_error($refund_transaction)) {
            $order->add_order_note(
                sprintf(
                    __('Failed to refund wallet payment: %s', 'wc-wallet'),
                    $refund_transaction->get_error_message()
                )
            );
            return;
        }

        // Mark as rolled back
        $order->update_meta_data('_wallet_payment_rolled_back', 'yes');
        $order->update_meta_data('_wallet_rollback_transaction_id', $refund_transaction);
        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Partial wallet payment of %s refunded due to order failure/cancellation.', 'wc-wallet'),
                wc_price($wallet_amount)
            )
        );
    }

    /**
     * Handle cart updates - adjust wallet amount if needed
     */
    public function handle_cart_update() {
        $wallet_amount = $this->get_partial_amount();

        if ($wallet_amount <= 0) {
            return;
        }

        if (!WC()->cart) {
            return;
        }

        $cart_total = WC()->cart->get_total('edit');

        // If wallet amount exceeds new cart total, adjust it
        if ($wallet_amount > $cart_total) {
            $this->set_partial_amount($cart_total);

            wc_add_notice(
                sprintf(
                    __('Cart updated. Wallet payment adjusted to %s', 'wc-wallet'),
                    wc_price($cart_total)
                ),
                'notice'
            );
        }
    }
}
