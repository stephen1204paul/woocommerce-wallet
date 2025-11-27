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

        add_submenu_page(
            'woocommerce',
            __('Wallet Transactions', 'wc-wallet'),
            __('Wallet Transactions', 'wc-wallet'),
            'manage_woocommerce',
            'wc-wallet-transactions',
            array($this, 'transactions_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wc_wallet_settings', 'wc_wallet_enable');
        register_setting('wc_wallet_settings', 'wc_wallet_min_topup');
        register_setting('wc_wallet_settings', 'wc_wallet_max_topup');

        // Cashback settings
        register_setting('wc_wallet_settings', 'wc_wallet_cashback_enable');
        register_setting('wc_wallet_settings', 'wc_wallet_cashback_max_amount');
        register_setting('wc_wallet_settings', 'wc_wallet_cashback_include_shipping');
        register_setting('wc_wallet_settings', 'wc_wallet_cashback_include_taxes');

        // Register cashback percentage for each role
        $roles = WC_Wallet_Cashback::get_user_roles();
        foreach ($roles as $role_key => $role_name) {
            register_setting('wc_wallet_settings', 'wc_wallet_cashback_' . $role_key);
        }
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

                <h2><?php _e('Cashback Settings', 'wc-wallet'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wc_wallet_cashback_enable"><?php _e('Enable Cashback', 'wc-wallet'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" name="wc_wallet_cashback_enable" id="wc_wallet_cashback_enable" value="yes" <?php checked(get_option('wc_wallet_cashback_enable'), 'yes'); ?> />
                            <p class="description"><?php _e('Enable cashback rewards for customer purchases.', 'wc-wallet'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wc_wallet_cashback_max_amount"><?php _e('Maximum Cashback Per Order', 'wc-wallet'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="wc_wallet_cashback_max_amount" id="wc_wallet_cashback_max_amount" value="<?php echo esc_attr(get_option('wc_wallet_cashback_max_amount', 0)); ?>" min="0" step="0.01" class="regular-text" />
                            <p class="description"><?php _e('Maximum cashback amount per order (0 for unlimited).', 'wc-wallet'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wc_wallet_cashback_include_shipping"><?php _e('Include Shipping in Cashback', 'wc-wallet'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" name="wc_wallet_cashback_include_shipping" id="wc_wallet_cashback_include_shipping" value="yes" <?php checked(get_option('wc_wallet_cashback_include_shipping'), 'yes'); ?> />
                            <p class="description"><?php _e('Include shipping costs when calculating cashback.', 'wc-wallet'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wc_wallet_cashback_include_taxes"><?php _e('Include Taxes in Cashback', 'wc-wallet'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" name="wc_wallet_cashback_include_taxes" id="wc_wallet_cashback_include_taxes" value="yes" <?php checked(get_option('wc_wallet_cashback_include_taxes'), 'yes'); ?> />
                            <p class="description"><?php _e('Include taxes when calculating cashback.', 'wc-wallet'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php _e('Cashback Percentage by User Role', 'wc-wallet'); ?></h3>
                <p><?php _e('Set the cashback percentage for each user role. If a user has multiple roles, the highest percentage will be used.', 'wc-wallet'); ?></p>
                <table class="form-table">
                    <?php
                    $roles = WC_Wallet_Cashback::get_user_roles();
                    foreach ($roles as $role_key => $role_name) :
                        $option_name = 'wc_wallet_cashback_' . $role_key;
                        $percentage = get_option($option_name, 0);
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr($option_name); ?>">
                                <?php echo esc_html($role_name); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="<?php echo esc_attr($option_name); ?>"
                                id="<?php echo esc_attr($option_name); ?>"
                                value="<?php echo esc_attr($percentage); ?>"
                                min="0"
                                max="100"
                                step="0.01"
                                class="small-text"
                            /> %
                            <p class="description">
                                <?php echo sprintf(__('Cashback percentage for %s role', 'wc-wallet'), strtolower($role_name)); ?>
                            </p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
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
                    <strong><?php echo wp_kses_post(wc_price($balance)); ?></strong>
                </td>
            </tr>

            <tr>
                <th><label for="wallet_adjustment_amount"><?php _e('Adjust Wallet Balance', 'wc-wallet'); ?></label></th>
                <td>
                    <?php wp_nonce_field('wallet_adjustment_' . $user->ID, 'wallet_adjustment_nonce'); ?>
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

        // CSRF protection: Verify nonce
        if (!isset($_POST['wallet_adjustment_nonce']) || !wp_verify_nonce($_POST['wallet_adjustment_nonce'], 'wallet_adjustment_' . $user_id)) {
            return;
        }

        if (!isset($_POST['wallet_adjustment_amount']) || $_POST['wallet_adjustment_amount'] === '') {
            return;
        }

        // Sanitize and validate input
        $amount = floatval(sanitize_text_field($_POST['wallet_adjustment_amount']));

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
     * Transactions page content
     */
    public function transactions_page() {
        global $wpdb;

        // Get filter parameters
        $filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
        $filter_date_from = isset($_GET['filter_date_from']) ? sanitize_text_field($_GET['filter_date_from']) : '';
        $filter_date_to = isset($_GET['filter_date_to']) ? sanitize_text_field($_GET['filter_date_to']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Build query
        $transaction_table = $wpdb->prefix . 'wc_wallet_transactions';
        $where_clauses = array('1=1');
        $query_params = array();

        if ($filter_user > 0) {
            $where_clauses[] = 'user_id = %d';
            $query_params[] = $filter_user;
        }

        if (!empty($filter_type)) {
            $where_clauses[] = 'type = %s';
            $query_params[] = $filter_type;
        }

        if (!empty($filter_date_from)) {
            $where_clauses[] = 'DATE(created_date) >= %s';
            $query_params[] = $filter_date_from;
        }

        if (!empty($filter_date_to)) {
            $where_clauses[] = 'DATE(created_date) <= %s';
            $query_params[] = $filter_date_to;
        }

        if (!empty($search)) {
            $where_clauses[] = '(details LIKE %s OR order_id = %d)';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = intval($search);
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Get total count for pagination
        if (!empty($query_params)) {
            $total_query = "SELECT COUNT(*) FROM $transaction_table WHERE $where_sql";
            $total_items = $wpdb->get_var($wpdb->prepare($total_query, $query_params));
        } else {
            $total_query = "SELECT COUNT(*) FROM $transaction_table WHERE $where_sql";
            $total_items = $wpdb->get_var($total_query);
        }

        $total_pages = ceil($total_items / $per_page);

        // Get transactions
        if (!empty($query_params)) {
            $transactions_query = "SELECT * FROM $transaction_table WHERE $where_sql ORDER BY created_date DESC LIMIT %d OFFSET %d";
            $query_params[] = $per_page;
            $query_params[] = $offset;
            $transactions = $wpdb->get_results($wpdb->prepare($transactions_query, $query_params));
        } else {
            $transactions_query = "SELECT * FROM $transaction_table WHERE $where_sql ORDER BY created_date DESC LIMIT %d OFFSET %d";
            $transactions = $wpdb->get_results($wpdb->prepare($transactions_query, $per_page, $offset));
        }

        // Get statistics
        $stats_today = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_count,
                SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as total_credits,
                SUM(CASE WHEN type = 'debit' THEN ABS(amount) ELSE 0 END) as total_debits
            FROM $transaction_table
            WHERE DATE(created_date) = CURDATE()"
        );

        // Get all users with wallets for filter dropdown
        $users_with_wallets = $wpdb->get_results(
            "SELECT DISTINCT user_id FROM $transaction_table ORDER BY user_id ASC"
        );

        ?>
        <div class="wrap wc-wallet-transactions">
            <h1><?php _e('Wallet Transactions', 'wc-wallet'); ?></h1>

            <!-- Statistics Cards -->
            <div class="wallet-stats-cards" style="display: flex; gap: 20px; margin: 20px 0;">
                <div style="background: #fff; padding: 20px; border-left: 4px solid #ff8c00; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php _e('Today\'s Transactions', 'wc-wallet'); ?></h3>
                    <p style="margin: 0; font-size: 28px; font-weight: bold;"><?php echo number_format_i18n($stats_today->total_count ? $stats_today->total_count : 0); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border-left: 4px solid #28a745; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php _e('Today\'s Credits', 'wc-wallet'); ?></h3>
                    <p style="margin: 0; font-size: 28px; font-weight: bold;"><?php echo wp_kses_post(wc_price($stats_today->total_credits ? $stats_today->total_credits : 0)); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border-left: 4px solid #dc3545; box-shadow: 0 1px 3px rgba(0,0,0,0.1); flex: 1;">
                    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php _e('Today\'s Debits', 'wc-wallet'); ?></h3>
                    <p style="margin: 0; font-size: 28px; font-weight: bold;"><?php echo wp_kses_post(wc_price($stats_today->total_debits ? $stats_today->total_debits : 0)); ?></p>
                </div>
            </div>

            <!-- Filters -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" style="display: inline-block;">
                        <input type="hidden" name="page" value="wc-wallet-transactions" />

                        <select name="filter_user" id="filter_user" style="min-width: 200px; vertical-align: middle;">
                            <option value="0"><?php _e('All Users', 'wc-wallet'); ?></option>
                            <?php foreach ($users_with_wallets as $wallet_user) :
                                $user_data = get_userdata($wallet_user->user_id);
                                if ($user_data) :
                            ?>
                                <option value="<?php echo esc_attr($wallet_user->user_id); ?>" <?php selected($filter_user, $wallet_user->user_id); ?>>
                                    <?php echo esc_html($user_data->display_name . ' (' . $user_data->user_email . ')'); ?>
                                </option>
                            <?php endif; endforeach; ?>
                        </select>

                        <select name="filter_type" id="filter_type" style="vertical-align: middle;">
                            <option value=""><?php _e('All Types', 'wc-wallet'); ?></option>
                            <option value="credit" <?php selected($filter_type, 'credit'); ?>><?php _e('Credit', 'wc-wallet'); ?></option>
                            <option value="debit" <?php selected($filter_type, 'debit'); ?>><?php _e('Debit', 'wc-wallet'); ?></option>
                        </select>

                        <input type="date" name="filter_date_from" id="filter_date_from" value="<?php echo esc_attr($filter_date_from); ?>" placeholder="<?php esc_attr_e('From Date', 'wc-wallet'); ?>" style="vertical-align: middle;" />

                        <input type="date" name="filter_date_to" id="filter_date_to" value="<?php echo esc_attr($filter_date_to); ?>" placeholder="<?php esc_attr_e('To Date', 'wc-wallet'); ?>" style="vertical-align: middle;" />

                        <input type="text" name="s" id="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Order ID or Details', 'wc-wallet'); ?>" style="vertical-align: middle;" />

                        <button type="submit" class="button" style="vertical-align: middle;"><?php _e('Filter', 'wc-wallet'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-wallet-transactions')); ?>" class="button" style="vertical-align: middle;"><?php _e('Reset', 'wc-wallet'); ?></a>
                    </form>
                </div>
                <br class="clear">
            </div>

            <!-- Transactions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'wc-wallet'); ?></th>
                        <th><?php _e('Date', 'wc-wallet'); ?></th>
                        <th><?php _e('User', 'wc-wallet'); ?></th>
                        <th><?php _e('Type', 'wc-wallet'); ?></th>
                        <th><?php _e('Amount', 'wc-wallet'); ?></th>
                        <th><?php _e('Balance After', 'wc-wallet'); ?></th>
                        <th><?php _e('Details', 'wc-wallet'); ?></th>
                        <th><?php _e('Order', 'wc-wallet'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)) : ?>
                        <?php foreach ($transactions as $transaction) :
                            $user = get_userdata($transaction->user_id);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($transaction->id); ?></strong></td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_date))); ?></td>
                            <td>
                                <?php if ($user) : ?>
                                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $transaction->user_id)); ?>">
                                        <?php echo esc_html($user->display_name); ?>
                                    </a><br>
                                    <small><?php echo esc_html($user->user_email); ?></small>
                                <?php else : ?>
                                    <span style="color: #999;"><?php _e('User not found', 'wc-wallet'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="transaction-type transaction-type-<?php echo esc_attr($transaction->type); ?>" style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; <?php echo $transaction->type === 'credit' ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;'; ?>">
                                    <?php echo esc_html(ucfirst($transaction->type)); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="<?php echo $transaction->type === 'credit' ? 'color: #28a745;' : 'color: #dc3545;'; ?>">
                                    <?php echo wp_kses_post(wc_price(abs($transaction->amount))); ?>
                                </strong>
                            </td>
                            <td><?php echo wp_kses_post(wc_price($transaction->balance)); ?></td>
                            <td><?php echo esc_html($transaction->details); ?></td>
                            <td>
                                <?php if ($transaction->order_id > 0) : ?>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $transaction->order_id . '&action=edit')); ?>">
                                        #<?php echo esc_html($transaction->order_id); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                                <?php _e('No transactions found.', 'wc-wallet'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf(_n('%s item', '%s items', $total_items, 'wc-wallet'), number_format_i18n($total_items)); ?>
                        </span>
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;', 'wc-wallet'),
                            'next_text' => __('&raquo;', 'wc-wallet'),
                            'total' => $total_pages,
                            'current' => $current_page,
                            'type' => 'plain'
                        ));
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'woocommerce_page_wc-wallet-settings' || $hook === 'woocommerce_page_wc-wallet-transactions' || $hook === 'profile.php' || $hook === 'user-edit.php') {
            wp_enqueue_style('wc-wallet-admin', WC_WALLET_PLUGIN_URL . 'assets/css/admin.css', array(), WC_WALLET_VERSION);
        }
    }
}
