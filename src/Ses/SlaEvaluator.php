<?php

namespace GlpiPlugin\G4freports\Ses;

/**
 * SES report measurement, with GLPI's operational clock retained for audit.
 * The agreed report uses a positive stored solution duration, otherwise a
 * positive elapsed-minus-general-waiting duration, otherwise elapsed duration.
 * A clone keeps its recorded opening. No source value is written back to GLPI.
 */
class SlaEvaluator
{
    private $context;
    private $input;
    private $zone;
    private $timezoneAvailable;
    private $days = [];

    public function __construct(array $context, array $input)
    {
        $this->context = $context;
        $this->input = $input;
        $this->timezoneAvailable = !empty($context['timezone']) && (($context['capabilities']['timezone'] ?? true) !== false);
        try {
            $this->zone = new \DateTimeZone((string)($context['timezone'] ?? 'UTC'));
        } catch (\Exception $e) {
            $this->zone = new \DateTimeZone('UTC');
            $this->timezoneAvailable = false;
        }
    }

    public function evaluate(array $ticket): array
    {
        // The native deadline and calendar explain the operational clock, but
        // they are not the duration policy used in the validated SES report.
        // In particular, a zero statistic can coexist with a later native
        // deadline after a change of SLA/calendar. Keep both results visible.
        $out = $this->evaluateOperational($ticket);
        $out['sla_operational_net_seconds'] = $out['sla_net_seconds'];
        $out['sla_operational_method'] = $out['sla_method'];
        $out['sla_operational_within'] = $out['within_sla'];
        $out['sla_operational_reason'] = $out['sla_reason'];
        $out['sla_report_duration_seconds'] = null;
        $out['sla_elapsed_seconds'] = null;
        $out['sla_elapsed_less_waiting_seconds'] = null;

        $target = [2 => 28800, 3 => 21600, 4 => 14400, 5 => 7200][(int)($ticket['priority'] ?? 0)] ?? null;
        $out['sla_target_seconds'] = $target;
        if ($target === null) {
            return $this->finish($out, 'Prioridade sem meta de solução configurada nesta apuração (códigos 2, 3, 4 e 5).');
        }

        $open = $this->time($ticket['opened_at'] ?? $ticket['date'] ?? null);
        $solved = $this->time($ticket['solved_at'] ?? $ticket['solvedate'] ?? null);
        $waiting = $this->integer($ticket['waiting_seconds'] ?? $ticket['waiting_duration'] ?? null);
        $statistic = $this->integer($ticket['solve_seconds'] ?? $ticket['solve_delay_stat'] ?? null);
        if ($this->timezoneAvailable && $open !== null && $solved !== null && $solved >= $open) {
            $out['sla_elapsed_seconds'] = $solved - $open;
            if ($waiting !== null && $waiting >= 0) {
                $out['sla_elapsed_less_waiting_seconds'] = $out['sla_elapsed_seconds'] - $waiting;
            }
        }

        if ($statistic !== null && $statistic > 0) {
            $seconds = $statistic;
            $out['sla_method'] = 'report_stored_duration';
            $reason = 'Tempo de solução positivo armazenado no GLPI, comparado em segundos com a meta da prioridade. O prazo nativo e o cálculo por calendário são mantidos separadamente para conferência.';
        } elseif ($out['sla_elapsed_seconds'] !== null) {
            if ($out['sla_elapsed_less_waiting_seconds'] !== null && $out['sla_elapsed_less_waiting_seconds'] > 0) {
                $seconds = $out['sla_elapsed_less_waiting_seconds'];
                $out['sla_method'] = 'report_elapsed_less_waiting';
                $reason = 'Sem tempo de solução armazenado positivo: usado o intervalo entre a abertura registrada e a solução, menos a espera geral armazenada no GLPI, pois essa diferença é positiva. O intervalo inclui todo o tempo corrido; o calendário operacional permanece como evidência separada.';
            } else {
                $seconds = $out['sla_elapsed_seconds'];
                $out['sla_method'] = 'report_elapsed';
                $reason = 'Sem tempo de solução armazenado positivo e sem diferença positiva entre o tempo corrido e a espera geral: usado o intervalo integral entre a abertura registrada e a solução. A abertura preservada no chamado também é utilizada nos clones.';
                if ($waiting === null || $waiting < 0) {
                    $out['sla_flags'][] = 'general_waiting_unavailable';
                    $reason = 'Sem tempo de solução armazenado positivo e sem contador válido de espera geral: usado o intervalo integral entre a abertura registrada e a solução, sem presumir períodos de suspensão.';
                }
            }
        } else {
            // Do not silently substitute the old calendar result when the
            // report duration itself cannot be computed from the source.
            $out['within_sla'] = null;
            $out['sla_result'] = 'unresolved';
            $out['sla_method'] = 'unresolved';
            $out['sla_net_seconds'] = $out['sla_active_seconds'] = null;
            return $this->finish($out, !$this->timezoneAvailable
                ? 'Sem tempo de solução armazenado positivo e sem fuso horário confirmado para calcular o intervalo entre abertura e solução.'
                : 'Sem tempo de solução armazenado positivo e com datas de abertura e solução ausentes, inválidas ou invertidas.');
        }

        $out['sla_report_duration_seconds'] = $seconds;
        // These existing fields are the duration consumed by report exporters.
        // Calendar gross/waiting and the former net are separate audit fields;
        // their subtraction must not be presented as this report duration.
        $out['sla_net_seconds'] = $out['sla_active_seconds'] = $seconds;
        $out['within_sla'] = $seconds <= $target;
        $out['sla_result'] = $out['within_sla'] ? 'within' : 'late';
        return $this->finish($out, $reason);
    }

    /** Native/calendar assessment is evidence only; it cannot veto the report policy. */
    private function evaluateOperational(array $ticket): array
    {
        $out = [
            'sla_evaluated' => true, 'within_sla' => null, 'sla_result' => 'unresolved',
            'sla_target_seconds' => null, 'sla_calendar_id' => null, 'sla_calendar_name' => '',
            'sla_gross_seconds' => null, 'sla_pending_seconds' => null,
            'sla_net_seconds' => null, 'sla_active_seconds' => null,
            'sla_method' => 'unresolved', 'sla_reason' => '', 'sla_flags' => [], 'sla_flags_text' => '',
            'sla_history_complete' => false, 'sla_stored_within' => null, 'sla_difference' => false,
            'sla_pending_intervals' => [], 'sla_history_log_ids' => [],
            'sla_calendar_source' => '', 'sla_suspension_counter_matches' => null,
            'sla_upper_bound_seconds' => null, 'sla_provisional' => false,
            'sla_calculated_deadline' => null, 'sla_source_target_seconds' => null,
        ];
        if (!$this->timezoneAvailable) {
            return $this->finish($out, 'Fuso horário da fonte não confirmado ou incompatível com os horários extraídos. A avaliação de SLA está pendente para evitar conversão incorreta dos prazos e calendários.');
        }
        $open = $this->time($ticket['opened_at'] ?? $ticket['date'] ?? null);
        $solved = $this->time($ticket['solved_at'] ?? $ticket['solvedate'] ?? null);
        $created = $this->time($ticket['created_at'] ?? $ticket['date_creation'] ?? null);
        $deadline = $this->time($ticket['deadline'] ?? $ticket['time_to_resolve'] ?? null);
        $slaId = (int)($ticket['sla_id'] ?? $ticket['slas_id_ttr'] ?? 0);
        $target = [2 => 28800, 3 => 21600, 4 => 14400, 5 => 7200][(int)($ticket['priority'] ?? 0)] ?? null;
        $agreement = $this->context['agreements'][$slaId] ?? null;
        if ($agreement && (int)($agreement['type'] ?? 0) !== 0) {
            $agreement = null;
        }
        $out['sla_target_seconds'] = $target;
        $out['sla_source_target_seconds'] = $agreement ? $this->duration($agreement) : null;
        if ($deadline !== null && $solved !== null) {
            $out['sla_stored_within'] = $solved <= $deadline;
        } else {
            $out['sla_flags'][] = 'source_deadline_empty';
        }
        if ($open === null || $solved === null || $solved < $open) {
            return $this->finish($out, 'Datas de abertura e solução ausentes, inválidas ou invertidas.');
        }
        if ($target === null) {
            return $this->finish($out, 'Prioridade sem meta de solução configurada nesta apuração (códigos 2, 3, 4 e 5).');
        }
        $history = $this->history($ticket);
        $changes = $this->changes($history, $open, $solved);
        $out['sla_flags'] = array_merge($out['sla_flags'], $changes['flags']);
        $out['sla_history_log_ids'] = $changes['log_ids'];
        // Clones, backdated opening and assignments describe provenance. They do
        // not erase an ordinary ticket's accepted SLA clock or eligibility.
        if ($created !== null && $created > $open + 5) {
            $out['sla_flags'][] = 'creation_after_opening';
        }
        if ($created !== null && $created > $solved) {
            $out['sla_flags'][] = 'creation_after_solution';
        }
        if ($changes['clone']) {
            $out['sla_flags'][] = 'copied_lifecycle';
        }
        if ($agreement && $out['sla_source_target_seconds'] !== $target) {
            $out['sla_flags'][] = 'target_differs_from_bound_sla';
        }
        $choice = $this->chooseCalendar($ticket, $history);
        $calendarId = $choice['id'];
        $out['sla_calendar_id'] = $calendarId;
        $out['sla_calendar_source'] = $choice['source'];
        $sourceCalendar = $agreement ? $this->agreementCalendar($agreement, $ticket) : $this->entityCalendar($ticket);
        if ($calendarId !== null) {
            $out['sla_calendar_name'] = $calendarId === 0 ? '24 horas / sem calendário restritivo'
                : (string)($this->context['calendars'][$calendarId]['name'] ?? ('Calendário #' . $calendarId));
            $out['sla_gross_seconds'] = $this->activeSeconds($open, $solved, $calendarId);
            if ($this->calendarPostdatesTicket($calendarId, $open, $solved)) {
                $out['sla_flags'][] = 'calendar_changed_after_period';
            }
        }
        if (!empty($choice['historical'])) {
            $out['sla_flags'][] = 'calendar_from_history';
        }
        $gross = $out['sla_gross_seconds'];
        $waiting = $this->integer($ticket['waiting_seconds'] ?? $ticket['waiting_duration'] ?? null);
        $slaWaiting = $this->integer($ticket['sla_waiting_seconds'] ?? $ticket['sla_waiting_duration'] ?? null);
        $statistic = $this->integer($ticket['solve_seconds'] ?? $ticket['solve_delay_stat'] ?? null);
        $sameSourceClock = $calendarId !== null && $sourceCalendar !== null
            && ($sourceCalendar === $calendarId || $this->sameCalendarWindow($sourceCalendar, $calendarId, $open, $solved));
        $usesRecordedCalendar = empty($choice['override']) || ($calendarId !== null && $sourceCalendar === $calendarId);

        // GLPI's normal solved-ticket measurement uses its accepted deadline.
        // History certification, cloning and statistics cannot veto this result.
        // An explicit different report calendar or a different report target is
        // a new calculation and must not silently inherit the original deadline.
        if ($agreement && $deadline !== null && $deadline >= $open
            && $out['sla_source_target_seconds'] === $target && $usesRecordedCalendar) {
            if ($gross !== null && $slaWaiting !== null && $slaWaiting >= 0 && $sameSourceClock) {
                $out = $this->withClock($out, $gross, $slaWaiting, $waiting, $statistic);
            }
            $out['within_sla'] = $solved <= $deadline;
            $out['sla_result'] = $out['within_sla'] ? 'within' : 'late';
            $out['sla_method'] = 'recorded_deadline';
            return $this->finish($out, 'Comparação da solução com o prazo registrado no GLPI para o SLA aplicado, cuja meta coincide com a meta da prioridade. Clonagem e alterações de grupo, categoria ou SLA não excluem o chamado. Os contadores e o histórico são informações de conferência.');
        }
        if ($calendarId === null || $gross === null) {
            return $this->finish($out, $choice['reason'] ?: 'Calendário não acessível, segmentos inválidos ou período excessivo para cálculo.');
        }

        // A missing deadline is computed from the target and the SLA's own
        // accumulated waiting, exactly the state GLPI uses in computeDate.
        // Different report target: preserve that target, not the bound duration.
        if ($agreement && $sameSourceClock && $slaWaiting !== null && $slaWaiting >= 0) {
            $expected = $this->addActiveSeconds($open, $target + $slaWaiting, $calendarId);
            if ($expected !== null) {
                $out = $this->withClock($out, $gross, $slaWaiting, $waiting, $statistic);
                $out['sla_calculated_deadline'] = $this->format($expected);
                $out['within_sla'] = $solved <= $expected;
                $out['sla_result'] = $out['within_sla'] ? 'within' : 'late';
                $out['sla_method'] = 'recalculated_sla_deadline';
                return $this->finish($out, 'Prazo calculado a partir da abertura, da meta de solução da prioridade e da espera acumulada do SLA, usando o calendário aplicável ao SLA. '
                    . ($out['sla_source_target_seconds'] !== $target ? 'A duração cadastrada no SLA difere da meta do relatório; foi preservada a meta da prioridade. ' : '')
                    . 'A existência de um clone ou de alterações de atribuição não impede o cálculo. O prazo original, quando presente, permanece para comparação.');
            }
        }

        // Without a bound SLA, use the configured level rule when its clock and
        // recorded net/general-waiting agree. Different calendar IDs may encode
        // identical working segments and holidays for the actual ticket window.
        if (!$agreement && $waiting !== null && $waiting >= 0 && $statistic !== null && $statistic >= 0
            && $statistic === max(0, $gross - $waiting) && ($sameSourceClock || $waiting === 0)) {
            $out = $this->withClock($out, $gross, $waiting, $waiting, $statistic);
            $out['within_sla'] = $statistic <= $target;
            $out['sla_result'] = $out['within_sla'] ? 'within' : 'late';
            $out['sla_method'] = 'validated_operational_duration';
            return $this->finish($out, 'Meta definida pela prioridade e calendário identificado pela regra do nível. O tempo útil registrado no GLPI coincide com o tempo no calendário menos a espera geral registrada, com o limite mínimo de zero usado pelo GLPI. Não é necessário existir vínculo SLA ou prazo preenchido para esta validação.');
        }

        // History is a fallback for missing counters or a real change of clock,
        // rather than a prerequisite for every ordinary ticket measurement.
        $state = $this->suspensions($history, $open, $solved, $created);
        $out['sla_pending_intervals'] = $state['intervals'];
        $out['sla_history_log_ids'] = array_values(array_unique(array_merge($out['sla_history_log_ids'], $state['log_ids'])));
        if ($state['complete']) {
            $pending = 0;
            $sourcePending = 0;
            foreach ($state['intervals'] as &$interval) {
                $seconds = $this->activeSeconds($interval['start_ts'], $interval['end_ts'], $calendarId);
                if ($seconds === null) {
                    return $this->finish($out, 'Não foi possível calcular as suspensões no calendário selecionado.');
                }
                $interval['seconds'] = $seconds;
                $interval['calendar_id'] = $calendarId;
                $pending += $seconds;
                $original = $sourceCalendar === null ? null
                    : $this->activeSeconds($interval['start_ts'], $interval['end_ts'], $sourceCalendar);
                $sourcePending = $sourcePending === null || $original === null ? null : $sourcePending + $original;
            }
            unset($interval);
            // Reconcile any carried waiting baseline before projecting a
            // different calendar. This checks values, not clone provenance.
            if ($waiting === null || $sourcePending === $waiting || ($sameSourceClock && $pending === $waiting)) {
                $out['sla_history_complete'] = true;
                $out['sla_pending_intervals'] = $state['intervals'];
                $out = $this->withClock($out, $gross, $pending, $waiting, $statistic);
                $out['within_sla'] = $out['sla_net_seconds'] <= $target;
                $out['sla_result'] = $out['within_sla'] ? 'within' : 'late';
                $out['sla_method'] = $sameSourceClock ? 'reconstructed' : 'reconstructed_current_calendar';
                return $this->finish($out, 'Cálculo complementar pelos intervalos de suspensão disponíveis, aplicando o mesmo calendário ao tempo total e às suspensões. Os registros de espera existentes foram conciliados; clonagem e alterações de atribuição não constituem exclusões.');
            }
            $out['sla_flags'][] = 'history_counter_difference';
        }
        $out['sla_flags'][] = 'incomplete_history';
        if ($gross <= $target) {
            $out['within_sla'] = true;
            $out['sla_result'] = 'within';
            $out['sla_method'] = 'within_by_upper_bound';
            $out['sla_upper_bound_seconds'] = $gross;
            return $this->finish($out, 'Cumprimento demonstrado pelo limite superior: o intervalo útil integral entre abertura e solução já está dentro da meta; suspensões não negativas somente reduzem esse tempo. O tempo líquido exato não foi presumido.');
        }
        return $this->finish($out, 'Não há prazo aplicável nem contadores ou intervalos suficientes para calcular a meta no calendário selecionado. Confira a evidência específica; clonagem e alterações normais do chamado não são motivos de exclusão.');
    }

    private function withClock(array $out, int $gross, int $pending, ?int $waiting, ?int $statistic): array
    {
        $out['sla_pending_seconds'] = $pending;
        $out['sla_net_seconds'] = $out['sla_active_seconds'] = max(0, $gross - $pending);
        $out['sla_suspension_counter_matches'] = $waiting === null ? null : $waiting === $pending;
        if ($pending > $gross) {
            $out['sla_flags'][] = 'waiting_exceeds_gross';
        }
        if ($waiting !== null && $waiting !== $pending) {
            $out['sla_flags'][] = 'suspension_counter_difference';
        }
        if ($statistic !== null && $statistic !== $out['sla_net_seconds']) {
            $out['sla_flags'][] = 'stored_statistic_difference';
        }
        return $out;
    }

    private function sameCalendarWindow(int $first, int $second, int $start, int $end): bool
    {
        if ($first === $second) {
            return true;
        }
        $day = (new \DateTimeImmutable('@' . $start))->setTimezone($this->zone)->setTime(0, 0);
        for ($i = 0; $i < 3660 && $day->getTimestamp() < $end; $i++, $day = $day->modify('+1 day')) {
            $clip = static function (?array $intervals) use ($start, $end): ?array {
                if ($intervals === null) return null;
                $result = [];
                foreach ($intervals as $interval) {
                    $a = max($start, $interval[0]); $b = min($end, $interval[1]);
                    if ($b > $a) $result[] = [$a, $b];
                }
                return $result;
            };
            $a = $first === 0 ? [[$day->getTimestamp(), $day->modify('+1 day')->getTimestamp()]] : $this->dayIntervals($first, $day);
            $b = $second === 0 ? [[$day->getTimestamp(), $day->modify('+1 day')->getTimestamp()]] : $this->dayIntervals($second, $day);
            if ($a === null || $b === null || $clip($a) !== $clip($b)) return false;
        }
        return $day->getTimestamp() >= $end;
    }

    private function history(array $ticket): array
    {
        $rows = $ticket['sla_history'] ?? $this->context['histories'][(int)($ticket['id'] ?? 0)] ?? [];
        $history = [];
        foreach ($rows as $row) {
            $time = $this->time($row['date_mod'] ?? $row['at'] ?? null);
            if ($time === null) {
                continue;
            }
            $row['_ts'] = $time;
            $row['_id'] = (int)($row['id'] ?? $row['log_id'] ?? 0);
            $history[] = $row;
        }
        usort($history, static function (array $a, array $b): int {
            return [$a['_ts'], $a['_id']] <=> [$b['_ts'], $b['_id']];
        });
        return $history;
    }

    private function changes(array $history, int $open, int $solved): array
    {
        $result = ['flags' => [], 'changed_clock' => false, 'clone' => false, 'log_ids' => []];
        foreach ($history as $row) {
            $option = (int)($row['id_search_option'] ?? 0);
            $action = (int)($row['linked_action'] ?? 0);
            $at = $row['_ts'];
            if (!empty($row['is_clone']) || ($option === 0 && in_array($action, [0, 12], true)
                && preg_match('/\A(?:clone de|cloned from|clonagem|clon[ée])\b/iu', (string)($row['new_value'] ?? '')))) {
                $result['clone'] = true;
            }
            if ($at > $solved && ((in_array($option, [3, 15, 30, 80], true) && $action === 0)
                || (($row['itemtype_link'] ?? '') === 'Group' && ($option === 8 || $action === 16)))) {
                $result['flags'][] = 'post_solution_change';
                $result['log_ids'][] = $row['_id'];
                $result['changed_clock'] = true;
            }
            if ($at < $open || $at > $solved) {
                continue;
            }
            $flag = null;
            if ($option === 30 && $action === 0) {
                $old = $this->valueId($row, 'old');
                $new = $this->valueId($row, 'new');
                if ($new === 0 && $old > 0) {
                    $flag = 'sla_removed';
                } elseif ($new > 0 && $old === 0 && $at > $open + 5) {
                    $flag = 'sla_assigned_late';
                } elseif ($old !== $new) {
                    $flag = 'sla_changed';
                }
                if ($old !== $new && $at > $open + 5) {
                    $result['changed_clock'] = true;
                }
            } elseif ($option === 18 && $action === 0 && trim((string)($row['new_value'] ?? '')) === '') {
                $flag = 'deadline_removed';
            } elseif (in_array($option, [3, 80], true) && $action === 0 && $at > $open + 5) {
                $flag = $option === 3 ? 'priority_changed' : 'entity_changed';
                $result['changed_clock'] = true;
            } elseif (($row['itemtype_link'] ?? '') === 'Group' && ($option === 8 || $action === 16) && $at > $open + 5) {
                $flag = 'group_changed';
                $result['changed_clock'] = true;
            } elseif ($option === 15 && $action === 0) {
                $flag = 'opening_changed';
                $result['changed_clock'] = true;
            }
            if ($flag !== null) {
                $result['flags'][] = $flag;
                $result['log_ids'][] = $row['_id'];
            }
        }
        return $result;
    }

    private function chooseCalendar(array $ticket, array $history): array
    {
        $groups = array_map('intval', $ticket['group_ids'] ?? []);
        $levels = [];
        foreach (['n1', 'n2', 'n3'] as $level) {
            if (array_intersect($groups, $this->input[$level . '_group_ids'] ?? [])) {
                $levels[] = $level;
            }
        }
        $overrides = [];
        foreach ($levels as $level) {
            $id = (int)($this->input['sla_' . $level . '_calendar_id'] ?? 0);
            if ($id > 0) $overrides[$id] = $id;
        }
        if (count($overrides) > 1) {
            return ['id' => null, 'source' => '', 'override' => true, 'reason' => 'Os níveis atualmente atribuídos possuem calendários de relatório explicitamente selecionados e distintos.'];
        }
        if ($overrides) {
            $override = reset($overrides);
            return ['id' => isset($this->context['calendars'][$override]) ? $override : null,
                'source' => 'selected_' . implode('_', $levels), 'override' => true, 'reason' => 'Calendário selecionado não encontrado no catálogo acessível.'];
        }
        $bound = $this->context['agreements'][(int)($ticket['sla_id'] ?? $ticket['slas_id_ttr'] ?? 0)] ?? null;
        if ($bound && (int)($bound['type'] ?? 0) === 0) {
            return ['id' => $this->agreementCalendar($bound, $ticket), 'source' => 'bound_sla',
                'reason' => 'Calendário do SLA aplicado não está acessível.'];
        }
        if (count($levels) > 1) {
            return ['id' => null, 'source' => '', 'reason' => 'Vínculos atuais a mais de um nível de atendimento sem SLA aplicado: calendário de apuração ambíguo.'];
        }
        $level = $levels[0] ?? null;
        if ($level === 'n1' || $level === 'n2') {
            $candidates = [];
            foreach ($this->context['agreements'] ?? [] as $agreement) {
                if ((int)($agreement['type'] ?? 0) !== 0 || !preg_match('/\b' . $level . '\b/i', (string)($agreement['name'] ?? ''))
                    || $this->duration($agreement) === null || !$this->agreementApplies($agreement, $ticket)) {
                    continue;
                }
                $id = $this->agreementCalendar($agreement, $ticket);
                if ($id !== null) {
                    $candidates[$id] = $id;
                }
            }
            if (count($candidates) === 1) {
                return ['id' => reset($candidates), 'source' => 'catalog_' . $level, 'reason' => ''];
            }
            return ['id' => null, 'source' => '', 'reason' => count($candidates) > 1
                ? 'SLAs do nível atual apontam para calendários distintos. Selecione explicitamente o calendário na configuração do relatório.'
                : 'Calendário do nível atual não identificado de forma única no catálogo de SLAs. Selecione explicitamente o calendário na configuração do relatório.'];
        }
        $candidates = [];
        $solved = $this->time($ticket['solved_at'] ?? $ticket['solvedate'] ?? null);
        foreach ($history as $row) {
            if ((int)($row['id_search_option'] ?? 0) !== 30 || (int)($row['linked_action'] ?? 0) !== 0
                || ($solved !== null && $row['_ts'] > $solved)) {
                continue;
            }
            foreach (['old', 'new'] as $side) {
                $id = $this->valueId($row, $side);
                $agreement = $this->context['agreements'][$id] ?? null;
                if (!$agreement || (int)($agreement['type'] ?? 0) !== 0) {
                    continue;
                }
                $calendar = $this->agreementCalendar($agreement, $ticket);
                if ($calendar !== null) {
                    $candidates[$calendar] = $calendar;
                }
            }
        }
        if (count($candidates) === 1) {
            return ['id' => reset($candidates), 'source' => 'historical_sla', 'historical' => true, 'reason' => ''];
        }
        return ['id' => null, 'source' => '', 'reason' => 'Sem calendário de apuração unívoco para o nível atual: nenhum SLA positivo aplicável, histórico ausente ou calendários históricos divergentes. A ausência de vínculo SLA não elimina a meta de prioridade.'];
    }

    /** Catalogue discovery respects entity scope; an observed bound SLA remains evidence. */
    private function agreementApplies(array $agreement, array $ticket): bool
    {
        if (!array_key_exists('entities_id', $agreement)) {
            return false;
        }
        $owner = (int)$agreement['entities_id'];
        $id = (int)($ticket['entity_id'] ?? $ticket['entities_id'] ?? 0);
        if ($owner === $id) {
            return true;
        }
        if (empty($agreement['is_recursive'])) {
            return false;
        }
        $seen = [];
        while (!isset($seen[$id])) {
            $seen[$id] = true;
            $entity = $this->context['entities'][$id] ?? null;
            if (!$entity) {
                return false;
            }
            $id = (int)($entity['entities_id'] ?? $entity['parent_entities_id'] ?? 0);
            if ($id === $owner) {
                return true;
            }
        }
        return false;
    }

    private function agreementCalendar(array $agreement, array $ticket): ?int
    {
        if (!empty($agreement['use_ticket_calendar'])) {
            return $this->entityCalendar($ticket);
        }
        return $this->knownCalendar((int)($agreement['calendars_id'] ?? $agreement['parent_slm_calendars_id'] ?? -1));
    }

    private function entityCalendar(array $ticket): ?int
    {
        $id = (int)($ticket['entity_id'] ?? $ticket['entities_id'] ?? 0);
        $seen = [];
        while (!isset($seen[$id])) {
            $seen[$id] = true;
            $entity = $this->context['entities'][$id] ?? null;
            if (!$entity) return null;
            $calendar = (int)($entity['calendars_id'] ?? -1);
            if ((int)($entity['calendars_strategy'] ?? $calendar) !== -2 && $calendar !== -2) {
                return $this->knownCalendar($calendar);
            }
            $id = (int)($entity['entities_id'] ?? $entity['parent_entities_id'] ?? 0);
        }
        return null;
    }

    private function knownCalendar(int $id): ?int
    {
        return $id === 0 || isset($this->context['calendars'][$id]) ? $id : null;
    }

    private function duration(array $agreement): ?int
    {
        $number = $this->integer($agreement['number_time'] ?? null);
        $multiplier = ['minute' => 60, 'hour' => 3600, 'day' => 86400][(string)($agreement['definition_time'] ?? '')] ?? null;
        return $number !== null && $number > 0 && $multiplier !== null ? $number * $multiplier : null;
    }

    private function suspensions(array $history, int $open, int $solved, ?int $created): array
    {
        $result = ['complete' => false, 'intervals' => [], 'log_ids' => []];
        if (empty($this->context['history_available'])) {
            return $result;
        }
        $events = [];
        foreach ($history as $row) {
            if ($created !== null && (int)($row['linked_action'] ?? 0) === 20 && abs($row['_ts'] - $created) <= 5) {
                $result['log_ids'][] = $row['_id'];
            }
            if ((int)($row['id_search_option'] ?? 0) === 12 && (int)($row['linked_action'] ?? 0) === 0
                && $row['_ts'] >= $open && $row['_ts'] <= $solved + 1) {
                $row['_ts'] = min($solved, $row['_ts']);
                $events[] = $row;
            }
        }
        if (!$events) {
            return $result;
        }
        $state = $this->valueId($events[0], 'old');
        if ($state < 1 || $state > 6) {
            return $result;
        }
        $cursor = $open;
        $valid = true;
        $solutionAtEnd = false;
        foreach ($events as $event) {
            $old = $this->valueId($event, 'old');
            $new = $this->valueId($event, 'new');
            if ($old !== $state || $new < 1 || $new > 6) {
                $valid = false;
                break;
            }
            if (in_array($state, [4, 5, 6], true) && $event['_ts'] > $cursor) {
                $result['intervals'][] = ['start' => $this->format($cursor), 'end' => $this->format($event['_ts']),
                    'start_ts' => $cursor, 'end_ts' => $event['_ts'], 'status' => $state,
                    'exit_log_id' => $event['_id']];
            }
            $result['log_ids'][] = $event['_id'];
            $cursor = $event['_ts'];
            $state = $new;
            if ($new === 5 && abs($cursor - $solved) <= 1) {
                $solutionAtEnd = true;
            }
        }
        $result['complete'] = $valid && $solutionAtEnd && in_array($state, [5, 6], true);
        return $result;
    }

    /** Positive number of active seconds; null means uncomputable, never zero by default. */
    private function activeSeconds(int $start, int $end, int $calendarId): ?int
    {
        if ($end < $start || $end - $start > 3660 * 86400) {
            return null;
        }
        if ($calendarId === 0) {
            return $end - $start;
        }
        $total = 0;
        $day = (new \DateTimeImmutable('@' . $start))->setTimezone($this->zone)->setTime(0, 0);
        while ($day->getTimestamp() < $end) {
            $intervals = $this->dayIntervals($calendarId, $day);
            if ($intervals === null) {
                return null;
            }
            foreach ($intervals as $interval) {
                $total += max(0, min($end, $interval[1]) - max($start, $interval[0]));
            }
            $day = $day->modify('+1 day');
        }
        return $total;
    }

    private function addActiveSeconds(int $start, int $seconds, int $calendarId): ?int
    {
        if ($seconds < 0) {
            return null;
        }
        if ($calendarId === 0) {
            return $start + $seconds;
        }
        $day = (new \DateTimeImmutable('@' . $start))->setTimezone($this->zone)->setTime(0, 0);
        for ($i = 0; $i < 3660; $i++, $day = $day->modify('+1 day')) {
            $intervals = $this->dayIntervals($calendarId, $day);
            if ($intervals === null) {
                return null;
            }
            foreach ($intervals as $interval) {
                $from = max($start, $interval[0]);
                $available = max(0, $interval[1] - $from);
                if ($available >= $seconds && $from <= $interval[1]) {
                    return $from + $seconds;
                }
                $seconds -= $available;
            }
        }
        return null;
    }

    private function dayIntervals(int $calendarId, \DateTimeImmutable $day): ?array
    {
        $date = $day->format('Y-m-d');
        $key = $calendarId . ':' . $date;
        if (array_key_exists($key, $this->days)) {
            return $this->days[$key];
        }
        $calendar = $this->context['calendars'][$calendarId] ?? null;
        if (!$calendar || empty($calendar['segments']) || !is_array($calendar['segments'])) {
            return $this->days[$key] = null;
        }
        foreach ($calendar['holidays'] ?? [] as $holiday) {
            if ($this->isHoliday($date, $holiday)) {
                return $this->days[$key] = [];
            }
        }
        $intervals = [];
        foreach ($calendar['segments'] as $segment) {
            $weekday = (int)($segment['day'] ?? $segment['day_raw'] ?? -1);
            if ($weekday !== (int)$day->format('w')) {
                continue;
            }
            $begin = $this->daySecond($segment['begin'] ?? $segment['begin_raw'] ?? null);
            $end = $this->daySecond($segment['end'] ?? $segment['end_raw'] ?? null);
            if ($begin === null || $end === null || $end < $begin) {
                return $this->days[$key] = null;
            }
            // Calendar segments are local wall times. setTime handles timezone
            // changes correctly instead of treating every local day as 86400 s.
            $a = $day->setTime(intdiv($begin, 3600), intdiv($begin % 3600, 60), $begin % 60)->getTimestamp();
            $b = $day->setTime(intdiv($end, 3600), intdiv($end % 3600, 60), $end % 60)->getTimestamp();
            $intervals[] = [$a, $b];
        }
        usort($intervals, static function (array $a, array $b): int { return $a[0] <=> $b[0]; });
        $merged = [];
        foreach ($intervals as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $interval[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }
        return $this->days[$key] = $merged;
    }

    private function isHoliday(string $date, array $holiday): bool
    {
        $begin = substr((string)($holiday['begin_date'] ?? ''), 0, 10);
        $end = substr((string)($holiday['end_date'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $begin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end)) {
            return false;
        }
        if (!empty($holiday['is_perpetual'])) {
            $begin = substr($begin, 5);
            $end = substr($end, 5);
            $date = substr($date, 5);
            return $begin <= $end ? ($date >= $begin && $date <= $end) : ($date >= $begin || $date <= $end);
        }
        return $date >= $begin && $date <= $end;
    }

    private function calendarPostdatesTicket(int $calendarId, int $start, int $end): bool
    {
        if ($calendarId === 0) {
            return false;
        }
        $calendar = $this->context['calendars'][$calendarId] ?? [];
        $modified = $this->time($calendar['date_mod'] ?? null);
        if ($modified !== null && $modified > $end) {
            return true;
        }
        $startDate = $this->format($start);
        $endDate = $this->format($end);
        foreach ($calendar['holidays'] ?? [] as $holiday) {
            $created = $this->time($holiday['date_creation'] ?? null);
            if ($created === null || $created <= $end) {
                continue;
            }
            // Only flag a later holiday whose dates overlap the measured cycle.
            $day = (new \DateTimeImmutable($startDate, $this->zone))->setTime(0, 0);
            for ($i = 0; $i < 3660 && $day->format('Y-m-d') <= substr($endDate, 0, 10); $i++, $day = $day->modify('+1 day')) {
                if ($this->isHoliday($day->format('Y-m-d'), $holiday)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function valueId(array $row, string $side): ?int
    {
        $numeric = $this->integer($row[$side . '_id'] ?? null);
        if ($numeric !== null && $numeric > 0) {
            return $numeric;
        }
        $value = trim((string)($row[$side . '_value'] ?? ''));
        if (preg_match('/\A\d+\z/D', $value)) {
            return (int)$value;
        }
        return preg_match('/\((\d+)\)\s*$/D', $value, $matches) ? (int)$matches[1] : $numeric;
    }

    private function integer($value): ?int
    {
        return $value !== null && preg_match('/\A-?\d+\z/D', (string)$value) ? (int)$value : null;
    }

    private function time($value): ?int
    {
        if (!is_string($value) || !preg_match('/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\z/D', $value, $match)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $match[1], $this->zone);
        return $date && $date->format('Y-m-d H:i:s') === $match[1] ? $date->getTimestamp() : null;
    }

    private function format(int $time): string
    {
        return (new \DateTimeImmutable('@' . $time))->setTimezone($this->zone)->format('Y-m-d H:i:s');
    }

    private function daySecond($value): ?int
    {
        if (!is_string($value) || !preg_match('/\A(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?\z/D', $value, $match)) {
            return null;
        }
        $seconds = (int)$match[1] * 3600 + (int)$match[2] * 60 + (int)$match[3];
        return (int)$match[2] < 60 && (int)$match[3] < 60 && $seconds <= 86400 ? $seconds : null;
    }

    private function finish(array $out, string $reason): array
    {
        $labels = [
            'source_deadline_empty' => 'Prazo original vazio', 'sla_removed' => 'SLA removido no histórico',
            'sla_assigned_late' => 'SLA atribuído após a abertura', 'sla_changed' => 'SLA alterado durante o atendimento',
            'deadline_removed' => 'Prazo removido no histórico', 'priority_changed' => 'Prioridade alterada',
            'entity_changed' => 'Entidade alterada', 'group_changed' => 'Grupo alterado',
            'opening_changed' => 'Data de abertura alterada', 'creation_after_opening' => 'Criação não coincide com a abertura',
            'creation_after_solution' => 'Linha criada após a solução', 'copied_lifecycle' => 'Clonagem registrada',
            'creation_log_after_solution' => 'Evento de criação/clonagem posterior à solução',
            'calendar_from_history' => 'Calendário recuperado de vínculo SLA histórico',
            'calendar_changed_after_period' => 'Configuração atual alterada após o atendimento',
            'suspension_counter_difference' => 'Suspensão recalculada difere do contador original',
            'stored_statistic_difference' => 'Tempo útil recalculado difere da estatística original',
            'incomplete_history' => 'Histórico insuficiente para reconstrução integral',
            'post_solution_change' => 'Grupo, prioridade, entidade, SLA ou abertura alterados após a solução',
            'target_differs_from_bound_sla' => 'Meta da prioridade difere da duração do SLA aplicado',
            'waiting_exceeds_gross' => 'Espera acumulada excede o tempo útil bruto; estatística limitada a zero conforme GLPI',
            'history_counter_difference' => 'Intervalos históricos disponíveis não conciliam toda a espera registrada',
            'general_waiting_unavailable' => 'Espera geral ausente ou inválida; sem desconto presumido de suspensão',
        ];
        $out['sla_flags'] = array_values(array_unique($out['sla_flags']));
        $out['sla_flags_text'] = implode('; ', array_map(static function (string $flag) use ($labels): string {
            return $labels[$flag] ?? $flag;
        }, $out['sla_flags']));
        $out['sla_reason'] = $reason;
        // Ordinary ticket changes are audit context, not automatic uncertainty
        // of a measured result. Metric coverage handles actual unresolved rows.
        $out['sla_provisional'] = false;
        $out['sla_difference'] = $out['within_sla'] !== null && $out['sla_stored_within'] !== null && $out['within_sla'] !== $out['sla_stored_within'];
        return $out;
    }
}
