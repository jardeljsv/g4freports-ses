<?php

namespace GlpiPlugin\G4freports\Ses;

/** Validate an entire interchange workbook and stage a non-destructive merge. No persistence. */
final class RegisterImporter
{
    public static function preview(string $kind, string $binary, array $current, array $frontIds = []): array
    {
        if (!in_array($kind, ['staff', 'tests'], true)) throw new \InvalidArgumentException('Cadastro desconhecido.');
        $staff = $kind === 'staff';
        $current = $staff ? StaffRegister::rows($current) : Settings::testNumbers($current);
        $book = RegisterWorkbookReader::read($binary);
        $sheets = $book['sheets'];
        $control = $sheets['Controle'] ?? [];
        if (($control[1]['A'] ?? '') !== 'g4freports-register-v1' || ($control[1]['B'] ?? '') !== $kind) {
            throw new \InvalidArgumentException('Use o arquivo .xlsx exportado por esta tabela na versão 1.8.20 ou posterior. O tipo de cadastro deve ser o mesmo.');
        }
        $month = $control[1]['C'] ?? '';
        if (($month !== '' && !preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $month)) || (!$staff && $month !== '')) {
            throw new \InvalidArgumentException('Competência inválida no arquivo. Exporte um novo modelo.');
        }
        $sheetName = $staff ? 'Profissionais' : 'Números desconsiderados';
        $expectedSheets = $staff ? [$sheetName, 'Dias por competência', 'Controle'] : [$sheetName, 'Controle'];
        $actualSheets = array_keys($sheets); sort($actualSheets); sort($expectedSheets);
        if ($actualSheets !== $expectedSheets) throw new \InvalidArgumentException('As abas do arquivo não correspondem ao modelo exportado.');
        $headers = $staff ? ['Nº', 'Nome', 'Perfil Profissional', 'Data de admissão', 'Data de saída', 'Não Faturar', 'Valor mensal', 'Identificação do vínculo', 'ID do registro']
            : ['Número de origem', 'Identificação / motivo', 'Excluir'];
        if ($staff) $headers = array_merge($headers, $month !== ''
            ? ['Primeiro dia Faturado', 'Último dia Faturado', 'Dias Faturado', 'Valor Faturado', 'Status', 'Dias automáticos', 'Dias informados', 'Frente de serviço'] : ['Frente de serviço']);
        self::headers($sheets[$sheetName][3] ?? [], $headers, $sheetName);
        $reasons = [];
        if ($staff) {
            self::headers($control[2] ?? [], ['ID do registro', 'Motivo de saída'], 'Controle');
            foreach ($control as $r => $cells) {
                if ($r < 3 || !self::hasValues($cells)) continue;
                self::columns($cells, 2, 'Controle', $r);
                $id = trim($cells['A'] ?? ''); $reason = trim($cells['B'] ?? '');
                if ($id === '' || !in_array($reason, ['pending', 'replacement', 'contractor_reduction', 'other'], true)
                    || (isset($reasons[$id]) && $reasons[$id] !== $reason)) throw new \InvalidArgumentException('Metadados de vínculo inválidos na aba Controle.');
                $reasons[$id] = $reason;
            }
        }
        $incoming = []; $sourceIds = []; $rowNumbers = []; $hasTotal = false;
        foreach ($sheets[$sheetName] as $r => $cells) {
            if ($r <= 3 || !self::hasValues($cells)) continue;
            self::columns($cells, count($headers), $sheetName, $r);
            if (($cells['A'] ?? '') === 'Total de registros') {
                if ($hasTotal || !preg_match('/\A\d+\z/D', $cells['B'] ?? '')) throw new \InvalidArgumentException('Linha de total inválida ou repetida.');
                foreach ($cells as $col => $value) {
                    if (trim($value) !== '' && !in_array($col, $staff && $month !== '' ? ['A', 'B', 'K', 'L', 'M'] : ['A', 'B'], true)) {
                        throw new \InvalidArgumentException('Há dados na linha de total. Insira registros em linhas próprias.');
                    }
                }
                $hasTotal = true; continue;
            }
            if (count($incoming) >= 500) throw new \InvalidArgumentException('A importação aceita até 500 registros por arquivo.');
            $types = $book['types'][$sheetName][$r] ?? [];
            $get = static fn(string $c): string => trim($cells[$c] ?? '');
            if ($staff) {
                foreach (['H', 'I'] as $c) self::textIdentifier($get($c), $types[$c] ?? '', $r, $headers[ord($c) - 65]);
                $id = $get('I');
                $row = ['id' => $id, 'name' => $get('B'), 'profile' => $get('C'),
                    'admission_date' => self::date($get('D'), $types['D'] ?? '', $book['date1904'], $r),
                    'departure_date' => self::date($get('E'), $types['E'] ?? '', $book['date1904'], $r),
                    'not_billed' => self::flag($get('F'), $r),
                    'monthly_value' => ($types['G'] ?? '') === 'n' && $get('G') !== '' ? self::numeric($get('G'), 8, 2, $r) : $get('G'),
                    'record_reference' => $get('H'), 'service_front' => $get($month !== '' ? 'Q' : 'J'),
                    'departure_reason' => $reasons[$id] ?? 'pending', 'billing_days_overrides' => []];
                if ($month !== '') $row['billing_days_overrides'][$month] = self::days($get('P'), $r, $types['P'] ?? '');
                if ($id !== '') $sourceIds[$id] = true;
            } else {
                self::textIdentifier($get('A'), $types['A'] ?? '', $r, 'Número de origem');
                $row = ['number' => $get('A'), 'label' => $get('B'), 'active' => self::flag($get('C'), $r)];
            }
            $incoming[] = $row; $rowNumbers[] = $r;
        }
        $overrides = [];
        if ($staff) {
            $extra = $sheets['Dias por competência'];
            self::headers($extra[1] ?? [], ['ID do registro', 'Competência', 'Dias informados'], 'Dias por competência');
            foreach ($extra as $r => $cells) {
                if ($r < 2 || !self::hasValues($cells)) continue;
                self::columns($cells, 3, 'Dias por competência', $r);
                $id = trim($cells['A'] ?? ''); $m = trim($cells['B'] ?? '');
                self::textIdentifier($id, $book['types']['Dias por competência'][$r]['A'] ?? '', $r, 'ID do registro');
                if (!isset($sourceIds[$id])) throw new \InvalidArgumentException('Dias por competência, linha ' . $r . ': ID ausente na tabela de profissionais.');
                if (!preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $m) || $m === $month) throw new \InvalidArgumentException('Dias por competência, linha ' . $r . ': mês inválido ou já apresentado na tabela principal.');
                $days = self::days(trim($cells['C'] ?? ''), $r, $book['types']['Dias por competência'][$r]['C'] ?? '');
                if (isset($overrides[$id]) && array_key_exists($m, $overrides[$id]) && $overrides[$id][$m] !== $days) throw new \InvalidArgumentException('Dias divergentes para o mesmo vínculo e competência.');
                $overrides[$id][$m] = $days;
            }
        }
        $merged = []; $natural = []; $sameEmployment = [];
        foreach ($current as $row) {
            $key = $staff ? $row['id'] : $row['number']; $merged[$key] = $row;
            if ($staff) {
                $natural[self::identity($row)][] = $key;
                $sameEmployment[self::employment($row)][] = $key;
            }
        }
        $seen = []; $summary = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'duplicates' => 0]; $changes = [];
        foreach ($incoming as $i => $row) {
            $line = $rowNumbers[$i]; $old = null;
            try {
                if ($staff) {
                    $sourceId = $row['id'];
                    // Normalize before lookup, but retain explicit blank monthly overrides for clearing.
                    $delta = array_replace($overrides[$sourceId] ?? [], $row['billing_days_overrides']);
                    $row['billing_days_overrides'] = $delta;
                    $normalized = StaffRegister::rows([$row])[0];
                    $key = $sourceId;
                    if ($key === '') {
                        $matches = $natural[self::identity($normalized)] ?? [];
                        if (count($matches) > 1) throw new \InvalidArgumentException('Vínculo ambíguo. Mantenha o ID do registro exportado.');
                        $key = $matches[0] ?? $normalized['id'];
                    }
                    $old = $merged[$key] ?? null;
                    if ($old !== null && self::employment($old) !== self::employment($normalized)) {
                        throw new \InvalidArgumentException('O ID corresponde a outro nome, admissão ou identificação de vínculo. Faça correções de identidade diretamente na tabela e exporte novamente.');
                    }
                    if ($old === null && !empty($sameEmployment[self::employment($normalized)])) {
                        throw new \InvalidArgumentException('Já existe vínculo com o mesmo nome, admissão e identificação. Use o ID existente; para vínculos distintos informe uma identificação diferente.');
                    }
                    $normalized['id'] = $key;
                    $existingOverrides = $old['billing_days_overrides'] ?? [];
                    foreach ($delta as $m => $days) { if ($days === null) unset($existingOverrides[$m]); else $existingOverrides[$m] = $days; }
                    $normalized['billing_days_overrides'] = $existingOverrides;
                    if ($old !== null) $normalized['departure_reason'] = $old['departure_reason'];
                    $front = $normalized['service_front'] ?? '';
                    if ($front !== '' && $front !== ($old['service_front'] ?? '') && !in_array($front, $frontIds, true)) throw new \InvalidArgumentException('Frente de serviço não cadastrada. Use o identificador exportado de uma frente disponível ou deixe em branco.');
                    $row = StaffRegister::rows([$normalized])[0];
                } else {
                    $row = Settings::testNumbers([$row])[0]; $key = $row['number']; $old = $merged[$key] ?? null;
                }
                if (isset($seen[$key])) {
                    if ($seen[$key] !== $row) throw new \InvalidArgumentException('Registros repetidos com valores diferentes. Corrija a duplicidade no arquivo.');
                    $summary['duplicates']++; continue;
                }
                $seen[$key] = $row;
                $action = $old === null ? 'added' : ($old === $row ? 'unchanged' : 'updated');
                $summary[$action]++;
                if ($action !== 'unchanged') $changes[] = ['action' => $action, 'key' => (string)$key,
                    'label' => $staff ? $row['name'] : $row['number'], 'fields' => self::diff($old ?? [], $row)];
                $merged[$key] = $row;
                if ($staff && $old === null) {
                    $natural[self::identity($row)][] = $key; $sameEmployment[self::employment($row)][] = $key;
                }
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException($sheetName . ', linha ' . $line . ': ' . $e->getMessage());
            }
        }
        $rows = $staff ? StaffRegister::rows(array_values($merged)) : Settings::testNumbers(array_values($merged));
        if ($staff) StaffRegister::validateDuplicates($rows, $current);
        return ['rows' => $rows, 'summary' => $summary, 'changes' => $changes, 'month' => $month,
            'retained' => count($current) - count(array_intersect(array_keys($seen), array_keys($staff ? array_column($current, null, 'id') : array_column($current, null, 'number'))))];
    }

    private static function identity(array $row): string
    {
        return self::employment($row) . '|' . self::normalize($row['profile']);
    }

    private static function employment(array $row): string
    {
        return self::normalize($row['name']) . '|' . $row['admission_date'] . '|' . self::normalize($row['record_reference'] ?? '');
    }

    private static function normalize(string $value): string
    {
        $value = strtr($value, array_combine(preg_split('//u', 'ÁÀÂÃÄÅÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑáàâãäåéèêëíìîïóòôõöúùûüçñ', -1, PREG_SPLIT_NO_EMPTY), str_split('AAAAAAEEEEIIIIOOOOOUUUUCNaaaaaaeeeeiiiiooooouuuucn')));
        return strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    private static function hasValues(array $cells): bool { return count(array_filter($cells, static fn($v): bool => trim($v) !== '')) > 0; }

    private static function headers(array $cells, array $headers, string $sheet): void
    {
        self::columns($cells, count($headers), $sheet, 1);
        foreach ($headers as $i => $label) if (trim($cells[chr(65 + $i)] ?? '') !== $label) throw new \InvalidArgumentException('Cabeçalhos inválidos na aba ' . $sheet . '. Preserve as colunas do arquivo exportado.');
    }

    private static function columns(array $cells, int $count, string $sheet, int $row): void
    {
        foreach ($cells as $col => $value) if (trim($value) !== '' && (strlen($col) !== 1 || ord($col) - 65 >= $count)) throw new \InvalidArgumentException($sheet . ', linha ' . $row . ': coluna não prevista no modelo.');
    }

    private static function textIdentifier(string $value, string $type, int $row, string $label): void
    {
        if ($value !== '' && !in_array($type, ['s', 'inlineStr'], true)) throw new \InvalidArgumentException('Linha ' . $row . ': mantenha ' . $label . ' como texto, para preservar todos os dígitos e zeros iniciais.');
    }

    private static function flag(string $value, int $row): bool
    {
        $value = self::normalize($value);
        if (in_array($value, ['sim', '1', 'true'], true)) return true;
        if (in_array($value, ['nao', '0', 'false'], true)) return false;
        throw new \InvalidArgumentException('Linha ' . $row . ': informe Sim ou Não na coluna de marcação.');
    }

    private static function days(string $value, int $row, string $type = ''): ?int
    {
        if ($value === '') return null;
        if ($type === 'n') $value = self::numeric($value, 2, 0, $row);
        if (!preg_match('/\A(?:0|[1-9]\d?)\z/D', $value) || (int)$value > 31) throw new \InvalidArgumentException('Linha ' . $row . ': dias informados devem ser um inteiro de 0 a 31, ou ficar em branco para usar o automático.');
        return (int)$value;
    }

    /** Expand numeric cell notation exactly, bounded before allocating zero padding. Text cells never enter this path. */
    private static function numeric(string $value, int $integerDigits, int $scale, int $row): string
    {
        $invalid = static function () use ($row): void {
            throw new \InvalidArgumentException('Linha ' . $row . ': valor numérico fora do limite ou com casas decimais não permitidas.');
        };
        if (!preg_match('/\A([+-]?)([0-9]*)(?:\.([0-9]*))?(?:[Ee]([+-]?[0-9]+))?\z/D', $value, $m)
            || (($m[2] ?? '') === '' && ($m[3] ?? '') === '') || $m[1] === '-') $invalid();
        $integer = $m[2];
        $digits = $integer . ($m[3] ?? '');
        $trimmed = ltrim($digits, '0');
        if ($trimmed === '') return '0';
        $leadingZeros = strlen($digits) - strlen($trimmed);
        $digits = rtrim($trimmed, '0');
        $exponent = $m[4] ?? '0';
        // The reader caps a cell at 10,000 bytes. Larger exponents cannot produce
        // a nonzero in-range value, and must not reach integer casts or padding.
        if (strlen(ltrim(ltrim($exponent, '+-'), '0')) > 5) $invalid();
        $point = strlen($integer) - $leadingZeros + (int)$exponent;
        if (max(1, $point) > $integerDigits || max(0, strlen($digits) - $point) > $scale) $invalid();
        if ($point <= 0) return '0.' . str_repeat('0', -$point) . $digits;
        if ($point >= strlen($digits)) return $digits . str_repeat('0', $point - strlen($digits));
        return substr($digits, 0, $point) . '.' . substr($digits, $point);
    }

    private static function date(string $value, string $type, bool $date1904, int $row): string
    {
        if ($value === '') return '';
        if ($type === 'n') {
            $value = self::numeric($value, 6, 0, $row);
            if (!preg_match('/\A\d{1,6}(?:\.0+)?\z/D', $value)) throw new \InvalidArgumentException('Linha ' . $row . ': data numérica inválida ou com horário.');
            $serial = (int)$value;
            if (!$date1904 && $serial === 60) throw new \InvalidArgumentException('Linha ' . $row . ': a data 29/02/1900 não existe.');
            $base = new \DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-31', new \DateTimeZone('UTC'));
            return $base->modify('+' . ($date1904 || $serial < 60 ? $serial : $serial - 1) . ' days')->format('Y-m-d');
        }
        if ($type === 'd') {
            if (!preg_match('/\A((19\d{2}|20\d{2}|2100)-(\d{2})-(\d{2}))(?:T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?\z/D', $value, $m)
                || !checkdate((int)$m[3], (int)$m[4], (int)$m[2])) {
                throw new \InvalidArgumentException('Linha ' . $row . ': data ISO inválida; use AAAA-MM-DD ou uma data válida à meia-noite, entre 1900 e 2100.');
            }
            if (isset($m[5]) && ($m[5] !== '00' || $m[6] !== '00' || $m[7] !== '00' || preg_match('/[1-9]/', $m[8] ?? ''))) {
                throw new \InvalidArgumentException('Linha ' . $row . ': datas com horário diferente de meia-noite não são aceitas.');
            }
            if (!in_array($m[9] ?? '', ['', 'Z', '+00:00'], true)) {
                throw new \InvalidArgumentException('Linha ' . $row . ': fuso horário da data não aceito; use uma data sem fuso, Z ou +00:00.');
            }
            return $m[1];
        }
        if (preg_match('/\A(\d{2})\/(\d{2})\/(\d{4})\z/D', $value, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
        return $value;
    }

    private static function diff(array $before, array $after): array
    {
        $labels = ['name' => 'Nome', 'profile' => 'Perfil', 'admission_date' => 'Admissão', 'departure_date' => 'Saída', 'not_billed' => 'Não Faturar', 'monthly_value' => 'Valor mensal', 'record_reference' => 'Identificação do vínculo', 'service_front' => 'Frente de serviço', 'billing_days_overrides' => 'Dias por competência', 'number' => 'Número', 'label' => 'Identificação / motivo', 'active' => 'Excluir'];
        $changes = [];
        foreach ($labels as $field => $label) if (($before[$field] ?? null) !== ($after[$field] ?? null)) $changes[] = ['field' => $label, 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
        return $changes;
    }
}
