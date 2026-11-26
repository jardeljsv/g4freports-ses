<?php

namespace GlpiPlugin\G4freports\Ses;

/** Explicit host-GLPI groups for the shared SES archive, separate from source filters. */
final class ArchiveSharingSettings
{
    public const CONFIG_KEY = 'ses_archive_shared_groups';

    public static function normalizeGroups($raw): string
    {
        if (is_string($raw)) {
            if (strlen($raw) > 8192) throw self::invalid();
            $raw = trim($raw);
            if ($raw === '') return '';
            if ($raw[0] !== '[') throw self::invalid();
            try { $raw = json_decode($raw, true, 4, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw self::invalid(); }
        }
        if (!is_array($raw) || count($raw) > 100
            || ($raw && array_keys($raw) !== range(0, count($raw) - 1))) throw self::invalid();
        $ids = [];
        foreach ($raw as $value) {
            if ((!is_int($value) && !is_string($value))
                || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$value)
                || (float)$value > 2147483647) throw self::invalid();
            $ids[(int)$value] = (int)$value;
        }
        sort($ids, SORT_NUMERIC);
        return $ids ? json_encode(array_values($ids), JSON_THROW_ON_ERROR) : '';
    }

    public static function groups($raw): array
    {
        $normalized = self::normalizeGroups($raw);
        return $normalized === '' ? [] : json_decode($normalized, true, 4, JSON_THROW_ON_ERROR);
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Histórico compartilhado: selecione até 100 grupos válidos do GLPI.');
    }
}
