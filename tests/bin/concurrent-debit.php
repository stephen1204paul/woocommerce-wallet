<?php
/**
 * Child process for the race test: waits until the given unix timestamp so
 * sibling processes hit debit() at the same instant, then prints OK or FAIL.
 * Usage: php concurrent-debit.php <user_id> <amount> <start_at_microtime>
 */
require __DIR__ . '/../bootstrap.php';

[$_, $user_id, $amount, $start_at] = $argv;
while (microtime(true) < (float) $start_at) { usleep(200); }

$result = (new WC_Wallet_Manager())->debit((int) $user_id, (float) $amount, 'race');
echo is_wp_error($result) || !$result ? "FAIL\n" : "OK\n";
