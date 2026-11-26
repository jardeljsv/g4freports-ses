<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** Temporal volumes from the preserved extraction; never changes indicator populations. */
class HeatmapData
{
    public static function build(array $snapshot): array
    {
        $month = $snapshot['period']['month'] ?? '';
        return [
            'tickets_solution' => self::matrix('tickets_solution', 'Chamados por dia da semana e hora da solução',
                $snapshot['tickets'] ?? [], 'solved_at', 'tickets', $month,
                'Solução dos chamados da extração, inclusive os abertos antes do mês.'),
            'calls_entry' => self::matrix('calls_entry', 'Ligações por dia da semana e hora de entrada',
                $snapshot['calls'] ?? [], 'entered_at', 'calls', $month,
                'Entrada na fila das ligações consideradas, após exclusões de números de teste; o desfecho pode ocorrer após o mês.'),
        ];
    }

    /** Month length for the same report range accepted by Settings; zero means invalid. */
    public static function daysInMonth($month): int
    {
        if (!is_string($month) || !preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $month)) {
            return 0;
        }
        return (int)(new \DateTimeImmutable($month . '-01 12:00:00', new \DateTimeZone('America/Sao_Paulo')))->format('t');
    }

    /**
     * Strict SQL local timestamp, including optional MySQL microseconds.
     * No timezone conversion: day/hour are taken from the recorded wall time.
     * Relative strings, offsets, impossible dates and dates outside the month are rejected.
     */
    public static function timestampParts($value, $month): ?array
    {
        if (!is_string($value) || !is_string($month)
            || !preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $month)
            || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?$/D', $value, $parts)
            || substr($value, 0, 7) !== $month) {
            return null;
        }
        $year = (int)$parts[1];
        $monthNumber = (int)$parts[2];
        $day = (int)$parts[3];
        $hour = (int)$parts[4];
        if (!checkdate($monthNumber, $day, $year) || $hour > 23 || (int)$parts[5] > 59 || (int)$parts[6] > 59) {
            return null;
        }
        // Noon avoids a local daylight-saving boundary when calculating the weekday.
        $date = new \DateTimeImmutable(substr($value, 0, 10) . ' 12:00:00', new \DateTimeZone('America/Sao_Paulo'));
        return ['day' => $day, 'hour' => $hour, 'weekday' => (int)$date->format('N') - 1];
    }

    private static function matrix(string $id, string $title, array $rows, string $dateKey, string $source, $month, string $basis): array
    {
        $values = array_fill(0, 24, array_fill(0, 7, 0));
        $total = 0;
        $omitted = 0;
        $maximum = 0;
        foreach ($rows as $row) {
            $parts = self::timestampParts($row[$dateKey] ?? null, $month);
            if ($parts === null) {
                $omitted++;
                continue;
            }
            $count = ++$values[$parts['hour']][$parts['weekday']];
            $maximum = max($maximum, $count);
            $total++;
        }
        $hours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hours[] = sprintf('%02dh', $hour);
        }
        $note = $basis . ' Horário local registrado (America/Sao_Paulo), sem nova conversão de fuso. '
            . 'Volume acumulado no mês por dia da semana e hora, sem divisão pela quantidade de dias. '
            . 'Registros com data válida no mês: ' . $total . '. Omitidos deste mapa por data ausente, inválida ou fora do mês: ' . $omitted . '. '
            . 'Zero indica ausência de registros na célula; a intensidade representa volume relativo ao máximo deste mapa, não cumprimento de nível de serviço.';
        if ($total === 0) {
            $note .= ' Sem datas válidas no mês para representar; os zeros da matriz não significam uma apuração disponível.';
        }
        return ['id' => $id, 'title' => $title, 'row_labels' => $hours,
            'column_labels' => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'], 'values' => $values,
            'available' => $total > 0, 'total' => $total, 'omitted' => $omitted, 'maximum' => $maximum,
            'note' => $note, 'date_key' => $dateKey, 'source' => $source];
    }
}
