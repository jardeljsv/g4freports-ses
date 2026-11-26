<?php

// Native bootstrap keeps GLPI's CSRF checks for archive and preview POSTs.
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\AnnualReport;
use GlpiPlugin\G4freports\Ses\AnnualAnalytics;
use GlpiPlugin\G4freports\Ses\AnnualSelection;
use GlpiPlugin\G4freports\Ses\ArchiveAccess;
use GlpiPlugin\G4freports\Ses\MonthlyArchiveStore;
use GlpiPlugin\G4freports\Ses\SnapshotStore;

@ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$owner = Security::currentUserId();
$configuration = Config::merged();
if ($owner <= 0 || !Security::canUse($configuration)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Acesso negado.']);
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    g4fr_json_response(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
$input = $method === 'POST' ? $_POST : $_GET;
$action = $input['action'] ?? '';
if (!is_string($action) || !in_array($action, ['list', 'archive', 'share', 'preview', 'prepare_activity', 'download_snapshot'], true)) {
    g4fr_json_response(400, ['ok' => false, 'error' => 'Operação de arquivo desconhecida.']);
}
$requiredMethod = in_array($action, ['archive', 'share', 'preview', 'prepare_activity'], true) ? 'POST' : 'GET';
if ($method !== $requiredMethod) {
    header('Allow: ' . $requiredMethod);
    g4fr_json_response(405, ['ok' => false, 'error' => 'Método não permitido para esta operação.']);
}
$nonce = $input['_ses_nonce'] ?? null;
$expectedNonce = $_SESSION['g4freports_ses_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expectedNonce) || $expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Sessão expirada. Recarregue a área SES.']);
}
$csrf = $method === 'POST' && class_exists('Session') && method_exists('Session', 'getNewCSRFToken') ? Session::getNewCSRFToken() : '';
$reply = static function (int $code, array $result) use ($csrf): void {
    if (is_string($csrf) && $csrf !== '') $result['csrf_token'] = $csrf;
    g4fr_json_response($code, $result);
};
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$streamingDownload = false;

try {
    if ($action === 'list') {
        $year = AnnualSelection::year($input['year'] ?? null);
        $reply(200, ['ok' => true] + ArchiveAccess::listing($owner, $year, $configuration));
    }
    if ($action === 'archive') {
        $share = $input['share'] ?? '0';
        if (!is_string($share) || !in_array($share, ['0', '1'], true)) throw new InvalidArgumentException('Opção de compartilhamento inválida.');
        if ($share === '1' && !ArchiveAccess::status($configuration)['allowed']) throw new RuntimeException('O compartilhamento não está autorizado para este usuário.', 403);
        $token = AnnualSelection::id($input['token'] ?? null);
        $revision = AnnualSelection::id($input['draft_revision'] ?? null);
        // Only the server-owned draft can be archived. Do not normalize live
        // settings or accept replacement financial/metric/narrative values.
        $snapshot = SnapshotStore::load($token, $owner);
        SnapshotStore::assertCurrentRules($snapshot);
        $record = MonthlyArchiveStore::archive($snapshot, $owner, $revision);
        if ($share === '1') {
            try { $record = ArchiveAccess::publish($owner, $record['id'], $record['month'], $configuration); }
            catch (RuntimeException $error) {
                $code = in_array($error->getCode(), [403, 404, 409, 503], true) ? $error->getCode() : 503;
                $reply($code, ['ok' => false, 'archive_saved' => true, 'record' => ['id' => $record['id'], 'month' => $record['month']],
                    'error' => 'O mês foi arquivado, mas o compartilhamento não pôde ser confirmado. Consulte seu histórico antes de tentar novamente.']);
            }
        }
        $reply(200, ['ok' => true, 'record' => $record]);
    }
    if ($action === 'share') {
        $id = AnnualSelection::id($input['id'] ?? null);
        $month = AnnualSelection::month($input['month'] ?? null);
        // Only the owner publishes a private archive. No owner/path override is accepted.
        if (isset($input['owner']) || isset($input['archive_ref']) || isset($input['path'])) throw new InvalidArgumentException('Selecione uma revisão própria para compartilhar.');
        $reply(200, ['ok' => true, 'record' => ArchiveAccess::publish($owner, $id, $month, $configuration)]);
    }
    if ($action === 'preview') {
        $selection = AnnualSelection::parse($input['payload'] ?? null);
        $records = AnnualSelection::records($selection, $owner, $configuration);
        $report = AnnualReport::build($records, $selection['months']);
        $reply(200, ['ok' => true, 'report' => $report, 'analytics' => AnnualAnalytics::build($report)]);
    }
    if ($action === 'prepare_activity') {
        $id = AnnualSelection::id($input['id'] ?? null);
        $month = AnnualSelection::month($input['month'] ?? null);
        // One pinned revision per request. The store enforces global admission
        // across users and a bounded streaming read of archived evidence only.
        $reference = ArchiveAccess::resolve($owner, $id, $month, ArchiveAccess::reference($input['archive_ref'] ?? null), $configuration);
        $activity = MonthlyArchiveStore::prepareActivity($reference['owner'], $id, $month);
        $reply(200, ['ok' => true, 'activity' => $activity]);
    }
    $id = AnnualSelection::id($input['id'] ?? null);
    $month = AnnualSelection::month($input['month'] ?? null);
    $reference = ArchiveAccess::resolve($owner, $id, $month, ArchiveAccess::reference($input['archive_ref'] ?? null), $configuration);
    $download = MonthlyArchiveStore::openSnapshotDownload($reference['owner'], $id, $month);
    // Raw archived evidence remains accessible even after calculation rules change.
    // No body is sent until the complete hash and handle identity have passed.
    try {
        $streamingDownload = true;
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="SES-arquivado-' . $month . '-' . substr($id, 0, 12) . '.json"');
        header('Content-Length: ' . $download['bytes']);
        $sent = @fpassthru($download['handle']);
        if ($sent !== $download['bytes']) throw new RuntimeException('Não foi possível concluir o download da evidência arquivada.', 503);
    } finally { fclose($download['handle']); }
} catch (InvalidArgumentException $e) {
    $reply(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    if ($streamingDownload) {
        // Never append an error object to a partially transmitted evidence file.
        if (headers_sent()) exit;
        while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
        header_remove('Content-Length'); header_remove('Content-Disposition');
    }
    $code = in_array($e->getCode(), [403, 404, 409, 503], true) ? $e->getCode() : 400;
    // Store messages with these codes are deliberately safe for the interface.
    $message = in_array($e->getCode(), [403, 404, 409, 503], true)
        ? $e->getMessage() : 'O rascunho não está disponível para arquivamento. Confira a versão exibida e gere novamente se necessário.';
    $reply($code, ['ok' => false, 'error' => $message]);
} catch (Throwable $e) {
    if ($streamingDownload) {
        if (headers_sent()) exit;
        while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
        header_remove('Content-Length'); header_remove('Content-Disposition');
    }
    $reply(500, ['ok' => false, 'error' => 'Não foi possível concluir a operação no arquivo de relatórios. Nenhum relatório mensal foi recalculado.']);
}
