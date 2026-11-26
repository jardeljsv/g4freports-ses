<?php

namespace GlpiPlugin\G4freports\Ses;

/** Evidence for a proposed review only; never changes contractual measurement. */
final class SeverityReview
{
    public static function build(array $snapshot, array $context): array
    {
        $out = [];
        $available = !empty($context['history_available']);
        $tickets = [];
        foreach ($snapshot['tickets'] ?? [] as $ticket) $tickets[(int)$ticket['id']] = $ticket;
        foreach ($snapshot['metrics'] ?? [] as $metric) {
            $id = $metric['id'];
            if (!in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true)) continue;
            $late = array_diff($metric['denominator_ids'] ?? [], $metric['evidence_ids'] ?? []);
            $rows = [];
            if ($available && (int)($snapshot['input']['entity_id'] ?? 1) === 1) {
                foreach (array_unique($late) as $ticketId) {
                    $ticket = $tickets[(int)$ticketId] ?? [];
                    $row = self::transfer($ticket, $context['histories'][(int)$ticketId] ?? []);
                    if ($row !== null) $rows[] = $row;
                }
            }
            usort($rows, static function ($a, $b) { return strcmp($b['received_at'], $a['received_at']) ?: ($a['id'] <=> $b['id']); });
            $out[$id] = ['history_available' => $available && (int)($snapshot['input']['entity_id'] ?? 1) === 1,
                'late_count' => count($late), 'candidates' => $rows];
        }
        return $out;
    }

    private static function entityId(array $event, string $side): ?int
    {
        // GLPI records root as zero. Text suffix is authoritative if present.
        if (preg_match('/\((\d+)\)\s*$/D', (string)($event[$side . '_value'] ?? ''), $m)) return (int)$m[1];
        $v = $event[$side . '_id'] ?? null;
        return is_numeric($v) && (int)$v > 0 ? (int)$v : null;
    }

    private static function transfer(array $ticket, array $events): ?array
    {
        $open = (string)($ticket['opened_at'] ?? '');
        $solved = (string)($ticket['solved_at'] ?? '');
        if ($open === '' || $solved === '' || (int)($ticket['entity_id'] ?? -1) !== 1) return null;
        usort($events, static function ($a, $b) { return strcmp((string)$a['date_mod'], (string)$b['date_mod']) ?: ((int)$a['id'] <=> (int)$b['id']); });
        $arrival = null;
        $transfers = 0;
        foreach ($events as $event) {
            $at = (string)($event['date_mod'] ?? '');
            if ($at < $open || $at > $solved || (int)($event['linked_action'] ?? -1) !== 0
                || (int)($event['id_search_option'] ?? 0) !== 80) continue;
            $old = self::entityId($event, 'old');
            $new = self::entityId($event, 'new');
            // Reject ambiguous or interrupted custody; use the final direct entry.
            if ($new !== 1 || $old !== 0) {
                $arrival = null;
                continue;
            }
            $arrival = $event;
            $transfers++;
        }
        if ($arrival === null) return null;
        $received = (string)$arrival['date_mod'];
        $pending = [];
        foreach ($events as $event) {
            $at = (string)($event['date_mod'] ?? '');
            if ($at < $received || $at > $solved || (int)($event['linked_action'] ?? -1) !== 0
                || (int)($event['id_search_option'] ?? 0) !== 12) continue;
            if ((string)($event['new_value'] ?? '') === '4') $pending[] = $at;
        }
        return ['id' => (int)$ticket['id'], 'log_id' => (int)$arrival['id'], 'received_at' => $received,
            'opened_at' => $open, 'solved_at' => $solved, 'pending_at' => array_values(array_unique($pending)),
            'transfer_count' => $transfers, 'stored_deadline' => $ticket['deadline'] ?? null];
    }
}
