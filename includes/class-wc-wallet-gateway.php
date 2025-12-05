<?php
/**
 * WooCommerce Wallet Payment Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Gateway extends WC_Payment_Gateway {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'wallet';
        $this->method_title = __('Wallet', 'wc-wallet');
        $this->method_description = __('Allow customers to pay using their wallet balance.', 'wc-wallet');
        $this->has_fields = true;
        $this->supports = array(
            'products',
            'refunds'
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->order_button_text = $this->get_option('order_button_text');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_order_status_completed', array($this, 'mark_order_as_wallet_topup'));
        add_filter('woocommerce_available_payment_gateways', array($this, 'check_wallet_availability'));
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'wc-wallet'),
                'type' => 'checkbox',
                'label' => __('Enable Wallet Payment', 'wc-wallet'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'wc-wallet'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-wallet'),
                'default' => __('Wallet', 'wc-wallet'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'wc-wallet'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'wc-wallet'),
                'default' => __('Pay using your wallet balance.', 'wc-wallet'),
                'desc_tip' => true,
            ),
            'order_button_text' => array(
                'title' => __('Order Button Text', 'wc-wallet'),
                'type' => 'text',
                'description' => __('Text for the order button when wallet is selected.', 'wc-wallet'),
                'default' => __('Pay with Wallet', 'wc-wallet'),
                'desc_tip' => true,
            ),
        );
    }

    /**
     * Payment fields
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $wallet_manager = WC_Wallet_Manager::instance();
            $balance = $wallet_manager->get_wallet_balance($user_id);
            $cart_total = WC()->cart->get_total('');

            echo '<div class="wallet-payment-info">';
            echo '<p><strong>' . esc_html__('Your Wallet Balance:', 'wc-wallet') . '</strong> ' . wp_kses_post(wc_price($balance)) . '</p>';
            echo '<p><strong>' . esc_html__('Order Total:', 'wc-wallet') . '</strong> ' . wp_kses_post(wc_price($cart_total)) . '</p>';

            if ($balance < $cart_total) {
                echo '<p class="wallet-insufficient-balance" style="color: #e2401c;">';
                echo esc_html__('Insufficient wallet balance. Please top up your wallet or choose another payment method.', 'wc-wallet');
                echo '</p>';
            } else {
                echo '<p class="wallet-sufficient-balance" style="color: #0f834d;">';
                echo sprintf(esc_html__('You have sufficient balance to complete this purchase. Remaining balance after purchase: %s', 'wc-wallet'), wp_kses_post(wc_price($balance - $cart_total)));
                echo '</p>';
            }
            echo '</div>';
        }
    }

    /**
     * Check if gateway is available
     */
    public function check_wallet_availability($available_gateways) {
        if (!is_user_logged_in()) {
            unset($available_gateways['wallet']);
            return $available_gateways;
        }

        // Hide wallet gateway if partial payment is active
        if (WC()->session) {
            $partial_amount = WC()->session->get('wc_partial_wallet_amount', 0);
            if ($partial_amount > 0) {
                unset($available_gateways['wallet']);
                return $available_gateways;
            }
        }

        // Don't allow wallet payment for wallet top-up orders
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = $cart_item['product_id'];
                if (get_post_meta($product_id, '_is_wallet_topup_product', true) === 'yes') {
                    unset($available_gateways['wallet']);
                    return $available_gateways;
                }
            }
        }

        // Check if user has sufficient balance
        $user_id = get_current_user_id();
        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        if (WC()->cart) {
            $cart_total = WC()->cart->get_total('');

            if ($balance < $cart_total) {
                // User can still see the option but will get an error on payment
                // Or you can hide it completely by uncommenting the line below
                // unset($available_gateways['wallet']);
            }
        }

        return $available_gateways;
    }

    /**
     * Process payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $user_id = $order->get_user_id();

        if (!$user_id) {
            wc_add_notice(__('You must be logged in to use wallet payment.', 'wc-wallet'), 'error');
            return array('result' => 'failure');
        }

        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);
        $order_total = $order->get_total();

        // Check if user has sufficient balance
        if ($balance < $order_total) {
            wc_add_notice(__('Insufficient wallet balance. Please top up your wallet or choose another payment method.', 'wc-wallet'), 'error');
            return array('result' => 'failure');
        }

        // Debit from wallet
        $result = $wallet_manager->debit(
            $user_id,
            $order_total,
            sprintf(__('Payment for order #%s', 'wc-wallet'), $order->get_order_number()),
            $order_id
        );

        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
            return array('result' => 'failure');
        }

        // Payment successful
        $order->payment_complete();
        $order->add_order_note(
            sprintf(__('Payment completed via wallet. Transaction ID: %s', 'wc-wallet'), $result)
        );

        // Save transaction ID
        $order->update_meta_data('_wallet_transaction_id', $result);
        $order->save();

        // Empty cart
        WC()->cart->empty_cart();

        // Return success
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order || !is_a($order, 'WC_Order')) {
            return new WP_Error('invalid_order', __('Invalid order.', 'wc-wallet'));
        }

        $user_id = $order->get_user_id();

        if (!$user_id) {
            return new WP_Error('invalid_user', __('Invalid user.', 'wc-wallet'));
        }

        // Check if already refunded to prevent duplicates
        $already_refunded = $order->get_meta('_wallet_refunded');
        if ($already_refunded === 'yes') {
            return new WP_Error('already_refunded', __('This order has already been refunded to wallet.', 'wc-wallet'));
        }

        // If amount is null, refund the full order total
        if (is_null($amount)) {
            $amount = $order->get_total();
        }

        $wallet_manager = WC_Wallet_Manager::instance();

        // Credit wallet with refund amount
        $transaction_id = $wallet_manager->credit(
            $user_id,
            $amount,
            sprintf(__('Refund for order #%s. Reason: %s', 'wc-wallet'), $order->get_order_number(), $reason),
            $order_id
        );

        if ($transaction_id) {
            // Mark as refunded to prevent duplicate refunds
            $order->update_meta_data('_wallet_refunded', 'yes');
            $order->update_meta_data('_wallet_refund_transaction_id', $transaction_id);
            $order->save();

            $order->add_order_note(
                sprintf(__('Refunded %s to wallet. Transaction ID: %s', 'wc-wallet'), wc_price($amount), $transaction_id)
            );
            return true;
        }

        return new WP_Error('refund_failed', __('Refund failed. Please try again.', 'wc-wallet'));
    }

    /**
     * Mark wallet topup order
     */
    public function mark_order_as_wallet_topup($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if order contains wallet topup product
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();

            if (get_post_meta($product_id, '_is_wallet_topup_product', true) === 'yes') {
                $order->update_meta_data('_is_wallet_topup', 'yes');
                $order->save();
                break;
            }
        }
    }
}
