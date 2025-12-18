<?php
/**
 * Partial Wallet Payment Section Template
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/partial-payment-section.php
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wc-wallet-partial-payment-section">
    <h3 class="wallet-partial-header">
        <span><?php esc_html_e('Use Wallet Balance', 'wc-wallet'); ?></span>
        <span class="wallet-balance-display"><?php echo wp_kses_post(wc_price($balance)); ?></span>
    </h3>

    <div class="wallet-amount-input-group">
        <label for="wallet_partial_amount">
            <?php esc_html_e('Amount to use from wallet:', 'wc-wallet'); ?>
        </label>
        <input
            type="number"
            id="wallet_partial_amount"
            name="wallet_partial_amount"
            class="wallet-amount-input"
            step="0.01"
            min="0"
            max="<?php echo esc_attr($max_amount); ?>"
            value="<?php echo esc_attr($current_amount); ?>"
            placeholder="0.00"
        >
        <p class="wallet-error-message"></p>
    </div>

    <!-- <div class="wallet-breakdown">
        <div class="wallet-breakdown-row">
            <span><?php esc_html_e('Order Total:', 'wc-wallet'); ?></span>
            <strong class="wallet-cart-total"><?php echo wp_kses_post(wc_price($cart_total)); ?></strong>
        </div>
        <div class="wallet-breakdown-row">
            <span><?php esc_html_e('Wallet Payment:', 'wc-wallet'); ?></span>
            <strong class="wallet-breakdown-amount"><?php echo wp_kses_post(wc_price($wallet_payment)); ?></strong>
        </div>
        <div class="wallet-breakdown-row wallet-breakdown-total">
            <span><?php esc_html_e('Remaining to Pay:', 'wc-wallet'); ?></span>
            <strong class="wallet-remaining-amount"><?php echo wp_kses_post(wc_price($remaining)); ?></strong>
        </div>
    </div>

    <?php if ($current_amount > 0) : ?>
        <div class="wallet-clear-section">
            <button type="button" id="wallet_partial_clear" class="button">
                <?php esc_html_e('Clear Wallet Payment', 'wc-wallet'); ?>
            </button>
        </div>
    <?php endif; ?> -->
</div>
