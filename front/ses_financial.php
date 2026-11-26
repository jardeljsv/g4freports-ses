<?php

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Ses\FinanceSettings;
use GlpiPlugin\G4freports\Ses\RegisterHistory;

@ini_set('display_errors', '0');
header('Cache-Control: no-store');
$cfg = Config::merged();
if (Security::currentUserId() <= 0 || !Security::canUse($cfg) || !Security::canConfigure()) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'É necessária permissão de configuração para alterar os dados financeiros.']);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    g4fr_json_response(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
$nonce = $_POST['_ses_nonce'] ?? null;
$expected = $_SESSION['g4freports_ses_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expected) || $expected === '' || !hash_equals($expected, $nonce)) {
    g4fr_json_response(403, ['ok' => false, 'error' => 'Sessão expirada. Recarregue a área SES.']);
}
$csrf = class_exists('Session') && method_exists('Session', 'getNewCSRFToken') ? Session::getNewCSRFToken() : '';
$lock = '';
$code = 200;
$result = [];
try {
    if (($_POST['action'] ?? '') !== 'save') throw new InvalidArgumentException('Operação financeira desconhecida.');
    $raw = $_POST['config'] ?? null;
    $revision = $_POST['revision'] ?? null;
    if (!is_string($raw) || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
        throw new InvalidArgumentException('Conteúdo ou versão da configuração financeira inválidos.');
    }
    // Resolve the same contractual defaults used when reading the saved form.
    // Explicit contract_total:null keeps an intentionally empty custom baseline.
    $next = FinanceSettings::fromSettings([FinanceSettings::CONFIG_KEY => $raw]);
    $lock = RegisterHistory::lock();
    $cfg = Config::merged();
    $current = FinanceSettings::fromSettings($cfg);
    if (!hash_equals(FinanceSettings::revision($current), $revision)) {
        $code = 409;
        $result = ['ok' => false, 'error' => 'Os dados financeiros foram alterados em outra sessão. Suas alterações continuam na tela; confira a versão salva antes de editar novamente.'];
    } else {
        Config::save([FinanceSettings::CONFIG_KEY => FinanceSettings::json($next)]);
        $saved = FinanceSettings::fromSettings(Config::merged());
        if (!hash_equals(FinanceSettings::revision($next), FinanceSettings::revision($saved))) {
            throw new RuntimeException('O GLPI não confirmou os dados financeiros salvos. Recarregue a página para conferir.');
        }
        $result = ['ok' => true, 'config' => $saved, 'revision' => FinanceSettings::revision($saved)];
    }
} catch (InvalidArgumentException $e) {
    $code = 400; $result = ['ok' => false, 'error' => $e->getMessage()];
} catch (RuntimeException $e) {
    $code = 500; $result = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    $code = 500; $result = ['ok' => false, 'error' => 'Não foi possível salvar os dados financeiros. Suas alterações continuam na tela.'];
} finally {
    if ($lock !== '') {
        try { RegisterHistory::unlock($lock); }
        catch (Throwable $e) { /* A confirmed save must not become an error on lock cleanup. */ }
    }
}
g4fr_json_response($code, array_merge($result, ['csrf_token' => $csrf]));
