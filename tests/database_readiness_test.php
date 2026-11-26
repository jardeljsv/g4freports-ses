<?php
/** Exercises real MysqlClient::test with a synthetic mysqli transport; no database is contacted. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (extension_loaded('mysqli')) {
    // Keep this test portable without replacing a loaded native extension.
    $process = proc_open([PHP_BINARY, '-n', __FILE__], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated diagnostic fixture.');
    exit(proc_close($process));
}
if (!class_exists('mysqli', false)) {
    define('MYSQLI_OPT_CONNECT_TIMEOUT', 0);
    define('MYSQLI_OPT_READ_TIMEOUT', 1);
    define('MYSQLI_OPT_LOCAL_INFILE', 2);
    class DiagnosticResult
    {
        private array $rows;
        public function __construct(array $rows) { $this->rows = $rows; mysqli::$created++; }
        public function fetch_assoc() { return array_shift($this->rows); }
        public function free(): void { mysqli::$freed++; }
    }
    class mysqli
    {
        public static string $mode = '';
        public static array $queries = [];
        public static int $closed = 0;
        public static int $created = 0;
        public static int $freed = 0;
        public int $errno = 0;
        public function options($name, $value): bool { return true; }
        public function real_connect(...$args): bool { return true; }
        public function set_charset($value): bool { return true; }
        public function close(): void { self::$closed++; }
        public function query(string $sql)
        {
            self::$queries[] = $sql;
            if ($sql === 'SELECT VERSION() AS server_version') {
                return new DiagnosticResult([['server_version' => '8.0.0-fixture']]);
            }
            if (str_starts_with($sql, 'WITH g4freports_probe')) {
                if (self::$mode === 'unsupported') throw new RuntimeException('PRIVATE driver detail', 1064);
                if (self::$mode === 'disconnected') throw new RuntimeException('PRIVATE driver detail', 2013);
                if (self::$mode === 'invalid_probe') return new DiagnosticResult([['probe_value' => 1, 'probe_rank' => 2]]);
                return new DiagnosticResult([['probe_value' => 1, 'probe_rank' => 1]]);
            }
            if (!preg_match('/\ASELECT `[^;]+` FROM `([a-z_]+)` LIMIT 0\z/D', $sql, $matches)) {
                throw new LogicException('Diagnostic attempted an unexpected or unbounded query: ' . $sql);
            }
            if (self::$mode === 'missing_users' && $matches[1] === 'glpi_users') {
                throw new RuntimeException('PRIVATE driver detail', 1146);
            }
            if (self::$mode === 'missing_actor_column' && $matches[1] === 'glpi_logs' && str_contains($sql, '`user_name`')) {
                throw new RuntimeException('PRIVATE driver detail', 1054);
            }
            if (self::$mode === 'optional_missing' && $matches[1] === 'tab_pesquisa') {
                throw new RuntimeException('PRIVATE driver detail', 1146);
            }
            if (self::$mode === 'optional_users_missing' && $matches[1] === 'glpi_tickets_users') {
                throw new RuntimeException('PRIVATE driver detail', 1146);
            }
            return new DiagnosticResult([]);
        }
    }
}
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Database\MysqlClient;
$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    $count++;
    if (!$ok) throw new RuntimeException($message);
};
$client = new MysqlClient(['host' => 'fixture.invalid', 'database' => 'fixture', 'username' => 'fixture']);
$run = static function (string $mode, string $source = 'glpi') use ($client, $check): array {
    mysqli::$mode = $mode; mysqli::$queries = [];
    $closed = mysqli::$closed;
    $result = $client->test($source);
    $check(mysqli::$closed === $closed + 1, 'Every diagnostic closes its connection.');
    $check(mysqli::$created === mysqli::$freed, 'Every diagnostic frees its result.');
    $check(!str_contains(json_encode($result), 'PRIVATE'), 'Driver details remain sanitized.');
    $check($result['performance_verified'] === false, 'Readiness is not a performance claim.');
    return $result;
};
$ok = $run('');
$check($ok['ok'] && $ok['query_features']['status'] === 'available', 'A capable source passes readiness.');
foreach (['glpi_users', 'glpi_groups_users'] as $table) {
    $check(($ok['schema'][$table]['required'] ?? false) === true, 'Mandatory actor/association table is checked: ' . $table);
}
foreach (['id', 'date_mod', 'user_name'] as $column) {
    $check(in_array($column, $ok['schema']['glpi_logs']['columns'], true), 'Actor status column is checked: ' . $column);
}
foreach (['id', 'date_creation', 'users_id', 'user_name'] as $column) {
    $check(in_array($column, $ok['schema']['glpi_itilsolutions']['columns'], true), 'Solution column is checked: ' . $column);
}
$unsupported = $run('unsupported');
$check(!$unsupported['ok'] && $unsupported['query_features']['status'] === 'unsupported', 'Unsupported CTE/window syntax cannot pass readiness.');
$check($unsupported['connection']['status'] === 'connected', 'Connection and query-feature readiness are distinct.');
$check($unsupported['query_features']['mysql_code'] === 1064, 'Sanitized capability error retains its code.');
$missing = $run('missing_users');
$check(!$missing['ok'] && $missing['schema']['glpi_users']['status'] === 'missing_table', 'Missing mandatory actor table fails readiness.');
$missingColumn = $run('missing_actor_column');
$check(!$missingColumn['ok'] && $missingColumn['schema']['glpi_logs']['status'] === 'missing_columns', 'Missing mandatory actor column fails readiness.');
$optional = $run('optional_missing', 'callcenter');
$check($optional['ok'] && $optional['schema']['tab_pesquisa']['status'] === 'missing_table', 'Optional phone survey stays optional.');
$optionalUsers = $run('optional_users_missing');
$check($optionalUsers['ok'] && !$optionalUsers['schema']['glpi_tickets_users']['required'], 'Optional ticket display actors stay optional, matching extraction.');
$check($run('invalid_probe')['ok'] === false, 'Incorrect synthetic result is rejected.');
$lost = $run('disconnected');
$check(!$lost['ok'] && $lost['query_features']['status'] === 'unavailable', 'A disconnected probe is not misclassified as unsupported SQL.');
echo 'Database readiness: ' . $count . " assertions passed (synthetic transport; no source reads).\n";
