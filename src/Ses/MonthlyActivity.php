<?php

namespace GlpiPlugin\G4freports\Ses;

/** Small daily projection of frozen evidence. It never recalculates an indicator. */
final class MonthlyActivity
{
    public const VERSION = 1;
    private const SERIES = ['tickets' => ['solved_at', 'tickets', 'solvedate'],
        'calls' => ['entered_at', 'calls_received', 'queue_entry'],
        'excluded_calls' => ['entered_at', 'calls_excluded', 'queue_entry']];
    private string $month;
    private string $revision;
    private array $counts = [];
    private array $buckets = [];

    public function __construct(string $month, string $revision)
    {
        if (!HeatmapData::daysInMonth($month) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
            throw new \InvalidArgumentException('Referência inválida para o detalhe diário.', 400);
        }
        $this->month = $month;
        $this->revision = $revision;
        foreach (self::SERIES as $name => $_) {
            $this->counts[$name] = ['observed' => 0, 'invalid_dates' => 0, 'out_of_month' => 0];
            $this->buckets[$name] = array_fill(1, HeatmapData::daysInMonth($month), 0);
        }
    }

    /** Called once per preserved evidence row by the bounded JSON reader. */
    public function add(string $name, array $row): void
    {
        if (!isset(self::SERIES[$name])) throw new \RuntimeException('Série diária inválida.', 503);
        $this->counts[$name]['observed']++;
        $value = $row[self::SERIES[$name][0]] ?? null;
        $parts = HeatmapData::timestampParts($value, $this->month);
        if ($parts !== null) {
            $this->buckets[$name][$parts['day']]++;
            return;
        }
        // A valid recorded date in another month is different from an invalid timestamp.
        $otherMonth = is_string($value) ? substr($value, 0, 7) : '';
        $other = $otherMonth !== $this->month && HeatmapData::timestampParts($value, $otherMonth) !== null;
        $this->counts[$name][$other ? 'out_of_month' : 'invalid_dates']++;
    }

    public function finish(array $metadata, array $summary): array
    {
        if (($metadata['schema_version'] ?? null) !== 2 || ($metadata['period']['month'] ?? null) !== $this->month
            || (isset($metadata['input']['month']) && $metadata['input']['month'] !== $this->month)) {
            throw new \RuntimeException('A evidência arquivada não corresponde à competência selecionada.', 503);
        }
        $period = $summary['period']['completeness'] ?? 'unknown';
        if (!in_array($period, ['complete', 'provisional', 'unknown'], true)) $period = 'unknown';
        $generated = is_string($metadata['generated_at'] ?? null) ? $metadata['generated_at'] : '';
        $zone = is_string($metadata['input']['timezone'] ?? null) ? $metadata['input']['timezone'] : '';
        $lastObservedDay = self::lastObservedDay($this->month, $period, $generated, $zone);
        if ($period === 'provisional' && $lastObservedDay === null) $period = 'unknown';
        $notes = ['Chamados: data da solução na população mensal arquivada. Ligações: primeira entrada do mês por ligação e fila, após as exclusões já registradas.',
            'Datas e horários locais preservados, sem nova conversão de fuso. A intensidade representa volume, não cumprimento de indicador.'];
        $series = [];
        foreach (self::SERIES as $name => [$dateKey, $totalKey, $basis]) {
            $count = $this->counts[$name];
            $total = $metadata['totals'][$totalKey] ?? null;
            if (!is_int($total) || $total < 0 || $total > 100000) $total = null;
            $storedTotal = $summary['totals'][$totalKey] ?? null;
            $summaryMatches = $storedTotal === null || $storedTotal === $total;
            $actualBasis = $metadata['period'][$name === 'tickets' ? 'date_basis' : 'call_date_basis'] ?? null;
            $present = ($metadata['collections'][$name] ?? false) === true;
            $reconciled = $present && $total !== null && $total === $count['observed'] && $summaryMatches;
            $available = $reconciled && $actualBasis === $basis;
            $future = 0;
            if ($lastObservedDay !== null) foreach ($this->buckets[$name] as $day => $number) if ($day > $lastObservedDay) $future += $number;
            $unassigned = $count['invalid_dates'] + $count['out_of_month'] + $future;
            $assigned = $count['observed'] - $unassigned;
            $status = !$available ? 'unavailable' : ($unassigned > 0 || $period === 'unknown' ? 'partial'
                : ($period === 'provisional' ? 'provisional' : 'complete'));
            $series[$name] = ['available' => $available, 'status' => $status, 'total' => $total,
                'observed' => $count['observed'], 'assigned' => $assigned, 'unassigned' => $unassigned,
                'invalid_dates' => $count['invalid_dates'], 'out_of_month' => $count['out_of_month'],
                'future_dates' => $future, 'reconciled' => $reconciled, 'date_basis' => $basis];
            $label = ['tickets' => 'Chamados', 'calls' => 'Ligações consideradas', 'excluded_calls' => 'Ligações de teste excluídas'][$name];
            if (!$available) $notes[] = $label . ': detalhe indisponível; coleção, base de datas ou reconciliação com o total arquivado não confirmada.';
            elseif ($unassigned) $notes[] = $label . ': ' . $unassigned . ' registro(s) sem posição diária válida ('
                . $count['invalid_dates'] . ' data(s) inválida(s), ' . $count['out_of_month'] . ' fora da competência, ' . $future . ' após a data da extração). O total mensal permanece preservado.';
        }
        if ($period === 'provisional') $notes[] = 'Competência provisória: o dia da extração pode estar incompleto; dias posteriores ficam sem valor.';
        if ($period === 'unknown') $notes[] = 'Encerramento ou data da extração não confirmados: zeros diários não são apresentados como cobertura completa.';
        $days = [];
        for ($day = 1; $day <= HeatmapData::daysInMonth($this->month); $day++) {
            $row = ['date' => $this->month . '-' . sprintf('%02d', $day)];
            foreach (self::SERIES as $name => $_) {
                $value = $this->buckets[$name][$day];
                $row[$name] = !$series[$name]['available'] || ($lastObservedDay !== null && $day > $lastObservedDay)
                    || ($period === 'unknown' && $value === 0) ? null : $value;
            }
            $days[] = $row;
        }
        $statuses = array_column($series, 'status');
        $status = !in_array(true, array_column($series, 'available'), true) ? 'unavailable'
            : (array_intersect($statuses, ['partial', 'unavailable']) ? 'partial'
                : (in_array('provisional', $statuses, true) ? 'provisional' : 'complete'));
        return ['schema_version' => 1, 'revision' => $this->revision, 'month' => $this->month, 'status' => $status,
            'period_completeness' => $period, 'generated_at' => $generated, 'timezone' => $zone,
            'days' => $days, 'series' => $series, 'notes' => $notes];
    }

    /** Validate the small stored projection independently of the large original evidence. */
    public static function valid(array $value, string $revision, string $month): bool
    {
        $statuses = ['complete', 'partial', 'unavailable', 'provisional'];
        if (($value['schema_version'] ?? null) !== 1 || ($value['revision'] ?? null) !== $revision
            || ($value['month'] ?? null) !== $month || !in_array($value['status'] ?? null, $statuses, true)
            || !in_array($value['period_completeness'] ?? null, ['complete', 'provisional', 'unknown'], true)
            || !is_string($value['generated_at'] ?? null) || strlen($value['generated_at']) > 80
            || !is_string($value['timezone'] ?? null) || strlen($value['timezone']) > 100
            || !is_array($value['days'] ?? null) || count($value['days']) !== HeatmapData::daysInMonth($month)
            || array_keys($value['days']) !== range(0, count($value['days']) - 1)
            || !is_array($value['series'] ?? null) || count($value['series']) !== 3
            || !is_array($value['notes'] ?? null) || count($value['notes']) > 12) return false;
        foreach ($value['notes'] as $note) if (!is_string($note) || strlen($note) > 1000) return false;
        foreach (self::SERIES as $name => $_) {
            $series = $value['series'][$name] ?? null;
            if (!is_array($series) || !is_bool($series['available'] ?? null) || !is_bool($series['reconciled'] ?? null)
                || !in_array($series['status'] ?? null, $statuses, true) || ($series['date_basis'] ?? null) !== self::SERIES[$name][2]) return false;
            foreach (['total', 'observed', 'assigned', 'unassigned', 'invalid_dates', 'out_of_month', 'future_dates'] as $key) {
                if ($key === 'total' && array_key_exists($key, $series) && $series[$key] === null) continue;
                if (!is_int($series[$key] ?? null) || $series[$key] < 0 || $series[$key] > 100000) return false;
            }
            if ($series['assigned'] + $series['unassigned'] !== $series['observed']
                || $series['invalid_dates'] + $series['out_of_month'] + $series['future_dates'] !== $series['unassigned']) return false;
            if ($series['available'] && (!$series['reconciled'] || $series['total'] !== $series['observed']
                || $series['status'] === 'unavailable')) return false;
            if ($series['reconciled'] && $series['total'] !== $series['observed']) return false;
            if (!$series['available'] && $series['status'] !== 'unavailable') return false;
        }
        $sums = array_fill_keys(array_keys(self::SERIES), 0);
        foreach ($value['days'] as $index => $day) {
            if (!is_array($day) || ($day['date'] ?? null) !== $month . '-' . sprintf('%02d', $index + 1)) return false;
            foreach (self::SERIES as $name => $_) {
                if (!array_key_exists($name, $day)
                    || ($day[$name] !== null && (!is_int($day[$name]) || $day[$name] < 0 || $day[$name] > 100000))) return false;
                if (!$value['series'][$name]['available'] && $day[$name] !== null) return false;
                $sums[$name] += $day[$name] ?? 0;
            }
        }
        foreach ($sums as $name => $sum) if ($value['series'][$name]['available'] && $sum !== $value['series'][$name]['assigned']) return false;
        return true;
    }

    private static function lastObservedDay(string $month, string $period, string $generated, string $zone): ?int
    {
        if ($period === 'complete') return HeatmapData::daysInMonth($month);
        if ($period !== 'provisional' || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/D', $generated)
            || substr($generated, -6) === '-00:00') return null;
        try {
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $generated);
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$time || ($errors && ($errors['warning_count'] || $errors['error_count']))) return null;
            $date = $time->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
            if ($date < $month . '-01') return 0;
            if (substr($date, 0, 7) !== $month) return null;
            return (int)substr($date, 8, 2);
        } catch (\Exception $error) { return null; }
    }
}
