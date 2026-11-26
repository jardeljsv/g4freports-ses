<?php

namespace GlpiPlugin\G4freports\Ses;

use GlpiPlugin\G4freports\Config;

/** Ten recoverable pre-save revisions for each register, kept in GLPI configuration. */
final class RegisterHistory
{
    private const RETAIN = 10;

    private static function prefix(string $kind): string
    {
        if (!in_array($kind, ['staff', 'tests'], true)) throw new \InvalidArgumentException('Cadastro desconhecido.');
        return 'ses_register_' . $kind . '_history_';
    }

    public static function entries(array $settings, string $kind): array
    {
        $raw = json_decode($settings[self::prefix($kind) . 'index'] ?? '[]', true);
        return is_array($raw) ? array_values(array_slice($raw, 0, self::RETAIN)) : [];
    }

    public static function revisionRows(array $settings, string $kind, string $id): array
    {
        foreach (self::entries($settings, $kind) as $entry) {
            if (($entry['id'] ?? '') !== $id) continue;
            $slot = (int)($entry['slot'] ?? -1);
            if ($slot < 0 || $slot >= self::RETAIN) break;
            $parts = (int)($entry['parts'] ?? 1);
            if ($parts < 1 || $parts > 4) break;
            $raw = '';
            for ($part = 0; $part < $parts; $part++) {
                $value = $settings[self::prefix($kind) . $slot . '_' . $part] ?? null;
                if (!is_string($value)) throw new \RuntimeException('Esta versão do histórico está incompleta.');
                $raw .= $value;
            }
            return $kind === 'staff' ? StaffRegister::rows($raw) : Settings::testNumbers($raw);
        }
        throw new \InvalidArgumentException('Esta versão não está mais no histórico. Atualize a lista de versões.');
    }

    /** Includes the selected register's new value in the same configuration save. */
    public static function save(array $settings, string $kind, array $before, array $after, int $userId, string $userName): array
    {
        $key = $kind === 'staff' ? StaffRegister::CONFIG_KEY : 'ses_test_numbers_json';
        if (StaffRegister::revision($before) === StaffRegister::revision($after)) {
            // Save the explicit exclusion field even when a legacy register's
            // visible marks were not edited. Repeated saves never invert marks.
            if ($kind === 'staff' && ($settings[$key] ?? '[]') !== StaffRegister::json($after)) {
                self::saveValues([$key => StaffRegister::json($after)]);
            }
            return self::entries($settings, $kind);
        }
        $entries = self::entries($settings, $kind);
        $slot = $entries ? (((int)$entries[0]['slot'] + 1) % self::RETAIN) : 0;
        $idField = $kind === 'staff' ? 'id' : 'number';
        $oldMap = array_column($before, null, $idField);
        $newMap = array_column($after, null, $idField);
        $added = $removed = $updated = 0;
        foreach ($oldMap as $id => $row) {
            if (!isset($newMap[$id])) $removed++;
            elseif ($newMap[$id] !== $row) $updated++;
        }
        foreach ($newMap as $id => $row) if (!isset($oldMap[$id])) $added++;
        // Keep each GLPI configuration value below the TEXT limit, including a
        // large phone register. mb_strcut does not split UTF-8 characters.
        $priorJson = StaffRegister::json($before);
        $chunks = [];
        while ($priorJson !== '') {
            $chunk = mb_strcut($priorJson, 0, 50000, 'UTF-8');
            $chunks[] = $chunk;
            $priorJson = substr($priorJson, strlen($chunk));
        }
        if (count($chunks) > 4) throw new \InvalidArgumentException('O cadastro excede o limite de recuperação do histórico.');
        array_unshift($entries, [
            'id' => bin2hex(random_bytes(16)), 'slot' => $slot, 'parts' => count($chunks),
            'saved_at' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
            'user_id' => $userId, 'user_name' => mb_substr($userName, 0, 120),
            'added' => $added, 'removed' => $removed, 'updated' => $updated,
            'previous_count' => count($before), 'saved_count' => count($after),
        ]);
        $entries = array_slice($entries, 0, self::RETAIN);
        $values = [];
        for ($part = 0; $part < 4; $part++) $values[self::prefix($kind) . $slot . '_' . $part] = $chunks[$part] ?? '';
        $values[self::prefix($kind) . 'index'] = StaffRegister::json($entries);
        $values[$key] = StaffRegister::json($after);
        self::saveValues($values);
        return $entries;
    }

    /** Commit the register and its recoverable history together, while the caller holds the lock. */
    private static function saveValues(array $values): void
    {
        global $DB;
        // GLPI 10 cannot nest beginTransaction(): it starts a new transaction
        // and commit() would also commit its caller's work. GLPI 11 removed
        // this public method and manages nesting through native savepoints.
        if (is_callable([$DB, 'inTransaction']) && $DB->inTransaction()) {
            throw new \RuntimeException('Não foi possível iniciar um salvamento isolado do cadastro. Tente novamente.');
        }
        $started = false;
        try {
            // GLPI 10 returns bool; GLPI 11 returns void and throws on failure.
            if ($DB->beginTransaction() === false) {
                throw new \RuntimeException('Não foi possível iniciar o salvamento do cadastro.');
            }
            $started = true;
            Config::save($values);
            // Native Config writes each key separately and does not return
            // individual write failures. Read the actual keys, without defaults.
            $saved = \Config::getConfigurationValues('plugin:g4freports', array_keys($values));
            foreach ($values as $key => $value) {
                if (!array_key_exists($key, $saved) || $saved[$key] !== $value) {
                    throw new \RuntimeException('O GLPI não confirmou o cadastro e seu histórico completos. Nenhuma alteração foi confirmada.');
                }
            }
            if ($DB->commit() === false) {
                throw new \RuntimeException('O GLPI não confirmou a conclusão do salvamento do cadastro.');
            }
            $started = false;
        } catch (\Throwable $error) {
            if ($started) {
                try {
                    if ($DB->rollBack() === false) throw new \RuntimeException('Rollback failed.');
                } catch (\Throwable $rollbackError) {
                    // Preserve the original failure and never log register data.
                    try {
                        if (function_exists('g4fr_log')) {
                            g4fr_log(defined('GLPI_ROOT') ? GLPI_ROOT : null, 'SES_REGISTER_ROLLBACK_FAILED');
                        }
                    } catch (\Throwable $logError) { /* Best-effort cleanup logging. */ }
                }
            }
            throw $error;
        }
    }

    /** Serialize revision-check + save across PHP workers using the native GLPI DB. */
    public static function lock(): string
    {
        global $DB;
        $identity = defined('GLPI_ROOT') ? GLPI_ROOT : dirname(__DIR__, 3);
        $name = 'g4fr_registers_' . substr(hash('sha256', $identity), 0, 24);
        // GLPI 11 forbids plugin calls to DBmysql::query(). The native lock
        // API is also available in GLPI 10 and applies the database namespace.
        if (!$DB->getLock($name)) {
            throw new \RuntimeException('Outro cadastro está sendo salvo. Aguarde alguns segundos e tente novamente; suas alterações continuam na tela.');
        }
        return $name;
    }

    public static function unlock(string $name): void
    {
        global $DB;
        if ($name !== '' && !$DB->releaseLock($name)) {
            throw new \RuntimeException('Não foi possível liberar o bloqueio do cadastro.');
        }
    }
}
