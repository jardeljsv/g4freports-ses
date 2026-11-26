<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** Saved contract roster and an auditable, date-based INS10 working calculation. */
final class StaffRegister
{
    public const CONFIG_KEY = 'ses_staff_register_json';
    private const MAX_ROWS = 500;
    private const MAX_BYTES = 60000;

    public static function fromSettings(array $settings): array
    {
        return self::rows($settings[self::CONFIG_KEY] ?? '[]');
    }

    /**
     * A checked mark excludes the employment from INS10. The legacy field was
     * misleadingly named "billed", but stored that same original checkbox mark.
     * Never invert it on loading, saving, restoring history or generating reports.
     */
    public static function isNotBilled(array $row): bool
    {
        $value = array_key_exists('not_billed', $row) ? $row['not_billed']
            : (array_key_exists('billed', $row) ? $row['billed'] : false);
        if (!in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            throw new \InvalidArgumentException('Informe a opção Não Faturar do profissional.');
        }
        return in_array($value, [true, 1, '1'], true);
    }

    /** Normalize a complete register before saving; never infer an exit's reason. */
    public static function rows($raw): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > self::MAX_BYTES || substr(ltrim($raw), 0, 1) !== '[') {
                throw new \InvalidArgumentException('O cadastro de profissionais deve ser uma lista JSON de até 60.000 bytes.');
            }
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('O cadastro de profissionais contém JSON inválido.');
            }
        }
        if (!is_array($raw) || ($raw && array_keys($raw) !== range(0, count($raw) - 1)) || count($raw) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('Informe uma lista com até 500 profissionais.');
        }
        $result = [];
        $seen = [];
        foreach ($raw as $index => $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('Cadastro de profissionais: linha inválida.');
            $label = 'Profissional ' . ($index + 1);
            $id = self::text($row['id'] ?? '', $label . ': identificador', 64, false);
            if ($id === '') $id = bin2hex(random_bytes(16));
            if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id) || isset($seen[$id])) {
                throw new \InvalidArgumentException($label . ': identificador inválido ou duplicado.');
            }
            $admission = self::date($row['admission_date'] ?? '', $label . ': data de admissão');
            $departure = self::date($row['departure_date'] ?? null, $label . ': data de saída', true);
            if ($departure !== null && $departure < $admission) {
                throw new \InvalidArgumentException($label . ': a data de saída não pode ser anterior à admissão.');
            }
            $reason = $row['departure_reason'] ?? 'pending';
            if ($reason === '' || $reason === null) $reason = 'pending';
            if (!is_string($reason) || !in_array($reason, ['pending', 'replacement', 'contractor_reduction', 'other'], true)) {
                throw new \InvalidArgumentException($label . ': motivo de saída inválido.');
            }
            $notBilled = self::isNotBilled($row);
            $seen[$id] = true;
            $result[] = [
                'id' => $id,
                'not_billed' => $notBilled,
                'name' => self::text($row['name'] ?? '', $label . ': nome', 240),
                'profile' => self::text($row['profile'] ?? '', $label . ': perfil profissional', 300),
                // Optional local reference distinguishes legitimate homonyms or separate contracts.
                'record_reference' => self::text($row['record_reference'] ?? '', $label . ': identificação do vínculo', 120, false),
                'admission_date' => $admission,
                'departure_date' => $departure,
                'monthly_value' => self::money($row['monthly_value'] ?? '', $label . ': valor mensal'),
                // Keep the optional analytical detail even when the departure date is absent.
                'departure_reason' => $reason,
                'service_front' => self::text($row['service_front'] ?? '', $label . ': frente de serviço', 160, false),
                'billing_days_overrides' => self::billingDaysOverrides($row['billing_days_overrides'] ?? [], $admission, $departure, $label),
            ];
            // Do not inflate every legacy row when no distinction is needed.
            foreach (['record_reference', 'service_front', 'billing_days_overrides'] as $optional) {
                if ($result[count($result) - 1][$optional] === '' || $result[count($result) - 1][$optional] === []) {
                    unset($result[count($result) - 1][$optional]);
                }
            }
        }
        if (strlen(self::json($result)) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('O cadastro de profissionais excede o limite de armazenamento de 60.000 bytes.');
        }
        return self::sortRows($result);
    }

    /** Sorting affects presentation only: departed professionals remain in the register. */
    public static function sortRows(array $rows): array
    {
        $collator = class_exists('Collator') ? new \Collator('pt_BR') : null;
        usort($rows, static function (array $a, array $b) use ($collator): int {
            $cmp = $collator ? $collator->compare($a['name'], $b['name'])
                : strcmp(self::identityText($a['name']), self::identityText($b['name']));
            return $cmp ?: strcmp($a['admission_date'] . $a['id'], $b['admission_date'] . $b['id']);
        });
        return $rows;
    }

    private static function identityText(string $text): string
    {
        $text = strtr($text, array_combine(
            preg_split('//u', 'ÁÀÂÃÄÅÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑáàâãäåéèêëíìîïóòôõöúùûüçñ', -1, PREG_SPLIT_NO_EMPTY),
            str_split('AAAAAAEEEEIIIIOOOOOUUUUCNaaaaaaeeeeiiiiooooouuuucn')
        ));
        return strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    private static function duplicateKey(array $row): string
    {
        return implode('|', [self::identityText($row['name']), $row['admission_date'],
            self::identityText($row['profile']), self::identityText($row['record_reference'] ?? '')]);
    }

    /** Existing duplicates remain visible for review; never silently drop a saved row. */
    public static function duplicateWarnings(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) $groups[self::duplicateKey($row)][] = $row;
        $warnings = [];
        foreach ($groups as $group) if (count($group) > 1) {
            $warnings[] = ['name' => $group[0]['name'], 'admission_date' => $group[0]['admission_date'],
                'ids' => array_column($group, 'id'), 'count' => count($group)];
        }
        return $warnings;
    }

    /** Reject new duplicate employments, allowing unchanged legacy duplicates to be reviewed. */
    public static function validateDuplicates(array $rows, array $currentRows = []): void
    {
        $prior = [];
        foreach ($currentRows as $row) $prior[self::duplicateKey($row)][$row['id']] = true;
        $groups = [];
        foreach ($rows as $row) $groups[self::duplicateKey($row)][] = $row;
        foreach ($groups as $key => $group) {
            if (count($group) < 2) continue;
            foreach ($group as $row) if (!isset($prior[$key][$row['id']])) {
                throw new \InvalidArgumentException('Vínculo duplicado: ' . $row['name'] . ' (admissão ' . $row['admission_date']
                    . '). Confira a entrada existente. Para homônimos ou vínculos realmente distintos, preencha uma identificação do vínculo diferente.');
            }
        }
    }

    public static function json(array $rows): string
    {
        return json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Used by the form to avoid silently overwriting another administrator's edits. */
    public static function revision(array $rows): string
    {
        return hash('sha256', self::json($rows));
    }

    /** Optional analytical description; legacy pending means no reason supplied. */
    public static function departureReasonLabel($reason): string
    {
        $labels = ['replacement' => 'Substituição',
            'contractor_reduction' => 'Redução determinada pela contratante',
            'other' => 'Outra saída sem substituição'];
        return is_string($reason) ? ($labels[$reason] ?? 'Motivo não informado') : 'Motivo não informado';
    }

    /** Explicit monthly attendance overrides survive save/reload without changing employment dates. */
    private static function billingDaysOverrides($raw, string $admission, ?string $departure, string $label): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($label . ': os dias faturados por competência devem formar um objeto mês/dias.');
        }
        $overrides = [];
        foreach ($raw as $month => $days) {
            if (!is_string($month) || !preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $month)) {
                throw new \InvalidArgumentException($label . ': competência inválida nos dias faturados.');
            }
            // Clearing a month restores its automatic count. A numeric zero is an explicit override.
            if ($days === null || $days === '') continue;
            $maximum = self::employmentDays($admission, $departure, $month);
            $validInteger = is_int($days) || (is_string($days) && preg_match('/\A(?:0|[1-9]\d*)\z/D', $days));
            if (!$validInteger || (int)$days < 0 || (int)$days > $maximum) {
                throw new \InvalidArgumentException($label . ': dias faturados de ' . $month . ' devem ser um inteiro de 0 a ' . $maximum
                    . ', conforme as datas de admissão e saída. Confira também os ajustes de competências anteriores ao alterar essas datas.');
            }
            $overrides[$month] = (int)$days;
        }
        ksort($overrides);
        return $overrides;
    }

    private static function employmentDays(string $admission, ?string $departure, string $month): int
    {
        $start = new \DateTimeImmutable($month . '-01', new \DateTimeZone('UTC'));
        $first = max($start->format('Y-m-d'), $admission);
        $last = min($start->modify('last day of this month')->format('Y-m-d'), $departure ?? '9999-12-31');
        return $first > $last ? 0 : (int)(new \DateTimeImmutable($first))->diff(new \DateTimeImmutable($last))->days + 1;
    }

    /**
     * Monthly billing uses a saved override when present, otherwise inclusive employment days.
     * Amounts round once per professional. Dates remain the actual employment overlap.
     * Incomplete draft rows remain exportable, with unavailable derived values left blank.
     */
    public static function billingForMonth(array $row, string $month): array
    {
        $departure = $row['departure_date'] ?? null;
        $result = ['billing_first_day' => null, 'billing_last_day' => null,
            'automatic_billed_days' => null, 'billed_days_override' => null,
            'billed_days' => null, 'billed_value' => null, 'billing_month_days' => null,
            'status' => $departure === null || trim((string)$departure) === '' ? 'Ativo' : 'Inativo'];
        if (!preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $month)) return $result;
        $zone = new \DateTimeZone('UTC');
        $monthStart = new \DateTimeImmutable($month . '-01', $zone);
        $monthEnd = $monthStart->modify('last day of this month');
        $monthDays = (int)$monthEnd->format('j');
        $result['billing_month_days'] = $monthDays;
        $excluded = self::isNotBilled($row);
        // Preserve the explicit exclusion even for an incomplete form row.
        if ($excluded) {
            $result['billed_days'] = 0;
            $result['billed_value'] = '0.00';
        }
        try {
            $admission = self::date($row['admission_date'] ?? '', 'Data de admissão');
            $departure = self::date($departure, 'Data de saída', true);
            if ($departure !== null && $departure < $admission) return $result;
        } catch (\InvalidArgumentException $e) {
            return $result;
        }
        $days = self::employmentDays($admission, $departure, $month);
        $result['automatic_billed_days'] = $days;
        $rawOverride = isset($row['billing_days_overrides']) && is_array($row['billing_days_overrides'])
            ? ($row['billing_days_overrides'][$month] ?? null) : null;
        if ($rawOverride !== null && $rawOverride !== '') {
            $validInteger = is_int($rawOverride) || (is_string($rawOverride) && preg_match('/\A(?:0|[1-9]\d*)\z/D', $rawOverride));
            if (!$validInteger || (int)$rawOverride < 0 || (int)$rawOverride > $days) return $result;
            $result['billed_days_override'] = (int)$rawOverride;
        }
        if ($excluded) return $result;
        if ($days === 0) {
            $result['billed_days'] = 0;
            $result['billed_value'] = '0.00';
            return $result;
        }
        $result['billing_first_day'] = max($monthStart->format('Y-m-d'), $admission);
        $result['billing_last_day'] = min($monthEnd->format('Y-m-d'), $departure ?? $monthEnd->format('Y-m-d'));
        $result['billed_days'] = $result['billed_days_override'] ?? $days;
        try {
            $monthly = self::money($row['monthly_value'] ?? '', 'Valor mensal');
        } catch (\InvalidArgumentException $e) {
            return $result;
        }
        $monthlyCents = (int)str_replace('.', '', $monthly);
        // Divide only at the end: no premature rounding of the daily rate.
        $billedCents = intdiv($monthlyCents * $result['billed_days'] * 2 + $monthDays, $monthDays * 2);
        $result['billed_value'] = number_format($billedCents / 100, 2, '.', '');
        return $result;
    }

    /**
     * Departure is the last active day. The denominator counts every billed professional in the month.
     * Every included departure in the three-month window counts by its date.
     * Its optional reason is an analytical subdivision and never gates INS10.
     */
    public static function summarize(array $rows, string $month): array
    {
        if (!preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $month)) {
            throw new \InvalidArgumentException('Mês inválido para apuração do cadastro de profissionais.');
        }
        $rows = self::rows($rows);
        $first = new \DateTimeImmutable($month . '-01', new \DateTimeZone('America/Sao_Paulo'));
        $monthStart = $first->format('Y-m-d');
        $monthEnd = $first->modify('last day of this month')->format('Y-m-d');
        $windowStart = $first->modify('-2 months')->format('Y-m-d');
        $active = $departures = $classifiedReplacements = $reductions = $other = $unclassified = $monthlyValueCents = $billedTotal = 0;
        $billedDaysTotal = $billedValueCents = $billingProfessionals = 0;
        $annotated = [];
        $activeIds = $departureIds = $classifiedReplacementIds = $reductionIds = $unclassifiedIds = $unbilledIds = $billingProfessionalIds = [];
        foreach ($rows as $row) {
            $row = array_replace($row, self::billingForMonth($row, $month));
            $billedDaysTotal += $row['billed_days'];
            $billedValueCents += (int)str_replace('.', '', $row['billed_value']);
            $countedBilling = !$row['not_billed'] && $row['billed_days'] > 0;
            if ($countedBilling) { $billingProfessionals++; $billingProfessionalIds[] = $row['id']; }
            $departure = $row['departure_date'];
            $included = !$row['not_billed'];
            if ($included) $billedTotal++;
            else $unbilledIds[] = $row['id'];
            $atMonthEnd = $included && $row['admission_date'] <= $monthEnd && ($departure === null || $departure >= $monthEnd);
            $inMonth = $row['admission_date'] <= $monthEnd && ($departure === null || $departure >= $monthStart);
            $inWindow = $included && $departure !== null && $departure >= $windowStart && $departure <= $monthEnd;
            if ($atMonthEnd) {
                $active++;
                $activeIds[] = $row['id'];
                $monthlyValueCents += (int)str_replace('.', '', $row['monthly_value']);
            }
            if ($inWindow) {
                $departures++;
                $departureIds[] = $row['id'];
                if ($row['departure_reason'] === 'replacement') { $classifiedReplacements++; $classifiedReplacementIds[] = $row['id']; }
                elseif ($row['departure_reason'] === 'contractor_reduction') { $reductions++; $reductionIds[] = $row['id']; }
                elseif ($row['departure_reason'] === 'other') $other++;
                else { $unclassified++; $unclassifiedIds[] = $row['id']; }
            }
            $row['relevant_month'] = $inMonth;
            $row['counted_billed_month'] = $countedBilling;
            $row['departure_in_window'] = $inWindow;
            $row['active_at_month_end'] = $atMonthEnd;
            $row['counted_departure'] = $inWindow;
            // Compatibility alias for existing exports: all date-eligible exits.
            $row['counted_replacement'] = $inWindow;
            $row['classification_label'] = $departure === null ? 'Sem saída registrada' : self::departureReasonLabel($row['departure_reason']);
            $row['reason'] = $row['not_billed'] ? 'Não Faturar marcado: não compõe a equipe, as saídas nem o valor mensal do INS10.' : ($inWindow ? 'Saída registrada dentro da janela de três meses; contabilizada pela data, independentemente do motivo.'
                : ($countedBilling ? 'Profissional faturado na competência, inclusive quando proporcional; compõe a equipe de referência.' : 'Sem dias faturados na competência e fora da janela de saídas.'));
            $annotated[] = $row;
        }
        $configured = count($rows) > 0;
        $note = 'Equipe de referência: cada profissional incluído com pelo menos um dia faturado na competência conta uma vez, inclusive admissões e saídas com faturamento proporcional; a data de saída é o último dia ativo. '
            . 'A janela de saídas vai de ' . $windowStart . ' a ' . $monthEnd . '. '
            . 'Profissionais com “Não Faturar” marcado permanecem no cadastro, mas não entram na equipe, nas saídas ou no valor mensal do INS10. Desmarcado significa inclusão conforme as datas do vínculo. '
            . 'Todas as saídas incluídas na janela contam pela data. O motivo da saída é opcional e apenas descritivo; não altera a contagem nem impede o cálculo. '
            . 'O valor mensal é o valor cadastrado; sua soma de referência usa os profissionais incluídos e ativos no fechamento. '
            . 'O valor faturado é proporcional aos dias corridos do vínculo dentro da competência, incluindo admissão e saída, com possibilidade de ajuste dos dias por competência: valor mensal × dias faturados ÷ dias do mês, com arredondamento final por profissional. O ajuste não altera as datas do vínculo nem as competências anteriores. '
            . 'Não Faturar zera os dias e o valor faturado, preservando o valor mensal cadastrado. O status Ativo/Inativo indica apenas ausência/presença de data de saída; não altera os cálculos de meses anteriores. Não há encargos nem reajustes históricos automáticos.';
        if (!$configured) $note .= ' Cadastro de profissionais não preenchido: cadastre os profissionais e suas datas para calcular o indicador.';
        if ($configured && $billingProfessionals === 0) $note .= ' Não há profissionais incluídos com dias faturados na competência; o percentual não se aplica por ausência de equipe de referência.';
        return [
            'rows' => $annotated, 'month' => $month, 'window_start' => $windowStart, 'window_end' => $monthEnd,
            'month_end' => $monthEnd, 'configured' => $configured, 'complete' => $configured,
            'active_total' => $active, 'departures_total' => $departures,
            // Compatibility aliases retain the metric's count; explicit reasons have their own subdivision.
            'replacements' => $departures, 'classified_replacements' => $classifiedReplacements, 'reductions' => $reductions,
            'billed_total' => $billedTotal, 'unbilled_total' => count($unbilledIds),
            'other_departures' => $other, 'unclassified_departures' => $unclassified,
            'pending_departures' => 0,
            'numerator' => $configured ? $departures : null, 'denominator' => $configured ? $billingProfessionals : null,
            'value' => $configured && $billingProfessionals > 0 ? $departures * 100 / $billingProfessionals : null,
            'monthly_value_total' => number_format($monthlyValueCents / 100, 2, '.', ''),
            'billed_days_total' => $billedDaysTotal,
            'billed_value_total' => number_format($billedValueCents / 100, 2, '.', ''),
            'billing_professionals_total' => $billingProfessionals,
            'billing_professional_ids' => $billingProfessionalIds,
            'billing_month_days' => (int)$first->format('t'),
            'active_ids' => $activeIds, 'departure_ids' => $departureIds, 'replacement_ids' => $departureIds,
            'classified_replacement_ids' => $classifiedReplacementIds,
            'reduction_ids' => $reductionIds, 'unclassified_ids' => $unclassifiedIds, 'pending_ids' => [],
            'unbilled_ids' => $unbilledIds,
            'method' => 'Saídas de profissionais incluídos nos três meses até o fechamento ÷ profissionais faturados na competência, inclusive proporcionalmente, × 100. O motivo da saída é apenas descritivo.',
            'note' => $note,
        ];
    }

    private static function text($value, string $label, int $limit, bool $required = true): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException($label . ': informe um texto válido.');
        $value = trim($value);
        if (($required && $value === '') || strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) || !preg_match('//u', $value)) {
            throw new \InvalidArgumentException($label . ': texto vazio, inválido ou acima do limite de ' . $limit . ' bytes.');
        }
        return $value;
    }

    private static function date($value, string $label, bool $optional = false): ?string
    {
        if ($optional && ($value === null || $value === '')) return null;
        if (!is_string($value) || !preg_match('/\A(19\d{2}|20\d{2}|2100)-(\d{2})-(\d{2})\z/D', $value, $match)
            || !checkdate((int)$match[2], (int)$match[3], (int)$match[1])) {
            throw new \InvalidArgumentException($label . ': informe uma data válida no formato AAAA-MM-DD, entre 1900 e 2100.');
        }
        return $value;
    }

    private static function money($value, string $label): string
    {
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException($label . ': informe um valor em reais com até duas casas decimais.');
        $value = trim((string)$value);
        $value = preg_replace('/\AR\$\s*/u', '', $value);
        if (strpos($value, ',') !== false) {
            if (!preg_match('/\A(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}\z/D', $value)) {
                throw new \InvalidArgumentException($label . ': formato monetário inválido.');
            }
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        if (!preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/D', $value)) {
            throw new \InvalidArgumentException($label . ': informe de R$ 0,00 a R$ 99.999.999,99, com até duas casas decimais.');
        }
        $parts = explode('.', $value, 2);
        return (string)(int)$parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    }
}
