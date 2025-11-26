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
    }
}
