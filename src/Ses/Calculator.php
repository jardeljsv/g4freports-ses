<?php

namespace GlpiPlugin\G4freports\Ses;

/** One immutable calculation result is consumed by the screen, Word and Excel. */
class Calculator
{
    public static function build(array $input, array $data): array
    {
        if (($input['ins6_mode'] ?? '') !== 'assigned_n1') {
            throw new \InvalidArgumentException('O critério INS6 foi atualizado. Recarregue a página e gere novamente a extração.');
        }
        if (!in_array($input['ins12_mode'] ?? '', ['glpi_and_phone', 'glpi_only'], true)) {
            throw new \InvalidArgumentException('Selecione as fontes do INS12 e gere novamente a extração.');
        }
        if (empty($input['n3_group_ids']) || !is_array($input['n3_group_ids'])
            || array_intersect($input['n1_group_ids'], $input['n3_group_ids'])) {
            throw new \InvalidArgumentException('Informe os grupos N3, distintos dos grupos N1, e gere uma nova extração mensal.');
        }
        $warnings = $data['warnings'] ?? [];
        $capabilities = $data['capabilities'] ?? [];
        $selectionCatalog = $data['selection_catalog'] ?? [];
        $tickets = $data['tickets'] ?? [];
        $phoneResolution = PhoneSurveyScore::resolve($data['calls'] ?? []);
        $sourceCalls = $phoneResolution['calls'];
        unset($phoneResolution['calls']);
        $slaContext = $data['sla_context'] ?? [];
        $slaEvaluator = new SlaEvaluator($slaContext, $input);
        $slaEvaluated = $slaUnresolved = $slaRecovered = $slaDifferences = $slaProvisional = 0;
        $slaMethods = [];
        $ticketIds = [];
        $priorityLabels = [2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta'];
        $cohorts = [];
        foreach ($input['priority_map'] as $metricId => $priority) {
            $cohorts[$metricId] = ['priority' => $priority, 'all' => [], 'denominator' => [], 'numerator' => [], 'missing' => 0, 'missing_ids' => [], 'unresolved_ids' => [], 'recovered_ids' => [], 'provisional_ids' => [], 'excluded' => [], 'outside_groups' => []];
        }
        $n1Ids = array_fill_keys($input['n1_categories'], true);
        $validatedIds = array_fill_keys($input['validated_kb_ids'], true);
        $manualIncomplete = array_fill_keys($input['incomplete_ticket_ids'], true);
        $eligible = $resolvedN1 = $reopened = $kbLinked = $kbValidated = $kbProxy = $rated = $satisfied = [];
        $assignedN3 = $ins6Excluded = [];
        $administrativeEvents = $ins6SolverInclusions = $administrativeExcludedIds = [];
        $missing = $requests = $incidents = $unknownPriorities = $invalidRatings = 0;
        foreach ($tickets as &$ticket) {
            $id = (int)$ticket['id'];
            if (isset($ticketIds[$id])) {
                throw new \RuntimeException('A extração retornou o chamado ' . $id . ' duplicado. O relatório não foi gerado.');
            }
            $ticketIds[$id] = $id;
            $groups = array_map('intval', $ticket['group_ids'] ?? []);
            $ticket['n3_group_ids'] = array_values(array_unique(array_intersect($groups, $input['n3_group_ids'])));
            $ticket['assigned_n3'] = count($ticket['n3_group_ids']) > 0;
            $ticket['assigned_n1'] = (bool)array_intersect($groups, $input['n1_group_ids']);
            $ticket['assigned_n2'] = (bool)array_intersect($groups, $input['n2_group_ids']);
            $ticket['ins4_scope'] = ($ticket['assigned_n1'] || $ticket['assigned_n2']) && !$ticket['assigned_n3'];
            $ticket['ins4_exclusion_reason'] = $ticket['assigned_n3'] ? 'Vínculo atual a N3' : ($ticket['ins4_scope'] ? '' : 'Sem vínculo atual aos grupos N1 ou N2 selecionados');
            if ($ticket['assigned_n3']) {
                $assignedN3[] = $id;
            }
            if ((int)$ticket['type'] === 2) {
                $requests++;
            } elseif ((int)$ticket['type'] === 1) {
                $incidents++;
            }
            $deadline = self::date($ticket['deadline'] ?? null);
            $solved = self::date($ticket['solved_at'] ?? null);
            if ($solved === null) {
                throw new \RuntimeException('O chamado ' . $id . ' não tem data de solução válida na extração mensal.');
            }
            // Measure the applicable ticket clock; history/clone flags do not alter eligibility.
            $ticket = array_replace($ticket, $slaEvaluator->evaluate($ticket));
            $evaluated = $ticket['within_sla'] !== null;
            if ($evaluated) $slaEvaluated++; else $slaUnresolved++;
            if ($deadline === null && $evaluated) $slaRecovered++;
            if (!empty($ticket['sla_difference'])) $slaDifferences++;
            if ($evaluated && !empty($ticket['sla_provisional'])) $slaProvisional++;
            $method = (string)($ticket['sla_method'] ?? 'unresolved');
            $slaMethods[$method] = ($slaMethods[$method] ?? 0) + 1;
            if ($deadline === null) {
                $missing++;
            }
            $mapped = false;
            foreach ($cohorts as $metricId => &$cohort) {
                if ((int)$ticket['priority'] !== $cohort['priority']) {
                    continue;
                }
                $mapped = true;
                if ($ticket['assigned_n3'] && in_array($metricId, ['INS3', 'INS4'], true)) {
                    $cohort['excluded'][] = $id;
                    continue;
                }
                if ($metricId === 'INS4' && !$ticket['ins4_scope']) {
                    $cohort['outside_groups'][] = $id;
                    continue;
                }
                $cohort['all'][] = $id;
                if ($deadline === null) {
                    $cohort['missing']++;
                    $cohort['missing_ids'][] = $id;
                    if ($evaluated) $cohort['recovered_ids'][] = $id;
                }
                if (!$evaluated) {
                    $cohort['unresolved_ids'][] = $id;
                } else {
                    $cohort['denominator'][] = $id;
                    if (!empty($ticket['sla_provisional'])) $cohort['provisional_ids'][] = $id;
                    if ($ticket['within_sla']) {
                        $cohort['numerator'][] = $id;
                    }
                }
            }
            unset($cohort);
            if (!$mapped) {
                $unknownPriorities++;
            }
            $ticket['n1_eligible'] = isset($n1Ids[(int)$ticket['category_id']]);
            $ticket['ins6_eligible'] = $ticket['n1_eligible'] && !$ticket['assigned_n3'];
            $ticket = ActorEvidence::classify($ticket, $input, isset($manualIncomplete[$id]));
            if ($ticket['ins5']) $reopened[] = $id;
            if ($ticket['ins5_excluded_administrative']) $administrativeExcludedIds[] = $id;
            foreach ($ticket['ins5_reopening_events'] as $event) {
                if (empty($event['administrative_exempt'])) continue;
                $administrativeEvents[] = array_merge($event, [
                    'ticket_id' => $id, 'ticket_title' => $ticket['title'] ?? '',
                    'ticket_still_counted' => $ticket['ins5'],
                    'ticket_result_reason' => $ticket['incomplete_execution'] ? 'Execução incompleta informada manualmente.'
                        : ($ticket['ins5'] ? 'Há outra reabertura não dispensada.' : 'Todas as reaberturas registradas atendem à dispensa administrativa.'),
                ]);
            }
            if ($ticket['ins6_solver_fallback']) {
                $ins6SolverInclusions[] = [
                    'ticket_id' => $id, 'ticket_title' => $ticket['title'] ?? '',
                    'category_id' => $ticket['category_id'], 'category' => $ticket['category'] ?? '',
                    'solver_id' => $ticket['solver_id'], 'solver_name' => $ticket['solver_name'],
                    'solution_id' => $ticket['solution_id'], 'solution_at' => $ticket['solution_at'],
                    'solve_log_id' => $ticket['solve_log_id'], 'solver_group_ids' => $ticket['solver_group_ids'],
                    'membership_basis' => ActorEvidence::MEMBERSHIP_BASIS,
                    'reason' => 'Categoria elegível N1, sem grupo técnico atribuído; solucionador identificado com filiação atual a N1.',
                ];
            }
            if ($ticket['n1_eligible'] && $ticket['assigned_n3']) {
                $ins6Excluded[] = $id;
            }
            if ($ticket['ins6_eligible']) {
                $eligible[] = $id;
            }
            if ($ticket['ins6']) {
                $resolvedN1[] = $id;
            }
            $links = array_map('intval', $ticket['kb_ids'] ?? []);
            $ticket['kb_validated'] = false;
            if ($links) {
                $kbLinked[] = $id;
                foreach ($links as $kbId) {
                    if (isset($validatedIds[$kbId])) {
                        $ticket['kb_validated'] = true;
                        break;
                    }
                }
            }
            if ($ticket['kb_validated']) {
                $kbValidated[] = $id;
            }
            if ((int)($ticket['kb_category_id'] ?? 0) > 0) {
                $kbProxy[] = $id;
            }
            $rating = $ticket['satisfaction'] ?? null;
            $ticket['satisfaction_valid'] = false;
            if ($rating !== null && $rating !== '') {
                if (is_numeric($rating) && in_array((float)$rating, [1.0, 2.0, 3.0, 4.0, 5.0], true)) {
                    $rated[] = $id;
                    $ticket['satisfaction_valid'] = true;
                    if ((float)$rating >= 4) {
                        $satisfied[] = $id;
                    }
                } else {
                    $invalidRatings++;
                }
            }
        }
        unset($ticket);
        foreach ($input['incomplete_ticket_ids'] as $id) {
            if (!isset($ticketIds[$id])) {
                throw new \InvalidArgumentException('O chamado ' . $id . ' informado como execução incompleta não pertence ao período e filtros selecionados.');
            }
        }
        $allTickets = array_values($ticketIds);
        $slaScopeIds = $slaScopeEvaluatedIds = $slaScopeUnresolvedIds = [];
        foreach ($cohorts as $cohort) {
            foreach ($cohort['all'] as $id) $slaScopeIds[$id] = true;
            foreach ($cohort['denominator'] as $id) $slaScopeEvaluatedIds[$id] = true;
            foreach ($cohort['unresolved_ids'] as $id) $slaScopeUnresolvedIds[$id] = true;
        }
        $metrics = [];
        $metricExclusions = [];
        $n3ExclusionNote = 'Chamados com qualquer vínculo técnico atual a grupo N3 são excluídos da quantidade apurada e da base de cálculo, inclusive quando também vinculados a N1. A exclusão se aplica somente ao INS3, INS4 e INS6; os chamados permanecem na base detalhada e nos demais indicadores.';
        foreach ($cohorts as $metricId => $cohort) {
            $excludesN3 = in_array($metricId, ['INS3', 'INS4'], true);
            $note = 'Prioridade GLPI ' . $cohort['priority'] . ' (' . $priorityLabels[$cohort['priority']] . '). '
                . count($cohort['all']) . ' chamados no recorte' . ($excludesN3 ? ' após excluir N3' : '')
                . '; ' . count($cohort['denominator']) . ' avaliados. '
                . ($cohort['missing'] > 0 ? $cohort['missing'] . ' não têm prazo gravado na origem; ' . count($cohort['recovered_ids']) . ' destes foram calculados pela regra de SLA aplicável. ' : '')
                . 'Clonagem e alterações de grupo, categoria ou SLA não excluem chamados do cálculo.';
            if ($cohort['unresolved_ids']) {
                $note .= ' ' . count($cohort['unresolved_ids']) . ' chamados não puderam ser calculados por falta dos dados indicados na evidência individual. '
                    . 'O percentual representa somente os avaliados; a cobertura informa a parcela calculada do recorte.';
            }
            if ($excludesN3) {
                $note .= ' ' . count($cohort['excluded']) . ' chamados excluídos por vínculo N3. ' . $n3ExclusionNote;
            }
            if ($metricId === 'INS4') {
                $note .= ' INS4 abrange chamados com vínculo técnico atual a N1 ou N2, sem restrição às categorias elegíveis do INS6. '
                    . count($cohort['outside_groups']) . ' chamados desta prioridade não possuem vínculo a N1/N2 e ficam fora da base de cálculo. O filtro geral de grupos, quando informado, continua limitando a extração.';
            }
            $status = null;
            if (count($cohort['unresolved_ids']) > 0) {
                $status = count($cohort['denominator']) === 0 ? 'pending' : 'provisional';
            }
            $metric = self::metric($metricId, 'Resolução no prazo — ' . $priorityLabels[$cohort['priority']],
                $cohort['numerator'], $cohort['denominator'], 90, 'gte',
                'Chamados não excedidos ÷ chamados avaliados desta prioridade × 100. '
                    . 'Metas: baixa 8h, média 6h, alta 4h, muito alta 2h. Usa a duração de solução gravada quando positiva; '
                    . 'senão, usa abertura até solução menos a espera geral quando o resultado é positivo; caso contrário, usa abertura até solução. '
                    . 'A abertura original preservada em clones permanece o início. Prazos e calendários operacionais são evidências separadas na auditoria.'
                    . ($excludesN3 ? ' Ambas as parcelas excluem chamados com vínculo técnico atual a grupo N3.' : '')
                    . ($metricId === 'INS4' ? ' Ambas as parcelas incluem grupos N1 e N2 selecionados; não exigem categoria N1.' : ''),
                $note, $status);
            $metric['cohort_ids'] = $cohort['all'];
            $metric['missing_deadline_ids'] = $cohort['missing_ids'];
            $metric['missing_deadline_count'] = $cohort['missing'];
            $metric['unresolved_ids'] = $cohort['unresolved_ids'];
            $metric['unresolved_count'] = count($cohort['unresolved_ids']);
            $metric['recovered_deadline_ids'] = $cohort['recovered_ids'];
            $metric['recovered_deadline_count'] = count($cohort['recovered_ids']);
            $metric['cohort_count'] = count($cohort['all']);
            $metric['evaluated_count'] = count($cohort['denominator']);
            $metric['evidence_complete'] = !$cohort['unresolved_ids'];
            $metric['coverage_percent'] = $cohort['all'] ? 100 * count($cohort['denominator']) / count($cohort['all']) : null;
            $metric['provisional_evidence_ids'] = $cohort['provisional_ids'];
            $metric['provisional_evidence_count'] = count($cohort['provisional_ids']);
            $metric['outside_scope_ids'] = $cohort['outside_groups'];
            if ($excludesN3) {
                self::addN3Exclusions($metric, $metricExclusions, $cohort['excluded'], $n3ExclusionNote);
            }
            $metrics[] = $metric;
        }
        $reopeningAvailable = $capabilities['reopening'] ?? true;
        $metric = self::metric('INS5', 'Chamados reabertos por erros ou execução incompleta', $reopeningAvailable ? $reopened : null, $allTickets, 2, 'lte',
            'Chamados com pelo menos uma reabertura não dispensada ou execução incompleta informada manualmente ÷ chamados solucionados × 100; cada chamado conta uma vez.',
            count($input['incomplete_ticket_ids']) . ' IDs de execução incompleta informados. Reabertura é a transição de solucionado/fechado para estado ativo. '
                . 'Dispensa-se o evento realizado pelo mesmo técnico que solucionou o ciclo anterior, com filiação atual a grupo N1/N2/N3 selecionado. '
                . count($administrativeEvents) . ' eventos administrativos dispensados; ' . count($administrativeExcludedIds) . ' chamados retirados da quantidade de reabertos; a base de cálculo permanece completa. '
                . 'Outra reabertura não dispensada ou execução incompleta mantém o chamado no cálculo. Recusa de solução não reintroduz automaticamente um evento dispensado. '
                . ActorEvidence::BASIS_NOTE
                . (!$reopeningAvailable ? ' A evidência de reabertura não está disponível.' : ''),
            $reopeningAvailable ? null : 'pending');
        $metric['administrative_event_count'] = count($administrativeEvents);
        $metric['administrative_excluded_ids'] = $administrativeExcludedIds;
        $metrics[] = $metric;
        $ins6Method = 'Chamados de categorias elegíveis solucionados no 1º nível ÷ chamados solucionados de categorias elegíveis × 100. '
            . 'Contam os atribuídos a N1 e, quando não há grupo técnico atribuído, os cujo solucionador identificado pertence atualmente a N1. Ambas as parcelas excluem vínculo técnico atual a N3.';
        $metric = self::metric('INS6', 'Chamados solucionados no 1º nível', $resolvedN1, $eligible, 80, 'gte', $ins6Method,
            'Categorias elegíveis conforme a seleção, com exclusão de ' . count($ins6Excluded) . ' chamados por vínculo N3. '
                . count($ins6SolverInclusions) . ' chamados sem grupo foram incluídos pelo solucionador N1 identificado. A presença de outro grupo atribuído impede esse complemento; '
                . 'os chamados já atribuídos a N1 conservam o critério de grupo. ' . $n3ExclusionNote . ' ' . ActorEvidence::BASIS_NOTE);
        $metric['solver_inclusion_ids'] = array_column($ins6SolverInclusions, 'ticket_id');
        self::addN3Exclusions($metric, $metricExclusions, $ins6Excluded, $n3ExclusionNote);
        $metrics[] = $metric;
        if ($eligible && !$resolvedN1) {
            $selectedGroups = [];
            foreach ($input['n1_group_ids'] as $groupId) {
                $name = $selectionCatalog['groups'][$groupId]['name'] ?? '';
                $selectedGroups[] = (string)$groupId . ($name !== '' ? ' — ' . $name : ' (nome não registrado nesta extração)');
            }
            $warnings[] = 'INS6: nenhum dos ' . count($eligible) . ' chamados elegíveis atende ao critério de grupo N1 ou solucionador N1 sem grupo atribuído: '
                . implode('; ', $selectedGroups) . '. O resultado é 0%. Confira se esses grupos correspondem à equipe N1 no banco consultado; '
                . 'o resultado descreve os vínculos encontrados e não comprova ausência de atendimento histórico pela equipe.';
        }

        $testNumbers = [];
        foreach ($input['test_numbers'] as $test) {
            if (!empty($test['active'])) {
                $testNumbers[$test['number']] = $test['label'];
            }
        }
        $calls = $excludedCalls = $allCalls = $ins7 = $ins8 = $ins9 = [];
        $seenCalls = [];
        $answered = $abandoned = $abandonedShort = $unknown = $invalidTalk = $invalidWait = $invalidAbandon = $unfinished = 0;
        $talkSum = $talkCount = $waitSum = $waitCount = 0;
        foreach ($sourceCalls as $call) {
            $callKey = (string)$call['id'] . '|' . (string)$call['queue'];
            if (isset($seenCalls[$callKey])) {
                throw new \RuntimeException('A extração retornou uma ligação/fila duplicada. O relatório não foi gerado.');
            }
            $seenCalls[$callKey] = true;
            $call['call_key'] = $callKey;
            $digits = Settings::callerDigits($call['caller'] ?? '');
            $call['caller_normalized'] = $digits;
            if ($digits !== '' && array_key_exists($digits, $testNumbers)) {
                $call['excluded_reason'] = $testNumbers[$digits] !== '' ? $testNumbers[$digits] : 'Número de teste cadastrado';
                $call['test_number'] = $digits;
                $excludedCalls[] = $call;
                continue;
            }
            $connected = self::date($call['connected_at'] ?? null) !== null;
            $completed = self::date($call['completed_at'] ?? null) !== null;
            $hasAbandon = self::date($call['abandoned_at'] ?? null) !== null;
            $isAnswered = $connected || $completed;
            $isAbandoned = !$isAnswered && $hasAbandon;
            $talk = self::seconds($call['talk_seconds'] ?? null);
            $wait = self::seconds($call['wait_seconds'] ?? null);
            $abandonWait = self::seconds($call['abandon_wait_seconds'] ?? null);
            $call['type'] = $isAnswered ? 'Atendida' : ($isAbandoned ? 'Abandonada' : 'Sem desfecho');
            $call['ins7'] = $completed && $talk !== null && $talk <= 600;
            $call['ins8'] = $connected && $wait !== null && $wait <= 30;
            $call['ins9'] = $isAbandoned && $abandonWait !== null && $abandonWait > 30;
            $allCalls[] = $callKey;
            if ($isAnswered) {
                $answered++;
                if ($talk !== null) {
                    $talkSum += $talk;
                    $talkCount++;
                }
            }
            if ($isAbandoned) {
                $abandoned++;
                if ($abandonWait !== null && $abandonWait <= 30) {
                    $abandonedShort++;
                }
            }
            if (!$isAnswered && !$isAbandoned) {
                $unknown++;
            }
            if ($connected && !$completed) {
                $unfinished++;
            }
            if ($completed && $talk === null) {
                $invalidTalk++;
            }
            if ($connected && $wait === null) {
                $invalidWait++;
            }
            if ($isAbandoned && $abandonWait === null) {
                $invalidAbandon++;
            }
            if ($connected && $wait !== null) {
                $waitSum += $wait;
                $waitCount++;
            }
            if ($call['ins7']) {
                $ins7[] = $callKey;
            }
            if ($call['ins8']) {
                $ins8[] = $callKey;
            }
            if ($call['ins9']) {
                $ins9[] = $callKey;
            }
            $calls[] = $call;
        }
        $callNote = count($excludedCalls) . ' ligações excluídas por correspondência exata com números de teste ativos. Base de cálculo: todas as ligações recebidas restantes, inclusive abandonadas. Não há exclusão automática por curta duração.';
        $metrics[] = self::metric('INS7', 'Atendimentos telefônicos com duração até 10 minutos', $ins7, $allCalls, 85, 'gte',
            'Ligações com evento COMPLETEAGENT/COMPLETECALLER e duração de conversação válida ≤ 600 segundos ÷ ligações recebidas × 100.',
            $callNote, ($unknown + $unfinished + $invalidTalk > 0 ? 'provisional' : null));
        $metrics[] = self::metric('INS8', 'Atendimentos telefônicos com espera até 30 segundos', $ins8, $allCalls, 85, 'gte',
            'Ligações com CONNECT e espera válida na fila até a conexão ≤ 30 segundos ÷ ligações recebidas × 100.',
            $callNote, ($unknown + $invalidWait > 0 ? 'provisional' : null));
        $metrics[] = self::metric('INS9', 'Abandono de ligação telefônica', $ins9, $allCalls, 5, 'lte',
            'Ligações sem atendimento, com abandono após mais de 30 segundos na fila ÷ ligações recebidas × 100.',
            $callNote . ' Total de abandonos: ' . $abandoned . '; acima de 30 segundos (abandonos considerados no INS9): ' . count($ins9)
                . '; até 30 segundos: ' . $abandonedShort . '; sem espera válida: ' . $invalidAbandon
                . '. O limite é estritamente maior que 30 segundos.', ($unknown + $invalidAbandon > 0 ? 'provisional' : null));

        $staff = $input['staff_summary'];
        $metrics[] = self::countMetric('INS10', 'Rotatividade da equipe', $staff['numerator'], $staff['denominator'], 10, 'lte',
            $staff['method'], $staff['note'], $staff['configured'] ? null : 'pending');
        if ($input['kb_mode'] === 'category_proxy') {
            $metrics[] = self::metric('INS11', 'Vinculação à base de conhecimento', $kbProxy, $allTickets, 80, 'gte',
                'Chamados cuja categoria atual tem categoria de base de conhecimento vinculada ÷ chamados solucionados × 100.',
                'Método selecionado: associação da categoria do chamado à categoria da base de conhecimento, conforme o campo usado no Metabase. '
                    . 'Esse critério mede a associação de categorias; o vínculo direto a artigo validado é apurado na outra opção disponível.');
        } else {
            $kbAvailable = ($capabilities['kb_links'] ?? true) && ($selectionCatalog['articles_available'] ?? true)
                && count($input['validated_kb_ids']) > 0;
            $metrics[] = self::metric('INS11', 'Vinculação à base de conhecimento', $kbAvailable ? $kbValidated : null, $allTickets, 80, 'gte',
                'Chamados com vínculo direto a pelo menos um artigo da lista validada ÷ chamados solucionados × 100.',
                count($kbLinked) . ' chamados com vínculo direto observado; ' . count($input['validated_kb_ids']) . ' IDs de artigos validados informados. '
                    . (!$kbAvailable ? 'Apuração pendente: são necessários acesso aos vínculos diretos e ao catálogo de artigos, além de uma lista de artigos previamente validados.' : 'Cada chamado é contado uma única vez.'),
                $kbAvailable || !count($allTickets) ? null : 'pending');
        }
        $includePhone = $input['ins12_mode'] === 'glpi_and_phone';
        $phoneAvailable = !empty($capabilities['phone_survey']);
        $phoneSurvey = PhoneSurveySummary::build($calls, $phoneAvailable, $input['phone_survey_actions'] ?? '', $input['ins12_mode'], $phoneResolution);
        $phoneRepresentatives = array_fill_keys($phoneSurvey['representative_call_keys'], true);
        $phoneRated = array_fill_keys($phoneSurvey['valid_call_keys'], true);
        $phoneSatisfied = array_fill_keys($phoneSurvey['favorable_call_keys'], true);
        foreach ($calls as &$call) {
            $call['ins12_score'] = PhoneSurveySummary::normalizedRating($call);
            $call['ins12_representative'] = isset($phoneRepresentatives[$call['call_key']]);
            $call['ins12_eligible'] = $includePhone && isset($phoneRated[$call['call_key']]);
            $call['ins12'] = $includePhone && isset($phoneSatisfied[$call['call_key']]);
        }
        unset($call);
        $satisfactionAvailable = $capabilities['satisfaction'] ?? true;
        $ins12Complete = $satisfactionAvailable && (!$includePhone || $phoneAvailable);
        $ins12Numerators = array_merge($satisfactionAvailable ? $satisfied : [], $includePhone ? $phoneSurvey['favorable_call_keys'] : []);
        $ins12Denominators = array_merge($satisfactionAvailable ? $rated : [], $includePhone ? $phoneSurvey['valid_call_keys'] : []);
        // Invalid scores are excluded by the selected rule, not evidence of a partial result.
        $ins12Status = !$ins12Complete ? 'pending' : null;
        $ins12Method = $includePhone
            ? '(Respostas GLPI favoráveis + respostas telefônicas favoráveis) ÷ (respostas GLPI válidas + respostas telefônicas válidas) × 100. Notas inteiras de 1 a 5; favoráveis: 4 e 5. Uma resposta por ligação. ' . PhoneSurveySummary::scoreRuleText()
            : 'Pesquisas GLPI válidas com nota 4 ou 5 ÷ pesquisas GLPI respondidas com nota de 1 a 5 × 100. Telefonia excluída por seleção.';
        $ins12 = self::countMetric('INS12', 'Satisfação do usuário', $ins12Complete ? count($ins12Numerators) : null,
            $ins12Complete ? count($ins12Denominators) : null, 80, 'gte', $ins12Method,
            'A escala GLPI de 1 a 5 é explicitada; não há conversão numérica automática para a escala contratual de 0 a 10. Respostas recebidas após o mês podem integrar uma nova extração. '
                . $invalidRatings . ' respostas GLPI fora da escala foram excluídas. '
                . ($includePhone ? 'Telefonia incluída: uma resposta por identificador de ligação, após excluir números de teste; notas consideradas 4 e 5 são favoráveis. '
                    . $phoneResolution['explanation'] . ' '
                    . (int)($phoneSurvey['invalid_responses'] ?? 0) . ' respostas telefônicas fora da escala observadas. '
                    : 'Telefonia excluída por seleção; o resultado considera somente GLPI. ')
                . (!$ins12Complete ? 'Apuração pendente: uma fonte selecionada está indisponível. As contagens disponíveis por fonte são parciais e não constituem o resultado combinado.' : 'Cada resposta válida tem o mesmo peso na fração; não se calcula média dos percentuais por fonte.'),
            $ins12Status);
        // Ticket IDs are integers; telephone evidence uses callid|queue keys.
        // Only one queue row represents a call, keeping the two sources distinct.
        $ins12['evidence_ids'] = $ins12Numerators;
        $ins12['denominator_ids'] = $ins12Denominators;
        $ins12['evidence_complete'] = $ins12Complete;
        $ins12['source_counts'] = [
            'tickets' => ['available' => $satisfactionAvailable, 'included' => true,
                'numerator' => $satisfactionAvailable ? count($satisfied) : null,
                'denominator' => $satisfactionAvailable ? count($rated) : null,
                'invalid' => $satisfactionAvailable ? $invalidRatings : null],
            'phone' => ['available' => $phoneAvailable, 'included' => $includePhone,
                'numerator' => $phoneSurvey['favorable_responses'], 'denominator' => $phoneSurvey['valid_responses'],
                'invalid' => $phoneSurvey['invalid_responses']],
        ];
        $metrics[] = $ins12;
        if ($includePhone && !$phoneAvailable) {
            $warnings[] = 'INS12: telefonia selecionada, mas a fonte de pesquisas não está disponível. O resultado fica pendente; selecione somente GLPI se desejar apurar apenas essa fonte.';
        }
        if ($includePhone && (int)($phoneSurvey['invalid_responses'] ?? 0) > 0) {
            $warnings[] = $phoneSurvey['invalid_responses'] . ' pesquisas telefônicas fora da escala inteira 1–5 foram excluídas do INS12; consulte os valores originais na base de ligações.';
        }

        if ($missing) {
            $warnings[] = $missing . ' chamados sem prazo gravado na origem; ' . $slaRecovered . ' foram avaliados com as evidências de tempo. '
                . 'O campo vazio é uma característica da origem, não ausência de meta de atendimento.';
        }
        if ($slaScopeUnresolvedIds) {
            $warnings[] = count($slaScopeUnresolvedIds) . ' chamados dos recortes INS1–INS4 têm dados de SLA insuficientes para o cálculo. '
                . 'Consulte o motivo individual e a cobertura do indicador. Registros fora desses recortes não alteram sua situação.';
        }
        if ($slaDifferences) {
            $warnings[] = $slaDifferences . ' chamados têm classificação calculada diferente da comparação simples com o prazo gravado. Consulte os calendários, suspensões e métodos nas evidências.';
        }
        if ($unknownPriorities) {
            $warnings[] = $unknownPriorities . ' chamados têm prioridades fora do mapeamento INS1–INS4; permanecem no total e no detalhamento.';
        }
        if ($unknown) {
            $warnings[] = $unknown . ' ligações não têm atendimento nem abandono identificado; os indicadores de telefonia são provisórios.';
        }
        if ($unfinished + $invalidTalk + $invalidWait + $invalidAbandon > 0) {
            $warnings[] = 'Telefonia: ' . $unfinished . ' ligações conectadas sem evento de conclusão; ' . $invalidTalk . ' conclusões sem duração válida; '
                . $invalidWait . ' conexões sem espera válida; ' . $invalidAbandon . ' abandonos sem espera válida. Valores ausentes ou negativos não contam como atendimento no limite.';
        }
        if ($invalidRatings) {
            $warnings[] = $invalidRatings . ' avaliações fora da escala GLPI 1–5 foram excluídas da base de respostas válidas do INS12.';
        }
        if (!empty($input['group_ids'])) {
            $warnings[] = 'O filtro geral de grupos limita todos os chamados antes de calcular os indicadores. Para abranger integralmente N1 e N2 no INS4, deixe esse filtro vazio ou inclua os grupos de ambos os níveis.';
        }
        foreach ($metrics as &$metric) {
            $metric['label'] = MetricEvidence::title($metric['id'], $input['priority_map'][$metric['id']] ?? null);
            $labels = MetricEvidence::labels($metric['id']);
            $metric['numerator_label'] = $labels['numerator'];
            $metric['denominator_label'] = $labels['denominator'];
        }
        unset($metric);
        $mappingNotes = [];
        foreach ($input['priority_map'] as $metricId => $priority) {
            $mappingNotes[] = $metricId . ' = prioridade ' . $priority . ' (' . $priorityLabels[$priority] . ')';
        }
        $usedCalendars = [];
        foreach ($tickets as $ticket) {
            $calendarId = $ticket['sla_calendar_id'] ?? null;
            if ($calendarId !== null && isset($slaContext['calendars'][$calendarId])) {
                $usedCalendars[$calendarId] = $slaContext['calendars'][$calendarId];
            }
        }
        $months = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $snapshot = [
            'schema_version' => 2,
            'rules_version' => Settings::RULES_VERSION,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone($input['timezone'])))->format(DATE_ATOM),
            'input' => $input,
            'selection_catalog' => $selectionCatalog,
            'period' => ['month' => $input['month'], 'start' => $input['start'], 'end_exclusive' => $input['end_exclusive'],
                'label' => ucfirst($months[(int)substr($input['month'], 5, 2)]) . ' de ' . substr($input['month'], 0, 4),
                'date_basis' => 'solvedate', 'call_date_basis' => 'queue_entry'],
            'totals' => ['tickets' => count($tickets), 'requests' => $requests, 'incidents' => $incidents,
                'tickets_assigned_n3' => count($assignedN3),
                'calls_received' => count($calls), 'calls_answered' => $answered, 'calls_abandoned' => $abandoned,
                'calls_abandoned_over_30' => count($ins9), 'calls_abandoned_up_to_30' => $abandonedShort,
                'calls_abandoned_unknown_wait' => $invalidAbandon,
                'calls_excluded' => count($excludedCalls), 'calls_unknown' => $unknown, 'missing_deadline' => $missing,
                'sla_evaluated' => $slaEvaluated, 'sla_unresolved' => $slaUnresolved,
                'sla_scope_count' => count($slaScopeIds), 'sla_scope_evaluated' => count($slaScopeEvaluatedIds),
                'sla_scope_unresolved' => count($slaScopeUnresolvedIds),
                'sla_recovered' => $slaRecovered, 'sla_differences' => $slaDifferences,
                'sla_provisional' => $slaProvisional,
                'mean_talk_seconds' => $talkCount ? $talkSum / $talkCount : null,
                'mean_wait_seconds' => $waitCount ? $waitSum / $waitCount : null,
                'mean_talk_count' => $talkCount, 'mean_wait_count' => $waitCount],
            'metrics' => $metrics, 'tickets' => $tickets, 'calls' => $calls, 'excluded_calls' => $excludedCalls,
            'metric_exclusions' => $metricExclusions,
            'ins5_administrative_events' => $administrativeEvents,
            'ins6_solver_inclusions' => $ins6SolverInclusions,
            'actor_evidence_basis' => ActorEvidence::BASIS_NOTE,
            'phone_survey' => $phoneSurvey,
            'sla_audit' => ['methods' => $slaMethods, 'source_deadline_missing' => $missing,
                'evaluated' => $slaEvaluated, 'unresolved' => $slaUnresolved, 'recovered' => $slaRecovered,
                'different_from_deadline' => $slaDifferences, 'policy' => 'validated_report_duration',
                'provisional_evidence' => $slaProvisional,
                'target_hours_by_priority' => [2 => 8, 3 => 6, 4 => 4, 5 => 2],
                'calendar_overrides' => ['N1' => $input['sla_n1_calendar_id'] ?? 0,
                    'N2' => $input['sla_n2_calendar_id'] ?? 0, 'N3' => $input['sla_n3_calendar_id'] ?? 0]],
            // Preserve the exact reference data used; future calendar edits must not alter this extraction.
            'sla_reference' => ['timezone' => $slaContext['timezone'] ?? null,
                'database_timezone' => $slaContext['database_timezone'] ?? [],
                'capabilities' => $slaContext['capabilities'] ?? [], 'calendars' => $usedCalendars],
            'staff_register' => $staff,
            'warnings' => array_values(array_unique($warnings)),
            'manual' => ['executive_summary' => $input['executive_summary'], 'actions' => $input['actions'], 'notes' => $input['notes'],
                'phone_survey_actions' => $input['phone_survey_actions']],
            'rule_notes' => [
                'Chamados selecionados pela data de solução (solvedate), inclusive os abertos antes do mês; início inclusivo e primeiro instante do mês seguinte exclusivo.',
                'Telefonia selecionada pela entrada na fila (ENTERQUEUE); eventos posteriores ao fim do mês são consultados para determinar o desfecho. Unidade: identificador da ligação e fila.',
                'Datas das fontes interpretadas no horário local configurado (America/Sao_Paulo), sem conversão automática de UTC.',
                'Mapeamento de prioridades aplicado: ' . implode('; ', $mappingNotes) . '. A numeração operacional do painel e a numeração de severidade do contrato podem diferir; a associação escolhida é mostrada expressamente.',
                'INS1–INS4 avaliam a meta da prioridade contra a duração do relatório: baixa 8h, média 6h, alta 4h, muito alta 2h. A numeração do indicador é uma associação separada da meta.',
                'O início do relógio de solução é a abertura registrada no chamado (campo date do GLPI), inclusive quando herdada do chamado original na clonagem. A data técnica de criação do registro (date_creation) não reinicia esse relógio. O recorte mensal continua sendo a data de solução.',
                'Duração do relatório: solve_delay_stat positivo; senão, intervalo entre abertura e solução menos waiting_duration quando positivo; senão, o intervalo integral entre abertura e solução. A comparação usa segundos, sem arredondamento antes de classificar.',
                'Prazo nativo, calendário, suspensões e cálculo operacional permanecem disponíveis na auditoria, sem substituir a duração adotada pelo relatório validado nem alterar os valores brutos do GLPI.',
                'Clonagem, abertura retroativa e alterações de grupo, categoria ou SLA não são exclusões nem tornam um resultado provisório. Observações de histórico e contadores ficam na auditoria. O relatório não modifica chamados nem regras do GLPI.',
                $n3ExclusionNote,
                'INS5 dispensa cada reabertura efetuada pelo técnico que solucionou o ciclo anterior quando sua filiação atual é N1/N2/N3. Qualquer outra reabertura ou execução incompleta manual mantém o chamado na quantidade apurada. A recusa de solução isolada não reintroduz a reabertura dispensada.',
                $ins6Method . ' ' . ActorEvidence::BASIS_NOTE,
                'Números de teste: correspondência exata dos dígitos do originador após remover a formatação; sem suposição de DDI, sem correspondência por sufixo. A lista aplicada acompanha esta extração.',
                'INS10: a equipe de referência conta uma vez cada profissional com pelo menos um dia faturado na competência, inclusive faturamento proporcional e saídas no próprio mês. As saídas consideradas abrangem o mês e os dois meses anteriores. Não Faturar marcado exclui o vínculo da equipe, das saídas e do faturamento. Dias ajustados são específicos da competência; as datas, marcações originais e os registros históricos são preservados.',
                'TMA por ligação é a duração de conversação; TME por ligação é a espera na fila antes de conectar ao agente. As médias usam as durações válidas de ligações atendidas/conectadas, respectivamente, e diferem dos percentuais INS7 e INS8.',
                'INS12: ' . ($includePhone ? 'GLPI e telefonia incluídos, com soma das respostas favoráveis e válidas. A telefonia conta uma resposta por ligação, independentemente da quantidade de filas.' : 'Somente GLPI; pesquisas telefônicas não participam por escolha da extração.') . ' Chamados usam a data de solução; a pesquisa telefônica usa a primeira entrada nas filas selecionadas dentro do mês. As datas das respostas não substituem esses recortes.',
                'Sem população elegível: não aplicável, nunca aprovação automática ou zero por divisão vazia. Evidência ausente: pendente ou provisório conforme o indicador.',
                'Esta é uma extração do estado atual das bases, com data de geração. Não reconstitui automaticamente o estado histórico de um relatório emitido anteriormente. Word e Excel usam o mesmo conjunto de dados preservado.',
                'A composição financeira usa os valores proporcionais do cadastro e as faixas de glosa do edital. Ajustes aprovados são informados por competência, com justificativa, e não alteram o resultado técnico do indicador. Valores são arredondados em centavos por profissional e por glosa antes da soma.',
            ],
        ];
        $snapshot['financial'] = FinancialSummary::build($snapshot);
        return $snapshot;
    }

    private static function addN3Exclusions(array &$metric, array &$exclusions, array $ids, string $note): void
    {
        $metric['excluded_ticket_ids'] = $ids;
        $metric['excluded_count'] = count($ids);
        $metric['exclusion_note'] = $note;
        $exclusions[$metric['id']] = ['ticket_ids' => $ids, 'count' => count($ids), 'reason' => 'assigned_n3', 'note' => $note];
    }

    private static function metric(string $id, string $label, $numeratorIds, array $denominatorIds,
        float $target, string $direction, string $method, string $note, $status = null): array
    {
        $metric = self::countMetric($id, $label, $numeratorIds === null ? null : count($numeratorIds), count($denominatorIds),
            $target, $direction, $method, $note, $status);
        $metric['evidence_ids'] = $numeratorIds;
        $metric['denominator_ids'] = $denominatorIds;
        return $metric;
    }

    private static function countMetric(string $id, string $label, $numerator, $denominator,
        float $target, string $direction, string $method, string $note, $status = null): array
    {
        $value = $denominator !== null && $denominator > 0 && $numerator !== null ? 100 * $numerator / $denominator : null;
        if ($status === null) {
            if ($denominator === 0) {
                $status = 'not_applicable';
            } elseif ($value === null) {
                $status = 'pending';
            } else {
                $status = ($direction === 'gte' ? $value >= $target : $value <= $target) ? 'met' : 'unmet';
            }
        }
        return ['id' => $id, 'label' => $label, 'numerator' => $numerator, 'denominator' => $denominator,
            'value' => $value, 'target' => $target, 'direction' => $direction, 'status' => $status,
            'method' => $method, 'note' => $note];
    }

    private static function seconds($value)
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)
            || !is_finite((float)$value) || (float)$value < 0) {
            return null;
        }
        return (float)$value;
    }

    private static function date($value)
    {
        if (!is_string($value) || $value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        try {
            $parsed = new \DateTimeImmutable($value, new \DateTimeZone('America/Sao_Paulo'));
            $errors = \DateTimeImmutable::getLastErrors();
            return is_array($errors) && ($errors['warning_count'] || $errors['error_count']) ? null : $parsed;
        } catch (\Exception $e) {
            return null;
        }
    }
}
