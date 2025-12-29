<?php
/**
 * WooCommerce Wallet Partial Payment Handler
 *
 * Handles partial wallet payments where customers can use a custom amount from their wallet
 * combined with another payment gateway.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Partial_Payment {

    /**
     * The single instance of the class
     */
    protected static $_instance = null;

    /**
     * Session key for storing partial wallet amount
     */
    const SESSION_KEY = 'wc_partial_wallet_amount';

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
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Frontend UI
        add_action('woocommerce_review_order_before_payment', array($this, 'render_partial_payment_section'));
        add_action('woocommerce_cart_totals_after_order_total', array($this, 'render_partial_payment_section'));

        // AJAX handlers
        add_action('wp_ajax_wc_wallet_set_partial_amount', array($this, 'ajax_set_partial_amount'));
        add_action('wp_ajax_nopriv_wc_wallet_set_partial_amount', array($this, 'ajax_set_partial_amount'));
        add_action('wp_ajax_wc_wallet_get_updated_totals', array($this, 'ajax_get_updated_totals'));
        add_action('wp_ajax_nopriv_wc_wallet_get_updated_totals', array($this, 'ajax_get_updated_totals'));

        // Cart/Checkout modifications
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_wallet_fee_line'), 10, 1);
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_payment_gateways'), 20);

        // Order processing
        add_action('woocommerce_checkout_order_processed', array($this, 'process_partial_payment'), 5, 1);
        add_action('woocommerce_order_status_failed', array($this, 'rollback_wallet_payment'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array($this, 'rollback_wallet_payment'), 10, 1);

        // Session management
        add_action('woocommerce_cart_emptied', array($this, 'clear_partial_amount'));
        add_action('woocommerce_cart_updated', array($this, 'handle_cart_update'));

        // Display wallet breakdown on emails, invoices, and receipts
        add_action('woocommerce_email_order_meta', array($this, 'display_wallet_breakdown_on_email'), 10, 3);
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_wallet_breakdown_on_order_details'), 10, 1);
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_wallet_breakdown_admin'), 10, 1);

        // Register shortcodes for Booster.io and other invoice plugins
        add_shortcode('wcj_wallet_original_total', array($this, 'shortcode_wallet_original_total'));
        add_shortcode('wcj_wallet_payment_amount', array($this, 'shortcode_wallet_payment_amount'));
        add_shortcode('wcj_wallet_breakdown_table', array($this, 'shortcode_wallet_breakdown_table'));
        add_shortcode('wcj_wallet_debug', array($this, 'shortcode_wallet_debug'));
        add_shortcode('wcj_order_wallet_payment', array($this, 'shortcode_wallet_payment_amount')); // Alias for compatibility
        add_shortcode('wcj_wallet_payment_row', array($this, 'shortcode_wallet_payment_row'));

        // Hook into Booster.io's shortcode processing to get order ID
        add_filter('wcj_shortcodes_atts', array($this, 'store_booster_order_id'), 10, 2);

        // Format wallet amounts in final output using regex replacement
        add_filter('wcj_get_invoice_html', array($this, 'format_wallet_in_invoice_html'), 999, 2);
        add_filter('the_content', array($this, 'format_wallet_in_content'), 999);
    }

    /**
     * Set partial wallet amount in session
     *
     * @param float $amount Amount to set
     * @return bool Success
     */
    public function set_partial_amount($amount) {
        $amount = floatval($amount);

        if ($amount < 0) {
            $amount = 0;
        }

        if (WC()->session) {
            WC()->session->set(self::SESSION_KEY, $amount);
            return true;
        }

        return false;
    }

    /**
     * Get partial wallet amount from session
     *
     * @return float Amount
     */
    public function get_partial_amount() {
        if (WC()->session) {
            return floatval(WC()->session->get(self::SESSION_KEY, 0));
        }

        return 0;
    }

    /**
     * Clear partial wallet amount from session
     */
    public function clear_partial_amount() {
        if (WC()->session) {
            WC()->session->set(self::SESSION_KEY, null);
        }
    }

    /**
     * Validate wallet amount
     *
     * @param float $amount Amount to validate
     * @param int|null $user_id User ID (defaults to current user)
     * @return array ['valid' => bool, 'message' => string]
     */
    public function validate_amount($amount, $user_id = null) {
        $amount = floatval($amount);

        if ($amount < 0) {
            return array(
                'valid' => false,
                'message' => __('Amount cannot be negative.', 'wc-wallet')
            );
        }

        if ($amount == 0) {
            return array('valid' => true, 'message' => '');
        }

        if ($amount < 0.01) {
            return array(
                'valid' => false,
                'message' => __('Minimum amount is 0.01', 'wc-wallet')
            );
        }

        // Get user ID
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return array(
                    'valid' => false,
                    'message' => __('You must be logged in to use wallet payment.', 'wc-wallet')
                );
            }
            $user_id = get_current_user_id();
        }

        // Check wallet balance
        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        if ($amount > $balance) {
            return array(
                'valid' => false,
                'message' => sprintf(
                    __('Amount exceeds your wallet balance of %s', 'wc-wallet'),
                    wc_price($balance)
                )
            );
        }

        // Check cart total
        if (WC()->cart) {
            $cart_total = $this->get_cart_total_before_wallet();

            if ($amount > $cart_total) {
                return array(
                    'valid' => false,
                    'message' => sprintf(
                        __('Amount exceeds cart total of %s', 'wc-wallet'),
                        wc_price($cart_total)
                    )
                );
            }
        }

        return array('valid' => true, 'message' => '');
    }

    /**
     * Get maximum wallet amount that can be used
     *
     * @param int|null $user_id User ID
     * @return float Maximum amount
     */
    public function get_max_wallet_amount($user_id = null) {
        if (!$user_id) {
            if (!is_user_logged_in()) {
                return 0;
            }
            $user_id = get_current_user_id();
        }

        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        if (WC()->cart) {
            $cart_total = $this->get_cart_total_before_wallet();
            return min($balance, $cart_total);
        }

        return $balance;
    }

    /**
     * Render partial payment section on checkout page
     */
    public function render_partial_payment_section() {
        // Only show for logged-in users
        if (!is_user_logged_in()) {
            return;
        }

        // Check if cart exists and has items
        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $user_id = get_current_user_id();
        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        // Don't show if no balance
        if ($balance <= 0) {
            return;
        }

        $cart_total = $this->get_cart_total_before_wallet();
        $max_amount = min($balance, $cart_total);
        $current_amount = $this->get_partial_amount();

        // Calculate breakdown
        $wallet_payment = $current_amount > 0 ? $current_amount : 0;
        $remaining = $cart_total - $wallet_payment;

        // Load template
        include(WC_WALLET_PLUGIN_DIR . 'templates/checkout/partial-payment-section.php');
    }

    /**
     * AJAX handler to set partial wallet amount
     */
    public function ajax_set_partial_amount() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_wallet_partial_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed.', 'wc-wallet')
            ));
        }

        // Get amount
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

        // Validate amount
        $validation = $this->validate_amount($amount);

        if (!$validation['valid']) {
            wp_send_json_error(array(
                'message' => $validation['message']
            ));
        }

        // Set amount
        $this->set_partial_amount($amount);

        // Get updated totals
        $cart_total = WC()->cart ? $this->get_cart_total_before_wallet() : 0;
        $remaining_total = $cart_total - $amount;

        wp_send_json_success(array(
            'wallet_amount' => $amount,
            'cart_total' => $cart_total,
            'remaining_total' => $remaining_total,
            'formatted_wallet' => wc_price($amount),
            'formatted_remaining' => wc_price($remaining_total),
            'formatted_cart_total' => wc_price($cart_total)
        ));
    }

    /**
     * Get cart total before wallet fee is applied
     *
     * This prevents circular dependency where cart total includes wallet fee,
     * which is based on the cart total.
     *
     * @return float Cart total before wallet deduction
     */
    public function get_cart_total_before_wallet() {
        if (!WC()->cart) {
            return 0;
        }

        // Calculate total from components, excluding wallet fee
        $total = 0;

        // Cart subtotal (includes discounts)
        $total += WC()->cart->get_subtotal() - WC()->cart->get_discount_total();

        // Add shipping
        $total += WC()->cart->get_shipping_total();

        // Add taxes
        $total += WC()->cart->get_total_tax();

        // Add fees (but exclude wallet fee)
        foreach (WC()->cart->get_fees() as $fee) {
            // Skip the wallet payment fee
            if ($fee->name === __('Wallet Payment', 'wc-wallet')) {
                continue;
            }
            $total += $fee->amount;
        }

        return max(0, $total);
    }

    /**
     * AJAX handler to get updated cart totals
     */
    public function ajax_get_updated_totals() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_wallet_partial_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed.', 'wc-wallet')
            ));
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('User not logged in.', 'wc-wallet')
            ));
        }

        $user_id = get_current_user_id();
        $wallet_manager = WC_Wallet_Manager::instance();
        $balance = $wallet_manager->get_wallet_balance($user_id);

        if (!WC()->cart) {
            wp_send_json_error(array(
                'message' => __('Cart not found.', 'wc-wallet')
            ));
        }

        // Get cart total BEFORE wallet fee is applied
        $cart_total = $this->get_cart_total_before_wallet();
        $max_amount = min($balance, $cart_total);
        $current_amount = $this->get_partial_amount();

        // Adjust current amount if it exceeds new cart total
        if ($current_amount > $cart_total) {
            $current_amount = $cart_total;
            $this->set_partial_amount($current_amount);
        }

        // Calculate breakdown
        $wallet_payment = $current_amount > 0 ? $current_amount : 0;
        $remaining = $cart_total - $wallet_payment;

        wp_send_json_success(array(
            'user_balance' => $balance,
            'cart_total' => $cart_total,
            'max_amount' => $max_amount,
            'current_amount' => $current_amount,
            'wallet_payment' => $wallet_payment,
            'remaining' => $remaining,
            'formatted_balance' => wc_price($balance),
            'formatted_cart_total' => wc_price($cart_total),
            'formatted_wallet' => wc_price($wallet_payment),
            'formatted_remaining' => wc_price($remaining)
        ));
    }

    /**
     * Add wallet payment as a negative fee for display
     *
     * @param WC_Cart $cart Cart object
     */
    public function add_wallet_fee_line($cart) {
        $wallet_amount = $this->get_partial_amount();

        if ($wallet_amount > 0) {
            $cart->add_fee(__('Wallet Payment', 'wc-wallet'), -$wallet_amount, false);
        }
    }

    /**
     * Filter payment gateways - hide wallet gateway when partial payment is active
     *
     * @param array $gateways Available gateways
     * @return array Filtered gateways
     */
    public function filter_payment_gateways($gateways) {
        $wallet_amount = $this->get_partial_amount();

        // Hide wallet gateway if partial amount is set
        if ($wallet_amount > 0 && isset($gateways['wallet'])) {
            unset($gateways['wallet']);
        }

        return $gateways;
    }

    /**
     * Process partial wallet payment before gateway payment
     *
     * @param int $order_id Order ID
     */
    public function process_partial_payment($order_id) {
        $wallet_amount = $this->get_partial_amount();

        // No partial payment to process
        if ($wallet_amount <= 0) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();

        if (!$user_id) {
            wc_add_notice(__('Invalid user for wallet payment.', 'wc-wallet'), 'error');
            return;
        }

        // Prevent concurrent processing
        $lock_key = 'wallet_partial_lock_' . $user_id;

        if (get_transient($lock_key)) {
            throw new Exception(__('Another order is being processed. Please wait.', 'wc-wallet'));
        }

        set_transient($lock_key, true, 60);

        try {
            // Re-validate balance
            $wallet_manager = WC_Wallet_Manager::instance();
            $current_balance = $wallet_manager->get_wallet_balance($user_id);

            if ($current_balance < $wallet_amount) {
                $this->clear_partial_amount();
                delete_transient($lock_key);

                throw new Exception(
                    sprintf(
                        __('Your wallet balance has changed. Current balance: %s. Please review and try again.', 'wc-wallet'),
                        wc_price($current_balance)
                    )
                );
            }

            // Get original total before any modifications
            $original_total = $order->get_total() + $wallet_amount;

            // Debit wallet
            $transaction_id = $wallet_manager->debit(
                $user_id,
                $wallet_amount,
                sprintf(
                    __('Partial payment for order #%s (%s of %s total)', 'wc-wallet'),
                    $order->get_order_number(),
                    html_entity_decode(wp_strip_all_tags(wc_price($wallet_amount))),
                    html_entity_decode(wp_strip_all_tags(wc_price($original_total)))
                ),
                $order_id
            );

            if (is_wp_error($transaction_id)) {
                delete_transient($lock_key);
                throw new Exception($transaction_id->get_error_message());
            }

            // Store order meta
            $order->update_meta_data('_partial_wallet_used', 'yes');
            $order->update_meta_data('_partial_wallet_amount', $wallet_amount);
            $order->update_meta_data('_partial_wallet_transaction_id', $transaction_id);
            $order->update_meta_data('_original_order_total', $original_total);
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(
                    __('Partial wallet payment: %s debited from wallet. Remaining %s to be paid via %s.', 'wc-wallet'),
                    wc_price($wallet_amount),
                    wc_price($order->get_total()),
                    $order->get_payment_method_title()
                )
            );

            // Clear session
            $this->clear_partial_amount();

        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Rollback wallet payment for failed/cancelled orders
     *
     * @param int $order_id Order ID
     */
    public function rollback_wallet_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if partial wallet was used
        $wallet_used = $order->get_meta('_partial_wallet_used');

        if ($wallet_used !== 'yes') {
            return;
        }

        // Check if already rolled back
        $rolled_back = $order->get_meta('_wallet_payment_rolled_back');

        if ($rolled_back === 'yes') {
            return;
        }

        // Get transaction details
        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));
        $user_id = $order->get_user_id();

        if (!$user_id || $wallet_amount <= 0) {
            return;
        }

        // Credit wallet (refund)
        $wallet_manager = WC_Wallet_Manager::instance();
        $refund_transaction = $wallet_manager->credit(
            $user_id,
            $wallet_amount,
            sprintf(
                __('Refund for failed/cancelled order #%s', 'wc-wallet'),
                $order->get_order_number()
            ),
            $order_id
        );

        if (is_wp_error($refund_transaction)) {
            $order->add_order_note(
                sprintf(
                    __('Failed to refund wallet payment: %s', 'wc-wallet'),
                    $refund_transaction->get_error_message()
                )
            );
            return;
        }

        // Mark as rolled back
        $order->update_meta_data('_wallet_payment_rolled_back', 'yes');
        $order->update_meta_data('_wallet_rollback_transaction_id', $refund_transaction);
        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Partial wallet payment of %s refunded due to order failure/cancellation.', 'wc-wallet'),
                wc_price($wallet_amount)
            )
        );
    }

    /**
     * Handle cart updates - adjust wallet amount if needed
     */
    public function handle_cart_update() {
        $wallet_amount = $this->get_partial_amount();

        if ($wallet_amount <= 0) {
            return;
        }

        if (!WC()->cart) {
            return;
        }

        $cart_total = $this->get_cart_total_before_wallet();

        // If wallet amount exceeds new cart total, adjust it
        if ($wallet_amount > $cart_total) {
            $this->set_partial_amount($cart_total);

            wc_add_notice(
                sprintf(
                    __('Cart updated. Wallet payment adjusted to %s', 'wc-wallet'),
                    wc_price($cart_total)
                ),
                'notice'
            );
        }
    }

    /**
     * Display wallet payment breakdown on order emails
     *
     * @param WC_Order $order Order object
     * @param bool $sent_to_admin Whether email is for admin
     * @param bool $plain_text Whether email is plain text
     */
    public function display_wallet_breakdown_on_email($order, $sent_to_admin = false, $plain_text = false) {
        $wallet_used = $order->get_meta('_partial_wallet_used');

        if ($wallet_used !== 'yes') {
            return;
        }

        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));
        $original_total = floatval($order->get_meta('_original_order_total'));

        if ($wallet_amount <= 0) {
            return;
        }

        if ($plain_text) {
            echo "\n" . __('PAYMENT BREAKDOWN', 'wc-wallet') . "\n";
            echo str_repeat('-', 50) . "\n";
            echo __('Original Order Total:', 'wc-wallet') . ' ' . wc_price($original_total) . "\n";
            echo __('Paid from Wallet:', 'wc-wallet') . ' -' . wc_price($wallet_amount) . "\n";
            echo __('Paid via ', 'wc-wallet') . $order->get_payment_method_title() . ': ' . wc_price($order->get_total()) . "\n";
            echo str_repeat('-', 50) . "\n\n";
        } else {
            ?>
            <div style="margin: 20px 0; padding: 15px; background-color: #f7f7f7; border: 1px solid #e0e0e0; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #333; font-size: 16px; border-bottom: 2px solid #0073aa; padding-bottom: 8px;">
                    <?php esc_html_e('Payment Breakdown', 'wc-wallet'); ?>
                </h3>
                <table cellspacing="0" cellpadding="6" style="width: 100%; border: 0;">
                    <tbody>
                        <tr>
                            <td style="text-align: left; padding: 8px; border-bottom: 1px solid #e0e0e0;">
                                <?php esc_html_e('Original Order Total:', 'wc-wallet'); ?>
                            </td>
                            <td style="text-align: right; padding: 8px; border-bottom: 1px solid #e0e0e0;">
                                <strong><?php echo wp_kses_post(wc_price($original_total)); ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: left; padding: 8px; border-bottom: 1px solid #e0e0e0; color: #0073aa;">
                                <?php esc_html_e('Paid from Wallet:', 'wc-wallet'); ?>
                            </td>
                            <td style="text-align: right; padding: 8px; border-bottom: 1px solid #e0e0e0; color: #0073aa;">
                                <strong>-<?php echo wp_kses_post(wc_price($wallet_amount)); ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: left; padding: 8px;">
                                <?php echo esc_html(sprintf(__('Paid via %s:', 'wc-wallet'), $order->get_payment_method_title())); ?>
                            </td>
                            <td style="text-align: right; padding: 8px;">
                                <strong><?php echo wp_kses_post(wc_price($order->get_total())); ?></strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php
        }
    }

    /**
     * Display wallet payment breakdown on order details page (customer view)
     *
     * @param WC_Order $order Order object
     */
    public function display_wallet_breakdown_on_order_details($order) {
        $wallet_used = $order->get_meta('_partial_wallet_used');

        if ($wallet_used !== 'yes') {
            return;
        }

        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));
        $original_total = floatval($order->get_meta('_original_order_total'));

        if ($wallet_amount <= 0) {
            return;
        }

        ?>
        <section class="woocommerce-wallet-payment-breakdown" style="margin-top: 20px; padding: 15px; background-color: #f9f9f9; border: 2px solid #0073aa; border-radius: 5px;">
            <h2 class="woocommerce-order-details__title" style="margin-top: 0; color: #0073aa;">
                <?php esc_html_e('Payment Breakdown', 'wc-wallet'); ?>
            </h2>
            <table class="woocommerce-table woocommerce-table--wallet-breakdown shop_table wallet_breakdown">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Original Order Total:', 'wc-wallet'); ?></th>
                        <td><?php echo wp_kses_post(wc_price($original_total)); ?></td>
                    </tr>
                    <tr style="color: #0073aa;">
                        <th scope="row"><?php esc_html_e('Paid from Wallet:', 'wc-wallet'); ?></th>
                        <td><strong>-<?php echo wp_kses_post(wc_price($wallet_amount)); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html(sprintf(__('Paid via %s:', 'wc-wallet'), $order->get_payment_method_title())); ?></th>
                        <td><strong><?php echo wp_kses_post(wc_price($order->get_total())); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </section>
        <?php
    }

    /**
     * Display wallet payment breakdown in admin order details
     *
     * @param WC_Order $order Order object
     */
    public function display_wallet_breakdown_admin($order) {
        $wallet_used = $order->get_meta('_partial_wallet_used');

        if ($wallet_used !== 'yes') {
            return;
        }

        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));
        $original_total = floatval($order->get_meta('_original_order_total'));
        $transaction_id = $order->get_meta('_partial_wallet_transaction_id');

        if ($wallet_amount <= 0) {
            return;
        }

        ?>
        <div class="wallet-payment-breakdown" style="margin-top: 15px; padding: 12px; background-color: #f0f8ff; border-left: 4px solid #0073aa;">
            <h4 style="margin-top: 0; color: #0073aa;">
                <?php esc_html_e('Wallet Payment Breakdown', 'wc-wallet'); ?>
            </h4>
            <p style="margin: 5px 0;">
                <strong><?php esc_html_e('Original Order Total:', 'wc-wallet'); ?></strong>
                <?php echo wp_kses_post(wc_price($original_total)); ?>
            </p>
            <p style="margin: 5px 0; color: #0073aa;">
                <strong><?php esc_html_e('Paid from Wallet:', 'wc-wallet'); ?></strong>
                -<?php echo wp_kses_post(wc_price($wallet_amount)); ?>
                <?php if ($transaction_id) : ?>
                    <span style="font-size: 0.9em; color: #666;">
                        (<?php echo esc_html(sprintf(__('Transaction ID: %s', 'wc-wallet'), $transaction_id)); ?>)
                    </span>
                <?php endif; ?>
            </p>
            <p style="margin: 5px 0;">
                <strong><?php echo esc_html(sprintf(__('Paid via %s:', 'wc-wallet'), $order->get_payment_method_title())); ?></strong>
                <?php echo wp_kses_post(wc_price($order->get_total())); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Store Booster.io order ID from their shortcode processing
     * This filter is called by Booster when processing any of their shortcodes
     *
     * @param array $atts Shortcode attributes from Booster
     * @param string $shortcode The shortcode being processed
     * @return array Unchanged attributes
     */
    public function store_booster_order_id($atts, $shortcode) {
        if (isset($atts['order_id']) && !empty($atts['order_id'])) {
            // Store the order ID in a class property for our shortcodes to use
            $this->booster_current_order_id = $atts['order_id'];
        }
        return $atts;
    }

    /**
     * Format wallet amounts in Booster invoice HTML
     * Uses regex to find and format wallet payment amounts
     *
     * @param string $html The invoice HTML
     * @param array $args Invoice arguments
     * @return string Formatted HTML
     */
    public function format_wallet_in_invoice_html($html, $args = array()) {
        return $this->format_wallet_amounts_in_html($html);
    }

    /**
     * Format wallet amounts in content
     *
     * @param string $content The content
     * @return string Formatted content
     */
    public function format_wallet_in_content($content) {
        return $this->format_wallet_amounts_in_html($content);
    }

    /**
     * Format wallet payment amounts in HTML using regex
     * Finds patterns like "- RM4" and formats to "- RM4.00"
     *
     * @param string $html The HTML content
     * @return string Formatted HTML
     */
    private function format_wallet_amounts_in_html($html) {
        // Pattern: Wallet Payment row with amount like "- RM4" or "- RM100"
        // Match: <th>Wallet Payment</th><td>- RM followed by digits
        $pattern = '/(<th>Wallet Payment<\/th>\s*<td>\s*-\s*RM\s*)(\d+(?:\.\d+)?)/i';

        $html = preg_replace_callback($pattern, function($matches) {
            $amount = $matches[2];
            $formatted = number_format((float)$amount, 2, '.', '');
            return $matches[1] . $formatted;
        }, $html);

        return $html;
    }

    /**
     * Get order from current context
     * Used by shortcodes to determine which order to display data for
     *
     * @param array $atts Shortcode attributes (may contain order_id)
     * @return WC_Order|false Order object or false
     */
    private function get_order_from_context($atts = array()) {
        global $post, $wcj_pdf_invoice_order_id, $wcj_order, $wcj_pdf_invoice_data;

        // Try to get order_id from shortcode attributes
        if (isset($atts['order_id']) && !empty($atts['order_id'])) {
            $order = wc_get_order($atts['order_id']);
            if ($order) {
                return $order;
            }
        }

        // Try Booster.io order ID stored from their shortcode filter
        if (isset($this->booster_current_order_id) && !empty($this->booster_current_order_id)) {
            $order = wc_get_order($this->booster_current_order_id);
            if ($order) {
                return $order;
            }
        }

        // Try Booster.io invoice data array (primary method for PDF invoices)
        if (isset($wcj_pdf_invoice_data) && is_array($wcj_pdf_invoice_data) && !empty($wcj_pdf_invoice_data)) {
            // Get the first item from the array
            $invoice_data = reset($wcj_pdf_invoice_data);

            // Try to get order_id from the invoice data
            if (isset($invoice_data['order_id']) && !empty($invoice_data['order_id'])) {
                $order = wc_get_order($invoice_data['order_id']);
                if ($order) {
                    return $order;
                }
            }

            // Alternative: the array key might be the order ID
            $order_id = key($wcj_pdf_invoice_data);
            if ($order_id && is_numeric($order_id)) {
                $order = wc_get_order($order_id);
                if ($order) {
                    return $order;
                }
            }
        }

        // Try Booster.io global order object
        if (isset($wcj_order) && $wcj_order instanceof WC_Order) {
            return $wcj_order;
        }

        // Try Booster.io global order ID
        if (isset($wcj_pdf_invoice_order_id) && !empty($wcj_pdf_invoice_order_id)) {
            $order = wc_get_order($wcj_pdf_invoice_order_id);
            if ($order) {
                return $order;
            }
        }

        // Try to get order from global post
        if (isset($post) && $post instanceof WP_Post) {
            $order = wc_get_order($post->ID);
            if ($order) {
                return $order;
            }
        }

        // Try to get order from query vars
        $order_id = get_query_var('order-received');
        if ($order_id) {
            return wc_get_order($order_id);
        }

        return false;
    }

    /**
     * Shortcode: Display original order total before wallet deduction
     * Usage: [wcj_wallet_original_total]
     *
     * @param array $atts Shortcode attributes
     * @return string Formatted original total or empty string
     */
    public function shortcode_wallet_original_total($atts) {
        $atts = shortcode_atts(array('order_id' => ''), $atts);
        $order = $this->get_order_from_context($atts);

        if (!$order) {
            return '';
        }

        $wallet_used = $order->get_meta('_partial_wallet_used');
        if ($wallet_used !== 'yes') {
            return '';
        }

        $original_total = floatval($order->get_meta('_original_order_total'));
        return html_entity_decode(wp_strip_all_tags(wc_price($original_total)));
    }

    /**
     * Shortcode: Display wallet payment amount
     * Usage: [wcj_wallet_payment_amount]
     *
     * @param array $atts Shortcode attributes
     * @return string Formatted wallet amount or empty string
     */
    public function shortcode_wallet_payment_amount($atts) {
        $atts = shortcode_atts(array('order_id' => ''), $atts);
        $order = $this->get_order_from_context($atts);

        if (!$order) {
            return '';
        }

        $wallet_used = $order->get_meta('_partial_wallet_used');
        if ($wallet_used !== 'yes') {
            return '';
        }

        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));
        return html_entity_decode(wp_strip_all_tags(wc_price($wallet_amount)));
    }

    /**
     * Shortcode: Display wallet payment as a table row
     * Usage: [wcj_wallet_payment_row]
     *
     * @param array $atts Shortcode attributes
     * @return string HTML table row or empty string
     */
    public function shortcode_wallet_payment_row($atts) {
        $atts = shortcode_atts(array('order_id' => ''), $atts);
        $order = $this->get_order_from_context($atts);

        if (!$order) {
            return '';
        }

        $wallet_used = $order->get_meta('_partial_wallet_used');
        if ($wallet_used !== 'yes') {
            return '';
        }

        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));

        if ($wallet_amount <= 0) {
            return '';
        }

        // Format to 2 decimal places
        $formatted_amount = number_format($wallet_amount, 2, '.', '');

        return '<tr><th>Wallet Payment</th><td>- RM' . esc_html($formatted_amount) . '</td></tr>';
    }

    /**
     * Shortcode: Display complete wallet payment breakdown table
     * Usage: [wcj_wallet_breakdown_table]
     *
     * @param array $atts Shortcode attributes
     * @return string HTML table with breakdown or empty string
     */
    public function shortcode_wallet_breakdown_table($atts) {
        $atts = shortcode_atts(array('order_id' => ''), $atts);
        $order = $this->get_order_from_context($atts);

        if (!$order) {
            return '';
        }

        $wallet_used = $order->get_meta('_partial_wallet_used');
        if ($wallet_used !== 'yes') {
            return '';
        }

        $wallet_amount = floatval($order->get_meta('_partial_wallet_amount'));
        $original_total = floatval($order->get_meta('_original_order_total'));

        if ($wallet_amount <= 0) {
            return '';
        }

        ob_start();
        ?>
        <table class="pdf_invoice_totals_table" style="margin-top: 10px; border-top: 2px solid #0073aa;">
            <tbody>
                <tr><th style="color: #0073aa;" colspan="2">PAYMENT BREAKDOWN</th></tr>
                <tr><th>Original Order Total</th><td><?php echo html_entity_decode(wp_strip_all_tags(wc_price($original_total))); ?></td></tr>
                <tr><th style="color: #0073aa;">Paid from Wallet</th><td style="color: #0073aa;">-<?php echo html_entity_decode(wp_strip_all_tags(wc_price($wallet_amount))); ?></td></tr>
                <tr><th>Paid via <?php echo esc_html($order->get_payment_method_title()); ?></th><td><?php echo html_entity_decode(wp_strip_all_tags(wc_price($order->get_total()))); ?></td></tr>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Debug shortcode to troubleshoot Booster.io integration
     * Usage: [wcj_wallet_debug]
     *
     * @param array $atts Shortcode attributes
     * @return string Debug information
     */
    public function shortcode_wallet_debug($atts) {
        global $wcj_pdf_invoice_data;

        $atts = shortcode_atts(array('order_id' => ''), $atts);
        $order = $this->get_order_from_context($atts);

        // Find all globals that might contain order info
        $relevant_globals = array();
        foreach ($GLOBALS as $key => $value) {
            if (stripos($key, 'order') !== false || stripos($key, 'wcj') !== false) {
                if (is_object($value) && method_exists($value, 'get_id')) {
                    $relevant_globals[$key] = 'Object with get_id(): ' . $value->get_id();
                } elseif (is_numeric($value)) {
                    $relevant_globals[$key] = 'Numeric: ' . $value;
                } elseif (is_string($value) && strlen($value) < 100) {
                    $relevant_globals[$key] = 'String: ' . $value;
                } elseif (is_array($value)) {
                    $relevant_globals[$key] = 'Array with ' . count($value) . ' items';
                }
            }
        }

        ob_start();
        ?>
        <div style="border: 1px solid #ccc; padding: 10px; margin: 10px 0; background: #f9f9f9; font-size: 12px;">
            <h4>Wallet Payment Debug Info</h4>
            <p><strong>Order Found:</strong> <?php echo $order ? 'Yes' : 'No'; ?></p>
            <?php if ($order): ?>
                <p><strong>Order ID:</strong> <?php echo $order->get_id(); ?></p>
                <p><strong>Order Number:</strong> <?php echo $order->get_order_number(); ?></p>
                <p><strong>Wallet Used Meta:</strong> <?php echo $order->get_meta('_partial_wallet_used') ? $order->get_meta('_partial_wallet_used') : 'Not set'; ?></p>
                <p><strong>Wallet Amount Meta:</strong> <?php echo $order->get_meta('_partial_wallet_amount') ? $order->get_meta('_partial_wallet_amount') : 'Not set'; ?></p>
                <p><strong>Original Total Meta:</strong> <?php echo $order->get_meta('_original_order_total') ? $order->get_meta('_original_order_total') : 'Not set'; ?></p>
            <?php endif; ?>
            <hr>
            <p><strong>Booster.io Invoice Data Structure:</strong></p>
            <?php if (isset($wcj_pdf_invoice_data) && is_array($wcj_pdf_invoice_data)): ?>
                <pre style="font-size: 10px; overflow: auto; max-height: 200px;"><?php print_r($wcj_pdf_invoice_data); ?></pre>
            <?php else: ?>
                <p>Not available</p>
            <?php endif; ?>
            <hr>
            <p><strong>All Order/WCJ Related Globals:</strong></p>
            <?php if (!empty($relevant_globals)): ?>
                <ul style="font-size: 11px;">
                    <?php foreach ($relevant_globals as $key => $value): ?>
                        <li><code>$<?php echo esc_html($key); ?></code>: <?php echo esc_html($value); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No order-related globals found</p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
