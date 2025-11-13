<?php
/**
 * Plugin Name: WooCommerce Wallet
 * Plugin URI: https://example.com/woocommerce-wallet
 * Description: A comprehensive wallet system for WooCommerce allowing customers to add funds and use them for purchases.
 * Version: 1.0.0
 * Author: Your Name
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
define('WC_WALLET_VERSION', '1.0.0');
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
        require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-gateway.php';

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
    }

    /**
     * Init when WordPress Initialises
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('wc-wallet', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Init when WooCommerce Initialises
     */
    public function woocommerce_init() {
        // Initialize wallet manager
        WC_Wallet_Manager::instance();

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

        // Flush rewrite rules
        flush_rewrite_rules();
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
