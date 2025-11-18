<?php
/**
 * WooCommerce Wallet Cashback Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Cashback {

    /**
     * The single instance of the class
     */
    protected static $_instance = null;

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
        add_action('woocommerce_order_status_completed', array($this, 'process_cashback'), 20);
        add_action('woocommerce_order_status_processing', array($this, 'process_cashback'), 20);
    }

    /**
     * Process cashback for completed orders
     */
    public function process_cashback($order_id) {
        // Check if cashback is enabled
        if (get_option('wc_wallet_cashback_enable') !== 'yes') {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if cashback already processed
        $cashback_processed = $order->get_meta('_wallet_cashback_processed');
        if ($cashback_processed === 'yes') {
            return;
        }

        // Don't give cashback for wallet top-up orders
        $is_wallet_topup = $order->get_meta('_is_wallet_topup');
        if ($is_wallet_topup === 'yes') {
            return;
        }

        // Don't give cashback if paid with wallet (optional - you can change this)
        // Uncomment the lines below if you don't want cashback on wallet payments
        // if ($order->get_payment_method() === 'wallet') {
        //     return;
        // }

        // Get user
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return; // Guest checkout, no cashback
        }

        // Calculate cashback amount
        $cashback_amount = $this->calculate_cashback($order, $user_id);

        if ($cashback_amount <= 0) {
            return;
        }

        // Credit cashback to wallet
        $wallet_manager = WC_Wallet_Manager::instance();
        $transaction_id = $wallet_manager->credit(
            $user_id,
            $cashback_amount,
            sprintf(
                __('Cashback for order #%s (%s%% cashback)', 'wc-wallet'),
                $order->get_order_number(),
                $this->get_user_cashback_percentage($user_id)
            ),
            $order_id
        );

        if ($transaction_id) {
            // Mark as processed
            $order->update_meta_data('_wallet_cashback_processed', 'yes');
            $order->update_meta_data('_wallet_cashback_amount', $cashback_amount);
            $order->update_meta_data('_wallet_cashback_transaction_id', $transaction_id);
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(
                    __('Cashback credited to wallet: %s', 'wc-wallet'),
                    wc_price($cashback_amount)
                )
            );

            // Fire action for other plugins
            do_action('wc_wallet_cashback_credited', $user_id, $cashback_amount, $order_id, $transaction_id);
        }
    }

    /**
     * Calculate cashback amount for an order
     */
    public function calculate_cashback($order, $user_id) {
        $percentage = $this->get_user_cashback_percentage($user_id);

        if ($percentage <= 0) {
            return 0;
        }

        // Get order total (excluding shipping and taxes if configured)
        $include_shipping = get_option('wc_wallet_cashback_include_shipping', 'no') === 'yes';
        $include_taxes = get_option('wc_wallet_cashback_include_taxes', 'no') === 'yes';

        $cashback_base = $order->get_subtotal();

        if ($include_shipping) {
            $cashback_base += $order->get_shipping_total();
        }

        if ($include_taxes) {
            $cashback_base += $order->get_total_tax();
        }

        // Calculate cashback
        $cashback_amount = ($cashback_base * $percentage) / 100;

        // Apply maximum cashback limit if set
        $max_cashback = get_option('wc_wallet_cashback_max_amount', 0);
        if ($max_cashback > 0 && $cashback_amount > $max_cashback) {
            $cashback_amount = $max_cashback;
        }

        // Round to 2 decimal places
        $cashback_amount = round($cashback_amount, 2);

        // Allow filtering
        return apply_filters('wc_wallet_cashback_amount', $cashback_amount, $order, $user_id, $percentage);
    }

    /**
     * Get cashback percentage for a user based on their role
     */
    public function get_user_cashback_percentage($user_id) {
        $user = get_userdata($user_id);

        if (!$user) {
            return 0;
        }

        // Get user roles
        $roles = $user->roles;

        if (empty($roles)) {
            return 0;
        }

        // Check each role and return the highest cashback percentage
        $highest_percentage = 0;

        foreach ($roles as $role) {
            $percentage = get_option('wc_wallet_cashback_' . $role, 0);
            $percentage = floatval($percentage);

            if ($percentage > $highest_percentage) {
                $highest_percentage = $percentage;
            }
        }

        return $highest_percentage;
    }

    /**
     * Get available user roles for cashback settings
     */
    public static function get_user_roles() {
        global $wp_roles;

        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        return $wp_roles->get_names();
    }

    /**
     * Get formatted cashback percentage for user
     */
    public function get_user_cashback_info($user_id) {
        $percentage = $this->get_user_cashback_percentage($user_id);

        if ($percentage <= 0) {
            return __('No cashback available', 'wc-wallet');
        }

        return sprintf(__('You earn %s%% cashback on purchases', 'wc-wallet'), $percentage);
    }
}
