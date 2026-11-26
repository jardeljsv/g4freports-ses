<?php
// Database diagnostics deliberately use SAVED settings. Only the source name
// travels in the GET URL, preserving the deployment's proxy-compatible flow.

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;
use GlpiPlugin\G4freports\Database\ConnectionConfig;
use GlpiPlugin\G4freports\Database\ConnectionException;
use GlpiPlugin\G4freports\Database\MysqlClient;

@ini_set('display_errors', '0');
@set_time_limit(45);

if (!Security::canConfigure()) {
    g4fr_json_response(403, ['ok' => false, 'reason' => 'access_denied', 'message' => 'Acesso negado.']);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    g4fr_json_response(405, ['ok' => false, 'reason' => 'method_not_allowed', 'message' => 'Método não permitido.']);
}

$source = $_GET['source'] ?? '';
if (!is_string($source) || !in_array($source, ['glpi', 'callcenter'], true)) {
    g4fr_json_response(400, ['ok' => false, 'reason' => 'invalid_source', 'message' => 'Selecione a conexão GLPI ou Callcenter.']);
}

try {
    $connection = ConnectionConfig::fromConfig($source, Config::merged());
    if (empty($connection['enabled'])) {
        g4fr_json_response(200, ['ok' => false, 'reason' => 'connection_disabled', 'message' => 'Habilite esta conexão e salve as configurações antes de testar.']);
    }
    // No changes to GLPI's own database handle or current API profile.
    $client = new MysqlClient($connection);
    $result = $client->test($source);
    $featuresOk = ($result['query_features']['status'] ?? '') === 'available';
    $result['reason'] = !empty($result['ok']) ? 'connected' : ($featuresOk ? 'schema_unavailable' : 'query_features_unavailable');
    $result['message'] = !empty($result['ok'])
        ? 'Conexão, estrutura e recursos SQL necessários verificados. Este teste não mede o desempenho da extração.'
        : ($featuresOk
            ? 'Conexão realizada, mas algumas tabelas ou colunas necessárias não estão acessíveis. Consulte os detalhes.'
            : 'Conexão realizada, mas os recursos SQL necessários à extração não foram confirmados. Consulte os detalhes.');
    g4fr_json_response(200, $result);
} catch (ConnectionException $e) {
    g4fr_json_response(200, [
        'ok' => false,
        'reason' => $e->getReason(),
        'message' => $e->getMessage(),
        'mysql_code' => $e->getMysqlCode(),
    ]);
} catch (InvalidArgumentException $e) {
    g4fr_json_response(200, ['ok' => false, 'reason' => 'invalid_configuration', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    // Never expose driver/GLPI exceptions: these may contain connection data.
    g4fr_json_response(200, [
        'ok' => false,
        'reason' => 'configuration_unavailable',
        'message' => 'Não foi possível carregar a conexão com segurança. Revise a configuração salva, a senha ou a variável de ambiente e a chave de criptografia do GLPI.',
    ]);
}
