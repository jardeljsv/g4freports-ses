<?php

// Keep GLPI's native POST/CSRF bootstrap; the nonce also binds this preview to the SES page.
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\StaffRegister;
use GlpiPlugin\G4freports\Ses\Settings;
use GlpiPlugin\G4freports\Ses\FinanceSettings;
use GlpiPlugin\G4freports\Ses\RegisterImporter;

@ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$cfg = Config::merged();
$csrf = class_exists('Session') && method_exists('Session', 'getNewCSRFToken') ? Session::getNewCSRFToken() : '';
$reply = static function (int $code, array $result) use ($csrf): void {
    g4fr_json_response($code, array_merge($result, ['csrf_token' => $csrf]));
};
if (Security::currentUserId() <= 0 || !Security::canUse($cfg) || !Security::canConfigure()) {
    $reply(403, ['ok' => false, 'error' => 'É necessária permissão de configuração para importar cadastros.']);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST'); $reply(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
$nonce = $_POST['_ses_nonce'] ?? null; $expected = $_SESSION['g4freports_ses_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expected) || $expected === '' || !hash_equals($expected, $nonce)) {
    $reply(403, ['ok' => false, 'error' => 'Sessão expirada. Recarregue a área SES antes de importar.']);
}
try {
    $kind = $_POST['kind'] ?? null; $raw = $_POST['rows'] ?? null; $revision = $_POST['revision'] ?? null;
    if (!is_string($kind) || !in_array($kind, ['staff', 'tests'], true) || !is_string($raw) || strlen($raw) > 150000
        || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) throw new InvalidArgumentException('Cadastro ou versão inválidos. Recarregue a página.');
    $saved = $kind === 'staff' ? StaffRegister::fromSettings($cfg) : Settings::testNumbers($cfg['ses_test_numbers_json'] ?? '[]');
    if (!hash_equals(StaffRegister::revision($saved), $revision)) {
        $reply(409, ['ok' => false, 'error' => 'O cadastro foi alterado em outra sessão. Exporte suas alterações locais e recarregue a página antes de importar.']);
    }
    $current = $kind === 'staff' ? StaffRegister::rows($raw) : Settings::testNumbers($raw);
    if ($kind === 'staff') StaffRegister::validateDuplicates($current, $saved);
    $file = $_FILES['workbook'] ?? null;
    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'], $file['name'])
        || !is_int($file['error']) || $file['error'] !== UPLOAD_ERR_OK
        || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])
        || !is_string($file['name']) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx'
        || !is_int($file['size']) || $file['size'] <= 0 || $file['size'] > 2097152) {
        throw new InvalidArgumentException('Selecione um arquivo .xlsx válido de até 2 MB. Confira também o limite de upload do servidor.');
    }
    $binary = file_get_contents($file['tmp_name'], false, null, 0, 2097153);
    if (!is_string($binary) || strlen($binary) !== $file['size'] || strlen($binary) > 2097152) throw new InvalidArgumentException('O arquivo está incompleto ou excede 2 MB.');
    $fronts = array_column(FinanceSettings::fromSettings($cfg)['contract_fronts'], 'id');
    $preview = RegisterImporter::preview($kind, $binary, $current, $fronts);
    // Read-only preview. The existing register endpoint owns validation, revision lock and history at Save.
    $reply(200, array_merge(['ok' => true, 'revision' => $revision], $preview));
} catch (InvalidArgumentException $e) {
    $reply(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    $reply(500, ['ok' => false, 'error' => 'Não foi possível analisar o arquivo. Nenhum dado foi alterado. Confira o modelo e tente novamente.']);
}
