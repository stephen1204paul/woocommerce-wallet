<?php
/**
 * WooCommerce Wallet Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Manager {

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
        add_action('woocommerce_order_status_completed', array($this, 'credit_wallet_on_order_complete'));
        add_action('woocommerce_order_status_refunded', array($this, 'refund_to_wallet'));
        add_action('woocommerce_order_status_cancelled', array($this, 'refund_wallet_payment'));
    }

    /**
     * Get user wallet balance
     */
    public function get_wallet_balance($user_id = null) {
        if (empty($user_id)) {
            $user_id = get_current_user_id();
        }

        return WC_Wallet_Database::get_balance($user_id);
    }

    /**
     * Credit wallet
     */
    public function credit($user_id, $amount, $details = '', $order_id = null) {
        if ($amount <= 0) {
            return false;
        }

        // Update balance
        $new_balance = WC_Wallet_Database::update_balance($user_id, $amount);

        if ($new_balance === false) {
            return false;
        }

        // Add transaction record
        $transaction_id = WC_Wallet_Database::add_transaction($user_id, 'credit', $amount, $details, $order_id);

        // Fire action
        do_action('wc_wallet_credited', $user_id, $amount, $new_balance, $transaction_id);

        return $transaction_id;
    }

    /**
     * Debit wallet
     */
    public function debit($user_id, $amount, $details = '', $order_id = null) {
        if ($amount <= 0) {
            return false;
        }

        // Check if user has sufficient balance
        $current_balance = $this->get_wallet_balance($user_id);

        if ($current_balance < $amount) {
            return new WP_Error('insufficient_balance', __('Insufficient wallet balance.', 'wc-wallet'));
        }

        // Update balance (debit is negative amount)
        $new_balance = WC_Wallet_Database::update_balance($user_id, -$amount);

        if ($new_balance === false) {
            return false;
        }

        // Add transaction record
        $transaction_id = WC_Wallet_Database::add_transaction($user_id, 'debit', -$amount, $details, $order_id);

        // Fire action
        do_action('wc_wallet_debited', $user_id, $amount, $new_balance, $transaction_id);

        return $transaction_id;
    }

    /**
     * Credit wallet when top-up order is completed
     */
    public function credit_wallet_on_order_complete($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if this is a wallet top-up order
        $is_wallet_topup = $order->get_meta('_is_wallet_topup');

        if ($is_wallet_topup !== 'yes') {
            return;
        }

        // Check if already credited
        $already_credited = $order->get_meta('_wallet_credited');

        if ($already_credited === 'yes') {
            return;
        }

        $user_id = $order->get_user_id();
        $amount = $order->get_total();

        // Credit the wallet
        $transaction_id = $this->credit(
            $user_id,
            $amount,
            sprintf(__('Wallet top-up via order #%s', 'wc-wallet'), $order->get_order_number()),
            $order_id
        );

        if ($transaction_id) {
            // Mark order as credited
            $order->update_meta_data('_wallet_credited', 'yes');
            $order->update_meta_data('_wallet_transaction_id', $transaction_id);
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(__('Wallet credited with %s', 'wc-wallet'), wc_price($amount))
            );
        }
    }

    /**
     * Refund to wallet when order is refunded
     */
    public function refund_to_wallet($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if wallet payment was used
        if ($order->get_payment_method() !== 'wallet') {
            return;
        }

        // Check if already refunded
        $already_refunded = $order->get_meta('_wallet_refunded');

        if ($already_refunded === 'yes') {
            return;
        }

        $user_id = $order->get_user_id();
        $amount = $order->get_total();

        // Credit the wallet (refund)
        $transaction_id = $this->credit(
            $user_id,
            $amount,
            sprintf(__('Refund for order #%s', 'wc-wallet'), $order->get_order_number()),
            $order_id
        );

        if ($transaction_id) {
            // Mark order as refunded
            $order->update_meta_data('_wallet_refunded', 'yes');
            $order->update_meta_data('_wallet_refund_transaction_id', $transaction_id);
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(__('Amount refunded to wallet: %s', 'wc-wallet'), wc_price($amount))
            );
        }
    }

    /**
     * Refund wallet payment when order is cancelled
     */
    public function refund_wallet_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if wallet payment was used
        if ($order->get_payment_method() !== 'wallet') {
            return;
        }

        // Check if already refunded
        $already_refunded = $order->get_meta('_wallet_refunded');

        if ($already_refunded === 'yes') {
            return;
        }

        $user_id = $order->get_user_id();
        $amount = $order->get_total();

        // Credit the wallet (refund)
        $transaction_id = $this->credit(
            $user_id,
            $amount,
            sprintf(__('Refund for cancelled order #%s', 'wc-wallet'), $order->get_order_number()),
            $order_id
        );

        if ($transaction_id) {
            // Mark order as refunded
            $order->update_meta_data('_wallet_refunded', 'yes');
            $order->update_meta_data('_wallet_refund_transaction_id', $transaction_id);
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(__('Amount refunded to wallet: %s', 'wc-wallet'), wc_price($amount))
            );
        }
    }

    /**
     * Get formatted balance
     */
    public function get_formatted_balance($user_id = null) {
        $balance = $this->get_wallet_balance($user_id);
        return wc_price($balance);
    }

    /**
     * Can user afford amount
     */
    public function can_afford($user_id, $amount) {
        $balance = $this->get_wallet_balance($user_id);
        return $balance >= $amount;
    }
}
