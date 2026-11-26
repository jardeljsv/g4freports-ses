<?php

namespace GlpiPlugin\G4freports\Ses;

/** One financial calculation for the screen, Word and Excel snapshot. */
final class FinancialSummary
{
    /** Contract payment bands; percentage points, using unrounded metric results. */
    public static function rate(string $id, float $value): int
    {
        if (in_array($id, ['INS5', 'INS9', 'INS10'], true)) {
            $bounds = ['INS5' => [2, 4, 6, 8], 'INS9' => [5, 7, 9, 11], 'INS10' => [10, 15, 20, 25]][$id];
            foreach ($bounds as $rate => $bound) if ($value <= $bound) return $rate;
            return 4;
        }
        $target = in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true) ? 90
            : (in_array($id, ['INS7', 'INS8'], true) ? 85 : 80);
        for ($rate = 0; $rate < 4; $rate++) if ($value >= $target - 5 * $rate) return $rate;
        return 4;
    }

    public static function build(array $snapshot): array
    {
        $staff = $snapshot['staff_register'] ?? $snapshot['input']['staff_summary'] ?? [];
        $month = $snapshot['period']['month'] ?? $snapshot['input']['month'] ?? '';
        $config = FinanceSettings::fromSettings([FinanceSettings::CONFIG_KEY => $snapshot['input']['financial_config'] ?? []]);
        $monthConfig = $config['months'][$month] ?? ['deduction_overrides' => [], 'adjustments' => []];
        $roster = array_values(array_filter($staff['rows'] ?? [], static function ($row) { return !empty($row['relevant_month']); }));
        $grossCents = 0;
        foreach ($roster as $row) $grossCents += FinanceSettings::cents($row['billed_value'] ?? '0');
        $available = !empty($staff['configured']);
        $complete = $available;
        $deductions = [];
        $totalCents = $calculatedRate = $appliedRate = 0;
        $notes = [
            'Valor faturado: valor mensal dividido pelos dias corridos da competência, multiplicado pelos dias faturados; arredondamento em centavos por profissional.',
            'Glosas calculadas pelas faixas dos indicadores previstas no edital. Cada glosa é arredondada em centavos antes da soma; a base é o faturamento proporcional da competência.',
            'Uma glosa ajustada é uma decisão financeira informada e justificada pela gestão; não altera o resultado técnico do indicador.',
        ];
        foreach ($snapshot['metrics'] ?? [] as $metric) {
            $id = $metric['id'];
            $raw = $metric['value'] ?? null;
            // Prefer exact ratio: displaying 89.999% as 90.00% must not waive a deduction.
            $value = ($metric['denominator'] ?? 0) > 0 && isset($metric['numerator'])
                ? (float)$metric['numerator'] * 100 / (float)$metric['denominator'] : $raw;
            $status = $metric['status'] ?? 'pending';
            $rate = $status === 'not_applicable' ? 0
                : (in_array($status, ['met', 'unmet'], true) && $value !== null ? self::rate($id, (float)$value) : null);
            $override = $monthConfig['deduction_overrides'][$id] ?? null;
            $applied = $override !== null ? $override['rate'] : $rate;
            if ($rate === null) $complete = false;
            $amount = $available && $applied !== null ? self::deductionCents($grossCents, (float)$applied) : null;
            $totalCents += $amount ?? 0;
            $calculatedRate += $rate ?? 0;
            $appliedRate += $applied ?? 0;
            $deductions[] = ['id' => $id, 'label' => $metric['label'] ?? $id,
                'target' => $metric['target'] ?? null, 'direction' => $metric['direction'] ?? 'gte',
                'value' => $value, 'calculated_rate' => $rate, 'applied_rate' => $applied,
                'amount' => $amount === null ? null : FinanceSettings::amount($amount),
                'override' => $override !== null, 'justification' => $override['justification'] ?? ''];
        }
        if (count($deductions) !== 12) $complete = false;
        if (!$complete) $notes[] = 'O total financeiro é parcial: confira os indicadores sem apuração completa ou o cadastro da equipe antes de finalizar o faturamento.';
        $adjustmentCents = 0;
        foreach ($monthConfig['adjustments'] as $row) $adjustmentCents += FinanceSettings::cents($row['amount']);
        $fronts = $config['contract_fronts'];
        $monthly = [];
        $contractCents = $contractCount = 0;
        foreach ($fronts as &$front) {
            $value = 0;
            if (!empty($front['profile_lines'])) {
                foreach ($front['profile_lines'] as &$line) {
                    $lineCents = FinanceSettings::cents($line['monthly_rate']) * $line['contract_quantity'];
                    $line['contract_value'] = FinanceSettings::amount($lineCents);
                    $value += $lineCents;
                }
                unset($line);
            } else {
                $value = FinanceSettings::cents($front['monthly_rate']) * $front['contract_quantity'];
            }
            $front['contract_value'] = FinanceSettings::amount($value);
            $contractCents += $value;
            $contractCount += $front['contract_quantity'];
            $monthly[$front['id']] = ['id' => $front['id'], 'label' => $front['label'], 'professionals' => 0, 'billed_days' => 0, 'cents' => 0];
        }
        unset($front);
        foreach ($roster as $row) {
            $id = FinanceSettings::resolveFront($row, $fronts);
            if (!isset($monthly[$id])) $monthly[$id] = ['id' => $id, 'label' => 'Sem frente informada', 'professionals' => 0, 'billed_days' => 0, 'cents' => 0];
            if (empty($row['counted_billed_month'])) continue;
            $monthly[$id]['professionals']++;
            $monthly[$id]['billed_days'] += (int)$row['billed_days'];
            $monthly[$id]['cents'] += FinanceSettings::cents($row['billed_value']);
        }
        foreach ($monthly as &$row) {
            $row['billed_value'] = FinanceSettings::amount($row['cents']);
            unset($row['cents']);
        }
        unset($row);
        return ['available' => $available, 'complete' => $complete, 'month' => $month, 'roster' => $roster,
            'gross_value' => FinanceSettings::amount($grossCents), 'deduction_total' => FinanceSettings::amount($totalCents),
            'adjustment_total' => FinanceSettings::amount($adjustmentCents), 'net_value' => FinanceSettings::amount($grossCents - $totalCents + $adjustmentCents),
            'calculated_rate_total' => $calculatedRate, 'applied_rate_total' => $appliedRate,
            'deductions' => $deductions, 'adjustments' => $monthConfig['adjustments'], 'notes' => $notes,
            'contract_fronts' => $fronts, 'monthly_fronts' => array_values($monthly),
            'contract_quantity_total' => $contractCount, 'contract_total' => $config['contract_total'] ?? null,
            'contract_value_total' => $config['contract_total'] ?? FinanceSettings::amount($contractCents)];
    }

    private static function deductionCents(int $baseCents, float $rate): int
    {
        // Rate accepts hundredths of a percentage point; integer rounding avoids float cents drift.
        $rateHundredths = (int)round($rate * 100);
        return intdiv($baseCents * $rateHundredths * 2 + 10000, 20000);
    }
}
