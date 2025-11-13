<?php
/**
 * WooCommerce Wallet Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Admin {

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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('woocommerce_get_settings_pages', array($this, 'add_settings_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('show_user_profile', array($this, 'add_wallet_fields'));
        add_action('edit_user_profile', array($this, 'add_wallet_fields'));
        add_action('personal_options_update', array($this, 'save_wallet_fields'));
        add_action('edit_user_profile_update', array($this, 'save_wallet_fields'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Wallet Settings', 'wc-wallet'),
            __('Wallet', 'wc-wallet'),
            'manage_woocommerce',
            'wc-wallet-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wc_wallet_settings', 'wc_wallet_enable');
        register_setting('wc_wallet_settings', 'wc_wallet_min_topup');
        register_setting('wc_wallet_settings', 'wc_wallet_max_topup');
    }

    /**
     * Settings page content
     */
    public function settings_page() {
        ?>
        <div class="wrap wc-wallet-settings">
            <h1><?php _e('WooCommerce Wallet Settings', 'wc-wallet'); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('wc_wallet_settings');
                do_settings_sections('wc_wallet_settings');
                ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wc_wallet_enable"><?php _e('Enable Wallet', 'wc-wallet'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" name="wc_wallet_enable" id="wc_wallet_enable" value="yes" <?php checked(get_option('wc_wallet_enable'), 'yes'); ?> />
                            <p class="description"><?php _e('Enable or disable the wallet system.', 'wc-wallet'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wc_wallet_min_topup"><?php _e('Minimum Top-up Amount', 'wc-wallet'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="wc_wallet_min_topup" id="wc_wallet_min_topup" value="<?php echo esc_attr(get_option('wc_wallet_min_topup', 10)); ?>" min="1" step="0.01" class="regular-text" />
                            <p class="description"><?php _e('Minimum amount users can top up to their wallet.', 'wc-wallet'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wc_wallet_max_topup"><?php _e('Maximum Top-up Amount', 'wc-wallet'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="wc_wallet_max_topup" id="wc_wallet_max_topup" value="<?php echo esc_attr(get_option('wc_wallet_max_topup', 10000)); ?>" min="1" step="0.01" class="regular-text" />
                            <p class="description"><?php _e('Maximum amount users can top up to their wallet.', 'wc-wallet'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php _e('Wallet Statistics', 'wc-wallet'); ?></h2>
            <?php $this->display_statistics(); ?>
        </div>
        <?php
    }

    /**
     * Display wallet statistics
     */
    private function display_statistics() {
        global $wpdb;

        $balance_table = $wpdb->prefix . 'wc_wallet_balance';
        $transaction_table = $wpdb->prefix . 'wc_wallet_transactions';

        // Total wallet balance
        $total_balance = $wpdb->get_var("SELECT SUM(balance) FROM $balance_table");

        // Total users with wallet
        $total_users = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $balance_table WHERE balance > 0");

        // Total transactions
        $total_transactions = $wpdb->get_var("SELECT COUNT(*) FROM $transaction_table");

        // Total credits
        $total_credits = $wpdb->get_var("SELECT SUM(amount) FROM $transaction_table WHERE type = 'credit'");

        // Total debits
        $total_debits = $wpdb->get_var("SELECT SUM(ABS(amount)) FROM $transaction_table WHERE type = 'debit'");

        ?>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Statistic', 'wc-wallet'); ?></th>
                    <th><?php _e('Value', 'wc-wallet'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php _e('Total Wallet Balance', 'wc-wallet'); ?></td>
                    <td><strong><?php echo wc_price($total_balance ? $total_balance : 0); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Users with Balance', 'wc-wallet'); ?></td>
                    <td><strong><?php echo number_format_i18n($total_users ? $total_users : 0); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Total Transactions', 'wc-wallet'); ?></td>
                    <td><strong><?php echo number_format_i18n($total_transactions ? $total_transactions : 0); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Total Credits', 'wc-wallet'); ?></td>
                    <td><strong><?php echo wc_price($total_credits ? $total_credits : 0); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Total Debits', 'wc-wallet'); ?></td>
                    <td><strong><?php echo wc_price($total_debits ? $total_debits : 0); ?></strong></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Add wallet fields to user profile
     */
    public function add_wallet_fields($user) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user->ID);
        ?>

        <h3><?php _e('Wallet Information', 'wc-wallet'); ?></h3>

        <table class="form-table">
            <tr>
                <th><label><?php _e('Current Wallet Balance', 'wc-wallet'); ?></label></th>
                <td>
                    <strong><?php echo wc_price($balance); ?></strong>
                </td>
            </tr>

            <tr>
                <th><label for="wallet_adjustment_amount"><?php _e('Adjust Wallet Balance', 'wc-wallet'); ?></label></th>
                <td>
                    <input type="number" name="wallet_adjustment_amount" id="wallet_adjustment_amount" step="0.01" class="regular-text" placeholder="0.00" />
                    <p class="description"><?php _e('Enter a positive value to credit or negative value to debit. Leave blank for no change.', 'wc-wallet'); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="wallet_adjustment_note"><?php _e('Adjustment Note', 'wc-wallet'); ?></label></th>
                <td>
                    <textarea name="wallet_adjustment_note" id="wallet_adjustment_note" rows="3" class="large-text"></textarea>
                    <p class="description"><?php _e('Reason for wallet adjustment (optional).', 'wc-wallet'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save wallet fields
     */
    public function save_wallet_fields($user_id) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!isset($_POST['wallet_adjustment_amount']) || $_POST['wallet_adjustment_amount'] === '') {
            return;
        }

        $amount = floatval($_POST['wallet_adjustment_amount']);

        if ($amount == 0) {
            return;
        }

        $note = isset($_POST['wallet_adjustment_note']) ? sanitize_textarea_field($_POST['wallet_adjustment_note']) : '';

        if (empty($note)) {
            $note = __('Manual adjustment by admin', 'wc-wallet');
        }

        $wallet_manager = WC_Wallet_Manager::instance();

        if ($amount > 0) {
            $wallet_manager->credit($user_id, $amount, $note);
        } else {
            $wallet_manager->debit($user_id, abs($amount), $note);
        }
    }

    /**
     * Add settings page
     */
    public function add_settings_page($settings) {
        // This can be extended to add WooCommerce settings tab
        return $settings;
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'woocommerce_page_wc-wallet-settings' || $hook === 'profile.php' || $hook === 'user-edit.php') {
            wp_enqueue_style('wc-wallet-admin', WC_WALLET_PLUGIN_URL . 'assets/css/admin.css', array(), WC_WALLET_VERSION);
        }
    }
}
