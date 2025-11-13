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
        add_action('init', array($this, 'register_endpoint'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));
        add_filter('woocommerce_checkout_fields', array($this, 'add_wallet_balance_notice'));
    }

    /**
     * Register wallet endpoint
     */
    public function register_endpoint() {
        add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES);
    }

    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars['wallet'] = 'wallet';
        return $vars;
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

    /**
     * Add wallet balance notice on checkout
     */
    public function add_wallet_balance_notice($fields) {
        if (is_user_logged_in()) {
            $wallet_manager = WC_Wallet_Manager::instance();
            $balance = $wallet_manager->get_wallet_balance();

            if ($balance > 0) {
                wc_add_notice(
                    sprintf(__('Your wallet balance: %s', 'wc-wallet'), wc_price($balance)),
                    'notice'
                );
            }
        }

        return $fields;
    }
}
