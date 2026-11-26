<?php
// Isolated controller fixture; never served by a production PHP web SAPI.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\MonthlyArchiveStore;
use GlpiPlugin\G4freports\Ses\SnapshotStore;
use GlpiPlugin\G4freports\Ses\Settings;

$fixtureDir = PHP_SAPI === 'cli' ? ($argv[2] ?? '') : getenv('G4FR_ARCHIVE_FIXTURE_DIR');
if (!$fixtureDir || !is_dir($fixtureDir)) { http_response_code(404); exit; }
if (PHP_SAPI === 'cli' || ($_GET['fixture_no_archive'] ?? '') !== '1') define('GLPI_PLUGIN_DOC_DIR', $fixtureDir . '/documents');
define('GLPI_TMP_DIR', $fixtureDir . '/temporary');
define('GLPI_ROOT', $fixtureDir . '/installation');
if (PHP_SAPI === 'cli') {
    if (!in_array($argv[1] ?? '', ['--prepare', '--prepare-shared'], true)) exit(2);
    foreach (['documents', 'temporary', 'installation', 'sessions'] as $part) mkdir($fixtureDir . '/' . $part, 0700);
    $snapshot = ['schema_version' => 2, 'rules_version' => Settings::RULES_VERSION,
        'generated_at' => '2026-02-01T12:00:00-03:00',
        'period' => ['month' => '2026-01', 'label' => 'Janeiro de 2026', 'start' => '2026-01-01 00:00:00', 'end_exclusive' => '2026-02-01 00:00:00', 'date_basis' => 'date', 'call_date_basis' => 'calldate'],
        'input' => ['month' => '2026-01', 'timezone' => 'America/Sao_Paulo', 'entity_id' => 1, 'group_ids' => [2],
            'priority_map' => ['INS1' => 5, 'INS2' => 4, 'INS3' => 3, 'INS4' => 2], 'n1_group_ids' => [2], 'n2_group_ids' => [3], 'n3_group_ids' => [4],
            'n1_categories' => [], 'ins6_mode' => 'known', 'staff_mode' => 'manual', 'kb_mode' => 'validated_links', 'validated_kb_ids' => [],
            'ins12_mode' => 'glpi', 'queue_filter' => 'support', 'test_numbers' => [], 'report_title' => 'Frozen fixture'],
        'source_identity' => ['glpi' => str_repeat('a', 64), 'callcenter' => str_repeat('b', 64)],
        'financial' => ['available' => true, 'complete' => true, 'gross_value' => '1000.00', 'deduction_total' => '10.00', 'adjustment_total' => '0.00', 'net_value' => '990.00'],
        'metrics' => [], 'totals' => ['tickets' => 123], 'manual' => ['executive_summary' => 'Original frozen narrative'],
        'tickets' => [['id' => 987, 'name' => 'Raw evidence retained']]];
    for ($n = 1; $n <= 12; $n++) $snapshot['metrics']['INS' . $n] = ['id' => 'INS' . $n, 'label' => 'Indicador ' . $n,
        'value' => 80, 'target' => 75, 'status' => 'met', 'direction' => in_array($n, [5, 9, 10], true) ? 'lte' : 'gte', 'numerator' => 80, 'denominator' => 100];
    $token = SnapshotStore::save($snapshot, 42);
    $privateDraft = $snapshot; $privateDraft['manual']['notes'] = 'New draft remains private unless explicitly shared';
    $privateToken = SnapshotStore::save($privateDraft, 42);
    $old = $snapshot; $old['period']['month'] = $old['input']['month'] = '2026-02';
    $old['period']['start'] = '2026-02-01 00:00:00'; $old['period']['end_exclusive'] = '2026-03-01 00:00:00';
    $old['generated_at'] = '2026-03-01T12:00:00-03:00';
    $old['period']['label'] = 'Fevereiro de 2026'; $old['rules_version'] = 'historical-rule-version'; unset($old['source_identity']);
    $old['metrics']['INS1']['value'] = 85; $old['manual']['notes'] = 'Historical values must remain readable';
    $oldToken = SnapshotStore::save($old, 42);
    $oldRecord = MonthlyArchiveStore::archive($old, 42, SnapshotStore::fingerprint($old));
    $other = $snapshot; $other['manual']['notes'] = 'Other owner';
    $otherRecord = MonthlyArchiveStore::archive($other, 43, SnapshotStore::fingerprint($other));
    $monthly = $monthlyRecord = $monthlyToken = null;
    if (($argv[1] ?? '') === '--prepare-shared') {
        $monthly = json_decode(file_get_contents(__DIR__ . '/fixtures/monthly_export_v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $monthlyRecord = MonthlyArchiveStore::archive($monthly, 42, SnapshotStore::fingerprint($monthly));
        $monthlyToken = SnapshotStore::save($monthly, 42);
    }
    file_put_contents($fixtureDir . '/fixture.json', json_encode(['token' => $token, 'snapshot' => $snapshot,
        'draft_revision' => SnapshotStore::fingerprint($snapshot), 'old_token' => $oldToken, 'old_snapshot' => $old,
        'old_record' => $oldRecord, 'other_record' => $otherRecord, 'monthly_record' => $monthlyRecord,
        'monthly_snapshot' => $monthly, 'monthly_token' => $monthlyToken,
        'private_token' => $privateToken, 'private_snapshot' => $privateDraft,
        'private_revision' => SnapshotStore::fingerprint($privateDraft)], JSON_UNESCAPED_UNICODE));
    exit;
}
define('UPDATE', 2);
class CommonGLPI {}
class Config extends CommonGLPI {
    public static function getConfigurationValues($context): array {
        // Archive browsing must survive disabled sources and malformed current manual registers.
        return ['enabled' => ($_GET['fixture_enabled'] ?? '1') === '1' ? 1 : 0,
            'glpi_mysql_enabled' => 0, 'callcenter_mysql_enabled' => 0,
            'ses_archive_shared_groups' => $_GET['fixture_shared_groups'] ?? '',
            'ses_staff_register_json' => '{broken', 'ses_test_numbers_json' => '{broken'];
    }
    public static function setConfigurationValues($context, $values): void { throw new RuntimeException('Archive wrote live settings'); }
}
class Session {
    public static function getLoginUserID(): int { return (int)($_GET['fixture_user'] ?? 42); }
    public static function haveRight($right, $level): bool { return ($_GET['fixture_configure'] ?? '0') === '1'; }
    public static function getNewCSRFToken(): string { return 'next-test-token'; }
}
class ArchiveForbiddenSource {
    public function __construct(...$args) { throw new RuntimeException('Source client invoked'); }
    public static function __callStatic($method, $args) { throw new RuntimeException('Source extraction or calculation invoked'); }
}
foreach (['GlpiPlugin\\G4freports\\Database\\MysqlClient', 'GlpiPlugin\\G4freports\\Ses\\Repository',
    'GlpiPlugin\\G4freports\\Ses\\Calculator'] as $class) class_alias(ArchiveForbiddenSource::class, $class);
class ArchiveFixtureDB {
    public function request(array $query): array {
        if (($query['FROM'] ?? '') !== 'glpi_groups_users') throw new RuntimeException('Unexpected database query');
        if (($_GET['fixture_group_db_error'] ?? '') === '1') throw new RuntimeException('Fixture membership unavailable');
        $groups = $_GET['fixture_groups'] ?? (in_array((int)($query['WHERE']['users_id'] ?? 0), [42, 43, 45], true) ? '55' : '56');
        return array_map(static fn($id) => ['groups_id' => $id], explode(',', $groups));
    }
}
$DB = new ArchiveFixtureDB();
session_save_path($fixtureDir . '/sessions');
session_id('archive-fixture-' . Session::getLoginUserID());
session_start();
$_SESSION = ['glpiID' => Session::getLoginUserID(), 'g4freports_ses_nonce' => 'fixture-nonce', 'glpigroups' => [55, 56]];
register_shutdown_function(static function () use ($fixtureDir): void {
    file_put_contents($fixtureDir . '/last_request.json', json_encode(['session_status' => session_status(), 'request' => $_GET['fixture_request'] ?? '']));
});
// Native GLPI is absent from this fixture; emulate only its POST CSRF rejection.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_glpi_csrf_token'] ?? '') !== 'fixture-csrf') {
    http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'fixture csrf']); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/fail_admission') {
    session_write_close();
    ini_set('display_errors', '0');
    $admission = \GlpiPlugin\G4freports\Ses\ExportAdmission::acquire();
    if (($_GET['failure'] ?? '') === 'other') g4freports_fixture_undefined_function();
    if (($_GET['failure'] ?? '') === 'timeout') {
        set_time_limit(1);
        while (true) { hash('sha256', str_repeat('x', 4096)); }
    }
    ini_set('memory_limit', '16M');
    $failure = str_repeat('x', 32 * 1024 * 1024);
    exit;
}
if ($path === '/hold_admission') {
    // Isolated fixture holder: exercises the production lock across independent PHP servers.
    session_write_close();
    $admission = \GlpiPlugin\G4freports\Ses\ExportAdmission::acquire();
    try {
        file_put_contents($fixtureDir . '/admission_ready', 'ready');
        $deadline = microtime(true) + 8;
        while (!is_file($fixtureDir . '/admission_release') && microtime(true) < $deadline) usleep(10000);
        header('Content-Type: application/json'); echo '{"ok":true}';
    } finally { \GlpiPlugin\G4freports\Ses\ExportAdmission::release($admission); }
    exit;
}
if ($path === '/annual_export') require dirname(__DIR__) . '/front/ses_annual_export.php';
elseif ($path === '/monthly_export') require dirname(__DIR__) . '/front/ses_archive_export.php';
elseif ($path === '/monthly_live') require dirname(__DIR__) . '/front/ses_export.php';
else require dirname(__DIR__) . '/front/ses_archive_api.php';
