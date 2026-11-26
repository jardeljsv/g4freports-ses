<?php

namespace GlpiPlugin\G4freports\Ses;

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Database\ConnectionConfig;
use GlpiPlugin\G4freports\Database\ConnectionException;
use GlpiPlugin\G4freports\Database\MysqlClient;

/**
 * Read-only monthly extraction. Neither source uses GLPI's global DB handle.
 * Association rows are merged by ticket ID instead of concatenated in SQL:
 * there is no GROUP_CONCAT truncation and no ticket/group/user join fanout.
 */
final class Repository
{
    private const MAX_ROWS = 100000;

    public static function extract(array $input): array
    {
        $period = self::period($input);
        $config = Config::merged();
        $glpiProfile = ConnectionConfig::fromConfig('glpi', $config);
        $callProfile = ConnectionConfig::fromConfig('callcenter', $config);
        if (empty($glpiProfile['enabled'])) {
            throw new \RuntimeException('Habilite e configure a conexão GLPI/MariaDB do relatório SES nas configurações do plugin.');
        }
        if (empty($callProfile['enabled'])) {
            throw new \RuntimeException('Habilite e configure a conexão Callcenter/MySQL nas configurações do plugin.');
        }
        $glpi = new MysqlClient($glpiProfile);
        $callcenter = new MysqlClient($callProfile);
        // Freeze the identity of the profiles actually used by this extraction.
        // Credentials and raw connection details must not enter report snapshots.
        $sourceIdentity = [
            'glpi' => self::sourceIdentity($glpiProfile),
            'callcenter' => self::sourceIdentity($callProfile),
        ];
        unset($glpiProfile, $callProfile, $config);
        $warnings = [];
        $selectionCatalog = SelectionCatalog::resolve($glpi, $input, $warnings);
        $capabilities = ['reopening' => true, 'satisfaction' => true];
        $capabilities['kb_links'] = self::optionalTable(
            $glpi,
            'SELECT items_id, itemtype, knowbaseitems_id FROM glpi_knowbaseitems_items LIMIT 0',
            'Os vínculos de chamados com artigos da base de conhecimento não estão acessíveis. O INS11 por artigos validados ficará pendente.',
            $warnings
        );
        $capabilities['phone_survey'] = self::optionalTable(
            $callcenter,
            'SELECT id, uniqueid, atendimento, `date` FROM tab_pesquisa LIMIT 0',
            'A pesquisa de satisfação telefônica não está acessível. As ligações e os indicadores INS7–INS9 continuam disponíveis; o INS12 informa essa indisponibilidade conforme as fontes selecionadas.',
            $warnings
        );
        // The migration introduced nota only in newer callcenter schemas.
        // Its absence must not disable the existing atendimento source.
        $capabilities['phone_survey_nota'] = false;
        if ($capabilities['phone_survey']) {
            try {
                $callcenter->fetchAll('SELECT nota FROM tab_pesquisa LIMIT 0', [], 1);
                $capabilities['phone_survey_nota'] = true;
            } catch (ConnectionException $e) {
                if ($e->getReason() !== 'missing_columns') throw $e;
            }
        }

        $tickets = self::tickets($glpi, $input, $period, $capabilities['kb_links'], $warnings);
        $slaContext = SlaDataSource::load($glpi, $tickets, $input, $warnings);
        foreach ($slaContext['capabilities'] as $name => $available) {
            $capabilities['sla_' . $name] = $available;
        }
        $calls = self::calls($callcenter, $input, $period, $capabilities['phone_survey'], $warnings, $capabilities['phone_survey_nota']);

        return [
            'tickets' => $tickets,
            'calls' => $calls,
            'warnings' => array_values(array_unique($warnings)),
            'capabilities' => $capabilities,
            'selection_catalog' => $selectionCatalog,
            'sla_context' => $slaContext,
            'source_identity' => $sourceIdentity,
        ];
    }

    private static function sourceIdentity(array $profile): string
    {
        return hash('sha256', json_encode([
            'host' => (string)$profile['host'],
            'port' => (int)$profile['port'],
            'database' => (string)$profile['database'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function period(array $input): array
    {
        $month = (string)($input['month'] ?? '');
        if (!preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $month)) {
            throw new \InvalidArgumentException('Informe a competência no formato AAAA-MM.');
        }
        $start = new \DateTimeImmutable($month . '-01 00:00:00');
        return ['start' => $start->format('Y-m-d H:i:s'), 'end' => $start->modify('+1 month')->format('Y-m-d H:i:s')];
    }

    /** Only absent/inaccessible optional evidence is downgraded to a warning. */
    private static function optionalTable(MysqlClient $client, string $sql, string $warning, array &$warnings): bool
    {
        try {
            $client->fetchAll($sql, [], 1);
            return true;
        } catch (ConnectionException $e) {
            if (!in_array($e->getReason(), ['missing_table', 'missing_columns', 'permission_denied'], true)) {
                throw $e;
            }
            $warnings[] = $warning;
            return false;
        }
    }

    /** One explicit full-month solved/closed population shared by all ticket queries. */
    private static function ticketScope(array $input, array $period): array
    {
        $entity = (int)($input['entity_id'] ?? 1);
        if ($entity < 0) {
            throw new \InvalidArgumentException('A entidade do relatório é inválida.');
        }
        $sql = 'WITH scoped_tickets AS (SELECT t.id, t.entities_id, t.name, t.type, t.status, t.priority,'
            . ' t.itilcategories_id, t.`date`, t.solvedate, t.closedate, t.time_to_resolve,'
            . ' t.slas_id_ttr, t.waiting_duration, t.solve_delay_stat, t.locations_id,'
            . ' t.date_creation, t.sla_waiting_duration FROM glpi_tickets t'
            . ' WHERE t.entities_id = ? AND t.is_deleted = 0 AND t.status IN (5, 6)'
            . ' AND t.solvedate >= ? AND t.solvedate < ?';
        $params = [$entity, $period['start'], $period['end']];
        $groupIds = self::ids($input['group_ids'] ?? []);
        if ($groupIds) {
            $sql .= ' AND EXISTS (SELECT 1 FROM glpi_groups_tickets fg'
                . ' WHERE fg.tickets_id = t.id AND fg.type = 2 AND fg.groups_id IN ('
                . implode(',', array_fill(0, count($groupIds), '?')) . '))';
            $params = array_merge($params, $groupIds);
        }
        return [$sql . ')', $params];
    }

    private static function tickets(MysqlClient $client, array $input, array $period, bool $hasKb, array &$warnings): array
    {
        list($scope, $params) = self::ticketScope($input, $period);
        $sql = $scope . <<<'SQL'
,
reopened_tickets AS (
    SELECT DISTINCT l.items_id
    FROM glpi_logs l
    INNER JOIN scoped_tickets t ON t.id = l.items_id
    WHERE l.itemtype = 'Ticket' AND l.id_search_option = 12
      AND l.old_value IN ('5', '6') AND l.new_value IN ('1', '2', '3', '4')
),
refused_tickets AS (
    SELECT DISTINCT s.items_id
    FROM glpi_itilsolutions s
    INNER JOIN scoped_tickets t ON t.id = s.items_id
    WHERE s.itemtype = 'Ticket' AND s.status = 4
),
survey_ranked AS (
    SELECT s.tickets_id, s.satisfaction,
           ROW_NUMBER() OVER (PARTITION BY s.tickets_id ORDER BY s.id DESC) AS rn
    FROM glpi_ticketsatisfactions s
    INNER JOIN scoped_tickets t ON t.id = s.tickets_id
)
SELECT t.id, t.entities_id AS entity_id, t.name AS title, t.type, t.status, t.priority,
       t.itilcategories_id AS category_id, c.completename AS category,
       t.`date` AS opened_at, t.solvedate AS solved_at, t.closedate AS closed_at,
       t.time_to_resolve AS deadline, t.slas_id_ttr AS sla_id, sl.name AS sla_name,
       t.waiting_duration AS waiting_seconds, t.solve_delay_stat AS solve_seconds,
       t.date_creation AS created_at, t.sla_waiting_duration AS sla_waiting_seconds,
       TIMESTAMPDIFF(SECOND, t.`date`, t.solvedate) AS elapsed_seconds,
       CASE WHEN t.time_to_resolve IS NULL THEN NULL
            WHEN t.solvedate <= t.time_to_resolve THEN 1 ELSE 0 END AS within_sla,
       CASE WHEN r.items_id IS NOT NULL THEN 1 ELSE 0 END AS reopened,
       CASE WHEN f.items_id IS NOT NULL THEN 1 ELSE 0 END AS refused_solution,
       c.knowbaseitemcategories_id AS kb_category_id, s.satisfaction,
       t.locations_id AS location_id
FROM scoped_tickets t
LEFT JOIN glpi_itilcategories c ON c.id = t.itilcategories_id
LEFT JOIN glpi_slas sl ON sl.id = t.slas_id_ttr
LEFT JOIN reopened_tickets r ON r.items_id = t.id
LEFT JOIN refused_tickets f ON f.items_id = t.id
LEFT JOIN survey_ranked s ON s.tickets_id = t.id AND s.rn = 1
ORDER BY t.solvedate DESC, t.id DESC
SQL;
        $rows = $client->fetchAll($sql, $params, self::MAX_ROWS);
        $tickets = [];
        $locationIds = [];
        $invalidDurations = 0;
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($tickets[$id])) {
                throw new \RuntimeException('A extração retornou chamados duplicados. O relatório foi interrompido para evitar totais incorretos.');
            }
            $locationId = (int)($row['location_id'] ?? 0);
            if ($locationId > 0) {
                $locationIds[$locationId] = $locationId;
            }
            $tickets[$id] = [
                'id' => $id,
                'entity_id' => (int)$row['entity_id'],
                'title' => self::plain($row['title'] ?? ''),
                'type' => (int)$row['type'],
                'status' => (int)$row['status'],
                'priority' => (int)$row['priority'],
                'category_id' => (int)$row['category_id'],
                'category' => self::plain($row['category'] ?? ''),
                'opened_at' => self::date($row['opened_at']),
                'created_at' => self::date($row['created_at']),
                'solved_at' => self::date($row['solved_at']),
                'closed_at' => self::date($row['closed_at']),
                'deadline' => self::date($row['deadline']),
                'sla_id' => (int)$row['sla_id'],
                'sla_name' => self::plain($row['sla_name'] ?? ''),
                'waiting_seconds' => self::integer($row['waiting_seconds']),
                'sla_waiting_seconds' => self::integer($row['sla_waiting_seconds']),
                // Zero is legitimate when the service calendar counts no working time.
                'solve_seconds' => self::integer($row['solve_seconds']),
                'elapsed_seconds' => self::integer($row['elapsed_seconds']),
                'within_sla' => $row['within_sla'] === null ? null : (bool)$row['within_sla'],
                'group_ids' => [],
                'groups' => '',
                'n1_eligible' => false,
                'reopened' => (bool)$row['reopened'],
                'refused_solution' => (bool)$row['refused_solution'],
                'kb_category_id' => (int)($row['kb_category_id'] ?? 0),
                'kb_ids' => [],
                'kb_validated' => false,
                'satisfaction' => self::number($row['satisfaction']),
                'technicians' => '',
                'requesters' => '',
                'location' => '',
                'location_id' => $locationId,
            ];
            if (($tickets[$id]['solve_seconds'] !== null && $tickets[$id]['solve_seconds'] < 0)
                || ($tickets[$id]['elapsed_seconds'] !== null && $tickets[$id]['elapsed_seconds'] < 0)) {
                $invalidDurations++;
            }
        }
        unset($rows);
        if (!$tickets) {
            return [];
        }

        // Group membership is mandatory metric evidence; names are presentation only.
        $groupRows = $client->fetchAll($scope . '
SELECT DISTINCT g.tickets_id, g.groups_id
FROM glpi_groups_tickets g INNER JOIN scoped_tickets t ON t.id = g.tickets_id
WHERE g.type = 2 ORDER BY g.tickets_id, g.groups_id', $params, self::MAX_ROWS);
        $groupIds = [];
        foreach ($groupRows as $group) {
            $id = (int)$group['tickets_id'];
            $groupId = (int)$group['groups_id'];
            if (isset($tickets[$id]) && $groupId > 0) {
                $tickets[$id]['group_ids'][] = $groupId;
                $groupIds[$groupId] = $groupId;
            }
        }
        $groupNames = self::lookup($client, 'groups', array_values($groupIds), $warnings);
        $locationNames = self::lookup($client, 'locations', array_values($locationIds), $warnings);
        foreach ($tickets as &$ticket) {
            $names = [];
            foreach ($ticket['group_ids'] as $groupId) {
                $names[] = $groupNames[$groupId] ?? ('Grupo #' . $groupId);
            }
            $ticket['groups'] = implode('; ', $names);
            $ticket['location'] = $locationNames[$ticket['location_id']] ?? '';
        }
        unset($ticket);

        $actorsAvailable = self::optionalTable($client,
            'SELECT tickets_id, users_id, type FROM glpi_tickets_users LIMIT 0',
            'Os nomes de técnicos e solicitantes não estão acessíveis nesta conexão.', $warnings);
        if ($actorsAvailable) {
            $actors = $client->fetchAll($scope . '
SELECT DISTINCT a.tickets_id, a.users_id, a.type
FROM glpi_tickets_users a INNER JOIN scoped_tickets t ON t.id = a.tickets_id
WHERE a.type IN (1, 2) ORDER BY a.tickets_id, a.type, a.users_id', $params, self::MAX_ROWS);
            $userIds = [];
            foreach ($actors as $actor) {
                $userIds[(int)$actor['users_id']] = (int)$actor['users_id'];
            }
            $userNames = self::lookup($client, 'users', array_values($userIds), $warnings);
            $actorNames = [];
            foreach ($actors as $actor) {
                $id = (int)$actor['tickets_id'];
                $userId = (int)$actor['users_id'];
                $field = (int)$actor['type'] === 2 ? 'technicians' : 'requesters';
                if (isset($tickets[$id]) && $userId > 0) {
                    $actorNames[$id][$field][$userId] = $userNames[$userId] ?? ('Usuário #' . $userId);
                }
            }
            foreach ($actorNames as $id => $fields) {
                foreach ($fields as $field => $names) {
                    $tickets[$id][$field] = implode('; ', $names);
                }
            }
        }
        if ($hasKb) {
            $links = $client->fetchAll($scope . '
SELECT DISTINCT k.items_id, k.knowbaseitems_id
FROM glpi_knowbaseitems_items k INNER JOIN scoped_tickets t ON t.id = k.items_id
WHERE k.itemtype = \'Ticket\' AND k.knowbaseitems_id > 0
ORDER BY k.items_id, k.knowbaseitems_id', $params, self::MAX_ROWS);
            foreach ($links as $link) {
                $id = (int)$link['items_id'];
                if (isset($tickets[$id])) {
                    $tickets[$id]['kb_ids'][] = (int)$link['knowbaseitems_id'];
                }
            }
        }
        if ($invalidDurations > 0) {
            $warnings[] = $invalidDurations . ' chamado(s) contêm duração negativa na origem. Os valores originais foram preservados para conferência.';
        }
        return ActorEvidence::load($client, $scope, $params, array_values($tickets));
    }

    /** Lookup identifiers/SQL are fixed internally; ID values are prepared parameters. */
    private static function lookup(MysqlClient $client, string $kind, array $ids, array &$warnings): array
    {
        $queries = [
            'groups' => ['SELECT id, completename AS label FROM glpi_groups', 'grupos'],
            'locations' => ['SELECT id, completename AS label FROM glpi_locations', 'localizações'],
            'users' => ["SELECT id, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(firstname, ''), ' ', COALESCE(realname, ''))), ''), name) AS label FROM glpi_users", 'usuários'],
        ];
        $ids = self::ids($ids);
        if (!$ids) {
            return [];
        }
        $result = [];
        try {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $rows = $client->fetchAll($queries[$kind][0]
                    . ' WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk, self::MAX_ROWS);
                foreach ($rows as $row) {
                    $result[(int)$row['id']] = self::plain($row['label'] ?? '');
                }
            }
        } catch (ConnectionException $e) {
            if (!in_array($e->getReason(), ['missing_table', 'missing_columns', 'permission_denied'], true)) {
                throw $e;
            }
            $warnings[] = 'Os nomes de ' . $queries[$kind][1] . ' não estão acessíveis. Os identificadores disponíveis foram preservados.';
            return [];
        }
        return $result;
    }

    private static function calls(MysqlClient $client, array $input, array $period, bool $hasSurvey, array &$warnings, bool $hasNota = false): array
    {
        $queue = strtolower(trim((string)($input['queue_filter'] ?? 'ssdf')));
        if ($queue === '' || strlen($queue) > 100) {
            throw new \InvalidArgumentException('Informe um filtro de fila válido para as ligações do contrato.');
        }
        // Use an explicit LIKE escape character, independent of SQL backslash modes.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $queue) . '%';
        $rows = $client->fetchAll(self::callSql($hasSurvey, $hasNota), [$period['start'], $period['end'], $like], self::MAX_ROWS);
        $calls = [];
        $repeated = 0;
        $multipleCompletions = 0;
        $transfers = 0;
        $invalidDurations = 0;
        foreach ($rows as $row) {
            $connected = self::date($row['connected_at']);
            $completed = self::date($row['completed_at']);
            $abandoned = self::date($row['abandoned_at']);
            $answered = $connected !== null || $completed !== null;
            $registeredTalk = trim((string)($row['registered_talk'] ?? ''));
            $talk = $registeredTalk === '' ? self::integer($row['fallback_talk_seconds']) : self::integer($registeredTalk);
            $wait = self::integer($row['wait_seconds']);
            $abandonWait = self::integer($row['registered_abandon_wait']);
            if (($registeredTalk !== '' && $talk === null) || ($talk !== null && $talk < 0)
                || ($wait !== null && $wait < 0) || ($abandonWait !== null && $abandonWait < 0)) {
                $invalidDurations++;
            }
            $completionEvent = (string)($row['completion_event'] ?? '');
            $disconnect = $completionEvent === 'COMPLETEAGENT' ? 'Agente desligou'
                : ($completionEvent === 'COMPLETECALLER' ? 'Cliente desligou'
                    : (!$answered && $abandoned !== null ? 'Abandono na fila' : 'Sem evento de finalização'));
            $rawSatisfaction = $row['satisfaction'] === null ? null : (string)$row['satisfaction'];
            $entryCount = (int)$row['entry_count'];
            $completeCount = (int)$row['completion_count'];
            $transferCount = (int)$row['transfer_count'];
            $repeated += $entryCount > 1 ? 1 : 0;
            $multipleCompletions += $completeCount > 1 ? 1 : 0;
            $transfers += $transferCount > 0 ? 1 : 0;
            $calls[] = [
                'id' => (string)$row['call_id'],
                'queue' => (string)$row['queue'],
                'entered_at' => (string)$row['entered_at'],
                'connected_at' => $connected,
                'completed_at' => $completed,
                'abandoned_at' => $abandoned,
                'caller' => (string)($row['caller'] ?? ''),
                'type' => $answered ? 'Atendida' : ($abandoned !== null ? 'Abandonada' : 'Sem desfecho'),
                'termination' => $completionEvent !== '' ? $completionEvent : (!$answered && $abandoned !== null ? 'ABANDON' : 'Sem evento COMPLETE'),
                'disconnect' => $disconnect,
                'agent' => (string)($row['agent'] ?? ''),
                'talk_seconds' => $talk,
                'wait_seconds' => $wait,
                'abandon_wait_seconds' => $abandonWait,
                'ins7' => $completed !== null && $talk !== null && $talk >= 0 && $talk <= 600,
                'ins8' => $connected !== null && $wait !== null && $wait >= 0 && $wait <= 30,
                'ins9' => !$answered && $abandoned !== null && $abandonWait !== null && $abandonWait > 30,
                'satisfaction_raw' => $rawSatisfaction,
                'satisfaction_nota_raw' => isset($row['satisfaction_nota']) ? (string)$row['satisfaction_nota'] : null,
                'survey_id' => isset($row['survey_id']) ? (string)$row['survey_id'] : null,
                'survey_at' => self::date($row['survey_at'] ?? null),
                'repeated_entries' => $entryCount,
                'completion_events' => $completeCount,
                'transfer_events' => $transferCount,
            ];
        }
        if ($repeated > 0) {
            $warnings[] = $repeated . ' registro(s) de ligação/fila têm mais de uma entrada na mesma fila. A unidade segue callid + fila e usa a primeira entrada do mês; essas ocorrências requerem conferência.';
        }
        if ($multipleCompletions > 0) {
            $warnings[] = $multipleCompletions . ' registro(s) de ligação/fila têm vários eventos COMPLETE. A duração foi vinculada ao primeiro evento de conclusão, sem escolher a menor duração de outro evento.';
        }
        if ($transfers > 0) {
            $warnings[] = $transfers . ' registro(s) de ligação/fila possuem eventos de transferência. Esta extração mantém a unidade callid + fila; não soma pernas com novos identificadores.';
        }
        if ($invalidDurations > 0) {
            $warnings[] = $invalidDurations . ' ligação(ões) contêm duração inválida na origem. Valores ausentes ou negativos não são tratados como cumprimento de prazo.';
        }
        return $calls;
    }

    private static function callSql(bool $hasSurvey, bool $hasNota = false): string
    {
        $sql = <<<'SQL'
WITH entry_ranked AS (
    SELECT q.id, q.callid AS call_id, q.queuename AS queue,
           q.`time` AS entered_at, q.data2 AS caller,
           ROW_NUMBER() OVER (PARTITION BY q.callid, q.queuename ORDER BY q.`time`, q.id) AS rn
    FROM queue_log q
    WHERE q.event = 'ENTERQUEUE' AND q.callid IS NOT NULL AND q.callid NOT IN ('', 'NONE')
      AND q.`time` >= ? AND q.`time` < ? AND LOWER(q.queuename) LIKE ? ESCAPE '!'
),
entries AS (SELECT id, call_id, queue, entered_at, caller FROM entry_ranked WHERE rn = 1),
event_ranked AS (
    SELECT e.call_id, e.queue, q.`time`, q.event, q.agent, q.data2, q.data3,
           ROW_NUMBER() OVER (
               PARTITION BY e.call_id, e.queue,
                   CASE WHEN q.event IN ('COMPLETEAGENT', 'COMPLETECALLER') THEN 'COMPLETE' ELSE q.event END
               ORDER BY q.`time`, q.id
           ) AS rn
    FROM entries e
    INNER JOIN queue_log q ON q.callid = e.call_id AND q.queuename = e.queue
      AND (q.`time` > e.entered_at OR (q.`time` = e.entered_at AND q.id >= e.id))
    WHERE q.event IN ('ENTERQUEUE', 'CONNECT', 'COMPLETEAGENT', 'COMPLETECALLER', 'ABANDON', 'BLINDTRANSFER', 'ATTENDEDTRANSFER')
),
event_summary AS (
    SELECT call_id, queue,
           MAX(CASE WHEN event = 'CONNECT' AND rn = 1 THEN `time` END) AS connected_at,
           MAX(CASE WHEN event = 'CONNECT' AND rn = 1 THEN agent END) AS agent,
           MAX(CASE WHEN event IN ('COMPLETEAGENT', 'COMPLETECALLER') AND rn = 1 THEN `time` END) AS completed_at,
           MAX(CASE WHEN event IN ('COMPLETEAGENT', 'COMPLETECALLER') AND rn = 1 THEN event END) AS completion_event,
           MAX(CASE WHEN event IN ('COMPLETEAGENT', 'COMPLETECALLER') AND rn = 1 THEN data2 END) AS registered_talk,
           MAX(CASE WHEN event = 'ABANDON' AND rn = 1 THEN `time` END) AS abandoned_at,
           MAX(CASE WHEN event = 'ABANDON' AND rn = 1 THEN data3 END) AS registered_abandon_wait,
           SUM(CASE WHEN event = 'ENTERQUEUE' THEN 1 ELSE 0 END) AS entry_count,
           SUM(CASE WHEN event IN ('COMPLETEAGENT', 'COMPLETECALLER') THEN 1 ELSE 0 END) AS completion_count,
           SUM(CASE WHEN event IN ('BLINDTRANSFER', 'ATTENDEDTRANSFER') THEN 1 ELSE 0 END) AS transfer_count
    FROM event_ranked GROUP BY call_id, queue
)
SQL;
        if ($hasSurvey) {
            $notaExpression = $hasNota ? 'p.nota' : 'NULL';
            $surveyPredicate = $hasNota ? '(p.atendimento IS NOT NULL OR p.nota IS NOT NULL)' : 'p.atendimento IS NOT NULL';
            $sql .= <<<SQL
,
phone_survey_ranked AS (
    SELECT p.uniqueid, p.atendimento, {$notaExpression} AS nota, p.id AS survey_id, p.`date` AS survey_at,
           ROW_NUMBER() OVER (PARTITION BY p.uniqueid ORDER BY p.`date` DESC, p.id DESC) AS rn
    FROM tab_pesquisa p
    INNER JOIN (SELECT DISTINCT call_id FROM entries) ids ON ids.call_id = p.uniqueid
    WHERE {$surveyPredicate}
)
SQL;
        }
        $sql .= <<<'SQL'

SELECT e.call_id, e.queue, e.entered_at, e.caller,
       v.connected_at, v.agent, v.completed_at, v.completion_event,
       v.registered_talk, v.abandoned_at, v.registered_abandon_wait,
       TIMESTAMPDIFF(SECOND, e.entered_at, v.connected_at) AS wait_seconds,
       TIMESTAMPDIFF(SECOND, v.connected_at, v.completed_at) AS fallback_talk_seconds,
       v.entry_count, v.completion_count, v.transfer_count,
SQL;
        $sql .= $hasSurvey ? ' p.atendimento AS satisfaction, p.nota AS satisfaction_nota, p.survey_id, p.survey_at'
            : ' NULL AS satisfaction, NULL AS satisfaction_nota, NULL AS survey_id, NULL AS survey_at';
        $sql .= "\nFROM entries e LEFT JOIN event_summary v ON v.call_id = e.call_id AND v.queue = e.queue";
        if ($hasSurvey) {
            $sql .= "\nLEFT JOIN phone_survey_ranked p ON p.uniqueid = e.call_id AND p.rn = 1";
        }
        return $sql . "\nORDER BY e.entered_at DESC, e.call_id, e.queue";
    }

    private static function ids($values): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0) {
                $ids[(int)$value] = (int)$value;
            }
        }
        return array_values($ids);
    }

    private static function integer($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return preg_match('/\A-?[0-9]+\z/D', trim((string)$value)) ? (int)$value : null;
    }

    private static function number($value): ?float
    {
        return $value !== null && $value !== '' && is_numeric($value) ? (float)$value : null;
    }

    private static function date($value): ?string
    {
        return $value === null || $value === '' || strpos((string)$value, '0000-00-00') === 0 ? null : (string)$value;
    }

    private static function plain($value): string
    {
        return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
