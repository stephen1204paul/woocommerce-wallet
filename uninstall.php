<?php
/**
 * Uninstall WooCommerce Wallet
 *
 * Removes all wallet data, tables, and options when plugin is deleted
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove wallet tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_wallet_transactions");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_wallet_balance");

// Remove plugin options
delete_option('wc_wallet_version');
delete_option('wc_wallet_enable');
delete_option('wc_wallet_min_topup');
delete_option('wc_wallet_max_topup');
delete_option('wc_wallet_topup_product_id');

// Remove wallet topup product if exists
$topup_product_id = get_option('wc_wallet_topup_product_id');
if ($topup_product_id) {
    wp_delete_post($topup_product_id, true);
}

// Remove all wallet-related post meta
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wallet_%' OR meta_key = '_is_wallet_topup'");

// Clear any cached data
wp_cache_flush();
