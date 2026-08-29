<?php
/**
 * WooCommerce Wallet Database Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Database {

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'wc_wallet_transactions';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            balance decimal(10,2) NOT NULL,
            currency varchar(10) NOT NULL,
            details text,
            order_id bigint(20) DEFAULT NULL,
            created_date datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY order_id (order_id)
        ) ENGINE=InnoDB $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Create user wallet balance table
        $balance_table = $wpdb->prefix . 'wc_wallet_balance';

        $balance_sql = "CREATE TABLE IF NOT EXISTS $balance_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            balance decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(10) NOT NULL,
            updated_date datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_currency (user_id, currency)
        ) ENGINE=InnoDB $charset_collate;";

        dbDelta($balance_sql);
    }

    /**
     * Stack of open wallet transaction scopes: 'transaction' for a real
     * START TRANSACTION, 'savepoint' when a transaction was already open
     * (ours or the caller's). A SAVEPOINT rollback never implicitly commits
     * or discards an outer transaction the way a nested START TRANSACTION would.
     */
    private static $transaction_stack = array();

    private static function server_in_transaction() {
        global $wpdb;

        // @@in_transaction exists on MariaDB only; elsewhere the query fails and we fall back to our own stack.
        $suppress = $wpdb->suppress_errors(true);
        $value = $wpdb->get_var('SELECT @@in_transaction');
        $wpdb->suppress_errors($suppress);

        return $value !== null && (int) $value === 1;
    }

    public static function begin_transaction() {
        global $wpdb;

        $depth = count(self::$transaction_stack);

        if ($depth > 0 || self::server_in_transaction()) {
            $wpdb->query('SAVEPOINT wc_wallet_' . $depth);
            self::$transaction_stack[] = 'savepoint';
        } else {
            $wpdb->query('START TRANSACTION');
            self::$transaction_stack[] = 'transaction';
        }
    }

    public static function commit_transaction() {
        global $wpdb;

        $scope = array_pop(self::$transaction_stack);

        if ($scope === 'savepoint') {
            $wpdb->query('RELEASE SAVEPOINT wc_wallet_' . count(self::$transaction_stack));
        } elseif ($scope === 'transaction') {
            $wpdb->query('COMMIT');
        }
    }

    public static function rollback_transaction() {
        global $wpdb;

        $scope = array_pop(self::$transaction_stack);

        if ($scope === 'savepoint') {
            $wpdb->query('ROLLBACK TO SAVEPOINT wc_wallet_' . count(self::$transaction_stack));
        } elseif ($scope === 'transaction') {
            $wpdb->query('ROLLBACK');
        }
    }

    /**
     * Get user wallet balance
     */
    public static function get_balance($user_id, $currency = null) {
        global $wpdb;

        if (empty($currency)) {
            $currency = get_woocommerce_currency();
        }

        $table_name = $wpdb->prefix . 'wc_wallet_balance';

        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM $table_name WHERE user_id = %d AND currency = %s",
            $user_id,
            $currency
        ));

        return $balance !== null ? floatval($balance) : 0.00;
    }

    /**
     * Update user wallet balance
     *
     * The guard `balance >= %f` is enforced in the same UPDATE statement as the
     * deduction so concurrent debits cannot both pass a stale sufficiency check.
     * Returns false when a debit would take the balance below zero.
     */
    public static function update_balance($user_id, $amount, $currency = null) {
        global $wpdb;

        if (empty($currency)) {
            $currency = get_woocommerce_currency();
        }

        $table_name = $wpdb->prefix . 'wc_wallet_balance';
        $now = current_time('mysql');

        // Ensure a row exists; UNIQUE KEY user_currency makes this idempotent.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table_name (user_id, balance, currency, updated_date) VALUES (%d, 0.00, %s, %s)",
            $user_id,
            $currency,
            $now
        ));

        if ($amount < 0) {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE $table_name SET balance = balance - %f, updated_date = %s
                 WHERE user_id = %d AND currency = %s AND balance >= %f",
                abs($amount),
                $now,
                $user_id,
                $currency,
                abs($amount)
            ));
        } else {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE $table_name SET balance = balance + %f, updated_date = %s
                 WHERE user_id = %d AND currency = %s",
                $amount,
                $now,
                $user_id,
                $currency
            ));
        }

        if ($affected === false || $affected === 0) {
            return false;
        }

        return self::get_balance($user_id, $currency);
    }

    /**
     * Add transaction record
     */
    public static function add_transaction($user_id, $type, $amount, $details = '', $order_id = null) {
        global $wpdb;

        $currency = get_woocommerce_currency();
        $balance = self::get_balance($user_id, $currency);

        $table_name = $wpdb->prefix . 'wc_wallet_transactions';

        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'type' => $type,
                'amount' => $amount,
                'balance' => $balance,
                'currency' => $currency,
                'details' => $details,
                'order_id' => $order_id,
                'created_date' => current_time('mysql')
            ),
            array('%d', '%s', '%f', '%f', '%s', '%s', '%d', '%s')
        );

        return $wpdb->insert_id;
    }

    /**
     * Get user transactions
     */
    public static function get_transactions($user_id, $limit = 20, $offset = 0) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_wallet_transactions';

        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_date DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));

        return $transactions;
    }

    /**
     * Get transaction by ID
     */
    public static function get_transaction($transaction_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_wallet_transactions';

        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $transaction_id
        ));

        return $transaction;
    }

    /**
     * Get total transaction count for user
     */
    public static function get_transaction_count($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_wallet_transactions';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
            $user_id
        ));

        return intval($count);
    }
}
