<?php
/**
 * Test bootstrap: minimal WordPress surface plus a PDO-backed $wpdb so the
 * wallet classes run against a real MySQL/MariaDB (required — the behaviour
 * under test is row locking, which no SQLite or mock can reproduce).
 */

define('ABSPATH', __DIR__ . '/fake-wp/');
define('WC_WALLET_PLUGIN_DIR', dirname(__DIR__) . '/');

if (!function_exists('__')) { function __($text, $domain = null) { return $text; } }
function get_woocommerce_currency() { return 'MYR'; }
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function add_action() {}
function do_action() {}
function get_current_user_id() { return 1; }
function dbDelta($sql) { global $wpdb; $wpdb->query($sql); }

class WP_Error {
    private $code; private $message;
    public function __construct($code, $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

class WC_Wallet_Test_WPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $pdo;
    private $suppress = false;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function connect($with_db = true) {
        $host = getenv('WC_WALLET_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('WC_WALLET_TEST_DB_PORT') ?: '3306';
        $name = getenv('WC_WALLET_TEST_DB_NAME') ?: 'wc_wallet_test';
        $dsn = "mysql:host=$host;port=$port" . ($with_db ? ";dbname=$name" : '');
        $pdo = new PDO($dsn, getenv('WC_WALLET_TEST_DB_USER') ?: 'root', getenv('WC_WALLET_TEST_DB_PASS') ?: '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        return new self($pdo);
    }

    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function suppress_errors($suppress = true) { $prev = $this->suppress; $this->suppress = (bool) $suppress; return $prev; }

    public function prepare($query, ...$args) {
        if (is_array($args[0] ?? null)) { $args = $args[0]; }
        $query = str_replace('%%', "\0", $query);
        $query = preg_replace_callback('/%[dfs]/', function ($m) use (&$args) {
            $v = array_shift($args);
            switch ($m[0]) {
                case '%d': return (string) (int) $v;
                case '%f': return sprintf('%F', (float) $v);
                default: return $this->pdo->quote((string) $v);
            }
        }, $query);
        return str_replace("\0", '%', $query);
    }

    public function query($sql) {
        // Mirror wpdb::flush(): a failed statement leaves insert_id at 0, not a stale id.
        $this->insert_id = 0;
        $this->last_error = '';
        $r = $this->pdo->exec($sql);
        if ($r === false) { $this->last_error = implode(' ', $this->pdo->errorInfo()); return false; }
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return $r;
    }

    public function get_var($sql) {
        $st = $this->pdo->query($sql);
        if ($st === false) { return null; }
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function get_row($sql) { $st = $this->pdo->query($sql); return $st ? ($st->fetch(PDO::FETCH_OBJ) ?: null) : null; }
    public function get_results($sql) { $st = $this->pdo->query($sql); return $st ? $st->fetchAll(PDO::FETCH_OBJ) : array(); }

    public function insert($table, $data, $format = null) {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(function ($v) { return $v === null ? 'NULL' : $this->pdo->quote((string) $v); }, array_values($data)));
        $r = $this->query("INSERT INTO $table ($cols) VALUES ($vals)");
        return $r;
    }
}

// Create the test database on first use so a fresh checkout can run the suite.
$admin = WC_Wallet_Test_WPDB::connect(false);
$admin->query('CREATE DATABASE IF NOT EXISTS `' . (getenv('WC_WALLET_TEST_DB_NAME') ?: 'wc_wallet_test') . '`');
unset($admin);

$GLOBALS['wpdb'] = WC_Wallet_Test_WPDB::connect();

require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-database.php';
require_once WC_WALLET_PLUGIN_DIR . 'includes/class-wc-wallet-manager.php';
