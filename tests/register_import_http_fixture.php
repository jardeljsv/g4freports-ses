<?php
// Isolated HTTP fixture: never available under a production web SAPI.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\StaffRegister;
use GlpiPlugin\G4freports\Ses\Settings;
use GlpiPlugin\G4freports\Ses\RegisterExporter;
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== '--prepare' || !is_dir($argv[2] ?? '')) exit(2);
    $dir = $argv[2];
    $rows = StaffRegister::rows([['id' => 'fixture-1', 'name' => 'Pessoa Teste', 'profile' => 'Analista', 'admission_date' => '2026-01-01', 'monthly_value' => '3000.00', 'not_billed' => false, 'billing_days_overrides' => ['2026-05' => 0, '2026-06' => 22]]]);
    $phones = Settings::testNumbers([['number' => '00123', 'label' => 'Teste', 'active' => true]]);
    file_put_contents($dir . '/state.json', json_encode(['ses_staff_register_json' => StaffRegister::json($rows), 'ses_test_numbers_json' => StaffRegister::json($phones)]));
    file_put_contents($dir . '/fixture.json', json_encode(['staff' => $rows, 'tests' => $phones, 'staff_revision' => StaffRegister::revision($rows), 'tests_revision' => StaffRegister::revision($phones)]));
    $rows[0]['monthly_value'] = '3100.00';
    file_put_contents($dir . '/staff.xlsx', RegisterExporter::export('staff', $rows, 'all', '2026-06'));
    file_put_contents($dir . '/tests.xlsx', RegisterExporter::export('tests', $phones));
    exit;
}
if (!getenv('G4FR_IMPORT_FIXTURE_STATE')) { http_response_code(404); exit; }
define('UPDATE', 2);
class CommonGLPI {}
class Config extends CommonGLPI {
    public static function getConfigurationValues($context): array { return json_decode(file_get_contents(getenv('G4FR_IMPORT_FIXTURE_STATE')), true); }
    public static function setConfigurationValues($context, $values): void { throw new RuntimeException('Preview must not write'); }
}
class Session {
    public static function getLoginUserID(): int { return (int)($_GET['fixture_user'] ?? 42); }
    public static function haveRight($right, $level): bool { return ($_GET['fixture_configure'] ?? '1') === '1'; }
    public static function getNewCSRFToken(): string { return 'next-test-token'; }
}
$DB = new stdClass();
$_SESSION = ['glpiID' => Session::getLoginUserID(), 'g4freports_ses_nonce' => 'fixture-nonce'];
// Native GLPI CSRF is not available in this test environment; emulate its rejection.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_glpi_csrf_token'] ?? '') !== 'fixture-csrf') {
    http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'fixture csrf']); exit;
}
require dirname(__DIR__) . '/front/ses_register_import.php';
