<?php

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\Settings;
use GlpiPlugin\G4freports\Ses\Repository;
use GlpiPlugin\G4freports\Ses\Calculator;
use GlpiPlugin\G4freports\Ses\SnapshotStore;
use GlpiPlugin\G4freports\Ses\MonthlyExport;
use GlpiPlugin\G4freports\Ses\InputStore;
use GlpiPlugin\G4freports\Database\ConnectionException;

@ini_set('display_errors', '0');
@set_time_limit(120);
$cfg = Config::merged();
$owner = Security::currentUserId();
if ($owner <= 0 || !Security::canUse($cfg)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Acesso negado.']);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    g4fr_json_response(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
$input = g4fr_parse_request_payload();
$action = $_GET['action'] ?? '';
$nonce = $input['_ses_nonce'] ?? '';
$expected = $_SESSION['g4freports_ses_nonce'] ?? '';
if (!is_string($nonce) || !is_string($expected) || $expected === '' || !hash_equals($expected, $nonce)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Sessão expirada. Recarregue a área SES e tente novamente.']);
}
if ($action === 'get_csrf') {
    // Available to every authorized SES reader, including table export users.
    // This only issues a native token; it never parses or changes registers.
    header('Cache-Control: no-store');
    $csrf = class_exists('Session') && method_exists('Session', 'getNewCSRFToken') ? Session::getNewCSRFToken() : '';
    if (!is_string($csrf) || $csrf === '') {
        g4fr_json_response(500, ['ok' => false, 'error' => 'Não foi possível validar a sessão do GLPI.']);
    }
    g4fr_json_response(200, ['ok' => true, 'csrf_token' => $csrf]);
}
if ($action === 'get_settings') {
    if (!Security::canConfigure()) g4fr_json_response(403, ['ok' => false, 'error' => 'Acesso administrativo necessário.']);
    try {
        $numbers = Settings::testNumbers($cfg['ses_test_numbers_json'] ?? '[]');
        $csrf = class_exists('Session') && method_exists('Session', 'getNewCSRFToken') ? Session::getNewCSRFToken() : '';
        g4fr_json_response(200, ['ok' => true, 'test_numbers' => $numbers, 'csrf_token' => $csrf]);
    } catch (Throwable $e) {
        g4fr_json_response(400, ['ok' => false, 'error' => 'A lista de números de teste salva está inválida.']);
    }
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    if ($action === 'input_start') {
        $token = InputStore::start($owner, (int)($input['size_bytes'] ?? 0), (int)($input['total_chunks'] ?? 0));
        g4fr_json_response(200, ['ok' => true, 'upload_token' => $token]);
    }
    if ($action === 'input_chunk' || $action === 'input_finish') {
        $token = $input['upload_token'] ?? '';
        if (!is_string($token)) throw new InvalidArgumentException('Identificador de envio inválido.');
        if ($action === 'input_chunk') {
            $chunk = $input['data'] ?? '';
            if (!is_string($chunk)) throw new InvalidArgumentException('Fragmento inválido.');
            InputStore::chunk($token, $owner, (int)($input['index'] ?? -1), $chunk);
            g4fr_json_response(200, ['ok' => true]);
        }
        InputStore::finish($token, $owner);
        g4fr_json_response(200, ['ok' => true, 'input_token' => $token]);
    }
    if (isset($input['input_token'])) {
        if (!is_string($input['input_token'])) throw new InvalidArgumentException('Identificador de envio inválido.');
        $input = InputStore::take($input['input_token'], $owner);
    }
    if ($action === 'generate') {
        $normalized = Settings::normalizeInput($input, $cfg);
        $data = Repository::extract($normalized);
        $snapshot = Calculator::build($normalized, $data);
        // Provenance comes from the profiles actually used, never archive-time settings.
        $snapshot['source_identity'] = $data['source_identity'] ?? [];
        $snapshot = MonthlyExport::stamp($snapshot);
        // The snapshot retains the audit evidence needed by the report, not
        // the full raw history. Release that history before serializing it.
        unset($data);
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        $token = SnapshotStore::save($snapshot, $owner);
        g4fr_json_response(200, ['ok' => true, 'token' => $token, 'snapshot' => $snapshot,
            'draft_revision' => SnapshotStore::fingerprint($snapshot)]);
    }
    if ($action === 'update_manual') {
        $token = $input['token'] ?? '';
        if (!is_string($token)) throw new InvalidArgumentException('Identificador de relatório inválido.');
        $snapshot = SnapshotStore::updateNarrative($token, $owner, $input);
        g4fr_json_response(200, ['ok' => true, 'token' => $token, 'snapshot' => $snapshot,
            'draft_revision' => SnapshotStore::fingerprint($snapshot)]);
    }
    g4fr_json_response(400, ['ok' => false, 'error' => 'Ação desconhecida.']);
} catch (ConnectionException $e) {
    g4fr_json_response(200, ['ok' => false, 'reason' => $e->getReason(), 'error' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    g4fr_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    g4fr_json_response(200, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    g4fr_json_response(500, ['ok' => false, 'error' => 'Não foi possível concluir o relatório. Nenhum relatório parcial foi disponibilizado. Verifique as conexões e tente novamente.']);
}
