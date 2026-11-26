<?php

namespace GlpiPlugin\G4freports\Ses;

/** Only month/revision references cross the wire; results are loaded server-side. */
final class AnnualSelection
{
    private const MAX_MONTHS = 24;
    private const MAX_BYTES = 16384;

    public static function month($value): string
    {
        if (!is_string($value) || !preg_match('/\A(?:20\d{2}|2100)-(?:0[1-9]|1[0-2])\z/D', $value)) {
            throw new \InvalidArgumentException('Selecione uma competência válida entre 2000 e 2100.');
        }
        return $value;
    }

    public static function id($value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/D', $value)) {
            throw new \InvalidArgumentException('Selecione uma revisão arquivada válida.');
        }
        return $value;
    }

    public static function year($value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A(?:20\d{2}|2100)\z/D', (string)$value)) {
            throw new \InvalidArgumentException('Selecione um ano entre 2000 e 2100.');
        }
        return (int)$value;
    }

    public static function parse($raw): array
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES || substr(ltrim($raw), 0, 1) !== '{') {
            throw new \InvalidArgumentException('A seleção de competências é inválida ou excede 16 KB.');
        }
        try {
            $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('A seleção de competências contém JSON inválido.');
        }
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('A seleção deve ser um objeto JSON.');
        }
        $input = get_object_vars($decoded);
        if (array_diff(array_keys($input), ['months', 'selections'])) {
            throw new \InvalidArgumentException('Envie somente as competências e as revisões selecionadas.');
        }
        $months = $input['months'] ?? null;
        $selections = array_key_exists('selections', $input) ? $input['selections'] : [];
        if (!self::isList($months) || count($months) < 1 || count($months) > self::MAX_MONTHS
            || !self::isList($selections) || count($selections) > self::MAX_MONTHS) {
            throw new \InvalidArgumentException('Selecione de uma a 24 competências e no máximo uma revisão por competência.');
        }
        $seen = [];
        foreach ($months as $month) {
            $month = self::month($month);
            if (isset($seen[$month])) throw new \InvalidArgumentException('Uma competência foi selecionada mais de uma vez.');
            $seen[$month] = true;
        }
        $byMonth = [];
        foreach ($selections as $selection) {
            if (!$selection instanceof \stdClass) {
                throw new \InvalidArgumentException('Cada revisão deve ser um objeto JSON.');
            }
            $selection = get_object_vars($selection);
            if (array_diff(array_keys($selection), ['month', 'id', 'archive_ref'])
                || !array_key_exists('month', $selection) || !array_key_exists('id', $selection)) {
                throw new \InvalidArgumentException('Cada revisão deve informar somente sua competência e seu identificador.');
            }
            $month = self::month($selection['month']);
            $id = self::id($selection['id']);
            if (!isset($seen[$month])) throw new \InvalidArgumentException('A revisão informada não pertence às competências selecionadas.');
            if (isset($byMonth[$month])) throw new \InvalidArgumentException('Selecione somente uma revisão por competência.');
            $byMonth[$month] = ['month' => $month, 'id' => $id];
            if (array_key_exists('archive_ref', $selection)) $byMonth[$month]['archive_ref'] = self::id($selection['archive_ref']);
        }
        sort($months, SORT_STRING);
        ksort($byMonth, SORT_STRING);
        return ['months' => $months, 'selections' => array_values($byMonth)];
    }

    public static function records(array $selection, int $owner, ?array $configuration = null): array
    {
        $records = [];
        $authorized = ArchiveAccess::resolveMany($owner, $selection['selections'], $configuration);
        // Authorize the complete selection before loading any summary or daily projection.
        foreach ($authorized as $revision) {
            $record = MonthlyArchiveStore::loadSummary($revision['owner'], $revision['id'], $revision['month']);
            // This optional, bounded projection is derived from immutable evidence.
            // Preview and export never prepare it or read the raw monthly arrays.
            $activity = MonthlyArchiveStore::loadActivity($revision['owner'], $revision['id'], $revision['month']);
            if ($activity !== null) $record['summary']['activity'] = $activity;
            $records[] = $record;
        }
        return $records;
    }

    private static function isList($value): bool
    {
        return is_array($value) && (!$value || array_keys($value) === range(0, count($value) - 1));
    }
}
