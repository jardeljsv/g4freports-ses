<?php

namespace GlpiPlugin\G4freports\Ses;

use GlpiPlugin\G4freports\Database\ConnectionException;
use GlpiPlugin\G4freports\Database\MysqlClient;

/** Technical evidence for SLA reconstruction; never writes to either source. */
final class SlaDataSource
{
    private const MAX_CATALOG_ROWS = 10000;
    private const MAX_HISTORY_ROWS = 250000;
    private const BATCH_SIZE = 500;
    private const TABLES = [
        'glpi_slas', 'glpi_slms', 'glpi_entities', 'glpi_calendars',
        'glpi_calendarsegments', 'glpi_calendars_holidays', 'glpi_holidays',
        'glpi_logs', 'glpi_configs', 'glpi_tickets',
    ];

    private MysqlClient $client;
    private array $schema = [];
    private array $columnTypes = [];
    private array $warnings = [];
    private array $capabilities = [
        'schema' => false, 'timezone' => false, 'agreements' => false, 'parent_slms' => false,
        'entities' => false, 'calendars' => false, 'history' => false,
        'history_numeric_ids' => false,
    ];

    private function __construct(MysqlClient $client)
    {
        $this->client = $client;
    }

    public static function load(MysqlClient $client, array $tickets, array $input, array &$warnings): array
    {
        $source = new self($client);
        $context = $source->read($tickets, $input);
        $warnings = array_merge($warnings, $source->warnings);
        return $context;
    }

    private function read(array $tickets, array $input): array
    {
        $context = [
            'timezone' => null, 'database_timezone' => [], 'agreements' => [],
            'calendars' => [], 'entities' => [], 'histories' => [],
            'history_available' => false,
        ];
        $this->optional('schema', function (): void {
            $rows = $this->client->fetchAll(
                'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
                . implode(',', array_fill(0, count(self::TABLES), '?')) . ')', self::TABLES, 2000
            );
            foreach ($rows as $row) {
                $this->schema[(string)$row['table_name']][(string)$row['column_name']] = true;
                $this->columnTypes[(string)$row['table_name']][(string)$row['column_name']] = strtolower((string)($row['data_type'] ?? ''));
            }
            if (!$this->schema) {
                throw new ConnectionException('missing_columns', 'Esquema técnico indisponível.');
            }
        }, 'Não foi possível consultar o esquema técnico de SLA. A reconstrução ficará pendente onde as evidências não puderem ser verificadas.');

        if ($this->capabilities['schema']) {
            $this->optional('timezone', function () use (&$context, $input): void {
                $rows = [];
                try {
                    $this->requireColumns('glpi_configs', ['context', 'name', 'value']);
                    $rows = $this->client->fetchAll(
                        "SELECT value FROM glpi_configs WHERE context = 'core' AND name = 'timezone'", [], 2
                    );
                } catch (ConnectionException $error) {
                    if (!in_array($error->getReason(), ['missing_table', 'missing_columns', 'permission_denied'], true)) {
                        throw $error;
                    }
                }
                $timezone = count($rows) === 1 ? trim((string)$rows[0]['value']) : '';
                if ($timezone === '') {
                    $timezone = trim((string)($input['timezone'] ?? ''));
                    $this->warnings[] = 'O fuso configurado no GLPI não foi retornado. Foi utilizado o fuso explícito do relatório, com verificação da sessão SQL para campos TIMESTAMP.';
                }
                try {
                    $zone = $timezone !== '' ? new \DateTimeZone($timezone) : null;
                } catch (\Exception $error) {
                    $zone = null;
                }
                if ($zone === null) {
                    throw new ConnectionException('missing_columns', 'Fuso GLPI indisponível.');
                }
                $database = $this->client->fetchAll(
                    'SELECT @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone,'
                    . ' TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS utc_offset_seconds', [], 1
                );
                $context['database_timezone'] = $database[0] ?? [];
                $offset = $context['database_timezone']['utc_offset_seconds'] ?? null;
                $hasTimestamp = false;
                foreach (['glpi_tickets' => ['date', 'solvedate', 'closedate', 'date_creation', 'time_to_resolve'], 'glpi_logs' => ['date_mod']] as $table => $fields) {
                    foreach ($fields as $field) {
                        $type = $this->columnTypes[$table][$field] ?? '';
                        if (!in_array($type, ['timestamp', 'datetime'], true)) {
                            throw new ConnectionException('missing_columns', 'Tipo temporal não confirmado.');
                        }
                        $hasTimestamp = $hasTimestamp || $type === 'timestamp';
                    }
                }
                $context['database_timezone']['timestamp_columns'] = $hasTimestamp;
                // DATETIME carries local values; TIMESTAMP is converted by the SQL session.
                if ($hasTimestamp && ($offset === null || (int)$offset !== $zone->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))))) {
                    $this->warnings[] = 'O fuso configurado no GLPI difere do fuso da sessão de leitura do banco. '
                        . 'Confira os fusos antes de reconstruir os relógios de SLA; não foi aplicada conversão presumida.';
                    throw new ConnectionException('missing_columns', 'Fusos divergentes.');
                }
                $context['timezone'] = $timezone;
            }, 'O fuso horário da fonte GLPI não pôde ser confirmado; a reconstrução de SLA que depende do calendário ficará pendente.');

            $this->optional('agreements', function () use (&$context): void {
                $context['agreements'] = $this->agreements();
            }, 'As definições de SLA/SLM não estão acessíveis por completo. Não será presumido um calendário a partir do nome do SLA.');
            $this->optional('entities', function () use (&$context): void {
                $context['entities'] = $this->entities();
            }, 'A herança de calendários das entidades não está acessível. Os casos que dependem dessa herança ficarão pendentes.');
            $this->optional('calendars', function () use (&$context): void {
                $context['calendars'] = $this->calendars();
            }, 'Os calendários, segmentos ou feriados não estão acessíveis por completo. A extração não presumiu horários ou ausência de feriados.');
            $this->optional('history', function () use (&$context, $tickets): void {
                $context['histories'] = $this->histories($tickets);
                $context['history_available'] = true;
            }, 'O histórico técnico completo dos chamados não está acessível. A extração não presumiu ausência de suspensão, reabertura ou mudanças de SLA.');
        }

        foreach (['sla_n1_calendar_id', 'sla_n2_calendar_id', 'sla_n3_calendar_id'] as $field) {
            $id = (int)($input[$field] ?? 0);
            if ($id > 0 && !isset($context['calendars'][$id])) {
                throw new \InvalidArgumentException('O calendário de SLA selecionado (ID ' . $id
                    . ') não existe ou não está acessível por completo no banco GLPI deste relatório.');
            }
        }
        $context['capabilities'] = $this->capabilities;
        return $context;
    }

    private function agreements(): array
    {
        $fields = ['id', 'name', 'entities_id', 'is_recursive', 'type', 'number_time',
            'definition_time', 'end_of_working_day', 'use_ticket_calendar', 'calendars_id', 'slms_id'];
        $this->requireColumns('glpi_slas', $fields);
        $slms = [];
        $this->optional('parent_slms', function () use (&$slms): void {
            $this->requireColumns('glpi_slms', ['id', 'name', 'calendars_id', 'use_ticket_calendar']);
            $slmFields = ['id', 'name', 'calendars_id', 'use_ticket_calendar'];
            $slmFields = array_merge($slmFields, $this->available('glpi_slms', ['entities_id', 'is_recursive', 'date_creation', 'date_mod']));
            foreach ($this->client->fetchAll('SELECT ' . self::columns($slmFields) . ' FROM glpi_slms ORDER BY id', [], self::MAX_CATALOG_ROWS) as $row) {
                $slms[(int)$row['id']] = $row;
            }
        }, 'O cadastro SLM não está acessível para conferência. Foram preservadas as definições gravadas em cada SLA, utilizadas pelo GLPI no cálculo.');
        $fields = array_merge($fields, $this->available('glpi_slas', ['date_creation', 'date_mod']));
        $result = [];
        $unresolved = 0;
        $mismatches = 0;
        foreach ($this->client->fetchAll('SELECT ' . self::columns($fields) . ' FROM glpi_slas ORDER BY id', [], self::MAX_CATALOG_ROWS) as $row) {
            $row['raw_calendars_id'] = $row['calendars_id'];
            $row['raw_use_ticket_calendar'] = $row['use_ticket_calendar'];
            $row['parent_slm_mismatch'] = false;
            $slmId = (int)$row['slms_id'];
            if ($slmId > 0) {
                if (!isset($slms[$slmId])) {
                    $unresolved++;
                    $row['parent_slm_unavailable'] = true;
                } else {
                    foreach ($slms[$slmId] as $key => $value) {
                        $row['parent_slm_' . $key] = $value;
                    }
                    // GLPI copies SLM fields on create/change of SLM, but computes
                    // dates using the fields actually stored in the SLA itself.
                    $row['parent_slm_mismatch'] = (int)$row['calendars_id'] !== (int)$slms[$slmId]['calendars_id']
                        || (int)$row['use_ticket_calendar'] !== (int)$slms[$slmId]['use_ticket_calendar'];
                    if ($row['parent_slm_mismatch']) {
                        $mismatches++;
                    }
                }
            }
            $result[(int)$row['id']] = $row;
        }
        if ($unresolved > 0) {
            $this->warnings[] = $unresolved . ' definição(ões) de SLA referencia(m) SLM ausente ou inacessível. Foram preservados os campos gravados no SLA, sem substituí-los por valores presumidos do SLM.';
        }
        if ($mismatches > 0) {
            $this->warnings[] = $mismatches . ' definição(ões) de SLA diverge(m) do calendário ou da opção de herança do SLM atual. Foram mantidos os campos gravados no SLA, que o GLPI utiliza em execução; confira a divergência cadastral.';
        }
        return $result;
    }

    private function entities(): array
    {
        $fields = ['id', 'entities_id', 'calendars_id'];
        $this->requireColumns('glpi_entities', $fields);
        // calendars_strategy is GLPI 11; GLPI 10 keeps inheritance in calendars_id.
        $fields = array_merge($fields, $this->available('glpi_entities', ['name', 'calendars_strategy', 'date_creation', 'date_mod']));
        $result = [];
        foreach ($this->client->fetchAll('SELECT ' . self::columns($fields) . ' FROM glpi_entities ORDER BY id', [], self::MAX_CATALOG_ROWS) as $row) {
            $result[(int)$row['id']] = $row;
        }
        return $result;
    }

    private function calendars(): array
    {
        $this->requireColumns('glpi_calendars', ['id', 'name']);
        $this->requireColumns('glpi_calendarsegments', ['id', 'calendars_id', 'day', 'begin', 'end']);
        $this->requireColumns('glpi_holidays', ['id', 'begin_date', 'end_date', 'is_perpetual']);
        $this->requireColumns('glpi_calendars_holidays', ['calendars_id', 'holidays_id']);
        $fields = array_merge(['id', 'name'], $this->available('glpi_calendars', ['entities_id', 'is_recursive', 'date_creation', 'date_mod']));
        $result = [];
        foreach ($this->client->fetchAll('SELECT ' . self::columns($fields) . ' FROM glpi_calendars ORDER BY id', [], self::MAX_CATALOG_ROWS) as $row) {
            $row['segments'] = [];
            $row['holidays'] = [];
            $result[(int)$row['id']] = $row;
        }
        $segments = $this->client->fetchAll('SELECT id, calendars_id, day, `begin`, `end` FROM glpi_calendarsegments ORDER BY calendars_id, day, `begin`, id', [], self::MAX_CATALOG_ROWS);
        foreach ($segments as $row) {
            if (isset($result[(int)$row['calendars_id']])) {
                $result[(int)$row['calendars_id']]['segments'][] = [
                    'id' => (int)$row['id'], 'day' => (int)$row['day'], 'begin' => $row['begin'], 'end' => $row['end'],
                ];
            }
        }
        $fields = array_merge(['id', 'begin_date', 'end_date', 'is_perpetual'], $this->available('glpi_holidays', ['name', 'date_creation', 'date_mod']));
        $holidays = [];
        foreach ($this->client->fetchAll('SELECT ' . self::columns($fields) . ' FROM glpi_holidays ORDER BY id', [], self::MAX_CATALOG_ROWS) as $row) {
            $holidays[(int)$row['id']] = $row;
        }
        $links = $this->client->fetchAll('SELECT calendars_id, holidays_id FROM glpi_calendars_holidays ORDER BY calendars_id, holidays_id', [], self::MAX_CATALOG_ROWS);
        foreach ($links as $row) {
            $id = (int)$row['calendars_id'];
            $holidayId = (int)$row['holidays_id'];
            if (isset($result[$id])) {
                if (!isset($holidays[$holidayId])) {
                    throw new ConnectionException('missing_columns', 'Feriado vinculado não encontrado.');
                }
                $result[$id]['holidays'][] = $holidays[$holidayId];
            }
        }
        return $result;
    }

    private function histories(array $tickets): array
    {
        $this->requireColumns('glpi_logs', ['id', 'items_id', 'itemtype', 'itemtype_link',
            'linked_action', 'date_mod', 'id_search_option', 'old_value', 'new_value']);
        $numeric = $this->available('glpi_logs', ['old_id', 'new_id']);
        $this->capabilities['history_numeric_ids'] = count($numeric) === 2;
        $ids = [];
        foreach ($tickets as $ticket) {
            if ((int)($ticket['id'] ?? 0) > 0) {
                $ids[(int)$ticket['id']] = (int)$ticket['id'];
            }
        }
        $result = array_fill_keys(array_values($ids), []);
        $total = 0;
        foreach (array_chunk(array_values($ids), self::BATCH_SIZE) as $chunk) {
            $sql = 'SELECT id, items_id, itemtype, itemtype_link, linked_action, date_mod, id_search_option,'
                . " CASE WHEN linked_action = 12 THEN '' ELSE old_value END AS old_value,"
                . " CASE WHEN linked_action = 12 THEN 'Clone' ELSE new_value END AS new_value,"
                . ' CASE WHEN linked_action = 12 THEN 1 ELSE 0 END AS is_clone';
            foreach ($numeric as $field) {
                $sql .= ', `' . $field . '`';
            }
            $sql .= " FROM glpi_logs WHERE itemtype = 'Ticket' AND items_id IN ("
                . implode(',', array_fill(0, count($chunk), '?')) . ') AND ('
                // Field IDs are GLPI ticket technical search options; no content/title/people fields.
                . ' (linked_action = 0 AND id_search_option IN (3,7,8,12,15,16,17,18,30,37,80,180,185,190,191))'
                . " OR (itemtype_link = 'Group' AND linked_action IN (15,16))"
                . " OR (itemtype_link = 'PendingReason' AND linked_action IN (15,16))"
                . " OR (itemtype_link = 'ITILSolution' AND linked_action IN (17,18))"
                . ' OR linked_action = 20'
                . " OR (linked_action = 12 AND itemtype_link IN ('','0')"
                . " AND (new_value LIKE 'Clone de %' OR new_value LIKE 'Cloned from %'))"
                . ') ORDER BY items_id, date_mod, id';
            $remaining = self::MAX_HISTORY_ROWS - $total;
            // A SELECT exceeding the budget throws; no silently truncated history is returned.
            $rows = $this->client->fetchAll($sql, $chunk, max(1, $remaining));
            $total += count($rows);
            if ($total > self::MAX_HISTORY_ROWS) {
                throw new ConnectionException('row_limit_exceeded', 'O histórico técnico excedeu o limite seguro. Restrinja o escopo do relatório.');
            }
            foreach ($rows as $row) {
                $result[(int)$row['items_id']][] = $row;
            }
        }
        return $result;
    }

    private function optional(string $capability, callable $read, string $warning): void
    {
        try {
            $read();
            $this->capabilities[$capability] = true;
        } catch (ConnectionException $error) {
            if (!in_array($error->getReason(), ['missing_table', 'missing_columns', 'permission_denied'], true)) {
                throw $error;
            }
            $this->warnings[] = $warning;
            $this->capabilities[$capability] = false;
        }
    }

    private function requireColumns(string $table, array $columns): void
    {
        if (count($this->available($table, $columns)) !== count($columns)) {
            throw new ConnectionException('missing_columns', 'Colunas técnicas de SLA indisponíveis.');
        }
    }

    private function available(string $table, array $columns): array
    {
        return array_values(array_filter($columns, function (string $column) use ($table): bool {
            return isset($this->schema[$table][$column]);
        }));
    }

    /** All identifiers originate from the fixed technical column lists above. */
    private static function columns(array $columns): string
    {
        return '`' . implode('`, `', $columns) . '`';
    }
}
