<?php

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\AnnualSelection;
use GlpiPlugin\G4freports\Ses\ArchiveAccess;
use GlpiPlugin\G4freports\Ses\ExportAdmission;
use GlpiPlugin\G4freports\Ses\MonthlyArchiveStore;
use GlpiPlugin\G4freports\Ses\MonthlyExport;

@ini_set('display_errors', '0');
@set_time_limit(120);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$requester = Security::currentUserId();
$configuration = Config::merged();
if ($requester <= 0 || !Security::canUse($configuration)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Acesso negado.']);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    g4fr_json_response(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
$nonce = $_GET['_ses_nonce'] ?? null;
$expectedNonce = $_SESSION['g4freports_ses_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expectedNonce) || $expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Sessão expirada. Recarregue a área SES.']);
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$admission = null;
try {
    $id = AnnualSelection::id($_GET['id'] ?? null);
    $month = AnnualSelection::month($_GET['month'] ?? null);
    $format = $_GET['format'] ?? null;
    if (!is_string($format) || !in_array($format, ['docx', 'xlsx'], true)) throw new InvalidArgumentException('Selecione Word ou Excel para o relatório mensal arquivado.');
    if (isset($_GET['owner']) || isset($_GET['path']) || isset($_GET['snapshot'])) throw new InvalidArgumentException('Informe somente a referência do relatório arquivado.');
    $reference = ArchiveAccess::resolve($requester, $id, $month, ArchiveAccess::reference($_GET['archive_ref'] ?? null), $configuration);
    // Admission is shared across users on this instance/temp volume, before a large snapshot is read/decoded.
    $admission = ExportAdmission::acquire();
    $snapshot = MonthlyArchiveStore::loadSnapshot($reference['owner'], $id, $month);
    $export = MonthlyExport::render($snapshot, $format);
    $filename = preg_replace('/\.' . $format . '$/', '-rev-' . substr($id, 0, 12) . '.' . $format, $export['filename']);
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
    header('Content-Type: ' . $export['mime']);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($export['binary']));
    echo $export['binary'];
} catch (InvalidArgumentException $error) {
    g4fr_json_response(400, ['ok' => false, 'error' => $error->getMessage()]);
} catch (RuntimeException $error) {
    $code = in_array($error->getCode(), [403, 404, 409, 503], true) ? $error->getCode() : 500;
    g4fr_json_response($code, ['ok' => false, 'error' => $code === 500 ? 'Não foi possível exportar o relatório mensal arquivado.' : $error->getMessage()]);
} catch (Throwable $error) {
    g4fr_json_response(500, ['ok' => false, 'error' => 'Não foi possível exportar o relatório mensal arquivado.']);
} finally {
    ExportAdmission::release($admission);
}
