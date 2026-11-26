<?php
// Executes the real API endpoint with isolated GLPI session/config transports.
// No native GLPI installation or operational database is used in this test.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === '--request') {
    $fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    define('UPDATE', 2);
    class CommonGLPI {}
    class Config extends CommonGLPI {
        public static function getConfigurationValues($context): array {
            global $fixture;
            // Token refresh must not depend on otherwise-invalid saved forms.
            return ['enabled' => $fixture['enabled'] ?? 1, 'ses_test_numbers_json' => '{invalid', 'ses_staff_register_json' => '{invalid'];
        }
        public static function setConfigurationValues($context, $values): void { throw new RuntimeException('Token refresh must not save configuration.'); }
    }
    class Session {
        public static function getLoginUserID(): int { global $fixture; return $fixture['user'] ?? 42; }
        public static function haveRight($right, $level): bool { return false; }
        public static function getNewCSRFToken(): string { global $fixture; return $fixture['token'] ?? 'fresh-native-token'; }
    }
    $DB = new stdClass();
    $_SESSION = ['glpiID' => Session::getLoginUserID(), 'g4freports_ses_nonce' => 'fixture-nonce'];
    $_SERVER['REQUEST_METHOD'] = $fixture['method'] ?? 'GET';
    $_GET = ['action' => 'get_csrf', 'payload' => json_encode(['_ses_nonce' => $fixture['nonce'] ?? 'fixture-nonce'])];
    register_shutdown_function(static function (): void { fwrite(STDERR, (string)http_response_code()); });
    require dirname(__DIR__) . '/front/ses_api.php';
    exit(99);
}
$checks = 0;
foreach ([
    [[], 200, true],
    [['user' => 0], 403, false],
    [['enabled' => 0], 403, false],
    [['nonce' => 'wrong'], 403, false],
    [['method' => 'POST'], 405, false],
    [['token' => ''], 500, false],
] as [$fixture, $status, $ok]) {
    $command = [PHP_BINARY];
    if (!php_ini_loaded_file()) {
        array_push($command, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'));
        foreach (['ctype', 'iconv', 'mbstring'] as $extension) {
            if (extension_loaded($extension) && is_file(ini_get('extension_dir') . '/' . $extension . '.so')) array_push($command, '-d', 'extension=' . $extension);
        }
    }
    array_push($command, __FILE__, '--request');
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start endpoint fixture.');
    fwrite($pipes[0], json_encode($fixture)); fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $actualStatus = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $actualStatus !== (string)$status) throw new RuntimeException('Unexpected endpoint status: ' . $actualStatus . ' / ' . $body);
    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($result['ok'] ?? null) !== $ok || ($ok && ($result['csrf_token'] ?? '') !== 'fresh-native-token') || (!$ok && isset($result['csrf_token']))) throw new RuntimeException('Unexpected token response.');
    $checks++;
}
echo 'SES token endpoint: ' . $checks . " authorization/nonce/method/token cases passed; read-only users supported without parsing register contents.\n";
