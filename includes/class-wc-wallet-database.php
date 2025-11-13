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
        ) $charset_collate;";

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
        ) $charset_collate;";

        dbDelta($balance_sql);
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
     */
    public static function update_balance($user_id, $amount, $currency = null) {
        global $wpdb;

        if (empty($currency)) {
            $currency = get_woocommerce_currency();
        }

        $table_name = $wpdb->prefix . 'wc_wallet_balance';
        $current_balance = self::get_balance($user_id, $currency);
        $new_balance = $current_balance + $amount;

        // Ensure balance doesn't go negative
        if ($new_balance < 0) {
            return false;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d AND currency = %s",
            $user_id,
            $currency
        ));

        if ($existing) {
            $wpdb->update(
                $table_name,
                array(
                    'balance' => $new_balance,
                    'updated_date' => current_time('mysql')
                ),
                array(
                    'user_id' => $user_id,
                    'currency' => $currency
                ),
                array('%f', '%s'),
                array('%d', '%s')
            );
        } else {
            $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'balance' => $new_balance,
                    'currency' => $currency,
                    'updated_date' => current_time('mysql')
                ),
                array('%d', '%f', '%s', '%s')
            );
        }

        return $new_balance;
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
