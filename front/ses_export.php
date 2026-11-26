<?php

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\SnapshotStore;
use GlpiPlugin\G4freports\Ses\MonthlyExport;
use GlpiPlugin\G4freports\Ses\ExportAdmission;

@ini_set('display_errors', '0');
@set_time_limit(120);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$errorResponse = static function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
};
$owner = Security::currentUserId();
if ($owner <= 0 || !Security::canUse(Config::merged())) {
    $errorResponse(403, 'Acesso negado.');
    exit;
}
$token = $_GET['token'] ?? '';
$format = $_GET['format'] ?? '';
if (!is_string($token) || !in_array($format, ['docx', 'xlsx', 'json'], true)) {
    $errorResponse(400, 'Selecione um formato de relatório válido.');
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$admission = null;
try {
    if ($format !== 'json') $admission = ExportAdmission::acquire();
    $snapshot = SnapshotStore::load($token, $owner);
    if ($format === 'json') {
        $binary = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $filename = 'SES-rascunho-' . preg_replace('/[^0-9-]/', '', (string)($snapshot['period']['month'] ?? '')) . '.json';
        $mime = 'application/json';
    } else {
        SnapshotStore::assertCurrentRules($snapshot);
        $rendered = MonthlyExport::render($snapshot, $format);
        $binary = $rendered['binary'];
        $filename = $rendered['filename'];
        $mime = $rendered['mime'];
    }
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $binary;
} catch (InvalidArgumentException $e) {
    $errorResponse(400, $e->getMessage());
} catch (RuntimeException $e) {
    $errorResponse(in_array($e->getCode(), [409, 503], true) ? $e->getCode() : 400, $e->getMessage());
} catch (Throwable $e) {
    $errorResponse(500, 'Não foi possível exportar o relatório. Os dados preservados não foram alterados. Tente novamente.');
} finally {
    ExportAdmission::release($admission);
}
