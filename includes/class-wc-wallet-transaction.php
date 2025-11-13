<?php
/**
 * WooCommerce Wallet Transaction Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Wallet_Transaction {

    /**
     * Transaction ID
     */
    public $id;

    /**
     * User ID
     */
    public $user_id;

    /**
     * Transaction type (credit/debit)
     */
    public $type;

    /**
     * Amount
     */
    public $amount;

    /**
     * Balance after transaction
     */
    public $balance;

    /**
     * Currency
     */
    public $currency;

    /**
     * Transaction details
     */
    public $details;

    /**
     * Order ID
     */
    public $order_id;

    /**
     * Created date
     */
    public $created_date;

    /**
     * Constructor
     */
    public function __construct($transaction_id = 0) {
        if ($transaction_id > 0) {
            $this->load($transaction_id);
        }
    }

    /**
     * Load transaction data
     */
    public function load($transaction_id) {
        $transaction = WC_Wallet_Database::get_transaction($transaction_id);

        if ($transaction) {
            $this->id = $transaction->id;
            $this->user_id = $transaction->user_id;
            $this->type = $transaction->type;
            $this->amount = $transaction->amount;
            $this->balance = $transaction->balance;
            $this->currency = $transaction->currency;
            $this->details = $transaction->details;
            $this->order_id = $transaction->order_id;
            $this->created_date = $transaction->created_date;
        }
    }

    /**
     * Get formatted amount
     */
    public function get_formatted_amount() {
        return wc_price(abs($this->amount), array('currency' => $this->currency));
    }

    /**
     * Get formatted balance
     */
    public function get_formatted_balance() {
        return wc_price($this->balance, array('currency' => $this->currency));
    }

    /**
     * Get transaction type label
     */
    public function get_type_label() {
        $labels = array(
            'credit' => __('Credit', 'wc-wallet'),
            'debit' => __('Debit', 'wc-wallet')
        );

        return isset($labels[$this->type]) ? $labels[$this->type] : $this->type;
    }

    /**
     * Get formatted date
     */
    public function get_formatted_date() {
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($this->created_date));
    }

    /**
     * Is credit
     */
    public function is_credit() {
        return $this->type === 'credit';
    }

    /**
     * Is debit
     */
    public function is_debit() {
        return $this->type === 'debit';
    }
}
