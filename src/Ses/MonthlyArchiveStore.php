<?php

namespace GlpiPlugin\G4freports\Ses;

/** Owner-scoped immutable monthly bundles. Only summaries are read for collections. */
final class MonthlyArchiveStore
{
    public const MAX_SNAPSHOT_BYTES = 67108864;
    public const MAX_SUMMARY_BYTES = 1048576;
    public const MAX_REVISIONS_PER_MONTH = 50;
    public const MAX_CATALOG_BYTES = 4194304;
    public const MAX_ACTIVITY_BYTES = 65536;
    private const MAX_MANIFEST_BYTES = 16384;
    private const MAX_DIRECTORY_ENTRIES = 256;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public static function archive(array $snapshot, int $owner, string $expectedRevision): array
    {
        self::owner($owner);
        self::id($expectedRevision);
        if (($snapshot['schema_version'] ?? null) !== 2) {
            throw new \InvalidArgumentException('Esta versão de extração não é compatível com o arquivo mensal.', 400);
        }
        $snapshotMonth = self::month($snapshot['period']['month'] ?? null);
        if (isset($snapshot['input']['month']) && $snapshot['input']['month'] !== $snapshotMonth) {
            throw new \InvalidArgumentException('A competência da extração está inconsistente.', 400);
        }
        // Encode before acquiring the month lock; the snapshot can be large.
        $snapshotJson = self::encode($snapshot);
        if (strlen($snapshotJson) > self::MAX_SNAPSHOT_BYTES) {
            throw new \InvalidArgumentException('O relatório excede o limite de 64 MiB do arquivo mensal.', 400);
        }
        $revision = hash('sha256', $snapshotJson);
        if (!hash_equals($revision, $expectedRevision)) {
            throw new \RuntimeException('O rascunho mudou desde a prévia. Atualize a conferência antes de arquivar.', 409);
        }
        $summary = MonthlySummary::fromSnapshot($snapshot);
        $month = self::month($summary['month'] ?? null);
        $summaryJson = self::encode($summary);
        if (strlen($summaryJson) > self::MAX_SUMMARY_BYTES) {
            throw new \InvalidArgumentException('O resumo excede o limite de 1 MiB do arquivo mensal.', 400);
        }
        $directory = self::monthDirectory($owner, $month, true);
        $lock = self::lock($directory);
        $staging = null;
        try {
            $destination = $directory . '/' . $revision;
            if (file_exists($destination) || is_link($destination)) {
                $existing = self::record($owner, $revision, $month);
                // A retry must not declare a damaged evidence file successfully archived.
                $manifest = self::manifest($destination, $owner, $revision, $month);
                self::verifyFile($destination . '/snapshot.json', $manifest['snapshot']);
                return $existing;
            }
            if (count(self::revisionNames($directory)) >= self::MAX_REVISIONS_PER_MONTH) {
                throw new \RuntimeException('Esta competência já possui 50 revisões arquivadas. Nenhuma revisão foi removida.', 409);
            }
            $archivedAt = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM);
            $catalog = self::catalog($snapshot, $summary);
            $manifest = [
                'archive_schema' => 1, 'id' => $revision, 'owner' => $owner, 'month' => $month,
                'archived_at' => $archivedAt, 'snapshot_revision' => $revision,
                'snapshot' => ['sha256' => $revision, 'bytes' => strlen($snapshotJson)],
                'summary' => ['sha256' => hash('sha256', $summaryJson), 'bytes' => strlen($summaryJson)],
                'catalog' => $catalog,
            ];
            $manifestJson = self::encode($manifest);
            if (strlen($manifestJson) > self::MAX_MANIFEST_BYTES) throw self::storageError();
            $staging = $directory . '/.tmp-' . bin2hex(random_bytes(16));
            self::ensureDirectory($staging, true);
            self::writeNew($staging . '/snapshot.json', $snapshotJson);
            self::writeNew($staging . '/summary.json', $summaryJson);
            self::writeNew($staging . '/manifest.json', $manifestJson);
            self::writeNew($staging . '/manifest.sha256', hash('sha256', $manifestJson));
            // Publish the complete bundle together. Staging directories never appear in listings.
            self::ensureDirectory($directory, false);
            if (!@rename($staging, $destination)) throw self::storageError();
            $staging = null;
            return ['id' => $revision, 'month' => $month, 'archived_at' => $archivedAt,
                'snapshot_revision' => $revision, 'summary' => $summary];
        } finally {
            if ($staging !== null) self::discardStaging($staging);
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Metadata only: no snapshot, narrative or full summary is loaded by a year listing. */
    public static function listYear(int $owner, int $year): array
    {
        self::owner($owner);
        if ($year < 2000 || $year > 2100) throw new \InvalidArgumentException('Ano inválido para o arquivo mensal.', 400);
        $records = [];
        for ($number = 1; $number <= 12; $number++) {
            $month = sprintf('%04d-%02d', $year, $number);
            $directory = self::monthDirectory($owner, $month, false);
            if ($directory === null) continue;
            foreach (self::revisionNames($directory) as $id) {
                $manifest = self::manifest($directory . '/' . $id, $owner, $id, $month);
                $records[] = self::metadata($manifest);
            }
        }
        usort($records, static function (array $a, array $b): int {
            return strcmp($a['month'], $b['month']) ?: strcmp($b['archived_at'], $a['archived_at']) ?: strcmp($a['id'], $b['id']);
        });
        if (strlen(self::encode($records)) > self::MAX_CATALOG_BYTES) throw self::storageError();
        return $records;
    }

    public static function loadSummary(int $owner, string $id, string $month): array
    {
        return self::record($owner, $id, $month);
    }

    /** Small verified catalogue record; no full snapshot or summary is decoded. */
    public static function loadMetadata(int $owner, string $id, string $month): array
    {
        return self::metadata(self::manifest(self::bundle($owner, $id, $month), $owner, $id, $month));
    }

    /** Original serialized snapshot, used only for an explicit evidence JSON download. */
    public static function loadSnapshot(int $owner, string $id, string $month): array
    {
        $directory = self::bundle($owner, $id, $month);
        $manifest = self::manifest($directory, $owner, $id, $month);
        $json = self::readFile($directory . '/snapshot.json', self::MAX_SNAPSHOT_BYTES, $manifest['snapshot']);
        $snapshot = self::decode($json);
        if (($snapshot['schema_version'] ?? null) !== 2 || ($snapshot['period']['month'] ?? null) !== $month) throw self::storageError();
        return $snapshot;
    }

    /**
     * Verify immutable evidence before download, retaining one handle for the
     * verified bytes. The archive writer already validated its snapshot schema.
     * @return array{handle:resource,bytes:int}
     */
    public static function openSnapshotDownload(int $owner, string $id, string $month): array
    {
        $directory = self::bundle($owner, $id, $month);
        $manifest = self::manifest($directory, $owner, $id, $month);
        $expected = $manifest['snapshot']; $path = $directory . '/snapshot.json';
        self::fileStat($path, $expected['bytes']);
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw self::storageError();
        try {
            self::sameFile($path, $handle, $expected['bytes']);
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $handle, $expected['bytes'] + 1);
            if ($read !== $expected['bytes'] || !hash_equals($expected['sha256'], hash_final($hash))) throw self::storageError();
            self::sameFile($path, $handle, $expected['bytes']);
            if (!rewind($handle)) throw self::storageError();
            return ['handle' => $handle, 'bytes' => $expected['bytes']];
        } catch (\Throwable $error) { fclose($handle); throw $error; }
    }

    /** Compact cache only: annual reads never open or decode raw monthly evidence. */
    public static function loadActivity(int $owner, string $id, string $month): ?array
    {
        $directory = self::bundle($owner, $id, $month);
        self::manifest($directory, $owner, $id, $month);
        // Optional derived data must never make the original monthly values inaccessible.
        // Explicit preparation still reports a damaged cache instead of silently replacing it.
        try { return self::activity($directory, $owner, $id, $month); }
        catch (\RuntimeException $error) { return null; }
    }

    /** Prepare exactly one revision; original snapshot, summary and manifest remain immutable. */
    public static function prepareActivity(int $owner, string $id, string $month): array
    {
        $directory = self::bundle($owner, $id, $month);
        $manifest = self::manifest($directory, $owner, $id, $month);
        $cached = self::activity($directory, $owner, $id, $month);
        if ($cached !== null) return $cached;
        // Shared by every owner/month. A browser lock cannot bound other users or workers.
        $lock = self::lock(dirname($directory, 3), '.activity.lock',
            'Outro detalhe diário está sendo preparado. Tente novamente após a conclusão.', 409);
        $temporary = null;
        try {
            $cached = self::activity($directory, $owner, $id, $month);
            if ($cached !== null) return $cached;
            $path = $directory . '/snapshot.json';
            $stat = self::fileStat($path, $manifest['snapshot']['bytes']);
            $handle = @fopen($path, 'rb');
            if ($handle === false) throw self::storageError();
            $projection = new MonthlyActivity($month, $id);
            try {
                self::sameFile($path, $handle, $stat['size']);
                $metadata = MonthlyActivityReader::read($handle, [$projection, 'add']);
                self::sameFile($path, $handle, $stat['size']);
            } finally { fclose($handle); }
            if (($metadata['bytes'] ?? null) !== $manifest['snapshot']['bytes']
                || !is_string($metadata['sha256'] ?? null) || !hash_equals($id, $metadata['sha256'])) throw self::storageError();
            $summary = self::decode(self::readFile($directory . '/summary.json', self::MAX_SUMMARY_BYTES, $manifest['summary']));
            if (($summary['schema_version'] ?? null) !== 1 || ($summary['month'] ?? null) !== $month) throw self::storageError();
            $activity = $projection->finish($metadata, $summary);
            if (!MonthlyActivity::valid($activity, $id, $month)) throw self::storageError();
            $payload = self::encode($activity);
            $encoded = self::encode(['cache_schema' => 1, 'projection_version' => MonthlyActivity::VERSION,
                'owner' => $owner, 'revision' => $id, 'month' => $month,
                'payload_sha256' => hash('sha256', $payload), 'payload' => $payload]);
            if (strlen($encoded) > self::MAX_ACTIVITY_BYTES) throw self::storageError();
            $destination = self::activityPath($directory);
            $temporary = $directory . '/.activity-' . bin2hex(random_bytes(16)) . '.tmp';
            self::writeNew($temporary, $encoded);
            self::ensureDirectory($directory, false);
            // The global admission lock also serializes cache publication. Never replace a cache.
            if (file_exists($destination) || is_link($destination) || !@rename($temporary, $destination)) throw self::storageError();
            $temporary = null;
            return $activity;
        } finally {
            if ($temporary !== null) @unlink($temporary);
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function activityPath(string $directory): string
    {
        return $directory . '/activity-v' . MonthlyActivity::VERSION . '.json';
    }

    private static function activity(string $directory, int $owner, string $id, string $month): ?array
    {
        $path = self::activityPath($directory);
        if (!file_exists($path) && !is_link($path)) return null;
        $cache = self::decode(self::readFile($path, self::MAX_ACTIVITY_BYTES));
        if (($cache['cache_schema'] ?? null) !== 1 || ($cache['projection_version'] ?? null) !== MonthlyActivity::VERSION
            || ($cache['owner'] ?? null) !== $owner || ($cache['revision'] ?? null) !== $id || ($cache['month'] ?? null) !== $month
            || !is_string($cache['payload'] ?? null) || !is_string($cache['payload_sha256'] ?? null)
            || !hash_equals(hash('sha256', $cache['payload']), $cache['payload_sha256'])) throw self::storageError();
        $value = self::decode($cache['payload']);
        if (!MonthlyActivity::valid($value, $id, $month)) throw self::storageError();
        return $value;
    }

    private static function record(int $owner, string $id, string $month): array
    {
        $directory = self::bundle($owner, $id, $month);
        $manifest = self::manifest($directory, $owner, $id, $month);
        $summary = self::decode(self::readFile($directory . '/summary.json', self::MAX_SUMMARY_BYTES, $manifest['summary']));
        if (($summary['schema_version'] ?? null) !== 1 || ($summary['month'] ?? null) !== $month) throw self::storageError();
        return ['id' => $id, 'month' => $month, 'archived_at' => $manifest['archived_at'],
            'snapshot_revision' => $manifest['snapshot_revision'], 'summary' => $summary];
    }

    private static function catalog(array $snapshot, array $summary): array
    {
        $input = $snapshot['input'] ?? [];
        $groups = is_array($input['group_ids'] ?? null) ? count($input['group_ids']) : 0;
        $scope = 'Entidade ' . (string)($input['entity_id'] ?? '?') . ' · '
            . ($groups ? $groups . ' grupo(s) selecionado(s)' : 'Todos os grupos')
            . ' · Fila ' . self::shortText($input['queue_filter'] ?? '', 100);
        $statuses = [];
        foreach ($summary['metrics'] ?? [] as $metric) {
            if (is_array($metric) && preg_match('/\AINS(?:[1-9]|1[0-2])\z/D', (string)($metric['id'] ?? ''))) {
                $statuses[$metric['id']] = self::shortText($metric['status'] ?? '', 32);
            }
        }
        return ['generated_at' => self::shortText($summary['generated_at'] ?? '', 80),
            'rules_version' => self::shortText($summary['rules_version'] ?? '', 128),
            'title' => self::shortText($input['report_title'] ?? 'Relatório mensal SES', 180),
            'scope_label' => self::shortText($scope, 400),
            'source_status' => ($summary['source_identity']['status'] ?? '') === 'known' ? 'known' : 'unknown',
            'financial_complete' => ($summary['financial']['complete'] ?? false) === true,
            'metric_statuses' => $statuses];
    }

    private static function metadata(array $manifest): array
    {
        $catalog = $manifest['catalog'];
        return ['id' => $manifest['id'], 'month' => $manifest['month'], 'archived_at' => $manifest['archived_at'],
            'snapshot_revision' => $manifest['snapshot_revision'], 'generated_at' => $catalog['generated_at'],
            'rules_version' => $catalog['rules_version'], 'title' => $catalog['title'], 'scope_label' => $catalog['scope_label'],
            'source_status' => $catalog['source_status'], 'financial_complete' => $catalog['financial_complete'],
            'metric_statuses' => $catalog['metric_statuses']];
    }

    private static function manifest(string $directory, int $owner, string $id, string $month): array
    {
        self::ensureDirectory($directory, false);
        $json = self::readFile($directory . '/manifest.json', self::MAX_MANIFEST_BYTES);
        $digest = self::readFile($directory . '/manifest.sha256', 64);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $digest) || !hash_equals(hash('sha256', $json), $digest)) throw self::storageError();
        $manifest = self::decode($json);
        if (($manifest['archive_schema'] ?? null) !== 1 || ($manifest['owner'] ?? null) !== $owner
            || ($manifest['id'] ?? null) !== $id || ($manifest['month'] ?? null) !== $month
            || ($manifest['snapshot_revision'] ?? null) !== $id
            || !is_string($manifest['archived_at'] ?? null) || strlen($manifest['archived_at']) > 40
            || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/D', $manifest['archived_at'])) throw self::storageError();
        foreach (['snapshot' => self::MAX_SNAPSHOT_BYTES, 'summary' => self::MAX_SUMMARY_BYTES] as $key => $limit) {
            $file = $manifest[$key] ?? null;
            if (!is_array($file) || !is_int($file['bytes'] ?? null) || $file['bytes'] < 2 || $file['bytes'] > $limit
                || !is_string($file['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256'])) throw self::storageError();
            self::fileStat($directory . '/' . $key . '.json', $file['bytes']);
        }
        if (!hash_equals($id, $manifest['snapshot']['sha256'])) throw self::storageError();
        $catalog = $manifest['catalog'] ?? null;
        if (!is_array($catalog)) throw self::storageError();
        foreach (['generated_at' => 320, 'rules_version' => 512, 'title' => 720, 'scope_label' => 1600] as $key => $limit) {
            if (!is_string($catalog[$key] ?? null) || strlen($catalog[$key]) > $limit) throw self::storageError();
        }
        if (!in_array($catalog['source_status'] ?? null, ['known', 'unknown'], true)
            || !is_bool($catalog['financial_complete'] ?? null) || !is_array($catalog['metric_statuses'] ?? null)
            || count($catalog['metric_statuses']) > 12) throw self::storageError();
        foreach ($catalog['metric_statuses'] as $metric => $status) {
            if (!preg_match('/\AINS(?:[1-9]|1[0-2])\z/D', (string)$metric)
                || !in_array($status, ['met', 'unmet', 'pending', 'provisional', 'not_applicable', 'missing'], true)) throw self::storageError();
        }
        return $manifest;
    }

    private static function bundle(int $owner, string $id, string $month): string
    {
        self::owner($owner);
        self::id($id);
        self::month($month);
        $directory = self::monthDirectory($owner, $month, false);
        if ($directory === null || (!file_exists($directory . '/' . $id) && !is_link($directory . '/' . $id))) {
            throw new \RuntimeException('Relatório arquivado indisponível para este usuário e competência.', 404);
        }
        self::ensureDirectory($directory . '/' . $id, false);
        return $directory . '/' . $id;
    }

    private static function monthDirectory(int $owner, string $month, bool $create): ?string
    {
        self::owner($owner);
        self::month($month);
        if (!defined('GLPI_PLUGIN_DOC_DIR') || !is_string(GLPI_PLUGIN_DOC_DIR) || GLPI_PLUGIN_DOC_DIR === ''
            || strpos(GLPI_PLUGIN_DOC_DIR, "\0") !== false || is_link(rtrim(GLPI_PLUGIN_DOC_DIR, '/\\'))) throw self::storageError();
        $base = realpath(GLPI_PLUGIN_DOC_DIR);
        if ($base === false || !is_dir($base)) throw self::storageError();
        $directory = rtrim($base, '/\\');
        foreach (['g4freports', 'monthly_archive', (string)$owner, $month] as $part) {
            $directory .= '/' . $part;
            // The plugin parent can already contain other GLPI-managed documents.
            // Keep its existing permissions; only this archive and its children are private.
            if (!self::ensureDirectory($directory, $create, $part !== 'g4freports')) return null;
        }
        return $directory;
    }

    /** Never follow a symlink in the managed archive tree. */
    private static function ensureDirectory(string $path, bool $create, bool $private = true): bool
    {
        clearstatcache(true, $path);
        if (is_link($path)) throw self::storageError();
        if (!file_exists($path)) {
            if (!$create) return false;
            if (!@mkdir($path, 0700) && !is_dir($path)) throw self::storageError();
            clearstatcache(true, $path);
            if (is_link($path) || !@chmod($path, 0700)) throw self::storageError();
        }
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($private && ($stat['mode'] & 0077) !== 0)) throw self::storageError();
        return true;
    }

    private static function revisionNames(string $directory): array
    {
        self::ensureDirectory($directory, false);
        $handle = @opendir($directory);
        if ($handle === false) throw self::storageError();
        $names = [];
        $count = 0;
        try {
            while (($name = readdir($handle)) !== false) {
                if (++$count > self::MAX_DIRECTORY_ENTRIES) throw self::storageError();
                if (!preg_match('/\A[a-f0-9]{64}\z/D', $name)) continue;
                self::ensureDirectory($directory . '/' . $name, false);
                $names[] = $name;
                if (count($names) > self::MAX_REVISIONS_PER_MONTH) throw self::storageError();
            }
        } finally { closedir($handle); }
        return $names;
    }

    /** One non-blocking attempt: requests never wait behind another archive write. */
    private static function lock(string $directory, string $filename = '.archive.lock',
        string $busyMessage = 'Outra revisão desta competência está sendo arquivada. Tente novamente.', int $busyCode = 409)
    {
        self::ensureDirectory($directory, false);
        $path = $directory . '/' . $filename;
        clearstatcache(true, $path);
        if (is_link($path)) throw self::storageError();
        if (file_exists($path)) {
            self::fileStat($path, 0);
            $handle = @fopen($path, 'r+b');
        } else {
            $handle = @fopen($path, 'x+b');
            if ($handle === false && file_exists($path)) {
                self::fileStat($path, 0);
                $handle = @fopen($path, 'r+b');
            }
        }
        if ($handle === false) throw self::storageError();
        try {
            if (!@chmod($path, 0600)) throw self::storageError();
            self::sameFile($path, $handle, 0);
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException($busyMessage, $busyCode);
            }
            return $handle;
        } catch (\Throwable $error) { fclose($handle); throw $error; }
    }

    private static function writeNew(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) throw self::storageError();
        try {
            if (!@chmod($path, 0600)) throw self::storageError();
            self::sameFile($path, $handle, 0);
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset, 1048576));
                if ($written === false || $written === 0) throw self::storageError();
                $offset += $written;
            }
            if (!@fflush($handle)) throw self::storageError();
            if (function_exists('fsync') && !@fsync($handle)) throw self::storageError();
        } finally { fclose($handle); }
    }

    private static function readFile(string $path, int $limit, ?array $expected = null): string
    {
        $stat = self::fileStat($path);
        if ($stat['size'] > $limit) throw self::storageError();
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw self::storageError();
        try {
            self::sameFile($path, $handle, $stat['size']);
            $contents = stream_get_contents($handle, $limit + 1);
            if (!is_string($contents) || strlen($contents) !== $stat['size'] || strlen($contents) > $limit) throw self::storageError();
        } finally { fclose($handle); }
        if ($expected !== null && (strlen($contents) !== $expected['bytes']
            || !hash_equals($expected['sha256'], hash('sha256', $contents)))) throw self::storageError();
        return $contents;
    }

    private static function verifyFile(string $path, array $expected): void
    {
        self::fileStat($path, $expected['bytes']);
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw self::storageError();
        try {
            self::sameFile($path, $handle, $expected['bytes']);
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $handle, $expected['bytes'] + 1);
            if ($read !== $expected['bytes'] || !hash_equals($expected['sha256'], hash_final($hash))) throw self::storageError();
        } finally { fclose($handle); }
    }

    private static function fileStat(string $path, ?int $expectedSize = null): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || $stat['nlink'] !== 1 || ($expectedSize !== null && $stat['size'] !== $expectedSize)) throw self::storageError();
        return $stat;
    }

    private static function sameFile(string $path, $handle, int $size): void
    {
        $actual = fstat($handle);
        $named = self::fileStat($path, $size);
        if (!is_array($actual) || $actual['ino'] !== $named['ino'] || $actual['dev'] !== $named['dev']) throw self::storageError();
    }

    private static function discardStaging(string $directory): void
    {
        if (is_link($directory) || !is_dir($directory)) return;
        foreach (['snapshot.json', 'summary.json', 'manifest.json', 'manifest.sha256'] as $file) {
            $path = $directory . '/' . $file;
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
        @rmdir($directory);
    }

    private static function owner(int $owner): void
    {
        if ($owner <= 0) throw new \InvalidArgumentException('Sessão inválida para o arquivo mensal.', 400);
    }

    private static function month($month): string
    {
        if (!is_string($month) || !preg_match('/\A(?:20\d{2}|2100)-(?:0[1-9]|1[0-2])\z/D', $month)) {
            throw new \InvalidArgumentException('Competência inválida para o arquivo mensal.', 400);
        }
        return $month;
    }

    private static function id(string $id): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) throw new \InvalidArgumentException('Identificador de revisão inválido.', 400);
    }

    private static function shortText($text, int $length): string
    {
        if (!is_scalar($text)) return '';
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$text) ?? '', 0, $length, 'UTF-8');
    }

    private static function encode(array $value): string
    {
        try { return json_encode($value, self::JSON_FLAGS); }
        catch (\Throwable $error) { throw new \InvalidArgumentException('O relatório contém dados que não podem ser arquivados.', 400); }
    }

    private static function decode(string $json): array
    {
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw self::storageError(); }
        if (!is_array($value)) throw self::storageError();
        return $value;
    }

    private static function storageError(): \RuntimeException
    {
        return new \RuntimeException('O arquivo mensal não está disponível ou contém uma revisão incompleta. Confira o armazenamento permanente do plugin.', 503);
    }
}
