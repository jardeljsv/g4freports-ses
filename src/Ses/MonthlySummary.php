<?php

namespace GlpiPlugin\G4freports\Ses;

/** Frozen, compact projection of an existing snapshot. Never recalculates or reads a source. */
final class MonthlySummary
{
    public static function fromSnapshot(array $snapshot): array
    {
        $input = is_array($snapshot['input'] ?? null) ? $snapshot['input'] : [];
        $period = is_array($snapshot['period'] ?? null) ? $snapshot['period'] : [];
        $month = $period['month'] ?? $input['month'] ?? '';
        if (!is_string($month) || !preg_match('/\A(?:20\d{2}|2100)-(?:0[1-9]|1[0-2])\z/D', $month)) {
            throw new \InvalidArgumentException('A extração não contém uma competência válida entre 2000 e 2100.');
        }
        $sources = [];
        foreach (['glpi', 'callcenter'] as $name) {
            $value = $snapshot['source_identity'][$name] ?? null;
            $sources[$name] = is_string($value) && preg_match('/\A[a-f0-9]{64}\z/Di', $value) ? strtolower($value) : null;
        }
        $rules = self::text($snapshot['rules_version'] ?? '');
        $schema = is_int($snapshot['schema_version'] ?? null) ? $snapshot['schema_version'] : null;
        $periodState = self::periodState($month, $period['end_exclusive'] ?? $input['end_exclusive'] ?? null,
            $snapshot['generated_at'] ?? null, $input['timezone'] ?? null);
        $given = [];
        foreach (is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [] as $key => $metric) {
            if (!is_array($metric)) continue;
            $id = $metric['id'] ?? $key;
            if (!is_string($id) || !preg_match('/\AINS(?:[1-9]|1[0-2])\z/D', $id)) continue;
            if (isset($given[$id])) throw new \InvalidArgumentException('A extração contém indicador duplicado: ' . $id . '.');
            $given[$id] = $metric;
        }
        $metrics = [];
        for ($n = 1; $n <= 12; $n++) {
            $id = 'INS' . $n;
            $original = $given[$id] ?? [];
            $value = self::number($original['value'] ?? null);
            $direction = self::text($original['direction'] ?? (in_array($id, ['INS5', 'INS9', 'INS10'], true) ? 'lte' : 'gte'));
            $metrics[$id] = [
                'id' => $id, 'label' => self::text($original['label'] ?? $id),
                'title' => self::text($original['title'] ?? MetricEvidence::title($id, $input['priority_map'][$id] ?? null)),
                'status' => self::text($original['status'] ?? 'missing'),
                'target' => self::number($original['target'] ?? null), 'direction' => $direction,
                'value' => $value, 'raw_ratio' => self::number($original['raw_ratio'] ?? $value),
                'numerator' => self::number($original['numerator'] ?? null),
                'denominator' => self::number($original['denominator'] ?? null),
                'method' => self::text($original['method'] ?? ''), 'note' => self::text($original['note'] ?? ''),
                'exclusion_note' => self::text($original['exclusion_note'] ?? ''),
                'coverage_percent' => self::number($original['coverage_percent'] ?? null),
                'evidence_complete' => isset($original['evidence_complete']) ? (bool)$original['evidence_complete'] : null,
            ];
            $metrics[$id]['comparability'] = self::comparability($id, $metrics[$id], $input, $period, $rules, $schema, $sources);
        }
        $financial = is_array($snapshot['financial'] ?? null) ? $snapshot['financial'] : [];
        $money = [];
        foreach (['gross_cents' => 'gross_value', 'deduction_cents' => 'deduction_total',
            'adjustment_cents' => 'adjustment_total', 'net_cents' => 'net_value'] as $dest => $source) {
            $money[$dest] = self::cents($financial[$source] ?? null);
        }
        $available = !empty($financial['available']);
        $complete = $available && !empty($financial['complete']) && !in_array(null, $money, true);
        $financialNotes = self::texts($financial['notes'] ?? []);
        if (!empty($financial['complete']) && !$complete) $financialNotes[] = 'Valores financeiros arquivados incompletos; competência excluída do subtotal.';
        $manual = self::scalars($snapshot['manual'] ?? [], ['executive_summary', 'actions', 'notes', 'phone_survey_actions']);
        if (!array_key_exists('phone_survey_actions', $manual) && is_string($input['phone_survey_actions'] ?? null)) {
            $manual['phone_survey_actions'] = $input['phone_survey_actions'];
        }
        $groups = self::ids($input['group_ids'] ?? []);
        $scopeLabel = 'Entidade ' . (isset($input['entity_id']) ? (string)$input['entity_id'] : 'não registrada')
            . ' · Grupos ' . ($groups ? implode(', ', $groups) : 'todos')
            . ' · Filas ' . self::text($input['queue_filter'] ?? 'não registradas');
        return [
            'schema_version' => 1, 'month' => $month,
            'period' => ['month' => $month, 'start' => self::text($period['start'] ?? $input['start'] ?? ''),
                'end_exclusive' => self::text($period['end_exclusive'] ?? $input['end_exclusive'] ?? ''),
                'label' => self::text($period['label'] ?? $month),
                'date_basis' => self::text($period['date_basis'] ?? ''), 'call_date_basis' => self::text($period['call_date_basis'] ?? '')] + $periodState,
            'generated_at' => self::text($snapshot['generated_at'] ?? ''), 'rules_version' => $rules,
            'snapshot_schema' => $schema, 'scope_label' => $scopeLabel,
            'source_identity' => ['status' => !in_array(null, $sources, true) ? 'known' : 'unknown', 'value' => $sources],
            'metrics' => $metrics,
            'totals' => self::scalars($snapshot['totals'] ?? [], ['tickets', 'requests', 'incidents', 'tickets_assigned_n3',
                'calls_received', 'calls_answered', 'calls_abandoned', 'calls_abandoned_over_30', 'calls_abandoned_up_to_30',
                'calls_abandoned_unknown_wait', 'calls_excluded', 'calls_unknown', 'missing_deadline', 'sla_evaluated',
                'sla_unresolved', 'sla_scope_count', 'sla_scope_evaluated', 'sla_scope_unresolved', 'sla_recovered',
                'sla_differences', 'sla_provisional', 'mean_talk_seconds', 'mean_wait_seconds', 'mean_talk_count', 'mean_wait_count']),
            'financial' => ['available' => $available, 'complete' => $complete] + $money + ['notes' => $financialNotes],
            'manual' => $manual,
            'warnings' => self::texts($snapshot['warnings'] ?? []),
            'rule_notes' => self::texts($snapshot['rule_notes'] ?? []),
            'staffing' => self::scalars($snapshot['staff_register'] ?? [], ['configured', 'complete', 'active_total', 'departures_total',
                'billed_total', 'unbilled_total', 'billing_professionals_total', 'billed_days_total', 'billing_month_days',
                'window_start', 'window_end', 'numerator', 'denominator', 'value', 'method', 'note']),
        ];
    }

    private static function comparability(string $id, array $metric, array $input, array $period, string $rules, ?int $schema, array $sources): array
    {
        $context = ['rules_version' => $rules, 'snapshot_schema' => $schema, 'timezone' => $input['timezone'] ?? null,
            'target' => $metric['target'], 'direction' => $metric['direction']];
        $missing = [];
        if ($rules === '') $missing[] = 'rules_version';
        if ($schema === null) $missing[] = 'snapshot_schema';
        if (!in_array($metric['direction'], ['gte', 'lte'], true)) $missing[] = 'direction';
        $required = ['timezone'];
        $glpi = !in_array($id, ['INS7', 'INS8', 'INS9', 'INS10'], true);
        $phone = in_array($id, ['INS7', 'INS8', 'INS9'], true)
            || ($id === 'INS12' && ($input['ins12_mode'] ?? '') === 'glpi_and_phone');
        if ($glpi) {
            $required = array_merge($required, ['entity_id', 'group_ids']);
            $context['entity_id'] = $input['entity_id'] ?? null;
            $context['group_ids'] = self::ids($input['group_ids'] ?? []);
            $context['date_basis'] = $period['date_basis'] ?? null;
            $context['source_glpi'] = $sources['glpi'];
            if ($sources['glpi'] === null) $missing[] = 'source_glpi';
            if (empty($period['date_basis'])) $missing[] = 'date_basis';
        }
        if (in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true)) {
            $context['priority'] = $input['priority_map'][$id] ?? null;
            if ($context['priority'] === null) $missing[] = 'priority';
        }
        $groups = ['INS3' => ['n3_group_ids'], 'INS4' => ['n1_group_ids', 'n2_group_ids', 'n3_group_ids'],
            'INS5' => ['n1_group_ids', 'n2_group_ids', 'n3_group_ids'], 'INS6' => ['n1_group_ids', 'n3_group_ids', 'n1_categories']];
        foreach ($groups[$id] ?? [] as $field) { $required[] = $field; $context[$field] = self::ids($input[$field] ?? []); }
        if ($id === 'INS6') { $required[] = 'ins6_mode'; $context['ins6_mode'] = $input['ins6_mode'] ?? null; }
        if ($id === 'INS10') { $required[] = 'staff_mode'; $context['staff_mode'] = $input['staff_mode'] ?? null; }
        if ($id === 'INS11') {
            $required[] = 'kb_mode'; $context['kb_mode'] = $input['kb_mode'] ?? null;
            if (($input['kb_mode'] ?? '') === 'validated_links') {
                $required[] = 'validated_kb_ids'; $context['validated_kb_ids'] = self::ids($input['validated_kb_ids'] ?? []);
            }
        }
        if ($id === 'INS12') { $required[] = 'ins12_mode'; $context['ins12_mode'] = $input['ins12_mode'] ?? null; }
        if ($phone) {
            $required = array_merge($required, ['queue_filter', 'test_numbers']);
            $context['queue_filter'] = strtolower(trim(self::text($input['queue_filter'] ?? '')));
            $numbers = [];
            foreach (is_array($input['test_numbers'] ?? null) ? $input['test_numbers'] : [] as $test) if (is_array($test) && !empty($test['active'])) {
                $number = preg_replace('/\D/', '', self::text($test['number'] ?? ''));
                if ($number !== '') $numbers[$number] = $number;
            }
            $numbers = array_values($numbers); sort($numbers, SORT_STRING);
            $context['test_numbers_revision'] = hash('sha256', self::json($numbers));
            $context['call_date_basis'] = $period['call_date_basis'] ?? null;
            $context['source_callcenter'] = $sources['callcenter'];
            if ($sources['callcenter'] === null) $missing[] = 'source_callcenter';
            if (empty($period['call_date_basis'])) $missing[] = 'call_date_basis';
        }
        foreach ($required as $field) if (!array_key_exists($field, $input) || $input[$field] === null) $missing[] = $field;
        ksort($context);
        return ['fingerprint' => hash('sha256', self::json($context)), 'known' => !$missing,
            'missing_context' => array_values(array_unique($missing)), 'context' => $context];
    }

    private static function ids($values): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) if (is_int($value) || (is_string($value) && ctype_digit($value))) $ids[(int)$value] = (int)$value;
        $ids = array_values($ids); sort($ids, SORT_NUMERIC); return $ids;
    }

    /** Freeze whether the extraction had reached the month end; never consult today's date on archive reads. */
    private static function periodState(string $month, $end, $generated, $timezone): array
    {
        $unknown = ['completeness' => 'unknown', 'complete' => false,
            'note' => 'Data da extração, encerramento ou fuso não registrados de forma válida; fechamento mensal não confirmado.'];
        if (!is_string($end) || !is_string($generated) || !is_string($timezone) || $timezone === '') return $unknown;
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/D', $generated)
            || substr($generated, -6) === '-00:00') return $unknown;
        try {
            $zone = new \DateTimeZone($timezone);
            $monthEnd = (new \DateTimeImmutable($month . '-01 00:00:00', $zone))->modify('+1 month');
            if ($end !== $monthEnd->format('Y-m-d H:i:s')) return $unknown;
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $generated);
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$time || ($errors && ($errors['warning_count'] || $errors['error_count']))) return $unknown;
        } catch (\Exception $e) { return $unknown; }
        $complete = $time >= $monthEnd;
        return ['completeness' => $complete ? 'complete' : 'provisional', 'complete' => $complete,
            'note' => $complete ? 'Extração registrada após o encerramento da competência.'
                : 'Extração anterior ao encerramento da competência; valores mensais provisórios.'];
    }

    private static function cents($value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A(-?)(\d{1,14})(?:\.(\d{1,2}))?\z/D', (string)$value, $m)) return null;
        $cents = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }

    private static function number($value)
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float)$value)) return null;
        return $value + 0;
    }

    private static function text($value): string { return is_string($value) ? $value : ''; }
    private static function texts($values): array { return is_array($values) ? array_values(array_filter($values, 'is_string')) : []; }
    private static function scalars($values, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) if (is_array($values) && array_key_exists($field, $values) && (is_scalar($values[$field]) || $values[$field] === null)) $result[$field] = $values[$field];
        return $result;
    }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
