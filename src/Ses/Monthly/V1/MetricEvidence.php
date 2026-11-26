<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** Human-readable evidence from the frozen calculation, never a second query. */
final class MetricEvidence
{
    /** Shared customer-facing names; technical codes remain in the audit. */
    public static function title(string $id, ?int $priority = null): string
    {
        if (in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true)) {
            $severity = $priority === null ? (int)substr($id, 3) : self::contractSeverity($priority);
            return 'Índice de Resolução de Chamados Classificados como Severidade ' . $severity . ' Dentro do Prazo';
        }
        return [
            'INS5' => 'Índice de Chamados Reabertos por Erros ou Execução Incompleta',
            'INS6' => 'Índice de Chamados Solucionados no 1º Nível',
            'INS7' => 'Índice de Tempo Médio de Duração do Atendimento Telefônico',
            'INS8' => 'Índice de Tempo Médio de Espera de Atendimento Telefônico',
            'INS9' => 'Índice de Abandono de Ligações Telefônicas',
            'INS10' => 'Índice de Rotatividade de Profissionais',
            'INS11' => 'Indicador de Vinculação da Resolução do Chamado à Base de Conhecimento',
            'INS12' => 'Índice de Satisfação do Usuário',
        ][$id] ?? $id;
    }

    public static function priorityName($priority): string
    {
        return [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta', 6 => 'Maior'][(int)$priority] ?? 'Não mapeada';
    }

    public static function contractSeverity($priority): ?int
    {
        return [5 => 1, 4 => 2, 3 => 3, 2 => 4][(int)$priority] ?? null;
    }

    public static function labels(string $id): array
    {
        $labels = [
            'INS1' => ['Chamados não excedidos', 'Chamados avaliados'],
            'INS2' => ['Chamados não excedidos', 'Chamados avaliados'],
            'INS3' => ['Chamados não excedidos', 'Chamados avaliados'],
            'INS4' => ['Chamados N1/N2 não excedidos', 'Chamados N1/N2 avaliados'],
            'INS5' => ['Chamados reabertos por erros ou execução incompleta', 'Quantidade total de chamados solucionados'],
            'INS6' => ['Chamados solucionados no 1º nível', 'Quantidade total de chamados elegíveis'],
            'INS7' => ['Ligações atendidas com duração de até 10 minutos', 'Quantidade total de ligações consideradas'],
            'INS8' => ['Conectadas em até 30 s', 'Ligações recebidas consideradas'],
            'INS9' => ['Abandonadas após 30 s', 'Ligações recebidas consideradas'],
            'INS10' => ['Saídas consideradas no INS10', 'Quantidade de profissionais faturados na competência'],
            'INS11' => ['Chamados com resolução vinculada à base de conhecimento', 'Quantidade total de chamados solucionados'],
            'INS12' => ['Pesquisas respondidas como satisfeito ou muito satisfeito (4 ou 5)', 'Quantidade de pesquisas respondidas (notas válidas de 1 a 5)'],
        ];
        $pair = $labels[$id] ?? ['Quantidade apurada', 'Base de cálculo'];
        return ['numerator' => $pair[0], 'denominator' => $pair[1]];
    }

    public static function table(array $snapshot, string $id, string $scope = 'denominator'): array
    {
        $metrics = array_column($snapshot['metrics'] ?? [], null, 'id');
        $metric = $metrics[$id] ?? [];
        if ($id === 'INS10') return self::staff($snapshot);
        if ($id === 'INS12') return self::surveys($snapshot, $metric);
        if (in_array($id, ['INS7', 'INS8', 'INS9'], true)) return self::calls($snapshot, $metric, $id, $scope);
        return self::tickets($snapshot, $metric, $id);
    }

    private static function sets(array $metric): array
    {
        return [array_fill_keys(array_map('strval', $metric['evidence_ids'] ?? []), true),
            array_fill_keys(array_map('strval', $metric['denominator_ids'] ?? []), true)];
    }

    private static function tickets(array $s, array $m, string $id): array
    {
        [$num, $den] = self::sets($m);
        $input = $s['input'];
        $labels = self::labels($id);
        $headers = ['ID do chamado', 'Título', 'Categoria ID', 'Categoria de chamado', 'Grupos IDs', 'Grupos técnicos',
            'Abertura', 'Solução', 'Prioridade GLPI'];
        $extra = [];
        if ($id === 'INS5') $extra = ['Reabertura registrada', 'Execução incompleta informada', 'Solução recusada', 'Reaberturas administrativas desconsideradas', 'Reaberturas consideradas'];
        if ($id === 'INS6') $extra = ['Categoria elegível N1', 'Vínculo atual N1', 'Vínculo atual N2', 'Vínculo atual N3', 'Solucionador ID', 'Solucionador', 'Grupos atuais do solucionador IDs', 'Aceito pelo solucionador N1 sem grupo atribuído'];
        if ($id === 'INS11') $extra = ['Artigos diretamente vinculados IDs', 'Artigos validados encontrados IDs', 'Categoria BC ID', 'Critério de vínculo aplicado'];
        $isSla = in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true);
        if ($isSla) $headers = array_merge($headers, ['SLA ID', 'SLA aplicado', 'SLA atribuído', 'Prazo armazenado no GLPI', 'Conformidade']);
        $headers = array_merge($headers, $isSla ? self::slaHeaders() : [], $extra,
            $isSla ? ['Pertence ao recorte do indicador', 'Incluído no percentual apurado', $labels['numerator'], 'Motivo / classificação']
                : ['Elegível à base de cálculo', $labels['numerator'], 'Motivo / classificação']);
        $headers = array_merge($headers, ['Severidade do contrato', 'Código da prioridade GLPI']);
        $cohort = array_fill_keys(array_map('strval', $m['cohort_ids'] ?? array_merge($m['denominator_ids'] ?? [], $m['unresolved_ids'] ?? [])), true);
        // Keep the actual indicator population, including unfavorable outcomes.
        // Yield rows to avoid duplicating thousands of PHP row/cell arrays.
        $rows = (static function () use ($s, $m, $id, $num, $den, $input, $isSla, $cohort): \Generator {
        foreach ($s['tickets'] ?? [] as $t) {
            $key = (string)$t['id'];
            if ($isSla ? !isset($cohort[$key]) : !isset($den[$key])) continue;
            $eligible = isset($den[$key]);
            $counts = isset($num[$key]);
            $unresolved = ($t['within_sla'] ?? null) === null;
            $sla = (int)($t['sla_id'] ?? 0);
            $values = [$key, $t['title'] ?? '', (int)($t['category_id'] ?? 0), self::category($t),
                implode(', ', $t['group_ids'] ?? []), $t['groups'] ?? '', $t['opened_at'] ?? '', $t['solved_at'] ?? '',
                self::priorityName($t['priority'] ?? 0)];
            $reason = '';
            if ($isSla) {
                $values = array_merge($values, [$sla, $t['sla_name'] ?? '', $sla > 0 ? 'Sim' : 'Não',
                    $t['deadline'] ?? '', self::slaClassification($t)], self::slaValues($t));
                if ((int)$t['priority'] !== (int)$input['priority_map'][$id]) $reason = 'Prioridade fora do recorte deste indicador';
                elseif (in_array($id, ['INS3', 'INS4'], true) && !empty($t['assigned_n3'])) $reason = 'Excluído: vínculo atual a N3';
                elseif ($id === 'INS4' && empty($t['ins4_scope'])) $reason = 'Excluído: sem vínculo atual aos grupos N1 ou N2 selecionados';
                elseif ($unresolved) $reason = $t['sla_reason'] ?? 'Apuração pendente: gere uma nova extração para reconstruir o SLA';
                else $reason = $t['sla_reason'] ?? ($counts ? 'Não excedido conforme a medição preservada' : 'Excedido conforme a medição preservada');
            } elseif ($id === 'INS5') {
                array_push($values, self::yes(!empty($t['reopened'])), self::yes(!empty($t['incomplete_execution'])), self::yes(!empty($t['refused_solution'])),
                    (int)($t['ins5_exempt_event_count'] ?? 0), (int)($t['ins5_counted_reopening_count'] ?? 0));
                $reason = $m['numerator'] === null ? 'Evidência de reabertura indisponível' : ($counts ? 'Reabertura considerada ou execução incompleta; chamado contado uma vez'
                    : (!empty($t['ins5_excluded_administrative']) ? 'Reabertura pelo mesmo solucionador N1/N2/N3 desconsiderada; detalhes na auditoria' : 'Sem reabertura considerada nem execução incompleta informada'));
            } elseif ($id === 'INS6') {
                array_push($values, self::yes(!empty($t['n1_eligible'])), self::yes(!empty($t['assigned_n1'])), self::yes(!empty($t['assigned_n2'])), self::yes(!empty($t['assigned_n3'])),
                    $t['solver_id'] ?? '', $t['solver_name'] ?? '', implode(', ', $t['solver_group_ids'] ?? []), self::yes(!empty($t['ins6_solver_fallback'])));
                if (empty($t['n1_eligible'])) $reason = 'Categoria fora da lista elegível N1';
                elseif (!empty($t['assigned_n3'])) $reason = 'Excluído: vínculo atual a N3';
                else $reason = !empty($t['ins6_solver_fallback']) ? 'Categoria elegível, sem grupo técnico atribuído; solucionador com vínculo atual N1'
                    : ($counts ? 'Categoria elegível e vínculo atual N1' : 'Categoria elegível; não atende aos critérios de solução N1');
            } elseif ($id === 'INS11') {
                $valid = array_values(array_intersect($t['kb_ids'] ?? [], $input['validated_kb_ids'] ?? []));
                $proxy = ($input['kb_mode'] ?? '') === 'category_proxy';
                array_push($values, implode(', ', $t['kb_ids'] ?? []), implode(', ', $valid), $t['kb_category_id'] ?? 0,
                    $proxy ? 'Vínculo da categoria (proxy)' : 'Artigo diretamente vinculado e validado');
                $reason = $m['numerator'] === null ? 'Apuração pendente: vínculo/catálogo/lista validada indisponível' : ($counts ? ($proxy ? 'Categoria com vínculo BC; não comprova artigo' : 'Pelo menos um artigo vinculado consta da lista validada') : ($proxy ? 'Categoria sem vínculo BC' : 'Nenhum artigo diretamente vinculado consta da lista validada'));
            }
            if ($isSla) $values[] = self::yes(isset($cohort[$key]));
            array_push($values, self::yes($eligible), $isSla && $unresolved ? 'Não apurado' : ($m['numerator'] === null ? 'Pendente' : self::yes($counts)), $reason);
            array_push($values, self::contractSeverity($t['priority'] ?? 0), $t['priority'] ?? '');
            yield $values;
        }
        })();
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function calls(array $s, array $m, string $id, string $scope): array
    {
        [$num, $den] = self::sets($m);
        $headers = ['ID da ligação', 'Fila', 'Número de origem', 'Entrada na fila', 'Conexão ao agente', 'Conclusão', 'Abandono',
            'Desfecho', 'Conversa (s)', 'Espera até conexão (s)', 'Espera até abandono (s)', 'Número de teste',
            'Elegível à base de cálculo', self::labels($id)['numerator'], 'Motivo / classificação'];
        $rows = [];
        foreach ($s['calls'] ?? [] as $c) {
            $key = self::callKey($c);
            if (!isset($den[$key])) continue;
            if ($scope === 'numerator' && !isset($num[$key])) continue;
            $test = isset($c['excluded_reason']);
            $yes = isset($num[$key]);
            $reason = $test ? 'Excluída: número de teste salvo — ' . $c['excluded_reason'] : '';
            if (!$test) {
                if ($yes) $reason = $id === 'INS7' ? 'Concluída com conversa até 600 s' : ($id === 'INS8' ? 'Conectada com espera até 30 s' : 'Abandonada sem atendimento após mais de 30 s');
                elseif ($id === 'INS7') $reason = empty($c['completed_at']) ? 'Sem evento de conclusão; permanece na base recebida' : (self::secondsValid($c['talk_seconds'] ?? null) ? 'Conversa acima de 600 s' : 'Duração de conversa ausente/inválida');
                elseif ($id === 'INS8') $reason = empty($c['connected_at']) ? 'Sem conexão ao agente; permanece na base recebida' : (self::secondsValid($c['wait_seconds'] ?? null) ? 'Espera até conexão acima de 30 s' : 'Espera até conexão ausente/inválida');
                else $reason = ($c['type'] ?? '') === 'Atendida' ? 'Atendida; não é abandono' : (($c['type'] ?? '') === 'Abandonada' ? (self::secondsValid($c['abandon_wait_seconds'] ?? null) ? 'Abandono até 30 s; não atende ao limite estrito > 30 s' : 'Abandono sem espera válida') : 'Sem atendimento nem abandono identificado');
            }
            $rows[] = [(string)$c['id'], $c['queue'] ?? '', (string)($c['caller'] ?? ''), $c['entered_at'] ?? '', $c['connected_at'] ?? '', $c['completed_at'] ?? '', $c['abandoned_at'] ?? '',
                $c['type'] ?? '', $c['talk_seconds'] ?? '', $c['wait_seconds'] ?? '', $c['abandon_wait_seconds'] ?? '',
                self::yes($test), self::yes(isset($den[$key])), self::yes($yes), $reason];
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function surveys(array $s, array $m): array
    {
        [$num, $den] = self::sets($m);
        $headers = ['Fonte da pesquisa', 'ID do chamado / ligação', 'Título / fila', 'Categoria ID', 'Categoria de chamado', 'Grupos técnicos',
            'Data do recorte', 'Nota GLPI / atendimento original', 'Nota considerada (1–5)', 'Resposta única da ligação', 'Fonte selecionada',
            'Resposta válida considerada', 'Resposta favorável considerada', 'Motivo / classificação',
            'Telefonia: nota registrada', 'Origem da nota telefônica considerada', 'Motivo da escolha da nota telefônica'];
        $rows = [];
        foreach ($s['tickets'] ?? [] as $t) {
            $key = (string)$t['id'];
            if (!isset($den[$key])) continue;
            $rating = $t['satisfaction'] ?? null;
            $available = !empty($m['source_counts']['tickets']['available']);
            $reason = !$available ? 'Fonte de pesquisas GLPI indisponível' : ($rating === null || $rating === '' ? 'Sem resposta à pesquisa' : (empty($t['satisfaction_valid']) ? 'Nota fora da escala inteira 1–5' : (isset($num[$key]) ? 'Resposta favorável (4 ou 5)' : 'Resposta válida não favorável (1, 2 ou 3)')));
            $rows[] = ['GLPI', $key, $t['title'] ?? '', $t['category_id'] ?? 0, self::category($t), $t['groups'] ?? '', $t['solved_at'] ?? '',
                $rating ?? '', !empty($t['satisfaction_valid']) ? $rating : '', 'Não se aplica', 'Sim', self::yes(isset($den[$key])), self::yes(isset($num[$key])), $reason, '', '', ''];
        }
        $selected = ($s['input']['ins12_mode'] ?? '') === 'glpi_and_phone';
        $available = !empty($m['source_counts']['phone']['available']);
        foreach ($selected ? ($s['calls'] ?? []) : [] as $c) {
            $key = self::callKey($c);
            if (!isset($den[$key])) continue;
            $test = isset($c['excluded_reason']);
            $rating = $c['ins12_score'] ?? PhoneSurveySummary::normalizedRating($c);
            $raw = array_key_exists('satisfaction_raw', $c) ? $c['satisfaction_raw'] : null;
            $hasObserved = PhoneSurveySummary::hasResponse($c);
            if ($test) $reason = 'Excluída: número de teste salvo — ' . $c['excluded_reason'];
            elseif (!$selected) $reason = 'Telefonia desmarcada para o INS12 nesta extração';
            elseif (!$available) $reason = 'Fonte de pesquisas telefônicas indisponível';
            elseif (empty($c['ins12_representative'])) $reason = 'Outra fila da mesma ligação; resposta contada uma única vez';
            elseif ($rating === null) $reason = $hasObserved ? 'Sem nota válida na escala inteira 1–5' : 'Sem resposta à pesquisa';
            else $reason = isset($num[$key]) ? 'Resposta favorável (nota considerada 4 ou 5)' : 'Resposta válida não favorável (nota considerada 1, 2 ou 3)';
            $rows[] = ['Telefonia', (string)$c['id'], $c['queue'] ?? '', '', '', '', $c['entered_at'] ?? '', $raw ?? '', $rating ?? '',
                self::yes(!empty($c['ins12_representative'])), self::yes($selected), self::yes(isset($den[$key])), self::yes(isset($num[$key])), $reason,
                $c['satisfaction_nota_raw'] ?? '', PhoneSurveySummary::sourceLabel($c), $c['satisfaction_reason'] ?? ''];
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function staffRegisterTable(array $s): array
    {
        return self::staff($s, true);
    }

    private static function staff(array $s, bool $all = false): array
    {
        $headers = ['Nome', 'Perfil Profissional', 'Data de admissão', 'Data de saída', 'Valor mensal (R$)', 'Faturado na competência',
            'Ativo ao fim do mês', 'Saída na janela de três meses', 'Saída considerada no INS10', 'Participação no indicador', 'Não Faturar',
            'ID do registro', 'Identificação do vínculo', 'Primeiro dia Faturado', 'Último dia Faturado', 'Dias Faturado', 'Valor Faturado (R$)', 'Status', 'Dias automáticos', 'Dias informados'];
        $rows = [];
        $month = (string)($s['period']['month'] ?? $s['staff_register']['month'] ?? '');
        foreach (StaffRegister::sortRows($s['staff_register']['rows'] ?? []) as $r) {
            $notBilled = StaffRegister::isNotBilled($r);
            $billing = StaffRegister::billingForMonth($r, $month);
            $countedMonth = !$notBilled && (int)($billing['billed_days'] ?? 0) > 0;
            $countedExit = !empty($r['counted_departure'] ?? $r['counted_replacement'] ?? false);
            if (!$all && !$countedMonth && !$countedExit) continue;
            $rows[] = [$r['name'], $r['profile'], $r['admission_date'], $r['departure_date'] ?? '', (float)$r['monthly_value'],
                self::yes($countedMonth), self::yes(!empty($r['active_at_month_end'])), self::yes(!empty($r['departure_in_window'])),
                self::yes(!empty($r['counted_departure'] ?? $r['counted_replacement'] ?? false)), $r['reason'] ?? '', self::yes($notBilled), $r['id'] ?? '', $r['record_reference'] ?? '',
                $billing['billing_first_day'] ?? '', $billing['billing_last_day'] ?? '', $billing['billed_days'],
                $billing['billed_value'] === null ? null : (float)$billing['billed_value'], $billing['status'], $billing['automatic_billed_days'] ?? $billing['billed_days'], $billing['billed_days_override'] ?? ''];
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function category(array $t): string
    {
        if ((int)($t['category_id'] ?? 0) <= 0) return 'Sem categoria de chamado';
        return trim((string)($t['category'] ?? '')) !== '' ? $t['category'] : 'Categoria ID ' . $t['category_id'] . ' — nome indisponível';
    }

    private static function callKey(array $c): string { return (string)($c['call_key'] ?? ($c['id'] . '|' . $c['queue'])); }

    /** Common audit columns keep source values separate from the report's SLA clock. */
    public static function slaHeaders(): array
    {
        return ['Limite de solução (s)', 'Calendário apurado ID', 'Calendário apurado',
            'Tempo no calendário — auditoria (s)', 'Suspensão no calendário — auditoria (s)', 'Tempo de solução considerado no relatório (s)',
            'Limite superior do tempo líquido (s)',
            'Tempo útil armazenado GLPI (s)', 'Espera geral armazenada GLPI (s)', 'Espera SLA armazenada GLPI (s)',
            'Método de apuração SLA', 'Justificativa da apuração SLA', 'Reconstrução por histórico utilizada',
            'Conformidade pelo prazo armazenado', 'Divergência frente ao prazo armazenado', 'Observações de auditoria SLA',
            'Prazo calculado pelo SLA — auditoria', 'Limite do SLA vinculado (s)',
            'Tempo corrido desde a abertura original (s)', 'Tempo corrido menos espera geral (s)',
            'Tempo líquido operacional — auditoria (s)', 'Método operacional — auditoria', 'Conformidade operacional — auditoria'];
    }

    public static function slaValues(array $t): array
    {
        $stored = $t['sla_stored_within'] ?? null;
        return [$t['sla_target_seconds'] ?? null, $t['sla_calendar_id'] ?? null, $t['sla_calendar_name'] ?? '',
            $t['sla_gross_seconds'] ?? null, $t['sla_pending_seconds'] ?? null, $t['sla_net_seconds'] ?? null,
            $t['sla_upper_bound_seconds'] ?? null,
            $t['solve_seconds'] ?? null, $t['waiting_seconds'] ?? null, $t['sla_waiting_seconds'] ?? null,
            self::slaMethod($t), $t['sla_reason'] ?? '',
            isset($t['sla_method']) ? self::yes(in_array($t['sla_method'], ['reconstructed', 'reconstructed_current_calendar'], true)) : 'Não informado',
            $stored === null ? 'Prazo não disponível para comparação' : ($stored ? 'Não excedido' : 'Excedido'),
            $stored === null || ($t['within_sla'] ?? null) === null ? 'Comparação indisponível' : self::yes(!empty($t['sla_difference'])),
            $t['sla_flags_text'] ?? implode('; ', $t['sla_flags'] ?? []), $t['sla_calculated_deadline'] ?? '',
            $t['sla_source_target_seconds'] ?? null, $t['sla_elapsed_seconds'] ?? null, $t['sla_elapsed_less_waiting_seconds'] ?? null,
            $t['sla_operational_net_seconds'] ?? null, $t['sla_operational_method'] ?? '',
            !isset($t['sla_operational_within']) ? 'Não apurada' : ($t['sla_operational_within'] ? 'Não excedido' : 'Excedido')];
    }

    private static function slaMethod(array $t): string
    {
        $method = $t['sla_method'] ?? '';
        return ['report_stored_duration' => 'Tempo de solução positivo armazenado no GLPI',
            'report_elapsed_less_waiting' => 'Tempo corrido da abertura original à solução menos espera geral',
            'report_elapsed' => 'Tempo corrido da abertura original à solução',
            'recorded_deadline' => 'Prazo registrado do SLA',
            'recalculated_sla_deadline' => 'Prazo calculado pelo SLA e espera registrada',
            'validated_operational_duration' => 'Tempo útil validado pelos registros do GLPI',
            'reconstructed' => 'Reconstrução por calendário e histórico',
            'reconstructed_current_calendar' => 'Reconstrução com calendário atual identificado',
            'stored_validated' => 'Contadores armazenados com validação',
            'within_by_upper_bound' => 'Cumprimento comprovado pelo limite superior',
            'unresolved' => 'Apuração pendente de evidência'][$method] ?? ($method ?: 'Medição anterior: gere nova extração');
    }

    public static function slaClassification(array $t): string
    {
        $within = $t['within_sla'] ?? null;
        return $within === null ? 'Apuração pendente' : ($within ? 'Não excedido' : 'Excedido');
    }

    public static function ins5AuditTable(array $snapshot): array
    {
        $rows = [];
        foreach ($snapshot['ins5_administrative_events'] ?? [] as $event) {
            $rows[] = [$event['ticket_id'] ?? '', $event['log_id'] ?? '', $event['occurred_at'] ?? '',
                $event['reopener_id'] ?? '', $event['reopener_name'] ?? $event['reopener_raw'] ?? '',
                $event['solution_id'] ?? '', $event['solution_at'] ?? '', $event['solver_id'] ?? '', $event['solver_name'] ?? '',
                implode(', ', $event['solver_group_ids'] ?? []), 'Vínculos de equipe existentes na extração',
                self::yes(!empty($event['ticket_still_counted'])), $event['reason'] ?? 'Mesmo solucionador integrante N1/N2/N3'];
        }
        return ['title' => 'INS5 — Reaberturas administrativas desconsideradas',
            'headers' => ['Chamado ID', 'Evento ID', 'Data da reabertura', 'Autor ID', 'Autor da reabertura',
                'Solução anterior ID', 'Data da solução anterior', 'Solucionador ID', 'Solucionador anterior',
                'Grupos do solucionador IDs', 'Referência dos vínculos', 'Chamado ainda conta no INS5', 'Motivo'],
            'rows' => $rows,
            'note' => 'Cada linha é uma reabertura pelo mesmo técnico que solucionou o chamado, com vínculo N1/N2/N3 na extração. '
                . 'A exclusão é do evento: outra reabertura válida ou uma execução incompleta informada mantém o chamado no indicador. '
                . 'Vínculos atuais não comprovam automaticamente a associação histórica do profissional.'];
    }

    /** Explain the frozen INS5 exceptions without changing either calculation set. */
    public static function ins5ExceptionTables(array $snapshot): array
    {
        $tickets = array_column($snapshot['tickets'] ?? [], null, 'id');
        $metrics = array_column($snapshot['metrics'] ?? [], null, 'id');
        $metric = $metrics['INS5'] ?? [];
        [$num, $den] = self::sets($metric);
        $hasNum = array_key_exists('evidence_ids', $metric);
        $hasDen = array_key_exists('denominator_ids', $metric);
        $affected = [];
        $events = [];
        foreach ($snapshot['ins5_administrative_events'] ?? [] as $event) {
            $id = (string)($event['ticket_id'] ?? '');
            $ticket = $tickets[$id] ?? [];
            $counted = $hasNum ? isset($num[$id]) : !empty($event['ticket_still_counted']);
            $inBase = $hasDen ? isset($den[$id]) : isset($tickets[$id]);
            $impact = trim((string)($event['ticket_result_reason'] ?? ''));
            if ($impact === '') {
                $impact = !empty($ticket['incomplete_execution']) ? 'Execução incompleta informada manualmente.'
                    : ($counted ? 'Há outra reabertura não dispensada.' : 'Todas as reaberturas registradas atendem à dispensa administrativa.');
            }
            if (!isset($affected[$id])) {
                $affected[$id] = ['id' => $id, 'title' => $ticket['title'] ?? $event['ticket_title'] ?? '',
                    'category' => $ticket['category'] ?? '', 'solved_at' => $ticket['solved_at'] ?? '',
                    'events' => 0, 'counted' => $counted, 'in_base' => $inBase, 'reason' => $impact];
            }
            $affected[$id]['events']++;
            $reopener = trim((string)($event['reopener_name'] ?? '')) ?: ($event['reopener_raw'] ?? '');
            $events[] = [$id, $affected[$id]['title'], $event['log_id'] ?? '', $event['occurred_at'] ?? '',
                $event['reopener_id'] ?? '', $reopener, $event['solution_id'] ?? '', $event['solution_at'] ?? '',
                $event['solver_id'] ?? '', $event['solver_name'] ?? '', implode(', ', $event['solver_group_ids'] ?? []),
                $event['reason'] ?? 'Mesmo solucionador integrante N1/N2/N3', self::yes($counted), self::yes($inBase), $impact];
        }
        uksort($affected, 'strnatcmp');
        $rows = [];
        $stillCounted = $inBase = 0;
        foreach ($affected as $ticket) {
            $rows[] = [$ticket['id'], $ticket['title'], $ticket['category'], $ticket['solved_at'], $ticket['events'],
                self::yes($ticket['counted']), self::yes($ticket['in_base']), $ticket['reason']];
            if ($ticket['counted']) $stillCounted++;
            if ($ticket['in_base']) $inBase++;
        }
        return [
            'summary' => ['events' => count($events), 'tickets' => count($rows),
                'removed_from_reopened' => count($rows) - $stillCounted, 'still_counted' => $stillCounted, 'in_base' => $inBase],
            'tickets' => ['title' => 'Chamados com reabertura administrativa desconsiderada',
                'note' => 'Uma linha por chamado. A dispensa administrativa retira eventos da contagem de reaberturas; '
                    . 'o chamado permanece na base de solucionados. Outra reabertura válida ou execução incompleta informada mantém o chamado entre os reabertos.',
                'headers' => ['Chamado ID', 'Título do chamado', 'Categoria', 'Data da solução',
                    'Eventos desconsiderados', 'Conta entre os chamados reabertos', 'Permanece na base de solucionados', 'Motivo do resultado do chamado'],
                'rows' => $rows, 'total_label' => 'Total geral (chamados distintos)'],
            'events' => ['title' => 'Eventos de reabertura desconsiderados — autoria e solução anterior',
                'note' => 'Uma linha por evento administrativo registrado. O mesmo chamado pode ter vários eventos. '
                    . 'IDs, datas e autores referem-se ao ciclo anterior à reabertura; os vínculos N1/N2/N3 são os existentes na extração.',
                'headers' => ['Chamado ID', 'Título do chamado', 'Evento ID', 'Data da reabertura', 'Autor ID', 'Autor da reabertura',
                    'Solução anterior ID', 'Data da solução anterior', 'Solucionador ID', 'Solucionador anterior',
                    'Grupos do solucionador IDs', 'Motivo da dispensa do evento', 'Conta entre os chamados reabertos',
                    'Permanece na base de solucionados', 'Motivo do resultado do chamado'],
                'rows' => $events, 'total_label' => 'Total geral (eventos desconsiderados)'],
        ];
    }

    public static function ins6AuditTable(array $snapshot): array
    {
        $rows = [];
        foreach ($snapshot['ins6_solver_inclusions'] ?? [] as $ticket) {
            $rows[] = [$ticket['ticket_id'] ?? $ticket['id'] ?? '', $ticket['category_id'] ?? '', $ticket['category'] ?? '',
                $ticket['solution_id'] ?? '', $ticket['solution_at'] ?? '', $ticket['solver_id'] ?? '', $ticket['solver_name'] ?? '',
                implode(', ', $ticket['solver_group_ids'] ?? []), 'Sem grupo técnico atribuído',
                'Categoria elegível e solucionador com vínculo atual N1'];
        }
        return ['title' => 'INS6 — Chamados aceitos pelo solucionador N1 sem grupo atribuído',
            'headers' => ['Chamado ID', 'Categoria ID', 'Categoria de chamado', 'Solução ID', 'Data da solução',
                'Solucionador ID', 'Solucionador', 'Grupos do solucionador IDs', 'Atribuição do chamado', 'Motivo'],
            'rows' => $rows,
            'note' => 'Estes chamados compõem os solucionados no 1º nível. A regra complementar exige categoria elegível e ausência de grupo técnico atribuído; '
                . 'utiliza o autor da solução e seus vínculos N1 existentes na extração. Cada chamado conta uma única vez.'];
    }

    /** Compact calendar references from the frozen extraction, never another query. */
    public static function calendarReferenceRows(array $s): array
    {
        $rows = [];
        foreach (['N1', 'N2', 'N3'] as $level) {
            $id = (int)($s['input']['sla_' . strtolower($level) . '_calendar_id'] ?? 0);
            $rows[] = ['Seleção de calendário ' . $level, $id > 0 ? 'ID ' . $id . ' informado para esta extração'
                : 'Automática, conforme vínculos e evidências disponíveis do chamado'];
        }
        $days = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        $calendars = $s['sla_reference']['calendars'] ?? [];
        ksort($calendars, SORT_NUMERIC);
        foreach ($calendars as $id => $calendar) {
            $segments = [];
            foreach ($calendar['segments'] ?? [] as $segment) {
                $key = substr((string)($segment['begin'] ?? ''), 0, 5) . '–' . substr((string)($segment['end'] ?? ''), 0, 5);
                $segments[$key][] = $days[(int)($segment['day'] ?? -1)] ?? '?';
            }
            $hours = [];
            foreach ($segments as $range => $weekdays) $hours[] = implode(', ', array_unique($weekdays)) . ' ' . $range;
            $rows[] = ['Calendário utilizado · ID ' . $id,
                (string)($calendar['name'] ?? '') . '. Segmentos: ' . ($hours ? implode('; ', $hours) : 'nenhum informado')
                . '. Feriados/exceções vinculados no catálogo: ' . count($calendar['holidays'] ?? []) . '.'];
        }
        return $rows;
    }

    private static function yes(bool $value): string { return $value ? 'Sim' : 'Não'; }
    private static function secondsValid($value): bool { return is_numeric($value) && is_finite((float)$value) && (float)$value >= 0; }
}
