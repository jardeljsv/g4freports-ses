<?php

namespace GlpiPlugin\G4freports\Ses;

/** Input validation shared by the SES screen and its extraction endpoint. */
class Settings
{
    public const RULES_VERSION = 'ses-2026-10-05-v16';

    public static function defaults(): array
    {
        return ['ses_test_numbers_json' => '[]', StaffRegister::CONFIG_KEY => '[]', FinanceSettings::CONFIG_KEY => '{}'];
    }

    public static function normalizeNarrative(array $input): array
    {
        $result = [];
        foreach (['report_title' => ['Título', 180], 'executive_summary' => ['Resumo executivo', 20000],
            'actions' => ['Ações e justificativas', 20000], 'notes' => ['Observações', 20000],
            'phone_survey_actions' => ['Providências da pesquisa telefônica', 20000]] as $field => $rule) {
            if (array_key_exists($field, $input)) $result[$field] = self::text($input[$field], $rule[0], $rule[1]);
        }
        return $result;
    }

    public static function normalizeInput(array $input, array $settings): array
    {
        $month = self::text($input['month'] ?? '', 'Mês', 7);
        if (!preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $month)) {
            throw new \InvalidArgumentException('Informe o mês no formato AAAA-MM, entre 2000 e 2100.');
        }
        $zone = new \DateTimeZone('America/Sao_Paulo');
        $start = new \DateTimeImmutable($month . '-01 00:00:00', $zone);
        $staffRegister = StaffRegister::fromSettings($settings);
        // Default to the contract order used by the validated monthly report.
        // An explicitly selected dashboard mapping remains supported.
        $priorityMap = ['INS1' => 5, 'INS2' => 4, 'INS3' => 3, 'INS4' => 2];
        if (isset($input['priority_map'])) {
            if (!is_array($input['priority_map']) || count($input['priority_map']) !== 4) {
                throw new \InvalidArgumentException('Informe as quatro associações INS1–INS4, usando uma vez cada prioridade: 2, 3, 4 e 5.');
            }
            foreach ($priorityMap as $id => $priority) {
                $priorityMap[$id] = self::integer($input['priority_map'][$id] ?? null, $id, 2, 5);
            }
            if (count(array_unique($priorityMap)) !== 4) {
                throw new \InvalidArgumentException('Cada prioridade (2, 3, 4 e 5) deve aparecer uma única vez no mapeamento INS1–INS4.');
            }
        }
        $n1Defaults = [171,268,271,275,293,269,276,278,274,273,277,196,201,200,198,199,197,
            156,164,167,160,161,162,158,247,165,159,139,280,143,149,174,175,176,
            209,292,220,219,212,211,210,266,267,279];
        if (($input['ins6_mode'] ?? 'assigned_n1') === 'no_n2') {
            throw new \InvalidArgumentException('O critério antigo do INS6 (ausência de N2) foi substituído por vínculo N1 ou solucionador N1 quando não há grupo técnico atribuído. Recarregue a página e gere novamente a extração.');
        }
        $normalized = [
            'month' => $month,
            'start' => $start->format('Y-m-d H:i:s'),
            'end_exclusive' => $start->modify('+1 month')->format('Y-m-d H:i:s'),
            'period_start' => $start->format('Y-m-d H:i:s'),
            'period_end_exclusive' => $start->modify('+1 month')->format('Y-m-d H:i:s'),
            'entity_id' => self::integer($input['entity_id'] ?? 1, 'Entidade', 0, 2147483647),
            'group_ids' => self::ids($input['group_ids'] ?? [], 'Grupos'),
            'queue_filter' => self::text($input['queue_filter'] ?? 'ssdf', 'Filtro de fila', 100),
            'priority_map' => $priorityMap,
            'n1_categories' => self::ids($input['n1_categories'] ?? $n1Defaults, 'Categorias N1'),
            'n1_group_ids' => self::ids($input['n1_group_ids'] ?? [55], 'Grupos N1'),
            'n2_group_ids' => self::ids($input['n2_group_ids'] ?? [56,3310,3305], 'Grupos N2'),
            'n3_group_ids' => self::ids($input['n3_group_ids'] ?? [57,58,59,60,1663,3294,3300,3304,3306], 'Grupos N3'),
            // Zero resolves from the SLA catalogue; it never means a 24-hour calendar.
            'sla_n1_calendar_id' => self::integer($input['sla_n1_calendar_id'] ?? 0, 'Calendário N1', 0, 2147483647),
            'sla_n2_calendar_id' => self::integer($input['sla_n2_calendar_id'] ?? 0, 'Calendário N2', 0, 2147483647),
            'sla_n3_calendar_id' => self::integer($input['sla_n3_calendar_id'] ?? 0, 'Calendário N3', 0, 2147483647),
            'ins6_mode' => self::choice($input['ins6_mode'] ?? 'assigned_n1', ['assigned_n1'], 'Critério INS6'),
            'ins12_mode' => self::choice($input['ins12_mode'] ?? 'glpi_and_phone', ['glpi_and_phone', 'glpi_only'], 'Fontes INS12'),
            'kb_mode' => self::choice($input['kb_mode'] ?? 'validated_links', ['validated_links', 'category_proxy'], 'Critério INS11'),
            'validated_kb_ids' => self::ids($input['validated_kb_ids'] ?? [], 'Artigos validados'),
            'incomplete_ticket_ids' => self::ids($input['incomplete_ticket_ids'] ?? [], 'Chamados com execução incompleta'),
            'staff_total' => self::optionalInteger($input['staff_total'] ?? null, 'Total de profissionais'),
            'staff_replacements' => self::optionalInteger($input['staff_replacements'] ?? null, 'Substituições nos últimos três meses'),
            'staff_excluded' => self::optionalInteger($input['staff_excluded'] ?? null, 'Reduções determinadas pela contratante'),
            // Only the durable, saved register can determine the personnel snapshot.
            'staff_mode' => 'register',
            'staff_register' => $staffRegister,
            'staff_summary' => StaffRegister::summarize($staffRegister, $month),
            // Financial decisions are month-specific and must have been saved by an administrator.
            'financial_config' => FinanceSettings::fromSettings($settings),
            'report_title' => self::text($input['report_title'] ?? 'Relatório Mensal Gerencial — SES-DF', 'Título', 180),
            'executive_summary' => self::text($input['executive_summary'] ?? '', 'Resumo executivo', 20000),
            'actions' => self::text($input['actions'] ?? '', 'Ações e justificativas', 20000),
            'notes' => self::text($input['notes'] ?? '', 'Observações', 20000),
            'phone_survey_actions' => self::text($input['phone_survey_actions'] ?? '', 'Providências da pesquisa telefônica', 20000),
            'timezone' => 'America/Sao_Paulo',
            // Only the saved list may determine an extraction. A caller-supplied list is ignored.
            'test_numbers' => self::testNumbers($settings['ses_test_numbers_json'] ?? '[]'),
        ];
        if ($normalized['queue_filter'] === '') {
            throw new \InvalidArgumentException('Informe o filtro da fila do contrato.');
        }
        foreach (['n1_categories', 'n1_group_ids', 'n2_group_ids', 'n3_group_ids'] as $key) {
            if (!$normalized[$key]) {
                throw new \InvalidArgumentException('As listas de categorias N1 e de grupos N1, N2 e N3 não podem ficar vazias.');
            }
        }
        if (array_intersect(array_merge($normalized['n1_group_ids'], $normalized['n2_group_ids']), $normalized['n3_group_ids'])) {
            throw new \InvalidArgumentException('Um mesmo ID de grupo não pode ser configurado como N1/N2 e N3. Confira os grupos no banco usado para este relatório.');
        }
        if (array_intersect($normalized['n1_group_ids'], $normalized['n2_group_ids'])) {
            throw new \InvalidArgumentException('Os grupos N1 e N2 devem ser distintos para identificar o calendário da apuração.');
        }
        if ($normalized['staff_replacements'] !== null && $normalized['staff_total'] !== null
            && $normalized['staff_replacements'] > $normalized['staff_total']) {
            throw new \InvalidArgumentException('O número de substituições não pode superar o total de profissionais informado.');
        }
        if ($normalized['staff_excluded'] !== null && $normalized['staff_replacements'] !== null
            && $normalized['staff_excluded'] > $normalized['staff_replacements']) {
            throw new \InvalidArgumentException('As exclusões do INS10 não podem superar as substituições informadas.');
        }
        return $normalized;
    }

    public static function testNumbers($raw): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > 150000) {
                throw new \InvalidArgumentException('A lista de números de teste é muito extensa.');
            }
            if (substr(ltrim($raw), 0, 1) !== '[') {
                throw new \InvalidArgumentException('A lista de números de teste deve ser um array JSON, inclusive quando vazia ([]).');
            }
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('A lista de números de teste deve ser um JSON válido.');
            }
        }
        if (!is_array($raw) || ($raw && array_keys($raw) !== range(0, count($raw) - 1)) || count($raw) > 500) {
            throw new \InvalidArgumentException('Informe uma lista com no máximo 500 números de teste.');
        }
        $result = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Cada número de teste deve ter número, descrição e situação.');
            }
            $number = self::text($row['number'] ?? '', 'Número de teste', 80);
            if ($number === '' || preg_match('/[^0-9+().\-\s]/', $number)) {
                throw new \InvalidArgumentException('O número de teste deve conter dígitos e somente caracteres usuais de formatação.');
            }
            $number = self::callerDigits($number);
            if ($number === '' || strlen($number) > 32) {
                throw new \InvalidArgumentException('Informe um número de teste com 1 a 32 dígitos.');
            }
            if (isset($seen[$number])) {
                throw new \InvalidArgumentException('Existem números de teste duplicados após remover a formatação.');
            }
            $active = array_key_exists('active', $row) ? $row['active'] : true;
            if (!in_array($active, [true, false, 1, 0, '1', '0'], true)) {
                throw new \InvalidArgumentException('A situação do número de teste deve ser ativa ou inativa.');
            }
            $seen[$number] = true;
            $result[] = ['number' => $number, 'label' => self::text($row['label'] ?? '', 'Descrição do número', 240),
                'active' => in_array($active, [true, 1, '1'], true)];
        }
        return $result;
    }

    public static function callerDigits($value): string
    {
        return preg_replace('/[^0-9]/', '', (string)$value);
    }

    public static function prepareUpdate(array $input): array
    {
        if (array_key_exists('ses_test_numbers_json', $input)) {
            $input['ses_test_numbers_json'] = json_encode(self::testNumbers($input['ses_test_numbers_json']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists(StaffRegister::CONFIG_KEY, $input)) {
            $rows = StaffRegister::rows($input[StaffRegister::CONFIG_KEY]);
            $current = class_exists('Config') ? \Config::getConfigurationValues('plugin:g4freports') : [];
            StaffRegister::validateDuplicates($rows, StaffRegister::fromSettings(is_array($current) ? $current : []));
            $input[StaffRegister::CONFIG_KEY] = StaffRegister::json($rows);
        }
        if (array_key_exists(FinanceSettings::CONFIG_KEY, $input)) {
            $input[FinanceSettings::CONFIG_KEY] = FinanceSettings::json(FinanceSettings::normalize($input[FinanceSettings::CONFIG_KEY]));
        }
        return $input;
    }

    private static function ids($value, string $label): array
    {
        if (is_string($value)) {
            if (strlen($value) > 50000) {
                throw new \InvalidArgumentException($label . ': lista muito extensa.');
            }
            $value = trim($value);
            if ($value === '') {
                return [];
            }
            $value = preg_split('/[\s,;]+/', $value);
        }
        if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1)) || count($value) > 5000) {
            throw new \InvalidArgumentException($label . ': informe uma lista de IDs numéricos.');
        }
        $ids = [];
        foreach ($value as $id) {
            $parsed = self::integer($id, $label, 1, 2147483647);
            $ids[$parsed] = $parsed;
        }
        return array_values($ids);
    }

    private static function integer($value, string $label, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D', (string)$value)
            || (float)$value < $min || (float)$value > $max) {
            throw new \InvalidArgumentException($label . ': informe um número inteiro entre ' . $min . ' e ' . $max . '.');
        }
        return (int)$value;
    }

    private static function optionalInteger($value, string $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::integer($value, $label, 0, 1000000);
    }

    private static function text($value, string $label, int $limit): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException($label . ': valor inválido.');
        }
        $value = trim((string)$value);
        if (strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)
            || !preg_match('//u', $value)) {
            throw new \InvalidArgumentException($label . ': texto inválido ou maior que o limite de ' . $limit . ' bytes.');
        }
        return $value;
    }

    private static function choice($value, array $choices, string $label): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new \InvalidArgumentException($label . ': opção inválida.');
        }
        return $value;
    }
}
