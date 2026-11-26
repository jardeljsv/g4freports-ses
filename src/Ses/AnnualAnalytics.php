<?php

namespace GlpiPlugin\G4freports\Ses;

/**
 * Presentation analytics over pinned summaries only. No source reads or KPI recalculation.
 *
 * All Trend.rows and chart series align with months. Chart categories are YYYY-MM-01;
 * values retain their original units: whole percentages, counts, seconds or integer cents.
 * Exporters may convert display units, but must retain nulls and line_visible=false.
 * A visible line joins only consecutive calendar months with confirmed equivalent scope.
 */
final class AnnualAnalytics
{
    public static function build(array $report): array
    {
        if (($report['schema_version'] ?? null) !== 1 || !is_array($report['months'] ?? null)
            || !$report['months'] || count($report['months']) > 24) {
            throw new \InvalidArgumentException('Relatório arquivado incompatível para análise.');
        }
        $months = []; $summaries = []; $activity = [];
        $coverage = ['selected' => count($report['months']), 'archived' => 0, 'closed' => 0,
            'provisional' => 0, 'unknown' => 0, 'missing' => 0];
        foreach ($report['months'] as $record) {
            $month = $record['month'] ?? null;
            if (!is_string($month) || !preg_match('/\A(?:20\d{2}|2100)-(?:0[1-9]|1[0-2])\z/D', $month)
                || ($months && $month <= $months[count($months) - 1])) {
                throw new \InvalidArgumentException('A análise exige competências únicas em ordem cronológica.');
            }
            $months[] = $month;
            $summary = is_array($record['summary'] ?? null) && ($record['status'] ?? '') === 'available' ? $record['summary'] : null;
            $summaries[$month] = $summary;
            if ($summary === null) $coverage['missing']++;
            else {
                $coverage['archived']++;
                $state = $summary['period']['completeness'] ?? 'unknown';
                $coverage[$state === 'complete' ? 'closed' : ($state === 'provisional' ? 'provisional' : 'unknown')]++;
            }
            $activity[] = ['month' => $month, 'activity' => is_array($summary['activity'] ?? null) ? $summary['activity'] : null];
        }
        $metrics = []; $matrix = [];
        for ($n = 1; $n <= 12; $n++) {
            $id = 'INS' . $n; $rows = []; $label = $id;
            foreach ($months as $month) {
                $summary = $summaries[$month]; $metric = $summary['metrics'][$id] ?? [];
                $label = is_string($metric['title'] ?? null) ? $metric['title'] : ($metric['label'] ?? $label);
                $row = self::row($month, $summary, self::number($metric['value'] ?? null), $metric['status'] ?? 'missing');
                $row['target'] = self::number($metric['target'] ?? null);
                $row['numerator'] = self::number($metric['numerator'] ?? null);
                $row['denominator'] = self::number($metric['denominator'] ?? null);
                $row['label'] = $metric['label'] ?? $id; $row['title'] = $metric['title'] ?? $row['label'];
                $row['direction'] = $metric['direction'] ?? null;
                $row['method'] = $metric['method'] ?? ''; $row['note'] = $metric['note'] ?? '';
                $row['fingerprint'] = !empty($metric['comparability']['known']) && self::hash($metric['comparability']['fingerprint'] ?? null)
                    ? $metric['comparability']['fingerprint'] : null;
                $row['eligible'] = $row['eligible'] && $row['fingerprint'] !== null
                    && in_array($row['status'], ['met', 'unmet'], true) && in_array($row['direction'], ['gte', 'lte'], true)
                    && $row['target'] !== null
                    && $row['numerator'] !== null && $row['numerator'] >= 0 && $row['denominator'] !== null && $row['denominator'] > 0;
                $row['margin_pp'] = $row['value'] !== null && $row['target'] !== null && in_array($row['direction'], ['gte', 'lte'], true)
                    ? ($row['value'] - $row['target']) * ($row['direction'] === 'lte' ? -1 : 1) : null;
                $row['shortfall_pp'] = $row['margin_pp'] === null ? null : max(0, -$row['margin_pp']);
                $rows[] = $row;
            }
            $metrics[$id] = self::trend($id, $label, 'percent', $rows);
            $cells = [];
            foreach ($metrics[$id]['rows'] as $row) $cells[] = array_intersect_key($row, array_flip(['month', 'status', 'period_completeness', 'value', 'target', 'eligible', 'target_evaluable']));
            $matrix[] = ['id' => $id, 'label' => $label, 'cells' => $cells];
        }
        $volumes = [];
        foreach (['tickets' => ['tickets', 'Chamados solucionados', 'glpi'],
            'calls' => ['calls_received', 'Ligações consideradas', 'phone'],
            'excluded_calls' => ['calls_excluded', 'Ligações de teste excluídas', 'phone']] as $id => [$field, $label, $scope]) {
            $rows = [];
            foreach ($months as $month) {
                $summary = $summaries[$month]; $value = self::count($summary['totals'][$field] ?? null);
                $row = self::row($month, $summary, $value, $value === null ? 'unavailable' : 'available');
                $row['fingerprint'] = self::volumeScope($summary, $scope);
                $row['eligible'] = $row['eligible'] && $row['fingerprint'] !== null;
                $rows[] = $row;
            }
            $volumes[$id] = self::trend($id, $label, 'count', $rows);
        }
        $durations = [];
        foreach (['tma' => ['mean_talk_seconds', 'mean_talk_count', 'TMA · conversas com duração válida', 'INS7'],
            'tme' => ['mean_wait_seconds', 'mean_wait_count', 'TME · esperas com duração válida', 'INS8']] as $id => [$field, $count, $label, $metricId]) {
            $rows = [];
            foreach ($months as $month) {
                $summary = $summaries[$month]; $value = self::number($summary['totals'][$field] ?? null);
                if ($value !== null && $value < 0) $value = null;
                $status = $summary['metrics'][$metricId]['status'] ?? 'unavailable';
                $row = self::row($month, $summary, $value, $status);
                $row['sample_count'] = self::count($summary['totals'][$count] ?? null);
                $row['fingerprint'] = self::volumeScope($summary, 'phone');
                // Unfinished/invalid duration evidence must not acquire a conclusive trend from a surviving sample.
                $row['eligible'] = $row['eligible'] && $row['fingerprint'] !== null && ($row['sample_count'] ?? 0) > 0
                    && in_array($status, ['met', 'unmet'], true);
                $rows[] = $row;
            }
            $durations[$id] = self::trend($id, $label, 'seconds', $rows);
        }
        $financial = $report['financial'] ?? []; $financialTrends = [];
        $financialRows = [];
        foreach ($financial['months'] ?? [] as $row) $financialRows[$row['month']] = $row;
        foreach (['gross' => 'Base faturada', 'deduction' => 'Glosas aplicadas', 'adjustment' => 'Ajustes financeiros', 'net' => 'Valor líquido'] as $id => $label) {
            $rows = [];
            foreach ($months as $month) {
                $summary = $summaries[$month]; $original = $financialRows[$month] ?? [];
                $value = is_int($original[$id . '_cents'] ?? null) ? $original[$id . '_cents'] : null;
                $row = self::row($month, $summary, $value, $original['status'] ?? 'missing');
                $row['fingerprint'] = self::financialScope($summary);
                $row['eligible'] = $row['eligible'] && !empty($original['complete']) && $row['fingerprint'] !== null;
                $rows[] = $row;
            }
            $financialTrends[$id] = self::trend($id, $label, 'cents', $rows);
        }
        return ['schema_version' => 1, 'months' => $months, 'coverage' => $coverage,
            'findings' => self::findings($coverage, $metrics, $volumes, $financialTrends),
            'metrics' => $metrics, 'volumes' => $volumes, 'durations' => $durations,
            'financial' => ['rows' => array_values($financialRows), 'subtotal_cents' => $financial['subtotal_cents'] ?? [],
                'coverage_label' => $financial['coverage_label'] ?? '', 'trends' => $financialTrends],
            'matrix' => $matrix, 'activity' => $activity];
    }

    private static function row(string $month, ?array $summary, $value, string $status): array
    {
        $period = $summary['period']['completeness'] ?? 'unknown';
        return ['month' => $month, 'value' => $value, 'status' => $summary === null ? 'missing' : $status,
            'period_completeness' => $period, 'eligible' => $summary !== null && $period === 'complete' && $value !== null];
    }

    private static function trend(string $id, string $label, string $unit, array $rows): array
    {
        $coverage = ['observations' => 0, 'eligible' => 0, 'comparable_pairs' => 0, 'target_met' => 0, 'target_evaluated' => 0];
        $previous = null;
        foreach ($rows as &$row) {
            if ($row['value'] !== null) $coverage['observations']++;
            if ($row['eligible']) $coverage['eligible']++;
            if ($unit === 'percent') {
                // Monthly compliance is an archived fact. Unknown source identity blocks a
                // between-month comparison, not this month's finalized target assessment.
                $row['target_evaluable'] = $row['period_completeness'] === 'complete'
                    && in_array($row['status'], ['met', 'unmet'], true) && $row['value'] !== null && $row['target'] !== null
                    && in_array($row['direction'], ['gte', 'lte'], true) && $row['numerator'] !== null
                    && $row['numerator'] >= 0 && $row['denominator'] !== null && $row['denominator'] > 0;
                if ($row['target_evaluable']) { $coverage['target_evaluated']++; if ($row['status'] === 'met') $coverage['target_met']++; }
            }
            $comparison = ['previous_month' => $previous['month'] ?? null, 'eligible' => false,
                'delta_absolute' => null, 'delta_percent' => null, 'delta_pp' => null, 'direction' => 'unavailable', 'reason' => ''];
            if ($previous === null) $comparison['reason'] = 'Primeira competência selecionada; sem comparação.';
            elseif (self::monthNumber($row['month']) - self::monthNumber($previous['month']) !== 1) $comparison['reason'] = 'Intervalo entre meses não consecutivos; a linha e a variação mensal foram interrompidas.';
            elseif (!$previous['eligible'] || !$row['eligible']) $comparison['reason'] = 'Valor ausente, provisório ou sem evidência e contexto suficientes para comparação.';
            elseif ($previous['fingerprint'] !== $row['fingerprint'] || ($unit === 'percent'
                && ($previous['direction'] !== $row['direction'] || $previous['target'] != $row['target']))) $comparison['reason'] = 'Origem, escopo ou critérios diferentes; comparação direta interrompida.';
            else {
                $delta = $row['value'] - $previous['value'];
                // Cents must remain integers, including differences close to the platform limit.
                if (!is_finite((float)$delta) || ($unit === 'cents' && !is_int($delta))) $comparison['reason'] = 'Variação acima do limite numérico seguro.';
                else {
                    $comparison['eligible'] = true; $comparison['delta_absolute'] = $delta;
                    $relative = $previous['value'] > 0 ? 100 * ($delta / $previous['value']) : null;
                    $comparison['delta_percent'] = $unit !== 'percent' && $relative !== null && is_finite((float)$relative) ? $relative : null;
                    $comparison['delta_pp'] = $unit === 'percent' ? $delta : null;
                    $comparison['direction'] = $delta == 0 ? 'unchanged' : ($unit === 'percent'
                        ? (($row['direction'] === 'lte' ? $delta < 0 : $delta > 0) ? 'improved' : 'declined')
                        : ($delta > 0 ? 'increased' : 'decreased'));
                    $comparison['reason'] = 'Competências consecutivas com critérios equivalentes.'
                        . ($unit !== 'percent' && $previous['value'] <= 0 ? ' Base anterior nula ou não positiva; variação relativa não calculada.' : '');
                    $coverage['comparable_pairs']++;
                }
            }
            $row['comparison'] = $comparison; $previous = $row;
        }
        unset($row);
        $chart = self::chart($rows, $unit === 'percent');
        foreach ($rows as &$row) unset($row['fingerprint']);
        unset($row);
        return ['id' => $id, 'label' => $label, 'unit' => $unit, 'rows' => $rows, 'coverage' => $coverage, 'chart' => $chart];
    }

    private static function chart(array $rows, bool $targets): array
    {
        $length = count($rows); $series = []; $runs = []; $run = [];
        $provisional = $unverified = array_fill(0, $length, null);
        foreach ($rows as $index => $row) {
            if ($row['eligible']) {
                if ($run && !$row['comparison']['eligible']) { $runs[] = $run; $run = []; }
                $run[] = $index;
            } else {
                if ($run) { $runs[] = $run; $run = []; }
                if ($row['period_completeness'] === 'provisional' || $row['status'] === 'provisional') $provisional[$index] = $row['value'];
                else $unverified[$index] = $row['value'];
            }
        }
        if ($run) $runs[] = $run;
        foreach ($runs as $run) {
            $values = array_fill(0, $length, null);
            foreach ($run as $index) $values[$index] = $rows[$index]['value'];
            $series[] = self::series('Resultado', 'result', $values, count($run) > 1, 'circle');
        }
        if (self::hasNumbers($provisional)) $series[] = self::series('Provisório', 'provisional', $provisional, false, 'diamond');
        if (self::hasNumbers($unverified)) $series[] = self::series('Sem comparação confirmada', 'unverified', $unverified, false, 'diamond');
        if ($targets) {
            $isolated = array_fill(0, $length, null);
            foreach ($rows as $index => $row) if (!$row['eligible']) $isolated[$index] = $row['target'] ?? null;
            foreach ($runs as $run) {
                $values = array_fill(0, $length, null);
                foreach ($run as $index) $values[$index] = $rows[$index]['target'] ?? null;
                if (self::hasNumbers($values)) $series[] = self::series('Meta arquivada', 'target', $values, count($run) > 1, 'none');
            }
            if (self::hasNumbers($isolated)) $series[] = self::series('Meta arquivada', 'target', $isolated, false, 'diamond');
        }
        $seen = [];
        foreach ($series as &$item) { $item['show_legend'] = !isset($seen[$item['role']]); $seen[$item['role']] = true; }
        unset($item);
        return ['categories' => array_map(static fn($row) => $row['month'] . '-01', $rows), 'series' => $series];
    }

    private static function series(string $name, string $role, array $values, bool $line, string $marker): array
    {
        return ['name' => $name, 'role' => $role, 'values' => $values, 'line_visible' => $line,
            'marker' => $line ? $marker : ($marker === 'none' ? 'diamond' : $marker), 'line_dash' => $role === 'target' ? 'dash' : 'solid'];
    }

    /** Volume scope omits target/priority/eligibility settings that do not select the overall cohort. */
    private static function volumeScope(?array $summary, string $source): ?string
    {
        if ($summary === null) return null;
        $context = $summary['metrics'][$source === 'glpi' ? 'INS1' : 'INS7']['comparability']['context'] ?? [];
        $keys = ['rules_version', 'snapshot_schema', 'timezone'];
        $keys = array_merge($keys, $source === 'glpi' ? ['source_glpi', 'entity_id', 'group_ids', 'date_basis']
            : ['source_callcenter', 'queue_filter', 'test_numbers_revision', 'call_date_basis']);
        $scope = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $context) || $context[$key] === null) return null;
            $scope[$key] = $context[$key];
        }
        if (!is_string($scope['rules_version']) || $scope['rules_version'] === '' || !is_int($scope['snapshot_schema'])
            || !is_string($scope['timezone']) || $scope['timezone'] === '') return null;
        if ($source === 'glpi') {
            if (!self::hash($scope['source_glpi']) || !is_int($scope['entity_id']) || !is_array($scope['group_ids']) || $scope['date_basis'] !== 'solvedate') return null;
            foreach ($scope['group_ids'] as $id) if (!is_int($id)) return null;
            sort($scope['group_ids'], SORT_NUMERIC);
        } elseif (!self::hash($scope['source_callcenter']) || !self::hash($scope['test_numbers_revision'])
            || !is_string($scope['queue_filter']) || $scope['queue_filter'] === '' || $scope['call_date_basis'] !== 'queue_entry') return null;
        return hash('sha256', json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function financialScope(?array $summary): ?string
    {
        if (($summary['schema_version'] ?? null) !== 1) return null;
        // These are observed BRL amounts, not a like-for-like quality/cost-efficiency claim.
        // Roster/rate changes, management overrides and KPI scope changes may explain the
        // movement; they must not hide otherwise complete archived financial observations.
        return hash('sha256', 'monthly-summary-v1:observed-financial-amount:BRL-cents');
    }

    /** A short set of traceable observations; no causal attribution or annual KPI. */
    private static function findings(array $coverage, array $metrics, array $volumes, array $financial): array
    {
        $findings = [['kind' => 'coverage', 'months' => [], 'text' => $coverage['closed'] . ' de ' . $coverage['selected']
            . ' competências selecionadas estão encerradas; ' . $coverage['missing'] . ' sem revisão, '
            . $coverage['provisional'] . ' provisórias e ' . $coverage['unknown'] . ' com encerramento não confirmado.']];
        $latestClosed = null;
        foreach ($metrics['INS1']['rows'] as $index => $row) if ($row['period_completeness'] === 'complete') $latestClosed = $index;
        if ($latestClosed !== null) {
            $evaluated = 0; $unmet = [];
            foreach ($metrics as $id => $trend) {
                $row = $trend['rows'][$latestClosed];
                if (!$row['target_evaluable']) continue;
                $evaluated++;
                if ($row['status'] === 'unmet') $unmet[] = ['id' => $id, 'row' => $row];
            }
            if ($unmet) {
                usort($unmet, static fn($a, $b) => $b['row']['shortfall_pp'] <=> $a['row']['shortfall_pp']);
                $row = $unmet[0]['row'];
                $distance = round((float)$row['shortfall_pp'], 2) > 0
                    ? ' Maior distância observada: ' . $unmet[0]['id'] . ', ' . self::format($row['shortfall_pp']) . ' p.p. aquém da meta.'
                    : ' A situação fora da meta é a registrada no fechamento mensal.';
                $findings[] = ['kind' => 'target', 'metric_id' => $unmet[0]['id'], 'months' => [$row['month']],
                    'text' => count($unmet) . ' de ' . $evaluated . ' indicadores finalizados ficaram fora da meta arquivada em '
                        . $row['month'] . '.' . $distance];
            }
        }
        foreach (['declined' => 'piorou', 'improved' => 'melhorou'] as $direction => $verb) {
            $candidates = [];
            foreach ($metrics as $id => $trend) foreach ($trend['rows'] as $row) {
                if ($row['comparison']['eligible'] && $row['comparison']['direction'] === $direction
                    && round(abs($row['comparison']['delta_pp']), 2) > 0) $candidates[] = ['id' => $id, 'row' => $row];
            }
            usort($candidates, static function ($a, $b) {
                $date = strcmp($b['row']['month'], $a['row']['month']);
                return $date ?: (abs($b['row']['comparison']['delta_pp']) <=> abs($a['row']['comparison']['delta_pp']));
            });
            if (!$candidates) continue;
            $best = $candidates[0]; $row = $best['row']; $comparison = $row['comparison'];
            $margin = $row['status'] === 'met' ? ' e atendeu à meta arquivada'
                : ($row['status'] === 'unmet' ? (round((float)$row['shortfall_pp'], 2) > 0
                    ? ' e ficou ' . self::format($row['shortfall_pp']) . ' p.p. aquém da meta'
                    : ' e permaneceu fora da meta segundo a situação mensal arquivada') : '');
            $findings[] = ['kind' => 'metric_' . $direction, 'metric_id' => $best['id'],
                'months' => [$comparison['previous_month'], $row['month']],
                'text' => $best['id'] . ' ' . $verb . ' ' . self::format(abs($comparison['delta_pp'])) . ' p.p. entre '
                    . $comparison['previous_month'] . ' e ' . $row['month'] . $margin . '. Base atual: '
                    . self::format($row['numerator']) . ' / ' . self::format($row['denominator']) . '.'];
        }
        foreach (['tickets', 'calls'] as $id) {
            $trend = $volumes[$id]; $candidate = null;
            foreach ($trend['rows'] as $row) if ($row['comparison']['eligible'] && $row['comparison']['delta_absolute'] != 0) $candidate = $row;
            if ($candidate === null) continue;
            $c = $candidate['comparison'];
            $findings[] = ['kind' => 'volume', 'months' => [$c['previous_month'], $candidate['month']],
                'text' => $trend['label'] . ': ' . ($c['delta_absolute'] > 0 ? '+' : '') . self::format($c['delta_absolute'])
                    . ($c['delta_percent'] === null ? ' (base anterior zero; percentual não calculado)' : ' (' . ($c['delta_percent'] > 0 ? '+' : '') . self::format($c['delta_percent']) . '%)')
                    . ' entre ' . $c['previous_month'] . ' e ' . $candidate['month'] . '. Variação de volume, sem conclusão sobre qualidade.'];
            break;
        }
        $candidate = null;
        foreach ($financial['net']['rows'] as $row) if ($row['comparison']['eligible'] && $row['comparison']['delta_absolute'] != 0) $candidate = $row;
        if ($candidate !== null && count($findings) < 5) {
            $c = $candidate['comparison'];
            $findings[] = ['kind' => 'financial', 'months' => [$c['previous_month'], $candidate['month']],
                'text' => 'Valor líquido ' . ($c['delta_absolute'] > 0 ? 'aumentou' : 'diminuiu') . ' R$ '
                    . self::money(abs($c['delta_absolute'])) . ' entre ' . $c['previous_month'] . ' e ' . $candidate['month']
                    . '. Mudança financeira; não comprova economia nem alteração da qualidade.'];
        }
        return array_slice($findings, 0, 5);
    }

    private static function number($value) { return (is_int($value) || is_float($value)) && is_finite((float)$value) ? $value : null; }
    private static function count($value): ?int { return is_int($value) && $value >= 0 ? $value : null; }
    private static function hash($value): bool { return is_string($value) && (bool)preg_match('/\A[a-f0-9]{64}\z/D', $value); }
    private static function hasNumbers(array $values): bool { foreach ($values as $value) if ($value !== null) return true; return false; }
    private static function monthNumber(string $month): int { return (int)substr($month, 0, 4) * 12 + (int)substr($month, 5, 2); }
    private static function format($value): string { return number_format((float)$value, floor((float)$value) === (float)$value ? 0 : 2, ',', '.'); }
    private static function money(int $cents): string { return number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT); }
}
