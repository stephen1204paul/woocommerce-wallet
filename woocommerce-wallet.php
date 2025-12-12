<?php
/**
 * Plugin Name: WooCommerce Wallet
 * Plugin URI: https://example.com/woocommerce-wallet
 * Description: A comprehensive wallet system for WooCommerce allowing customers to add funds and use them for purchases.
 * Version: 1.0.2
 * Author: Stephen Paul
 * Author URI: https://example.com
 * Text Domain: wc-wallet
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('WC_WALLET_VERSION', '1.0.2');
define('WC_WALLET_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_WALLET_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_WALLET_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'wc_wallet_woocommerce_missing_notice');
    return;
}

function wc_wallet_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('WooCommerce Wallet requires WooCommerce to be installed and active.', 'wc-wallet'); ?></p>
    </div>
    <?php
}

/**
 * Main WC_Wallet Class
 */
final class WC_Wallet {

    /**
     * The single instance of the class
     */
    protected static $_instance = null;

    /**
     * Main WC_Wallet Instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * WC_Wallet Constructor
     */
    public function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required core files
     */
    public function includes() {
        // Core includes
        require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-database.php';
        require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-manager.php';
        require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-transaction.php';
        require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-topup.php';
        require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-cashback.php';
        require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-partial-payment.php';

        // Admin includes
        if (is_admin()) {
            require_once WC_WALLET_PLUGIN_DIR . 'includes/admin/class-wc-wallet-admin.php';
        }

        // Frontend includes
        if (!is_admin()) {
            require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-frontend.php';
        }
    }

    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'init'), 0);
        add_action('woocommerce_init', array($this, 'woocommerce_init'));
        add_filter('woocommerce_payment_gateways', array($this, 'add_wallet_gateway'));
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));
    }

    /**
     * Init when WordPress Initialises
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('wc-wallet', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Register wallet endpoint
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
     * Init when WooCommerce Initialises
     */
    public function woocommerce_init() {
        // Initialize wallet manager
        WC_Wallet_Manager::instance();

        // Initialize cashback
        WC_Wallet_Cashback::instance();

        // Initialize partial payment
        WC_Wallet_Partial_Payment::instance();

        // Initialize frontend
        if (!is_admin()) {
            WC_Wallet_Frontend::instance();
        }

        // Initialize admin
        if (is_admin()) {
            WC_Wallet_Admin::instance();
        }
    }

    /**
     * Add Wallet Payment Gateway
     */
    public function add_wallet_gateway($gateways) {
        // Load gateway class only when WooCommerce payment gateways are being loaded
        if (!class_exists('WC_Wallet_Gateway')) {
            require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-gateway.php';
        }
        $gateways[] = 'WC_Wallet_Gateway';
        return $gateways;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        WC_Wallet_Database::create_tables();

        // Set default options
        add_option('wc_wallet_version', WC_WALLET_VERSION);
        add_option('wc_wallet_enable', 'yes');
        add_option('wc_wallet_min_topup', 10);
        add_option('wc_wallet_max_topup', 10000);

        // Create virtual product for wallet top-ups
        $this->create_wallet_topup_product();

        // Register endpoint
        add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES);

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create hidden virtual product for wallet top-ups
     */
    private function create_wallet_topup_product() {
        // Check if product already exists
        $product_id = get_option('wc_wallet_topup_product_id');

        if ($product_id && get_post($product_id)) {
            return; // Product already exists
        }

        // Create post for virtual product
        $product_data = array(
            'post_title'   => __('Wallet Top-up', 'wc-wallet'),
            'post_content' => __('Virtual product for wallet top-up. This product should not be purchased directly.', 'wc-wallet'),
            'post_status'  => 'publish',
            'post_type'    => 'product',
            'post_author'  => 1
        );

        $product_id = wp_insert_post($product_data);

        if (is_wp_error($product_id) || !$product_id) {
            return;
        }

        // Set product as virtual and simple
        wp_set_object_terms($product_id, 'simple', 'product_type');
        update_post_meta($product_id, '_virtual', 'yes');
        update_post_meta($product_id, '_price', '0');
        update_post_meta($product_id, '_regular_price', '0');
        update_post_meta($product_id, '_sold_individually', 'yes');
        update_post_meta($product_id, '_manage_stock', 'no');
        update_post_meta($product_id, '_stock_status', 'instock');

        // Hide from catalog and search
        update_post_meta($product_id, '_visibility', 'hidden');
        update_post_meta($product_id, '_catalog_visibility', 'hidden');
        update_post_meta($product_id, '_featured', 'no');
        wp_set_object_terms($product_id, array('exclude-from-catalog', 'exclude-from-search'), 'product_visibility');

        // Mark as wallet topup product
        update_post_meta($product_id, '_is_wallet_topup_product', 'yes');

        // Save product ID in options
        update_option('wc_wallet_topup_product_id', $product_id);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

/**
 * Returns the main instance of WC_Wallet
 */
function WC_Wallet() {
    return WC_Wallet::instance();
}

// Initialize the plugin
WC_Wallet();
