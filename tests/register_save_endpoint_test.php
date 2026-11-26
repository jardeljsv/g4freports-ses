<?php
/**
 * Runs the real save endpoint, bootstrap and plugin classes in isolated PHP requests.
 * GLPI's Config/session APIs and DB transport are test doubles; this is not a live
 * MariaDB test. Optionally pass an official GLPI DBmysql.php path to inherit its
 * actual lock and transaction implementations (including GLPI 11's query ban).
 * Usage: php tests/register_save_endpoint_test.php [/path/to/GLPI/src/DBmysql.php]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (($argv[1] ?? '') === '--request') {
    $fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['register_fixture'] = $fixture;
    $GLOBALS['register_trace'] = [];
    define('GLPI_ROOT', $fixture['root']);
    define('UPDATE', 2);
    class CommonGLPI {}
    class Config extends CommonGLPI
    {
        public static function getConfigurationValues($context, array $names = []): array
        {
            $GLOBALS['register_trace'][] = $names ? 'read_keys' : 'read';
            $current = json_decode(file_get_contents($GLOBALS['register_fixture']['state']), true, 512, JSON_THROW_ON_ERROR);
            return $names ? array_intersect_key($current, array_fill_keys($names, true)) : $current;
        }
        public static function setConfigurationValues($context, $values): void
        {
            $GLOBALS['register_trace'][] = 'write';
            $fixture = $GLOBALS['register_fixture'];
            if (($fixture['write'] ?? '') === 'throw') throw new RuntimeException('Test persistence failed.');
            if (($fixture['write'] ?? '') === 'ignore') return;
            $current = json_decode(file_get_contents($fixture['state']), true, 512, JSON_THROW_ON_ERROR);
            foreach ($values as $name => $value) {
                if (($fixture['skip_key'] ?? '') === $name) continue;
                $current[$name] = $value;
                file_put_contents($fixture['state'], json_encode($current, JSON_THROW_ON_ERROR));
                if (($fixture['throw_after_key'] ?? '') === $name) throw new RuntimeException('Test partial persistence failed.');
            }
        }
    }
    class Session
    {
        public static function getLoginUserID(): int { return $GLOBALS['register_fixture']['user'] ?? 42; }
        public static function haveRight($right, $level): bool { return $GLOBALS['register_fixture']['configure'] ?? true; }
        public static function getNewCSRFToken(): string { return 'next-fixture-csrf'; }
    }
    // Simulates only mysqli's storage/transaction transport. When an official
    // DBmysql source is supplied, its transaction ownership logic runs unchanged.
    class RegisterMysqliTransport
    {
        private ?string $original = null;
        private array $savepoints = [];
        private function allowed(string $stage): bool
        {
            $mode = $GLOBALS['register_fixture'][$stage] ?? '';
            if ($mode === 'throw') throw new RuntimeException('Test ' . $stage . ' failed.');
            return $mode !== 'false';
        }
        public function begin_transaction(): bool
        {
            $GLOBALS['register_trace'][] = 'begin';
            if (!$this->allowed('begin')) return false;
            $this->original = file_get_contents($GLOBALS['register_fixture']['state']);
            return true;
        }
        public function commit(): bool
        {
            $GLOBALS['register_trace'][] = 'commit';
            if (!$this->allowed('commit')) return false;
            $this->original = null; $this->savepoints = [];
            return true;
        }
        public function rollback(): bool
        {
            $GLOBALS['register_trace'][] = 'rollback';
            if (!$this->allowed('rollback')) return false;
            if ($this->original !== null) file_put_contents($GLOBALS['register_fixture']['state'], $this->original);
            $this->original = null; $this->savepoints = [];
            return true;
        }
        public function savepoint($name): bool
        {
            $GLOBALS['register_trace'][] = 'savepoint';
            if (!$this->allowed('savepoint')) return false;
            $this->savepoints[$name] = file_get_contents($GLOBALS['register_fixture']['state']);
            return true;
        }
        public function release_savepoint($name): bool
        {
            $GLOBALS['register_trace'][] = 'release_savepoint';
            if (!$this->allowed('commit')) return false;
            unset($this->savepoints[$name]);
            return true;
        }
        public function rollbackTo($name): bool
        {
            $GLOBALS['register_trace'][] = 'rollback_to';
            if (!$this->allowed('rollback')) return false;
            if (!isset($this->savepoints[$name])) throw new LogicException('Unknown fixture savepoint.');
            file_put_contents($GLOBALS['register_fixture']['state'], $this->savepoints[$name]);
            return true;
        }
    }
    $PHPLOGGER = new class {
        public function error($message, $context): void { $GLOBALS['register_trace'][] = 'native_error'; }
    };
    if (!empty($fixture['upstream'])) {
        require __DIR__ . '/fixtures/register_safe_functions.php';
        require $fixture['upstream'];
    } else {
        // Minimal public DBmysql contract. Use the optional source argument to
        // run these same requests against the real version-specific methods.
        class DBmysql
        {
            public $dbdefault = 'glpi_fixture';
            protected $dbh;
            private int $transactionLevel = 0;
            public function beginTransaction(): void
            {
                $ok = $this->transactionLevel === 0 ? $this->dbh->begin_transaction() : $this->dbh->savepoint('savepoint_' . ($this->transactionLevel - 1));
                if (!$ok) throw new RuntimeException('Failed to start transaction.');
                $this->transactionLevel++;
            }
            public function commit(): void
            {
                $ok = $this->transactionLevel === 1 ? $this->dbh->commit() : $this->dbh->release_savepoint('savepoint_' . ($this->transactionLevel - 2));
                if (!$ok) throw new RuntimeException('Failed to commit transaction.');
                $this->transactionLevel--;
            }
            public function rollBack(): void
            {
                $ok = $this->transactionLevel === 1 ? $this->dbh->rollback() : $this->dbh->rollbackTo('savepoint_' . ($this->transactionLevel - 2));
                if (!$ok) throw new RuntimeException('Failed to roll back transaction.');
                $this->transactionLevel--;
            }
            public function query($query) { throw new RuntimeException('Executing direct queries is not allowed!'); }
            public function getLock($name)
            {
                $result = $this->doQuery('SELECT GET_LOCK(' . $this->quote($this->dbdefault . '.' . $name) . ', 0)');
                [$ok] = $this->fetchRow($result);
                return (bool)$ok;
            }
            public function releaseLock($name)
            {
                $result = $this->doQuery('SELECT RELEASE_LOCK(' . $this->quote($this->dbdefault . '.' . $name) . ')');
                [$ok] = $this->fetchRow($result);
                return $ok;
            }
        }
    }
    class RegisterDbTransport extends DBmysql
    {
        public function __construct() { $this->dbdefault = 'glpi_fixture'; $this->dbh = new RegisterMysqliTransport(); }
        public function query($query)
        {
            // GLPI 10's native lock implementation calls query internally;
            // calls originating in plugin code always fail in this harness.
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
            if (in_array($caller['function'] ?? '', ['getLock', 'releaseLock'], true)
                && ($caller['class'] ?? '') === 'DBmysql') return $this->doQuery($query);
            $GLOBALS['register_trace'][] = 'forbidden_query';
            throw new RuntimeException('Executing direct queries is not allowed!');
        }
        public function quote($value, int $type = 2) { return "'" . addslashes($value) . "'"; }
        public function fetchRow($result) { return $result; }
        public function doQuery($query)
        {
            $fixture = $GLOBALS['register_fixture'];
            if (str_starts_with($query, 'SELECT GET_LOCK(')) {
                $GLOBALS['register_trace'][] = 'lock';
                $GLOBALS['register_trace'][] = $query;
                if (($fixture['lock'] ?? '') === 'throw') throw new RuntimeException('Test lock failed.');
                return [($fixture['lock'] ?? '') === 'occupied' ? 0 : 1];
            }
            if (str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
                $GLOBALS['register_trace'][] = 'unlock';
                $GLOBALS['register_trace'][] = $query;
                if (($fixture['release'] ?? '') === 'throw') throw new RuntimeException('DO_NOT_LOG_PRIVATE_DATABASE_MESSAGE');
                return [($fixture['release'] ?? '') === 'false' ? 0 : 1];
            }
            if (str_starts_with($query, 'ROLLBACK TO ')) return $this->dbh->rollbackTo(trim(substr($query, 12), '`'));
            throw new LogicException('Unexpected query in isolated register transport.');
        }
    }
    $DB = new RegisterDbTransport();
    $outerState = null;
    if (!empty($fixture['outer_transaction'])) {
        $DB->beginTransaction();
        $current = json_decode(file_get_contents($fixture['state']), true, 512, JSON_THROW_ON_ERROR);
        $current['outer_marker'] = 'Caller work';
        file_put_contents($fixture['state'], json_encode($current, JSON_THROW_ON_ERROR));
        $outerState = file_get_contents($fixture['state']);
        $GLOBALS['register_trace'] = [];
    }
    $_SERVER['REQUEST_METHOD'] = $fixture['method'] ?? 'POST';
    $_SESSION = ['glpiID' => $fixture['user'] ?? 42, 'glpiname' => 'Fixture administrator', 'g4freports_ses_nonce' => 'fixture-nonce'];
    $_POST = $fixture['post'];
    register_shutdown_function(static function () use ($fixture, $DB, $outerState): void {
        $beforeOuterRollback = file_get_contents($fixture['state']);
        if ($outerState !== null) $DB->rollBack();
        file_put_contents($fixture['metadata'], json_encode([
            'status' => http_response_code(), 'trace' => $GLOBALS['register_trace'],
            'legacy_transactions' => is_callable([$DB, 'inTransaction']),
            'outer_state' => $outerState, 'before_outer_rollback' => $beforeOuterRollback,
        ], JSON_THROW_ON_ERROR));
    });
    // No endpoint code is copied, transformed or eval'd. Its own JSON response
    // exits this subprocess after the same validation/save/readback path.
    require dirname(__DIR__) . '/front/' . (($fixture['endpoint'] ?? '') === 'financial' ? 'ses_financial.php' : 'ses_registers.php');
    exit(99);
}

require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\StaffRegister as Staff;
use GlpiPlugin\G4freports\Ses\Settings;
use GlpiPlugin\G4freports\Ses\FinanceSettings;

$assertions = 0;
function saveCheck(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function saveRequest(array $post, array $options = []): array
{
    global $root, $state, $upstream;
    $metadata = $root . '/response-meta.json';
    $input = array_replace(['root' => $root, 'state' => $state, 'metadata' => $metadata,
        'upstream' => $upstream, 'post' => array_replace(['_ses_nonce' => 'fixture-nonce'], $post)], $options);
    $pipes = [];
    $command = [PHP_BINARY];
    // Preserve extension loading in isolated runtimes launched with php -n.
    if (!php_ini_loaded_file()) {
        array_push($command, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'));
        foreach (['ctype', 'iconv', 'mbstring'] as $extension) {
            if (extension_loaded($extension) && is_file(ini_get('extension_dir') . '/' . $extension . '.so')) {
                array_push($command, '-d', 'extension=' . $extension);
            }
        }
    }
    array_push($command, __FILE__, '--request');
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not launch endpoint fixture.');
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    saveCheck($exit === 0, 'Endpoint exits normally: ' . $stderr . ($exit === 0 ? '' : $stdout));
    saveCheck($stderr === '', 'No PHP warnings/fatal errors: ' . $stderr);
    $response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    $meta = json_decode(file_get_contents($metadata), true, 512, JSON_THROW_ON_ERROR);
    saveCheck(!in_array('forbidden_query', $meta['trace'], true), 'Plugin never calls forbidden direct-query API');
    return [$response, $meta];
}
function savePayload(string $kind, array $before, array $after): array
{
    return ['action' => 'save_' . $kind, 'kind' => $kind,
        'revision' => Staff::revision($before), 'rows' => Staff::json($after)];
}
function persistedRows(string $kind): array
{
    global $state;
    $cfg = json_decode(file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
    return $kind === 'staff' ? Staff::fromSettings($cfg) : Settings::testNumbers($cfg['ses_test_numbers_json'] ?? '[]');
}
function staffFixture(int $id): array
{
    return ['id' => 'agent_' . $id, 'name' => 'Profissional ' . str_pad((string)$id, 2, '0', STR_PAD_LEFT),
        'profile' => 'Analista', 'admission_date' => '2025-01-01', 'departure_date' => null,
        'monthly_value' => '1000.00', 'not_billed' => $id === 3];
}

$upstream = isset($argv[1]) ? realpath($argv[1]) : null;
if (isset($argv[1]) && !$upstream) throw new RuntimeException('Official DBmysql source path not found.');
$root = sys_get_temp_dir() . '/g4fr-save-' . bin2hex(random_bytes(6));
mkdir($root . '/files/_log', 0700, true);
$state = $root . '/configuration.json';
try {
    $before = Staff::rows(array_map('staffFixture', range(1, 30)));
    $phones = Settings::testNumbers([['number' => '00123', 'label' => 'Teste 1', 'active' => true],
        ['number' => '00619', 'label' => 'Teste 2', 'active' => false]]);
    file_put_contents($state, json_encode([Staff::CONFIG_KEY => Staff::json($before), 'ses_test_numbers_json' => Staff::json($phones)], JSON_THROW_ON_ERROR));

    // The UI collects every page before this endpoint receives the payload.
    // Simulate edits from page 1/page 2 plus a new entry, preserving all others.
    $edited = $before;
    $edited[0]['name'] = 'Agente alterado na página 1';
    $edited[0]['billing_days_overrides'] = ['2026-06' => 2, '2026-07' => 22];
    $edited[0]['service_front'] = 'ses_n2';
    $edited[26]['monthly_value'] = '2500.50';
    $edited[26]['billing_days_overrides'] = ['2026-06' => 0];
    $edited[26]['service_front'] = 'ses_monitoramento';
    $edited[] = staffFixture(31);
    $after = Staff::rows($edited);
    [$response, $meta] = saveRequest(savePayload('staff', $before, $edited));
    saveCheck($meta['status'] === 200 && $response['ok'], 'Staff create/edit request succeeds: ' . json_encode([$response, $meta]));
    saveCheck($response['rows'] === $after && persistedRows('staff') === $after, 'All 31 entries and cross-page edits survive actual save/readback');
    saveCheck($response['revision'] === Staff::revision($after), 'Saved revision matches returned complete register');
    saveCheck($response['csrf_token'] === 'next-fixture-csrf', 'Successful JSON includes refreshed CSRF token');
    saveCheck(array_column($after, 'not_billed', 'id')['agent_3'] === true, 'Existing exclusion marks remain unchanged');
    $events = array_values(array_filter($meta['trace'], static fn($event) => !str_starts_with($event, 'SELECT ')));
    saveCheck($events === ['read', 'lock', 'read', 'begin', 'write', 'read_keys', 'commit', 'read', 'unlock'], 'Lock surrounds revision check, atomic verified persistence and readback');
    saveCheck(str_contains(implode('\n', $meta['trace']), 'glpi_fixture.g4fr_registers_'), 'Native lock API namespaces the lock by database');

    [$history, $historyMeta] = saveRequest(['action' => 'history', 'kind' => 'staff']);
    saveCheck($historyMeta['status'] === 200 && count($history['history']) === 1, 'History endpoint works with native lock');
    saveCheck($history['history'][0]['added'] === 1 && $history['history'][0]['updated'] === 2, 'History records both edits and added employment');
    [$detail] = saveRequest(['action' => 'history_detail', 'kind' => 'staff', 'history_id' => $history['history'][0]['id']]);
    saveCheck($detail['rows'] === $before, 'Actual history_detail endpoint recovers previous complete register');

    $newPhones = $phones;
    $newPhones[0]['label'] = 'Descrição alterada';
    $newPhones[] = ['number' => '0000077', 'label' => 'Novo teste', 'active' => true];
    [$phoneResponse, $phoneMeta] = saveRequest(savePayload('tests', $phones, $newPhones));
    saveCheck($phoneMeta['status'] === 200 && $phoneResponse['rows'] === $newPhones, 'Test-number register uses same successful save path');
    saveCheck(persistedRows('tests') === $newPhones && persistedRows('staff') === $after, 'Phone save retains leading zeros/status and does not alter staff');
    $staffLock = array_values(array_filter($meta['trace'], static fn($event) => str_starts_with($event, 'SELECT GET_LOCK(')));
    $phoneLock = array_values(array_filter($phoneMeta['trace'], static fn($event) => str_starts_with($event, 'SELECT GET_LOCK(')));
    saveCheck(count($staffLock) === 1 && $staffLock === $phoneLock, 'Both register kinds use the same native serialization lock');

    $snapshot = file_get_contents($state);
    [$conflict, $conflictMeta] = saveRequest(savePayload('staff', $before, $edited));
    saveCheck($conflictMeta['status'] === 409 && !$conflict['ok'], 'Stale revision remains a conflict');
    saveCheck(file_get_contents($state) === $snapshot && in_array('unlock', $conflictMeta['trace'], true), 'Conflict preserves saved configuration and releases lock');
    [$invalid, $invalidMeta] = saveRequest(['action' => 'save_staff', 'revision' => Staff::revision($after), 'rows' => '[invalid']);
    saveCheck($invalidMeta['status'] === 400 && !$invalid['ok'], 'Invalid rows rejected with structured response');
    saveCheck(file_get_contents($state) === $snapshot && in_array('unlock', $invalidMeta['trace'], true), 'Invalid rows cannot save and still release lock');
    [$revisionError, $revisionMeta] = saveRequest(['action' => 'save_staff', 'revision' => 'bad', 'rows' => '[]']);
    saveCheck($revisionMeta['status'] === 400 && !$revisionError['ok'], 'Malformed revision rejected');
    [$occupied, $occupiedMeta] = saveRequest(savePayload('staff', $after, $after), ['lock' => 'occupied']);
    saveCheck($occupiedMeta['status'] === 500 && str_contains($occupied['error'], 'Outro cadastro'), 'Busy native lock returns controlled retry response');
    saveCheck(!in_array('write', $occupiedMeta['trace'], true) && !in_array('unlock', $occupiedMeta['trace'], true), 'Unacquired lock is never released and cannot permit save');
    [$lockError, $lockMeta] = saveRequest(savePayload('staff', $after, $after), ['lock' => 'throw']);
    saveCheck($lockMeta['status'] === 500 && !in_array('unlock', $lockMeta['trace'], true), 'Acquisition exception becomes JSON without erroneous release');

    foreach (['throw', 'false'] as $releaseMode) {
        $updated = $after; $updated[0]['monthly_value'] = $releaseMode === 'throw' ? '1250.00' : '1300.00';
        [$releaseResult, $releaseMeta] = saveRequest(savePayload('staff', $after, $updated), ['release' => $releaseMode]);
        $after = Staff::rows($updated);
        saveCheck($releaseMeta['status'] === 200 && $releaseResult['ok'] && persistedRows('staff') === $after, 'Release failure cannot conceal confirmed save: ' . $releaseMode);
        saveCheck($releaseResult['csrf_token'] === 'next-fixture-csrf', 'Cleanup failure retains refreshed client token');
    }
    $log = file_get_contents($root . '/files/_log/g4freports-plugin.log');
    saveCheck(substr_count($log, 'SES_REGISTER_LOCK_RELEASE_FAILED') === 2, 'Both cleanup failure forms recorded as narrow events');
    saveCheck(!str_contains($log, 'DO_NOT_LOG') && !str_contains($log, 'Profissional'), 'Cleanup log contains no DB exception message or register values');
    [$badAndRelease, $badAndReleaseMeta] = saveRequest(['action' => 'save_staff', 'revision' => Staff::revision($after), 'rows' => '[invalid'], ['release' => 'throw']);
    saveCheck($badAndReleaseMeta['status'] === 400 && str_contains($badAndRelease['error'], 'JSON inválido'), 'Cleanup error does not replace original validation error');

    $updated = $after; $updated[0]['monthly_value'] = '1600.00';
    [$writeError, $writeMeta] = saveRequest(savePayload('staff', $after, $updated), ['write' => 'throw', 'release' => 'throw']);
    saveCheck($writeMeta['status'] === 500 && $writeError['error'] === 'Test persistence failed.', 'Cleanup error does not replace original persistence error');
    saveCheck(persistedRows('staff') === $after, 'Failed write preserves prior saved register');
    [$noWrite, $noWriteMeta] = saveRequest(savePayload('staff', $after, $updated), ['write' => 'ignore']);
    saveCheck($noWriteMeta['status'] === 500 && str_contains($noWrite['error'], 'não confirmou'), 'Readback mismatch still blocks a false success');

    // Every part of one logical save must survive or roll back together. Native
    // Config may silently ignore an individual add/update and still return void.
    $atomicBefore = file_get_contents($state);
    $atomicConfig = json_decode($atomicBefore, true, 512, JSON_THROW_ON_ERROR);
    $indexKey = 'ses_register_staff_history_index';
    $priorEntries = json_decode($atomicConfig[$indexKey], true, 512, JSON_THROW_ON_ERROR);
    $slot = ($priorEntries[0]['slot'] + 1) % 10;
    $atomicKeys = [];
    for ($part = 0; $part < 4; $part++) $atomicKeys[] = 'ses_register_staff_history_' . $slot . '_' . $part;
    array_push($atomicKeys, $indexKey, Staff::CONFIG_KEY);
    foreach ($atomicKeys as $key) {
        foreach (['skip_key', 'throw_after_key'] as $failure) {
            [$failed, $failedMeta] = saveRequest(savePayload('staff', $after, $updated), [$failure => $key]);
            saveCheck($failedMeta['status'] === 500 && !$failed['ok'], 'Partial key failure cannot report success: ' . $failure . ' ' . $key);
            saveCheck(file_get_contents($state) === $atomicBefore, 'Partial failure preserves current rows and complete previous history: ' . $key);
            saveCheck(in_array('rollback', $failedMeta['trace'], true) && !in_array('commit', $failedMeta['trace'], true), 'Failed verification/write rolls back before commit');
        }
    }
    foreach (['begin', 'commit'] as $stage) {
        foreach (['false', 'throw'] as $mode) {
            [$failed, $failedMeta] = saveRequest(savePayload('staff', $after, $updated), [$stage => $mode]);
            saveCheck($failedMeta['status'] === 500 && !$failed['ok'], 'Transaction failure returns no success: ' . $stage . ' ' . $mode);
            saveCheck(file_get_contents($state) === $atomicBefore, 'Transaction failure cannot leave a phantom history entry: ' . $stage . ' ' . $mode);
            saveCheck($stage === 'begin' ? !in_array('write', $failedMeta['trace'], true) : in_array('rollback', $failedMeta['trace'], true), 'Transaction cleanup matches operation ownership');
        }
    }

    [$outerResult, $outerMeta] = saveRequest(savePayload('staff', $after, $updated), ['outer_transaction' => true]);
    if ($outerMeta['legacy_transactions']) {
        saveCheck($outerMeta['status'] === 500 && !$outerResult['ok'], 'GLPI 10 refuses to restart an existing transaction');
        saveCheck(!in_array('begin', $outerMeta['trace'], true) && !in_array('write', $outerMeta['trace'], true) && !in_array('commit', $outerMeta['trace'], true), 'GLPI 10 leaves caller transaction untouched');
        saveCheck($outerMeta['before_outer_rollback'] === $outerMeta['outer_state'], 'GLPI 10 preserves pre-existing caller work');
    } else {
        saveCheck($outerMeta['status'] === 200 && $outerResult['ok'], 'GLPI 11 commits its native nested savepoint');
        saveCheck(in_array('savepoint', $outerMeta['trace'], true) && in_array('release_savepoint', $outerMeta['trace'], true) && !in_array('commit', $outerMeta['trace'], true), 'GLPI 11 never commits caller transaction');
        $outerSaved = json_decode($outerMeta['before_outer_rollback'], true, 512, JSON_THROW_ON_ERROR);
        saveCheck(($outerSaved['outer_marker'] ?? '') === 'Caller work' && Staff::fromSettings($outerSaved) === Staff::rows($updated), 'Nested successful save retains caller work');
        [$outerFailure, $outerFailureMeta] = saveRequest(savePayload('staff', $after, $updated), ['outer_transaction' => true, 'skip_key' => $atomicKeys[0]]);
        saveCheck($outerFailureMeta['status'] === 500 && !$outerFailure['ok'], 'Nested partial write still fails');
        saveCheck(in_array('rollback_to', $outerFailureMeta['trace'], true) && $outerFailureMeta['before_outer_rollback'] === $outerFailureMeta['outer_state'], 'Nested rollback restores only this savepoint and preserves caller work');
        foreach (['savepoint', 'commit'] as $stage) {
            [$nestedFailure, $nestedMeta] = saveRequest(savePayload('staff', $after, $updated), ['outer_transaction' => true, $stage => 'false']);
            saveCheck($nestedMeta['status'] === 500 && !$nestedFailure['ok'], 'Nested transaction failure cannot report success: ' . $stage);
            saveCheck($nestedMeta['before_outer_rollback'] === $nestedMeta['outer_state'], 'Nested transaction failure preserves caller work: ' . $stage);
            saveCheck($stage === 'savepoint' ? !in_array('write', $nestedMeta['trace'], true) : in_array('rollback_to', $nestedMeta['trace'], true), 'Failed nested begin/commit cleans up only an acquired level');
        }
    }
    saveCheck(file_get_contents($state) === $atomicBefore, 'Outer caller can roll back after register operation');

    // Legacy field canonicalization also writes even when normalized rows did
    // not change; it needs the same checked transaction, without new history.
    $legacyRows = $after;
    foreach ($legacyRows as &$legacyRow) { $legacyRow['billed'] = $legacyRow['not_billed']; unset($legacyRow['not_billed']); }
    unset($legacyRow);
    $legacyConfig = $atomicConfig;
    $legacyConfig[Staff::CONFIG_KEY] = Staff::json($legacyRows);
    $legacyState = json_encode($legacyConfig, JSON_THROW_ON_ERROR);
    file_put_contents($state, $legacyState);
    [$legacyFailure, $legacyFailureMeta] = saveRequest(savePayload('staff', $after, $after), ['skip_key' => Staff::CONFIG_KEY]);
    saveCheck($legacyFailureMeta['status'] === 500 && !$legacyFailure['ok'] && file_get_contents($state) === $legacyState, 'Unchanged legacy save cannot bypass failed canonical persistence');
    [$legacySuccess, $legacySuccessMeta] = saveRequest(savePayload('staff', $after, $after));
    saveCheck($legacySuccessMeta['status'] === 200 && $legacySuccess['ok'] && persistedRows('staff') === $after, 'Legacy canonical save commits without changing normalized marks');
    $canonicalConfig = json_decode(file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
    saveCheck($canonicalConfig[$indexKey] === $atomicConfig[$indexKey] && $canonicalConfig[Staff::CONFIG_KEY] === Staff::json($after), 'Canonicalization writes explicit exclusion data without phantom history');
    file_put_contents($state, $atomicBefore);

    foreach (['false', 'throw'] as $mode) {
        [$rollbackFailure, $rollbackMeta] = saveRequest(savePayload('staff', $after, $updated), ['throw_after_key' => $atomicKeys[0], 'rollback' => $mode]);
        saveCheck($rollbackMeta['status'] === 500 && !$rollbackFailure['ok'] && $rollbackFailure['error'] === 'Test partial persistence failed.', 'Rollback failure preserves original failure without reporting success');
        saveCheck(!in_array('commit', $rollbackMeta['trace'], true), 'Failed rollback never falls through to commit');
        file_put_contents($state, $atomicBefore);
    }
    $cleanupLog = file_get_contents($root . '/files/_log/g4freports-plugin.log');
    saveCheck(substr_count($cleanupLog, 'SES_REGISTER_ROLLBACK_FAILED') === 2, 'Rollback failures emit narrow diagnostic events');
    saveCheck(!str_contains($cleanupLog, 'Profissional') && !str_contains($cleanupLog, 'DO_NOT_LOG'), 'Rollback log excludes register data and private failure details');

    foreach ([['configure' => false], ['user' => 0], ['post' => ['_ses_nonce' => 'wrong']], ['method' => 'GET']] as $denial) {
        [$denied, $deniedMeta] = saveRequest(savePayload('staff', $after, $after), $denial);
        saveCheck(in_array($deniedMeta['status'], [403, 405], true) && !$denied['ok'], 'Permission/session/method checks still apply');
        saveCheck(!in_array('lock', $deniedMeta['trace'], true) && !in_array('write', $deniedMeta['trace'], true), 'Denied requests never acquire lock or mutate register');
    }
    $financeBefore = FinanceSettings::fromSettings([]);
    $financeAfter = FinanceSettings::fromSettings([FinanceSettings::CONFIG_KEY => ['months' => [
        '2026-06' => ['deduction_overrides' => ['INS3' => ['rate' => 0, 'justification' => 'Decisão financeira aprovada']],
            'adjustments' => [['description' => 'Ajuste de período anterior', 'amount' => '10.25']]],
        '2026-07' => ['adjustments' => [['description' => 'Desconto de julho', 'amount' => '-5.00']]],
    ]]]);
    $financePayload = ['action' => 'save', 'config' => FinanceSettings::json($financeAfter), 'revision' => FinanceSettings::revision($financeBefore)];
    [$financeResponse, $financeMeta] = saveRequest($financePayload, ['endpoint' => 'financial']);
    saveCheck($financeMeta['status'] === 200 && $financeResponse['ok'], 'Financial settings save using same native lock and authenticated POST');
    saveCheck(FinanceSettings::normalize($financeResponse['config']) === $financeAfter && $financeResponse['revision'] === FinanceSettings::revision($financeAfter), 'Two months, explicit zero override and signed adjustments survive persistence');
    saveCheck($financeResponse['config']['contract_total'] === '562457.91' && count($financeResponse['config']['contract_fronts']) === 7, 'Approved total and default profile composition survive save/readback');
    saveCheck(persistedRows('staff') === $after && persistedRows('tests') === $newPhones, 'Financial save preserves staff and test-number registers');
    [$financialConflict, $financialConflictMeta] = saveRequest($financePayload, ['endpoint' => 'financial']);
    saveCheck($financialConflictMeta['status'] === 409 && !$financialConflict['ok'], 'Financial stale revision cannot overwrite another session');
    $badFinance = $financeAfter; $badFinance['months']['2026-06']['deduction_overrides']['INS3']['justification'] = '';
    [$financialInvalid, $financialInvalidMeta] = saveRequest(['action' => 'save', 'config' => FinanceSettings::json($badFinance), 'revision' => FinanceSettings::revision($financeAfter)], ['endpoint' => 'financial']);
    saveCheck($financialInvalidMeta['status'] === 400 && !$financialInvalid['ok'], 'Financial override needs explicit justification before persistence');
    foreach ([['configure' => false], ['user' => 0], ['post' => ['_ses_nonce' => 'wrong']], ['method' => 'GET']] as $denial) {
        [$denied, $deniedMeta] = saveRequest($financePayload, array_merge(['endpoint' => 'financial'], $denial));
        saveCheck(in_array($deniedMeta['status'], [403, 405], true) && !$denied['ok'], 'Financial endpoint retains permission/session/method checks');
        saveCheck(!in_array('write', $deniedMeta['trace'], true), 'Denied financial request cannot persist');
    }
    $profileEdit = $financeAfter;
    $profileEdit['contract_fronts'][0]['profile_lines'][0]['monthly_rate'] = '15000.17';
    $profileEdit['contract_fronts'][0]['profile_lines'][0]['contract_quantity'] = 2;
    $profileEdit = FinanceSettings::normalize($profileEdit);
    [$profileSaved, $profileMeta] = saveRequest(['action' => 'save', 'config' => FinanceSettings::json($profileEdit), 'revision' => FinanceSettings::revision($financeAfter)], ['endpoint' => 'financial']);
    saveCheck($profileMeta['status'] === 200 && $profileSaved['ok'] && FinanceSettings::normalize($profileSaved['config']) === $profileEdit, 'Nested profile quantity/rate edits and all monthly decisions persist together');
    saveCheck($profileSaved['config']['contract_total'] === '562457.91', 'Editing a profile does not replace the approved total');
    $emptyCustom = ['contract_fronts' => [], 'contract_total' => null, 'months' => $financeAfter['months']];
    [$emptySaved, $emptyMeta] = saveRequest(['action' => 'save', 'config' => FinanceSettings::json($emptyCustom), 'revision' => FinanceSettings::revision($profileEdit)], ['endpoint' => 'financial']);
    saveCheck($emptyMeta['status'] === 200 && $emptySaved['ok'], 'Explicit empty custom baseline saves without a false readback error');
    $savedCfg = json_decode(file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
    saveCheck(FinanceSettings::fromSettings($savedCfg) === FinanceSettings::normalize($emptyCustom), 'Intentional empty baseline remains empty after reload');
    $legacyFinance = ['contract_fronts' => [], 'months' => $financeAfter['months']];
    [$legacySaved, $legacyMeta] = saveRequest(['action' => 'save', 'config' => FinanceSettings::json($legacyFinance), 'revision' => FinanceSettings::revision(FinanceSettings::normalize($emptyCustom))], ['endpoint' => 'financial']);
    saveCheck($legacyMeta['status'] === 200 && $legacySaved['ok'] && FinanceSettings::normalize($legacySaved['config']) === $financeAfter, 'Legacy empty composition is canonicalized before saving, avoiding default/readback mismatch');
    saveCheck(persistedRows('staff') === $after && persistedRows('tests') === $newPhones, 'Financial profile edits and resets preserve staff marks, service fronts, days and test numbers');
    echo 'Register save endpoint checks passed: ' . $assertions . ' assertions; '
        . ($upstream ? 'official DBmysql methods from ' . basename($upstream) : 'GLPI API contract double')
        . "; DB transport/Config/session mocked, no live GLPI/MariaDB.\n";
} finally {
    foreach (glob($root . '/files/_log/*') ?: [] as $file) unlink($file);
    foreach (glob($root . '/*.json') ?: [] as $file) unlink($file);
    rmdir($root . '/files/_log'); rmdir($root . '/files'); rmdir($root);
}
