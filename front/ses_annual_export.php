<?php

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\AnnualDocxExporter;
use GlpiPlugin\G4freports\Ses\AnnualXlsxExporter;
use GlpiPlugin\G4freports\Ses\AnnualReport;
use GlpiPlugin\G4freports\Ses\AnnualSelection;

@ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$owner = Security::currentUserId();
$configuration = Config::merged();
if ($owner <= 0 || !Security::canUse($configuration)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Acesso negado.']);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    g4fr_json_response(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
$nonce = $_POST['_ses_nonce'] ?? null;
$expectedNonce = $_SESSION['g4freports_ses_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expectedNonce) || $expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Sessão expirada. Recarregue a área SES.']);
}
$csrf = class_exists('Session') && method_exists('Session', 'getNewCSRFToken') ? Session::getNewCSRFToken() : '';
if (is_string($csrf) && $csrf !== '') header('X-G4F-CSRF-Token: ' . $csrf);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $format = $_POST['format'] ?? null;
    if (!is_string($format) || !in_array($format, ['docx', 'xlsx'], true)) {
        throw new InvalidArgumentException('Selecione Word ou Excel para exportar a comparação mensal.');
    }
    $selection = AnnualSelection::parse($_POST['payload'] ?? null);
    $records = AnnualSelection::records($selection, $owner, $configuration);
    if (!$records) {
        throw new InvalidArgumentException('Selecione pelo menos uma revisão mensal arquivada para exportar a comparação.');
    }
    $report = AnnualReport::build($records, $selection['months']);
    $exporter = $format === 'docx' ? new AnnualDocxExporter() : new AnnualXlsxExporter();
    $binary = $exporter->toBinary($report);
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $exporter->filename($report));
    header('Content-Type: ' . ($format === 'docx'
        ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
} catch (InvalidArgumentException $e) {
    g4fr_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $code = in_array($e->getCode(), [404, 409, 503], true) ? $e->getCode() : 500;
    $message = in_array($e->getCode(), [404, 409, 503], true)
        ? $e->getMessage() : 'Não foi possível exportar a comparação dos relatórios arquivados.';
    g4fr_json_response($code, ['ok' => false, 'error' => $message]);
} catch (Throwable $e) {
    g4fr_json_response(500, ['ok' => false, 'error' => 'Não foi possível exportar a comparação dos relatórios arquivados.']);
}
