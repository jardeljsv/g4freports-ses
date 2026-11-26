<?php

namespace GlpiPlugin\G4freports\Ses;

/** Analytical comparison of explicitly pinned archived monthly summaries; no extraction or recalculation. */
final class AnnualReport
{
    public static function build(array $records, array $months): array
    {
        if (!$months || count($months) > 24) throw new \InvalidArgumentException('Selecione de 1 a 24 competências.');
        $selected = [];
        foreach ($months as $month) {
            if (!self::validMonth($month) || isset($selected[$month])) throw new \InvalidArgumentException('Competências inválidas ou repetidas.');
            $selected[$month] = true;
        }
        $months = array_keys($selected); sort($months, SORT_STRING);
        $pinned = []; $ids = [];
        foreach ($records as $record) {
            if (!is_array($record) || !self::validMonth($record['month'] ?? null)
                || !isset($selected[$record['month']]) || !is_string($record['id'] ?? null) || $record['id'] === ''
                || isset($pinned[$record['month']]) || isset($ids[$record['id']])) {
                throw new \InvalidArgumentException('Fixe exatamente uma revisão por competência selecionada, sem duplicidades.');
            }
            $summary = $record['summary'] ?? null;
            if (!is_array($summary) || ($summary['schema_version'] ?? null) !== 1 || ($summary['month'] ?? null) !== $record['month']
                || !is_array($summary['metrics'] ?? null)) throw new \InvalidArgumentException('Resumo mensal arquivado incompatível ou inválido.');
            $pinned[$record['month']] = $record; $ids[$record['id']] = true;
        }
        $monthly = []; $missing = []; $financialMonths = []; $completeMonths = []; $excludedMonths = [];
        $subtotal = ['gross' => 0, 'deduction' => 0, 'adjustment' => 0, 'net' => 0];
        foreach ($months as $month) {
            $record = $pinned[$month] ?? null;
            $summary = $record['summary'] ?? null;
            $monthly[] = ['month' => $month, 'status' => $record ? 'available' : 'missing',
                'record_id' => $record['id'] ?? null, 'archived_at' => $record['archived_at'] ?? null, 'summary' => $summary];
            if (!$record) $missing[] = $month;
            $f = is_array($summary['financial'] ?? null) ? $summary['financial'] : [];
            $available = !empty($f['available']);
            $periodCompleteness = $summary['period']['completeness'] ?? 'unknown';
            $periodComplete = $periodCompleteness === 'complete';
            $complete = $available && !empty($f['complete']) && $periodComplete;
            foreach (array_keys($subtotal) as $name) if (!is_int($f[$name . '_cents'] ?? null)) $complete = false;
            $financialNotes = is_array($f['notes'] ?? null) ? $f['notes'] : [];
            if ($record && !$periodComplete) $financialNotes[] = 'Encerramento mensal provisório ou não confirmado; valores preservados e excluídos do subtotal.';
            $financialMonths[] = ['month' => $month,
                'status' => !$record ? 'missing' : (!$available ? 'unavailable' : ($complete ? 'complete'
                    : ($periodCompleteness === 'provisional' ? 'provisional' : 'incomplete'))),
                'available' => $available, 'complete' => $complete, 'financial_complete' => !empty($f['complete']),
                'period_completeness' => $periodCompleteness,
                'gross_cents' => $f['gross_cents'] ?? null, 'deduction_cents' => $f['deduction_cents'] ?? null,
                'adjustment_cents' => $f['adjustment_cents'] ?? null, 'net_cents' => $f['net_cents'] ?? null,
                'notes' => $financialNotes];
            if ($complete) {
                $completeMonths[] = $month;
                foreach (array_keys($subtotal) as $name) {
                    $cents = $f[$name . '_cents'];
                    if (($cents > 0 && $subtotal[$name] > PHP_INT_MAX - $cents)
                        || ($cents < 0 && $subtotal[$name] < PHP_INT_MIN - $cents)) throw new \InvalidArgumentException('Subtotal financeiro acima do limite seguro.');
                    $subtotal[$name] += $cents;
                }
            } else $excludedMonths[] = $month;
        }
        $metrics = [];
        for ($n = 1; $n <= 12; $n++) {
            $id = 'INS' . $n; $rows = []; $previous = null; $previousMonth = null;
            $label = $id; $direction = in_array($id, ['INS5', 'INS9', 'INS10'], true) ? 'lte' : 'gte';
            foreach ($months as $month) {
                $metric = $pinned[$month]['summary']['metrics'][$id] ?? null;
                if (!is_array($metric)) $metric = null;
                if ($metric !== null) $metric['period_completeness'] = $pinned[$month]['summary']['period']['completeness'] ?? 'unknown';
                if ($metric !== null) { $label = $metric['label'] ?? $id; $direction = $metric['direction'] ?? $direction; }
                $rows[] = ['month' => $month, 'status' => $metric['status'] ?? 'missing',
                    'label' => $metric['label'] ?? $id, 'title' => $metric['title'] ?? $metric['label'] ?? $id,
                    'direction' => $metric['direction'] ?? $direction, 'value' => $metric['value'] ?? null,
                    'raw_ratio' => $metric['raw_ratio'] ?? null, 'numerator' => $metric['numerator'] ?? null,
                    'denominator' => $metric['denominator'] ?? null, 'target' => $metric['target'] ?? null,
                    'method' => $metric['method'] ?? '', 'note' => $metric['note'] ?? '',
                    'period_completeness' => $metric['period_completeness'] ?? 'unknown',
                    'comparison' => self::compare($previous, $metric, $previousMonth, $month)];
                // A missing selected month remains the predecessor; never bridge it silently.
                $previous = $metric; $previousMonth = $month;
            }
            $metrics[$id] = ['id' => $id, 'label' => $label, 'direction' => $direction, 'months' => $rows];
        }
        $warnings = ['Valores e textos são os das revisões mensais fixadas. Nenhuma base de origem foi consultada para esta análise.',
            'Variações são expressas em pontos percentuais; não há percentual anual agregado nem média anual de TMA/TME.',
            'A comparação descreve a variação observada e não comprova sua causa.'];
        if ($missing) $warnings[] = 'Competências sem revisão selecionada: ' . implode(', ', $missing) . '. Ausência não representa zero.';
        return ['schema_version' => 1, 'months' => $monthly, 'metrics' => $metrics,
            'coverage' => ['requested' => count($months), 'available' => count($pinned), 'missing_months' => $missing],
            'financial' => ['months' => $financialMonths, 'complete_months' => $completeMonths, 'excluded_months' => $excludedMonths,
                'coverage_label' => 'Subtotal de ' . count($completeMonths) . ' de ' . count($months) . ' competências selecionadas com financeiro completo.',
                'subtotal_cents' => $completeMonths ? $subtotal : ['gross' => null, 'deduction' => null, 'adjustment' => null, 'net' => null],
                'is_partial' => count($completeMonths) !== count($months)], 'warnings' => $warnings];
    }

    private static function compare(?array $before, ?array $after, ?string $previousMonth, string $month): array
    {
        $gap = $previousMonth === null ? 0 : self::monthNumber($month) - self::monthNumber($previousMonth) - 1;
        $interval = $previousMonth === null ? '' : $previousMonth . ' → ' . $month
            . ($gap > 0 ? ' (' . $gap . ' competência(s) não selecionada(s))' : '');
        $result = ['previous_month' => $previousMonth, 'delta_pp' => null, 'direction' => 'unavailable',
            'reason' => '', 'gap_months' => $gap, 'interval_label' => $interval];
        if ($previousMonth === null) $result['reason'] = 'Primeira competência selecionada; sem base de comparação.';
        elseif ($before === null || $after === null || ($before['status'] ?? '') === 'missing' || ($after['status'] ?? '') === 'missing') {
            $result['reason'] = 'Uma das competências selecionadas não contém este indicador arquivado.';
        } elseif (!in_array($before['status'] ?? '', ['met', 'unmet'], true) || !in_array($after['status'] ?? '', ['met', 'unmet'], true)) {
            $result['reason'] = 'Resultado pendente, provisório ou não aplicável; variação conclusiva indisponível.';
        } elseif (($before['period_completeness'] ?? '') !== 'complete' || ($after['period_completeness'] ?? '') !== 'complete') {
            $result['reason'] = 'Competência provisória ou encerramento mensal não confirmado na extração; variação conclusiva indisponível.';
        } elseif (empty($before['comparability']['known']) || empty($after['comparability']['known'])) {
            $result['reason'] = 'Origem ou critérios da extração não registrados integralmente; comparabilidade não confirmada.';
        } elseif (!is_string($before['comparability']['fingerprint'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $before['comparability']['fingerprint'])
            || ($before['comparability']['fingerprint'] ?? '') !== ($after['comparability']['fingerprint'] ?? '')
            || ($before['direction'] ?? '') !== ($after['direction'] ?? '')) {
            $result['reason'] = 'Origem, escopo ou critérios diferentes; valores mensais preservados sem comparação direta.';
        } elseif (!self::finite($before['value'] ?? null) || !self::finite($after['value'] ?? null)) {
            $result['reason'] = 'Percentual mensal indisponível.';
        } else {
            $delta = $after['value'] - $before['value'];
            $result['delta_pp'] = $delta;
            $result['direction'] = $delta == 0 ? 'unchanged' : (($after['direction'] === 'lte' ? $delta < 0 : $delta > 0) ? 'improved' : 'declined');
            $result['reason'] = $gap > 0 ? 'Comparação entre as competências selecionadas; intervalo não consecutivo.' : 'Comparação entre competências consecutivas com critérios equivalentes.';
        }
        return $result;
    }

    private static function finite($value): bool { return (is_int($value) || is_float($value)) && is_finite((float)$value); }
    private static function monthNumber(string $month): int { return (int)substr($month, 0, 4) * 12 + (int)substr($month, 5, 2); }
    private static function validMonth($month): bool { return is_string($month) && (bool)preg_match('/\A(?:20\d{2}|2100)-(?:0[1-9]|1[0-2])\z/D', $month); }
}
