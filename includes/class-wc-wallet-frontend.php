<?php
/**
 * WooCommerce Wallet Frontend
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Frontend {

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
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        if (is_account_page() || is_checkout()) {
            wp_enqueue_style('wc-wallet-styles', WC_WALLET_PLUGIN_URL . 'assets/css/wallet.css', array(), WC_WALLET_VERSION);
            wp_enqueue_script('wc-wallet-scripts', WC_WALLET_PLUGIN_URL . 'assets/js/wallet.js', array('jquery'), WC_WALLET_VERSION, true);

            wp_localize_script('wc-wallet-scripts', 'wc_wallet_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_wallet_nonce'),
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'min_topup' => WC_Wallet_Topup::get_min_amount(),
                'max_topup' => WC_Wallet_Topup::get_max_amount(),
            ));
        }

        // Enqueue partial payment assets on cart and checkout pages
        if ((is_cart() || is_checkout()) && !is_wc_endpoint_url('order-received')) {
            wp_enqueue_style(
                'wc-wallet-partial-payment',
                WC_WALLET_PLUGIN_URL . 'assets/css/partial-payment.css',
                array(),
                WC_WALLET_VERSION
            );

            wp_enqueue_script(
                'wc-wallet-partial-payment',
                WC_WALLET_PLUGIN_URL . 'assets/js/partial-payment.js',
                array('jquery', 'wc-checkout'),
                WC_WALLET_VERSION,
                true
            );

            // Get user balance for JS
            $user_balance = 0;
            $cart_total = 0;

            if (is_user_logged_in()) {
                $wallet_manager = WC_Wallet_Manager::instance();
                $user_balance = $wallet_manager->get_wallet_balance(get_current_user_id());
            }

            if (WC()->cart) {
                $cart_total = WC()->cart->get_total('edit');
            }

            wp_localize_script('wc-wallet-partial-payment', 'wc_wallet_partial_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_wallet_partial_nonce'),
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'currency_format' => str_replace(array('%1$s', '%2$s'), array('%s', '%v'), get_woocommerce_price_format()),
                'decimals' => wc_get_price_decimals(),
                'decimal_separator' => wc_get_price_decimal_separator(),
                'thousand_separator' => wc_get_price_thousand_separator(),
                'user_balance' => $user_balance,
                'cart_total' => $cart_total,
                'i18n' => array(
                    'amount_too_high' => __('Amount cannot exceed cart total or wallet balance', 'wc-wallet'),
                    'amount_invalid' => __('Please enter a valid amount', 'wc-wallet'),
                    'processing' => __('Processing...', 'wc-wallet'),
                )
            ));
        }
    }
}
