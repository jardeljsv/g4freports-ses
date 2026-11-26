<?php

namespace GlpiPlugin\G4freports\Ses;

use GlpiPlugin\G4freports\Database\MysqlClient;

/** Recorded solution/reopening actors; current group membership is explicitly dated evidence. */
final class ActorEvidence
{
    public const MEMBERSHIP_BASIS = 'current_extraction';
    public const BASIS_NOTE = 'Autores identificados pelos IDs das soluções e pelo sufixo numérico do histórico de status. A filiação aos grupos é a existente na extração; não é uma reconstrução de filiação histórica.';

    /** Batch reads share the same selected ticket population; no per-ticket query or name matching. */
    public static function load(MysqlClient $client, string $scope, array $parameters, array $tickets): array
    {
        if (!$tickets) return [];
        $statuses = $client->fetchAll($scope . "
SELECT l.items_id AS ticket_id, l.id AS log_id, l.date_mod AS occurred_at,
       l.user_name AS actor_raw, l.old_value AS from_status, l.new_value AS to_status
FROM glpi_logs l INNER JOIN scoped_tickets t ON t.id = l.items_id
WHERE l.itemtype = 'Ticket' AND l.id_search_option = 12
  AND (l.new_value = '5' OR (l.old_value IN ('5','6') AND l.new_value IN ('1','2','3','4')))
ORDER BY l.items_id, l.date_mod, l.id", $parameters, 250000);
        $solutions = $client->fetchAll($scope . "
SELECT s.items_id AS ticket_id, s.id AS solution_id, s.date_creation AS solution_at,
       s.users_id AS solver_id, s.user_name AS solver_name, s.status AS solution_status
FROM glpi_itilsolutions s INNER JOIN scoped_tickets t ON t.id = s.items_id
WHERE s.itemtype = 'Ticket'
ORDER BY s.items_id, s.date_creation, s.id", $parameters, 250000);
        $userIds = [];
        foreach ($statuses as $row) {
            $id = self::historyUserId($row['actor_raw'] ?? '');
            if ($id > 0) $userIds[$id] = $id;
        }
        foreach ($solutions as $row) {
            $id = (int)($row['solver_id'] ?? 0);
            if ($id > 0) $userIds[$id] = $id;
        }
        $members = [];
        foreach (array_chunk(array_values($userIds), 1000) as $chunk) {
            $rows = $client->fetchAll("SELECT u.id AS user_id,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.realname,''))), ''), u.name) AS user_name,
    gu.groups_id AS group_id, g.completename AS group_name
FROM glpi_users u
LEFT JOIN glpi_groups_users gu ON gu.users_id = u.id
LEFT JOIN glpi_groups g ON g.id = gu.groups_id
WHERE u.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
ORDER BY u.id, gu.groups_id', $chunk, 250000);
            foreach ($rows as $row) $members[] = $row;
        }
        return self::annotate($tickets, $statuses, $solutions, $members);
    }

    /** Pure normalization also allows replay of extracted GLPI rows without a database connection. */
    public static function annotate(array $tickets, array $statusRows, array $solutionRows, array $memberRows): array
    {
        $members = $statuses = $solutions = [];
        foreach ($memberRows as $row) {
            $id = (int)($row['user_id'] ?? 0);
            if ($id <= 0) continue;
            if (!isset($members[$id])) $members[$id] = ['name' => self::plain($row['user_name'] ?? ''), 'groups' => []];
            $group = (int)($row['group_id'] ?? 0);
            if ($group > 0) $members[$id]['groups'][$group] = $group;
        }
        foreach ($statusRows as $row) {
            $at = self::stamp($row['occurred_at'] ?? '');
            if ($at === null) continue;
            $row['_at'] = $at;
            $row['actor_id'] = self::historyUserId($row['actor_raw'] ?? '');
            $statuses[(int)$row['ticket_id']][] = $row;
        }
        foreach ($solutionRows as $row) {
            $at = self::stamp($row['solution_at'] ?? '');
            if ($at === null || (int)($row['solution_id'] ?? 0) <= 0) continue;
            $row['_at'] = $at;
            $solutions[(int)$row['ticket_id']][] = $row;
        }
        foreach ($tickets as &$ticket) {
            $id = (int)$ticket['id'];
            $history = $statuses[$id] ?? [];
            $records = $solutions[$id] ?? [];
            usort($history, static function ($a, $b) { return [$a['_at'], (int)$a['log_id']] <=> [$b['_at'], (int)$b['log_id']]; });
            usort($records, static function ($a, $b) { return [$a['_at'], (int)$a['solution_id']] <=> [$b['_at'], (int)$b['solution_id']]; });
            $events = [];
            $lastSolved = null;
            foreach ($history as $status) {
                $to = (int)$status['to_status'];
                if ($to === 5) {
                    $lastSolved = $status;
                    continue;
                }
                if (!in_array((int)$status['from_status'], [5, 6], true) || !in_array($to, [1, 2, 3, 4], true)) continue;
                $solver = self::solver($records, $lastSolved, $status['_at'], $members);
                $reopener = (int)$status['actor_id'];
                $events[] = [
                    'log_id' => (int)$status['log_id'], 'occurred_at' => $status['occurred_at'],
                    'from_status' => (int)$status['from_status'], 'to_status' => $to,
                    'reopener_id' => $reopener, 'reopener_name' => $members[$reopener]['name'] ?? '',
                    'reopener_raw' => self::plain($status['actor_raw'] ?? ''),
                    'solution_id' => $solver['solution_id'], 'solution_at' => $solver['solution_at'],
                    'solver_id' => $solver['solver_id'], 'solver_name' => $solver['solver_name'],
                    'solve_log_id' => $solver['solve_log_id'], 'solve_log_at' => $solver['solve_log_at'],
                    'solver_group_ids' => $solver['solver_group_ids'],
                    'actor_source' => $solver['solver_actor_source'],
                    'actor_conflict' => $solver['solver_actor_conflict'],
                    'membership_basis' => self::MEMBERSHIP_BASIS,
                ];
            }
            // Use the solution cycle ending in the stored solvedate, not a later automatic closure.
            $solvedAt = self::stamp($ticket['solved_at'] ?? '');
            $finalStatus = null;
            foreach ($history as $status) {
                if ($solvedAt !== null && $status['_at'] <= $solvedAt && (int)$status['to_status'] === 5) $finalStatus = $status;
            }
            $ticket = array_replace($ticket, self::solver($records, $finalStatus, $solvedAt, $members));
            $ticket['actor_evidence_available'] = true;
            $ticket['ins5_reopening_events'] = $events;
        }
        unset($ticket);
        return $tickets;
    }

    /** Apply selections without changing the source actor facts or the original reopened flag. */
    public static function classify(array $ticket, array $input, bool $manualIncomplete): array
    {
        $technicalGroups = array_values(array_unique(array_merge($input['n1_group_ids'] ?? [], $input['n2_group_ids'] ?? [], $input['n3_group_ids'] ?? [])));
        $events = $ticket['ins5_reopening_events'] ?? [];
        $exempt = $counted = 0;
        foreach ($events as &$event) {
            $same = (int)$event['reopener_id'] > 0 && (int)$event['reopener_id'] === (int)$event['solver_id'];
            $technical = (bool)array_intersect($event['solver_group_ids'] ?? [], $technicalGroups);
            $event['administrative_exempt'] = $same && $technical && empty($event['actor_conflict']);
            $event['reason'] = $event['administrative_exempt']
                ? 'Reabertura pelo mesmo técnico que solucionou o ciclo anterior, com filiação atual a grupo N1/N2/N3 selecionado.'
                : (!empty($event['actor_conflict']) ? 'Autores da solução e da mudança de status divergem; reabertura mantida no cálculo.'
                    : (!$same ? 'Reabertura por outro autor ou sem identificação coincidente do solucionador.'
                        : 'Filiação atual do solucionador aos grupos N1/N2/N3 selecionados não identificada.'));
            if ($event['administrative_exempt']) $exempt++; else $counted++;
        }
        unset($event);
        // A legacy boolean without event evidence must never create an unsupported exemption.
        $remaining = $counted > 0 || (!$events && !empty($ticket['reopened']));
        $ticket['ins5_reopening_events'] = $events;
        $ticket['ins5_exempt_event_count'] = $exempt;
        $ticket['ins5_counted_reopening_count'] = $counted;
        $ticket['ins5_excluded_administrative'] = $exempt > 0 && !$remaining && !$manualIncomplete;
        $ticket['ins5'] = $remaining || $manualIncomplete;
        $ticket['incomplete_execution'] = $manualIncomplete;
        $groups = array_values(array_filter(array_map('intval', $ticket['group_ids'] ?? [])));
        $eligible = !empty($ticket['ins6_eligible']);
        $assignedN1 = (bool)array_intersect($groups, $input['n1_group_ids'] ?? []);
        $solverN1 = !empty($ticket['solver_id']) && empty($ticket['solver_actor_conflict'])
            && (bool)array_intersect($ticket['solver_group_ids'] ?? [], $input['n1_group_ids'] ?? []);
        $ticket['ins6_solver_fallback'] = $eligible && !$groups && $solverN1;
        $ticket['ins6'] = $eligible && ($assignedN1 || $ticket['ins6_solver_fallback']);
        $ticket['ins6_basis'] = !$eligible ? 'ineligible' : ($assignedN1 ? 'assigned_n1'
            : ($ticket['ins6_solver_fallback'] ? 'solver_n1_without_group' : ($groups ? 'other_groups' : 'ungrouped')));
        return $ticket;
    }

    private static function solver(array $records, ?array $status, ?string $before, array $members): array
    {
        $solution = null;
        // The preceding solved status selects the cycle. A solution written after that cycle
        // must not be attributed backwards merely because it precedes a later reopening.
        $cutoff = $status['_at'] ?? $before;
        foreach ($records as $row) {
            if ($cutoff !== null && $row['_at'] <= $cutoff) $solution = $row;
        }
        $recordId = (int)($solution['solver_id'] ?? 0);
        $statusId = (int)($status['actor_id'] ?? 0);
        $sameTime = $solution && $status && $solution['_at'] === $status['_at'];
        $conflict = $sameTime && $recordId > 0 && $statusId > 0 && $recordId !== $statusId;
        // A later explicit solved-status actor supersedes an older persisted solution cycle.
        $useStatus = $status !== null && (!$solution || $status['_at'] > $solution['_at'] || $recordId <= 0);
        $id = $useStatus ? $statusId : $recordId;
        $source = $useStatus ? 'solved_status' : ($solution ? 'solution_author' : 'unavailable');
        $sameCycleRecord = !$useStatus || $sameTime;
        return [
            'solver_id' => $id, 'solver_name' => $members[$id]['name'] ?? ($useStatus ? '' : self::plain($solution['solver_name'] ?? '')),
            // Do not display an older cycle's solution record as evidence of a newer status-only solver.
            'solution_id' => $sameCycleRecord ? (int)($solution['solution_id'] ?? 0) : 0,
            'solution_at' => $useStatus ? ($status['occurred_at'] ?? null) : ($solution['solution_at'] ?? null),
            'solve_log_id' => (int)($status['log_id'] ?? 0), 'solve_log_at' => $status['occurred_at'] ?? null,
            'solver_group_ids' => array_values($members[$id]['groups'] ?? []),
            'solver_actor_source' => $source, 'solver_actor_conflict' => $conflict,
        ];
    }

    private static function historyUserId($value): int
    {
        return preg_match('/\(([1-9][0-9]*)\)\s*$/D', (string)$value, $match) ? (int)$match[1] : 0;
    }

    private static function stamp($value): ?string
    {
        if (!is_string($value) || $value === '' || strpos($value, '0000-00-00') === 0) return null;
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('America/Sao_Paulo'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) return null;
            return $date->format('Y-m-d H:i:s.u');
        } catch (\Exception $e) { return null; }
    }

    private static function plain($value): string
    {
        return trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
