<?php
use PHPUnit\Framework\TestCase;

class WalletDebitTest extends TestCase {
    private $manager;
    private $user_id = 42;

    protected function setUp(): void {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_wallet_transactions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_wallet_balance");
        WC_Wallet_Database::create_tables();
        $this->manager = new WC_Wallet_Manager();
    }

    private function log_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_wallet_transactions");
    }

    public function test_tables_use_innodb(): void {
        global $wpdb;
        foreach (array('wc_wallet_balance', 'wc_wallet_transactions') as $t) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $wpdb->prefix . $t
            ));
            $this->assertSame('InnoDB', $engine, "$t must be transactional");
        }
    }

    public function test_credit_then_debit_updates_balance_and_log(): void {
        $this->assertNotFalse($this->manager->credit($this->user_id, 100, 'topup'));
        $this->assertSame(100.0, $this->manager->get_wallet_balance($this->user_id));

        $this->assertNotFalse($this->manager->debit($this->user_id, 30, 'spend'));
        $this->assertSame(70.0, $this->manager->get_wallet_balance($this->user_id));
        $this->assertSame(2, $this->log_count());
    }

    public function test_debit_beyond_balance_is_rejected_and_leaves_no_trace(): void {
        $this->manager->credit($this->user_id, 50, 'topup');

        $result = $this->manager->debit($this->user_id, 50.01, 'spend');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('insufficient_balance', $result->get_error_code());
        $this->assertSame(50.0, $this->manager->get_wallet_balance($this->user_id));
        $this->assertSame(1, $this->log_count());
    }

    public function test_debit_of_unknown_user_is_rejected(): void {
        $this->assertInstanceOf(WP_Error::class, $this->manager->debit(999, 1, 'spend'));
        $this->assertSame(0.0, $this->manager->get_wallet_balance(999));
    }

    public function test_non_positive_amounts_are_rejected(): void {
        $this->assertFalse($this->manager->credit($this->user_id, 0));
        $this->assertFalse($this->manager->credit($this->user_id, -5));
        $this->assertFalse($this->manager->debit($this->user_id, 0));
        $this->assertFalse($this->manager->debit($this->user_id, -5));
    }

    /**
     * The sufficiency check must be evaluated against the balance at write time,
     * not a value read earlier in the request.
     */
    public function test_debit_uses_current_balance_not_a_stale_read(): void {
        global $wpdb;
        $this->manager->credit($this->user_id, 100, 'topup');
        $this->assertSame(100.0, $this->manager->get_wallet_balance($this->user_id));

        // Another actor spends 60 between our read and our write.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}wc_wallet_balance SET balance = 40 WHERE user_id = %d", $this->user_id
        ));

        $this->assertInstanceOf(WP_Error::class, $this->manager->debit($this->user_id, 100, 'spend'));
        $this->assertSame(40.0, $this->manager->get_wallet_balance($this->user_id));
    }

    /**
     * Two processes debit the full balance at the same instant. Exactly one may win.
     * Repeated because a race that passes once proves nothing.
     */
    public function test_concurrent_debits_cannot_overspend(): void {
        $rounds = 10;
        for ($i = 0; $i < $rounds; $i++) {
            $this->setUp();
            $this->manager->credit($this->user_id, 100, 'topup');

            $start_at = microtime(true) + 0.5;
            $procs = array();
            $pipes = array();
            for ($p = 0; $p < 2; $p++) {
                $cmd = array(PHP_BINARY, __DIR__ . '/bin/concurrent-debit.php', (string) $this->user_id, '100', (string) $start_at);
                $procs[$p] = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$p]);
                $this->assertIsResource($procs[$p]);
            }

            $outcomes = array();
            foreach ($procs as $p => $proc) {
                $outcomes[] = trim(stream_get_contents($pipes[$p][1]));
                $stderr = stream_get_contents($pipes[$p][2]);
                proc_close($proc);
                $this->assertSame('', $stderr, "child $p wrote to stderr");
            }

            $this->assertSame(1, count(array_keys($outcomes, 'OK')), "round $i: outcomes " . implode(',', $outcomes));
            $this->assertSame(0.0, $this->manager->get_wallet_balance($this->user_id), "round $i: balance");
            $this->assertSame(2, $this->log_count(), "round $i: exactly one debit row plus the top-up");
        }
    }

    /**
     * If the audit row cannot be written, the balance change must not survive.
     */
    public function test_failed_transaction_log_rolls_back_balance(): void {
        global $wpdb;
        $this->manager->credit($this->user_id, 100, 'topup');

        $wpdb->query("CREATE TRIGGER wc_wallet_test_fail BEFORE INSERT ON {$wpdb->prefix}wc_wallet_transactions
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated log failure'");

        $this->assertFalse($this->manager->debit($this->user_id, 30, 'spend'));
        $this->assertSame(100.0, $this->manager->get_wallet_balance($this->user_id));
        $wpdb->query('DROP TRIGGER wc_wallet_test_fail');
    }

    /**
     * When the caller already holds a transaction (WooCommerce checkout does),
     * the wallet must join it with a savepoint rather than implicitly committing it.
     */
    public function test_debit_inside_callers_transaction_rolls_back_with_it(): void {
        global $wpdb;
        $this->manager->credit($this->user_id, 100, 'topup');

        $wpdb->query('START TRANSACTION');
        $this->assertNotFalse($this->manager->debit($this->user_id, 100, 'spend'));
        $this->assertSame(0.0, $this->manager->get_wallet_balance($this->user_id));
        $wpdb->query('ROLLBACK');

        $this->assertSame(100.0, $this->manager->get_wallet_balance($this->user_id), 'outer rollback must undo the debit');
        $this->assertSame(1, $this->log_count(), 'outer rollback must undo the audit row');
    }

    public function test_failed_debit_inside_callers_transaction_keeps_outer_work(): void {
        global $wpdb;
        $this->manager->credit($this->user_id, 10, 'topup');

        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}wc_wallet_balance (user_id, balance, currency, updated_date) VALUES (%d, 5, 'MYR', NOW())", 7));
        $this->assertInstanceOf(WP_Error::class, $this->manager->debit($this->user_id, 50, 'spend'));
        $wpdb->query('COMMIT');

        $this->assertSame(5.0, $this->manager->get_wallet_balance(7), "wallet's rollback must not discard the caller's transaction");
        $this->assertSame(10.0, $this->manager->get_wallet_balance($this->user_id));
    }
}
