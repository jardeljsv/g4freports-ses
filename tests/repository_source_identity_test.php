<?php
/** Executes Repository::extract with empty synthetic sources; no database is contacted. */
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
}
namespace GlpiPlugin\G4freports {
    final class Config
    {
        public static array $values = [];
        public static int $reads = 0;
        public static function merged(): array { self::$reads++; return self::$values; }
    }
}
namespace GlpiPlugin\G4freports\Database {
    final class MysqlClient
    {
        public static array $profiles = [];
        public static array $queries = [];
        public static $onRead = null;
        private string $source;
        public function __construct(array $profile)
        {
            $this->source = $profile['source'];
            self::$profiles[$this->source] = $profile;
        }
        public function fetchAll(string $sql, array $params = [], int $limit = 100000): array
        {
            self::$queries[] = [$this->source, $sql, $params, $limit];
            if (self::$onRead !== null) {
                $callback = self::$onRead;
                self::$onRead = null;
                $callback();
            }
            return [];
        }
    }
}
namespace GlpiPlugin\G4freports\Ses {
    final class SelectionCatalog
    {
        public static function resolve($client, array $input, array &$warnings): array { return []; }
    }
    final class SlaDataSource
    {
        public static function load($client, array $tickets, array $input, array &$warnings): array
        {
            return ['capabilities' => []];
        }
    }
}
namespace {
    require dirname(__DIR__) . '/src/Database/ConnectionConfig.php';
    require dirname(__DIR__) . '/src/Ses/Repository.php';

    use GlpiPlugin\G4freports\Config;
    use GlpiPlugin\G4freports\Database\MysqlClient;
    use GlpiPlugin\G4freports\Ses\Repository;

    $count = 0;
    $check = static function (bool $ok, string $message) use (&$count): void {
        $count++;
        if (!$ok) throw new \RuntimeException($message);
    };
    $config = [
        'ses_db_enabled' => 1, 'ses_db_host' => 'glpi-source.invalid', 'ses_db_port' => '3306',
        'ses_db_name' => 'fixture_glpi', 'ses_db_user' => 'fixture_reader',
        'callcenter_db_enabled' => 1, 'callcenter_db_host' => 'calls-source.invalid',
        'callcenter_db_port' => '2210', 'callcenter_db_name' => 'fixture_calls',
        'callcenter_db_user' => 'fixture_calls_reader',
    ];
    $input = ['month' => '2026-01', 'entity_id' => 1, 'group_ids' => [], 'queue_filter' => 'fixture'];
    $run = static function (array $values, $onRead = null) use ($input): array {
        Config::$values = $values;
        Config::$reads = 0;
        MysqlClient::$profiles = [];
        MysqlClient::$queries = [];
        MysqlClient::$onRead = $onRead;
        return Repository::extract($input);
    };
    $baseline = $run($config);
    $queries = MysqlClient::$queries;
    $identity = $baseline['source_identity'];
    $check(array_keys($identity) === ['glpi', 'callcenter'], 'Both source identities are explicit.');
    foreach (['glpi' => 'ses', 'callcenter' => 'callcenter'] as $name => $source) {
        $profile = MysqlClient::$profiles[$source];
        $expected = hash('sha256', json_encode([
            'host' => $profile['host'], 'port' => $profile['port'], 'database' => $profile['database'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $check($identity[$name] === $expected, 'Identity describes the actual normalized client profile.');
        $check((bool)preg_match('/\A[0-9a-f]{64}\z/D', $identity[$name]), 'Only a SHA-256 fingerprint is exposed.');
    }
    $serialized = json_encode($baseline, JSON_THROW_ON_ERROR);
    foreach ($config as $key => $value) {
        if (preg_match('/_(?:host|name|user)$/D', $key)) {
            $check(strpos($serialized, (string)$value) === false, 'Raw connection details do not enter extraction data.');
        }
    }
    $changedDuringRead = $run($config, static function (): void {
        Config::$values['ses_db_host'] = 'new-glpi-source.invalid';
        Config::$values['callcenter_db_name'] = 'new_calls';
    });
    $check($changedDuringRead['source_identity'] === $identity, 'A concurrent configuration change cannot relabel extracted data.');
    $check(Config::$reads === 1, 'Extraction does not reread configuration to label its data.');

    $normalized = $run(array_replace($config, ['ses_db_host' => ' glpi-source.invalid ', 'ses_db_port' => '03306']));
    $check($normalized['source_identity'] === $identity, 'Equivalent normalized connection values keep the same identity.');
    foreach (['glpi' => 'ses_db_', 'callcenter' => 'callcenter_db_'] as $name => $prefix) {
        foreach (['host' => 'different-source.invalid', 'port' => 12345, 'name' => 'different_db'] as $field => $value) {
            $result = $run(array_replace($config, [$prefix . $field => $value]));
            $other = $name === 'glpi' ? 'callcenter' : 'glpi';
            $check($result['source_identity'][$name] !== $identity[$name], 'Every source location component affects its identity.');
            $check($result['source_identity'][$other] === $identity[$other], 'Changing one source leaves the other identity intact.');
            $check(MysqlClient::$queries === $queries, 'Source identity metadata adds no queries or extraction rule changes.');
        }
    }
    $env = 'G4FR_SOURCE_IDENTITY_FIXTURE_SECRET';
    $originalEnv = getenv($env);
    try {
        putenv($env . '=fixture-secret-one');
        $credentials = array_replace($config, [
            'ses_db_user' => 'rotated_reader', 'ses_db_password_env' => $env,
            'callcenter_db_user' => 'rotated_calls_reader', 'callcenter_db_password_env' => $env,
            'ses_db_read_timeout' => 7, 'callcenter_db_tls_mode' => 'verify',
        ]);
        $first = $run($credentials);
        putenv($env . '=fixture-secret-two');
        $second = $run($credentials);
        $check($first['source_identity'] === $identity && $second['source_identity'] === $identity,
            'Credential rotation and connection transport settings do not change source identity.');
        $check(strpos(json_encode($second, JSON_THROW_ON_ERROR), 'fixture-secret') === false,
            'Resolved secrets never enter extraction data.');
    } finally {
        putenv($originalEnv === false ? $env : $env . '=' . $originalEnv);
    }
    echo 'Repository source identity: ' . $count . " assertions passed (synthetic sources).\n";
}
