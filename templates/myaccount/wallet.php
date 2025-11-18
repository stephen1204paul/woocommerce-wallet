<?php
/**
 * My Wallet Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$user_id = get_current_user_id();
$wallet_manager = WC_Wallet_Manager::instance();
$balance = $wallet_manager->get_wallet_balance($user_id);
$min_topup = WC_Wallet_Topup::get_min_amount();
$max_topup = WC_Wallet_Topup::get_max_amount();

// Get cashback info
$cashback_enabled = get_option('wc_wallet_cashback_enable') === 'yes';
$cashback_manager = WC_Wallet_Cashback::instance();
$cashback_percentage = $cashback_manager->get_user_cashback_percentage($user_id);

// Get transactions
$transactions = WC_Wallet_Database::get_transactions($user_id, 20);
$transaction_count = WC_Wallet_Database::get_transaction_count($user_id);
?>

<div class="wc-wallet-page">
    <h2><?php _e('My Wallet', 'wc-wallet'); ?></h2>

    <!-- Wallet Balance -->
    <div class="wallet-balance-section">
        <div class="wallet-balance-card">
            <h3><?php _e('Current Balance', 'wc-wallet'); ?></h3>
            <div class="wallet-balance-amount">
                <?php echo wc_price($balance); ?>
            </div>
            <?php if ($cashback_enabled && $cashback_percentage > 0) : ?>
                <div class="wallet-cashback-info">
                    <span class="cashback-icon">🎁</span>
                    <?php echo sprintf(__('Earn %s%% cashback on purchases', 'wc-wallet'), number_format($cashback_percentage, 2)); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top-up Form -->
    <div class="wallet-topup-section">
        <h3><?php _e('Top Up Your Wallet', 'wc-wallet'); ?></h3>
        <form method="post" class="wallet-topup-form">
            <?php wp_nonce_field('wc_wallet_topup', 'wc_wallet_topup_nonce'); ?>

            <div class="form-row">
                <label for="topup_amount">
                    <?php echo sprintf(__('Enter Amount (Min: %s, Max: %s)', 'wc-wallet'), wc_price($min_topup), wc_price($max_topup)); ?>
                </label>
                <input
                    type="number"
                    name="topup_amount"
                    id="topup_amount"
                    min="<?php echo esc_attr($min_topup); ?>"
                    max="<?php echo esc_attr($max_topup); ?>"
                    step="0.01"
                    required
                    placeholder="<?php echo esc_attr($min_topup); ?>"
                    class="input-text"
                />
            </div>

            <div class="form-row">
                <button type="submit" name="wc_wallet_topup_submit" class="button">
                    <?php _e('Proceed to Payment', 'wc-wallet'); ?>
                </button>
            </div>

            <p class="wallet-topup-note">
                <?php _e('You will be redirected to checkout to complete the payment for your wallet top-up.', 'wc-wallet'); ?>
            </p>
        </form>
    </div>

    <!-- Transaction History -->
    <div class="wallet-transactions-section">
        <h3><?php _e('Transaction History', 'wc-wallet'); ?></h3>

        <?php if (!empty($transactions)) : ?>
            <table class="wallet-transactions-table woocommerce-table shop_table">
                <thead>
                    <tr>
                        <th><?php _e('Date', 'wc-wallet'); ?></th>
                        <th><?php _e('Type', 'wc-wallet'); ?></th>
                        <th><?php _e('Details', 'wc-wallet'); ?></th>
                        <th><?php _e('Amount', 'wc-wallet'); ?></th>
                        <th><?php _e('Balance', 'wc-wallet'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction) :
                        $trans = new WC_Wallet_Transaction($transaction->id);
                    ?>
                        <tr class="transaction-row transaction-<?php echo esc_attr($trans->type); ?>">
                            <td data-title="<?php _e('Date', 'wc-wallet'); ?>">
                                <?php echo esc_html($trans->get_formatted_date()); ?>
                            </td>
                            <td data-title="<?php _e('Type', 'wc-wallet'); ?>">
                                <span class="transaction-type transaction-type-<?php echo esc_attr($trans->type); ?>">
                                    <?php echo esc_html($trans->get_type_label()); ?>
                                </span>
                            </td>
                            <td data-title="<?php _e('Details', 'wc-wallet'); ?>">
                                <?php echo esc_html($trans->details); ?>
                            </td>
                            <td data-title="<?php _e('Amount', 'wc-wallet'); ?>" class="transaction-amount">
                                <?php if ($trans->is_credit()) : ?>
                                    <span class="credit-amount">+<?php echo $trans->get_formatted_amount(); ?></span>
                                <?php else : ?>
                                    <span class="debit-amount"><?php echo $trans->get_formatted_amount(); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-title="<?php _e('Balance', 'wc-wallet'); ?>">
                                <?php echo $trans->get_formatted_balance(); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($transaction_count > 20) : ?>
                <p class="wallet-transaction-note">
                    <?php echo sprintf(__('Showing latest 20 of %d transactions', 'wc-wallet'), $transaction_count); ?>
                </p>
            <?php endif; ?>

        <?php else : ?>
            <p class="no-transactions">
                <?php _e('No transactions found.', 'wc-wallet'); ?>
            </p>
        <?php endif; ?>
    </div>
</div>
