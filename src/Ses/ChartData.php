<?php

namespace GlpiPlugin\G4freports\Ses;

/** Chart definitions derived only from the preserved, calculated extraction. */
class ChartData
{
    public static function build(array $snapshot): array
    {
        $tickets = $snapshot['tickets'] ?? [];
        $calls = $snapshot['calls'] ?? [];
        $metrics = array_column($snapshot['metrics'] ?? [], null, 'id');
        $result = [];
        // Every service-level figure compares the same two measures. Composition
        // counts remain in the underlying evidence and source-specific tables.
        for ($number = 1; $number <= 12; $number++) {
            $id = 'INS' . $number;
            $result[$id] = self::metricComparison($id, $metrics[$id] ?? []);
        }

        $types = [0, 0, 0];
        foreach ($tickets as $ticket) {
            $type = (int)($ticket['type'] ?? 0);
            $types[$type === 2 ? 0 : ($type === 1 ? 1 : 2)]++;
        }
        $categories = ['Requisições', 'Incidentes'];
        if ($types[2] > 0) {
            $categories[] = 'Outros tipos';
        } else {
            array_pop($types);
        }
        $result['tickets_types'] = self::counts('tickets_types', 'Chamados solucionados por tipo', $categories, $types, count($tickets) > 0,
            'Quantidades de chamados selecionados pela data de solução, inclusive os abertos antes do mês.');
        $result['calls_daily'] = self::dailyCalls($snapshot, $calls);
        $result['tickets_daily'] = self::dailyTickets($snapshot, $tickets);
        $result['tickets_types']['kind'] = 'pie';
        $result['tickets_types']['note'] .= ' Percentuais das fatias representam a participação de cada tipo no total de chamados.';
        $result['tickets_types']['point_colors'] = array_slice([ReportStyle::BLUE, ReportStyle::YELLOW, ReportStyle::LIGHT_GRAY], 0, count($categories));
        return $result;
    }

    private static function metricComparison(string $id, array $metric): array
    {
        $defaultTargets = ['INS1' => 90, 'INS2' => 90, 'INS3' => 90, 'INS4' => 90,
            'INS5' => 2, 'INS6' => 80, 'INS7' => 85, 'INS8' => 85, 'INS9' => 5,
            'INS10' => 10, 'INS11' => 80, 'INS12' => 80];
        $target = (float)($metric['target'] ?? $defaultTargets[$id]);
        $maximumTarget = ($metric['direction'] ?? (in_array($id, ['INS5', 'INS9', 'INS10'], true) ? 'lte' : 'gte')) === 'lte';
        $available = self::metricAvailable($metric);
        $comparator = $maximumTarget ? '≤' : '≥';
        return ['id' => $id,
            'title' => $id . ' — Meta ' . $comparator . ' ' . number_format($target, 2, ',', '.') . '% × Resultado Apurado',
            'categories' => [$id],
            'series' => [
                ['name' => 'Meta', 'values' => [$target / 100], 'color' => ReportStyle::NAVY],
                ['name' => 'Resultado Apurado', 'values' => [$available ? (float)$metric['value'] / 100 : null], 'color' => ReportStyle::BLUE],
            ],
            'kind' => 'bar', 'stacked' => false, 'unit' => 'percent', 'available' => $available,
            'axis_category_title' => 'Indicador', 'axis_value_title' => 'Percentual (%)',
            'note' => $maximumTarget
                ? 'Meta atendida quando o resultado apurado é menor ou igual ao limite indicado.'
                : 'Meta atendida quando o resultado apurado é maior ou igual ao mínimo indicado.'];
    }

    private static function counts(string $id, string $title, array $categories, array $values, bool $available, string $note): array
    {
        return ['id' => $id, 'title' => $title, 'categories' => $categories,
            'series' => [['name' => 'Quantidade', 'values' => $available ? $values : array_fill(0, count($categories), null), 'color' => ReportStyle::BLUE]],
            'kind' => 'bar', 'stacked' => false, 'unit' => 'count', 'available' => $available, 'note' => $note];
    }

    /** Reconcile all solved tickets, including unanswered surveys, without changing INS12. */
    public static function ticketSurveyDistribution(array $snapshot): array
    {
        foreach ($snapshot['metrics'] ?? [] as $metric) {
            if (($metric['id'] ?? '') === 'INS12' && isset($metric['source_counts']['tickets'])
                && empty($metric['source_counts']['tickets']['available'])) {
                $total = count($snapshot['tickets'] ?? []);
                return ['rows' => [['Pesquisa indisponível na extração', $total]], 'total' => $total, 'available' => false];
            }
        }
        $counts = [0, 0, 0, 0, 0, 0, 0];
        foreach ($snapshot['tickets'] ?? [] as $ticket) {
            $rating = $ticket['satisfaction'] ?? null;
            if ($rating === null || $rating === '') {
                $counts[5]++;
            } elseif (is_numeric($rating) && in_array((float)$rating, [1.0, 2.0, 3.0, 4.0, 5.0], true)) {
                $counts[(int)$rating - 1]++;
            } else {
                $counts[6]++;
            }
        }
        $labels = ['1', '2', '3', '4', '5', 'Não preenchida', 'Nota inválida'];
        $rows = [];
        foreach ($labels as $index => $label) {
            if ($index === 6 && $counts[$index] === 0) continue;
            $rows[] = [$label, $counts[$index]];
        }
        return ['rows' => $rows, 'total' => count($snapshot['tickets'] ?? []), 'available' => true];
    }

    /** Full distinct-call population, separate from ticket nonresponses and metric selection. */
    public static function phoneSurveyDistribution(array $snapshot): array
    {
        $summary = $snapshot['phone_survey'] ?? [];
        $available = !empty($summary['available']);
        $representatives = array_fill_keys(array_map('strval', $summary['representative_call_keys'] ?? []), true);
        $total = count($representatives);
        if (!$available) {
            return ['rows' => [['Pesquisa telefônica indisponível na extração', $total]], 'total' => $total, 'available' => false];
        }
        $counts = [0, 0, 0, 0, 0, 0, 0];
        $seen = [];
        foreach ($snapshot['calls'] ?? [] as $call) {
            $key = (string)($call['call_key'] ?? ($call['id'] . '|' . $call['queue']));
            if (!isset($representatives[$key]) || isset($seen[(string)$call['id']]) || isset($call['excluded_reason'])) continue;
            $seen[(string)$call['id']] = true;
            $score = PhoneSurveySummary::normalizedRating($call);
            if ($score !== null) $counts[$score - 1]++;
            elseif (!PhoneSurveySummary::hasResponse($call)) $counts[5]++;
            else $counts[6]++;
        }
        $labels = ['1', '2', '3', '4', '5', 'Não preenchida', 'Nota inválida'];
        $rows = [];
        foreach ($labels as $i => $label) {
            if ($i === 6 && $counts[$i] === 0) continue;
            $rows[] = [$label, $counts[$i]];
        }
        return ['rows' => $rows, 'total' => array_sum($counts), 'available' => true];
    }

    private static function dailyCalls(array $snapshot, array $calls): array
    {
        $month = $snapshot['period']['month'] ?? '';
        $days = [];
        $series = [
            ['name' => 'Atendidas', 'values' => [], 'color' => ReportStyle::BLUE],
            ['name' => 'Abandonadas', 'values' => [], 'color' => ReportStyle::YELLOW],
            ['name' => 'Sem desfecho', 'values' => [], 'color' => ReportStyle::LIGHT_GRAY],
        ];
        $validMonth = is_string($month) && preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $month);
        if ($validMonth) {
            $first = new \DateTimeImmutable($month . '-01', new \DateTimeZone('America/Sao_Paulo'));
            $numberOfDays = (int)$first->format('t');
            for ($day = 1; $day <= $numberOfDays; $day++) {
                $days[] = sprintf('%02d', $day);
            }
            foreach ($series as &$row) {
                $row['values'] = array_fill(0, $numberOfDays, 0);
            }
            unset($row);
            foreach ($calls as $call) {
                $entry = (string)($call['entered_at'] ?? '');
                if (substr($entry, 0, 7) !== $month || !self::hasDate($entry)) {
                    continue;
                }
                $day = (int)substr($entry, 8, 2);
                $type = $call['type'] ?? '';
                $series[$type === 'Atendida' ? 0 : ($type === 'Abandonada' ? 1 : 2)]['values'][$day - 1]++;
            }
        }
        $available = $validMonth && count($calls) > 0;
        if (!$available) {
            foreach ($series as &$row) {
                $row['values'] = array_fill(0, count($days), null);
            }
            unset($row);
        }
        return ['id' => 'calls_daily', 'title' => 'Ligações recebidas por dia', 'categories' => $days, 'series' => $series,
            'kind' => 'column', 'stacked' => true, 'unit' => 'count', 'available' => (bool)$available,
            'axis_category_title' => 'Dia do mês', 'axis_value_title' => 'Quantidade de ligações',
            'note' => 'Quantidades por dia de entrada na fila, após exclusões de números de teste; inclui dias sem chamadas. Desfechos posteriores ao fim do mês permanecem associados ao dia de entrada.'];
    }

    private static function dailyTickets(array $snapshot, array $tickets): array
    {
        $month = $snapshot['period']['month'] ?? '';
        $numberOfDays = HeatmapData::daysInMonth($month);
        $days = [];
        for ($day = 1; $day <= $numberOfDays; $day++) {
            $days[] = sprintf('%02d', $day);
        }
        $values = array_fill(0, $numberOfDays, 0);
        $total = 0;
        $omitted = 0;
        foreach ($tickets as $ticket) {
            $parts = HeatmapData::timestampParts($ticket['solved_at'] ?? null, $month);
            if ($parts === null) {
                $omitted++;
                continue;
            }
            $values[$parts['day'] - 1]++;
            $total++;
        }
        $available = $total > 0;
        return ['id' => 'tickets_daily', 'title' => 'Chamados solucionados por dia', 'categories' => $days,
            'series' => [['name' => 'Solucionados', 'values' => $available ? $values : array_fill(0, $numberOfDays, null), 'color' => ReportStyle::BLUE]],
            'kind' => 'column', 'stacked' => false, 'unit' => 'count', 'available' => $available,
            'axis_category_title' => 'Dia do mês', 'axis_value_title' => 'Quantidade de chamados',
            'note' => 'Quantidades por data de solução dos chamados da extração, inclusive os abertos antes do mês; inclui dias sem soluções. '
                . 'Horário local registrado (America/Sao_Paulo), sem nova conversão de fuso. Registros representados: ' . $total
                . '. Omitidos deste gráfico por data de solução ausente, inválida ou fora do mês: ' . $omitted . '.'
                . (!$available ? ' Sem datas válidas no mês para representar; ausência de dados não é zero.' : '')];
    }

    private static function metricAvailable(array $metric): bool
    {
        return isset($metric['value']) && is_numeric($metric['value']) && ($metric['denominator'] ?? 0) > 0
            && !in_array($metric['status'] ?? '', ['pending', 'not_applicable'], true);
    }

    private static function hasDate($value): bool
    {
        if (!is_string($value) || $value === '' || strpos($value, '0000-00-00') === 0) {
            return false;
        }
        try {
            new \DateTimeImmutable($value, new \DateTimeZone('America/Sao_Paulo'));
            $errors = \DateTimeImmutable::getLastErrors();
            return !is_array($errors) || (!$errors['warning_count'] && !$errors['error_count']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
