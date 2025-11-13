<?php
/**
 * WooCommerce Wallet Top-up Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Topup {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_before_my_account', array($this, 'add_topup_form'));
        add_action('template_redirect', array($this, 'process_topup'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_wallet_menu_item'));
        add_action('woocommerce_account_wallet_endpoint', array($this, 'wallet_endpoint_content'));
    }

    /**
     * Add wallet menu item to My Account
     */
    public function add_wallet_menu_item($items) {
        $new_items = array();

        foreach ($items as $key => $value) {
            $new_items[$key] = $value;

            // Add wallet after dashboard
            if ($key === 'dashboard') {
                $new_items['wallet'] = __('My Wallet', 'wc-wallet');
            }
        }

        return $new_items;
    }

    /**
     * Add wallet endpoint content
     */
    public function wallet_endpoint_content() {
        wc_get_template('myaccount/wallet.php', array(), '', WC_WALLET_PLUGIN_DIR . 'templates/');
    }

    /**
     * Add top-up form
     */
    public function add_topup_form() {
        if (!is_user_logged_in()) {
            return;
        }

        // This is loaded via the wallet endpoint now
    }

    /**
     * Process wallet top-up
     */
    public function process_topup() {
        if (!isset($_POST['wc_wallet_topup_submit']) || !isset($_POST['wc_wallet_topup_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['wc_wallet_topup_nonce'], 'wc_wallet_topup')) {
            wc_add_notice(__('Security check failed. Please try again.', 'wc-wallet'), 'error');
            return;
        }

        if (!is_user_logged_in()) {
            wc_add_notice(__('Please login to top up your wallet.', 'wc-wallet'), 'error');
            return;
        }

        $amount = isset($_POST['topup_amount']) ? floatval($_POST['topup_amount']) : 0;

        // Validate amount
        $min_amount = get_option('wc_wallet_min_topup', 10);
        $max_amount = get_option('wc_wallet_max_topup', 10000);

        if ($amount < $min_amount) {
            wc_add_notice(sprintf(__('Minimum top-up amount is %s', 'wc-wallet'), wc_price($min_amount)), 'error');
            return;
        }

        if ($amount > $max_amount) {
            wc_add_notice(sprintf(__('Maximum top-up amount is %s', 'wc-wallet'), wc_price($max_amount)), 'error');
            return;
        }

        // Create a virtual product for wallet top-up
        $product = $this->create_topup_product($amount);

        if (!$product) {
            wc_add_notice(__('Failed to create top-up product. Please try again.', 'wc-wallet'), 'error');
            return;
        }

        // Clear cart
        WC()->cart->empty_cart();

        // Add to cart
        WC()->cart->add_to_cart($product->get_id(), 1);

        // Redirect to checkout
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Create virtual product for wallet top-up
     */
    private function create_topup_product($amount) {
        // Check if wallet topup product exists
        $product_id = get_option('wc_wallet_topup_product_id');

        if ($product_id) {
            $product = wc_get_product($product_id);

            if ($product && $product->exists()) {
                // Update price
                $product->set_price($amount);
                $product->set_regular_price($amount);
                $product->set_name(sprintf(__('Wallet Top-up (%s)', 'wc-wallet'), wc_price($amount)));
                $product->save();

                return $product;
            }
        }

        // Create new product
        $product = new WC_Product_Virtual();
        $product->set_name(sprintf(__('Wallet Top-up (%s)', 'wc-wallet'), wc_price($amount)));
        $product->set_status('private');
        $product->set_catalog_visibility('hidden');
        $product->set_price($amount);
        $product->set_regular_price($amount);
        $product->set_sold_individually(true);
        $product->set_virtual(true);

        $product_id = $product->save();

        if ($product_id) {
            update_option('wc_wallet_topup_product_id', $product_id);

            // Mark product as wallet topup
            update_post_meta($product_id, '_is_wallet_topup_product', 'yes');

            return $product;
        }

        return false;
    }

    /**
     * Get minimum top-up amount
     */
    public static function get_min_amount() {
        return get_option('wc_wallet_min_topup', 10);
    }

    /**
     * Get maximum top-up amount
     */
    public static function get_max_amount() {
        return get_option('wc_wallet_max_topup', 10000);
    }
}

// Initialize
new WC_Wallet_Topup();
