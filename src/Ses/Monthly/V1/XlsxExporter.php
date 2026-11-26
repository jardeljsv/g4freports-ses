<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** Native Excel workbook, using dependency-free OOXML and one monthly snapshot. */
final class XlsxExporter
{
    private array $sheets = [];
    private array $evidence = [];
    private array $rawColumns = [];
    private array $phoneIns12Columns = [];
    private array $phoneSurveyColumns = [];
    private array $snapshot = [];
    private array $chartData = [];
    private int $groupPresentColumn = 0;
    private int $n3AssignedColumn = 0;
    private int $ins4ScopeColumn = 0;
    private array $callValidityColumns = [];
    private array $temporalColumns = [];
    private array $heatmapData = [];

    public function filename(array $snapshot): string
    {
        return 'Indicadores_SES_' . preg_replace('/[^0-9-]/', '', $snapshot['period']['month'] ?? '') . '.xlsx';
    }

    public function toBinary(array $snapshot): string
    {
        $this->sheets = [];
        $this->evidence = [];
        $this->rawColumns = [];
        $this->phoneIns12Columns = [];
        $this->phoneSurveyColumns = [];
        $this->snapshot = $snapshot;
        $this->chartData = ChartData::build($snapshot);
        $this->heatmapData = HeatmapData::build($snapshot);
        $this->temporalColumns = [];
        foreach ($snapshot['metrics'] as $metric) {
            $this->evidence[$metric['id']] = [
                'num' => array_fill_keys(array_map('strval', $metric['evidence_ids'] ?? []), true),
                'den' => array_fill_keys(array_map('strval', $metric['denominator_ids'] ?? []), true),
                'available' => isset($metric['evidence_ids'], $metric['denominator_ids']),
                'excluded' => array_fill_keys(array_map('strval', $metric['excluded_ticket_ids'] ?? []), true),
            ];
        }
        // The raw sheets are built first to establish the evidence column map,
        // then ordered after the individual indicators in the final package.
        $tickets = $this->serializeSheet($this->rawTickets());
        $calls = $this->serializeSheet($this->rawCalls());
        $this->sheets[] = $this->consolidated();
        foreach ($snapshot['metrics'] as $metric) {
            $this->sheets[] = $this->serializeSheet($this->metric($metric));
        }
        $this->sheets[] = $this->personnel();
        $this->sheets[] = $this->financial();
        $this->sheets[] = $this->temporal();
        $this->sheets[] = $tickets;
        $this->sheets[] = $calls;
        unset($tickets, $calls);
        $this->sheets[] = $this->exclusionAudit();
        $this->sheets[] = $this->ins5Exceptions();
        $this->sheets[] = $this->methodology();
        return $this->package();
    }

    private function sheet(string $name, array $widths): array
    {
        return ['name' => $name, 'widths' => $widths, 'rows' => [], 'merges' => [], 'heights' => [], 'filter' => '', 'freeze' => 6, 'charts' => [], 'conditional' => [], 'tables' => [], 'links' => [], 'evidence_xml' => '', 'last_evidence_row' => 0];
    }

    private function put(array &$sheet, int $row, int $col, $value, int $style = 0, ?string $formula = null): void
    {
        $sheet['rows'][$row][$col] = ['value' => $value, 'style' => $style, 'formula' => $formula];
    }

    private function merged(array &$sheet, int $row, string $text, int $style, int $end = 7, int $height = 32): void
    {
        $this->put($sheet, $row, 1, $text, $style);
        $sheet['merges'][] = 'A' . $row . ':' . self::col($end) . $row;
        $sheet['heights'][$row] = $height;
    }

    private function title(array &$sheet, string $title, int $end = 7): void
    {
        $this->merged($sheet, 1, $title, 1, $end, 48);
        $this->merged($sheet, 2, 'G4F | Contrato SES/DF 053592/2025 | ' . ($this->snapshot['period']['label'] ?? $this->snapshot['period']['month']), 2, $end, 28);
        $this->merged($sheet, 3, 'Chamados por solução; ligações por entrada na fila. Extração: ' . OfficePackage::date($this->snapshot['generated_at'] ?? ''), 9, $end, 30);
    }

    private function headers(array &$sheet, int $row, array $headers): void
    {
        $height = 36;
        foreach ($headers as $i => $header) {
            $this->put($sheet, $row, $i + 1, $header, 3);
            $characters = function_exists('mb_strlen') ? mb_strlen($header, 'UTF-8') : strlen($header);
            $width = max(8, ($sheet['widths'][$i] ?? 25) - 4);
            $height = max($height, min(160, 12 + 16 * (int)ceil($characters / $width)));
        }
        $sheet['heights'][$row] = $height;
    }

    private function consolidated(): array
    {
        $sheet = $this->sheet('Painel Consolidado', [13, 44, 16, 18, 28, 18, 18]);
        $sheet['freeze'] = 3;
        $this->title($sheet, 'Relatório mensal de indicadores SES');
        $this->merged($sheet, 5, 'Resultados e metas contratuais', 2);
        $this->comparisonChart($sheet, 'gte', 80, [0, 5, 4, 23]);
        $this->comparisonChart($sheet, 'lte', 94, [4, 5, 7, 23]);
        $this->headers($sheet, 25, ['Indicador', 'Descrição', 'Meta', 'Resultado', 'Situação', 'Quantidade apurada', 'Base de cálculo']);
        foreach ($this->snapshot['metrics'] as $i => $m) {
            $row = 26 + $i;
            $this->put($sheet, $row, 1, $m['id'], 4);
            $this->put($sheet, $row, 2, MetricEvidence::title($m['id'], $this->snapshot['input']['priority_map'][$m['id']] ?? null), 0);
            $this->put($sheet, $row, 3, ($m['direction'] === 'lte' ? '≤ ' : '≥ ') . OfficePackage::percent($m['target']), 4);
            $result = $m['value'] === null ? ($m['status'] === 'not_applicable' ? 'N/A' : 'Pendente') : $m['value'] / 100;
            $this->put($sheet, $row, 4, $result, 6, "'" . $m['id'] . "'!B10");
            $this->put($sheet, $row, 5, OfficePackage::status($m), $this->statusStyle($m), "'" . $m['id'] . "'!B12");
            $this->put($sheet, $row, 6, $m['numerator'] === null ? 'Pendente' : $m['numerator'], 5, "'" . $m['id'] . "'!B8");
            $this->put($sheet, $row, 7, $m['denominator'] === null ? 'Pendente' : $m['denominator'], 5, "'" . $m['id'] . "'!B9");
            $title = MetricEvidence::title($m['id'], $this->snapshot['input']['priority_map'][$m['id']] ?? null);
            $characters = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
            $sheet['heights'][$row] = max(44, 12 + 16 * (int)ceil($characters / 38));
        }
        $this->merged($sheet, 40, 'Resumo operacional do período', 2);
        $labels = ['tickets' => 'Chamados solucionados', 'requests' => 'Requisições', 'incidents' => 'Incidentes', 'calls_received' => 'Ligações recebidas após exclusões', 'calls_answered' => 'Ligações atendidas', 'calls_abandoned' => 'Abandonadas, qualquer espera', 'calls_abandoned_up_to_30' => 'Abandonadas com espera de até 30 s'];
        $row = 42;
        foreach ($labels as $key => $label) {
            $this->put($sheet, $row, 2, $label, 0);
            $this->put($sheet, $row++, 4, $this->snapshot['totals'][$key] ?? 0, 5);
        }
        $this->put($sheet, 52, 2, 'TMA — tempo médio de atendimento');
        $this->put($sheet, 52, 4, $this->duration($this->snapshot['totals']['mean_talk_seconds'] ?? null), 10);
        $this->put($sheet, 53, 2, 'TME — tempo médio de espera');
        $this->put($sheet, 53, 4, $this->duration($this->snapshot['totals']['mean_wait_seconds'] ?? null), 10);
        $this->merged($sheet, 55, 'INS9 considera abandonos com espera superior a 30 segundos. A aba Resultado Final Indicadores apresenta as faixas de desconto, o faturamento e os ajustes manuais informados para a competência.', 9, 7, 48);
        $this->attachComposition($sheet, $this->chartData['tickets_types'], 101, 1, [4, 41, 7, 54]);
        $this->attachComposition($sheet, $this->chartData['calls_daily'], 108, 1, [0, 57, 7, 76]);
        $this->merged($sheet, 78, 'Dados dos gráficos vinculados às bases e aos indicadores', 2);
        return $sheet;
    }

    /** The monthly view preserves zero-billed professionals but omits historical exits. */
    private function monthlyStaffRows(): array
    {
        $month = (string)($this->snapshot['period']['month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/D', $month)) return [];
        $first = $month . '-01';
        $last = (new \DateTimeImmutable($first))->format('Y-m-t');
        return array_values(array_filter(StaffRegister::sortRows($this->snapshot['staff_register']['rows'] ?? []),
            static fn(array $row): bool => !empty($row['admission_date']) && $row['admission_date'] <= $last
                && (empty($row['departure_date']) || $row['departure_date'] >= $first)));
    }

    /** Native cells retain the monthly inputs, effective days and the per-person cent calculation. */
    private function personnel(): array
    {
        $sheet = $this->sheet('Base de Dados Colaboradores', [8, 39, 52, 16, 19, 20, 15, 18, 18, 15, 18, 18, 18, 18, 34, 32, 19, 26]);
        $sheet['freeze'] = 6;
        $this->title($sheet, 'Profissionais | composição do faturamento mensal', 7);
        $this->merged($sheet, 4, 'O cadastro histórico permanece na auditoria. Dias informados são ajustes da competência e não alteram as datas do vínculo. Cada valor faturado é arredondado a centavos antes da soma.', 9, 7, 44);
        $headers = ['Nº', 'Nome', 'Perfil Profissional', 'Dias Faturado', 'Valor Mensal (R$)', 'Valor Faturado (R$)', 'Não Faturar',
            'Data de admissão', 'Data de saída', 'Status', 'Primeiro dia do vínculo no mês', 'Último dia do vínculo no mês',
            'Dias automáticos', 'Dias informados', 'ID do registro', 'Frente de serviço', 'Faturado na competência', 'ID da frente'];
        $this->headers($sheet, 6, $headers);
        $month = (string)$this->snapshot['period']['month'];
        $monthDays = (int)(new \DateTimeImmutable($month . '-01'))->format('t');
        $frontMap = [];
        $fronts = $this->snapshot['financial']['contract_fronts'] ?? [];
        foreach ($fronts as $front) {
            $frontMap[$front['id']] = $front['label'];
        }
        $row = 7;
        $daysTotal = $centsTotal = $peopleTotal = 0;
        foreach ($this->monthlyStaffRows() as $person) {
            $billing = StaffRegister::billingForMonth($person, $month);
            $notBilled = StaffRegister::isNotBilled($person);
            $days = (int)($billing['billed_days'] ?? 0);
            $amount = $billing['billed_value'] === null ? null : (float)$billing['billed_value'];
            $counted = !$notBilled && $days > 0;
            $frontId = FinanceSettings::resolveFront($person, $fronts);
            $values = [$row - 6, $person['name'], $person['profile'], $days, (float)$person['monthly_value'], $amount,
                $notBilled ? 'Sim' : 'Não', $this->excelDate($person['admission_date']), $this->excelDate($person['departure_date'] ?? ''),
                $billing['status'], $this->excelDate($billing['billing_first_day'] ?? ''), $this->excelDate($billing['billing_last_day'] ?? ''),
                $billing['automatic_billed_days'] ?? null, $billing['billed_days_override'] ?? null, $person['id'] ?? '',
                $frontMap[$frontId] ?? 'Sem frente informada', $counted ? 'Sim' : 'Não', $frontId];
            foreach ($values as $index => $value) {
                $style = in_array($index, [0, 6], true) ? 15 : (in_array($index, [4, 5], true) ? 17 : (in_array($index, [7, 8, 10, 11], true) ? 8 : (is_numeric($value) && $index !== 14 ? 5 : 0)));
                $formula = null;
                if ($index === 3) $formula = 'IF(G' . $row . '="Sim",0,IF(ISNUMBER(N' . $row . '),N' . $row . ',M' . $row . '))';
                elseif ($index === 5 && $amount !== null) $formula = 'ROUND(E' . $row . '/' . $monthDays . '*D' . $row . ',2)';
                elseif ($index === 16) $formula = 'IF(AND(G' . $row . '="Não",D' . $row . '>0),"Sim","Não")';
                $this->put($sheet, $row, $index + 1, $value, 18 + (($row - 7) % 2) * 18 + $style, $formula);
            }
            $sheet['heights'][$row++] = 44;
            $daysTotal += $days;
            $centsTotal += (int)round((float)$amount * 100);
            $peopleTotal += $counted ? 1 : 0;
        }
        $last = $row - 1;
        if ($last >= 7) $sheet['tables'][] = ['name' => 'Colaboradores_competencia', 'ref' => 'A6:R' . $last, 'headers' => $headers];
        $this->put($sheet, $row, 2, 'Total da competência', 4);
        $this->put($sheet, $row, 4, $daysTotal, 5, $last >= 7 ? 'SUM(D7:D' . $last . ')' : null);
        $this->put($sheet, $row, 6, $centsTotal / 100, 17, $last >= 7 ? 'SUM(F7:F' . $last . ')' : null);
        $this->put($sheet, $row, 17, $peopleTotal, 5, $last >= 7 ? 'COUNTIF(Q7:Q' . $last . ',"Sim")' : null);
        $sheet['heights'][$row] = 32;
        $this->put($sheet, $row + 1, 2, 'Total dos registros visíveis', 4);
        $this->put($sheet, $row + 1, 4, $daysTotal, 5, $last >= 7 ? 'SUBTOTAL(109,D7:D' . $last . ')' : null);
        $this->put($sheet, $row + 1, 6, $centsTotal / 100, 17, $last >= 7 ? 'SUBTOTAL(109,F7:F' . $last . ')' : null);
        return $sheet;
    }

    /** Contract bands use exact metric values; approved overrides never rewrite the metric. */
    private function deductionRateFormula(string $id, string $result): string
    {
        if (in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true)) $thresholds = [0.9, 0.85, 0.8, 0.75];
        elseif (in_array($id, ['INS6', 'INS11', 'INS12'], true)) $thresholds = [0.8, 0.75, 0.7, 0.65];
        elseif (in_array($id, ['INS7', 'INS8'], true)) $thresholds = [0.85, 0.8, 0.75, 0.7];
        else $thresholds = ['INS5' => [0.02, 0.04, 0.06, 0.08], 'INS9' => [0.05, 0.07, 0.09, 0.11], 'INS10' => [0.10, 0.15, 0.20, 0.25]][$id] ?? [];
        if (!$thresholds) return '"Não apurado"';
        $operator = in_array($id, ['INS5', 'INS9', 'INS10'], true) ? '<=' : '>=';
        $formula = '0.04';
        for ($index = 3; $index >= 0; $index--) {
            $formula = 'IF(' . $result . $operator . $thresholds[$index] . ',' . ($index / 100) . ',' . $formula . ')';
        }
        $status = "'" . $id . "'!B12";
        return 'IF(' . $status . '="Não aplicável",0,IF(AND(OR(' . $status . '="Meta atingida",' . $status . '="Meta não atingida"),ISNUMBER(' . $result . ')),' . $formula . ',"Não apurado"))';
    }

    private function financial(): array
    {
        $sheet = $this->sheet('Resultado Final Indicadores', [15, 44, 17, 20, 20, 20, 23, 52, 20]);
        $sheet['freeze'] = 11;
        $this->title($sheet, 'Resultado financeiro dos indicadores', 9);
        $financial = $this->snapshot['financial'] ?? [];
        $rosterAvailable = !empty($financial['available']);
        $staffCount = count($this->monthlyStaffRows());
        $staffTotalRow = 7 + $staffCount;
        $this->put($sheet, 5, 1, 'Faturamento bruto', 4);
        $this->put($sheet, 5, 2, (float)($financial['gross_value'] ?? 0), 17, "'Base de Dados Colaboradores'!F" . $staffTotalRow);
        $this->put($sheet, 6, 1, 'Descontos por indicadores', 4);
        $this->put($sheet, 7, 1, 'Ajustes financeiros informados', 4);
        $this->put($sheet, 8, 1, 'Valor líquido', 4);
        foreach ([5, 6, 7, 8] as $summaryRow) $sheet['heights'][$summaryRow] = 52;
        $this->merged($sheet, 10, 'As faixas contratuais usam o resultado apurado. Uma glosa informada substitui apenas o desconto financeiro, com justificativa, sem alterar o indicador. Ajustes positivos somam e negativos subtraem. Valores arredondados por linha antes da soma.', 9, 9, 44);
        $this->headers($sheet, 11, ['Indicador', 'Descrição', 'Meta', 'Resultado apurado', 'Glosa calculada', 'Glosa aplicada', 'Valor da glosa (R$)', 'Justificativa / análise', 'Glosa informada']);
        $row = 12;
        foreach ($financial['deductions'] ?? [] as $deduction) {
            $id = (string)$deduction['id'];
            $result = $deduction['value'] === null ? (($this->metricById($id)['status'] ?? '') === 'not_applicable' ? 'N/A' : 'Não apurado') : (float)$deduction['value'] / 100;
            $this->put($sheet, $row, 1, $id, 4);
            $this->put($sheet, $row, 2, $deduction['label'] ?? MetricEvidence::title($id));
            $this->put($sheet, $row, 3, (($deduction['direction'] ?? '') === 'lte' ? '≤ ' : '≥ ') . OfficePackage::percent($deduction['target'] ?? 0));
            $this->put($sheet, $row, 4, $result, 6, "'" . $id . "'!B10");
            $calculated = $deduction['calculated_rate'] === null ? 'Não apurado' : (float)$deduction['calculated_rate'] / 100;
            $applied = $deduction['applied_rate'] === null ? 'Não apurado' : (float)$deduction['applied_rate'] / 100;
            $this->put($sheet, $row, 5, $calculated, 6, $this->deductionRateFormula($id, 'D' . $row));
            $this->put($sheet, $row, 6, $applied, 6, 'IF(ISNUMBER(I' . $row . '),I' . $row . ',E' . $row . ')');
            $amount = $deduction['amount'] === null ? 'Não apurado' : (float)$deduction['amount'];
            $this->put($sheet, $row, 7, $amount, 17, $rosterAvailable ? 'IF(ISNUMBER(F' . $row . '),ROUND($B$5*F' . $row . ',2),"Não apurado")' : '"Não apurado"');
            $this->put($sheet, $row, 8, $deduction['justification'] ?? '', 0);
            $this->put($sheet, $row, 9, !empty($deduction['override']) && is_numeric($applied) ? $applied : null, 6);
            $sheet['heights'][$row++] = 48;
        }
        $last = $row - 1;
        $deductions = $financial['deduction_total'] ?? null;
        $complete = !empty($financial['complete']);
        $completeFormula = $rosterAvailable && $last >= 12 ? 'AND(COUNT(E12:E' . $last . ')=12,COUNT(G12:G' . $last . ')=12)' : 'FALSE';
        $deductionFormula = $last >= 12 ? 'IF(' . $completeFormula . ',SUM(G12:G' . $last . '),"Não apurado")' : null;
        $this->put($sheet, 6, 2, $complete && $deductions !== null ? (float)$deductions : 'Não apurado', 17, $deductionFormula);
        $this->put($sheet, $row, 2, 'Total das glosas', 4);
        $this->put($sheet, $row, 5, $complete ? (float)($financial['calculated_rate_total'] ?? 0) / 100 : 'Não apurado', 6, $last >= 12 ? 'IF(' . $completeFormula . ',SUM(E12:E' . $last . '),"Não apurado")' : null);
        $this->put($sheet, $row, 6, $complete ? (float)($financial['applied_rate_total'] ?? 0) / 100 : 'Não apurado', 6, $last >= 12 ? 'IF(' . $completeFormula . ',SUM(F12:F' . $last . '),"Não apurado")' : null);
        $this->put($sheet, $row, 7, $complete && $deductions !== null ? (float)$deductions : 'Não apurado', 17, 'B6');
        $row += 3;
        $this->merged($sheet, $row++, 'Ajustes financeiros da competência', 2, 9);
        $this->put($sheet, $row, 1, 'Descrição', 3);
        $sheet['merges'][] = 'A' . $row . ':F' . $row;
        $this->put($sheet, $row++, 7, 'Valor (R$)', 3);
        $firstAdjustment = $row;
        foreach ($financial['adjustments'] ?? [] as $adjustment) {
            $this->put($sheet, $row, 1, $adjustment['description']);
            $sheet['merges'][] = 'A' . $row . ':F' . $row;
            $this->put($sheet, $row, 7, (float)$adjustment['amount'], 17);
            $sheet['heights'][$row++] = 40;
        }
        if ($row === $firstAdjustment) { $this->put($sheet, $row, 1, 'Nenhum ajuste informado'); $sheet['merges'][] = 'A' . $row . ':F' . $row; $this->put($sheet, $row++, 7, 0.0, 17); }
        $this->put($sheet, 7, 2, (float)($financial['adjustment_total'] ?? 0), 17, 'SUM(G' . $firstAdjustment . ':G' . ($row - 1) . ')');
        $this->put($sheet, 8, 2, $complete && isset($financial['net_value']) ? (float)$financial['net_value'] : 'Não apurado', 17,
            'IF(ISNUMBER(B6),ROUND(B5-B6+B7,2),"Não apurado")');
        $row += 2;
        $this->merged($sheet, $row++, 'Referência contratual por frente de serviço', 2, 9);
        $this->headers($sheet, $row, ['Perfil profissional', '', 'Profissionais previstos', 'Valor unitário mensal (R$)', 'Valor contratual (R$)']);
        $sheet['merges'][] = 'A' . $row . ':B' . $row;
        $row++;
        $frontStart = $row;
        foreach ($financial['contract_fronts'] ?? [] as $front) {
            $this->put($sheet, $row, 1, $front['label'], 4);
            $sheet['merges'][] = 'A' . $row . ':E' . $row;
            $sheet['heights'][$row++] = 40;
            $lines = $front['profile_lines'] ?? [];
            // Custom configurations made before profile-level contract rows remain valid.
            if (!$lines) $lines = [[
                'profile' => implode(' / ', $front['profiles'] ?? []) ?: $front['label'],
                'contract_quantity' => $front['contract_quantity'],
                'monthly_rate' => $front['monthly_rate'],
                'contract_value' => $front['contract_value'],
            ]];
            foreach ($lines as $line) {
                $this->put($sheet, $row, 1, $line['profile']);
                $sheet['merges'][] = 'A' . $row . ':B' . $row;
                $this->put($sheet, $row, 3, (int)$line['contract_quantity'], 5);
                $this->put($sheet, $row, 4, (float)$line['monthly_rate'], 17);
                $lineValue = $line['contract_value'] ?? FinanceSettings::amount(FinanceSettings::cents($line['monthly_rate']) * (int)$line['contract_quantity']);
                $this->put($sheet, $row, 5, (float)$lineValue, 17, 'ROUND(C' . $row . '*D' . $row . ',2)');
                $sheet['heights'][$row++] = 52;
            }
        }
        if ($row === $frontStart) $this->merged($sheet, $row++, 'Referência contratual não cadastrada.', 9, 9);
        else {
            $this->put($sheet, $row, 1, 'Total contratual', 4);
            $sheet['merges'][] = 'A' . $row . ':B' . $row;
            $this->put($sheet, $row, 3, (int)($financial['contract_quantity_total'] ?? 0), 5, 'SUM(C' . $frontStart . ':C' . ($row - 1) . ')');
            $approvedTotal = $financial['contract_total'] ?? null;
            $this->put($sheet, $row, 5, (float)($financial['contract_value_total'] ?? 0), 17,
                $approvedTotal !== null ? null : 'SUM(E' . $frontStart . ':E' . ($row - 1) . ')');
            $row++;
        }
        $row += 2;
        $this->merged($sheet, $row++, 'Composição mensal por frente de serviço', 2, 9);
        $this->headers($sheet, $row, ['Frente de serviço', '', 'Profissionais faturados', 'Dias faturados', 'Valor faturado (R$)']);
        $sheet['merges'][] = 'A' . $row . ':B' . $row;
        $row++;
        $monthlyFrontStart = $row;
        $staffLast = max(7, 6 + $staffCount);
        $frontRange = "'Base de Dados Colaboradores'!\$R\$7:\$R\$" . $staffLast;
        $peopleRange = "'Base de Dados Colaboradores'!\$Q\$7:\$Q\$" . $staffLast;
        $daysRange = "'Base de Dados Colaboradores'!\$D\$7:\$D\$" . $staffLast;
        $valueRange = "'Base de Dados Colaboradores'!\$F\$7:\$F\$" . $staffLast;
        $monthlyFronts = $financial['monthly_fronts'] ?? [];
        if (!in_array('__unmapped', array_column($monthlyFronts, 'id'), true)) {
            foreach ($this->monthlyStaffRows() as $person) {
                if (FinanceSettings::resolveFront($person, $financial['contract_fronts'] ?? []) !== '__unmapped') continue;
                // Keep a formula destination for an excluded/zero-day worker later
                // included through the workbook's editable personnel cells.
                $monthlyFronts[] = ['id' => '__unmapped', 'label' => 'Sem frente informada',
                    'professionals' => 0, 'billed_days' => 0, 'billed_value' => '0.00'];
                break;
            }
        }
        foreach ($monthlyFronts as $front) {
            $this->put($sheet, $row, 1, $front['label']);
            $criterion = '"' . str_replace('"', '""', (string)($front['id'] ?? '__unmapped')) . '"';
            $sheet['merges'][] = 'A' . $row . ':B' . $row;
            $this->put($sheet, $row, 3, (int)$front['professionals'], 5, 'COUNTIFS(' . $frontRange . ',' . $criterion . ',' . $peopleRange . ',"Sim")');
            $this->put($sheet, $row, 4, (int)$front['billed_days'], 5, 'SUMIF(' . $frontRange . ',' . $criterion . ',' . $daysRange . ')');
            $this->put($sheet, $row, 5, (float)$front['billed_value'], 17, 'SUMIF(' . $frontRange . ',' . $criterion . ',' . $valueRange . ')');
            $sheet['heights'][$row++] = 40;
        }
        if ($row > $monthlyFrontStart) {
            $this->put($sheet, $row, 1, 'Total mensal', 4);
            $sheet['merges'][] = 'A' . $row . ':B' . $row;
            foreach ([3, 4, 5] as $col) $this->put($sheet, $row, $col, $col === 3 ? (int)($this->snapshot['staff_register']['billing_professionals_total'] ?? 0) : ($col === 4 ? (int)($this->snapshot['staff_register']['billed_days_total'] ?? 0) : (float)($financial['gross_value'] ?? 0)), $col === 5 ? 17 : 5, 'SUM(' . self::col($col) . $monthlyFrontStart . ':' . self::col($col) . ($row - 1) . ')');
            $row++;
        }
        foreach ($financial['notes'] ?? [] as $note) $this->merged($sheet, ++$row, (string)$note, 9, 9, 44);
        return $sheet;
    }

    /** Reuse the existing metric cells: no redundant composition table or summed percentages. */
    private function attachMetricComparison(array &$sheet, array $definition, array $anchor): void
    {
        if (empty($definition['available'])) return;
        $prefix = "'" . $sheet['name'] . "'!";
        $definition['category_ref'] = $prefix . '$A$4';
        // A4 is the existing drill-down label; the cache names the indicator.
        // Use a literal category cache so a recalculation cannot replace it with that label.
        unset($definition['category_ref']);
        foreach ($definition['series'] as $index => &$series) {
            $series['value_ref'] = $prefix . ($index === 0 ? '$B$11' : '$B$10');
        }
        unset($series);
        $definition['anchor'] = $anchor;
        $sheet['charts'][] = $definition;
    }

    private function metric(array $m): array
    {
        $sheet = $this->sheet($m['id'], [47, 23, 21, 21, 21, 21, 23]);
        $sheet['freeze'] = 3;
        $this->title($sheet, $m['id'] . ' | ' . MetricEvidence::title($m['id'], $this->snapshot['input']['priority_map'][$m['id']] ?? null));
        $this->merged($sheet, 5, $m['method'], 9, 7, 65);
        $this->headers($sheet, 7, ['Medição', 'Valor']);
        $hasEvidence = $this->evidence[$m['id']]['available'] && isset($this->rawColumns[$m['id']]);
        $source = $this->rawColumns[$m['id']] ?? null;
        $labels = MetricEvidence::labels($m['id']);
        foreach ([8 => [$labels['numerator'], 'numerator', 'num'], 9 => [$labels['denominator'], 'denominator', 'den']] as $row => $spec) {
            $formula = null;
            if ($hasEvidence && $m[$spec[1]] !== null) {
                $formula = 'SUM(' . $this->range($source['sheet'], $source[$spec[2]], $source['rows']) . ')';
                if ($m['id'] === 'INS12') {
                    $phone = $this->phoneIns12Columns;
                    $formula .= '+SUM(' . $this->range($phone['sheet'], $phone[$spec[2]], $phone['rows']) . ')';
                }
            } elseif ($m['id'] === 'INS10' && $m[$spec[1]] !== null) {
                $formula = $spec[2] === 'num' ? 'B25' : 'B24';
            }
            $this->put($sheet, $row, 1, $spec[0], 0);
            $characters = function_exists('mb_strlen') ? mb_strlen($spec[0], 'UTF-8') : strlen($spec[0]);
            $sheet['heights'][$row] = max(32, min(100, 18 * (int)ceil($characters / 40)));
            $this->put($sheet, $row, 2, $m[$spec[1]] === null ? 'Pendente' : $m[$spec[1]], 5, $formula);
        }
        $this->put($sheet, 10, 1, 'Resultado', 4);
        $result = $m['value'] === null ? ($m['status'] === 'not_applicable' ? 'N/A' : 'Pendente') : $m['value'] / 100;
        $this->put($sheet, 10, 2, $result, 6, $m['value'] !== null ? 'IF(B9=0,"N/A",B8/B9)' : null);
        $this->put($sheet, 11, 1, 'Meta ' . ($m['direction'] === 'lte' ? '≤' : '≥'), 0);
        $this->put($sheet, 11, 2, $m['target'] / 100, 6);
        $this->put($sheet, 12, 1, 'Situação', 4);
        $statusFormula = null;
        if (in_array($m['status'], ['met', 'unmet'], true)) {
            $operator = $m['direction'] === 'lte' ? '<=' : '>=';
            $statusFormula = 'IF(ISNUMBER(B10),IF(B10' . $operator . 'B11,"Meta atingida","Meta não atingida"),"Não aplicável")';
        }
        $this->put($sheet, 12, 2, OfficePackage::status($m), $this->statusStyle($m), $statusFormula);
        $sheet['merges'][] = 'B12:C12';
        $sheet['heights'][12] = 34;
        if (array_key_exists('excluded_count', $m)) {
            $this->put($sheet, 14, 1, 'Excluídos por atribuição a N3', 4);
            $this->put($sheet, 14, 2, (int)$m['excluded_count'], 5);
            $this->put($sheet, 15, 1, 'Evidências na aba Auditoria de exclusões', 9);
            $sheet['merges'][] = 'A15:C15';
            $sheet['heights'][15] = 34;
        }
        $this->merged($sheet, 18, trim((string)($m['note'] ?? '')) ?: 'Evidências vinculadas às bases de chamados e ligações desta extração.', 9, 7, 64);
        $chart = $this->chartData[$m['id']];
        $this->attachMetricComparison($sheet, $chart, [3, 6, 7, 17]);
        if ($m['id'] === 'INS12') {
            $this->put($sheet, 14, 1, 'Fontes desta apuração', 4);
            $this->put($sheet, 15, 1, $this->ins12ModeLabel(), 9);
            $sheet['merges'][] = 'A15:C16';
            $sheet['heights'][15] = 30;
            $sheet['heights'][16] = 30;
        }
        if (empty($chart['available'])) {
            $this->put($sheet, 8, 4, $m['status'] === 'not_applicable' ? 'Não aplicável: sem população de cálculo.' : 'Gráfico pendente: faltam informações para apurar este indicador.', 13);
            $sheet['merges'][] = 'D8:G12';
        }
        // Keep the calculation tables in place; link the rule exceptions to
        // their own evidence rather than silently mixing them into that table.
        $auditLinks = ['INS3' => 7, 'INS4' => 7, 'INS6' => 8,
            'INS7' => 9, 'INS8' => 9, 'INS9' => 9, 'INS10' => 11, 'INS12' => 9];
        if ($m['id'] === 'INS5' || isset($auditLinks[$m['id']])) {
            $this->put($sheet, 17, 1, $m['id'] === 'INS5' ? 'Ver exceções de reabertura →' : 'Ver exceções e regras aplicadas →', 4);
            $sheet['merges'][] = 'A17:C17';
            $sheet['heights'][17] = 30;
            $sheet['links'][] = ['ref' => 'A17', 'location' => $m['id'] === 'INS5'
                ? "'Exceções INS5'!A1" : "'Auditoria de exclusões'!A" . $auditLinks[$m['id']]];
        }
        if ($m['id'] === 'INS10') {
            $this->merged($sheet, 21, 'Informações de pessoal | janela de três meses', 2);
            $this->headers($sheet, 23, ['Informação', 'Quantidade']);
            $summary = $this->snapshot['staff_register'] ?? $this->snapshot['input']['staff_summary'] ?? [];
            $billingRows = MetricEvidence::table($this->snapshot, 'INS10')['rows'];
            $staff = [24 => ['Profissionais faturados na competência', (int)($summary['billing_professionals_total'] ?? 0)],
                25 => ['Saídas consideradas no INS10', (int)($summary['departures_total'] ?? 0)],
                26 => ['Profissionais presentes na competência', count($this->monthlyStaffRows())],
                27 => ['Profissionais desconsiderados na competência', count(array_filter($this->monthlyStaffRows(), static fn(array $p): bool => StaffRegister::isNotBilled($p)))],
                28 => ['Profissionais ativos no fim da competência (conferência)', (int)($summary['active_total'] ?? 0)],
                29 => ['Valor mensal dos profissionais faturados', array_sum(array_map(static fn(array $p): float => $p[5] === 'Sim' ? (float)$p[4] : 0.0, $billingRows))]];
            foreach ($staff as $r => [$label, $quantity]) {
                $this->put($sheet, $r, 1, $label);
                $this->put($sheet, $r, 2, $quantity, $r === 29 ? 17 : 5);
                $sheet['heights'][$r] = 36;
            }
            $r = 30;
            $this->put($sheet, $r, 1, 'Janela de apuração');
            $this->put($sheet, $r, 2, OfficePackage::date($summary['window_start'] ?? '') . ' a ' . OfficePackage::date($summary['window_end'] ?? ''));
            $sheet['heights'][$r++] = 48;
            // Older saved snapshots have the raw roster but no billing annotations.
            // Use the same derived evidence values so formula caches reconcile on first opening.
            $billingRows = MetricEvidence::table($this->snapshot, 'INS10')['rows'];
            $this->put($sheet, $r, 1, 'Total de dias faturados na competência');
            $this->put($sheet, $r, 2, array_sum(array_column($billingRows, 15)), 5);
            $sheet['heights'][$r++] = 48;
            $this->put($sheet, $r, 1, 'Valor faturado na competência');
            $this->put($sheet, $r, 2, round(array_sum(array_column($billingRows, 16)), 2), 17);
            $sheet['heights'][$r++] = 48;
            $this->merged($sheet, max(36, $r + 1), trim((string)($summary['note'] ?? '')) ?: 'Dados de pessoal preservados na extração.', 9, 7, 64);
        } elseif ($hasEvidence && $m['id'] !== 'INS12') {
            $this->daily($sheet, $m, $source);
        }
        if ($m['id'] === 'INS12') {
            // Show source reconciliation before the daily table or technical notes.
            // The composition cells feed the chart at the top without mixing origins.
            $row = $this->ins12Sources($sheet, $m, 21);
            $this->ticketSurveyDistribution($sheet, $row + 2);
            $this->phoneSurveyDistribution($sheet, $this->lastOccupiedRow($sheet) + 3);
            if ($hasEvidence && $m['status'] !== 'pending') {
                $this->daily($sheet, $m, $source, $this->lastOccupiedRow($sheet) + 3);
            }
            if (isset($this->snapshot['phone_survey'])) {
                $this->phoneSurvey($sheet, $this->lastOccupiedRow($sheet) + 3);
            }
        }
        $this->metricEvidence($sheet, $m);
        if ($m['id'] === 'INS10') $this->linkStaffSummary($sheet);
        return $sheet;
    }

    /** Manual day edits in the exported monthly roster feed INS10 and the financial summary. */
    private function linkStaffSummary(array &$sheet): void
    {
        $count = count($this->monthlyStaffRows());
        $total = 7 + $count;
        $last = max(7, $total - 1);
        $prefix = "'Base de Dados Colaboradores'!";
        $formulas = [24 => $prefix . 'Q' . $total,
            29 => $count ? 'SUMIF(' . $prefix . '$Q$7:$Q$' . $last . ',"Sim",' . $prefix . '$E$7:$E$' . $last . ')' : '0',
            31 => $prefix . 'D' . $total,
            32 => $prefix . 'F' . $total];
        foreach ($formulas as $row => $formula) $sheet['rows'][$row][2]['formula'] = $formula;
    }

    /** Stable IDs keep financial inputs and repeated audit evidence reconciled after spreadsheet edits. */
    private function monthlyStaffLookup(string $column, string $idReference, string $fallback): string
    {
        $count = count($this->monthlyStaffRows());
        if (!$count) return $fallback;
        $last = 6 + $count;
        $prefix = "'Base de Dados Colaboradores'!";
        return 'IFERROR(INDEX(' . $prefix . '$' . $column . '$7:$' . $column . '$' . $last
            . ',MATCH(' . $idReference . ',' . $prefix . '$O$7:$O$' . $last . ',0)),' . $fallback . ')';
    }

    /** Put the relevant population, including failures, on the indicator's own sheet. */
    private function metricEvidence(array &$sheet, array $metric): void
    {
        $isCall = in_array($metric['id'], ['INS7', 'INS8', 'INS9'], true);
        $table = MetricEvidence::table($this->snapshot, $metric['id'], $isCall ? 'numerator' : 'denominator');
        $headers = $this->compactHeaders($table['headers'] ?? []);
        if (!$headers) { return; }
        $start = $this->lastOccupiedRow($sheet) + 3;
        $callTitles = ['INS7' => 'Ligações atendidas com duração de até 10 minutos',
            'INS8' => 'Ligações conectadas ao agente em até 30 segundos',
            'INS9' => 'Ligações abandonadas após espera de 30 segundos'];
        $this->merged($sheet, $start, $isCall ? $callTitles[$metric['id']] : 'Registros detalhados | ' . $metric['id'], 2);
        $note = $metric['id'] === 'INS10'
            ? 'Profissionais faturados em qualquer dia da competência e saídas consideradas na janela de três meses. Cada profissional com dias faturados positivos conta uma vez, inclusive os que saíram durante o mês. Dias informados substituem a contagem automática apenas na competência selecionada. O cadastro histórico completo permanece na Auditoria de exclusões.'
            : 'Esta tabela já contém a população deste indicador, incluindo os casos que não atingem a condição medida. INS1–INS4 também preservam casos do recorte cuja apuração esteja pendente. Consulte as bases gerais e a Auditoria de exclusões para os demais registros.';
        if ($isCall) {
            $note = (int)($metric['numerator'] ?? 0) . ' registros nesta condição; '
                . (int)($metric['denominator'] ?? 0) . ' ligações na base completa de cálculo. '
                . 'O filtro desta tabela altera somente as linhas exibidas, sem mudar o percentual oficial. '
                . 'A Base de Dados Ligações preserva todas as ligações consideradas e as colunas de participação de cada INS; números de teste ficam somente na auditoria.';
            $this->put($sheet, 4, 4, 'Ver base completa do cálculo →', 4);
            $sheet['merges'][] = 'D4:G4';
            $sheet['links'][] = ['ref' => 'D4', 'location' => "'Base de Dados Ligações'!A6"];
        }
        $this->merged($sheet, $start + 1, $note, 9, 7, $isCall ? 76 : 58);
        $headerRow = $start + 3;
        $this->headers($sheet, $headerRow, $headers);
        $sheet['heights'][$headerRow] = 60;
        foreach ($headers as $index => $header) {
            // Keep the first seven report columns unchanged so chart anchors retain
            // their tested geometry. Extend the evidence columns independently.
            if (!isset($sheet['widths'][$index])) {
                $sheet['widths'][$index] = preg_match('/motivo|categoria|título|perfil|nome|grupos|artigos/iu', $header) ? 42 : 25;
            }
            $characters = function_exists('mb_strlen') ? mb_strlen($header, 'UTF-8') : strlen($header);
            $sheet['heights'][$headerRow] = max($sheet['heights'][$headerRow], min(140, 18 * (int)ceil($characters / max(8, $sheet['widths'][$index] - 5))));
        }
        $row = $headerRow + 1;
        foreach ($table['rows'] ?? [] as $values) {
            $height = 40;
            $cells = [];
            foreach ($headers as $index => $header) {
                $value = $values[$index] ?? null;
                $style = is_int($value) || is_float($value) ? 5 : 0;
                if (preg_match('/(^ID\b|\bIDs\b|chave|número de origem)/iu', $header)) {
                    $value = $value === null ? null : (string)$value;
                    $style = 14;
                } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?$/D', $value)) {
                    $style = strlen($value) === 10 ? 8 : 7;
                    $value = $this->excelDate($value);
                } elseif (preg_match('/^Valor (mensal|Faturado)/u', $header) && (is_int($value) || is_float($value))) {
                    $style = 17;
                }
                if (is_string($value)) {
                    $characters = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
                    $height = max($height, min(180, 18 * (int)ceil($characters / max(10, $sheet['widths'][$index] - 3))));
                }
                // Solid RGB cell fills avoid relying on application-specific
                // custom differential table rendering. Keep readable banding
                // in ordinary cell styles, including blank and numeric cells.
                $style = 18 + (($row - $headerRow - 1) % 2) * 18 + $style;
                $formula = null;
                if ($metric['id'] === 'INS10' && in_array($index, [5, 15, 16], true)) {
                    $formula = $this->monthlyStaffLookup([5 => 'Q', 15 => 'D', 16 => 'F'][$index], '$L' . $row, $index === 5 ? '"Não"' : '0');
                }
                $cells[$index + 1] = ['value' => $value, 'style' => $style, 'formula' => $formula];
            }
            // Keep serialized rows, not a million PHP cell arrays, until ZIP packaging.
            $sheet['evidence_xml'] .= $this->rowXml($row, $cells, $height);
            $sheet['last_evidence_row'] = $row++;
        }
        if ($row > $headerRow + 1) {
            $sheet['tables'][] = ['name' => 'Evidencias_' . $metric['id'],
                'ref' => 'A' . $headerRow . ':' . self::col(count($headers)) . ($row - 1), 'headers' => $headers];
            if ($metric['id'] === 'INS10') {
                $first = $headerRow + 1;
                $last = $row - 1;
                $active = '$G$' . $first . ':$G$' . $last;
                $formulas = [25 => 'COUNTIF($I$' . $first . ':$I$' . $last . ',"Sim")',
                    28 => 'COUNTIF(' . $active . ',"Sim")'];
                foreach ($formulas as $summaryRow => $formula) { $sheet['rows'][$summaryRow][2]['formula'] = $formula; }
            }
            $this->recordTotal($sheet, $row, $headerRow + 1, $row - 1, $row - $headerRow - 1, $metric['id'] === 'INS12' ? 2 : 1, true);
            if ($metric['id'] === 'INS10') {
                $daysTotal = array_sum(array_column($table['rows'], 15));
                $billedValueTotal = round(array_sum(array_column($table['rows'], 16)), 2);
                $cells = [1 => ['value' => 'Totais faturados (registros visíveis)', 'style' => 4, 'formula' => null],
                    16 => ['value' => $daysTotal, 'style' => 5, 'formula' => 'SUBTOTAL(109,P' . $first . ':P' . $last . ')'],
                    17 => ['value' => $billedValueTotal, 'style' => 17, 'formula' => 'SUBTOTAL(109,Q' . $first . ':Q' . $last . ')']];
                $sheet['evidence_xml'] .= $this->rowXml($row + 1, $cells, 40);
                $sheet['last_evidence_row'] = $row + 1;
            }
        } else {
            $this->recordTotal($sheet, $row, $row, $row - 1, 0);
            $this->merged($sheet, $row + 1, $isCall ? 'Nenhuma ligação atende a esta condição. A base completa de cálculo continua disponível no link acima.' : 'Nenhum registro disponível para este indicador nesta extração.', 9, 7, 44);
        }
        // A native table supplies column filters and retains its own header identity
        // while selected. Freezing all preceding summary rows would obscure records.
        $sheet['filter'] = '';
        $this->put($sheet, 4, 1, 'Ver registros detalhados ↓', 4);
        $sheet['links'][] = ['ref' => 'A4', 'location' => "'" . $sheet['name'] . "'!A" . $headerRow];
        $this->put($sheet, $start, 8, 'Voltar à medição ↑', 4);
        $sheet['links'][] = ['ref' => 'H' . $start, 'location' => "'" . $sheet['name'] . "'!A1"];
    }

    /** Count visible records; IDs, dates, ratings and monetary rates must not be added. */
    private function recordTotal(array &$sheet, int $row, int $first, int $last, int $count, int $idColumn = 1, bool $serialized = false): void
    {
        $column = self::col($idColumn);
        $cells = [
            1 => ['value' => 'Total geral (registros)', 'style' => 4, 'formula' => null],
            2 => ['value' => $count, 'style' => 5,
                'formula' => $last >= $first ? 'SUBTOTAL(103,' . $column . $first . ':' . $column . $last . ')' : null],
        ];
        if ($serialized) {
            // The footer must follow the streamed body, not precede it in sheetData.
            $sheet['evidence_xml'] .= $this->rowXml($row, $cells, 36);
            $sheet['last_evidence_row'] = $row;
        } else {
            $sheet['rows'][$row] = $cells;
            $sheet['heights'][$row] = 36;
        }
    }

    /** Merged captions may extend beyond the last row containing a cell. */
    private function lastOccupiedRow(array $sheet): int
    {
        $last = max($sheet['rows'] ? max(array_keys($sheet['rows'])) : 0, $sheet['last_evidence_row']);
        foreach ($sheet['merges'] as $range) {
            if (preg_match('/\d+$/D', $range, $match)) { $last = max($last, (int)$match[0]); }
        }
        foreach ($sheet['charts'] as $chart) { $last = max($last, (int)($chart['anchor'][3] ?? 0) + 1); }
        return $last;
    }

    private function daily(array &$sheet, array $m, array $source, int $start = 21): void
    {
        $this->merged($sheet, $start, 'Distribuição diária da população do indicador', 2);
        $labels = MetricEvidence::labels($m['id']);
        $headerRow = $start + 2;
        $this->headers($sheet, $headerRow, ['Data de referência', $labels['numerator'], $labels['denominator'], 'Resultado diário']);
        $sources = [$source];
        if ($m['id'] === 'INS12' && ($this->snapshot['input']['ins12_mode'] ?? 'glpi_and_phone') === 'glpi_and_phone') {
            $sources[] = $this->phoneIns12Columns;
        }
        $daily = [];
        foreach ($sources as $dailySource) {
            $isTicket = $dailySource['sheet'] === 'Base de Dados';
            $dateKey = $isTicket ? 'solved_at' : 'entered_at';
            $rows = $this->snapshot[$isTicket ? 'tickets' : 'calls'];
            foreach ($rows as $item) {
                $key = $isTicket ? (string)$item['id'] : (string)($item['call_key'] ?? ($item['id'] . '|' . $item['queue']));
                $day = substr((string)($item[$dateKey] ?? ''), 0, 10);
                if ($day === '') { continue; }
                if (!isset($daily[$day])) { $daily[$day] = [0, 0]; }
                $daily[$day][0] += isset($this->evidence[$m['id']]['num'][$key]) ? 1 : 0;
                $daily[$day][1] += isset($this->evidence[$m['id']]['den'][$key]) ? 1 : 0;
            }
        }
        ksort($daily);
        $firstRow = $headerRow + 1;
        $r = $firstRow;
        foreach ($daily as $day => $counts) {
            $this->put($sheet, $r, 1, $this->excelDate($day), 8);
            foreach (['num', 'den'] as $i => $part) {
                $terms = [];
                foreach ($sources as $dailySource) {
                    $dates = $this->range($dailySource['sheet'], $dailySource['date'], $dailySource['rows']);
                    $sumRange = $this->range($dailySource['sheet'], $dailySource[$part], $dailySource['rows']);
                    $terms[] = 'SUMIFS(' . $sumRange . ',' . $dates . ',">="&A' . $r . ',' . $dates . ',"<"&A' . $r . '+1)';
                }
                $formula = implode('+', $terms);
                $this->put($sheet, $r, $i + 2, $counts[$i], 5, $formula);
            }
            $this->put($sheet, $r, 4, $counts[1] ? $counts[0] / $counts[1] : 'N/A', 6, 'IF(C' . $r . '=0,"N/A",B' . $r . '/C' . $r . ')');
            $r++;
        }
        $sheet['filter'] = 'A' . $headerRow . ':D' . max($headerRow, $r - 1);
        $num = array_sum(array_column($daily, 0));
        $den = array_sum(array_column($daily, 1));
        $this->put($sheet, $r, 1, 'Total geral', 4);
        $this->put($sheet, $r, 2, $num, 5, $r > $firstRow ? 'SUM(B' . $firstRow . ':B' . ($r - 1) . ')' : null);
        $this->put($sheet, $r, 3, $den, 5, $r > $firstRow ? 'SUM(C' . $firstRow . ':C' . ($r - 1) . ')' : null);
        $this->put($sheet, $r, 4, $den > 0 ? $num / $den : 'N/A', 6, 'IF(C' . $r . '=0,"N/A",B' . $r . '/C' . $r . ')');
        $sheet['heights'][$r] = 36;
    }

    /** Short display labels; column order, source meanings and stored values are unchanged. */
    private function compactHeaders(array $headers): array
    {
        $labels = [
            'Código da prioridade GLPI' => 'Prioridade (código)',
            'Categoria ID' => 'Categoria (ID)',
            'Prazo armazenado no GLPI' => 'Prazo GLPI',
            'Tempo útil armazenado no GLPI' => 'Tempo útil GLPI',
            'Espera armazenada no GLPI' => 'Espera GLPI',
            'Categoria elegível N1' => 'Categoria N1',
            'Categoria da base de conhecimento' => 'Categoria BC (ID)',
            'Categoria BC ID' => 'Categoria BC (ID)',
            'Possui grupo técnico atribuído' => 'Grupo técnico atribuído',
            'Elegível INS6 após excluir N3 (1=sim)' => 'INS6 elegível, sem N3 (1=sim)',
            'Escopo INS4 N1 ou N2 sem N3 (1=sim)' => 'INS4 N1/N2, sem N3 (1=sim)',
            'Motivo de exclusão do escopo INS4' => 'INS4: motivo da exclusão',
            'Prazo para solução registrado' => 'Prazo GLPI preenchido',
            'Calendário apurado ID' => 'Calendário apurado (ID)',
            'Tempo no calendário — auditoria (s)' => 'Tempo calendário (s; auditoria)',
            'Suspensão no calendário — auditoria (s)' => 'Suspensão calendário (s; auditoria)',
            'Tempo de solução considerado no relatório (s)' => 'Tempo de solução apurado (s)',
            'Limite superior do tempo líquido (s)' => 'Tempo líquido: limite superior (s)',
            'Tempo útil armazenado GLPI (s)' => 'Tempo útil GLPI (s)',
            'Espera geral armazenada GLPI (s)' => 'Espera geral GLPI (s)',
            'Espera SLA armazenada GLPI (s)' => 'Espera SLA GLPI (s)',
            'Método de apuração SLA' => 'Método SLA',
            'Justificativa da apuração SLA' => 'Justificativa SLA',
            'Reconstrução por histórico utilizada' => 'Histórico usado no SLA',
            'Conformidade pelo prazo armazenado' => 'Conformidade do prazo GLPI',
            'Divergência frente ao prazo armazenado' => 'Diverge do prazo GLPI',
            'Observações de auditoria SLA' => 'Auditoria SLA',
            'Prazo calculado pelo SLA — auditoria' => 'Prazo SLA calculado (auditoria)',
            'Tempo corrido desde a abertura original (s)' => 'Tempo desde abertura original (s)',
            'Tempo corrido menos espera geral (s)' => 'Tempo corrido − espera geral (s)',
            'Tempo líquido operacional — auditoria (s)' => 'Tempo operacional líquido (s; auditoria)',
            'Conformidade operacional — auditoria' => 'Conformidade operacional (auditoria)',
            'Chamados reabertos por erros ou execução incompleta' => 'Reabertos / execução incompleta',
            'Quantidade total de chamados solucionados' => 'Chamados solucionados',
            'Chamados solucionados no 1º nível' => 'Solucionados no 1º nível',
            'Quantidade total de chamados elegíveis' => 'Chamados elegíveis',
            'Ligações atendidas com duração de até 10 minutos' => 'Atendidas em até 10 min',
            'Quantidade total de ligações consideradas' => 'Ligações consideradas',
            'Chamados com resolução vinculada à base de conhecimento' => 'Resolução vinculada à BC',
            'Pesquisas respondidas como satisfeito ou muito satisfeito (4 ou 5)' => 'Respostas favoráveis (4–5)',
            'Quantidade de pesquisas respondidas (notas válidas de 1 a 5)' => 'Respostas válidas (1–5)',
            'Reaberturas administrativas desconsideradas' => 'Reaberturas administrativas excluídas',
            'Grupos atuais do solucionador IDs' => 'Grupos atuais do solucionador (IDs)',
            'Aceito pelo solucionador N1 sem grupo atribuído' => 'Aceito via solucionador N1 sem grupo',
            'Artigos diretamente vinculados IDs' => 'Artigos vinculados (IDs)',
            'Artigos validados encontrados IDs' => 'Artigos validados (IDs)',
            'Incluído no percentual apurado' => 'Incluído no cálculo',
            'Pertence ao recorte do indicador' => 'No recorte do indicador',
            'Chave ligação e fila' => 'Chave ligação / fila',
            'Satisfação telefônica considerada (1–5)' => 'Nota telefônica considerada (1–5)',
            'Duração da conversa válida' => 'Duração válida',
            'Espera até conexão válida' => 'Espera conexão válida',
            'ID da pesquisa telefônica' => 'Pesquisa telefônica (ID)',
            'Data da pesquisa telefônica (origem)' => 'Data da pesquisa (origem)',
            'Pesquisa: atendimento original' => 'Pesquisa: atendimento (tecla)',
            'Pesquisa: ligação representativa (1=sim)' => 'Pesquisa: ligação única (1=sim)',
            'Pesquisa: resposta registrada (1=sim)' => 'Pesquisa: respondida (1=sim)',
            'Pesquisa: resposta válida na fonte (1=sim)' => 'Pesquisa: válida na fonte (1=sim)',
            'Origem da nota telefônica considerada' => 'Origem da nota telefônica',
            'Motivo da escolha da nota telefônica' => 'Escolha da nota telefônica',
        ];
        return array_map(static function (string $header) use ($labels): string {
            if (preg_match('/^(INS\d+ · )(.*)$/u', $header, $parts)) return $parts[1] . ($labels[$parts[2]] ?? $parts[2]);
            return $labels[$header] ?? $header;
        }, $headers);
    }

    private function rawTickets(): array
    {
        $headers = ['ID', 'Título', 'Tipo', 'Status', 'Código da prioridade GLPI', 'Categoria ID', 'Categoria', 'Abertura', 'Solução', 'Fechamento', 'Prazo armazenado no GLPI', 'SLA ID', 'SLA aplicado', 'Conformidade', 'Tempo útil armazenado no GLPI', 'Tempo corrido', 'Espera armazenada no GLPI', 'Grupos técnicos', 'Técnicos', 'Localização', 'Categoria elegível N1', 'Reaberto', 'Solução recusada', 'Artigos vinculados', 'Vínculo validado', 'Categoria da base de conhecimento', 'Satisfação'];
        $widths = [13, 65, 17, 15, 18, 14, 68, 23, 23, 23, 23, 13, 35, 17, 23, 23, 23, 55, 45, 55, 18, 16, 18, 22, 22, 23, 16];
        $ids = ['INS1', 'INS2', 'INS3', 'INS4', 'INS5', 'INS6', 'INS11', 'INS12'];
        foreach ($ids as $id) {
            $this->rawColumns[$id] = ['sheet' => 'Base de Dados', 'num' => count($headers) + 1, 'den' => count($headers) + 2, 'date' => 9, 'rows' => count($this->snapshot['tickets'])];
            $labels = MetricEvidence::labels($id);
            $headers[] = $id . ' · ' . $labels['numerator']; $headers[] = $id . ' · ' . $labels['denominator'];
            $widths[] = 28; $widths[] = 28;
        }
        $headers[] = 'Grupos técnicos IDs'; $widths[] = 30;
        $this->groupPresentColumn = count($headers) + 1;
        $headers[] = 'Possui grupo técnico atribuído'; $widths[] = 22;
        $this->n3AssignedColumn = count($headers) + 1;
        foreach (['Atribuído a N3 (1=sim)', 'Grupos N3 IDs', 'Elegível INS6 após excluir N3 (1=sim)', 'Exclusões N3 por indicador'] as $header) {
            $headers[] = $header; $widths[] = 30;
        }
        $this->ins4ScopeColumn = count($headers) + 1;
        foreach (['Escopo INS4 N1 ou N2 sem N3 (1=sim)', 'Motivo de exclusão do escopo INS4', 'SLA atribuído', 'Prazo para solução registrado'] as $header) {
            $headers[] = $header; $widths[] = 32;
        }
        foreach (MetricEvidence::slaHeaders() as $header) {
            $headers[] = $header; $widths[] = preg_match('/justificativa|observações|método/iu', $header) ? 55 : 30;
        }
        foreach (['Severidade do contrato', 'Prioridade GLPI'] as $header) {
            $headers[] = $header; $widths[] = 25;
        }
        $this->temporalHeaders($headers, $widths, 'tickets', 'Solução');
        $headers = $this->compactHeaders($headers);
        $sheet = $this->sheet('Base de Dados', $widths);
        $this->title($sheet, 'Base de chamados solucionados');
        $this->merged($sheet, 5, 'Uma linha por chamado. Nas colunas dos indicadores, 1 indica participação e 0 indica não participação. Os tempos armazenados no GLPI e os tempos apurados pelo relatório aparecem separadamente. A falta de prazo preenchido não determina exclusão. INS4 considera N1 ou N2, excluindo N3.', 9, 10, 52);
        $this->headers($sheet, 6, $headers);
        $r = 7;
        foreach ($this->snapshot['tickets'] as $t) {
            $type = is_numeric($t['type'] ?? null) ? ((int)$t['type'] === 1 ? 'Incidente' : 'Requisição') : ($t['type'] ?? '');
            $status = [5 => 'Solucionado', 6 => 'Fechado'][(int)($t['status'] ?? 0)] ?? (string)($t['status'] ?? '');
            $values = [(string)$t['id'], $t['title'] ?? '', $type, $status, $t['priority'] ?? null, $t['category_id'] ?? null, $t['category'] ?? '', $this->excelDate($t['opened_at'] ?? null), $this->excelDate($t['solved_at'] ?? null), $this->excelDate($t['closed_at'] ?? null), $this->excelDate($t['deadline'] ?? null), $t['sla_id'] ?? null, $t['sla_name'] ?? '', MetricEvidence::slaClassification($t), $this->duration($t['solve_seconds'] ?? null), $this->duration($t['elapsed_seconds'] ?? null), $this->duration($t['waiting_seconds'] ?? null), $t['groups'] ?? '', $t['technicians'] ?? '', $t['location'] ?? '', !empty($t['n1_eligible']) ? 'Sim' : 'Não', !empty($t['reopened']) ? 'Sim' : 'Não', !empty($t['refused_solution']) ? 'Sim' : 'Não', implode(', ', $t['kb_ids'] ?? []), !empty($t['kb_validated']) ? 'Sim' : 'Não', $t['kb_category_id'] ?? null, $t['satisfaction'] ?? null];
            foreach ($ids as $id) {
                $values[] = isset($this->evidence[$id]['num'][(string)$t['id']]) ? 1 : 0;
                $values[] = isset($this->evidence[$id]['den'][(string)$t['id']]) ? 1 : 0;
            }
            $values[] = implode(', ', $t['group_ids'] ?? []);
            $values[] = empty($t['group_ids']) ? 0 : 1;
            $values[] = empty($t['assigned_n3']) ? 0 : 1;
            $values[] = implode(', ', $t['n3_group_ids'] ?? []);
            $values[] = empty($t['ins6_eligible']) ? 0 : 1;
            $excludedFrom = [];
            foreach (['INS3', 'INS4', 'INS6'] as $id) {
                if (isset($this->evidence[$id]['excluded'][(string)$t['id']])) { $excludedFrom[] = $id; }
            }
            $values[] = implode(', ', $excludedFrom);
            $values[] = empty($t['ins4_scope']) ? 0 : 1;
            $values[] = $t['ins4_exclusion_reason'] ?? '';
            $values[] = (int)($t['sla_id'] ?? 0) > 0 ? 'Sim' : 'Não';
            $stored = array_key_exists('sla_stored_within', $t) ? $t['sla_stored_within'] : ($t['within_sla'] ?? null);
            $values[] = $stored === null ? 'Não' : 'Sim';
            $values = array_merge($values, MetricEvidence::slaValues($t));
            $values[] = MetricEvidence::contractSeverity($t['priority'] ?? null);
            $values[] = MetricEvidence::priorityName($t['priority'] ?? null);
            foreach ($values as $i => $value) {
                $col = $i + 1;
                $style = $col === 1 ? 14 : (in_array($col, [8, 9, 10, 11], true) ? 7 : (in_array($col, [15, 16, 17], true) ? 10 : (is_numeric($value) && !is_string($value) ? 5 : 0)));
                $this->put($sheet, $r, $col, $value, $style);
            }
            $this->temporalHelpers($sheet, $r, 'tickets', $t['solved_at'] ?? null);
            $sheet['evidence_xml'] .= $this->rowXml($r, $sheet['rows'][$r], 42);
            unset($sheet['rows'][$r]);
            $sheet['last_evidence_row'] = $r++;
        }
        $sheet['filter'] = 'A6:' . self::col(count($headers)) . max(6, $r - 1);
        $this->recordTotal($sheet, max(8, $r), 7, $r - 1, count($this->snapshot['tickets']), 1, true);
        return $sheet;
    }

    private function rawCalls(): array
    {
        $headers = ['ID da ligação', 'Chave ligação e fila', 'Fila', 'Entrada na fila', 'Conexão', 'Finalização', 'Abandono', 'Número de origem', 'Tipo', 'Evento de finalização', 'Agente', 'Duração da conversa', 'Espera até conexão', 'Espera no abandono', 'Satisfação telefônica considerada (1–5)'];
        $widths = [27, 48, 28, 24, 24, 24, 24, 23, 22, 28, 25, 23, 23, 23, 28];
        foreach (['INS7', 'INS8', 'INS9'] as $id) {
            $this->rawColumns[$id] = ['sheet' => 'Base de Dados Ligações', 'num' => count($headers) + 1, 'den' => count($headers) + 2, 'date' => 4, 'rows' => count($this->snapshot['calls'])];
            $labels = MetricEvidence::labels($id);
            $headers[] = $id . ' · ' . $labels['numerator']; $headers[] = $id . ' · ' . $labels['denominator'];
            $widths[] = 28; $widths[] = 28;
        }
        foreach (['talk' => 'Duração da conversa válida', 'wait' => 'Espera até conexão válida', 'abandon' => 'Espera no abandono válida'] as $key => $label) {
            $this->callValidityColumns[$key] = count($headers) + 1;
            $headers[] = $label; $widths[] = 22;
        }
        foreach (['Pesquisa: atendimento original', 'ID da pesquisa telefônica', 'Data da pesquisa telefônica (origem)'] as $header) {
            $headers[] = $header; $widths[] = 30;
        }
        $this->phoneIns12Columns = ['sheet' => 'Base de Dados Ligações', 'num' => count($headers) + 1,
            'den' => count($headers) + 2, 'score' => count($headers) + 3, 'date' => 4, 'rows' => count($this->snapshot['calls'])];
        $ins12Labels = MetricEvidence::labels('INS12');
        foreach (['INS12 · ' . $ins12Labels['numerator'], 'INS12 · ' . $ins12Labels['denominator'], 'INS12 nota considerada (1–5)'] as $header) {
            $headers[] = $header; $widths[] = 28;
        }
        foreach (['representative' => 'Pesquisa: ligação representativa (1=sim)',
                  'response' => 'Pesquisa: resposta registrada (1=sim)',
                  'valid' => 'Pesquisa: resposta válida na fonte (1=sim)'] as $key => $header) {
            $this->phoneSurveyColumns[$key] = count($headers) + 1;
            $headers[] = $header; $widths[] = 29;
        }
        $this->temporalHeaders($headers, $widths, 'calls', 'Entrada');
        // Append the score-source audit after every existing column, including
        // temporal helpers, so formulas and historical column addresses remain stable.
        $surveySourceColumn = count($headers) + 1;
        foreach (['Pesquisa: nota registrada', 'Origem da nota considerada', 'Motivo da escolha da nota'] as $header) {
            $headers[] = $header; $widths[] = 36;
        }
        $headers = $this->compactHeaders($headers);
        $sheet = $this->sheet('Base de Dados Ligações', $widths);
        $this->title($sheet, 'Base de ligações consideradas');
        $this->merged($sheet, 5, 'Tempos individuais em horas, minutos e segundos. Ligações de teste excluídas: ' . ($this->snapshot['totals']['calls_excluded'] ?? 0) . '. Identificadores e telefones são armazenados como texto. '
            . (($this->snapshot['input']['ins12_mode'] ?? 'glpi_and_phone') === 'glpi_and_phone'
                ? 'INS12 inclui respostas telefônicas válidas, uma por ID da ligação. Colunas de evidência indicam a linha considerada; outras filas da mesma ligação não duplicam a resposta.'
                : 'INS12 considera somente o GLPI nesta extração. Respostas telefônicas são preservadas como evidência; sua participação nas respostas favoráveis e na base de cálculo é zero.'), 9, 10, 64);
        $this->headers($sheet, 6, $headers);
        $survey = $this->snapshot['phone_survey'] ?? [];
        $representatives = array_fill_keys(array_map('strval', $survey['representative_call_keys'] ?? []), true);
        $validResponses = array_fill_keys(array_map('strval', $survey['valid_call_keys'] ?? []), true);
        $r = 7;
        foreach ($this->snapshot['calls'] as $c) {
            $key = (string)($c['call_key'] ?? ($c['id'] . '|' . $c['queue']));
            $values = [(string)$c['id'], $key, $c['queue'] ?? '', $this->excelDate($c['entered_at'] ?? null), $this->excelDate($c['connected_at'] ?? null), $this->excelDate($c['completed_at'] ?? null), $this->excelDate($c['abandoned_at'] ?? null), (string)($c['caller'] ?? ''), $c['type'] ?? '', $c['termination'] ?? '', $c['agent'] ?? '', $this->duration($c['talk_seconds'] ?? null), $this->duration($c['wait_seconds'] ?? null), $this->duration($c['abandon_wait_seconds'] ?? null), PhoneSurveySummary::normalizedRating($c)];
            foreach (['INS7', 'INS8', 'INS9'] as $id) {
                $values[] = isset($this->evidence[$id]['num'][$key]) ? 1 : 0;
                $values[] = isset($this->evidence[$id]['den'][$key]) ? 1 : 0;
            }
            foreach (['talk_seconds', 'wait_seconds', 'abandon_wait_seconds'] as $field) {
                $value = $c[$field] ?? null;
                $values[] = is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 ? 1 : 0;
            }
            $values[] = $c['satisfaction_raw'] ?? null;
            $values[] = $c['survey_id'] ?? null;
            $values[] = $c['survey_at'] ?? null;
            $values[] = isset($this->evidence['INS12']['num'][$key]) ? 1 : 0;
            $values[] = isset($this->evidence['INS12']['den'][$key]) ? 1 : 0;
            $values[] = $c['ins12_score'] ?? null;
            $representative = isset($representatives[$key]);
            $values[] = $representative ? 1 : 0;
            $values[] = $representative && !empty($survey['available']) && PhoneSurveySummary::hasResponse($c) ? 1 : 0;
            $values[] = isset($validResponses[$key]) ? 1 : 0;
            foreach ($values as $i => $value) {
                $col = $i + 1;
                $style = in_array($col, [1, 2, 8], true) ? 14 : (in_array($col, [4, 5, 6, 7], true) ? 7 : (in_array($col, [12, 13, 14], true) ? 10 : (is_numeric($value) && !is_string($value) ? 5 : 0)));
                $this->put($sheet, $r, $col, $value, $style);
            }
            $this->temporalHelpers($sheet, $r, 'calls', $c['entered_at'] ?? null);
            foreach ([$c['satisfaction_nota_raw'] ?? null, PhoneSurveySummary::sourceLabel($c), $c['satisfaction_reason'] ?? ''] as $i => $value) {
                $this->put($sheet, $r, $surveySourceColumn + $i, $value, 0);
            }
            $sheet['evidence_xml'] .= $this->rowXml($r, $sheet['rows'][$r], 30);
            unset($sheet['rows'][$r]);
            $sheet['last_evidence_row'] = $r++;
        }
        $sheet['filter'] = 'A6:' . self::col(count($headers)) . max(6, $r - 1);
        $this->recordTotal($sheet, max(8, $r), 7, $r - 1, count($this->snapshot['calls']), 1, true);
        return $sheet;
    }

    /** Append helpers so the existing raw evidence columns keep their addresses. */
    private function temporalHeaders(array &$headers, array &$widths, string $source, string $label): void
    {
        $this->temporalColumns[$source] = ['original' => count($headers) + 1, 'valid' => count($headers) + 2, 'date' => count($headers) + 3, 'weekday' => count($headers) + 4, 'hour' => count($headers) + 5];
        foreach ([$label . ' original (texto)', $label . ' válida na competência', $label . ' temporal (segundo inteiro)', 'Dia da semana temporal (1=Seg)', 'Hora temporal (0–23)'] as $header) {
            $headers[] = $header; $widths[] = 29;
        }
    }

    private function temporalHelpers(array &$sheet, int $row, string $source, $original): void
    {
        $columns = $this->temporalColumns[$source];
        $parts = HeatmapData::timestampParts($original, $this->snapshot['period']['month']);
        $date = self::col($columns['date']) . $row;
        $text = self::col($columns['original']) . $row;
        $valid = self::col($columns['valid']) . $row;
        [$year, $month] = array_map('intval', explode('-', $this->snapshot['period']['month']));
        $gate = 'AND(' . $valid . '=1,ISNUMBER(' . $date . '),' . $date . '>=DATE(' . $year . ',' . $month . ',1),' . $date . '<DATE(' . $year . ',' . ($month + 1) . ',1))';
        $this->put($sheet, $row, $columns['original'], (string)($original ?? ''), 14);
        $this->put($sheet, $row, $columns['valid'], $parts === null ? 0 : 1, 5);
        // Rebuild the recorded second from source text: a microsecond near midnight
        // must not round into the next date in a 15-digit spreadsheet serial.
        $rebuilt = 'DATE(VALUE(LEFT(' . $text . ',4)),VALUE(MID(' . $text . ',6,2)),VALUE(MID(' . $text . ',9,2)))+TIME(VALUE(MID(' . $text . ',12,2)),VALUE(MID(' . $text . ',15,2)),VALUE(MID(' . $text . ',18,2)))';
        $this->put($sheet, $row, $columns['date'], $parts === null ? '' : $this->excelDate(substr((string)$original, 0, 19)), 7, 'IFERROR(IF(' . $valid . '=1,' . $rebuilt . ',""),"")');
        $this->put($sheet, $row, $columns['weekday'], $parts === null ? 0 : $parts['weekday'] + 1, 5, 'IFERROR(IF(' . $gate . ',WEEKDAY(' . $date . ',2),0),0)');
        $this->put($sheet, $row, $columns['hour'], $parts === null ? -1 : $parts['hour'], 5, 'IFERROR(IF(' . $gate . ',HOUR(' . $date . '),-1),-1)');
    }

    private function temporal(): array
    {
        $sheet = $this->sheet('Distribuição Temporal', [13, 17, 17, 17, 17, 17, 17, 17]);
        $sheet['freeze'] = 3;
        $this->title($sheet, 'Distribuição temporal da operação', 8);
        $this->merged($sheet, 5, 'Chamados solucionados em cada dia do mês', 2, 8);
        $definition = $this->chartData['tickets_daily'];
        $this->attachComposition($sheet, $definition, 93, 1, [0, 5, 8, 23]);
        if (empty($definition['available'])) { $this->merged($sheet, 10, 'Sem datas de solução válidas na competência para construir o gráfico.', 13, 8, 48); }
        $this->merged($sheet, 25, $definition['note'], 9, 8, 44);
        $this->heatmap($sheet, $this->heatmapData['tickets_solution'], 28);
        $this->heatmap($sheet, $this->heatmapData['calls_entry'], 60);
        $this->merged($sheet, 92, 'Dados do gráfico diário vinculados à base de chamados', 2, 8);
        return $sheet;
    }

    private function heatmap(array &$sheet, array $map, int $start): void
    {
        $this->merged($sheet, $start, $map['title'], 2, 8);
        $this->headers($sheet, $start + 1, array_merge(['Hora local'], $map['column_labels']));
        $source = $map['source'];
        $rawSheet = $source === 'tickets' ? 'Base de Dados' : 'Base de Dados Ligações';
        $count = count($this->snapshot[$source]);
        $weekdays = $this->range($rawSheet, $this->temporalColumns[$source]['weekday'], $count);
        $hours = $this->range($rawSheet, $this->temporalColumns[$source]['hour'], $count);
        $first = $start + 2;
        $last = $first + 23;
        foreach ($map['row_labels'] as $hour => $label) {
            $row = $first + $hour;
            $this->put($sheet, $row, 1, $label, 4);
            foreach ($map['column_labels'] as $weekday => $day) {
                $formula = 'COUNTIFS(' . $weekdays . ',' . ($weekday + 1) . ',' . $hours . ',' . $hour . ')';
                $this->put($sheet, $row, $weekday + 2, $map['values'][$hour][$weekday], 15, $formula);
            }
            $sheet['heights'][$row] = 25;
        }
        $range = 'B' . $first . ':H' . $last;
        if ($map['available']) { $sheet['conditional'][] = $range; }
        $this->put($sheet, $last + 1, 1, 'Total geral', 4);
        foreach ($map['column_labels'] as $weekday => $day) {
            $column = self::col($weekday + 2);
            $this->put($sheet, $last + 1, $weekday + 2, array_sum(array_column($map['values'], $weekday)), 5, 'SUM(' . $column . $first . ':' . $column . $last . ')');
        }
        $this->put($sheet, $last + 2, 1, 'Total no mapa', 4);
        $this->put($sheet, $last + 2, 2, $map['total'], 5, 'SUM(' . $range . ')');
        $this->put($sheet, $last + 2, 4, 'Fora do mapa', 4);
        $this->put($sheet, $last + 2, 5, $map['omitted'], 5, 'COUNTA(' . $this->range($rawSheet, 1, $count) . ')-B' . ($last + 2));
        $this->put($sheet, $last + 3, 1, 'Escala', 4);
        $this->put($sheet, $last + 3, 2, 0, 15);
        $this->put($sheet, $last + 3, 3, 'até', 9);
        $this->put($sheet, $last + 3, 4, $map['maximum'], $map['maximum'] > 0 ? 16 : 15, 'MAX(' . $range . ')');
        $this->put($sheet, $last + 3, 5, 'Máximo deste mapa', 9);
        $sheet['merges'][] = 'E' . ($last + 3) . ':H' . ($last + 3);
        $note = $map['note'] . ' Branco = zero. A intensidade do azul indica volume, sem avaliar cumprimento de SLA.';
        if (!$map['available']) { $note = 'Sem dados temporais válidos nesta competência. ' . $note; }
        $this->merged($sheet, $last + 4, $note, $map['available'] ? 9 : 13, 8, 55);
    }

    private function methodology(): array
    {
        $sheet = $this->sheet('Metodologia', [38, 38, 23, 23, 23, 23, 23]);
        $this->title($sheet, 'Metodologia e informações da extração');
        $input = $this->snapshot['input'] ?? [];
        $details = [
            ['Versão das regras', $this->snapshot['rules_version'] ?? ''],
            ['Início inclusivo', OfficePackage::date($this->snapshot['period']['start'])],
            ['Fim exclusivo', OfficePackage::date($this->snapshot['period']['end_exclusive'])],
            ['Base de chamados', 'Data de solução; inclui chamados abertos antes da competência.'],
            ['Base de telefonia', 'Entrada na fila; eventos posteriores ao fim do mês podem concluir a ligação.'],
            ['Entidade GLPI', (string)($input['entity_id'] ?? '')],
            ['Grupos técnicos', OfficePackage::selectionLabels($this->snapshot, 'groups', $input['group_ids'] ?? [], 'Todos os grupos da entidade selecionada')],
            ['Fila', (string)($input['queue_filter'] ?? '')],
            ['Horário de referência', (string)($input['timezone'] ?? 'America/Sao_Paulo')],
            ['Associação INS e prioridade GLPI', json_encode($input['priority_map'] ?? [], JSON_UNESCAPED_UNICODE)],
            ['Categorias elegíveis N1', implode(', ', $input['n1_categories'] ?? [])],
            ['Grupos N1', OfficePackage::selectionLabels($this->snapshot, 'groups', $input['n1_group_ids'] ?? [], 'Não informado')],
            ['Grupos N3 excluídos de INS3, INS4 e INS6', OfficePackage::selectionLabels($this->snapshot, 'groups', $input['n3_group_ids'] ?? [], 'Nenhum informado')],
            ['Escopo da exclusão N3', 'Atribuição atual a qualquer grupo N3 selecionado, inclusive chamados com N1 e N3 simultaneamente. Registros fora da base de cálculo de INS3, INS4 e INS6; permanecem na base detalhada e nos demais indicadores. A atribuição atual não comprova histórico de atendimento.'],
            ['Escopo do INS4', 'Chamados da prioridade selecionada com vínculo técnico atual a N1 ou N2, sem vínculo a N3. Não exige categoria elegível N1.'],
            ['Grupos N2', OfficePackage::selectionLabels($this->snapshot, 'groups', $input['n2_group_ids'] ?? [], 'Não informado')],
            ['Apuração do SLA', 'Usa o prazo registrado do SLA aplicável quando disponível. Na ausência de um prazo utilizável, calcula o prazo ou o tempo líquido de solução a partir do SLA, do calendário e dos registros operacionais de espera. O histórico detalhado auxilia a reconstrução e a auditoria; sua ausência isolada não impede medir um chamado. Casos sem evidência suficiente permanecem identificados separadamente.'],
            ['Cumprimento por limite superior', 'Se todo o tempo entre abertura e solução no calendário identificado já cabe na meta, suspensões não podem aumentar esse tempo. O cumprimento pode ser comprovado mesmo sem todas as pausas; a evidência apresenta o limite superior e conserva vazios os tempos exatos que não foram reconstruídos.'],
            ['Evidência original e reconstrução', 'As tabelas dos indicadores de prazo e a Base de Dados preservam o prazo e os contadores originais do GLPI, separados do prazo calculado, limite, calendário e tempos apurados. O método aplicado e a justificativa acompanham cada registro. Observações sobre clonagem, alterações ou divergências são evidências de auditoria e não excluem automaticamente o chamado.'],
            ['Modo INS6', (string)($input['ins6_mode'] ?? '')],
            ['Critério INS11', OfficePackage::kbCriterion($this->snapshot)],
            ['Fontes do INS12', $this->ins12ModeLabel()],
            ['Agregação do INS12', 'O resultado é a soma das respostas favoráveis dividida pela soma das respostas válidas das fontes incluídas, sem média entre percentuais. Notas consideradas 4 e 5 são favoráveis na escala 1–5. ' . PhoneSurveySummary::scoreRuleText() . ' Uma resposta por ID da ligação; a primeira entrada na fila, com desempate pela chave ligação e fila, identifica a linha representativa. A mesma pessoa pode responder em ambos os canais; não há deduplicação por pessoa ou chamado entre canais.'],
            ['Artigos validados informados', implode(', ', $input['validated_kb_ids'] ?? [])],
            ['Execução incompleta informada', implode(', ', $input['incomplete_ticket_ids'] ?? [])],
            ['Exclusão de testes', 'Correspondência exata do número de origem normalizado. Quantidade excluída: ' . ($this->snapshot['totals']['calls_excluded'] ?? 0) . '. A aba Auditoria de exclusões preserva a lista aplicada e as ligações excluídas.'],
            ['Dados e fórmulas', 'As bases preservam uma linha por chamado ou ligação e fila. Cada aba INS já contém somente os registros do seu recorte, incluindo resultados desfavoráveis; nos indicadores de SLA, também inclui casos aplicáveis ainda não apurados. INS7–INS9 destacam inicialmente somente ligações que atendem à condição medida, com acesso à base completa de ligações no link de cada aba; seus totais filtrados não substituem a base da fórmula. INS12 apresenta somente respostas válidas das fontes selecionadas. As bases gerais e a auditoria preservam os demais registros. As fórmulas somam as evidências e calculam a razão; o painel referencia as abas INS.'],
            ['Profissionais faturados / INS10', 'Profissionais sem a marcação Não Faturar e com dias faturados positivos na competência compõem a base do INS10. As saídas consideradas pertencem à competência e aos dois meses anteriores. Na coluna Não Faturar, Sim indica exclusão e Não mantém a participação. O valor faturado corresponde ao valor mensal × dias faturados ÷ dias corridos do mês, arredondado a centavos por profissional. A contagem automática inclui admissão e saída; dias informados têm precedência na competência. O status indica ausência/presença de data de saída, sem alterar a participação histórica. O cadastro completo permanece na Auditoria de exclusões.'],
            ['Distribuição temporal', 'Volume acumulado por hora e dia da semana, com escalas independentes em cada mapa. Chamados pela solução e ligações pela entrada, no horário local registrado. As colunas temporais ao final das bases preservam o texto original e a validação; a data temporal é reconstruída por fórmula a partir do texto original em segundos inteiros, evitando arredondamento dos microssegundos na virada do dia. Dia da semana e hora usam essa data temporal.'],
            ['Gráficos de composição', 'Percentuais nos gráficos de pizza e rosca descrevem a composição exibida. As tabelas mantêm as quantidades exatas, inclusive fatias pequenas e zeros; o resultado de cada INS aparece na medição.'],
            ['Dados ausentes', 'Pendente indica informação necessária ausente; N/A indica ausência de registros elegíveis na base de cálculo. Zero só é apresentado quando houve medição.'],
            ['Referências', 'Edital PE 90067/2024; alinhamento operacional de 31/07/2026; configuração da extração.'],
        ];
        $details = array_merge($details, MetricEvidence::calendarReferenceRows($this->snapshot));
        $details[] = ['Segmentos e exceções preservados', 'Os horários dos segmentos prevalecem sobre o nome do calendário. As quantidades de feriados/exceções referem-se ao catálogo vinculado, não somente à competência. A referência usada é preservada nesta extração.'];
        if (isset($this->snapshot['phone_survey'])) {
            $survey = $this->snapshot['phone_survey'];
            $details[] = ['Pesquisa telefônica / INS12', (string)($survey['status'] ?? '')];
            $details[] = ['Critério de participação', (string)($survey['explanation'] ?? '')];
            $details[] = ['Origem das notas telefônicas nesta competência', (string)($survey['resolution']['explanation'] ?? 'Critério preservado na extração original.')];
            $details[] = ['Providências da pesquisa telefônica', (string)($survey['actions'] ?? '')];
            $details[] = ['Escopo da pesquisa telefônica', 'Respostas vinculadas às ligações do período após exclusões de teste, deduplicadas pelo ID da ligação. Não representa todas as pesquisas da central. Contagens e evidências estão na aba INS12 e na Base de Dados Ligações.'];
        }
        $r = 5;
        foreach ($details as $detail) {
            foreach ($this->textRows($detail[1]) as $part => $line) {
                $this->put($sheet, $r, 1, $detail[0] . ($part > 0 ? ' (continuação)' : ''), 4);
                $this->put($sheet, $r, 2, $line, 9);
                $sheet['merges'][] = 'B' . $r . ':G' . $r;
                $sheet['heights'][$r++] = min(300, max(42, 20 + (int)ceil(strlen($line) / 150) * 16));
            }
        }
        foreach (['rule_notes' => 'Regras da medição', 'warnings' => 'Limitações da apuração'] as $key => $label) {
            if (empty($this->snapshot[$key])) { continue; }
            $this->merged($sheet, ++$r, $label, 2);
            foreach ($this->snapshot[$key] as $note) {
                $this->merged($sheet, ++$r, $note, 9, 7, 52);
            }
        }
        foreach (['executive_summary' => 'Análise executiva', 'actions' => 'Ações corretivas e melhorias', 'notes' => 'Observações gerenciais'] as $key => $label) {
            $text = trim((string)($this->snapshot['manual'][$key] ?? ''));
            if ($text !== '') {
                $this->merged($sheet, ++$r, $label, 2);
                foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
                    if (trim($line) !== '') { $this->merged($sheet, ++$r, $line, 9, 7, min(300, 40 + (int)(strlen($line) / 160) * 15)); }
                }
            }
        }
        if (!empty($this->snapshot['selection_catalog'])) {
            foreach ([['categories', 'n1_categories', 'Categorias elegíveis N1', 'Categoria de chamado'],
                      ['articles', 'validated_kb_ids', 'Artigos previamente validados informados', 'Título do artigo']] as $selection) {
                if (empty($input[$selection[1]])) { continue; }
                $this->merged($sheet, ++$r, $selection[2], 2);
                $note = $selection[0] === 'categories'
                    ? 'IDs e nomes da base GLPI do relatório, preservados na extração. O grupo configurado na categoria não substitui a atribuição efetiva do chamado.'
                    : 'IDs de artigos; não são IDs de categorias da base de conhecimento. A existência no catálogo não comprova aprovação ou vínculo ao chamado.';
                if ($selection[0] === 'articles' && ($input['kb_mode'] ?? '') === 'category_proxy') {
                    $note .= ' A lista de artigos não participa do critério por categoria selecionado.';
                }
                $this->merged($sheet, ++$r, $note, 9, 7, 52);
                $this->put($sheet, ++$r, 1, 'ID', 3);
                $this->put($sheet, $r, 2, $selection[3], 3);
                $sheet['merges'][] = 'B' . $r . ':G' . $r;
                $selectionRows = OfficePackage::selectionRows($this->snapshot, $selection[0], $input[$selection[1]]);
                $firstSelectionRow = $r + 1;
                foreach ($selectionRows as $row) {
                    $this->put($sheet, ++$r, 1, (int)$row[0], 5);
                    $this->put($sheet, $r, 2, $row[1], 9);
                    $sheet['merges'][] = 'B' . $r . ':G' . $r;
                    $sheet['heights'][$r] = 42;
                }
                $this->recordTotal($sheet, $r + 1, $firstSelectionRow, $r, count($selectionRows));
                $r++;
            }
        }
        return $sheet;
    }

    private function exclusionAudit(): array
    {
        $sheet = $this->sheet('Auditoria de exclusões', [29, 45, 24, 27, 26, 26, 52]);
        $sheet['freeze'] = 3;
        $this->title($sheet, 'Auditoria das exceções e exclusões dos indicadores');
        $this->merged($sheet, 5, 'Índice das regras aplicadas — clique para ver os registros', 2);
        foreach ([6 => 'INS5 — reaberturas administrativas e efeito em cada chamado →',
            7 => 'INS3, INS4 e INS6 — chamados excluídos por atribuição N3 →',
            8 => 'INS6 — chamados incluídos pelo solucionador N1, sem grupo →',
            9 => 'Telefonia — ligações excluídas por número de teste →',
            10 => 'Telefonia — cadastro de números de teste aplicado →',
            11 => 'INS10 — cadastro completo, Não Faturar e participação por período →'] as $r => $label) {
            $this->merged($sheet, $r, $label, 4, 7, 30);
        }
        $sheet['links'][] = ['ref' => 'A6', 'location' => "'Exceções INS5'!A1"];
        $this->merged($sheet, 12, 'Cada seção identifica a regra e seu efeito. Inclusões complementares, exclusões da base e eventos desconsiderados são apresentados separadamente; não devem ser somados como se fossem a mesma medida.', 9, 7, 48);
        $this->merged($sheet, 14, 'Regras de números de teste preservadas na extração', 2);
        $sheet['links'][] = ['ref' => 'A10', 'location' => "'Auditoria de exclusões'!A14"];
        $this->merged($sheet, 15, 'São removidos apenas os caracteres não numéricos. A comparação usa o número inteiro, sem correspondência por sufixo ou inclusão automática de DDI. Somente regras ativas excluem ligações.', 9, 7, 48);
        $this->headers($sheet, 17, ['Número cadastrado', 'Identificação / motivo', 'Ativa na extração']);
        $row = 18;
        foreach ($this->snapshot['input']['test_numbers'] ?? [] as $rule) {
            $this->put($sheet, $row, 1, (string)($rule['number'] ?? ''), 14);
            $this->put($sheet, $row, 2, (string)($rule['label'] ?? ''), 0);
            $this->put($sheet, $row++, 3, !empty($rule['active']) ? 'Sim' : 'Não', 4);
        }
        if ($row > 18) $sheet['tables'][] = ['name' => 'Regras_testes', 'ref' => 'A17:C' . ($row - 1),
            'headers' => ['Número cadastrado', 'Identificação / motivo', 'Ativa na extração']];
        $this->recordTotal($sheet, $row, 18, $row - 1, $row - 18);
        $row++;
        $row += 2;
        $sheet['links'][] = ['ref' => 'A9', 'location' => "'Auditoria de exclusões'!A" . $row];
        $this->merged($sheet, $row++, 'Ligações excluídas dos totais e de todos os indicadores de telefonia', 2);
        $this->merged($sheet, $row++, 'Total excluído: ' . count($this->snapshot['excluded_calls'] ?? []) . '. Os registros abaixo não integram a aba Base de Dados Ligações nem as fórmulas dos indicadores.', 9, 7, 42);
        $headerRow = $row;
        $this->headers($sheet, $row++, ['ID da ligação', 'Fila', 'Entrada na fila', 'Número de origem', 'Origem normalizada', 'Regra correspondente', 'Motivo da exclusão']);
        foreach ($this->snapshot['excluded_calls'] ?? [] as $call) {
            $values = [(string)($call['id'] ?? ''), (string)($call['queue'] ?? ''), $this->excelDate($call['entered_at'] ?? null), (string)($call['caller'] ?? ''), (string)($call['caller_normalized'] ?? ''), (string)($call['test_number'] ?? ''), (string)($call['excluded_reason'] ?? 'Número de teste cadastrado')];
            foreach ($values as $index => $value) { $this->put($sheet, $row, $index + 1, $value, $index === 2 ? 7 : (in_array($index, [0, 3, 4, 5], true) ? 14 : 0)); }
            $sheet['heights'][$row++] = 40;
        }
        if ($row > $headerRow + 1) $sheet['tables'][] = ['name' => 'Ligacoes_excluidas', 'ref' => 'A' . $headerRow . ':G' . ($row - 1),
            'headers' => ['ID da ligação', 'Fila', 'Entrada na fila', 'Número de origem', 'Origem normalizada', 'Regra correspondente', 'Motivo da exclusão']];
        $this->recordTotal($sheet, $row, $headerRow + 1, $row - 1, $row - $headerRow - 1);
        $row++;
        $row += 2;
        $sheet['links'][] = ['ref' => 'A7', 'location' => "'Auditoria de exclusões'!A" . $row];
        $this->merged($sheet, $row++, 'Exclusões N3 específicas de INS3, INS4 e INS6', 2);
        $this->merged($sheet, $row++, 'Os chamados abaixo permanecem na Base de Dados e nos totais gerais. A exclusão aplica-se somente aos indicadores informados, à condição apurada e à base de cálculo. A presença atual de qualquer grupo N3 selecionado prevalece mesmo com N1 simultâneo.', 9, 7, 64);
        $this->merged($sheet, $row++, 'Grupos N3 selecionados: ' . OfficePackage::selectionLabels($this->snapshot, 'groups', $this->snapshot['input']['n3_group_ids'] ?? [], 'Nenhum informado'), 9, 7, 80);
        $this->headers($sheet, $row++, ['Indicador', 'Quantidade excluída', 'Motivo']);
        $firstSummaryRow = $row;
        $totalExclusions = 0;
        foreach ($this->snapshot['metric_exclusions'] ?? [] as $id => $exclusion) {
            if (!in_array($id, ['INS3', 'INS4', 'INS6'], true)) continue;
            $this->put($sheet, $row, 1, $id, 4);
            $this->put($sheet, $row, 2, (int)($exclusion['count'] ?? 0), 5);
            $this->put($sheet, $row, 3, $exclusion['note'] ?? 'Atribuição atual a N3', 9);
            $sheet['merges'][] = 'C' . $row . ':G' . $row;
            $sheet['heights'][$row++] = 48;
            $totalExclusions += (int)($exclusion['count'] ?? 0);
        }
        $this->put($sheet, $row, 1, 'Total geral (exclusões por indicador)', 4);
        $this->put($sheet, $row, 2, $totalExclusions, 5, $row > $firstSummaryRow ? 'SUM(B' . $firstSummaryRow . ':B' . ($row - 1) . ')' : null);
        $sheet['heights'][$row] = 48;
        $this->merged($sheet, ++$row, 'Detalhamento por indicador (um chamado pode aparecer em mais de um indicador)', 2);
        $detailsHeaders = ['Indicador', 'ID do chamado', 'Solução', 'Categoria', 'Grupos N3 (ID e nome)', 'Grupos técnicos', 'Motivo'];
        $this->headers($sheet, ++$row, $detailsHeaders);
        $detailsHeaderRow = $row;
        $row++;
        $firstExclusionRow = $row;
        $ticketIndex = array_column($this->snapshot['tickets'], null, 'id');
        $details = 0;
        foreach ($this->snapshot['metric_exclusions'] ?? [] as $id => $exclusion) {
            if (!in_array($id, ['INS3', 'INS4', 'INS6'], true)) continue;
            foreach ($exclusion['ticket_ids'] ?? [] as $ticketId) {
                $ticket = $ticketIndex[$ticketId] ?? [];
                $values = [$id, (string)$ticketId, $this->excelDate($ticket['solved_at'] ?? null), $ticket['category'] ?? '',
                    OfficePackage::selectionLabels($this->snapshot, 'groups', $ticket['n3_group_ids'] ?? [], 'Não informado'),
                    $ticket['groups'] ?? '', 'Vínculo técnico atual a N3; fora da base de cálculo de ' . $id . '.'];
                foreach ($values as $index => $value) { $this->put($sheet, $row, $index + 1, $value, $index === 1 ? 14 : ($index === 2 ? 7 : 0)); }
                $sheet['heights'][$row++] = 64;
                $details++;
            }
        }
        if ($details > 0) $sheet['tables'][] = ['name' => 'Exclusoes_N3',
            'ref' => 'A' . $detailsHeaderRow . ':G' . ($row - 1), 'headers' => $detailsHeaders];
        $this->recordTotal($sheet, $row, $firstExclusionRow, $row - 1, $details, 2);
        $this->actorAudit($sheet, MetricEvidence::ins5AuditTable($this->snapshot), 'Reaberturas_administrativas');
        $ins6Row = $this->actorAudit($sheet, MetricEvidence::ins6AuditTable($this->snapshot), 'INS6_solucionador_N1');
        $sheet['links'][] = ['ref' => 'A8', 'location' => "'Auditoria de exclusões'!A" . $ins6Row];
        $row = $this->lastOccupiedRow($sheet) + 3;
        $sheet['links'][] = ['ref' => 'A11', 'location' => "'Auditoria de exclusões'!A" . $row];
        $this->merged($sheet, $row++, 'Cadastro completo de profissionais | faturamento e participação no INS10', 2);
        $this->merged($sheet, $row++, 'Somente profissionais faturados participam do INS10: Não na coluna Não Faturar mantém a participação; Sim indica exclusão. O valor mensal cadastrado é preservado. Dias e valor faturado respeitam o mês selecionado e a marcação de exclusão. Status indica a presença de data de saída; não define participação em meses anteriores. O cadastro completo permanece para conferência.', 9, 7, 80);
        $staffTable = MetricEvidence::staffRegisterTable($this->snapshot);
        $staffHeaderRow = $row;
        $this->headers($sheet, $row++, $staffTable['headers']);
        $sheet['heights'][$staffHeaderRow] = 70;
        foreach ($staffTable['headers'] as $index => $header) {
            if (!isset($sheet['widths'][$index])) $sheet['widths'][$index] = $index === 9 ? 60 : 28;
        }
        foreach ($staffTable['rows'] as $values) {
            foreach ($values as $index => $value) {
                $style = 0;
                if (in_array($index, [2, 3, 13, 14], true) && $value !== '' && $value !== null) { $value = $this->excelDate($value); $style = 8; }
                elseif (in_array($index, [4, 16], true)) $style = 17;
                elseif ($index === 15) $style = 5;
                $formula = in_array($index, [5, 15, 16], true)
                    ? $this->monthlyStaffLookup([5 => 'Q', 15 => 'D', 16 => 'F'][$index], '$L' . $row, $index === 5 ? '"Não"' : '0') : null;
                $this->put($sheet, $row, $index + 1, $value, 18 + (($row - $staffHeaderRow - 1) % 2) * 18 + $style, $formula);
            }
            $sheet['heights'][$row++] = 54;
        }
        if ($row > $staffHeaderRow + 1) {
            $sheet['tables'][] = ['name' => 'Cadastro_profissionais', 'ref' => 'A' . $staffHeaderRow . ':' . self::col(count($staffTable['headers'])) . ($row - 1), 'headers' => $staffTable['headers']];
            $this->recordTotal($sheet, $row, $staffHeaderRow + 1, $row - 1, $row - $staffHeaderRow - 1);
            $first = $staffHeaderRow + 1;
            $last = $row - 1;
            $active = '$G$' . $first . ':$G$' . $last;
            $unbilled = '$K$' . $first . ':$K$' . $last;
            $summary = $this->snapshot['staff_register'] ?? [];
            $this->put($sheet, $row + 1, 1, 'Faturados ativos no fim do mês', 4);
            $this->put($sheet, $row + 1, 2, (int)($summary['active_total'] ?? 0), 5, 'COUNTIFS(' . $active . ',"Sim",' . $unbilled . ',"Não")');
            $this->put($sheet, $row + 1, 5, (float)($summary['monthly_value_total'] ?? 0), 17, 'SUMIFS($E$' . $first . ':$E$' . $last . ',' . $active . ',"Sim",' . $unbilled . ',"Não")');
            $sheet['heights'][$row + 1] = 48;
            $this->put($sheet, $row + 2, 1, 'Totais faturados (registros visíveis)', 4);
            $this->put($sheet, $row + 2, 16, array_sum(array_column($staffTable['rows'], 15)), 5, 'SUBTOTAL(109,P' . $first . ':P' . $last . ')');
            $this->put($sheet, $row + 2, 17, round(array_sum(array_column($staffTable['rows'], 16)), 2), 17, 'SUBTOTAL(109,Q' . $first . ':Q' . $last . ')');
            $sheet['heights'][$row + 2] = 48;
        } else {
            $this->recordTotal($sheet, $row, $row, $row - 1, 0);
        }
        return $sheet;
    }

    /** Visible INS5 register: distinct tickets and events are separate measures. */
    private function ins5Exceptions(): array
    {
        $sheet = $this->sheet('Exceções INS5', [20, 48, 30, 25, 25, 40, 30, 60, 22, 38, 30, 65, 30, 32, 65]);
        $sheet['freeze'] = 3;
        $this->title($sheet, 'INS5 | Reaberturas administrativas desconsideradas');
        $this->put($sheet, 4, 1, 'Voltar ao INS5 →', 4);
        $sheet['links'][] = ['ref' => 'A4', 'location' => "'INS5'!A1"];
        $this->put($sheet, 4, 4, 'Ver demais exceções →', 4);
        $sheet['links'][] = ['ref' => 'D4', 'location' => "'Auditoria de exclusões'!A1"];
        $this->merged($sheet, 5, 'A dispensa alcança a reabertura administrativa. O chamado continua na quantidade total de solucionados. '
            . 'Outra reabertura válida ou execução incompleta informada mantém o chamado no total de reabertos. Estes quadros explicam a medição preservada no INS5.', 9, 7, 66);
        $tables = MetricEvidence::ins5ExceptionTables($this->snapshot);
        $this->headers($sheet, 7, ['Efeito da regra', 'Quantidade']);
        $row = 8;
        foreach (['events' => 'Eventos administrativos desconsiderados', 'tickets' => 'Chamados distintos com eventos desconsiderados',
            'removed_from_reopened' => 'Chamados retirados somente do total de reabertos',
            'still_counted' => 'Chamados que continuam no total de reabertos', 'in_base' => 'Chamados mantidos na base de solucionados'] as $key => $label) {
            // A wide label avoids hiding the difference between events and tickets.
            $this->put($sheet, $row, 1, $label, 0);
            $sheet['merges'][] = 'A' . $row . ':C' . $row;
            $this->put($sheet, $row, 4, $tables['summary'][$key], 5);
            $sheet['heights'][$row++] = 34;
        }
        // Match the summary header to the label/value columns above.
        unset($sheet['rows'][7][2]);
        $sheet['merges'][] = 'A7:C7';
        $this->put($sheet, 7, 4, 'Quantidade', 3);
        $this->actorAudit($sheet, $tables['tickets'], 'Excecoes_INS5_chamados');
        $this->actorAudit($sheet, $tables['events'], 'Excecoes_INS5_eventos');
        return $sheet;
    }

    /** Independent actor-rule evidence, distinct from N3 scope exclusions. */
    private function actorAudit(array &$sheet, array $table, string $name): int
    {
        $row = $this->lastOccupiedRow($sheet) + 3;
        $start = $row;
        $this->merged($sheet, $row++, (string)$table['title'], 2);
        $this->merged($sheet, $row++, (string)$table['note'], 9, 7, 76);
        $headers = $this->compactHeaders($table['headers'] ?? []);
        if (!$headers) return $start;
        $headerRow = $row;
        $this->headers($sheet, $row++, $headers);
        $sheet['heights'][$headerRow] = 76;
        foreach ($headers as $index => $header) {
            if (!isset($sheet['widths'][$index])) $sheet['widths'][$index] = 32;
        }
        $count = 0;
        foreach ($table['rows'] ?? [] as $values) {
            foreach ($values as $index => $value) {
                $style = is_int($value) || is_float($value) ? 5 : 0;
                if (preg_match('/(\bIDs?\b|chave)/iu', $headers[$index])) {
                    $value = (string)$value; $style = 14;
                } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?$/D', $value)) {
                    $style = strlen($value) === 10 ? 8 : 7;
                    $value = $this->excelDate($value);
                }
                $this->put($sheet, $row, $index + 1, $value, 18 + ($count % 2) * 18 + $style);
            }
            $sheet['heights'][$row++] = 88;
            $count++;
        }
        if ($count > 0) {
            $sheet['tables'][] = ['name' => $name,
                'ref' => 'A' . $headerRow . ':' . self::col(count($headers)) . ($row - 1), 'headers' => $headers];
        }
        $this->recordTotal($sheet, $row, $headerRow + 1, $row - 1, $count);
        if (!empty($table['total_label'])) {
            $this->put($sheet, $row, 1, $table['total_label'], 4);
            $sheet['heights'][$row] = 54;
        }
        if ($count === 0) $this->merged($sheet, $row + 1, 'Nenhum registro desta regra na extração.', 9, 7, 36);
        return $start;
    }

    private function ins12ModeLabel(): string
    {
        return ($this->snapshot['input']['ins12_mode'] ?? 'glpi_and_phone') === 'glpi_and_phone'
            ? 'GLPI e pesquisa telefônica' : 'Somente GLPI; pesquisa telefônica não incluída';
    }

    private function ins12Sources(array &$sheet, array $metric, int $row): int
    {
        $this->merged($sheet, $row++, 'INS12 — pesquisas respondidas por fonte', 2);
        $this->merged($sheet, $row++, 'Opção desta extração: ' . $this->ins12ModeLabel()
            . '. O total soma respostas favoráveis e válidas das fontes incluídas; não calcula a média dos percentuais. Dados conhecidos de uma fonte não completam a apuração se outra fonte incluída está indisponível.', 9, 7, 64);
        $this->headers($sheet, $row++, ['Fonte', 'Participação', 'Satisfeito / muito satisfeito (4 ou 5)', 'Pesquisas respondidas (1 a 5)', 'Resultado da fonte']);
        $sheet['heights'][$row - 1] = 65;
        foreach (['tickets' => 'Pesquisa GLPI', 'phone' => 'Pesquisa telefônica'] as $key => $label) {
            $counts = $metric['source_counts'][$key] ?? [];
            $available = !empty($counts['available']);
            $included = $key === 'tickets' || !empty($counts['included']);
            $this->put($sheet, $row, 1, $label, 4);
            $this->put($sheet, $row, 2, $included ? ($available ? 'Incluída' : 'Incluída / indisponível') : 'Não incluída', 9);
            foreach (['num' => 'numerator', 'den' => 'denominator'] as $part => $field) {
                $formula = null;
                if ($available) {
                    $source = $key === 'tickets' ? $this->rawColumns['INS12'] : $this->phoneIns12Columns;
                    if ($key === 'phone' && !$included) {
                        $valid = $this->range($source['sheet'], $this->phoneSurveyColumns['valid'], $source['rows']);
                        $score = $this->range($source['sheet'], $source['score'], $source['rows']);
                        $formula = $part === 'num' ? 'COUNTIFS(' . $valid . ',1,' . $score . ',">=4")' : 'SUM(' . $valid . ')';
                    } else {
                        $formula = 'SUM(' . $this->range($source['sheet'], $source[$part], $source['rows']) . ')';
                    }
                }
                $this->put($sheet, $row, $part === 'num' ? 3 : 4, $available ? (int)($counts[$field] ?? 0) : 'Não disponível', 5, $formula);
            }
            $result = !$available ? 'Não disponível' : (!empty($counts['denominator']) ? $counts['numerator'] / $counts['denominator'] : 'N/A');
            $this->put($sheet, $row, 5, $result, 6, $available ? 'IF(D' . $row . '=0,"N/A",C' . $row . '/D' . $row . ')' : null);
            $sheet['heights'][$row++] = 44;
        }
        $this->put($sheet, $row, 1, 'Total oficial do INS12', 4);
        $this->put($sheet, $row, 2, OfficePackage::status($metric), $this->statusStyle($metric), 'B12');
        $this->put($sheet, $row, 3, $metric['numerator'] ?? 'Pendente', 5, 'B8');
        $this->put($sheet, $row, 4, $metric['denominator'] ?? 'Pendente', 5, 'B9');
        $this->put($sheet, $row, 5, $metric['value'] === null ? ($metric['status'] === 'not_applicable' ? 'N/A' : 'Pendente') : $metric['value'] / 100, 6, 'B10');
        $sheet['heights'][$row++] = 44;
        $this->merged($sheet, $row++, 'A distribuição diária usa a solução do chamado para respostas GLPI e a entrada na fila da ligação representativa para respostas telefônicas. Respostas de uma fonte não incluída permanecem visíveis para conferência, mas não alteram o total oficial.', 9, 7, 52);
        return $row;
    }

    private function phoneSurvey(array &$sheet, int $row): void
    {
        $survey = $this->snapshot['phone_survey'];
        $available = !empty($survey['available']);
        $this->merged($sheet, $row++, 'Pesquisa telefônica — situação e providências', 2);
        $this->merged($sheet, $row++, (string)($survey['status'] ?? $this->ins12ModeLabel()), 9, 7, 48);
        $this->merged($sheet, $row++, 'Escopo: ligações do período após exclusões de teste, deduplicadas pelo ID da ligação. As contagens abaixo não representam todas as pesquisas existentes na central.', 9, 7, 48);
        $this->headers($sheet, $row++, ['Evidência da pesquisa telefônica', 'Quantidade']);
        foreach (['unique_calls' => 'Ligações distintas consideradas', 'responses' => 'Respostas registradas e vinculadas',
                  'valid_responses' => 'Respostas com nota inteira de 1 a 5', 'favorable_responses' => 'Respostas favoráveis (nota considerada 4 ou 5)', 'invalid_responses' => 'Respostas fora da faixa inteira de 1 a 5',
                  'without_response' => 'Ligações sem resposta vinculada'] as $key => $label) {
            $this->put($sheet, $row, 1, $label, 0);
            $this->put($sheet, $row, 2, $available || $key === 'unique_calls' ? (int)($survey[$key] ?? 0) : 'Não disponível', 5);
            $sheet['heights'][$row++] = 42;
        }
        $survey['score_rule'] = PhoneSurveySummary::scoreRuleText();
        $survey['score_resolution'] = (string)($survey['resolution']['explanation'] ?? 'Critério preservado na extração original.');
        foreach (['explanation' => 'Critério de participação no INS12', 'score_rule' => 'Critério da nota telefônica',
                  'score_resolution' => 'Origem das notas nesta competência', 'actions' => 'Providências e encaminhamentos'] as $key => $label) {
            $this->merged($sheet, ++$row, $label, 2);
            $text = trim((string)($survey[$key] ?? '')) ?: 'Não informado.';
            foreach ($this->textRows($text) as $line) {
                if (trim($line) !== '') { $this->merged($sheet, ++$row, $line, 9, 7, min(300, 40 + (int)(strlen($line) / 140) * 15)); }
            }
        }
    }

    /** Keep long narrative text readable without exceeding Excel row heights. */
    private function textRows(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if ($line === '') { $rows[] = ''; continue; }
            preg_match_all('/.{1,800}/us', $line, $chunks);
            foreach ($chunks[0] as $chunk) { $rows[] = $chunk; }
        }
        return $rows;
    }

    private function metricById(string $id): array
    {
        foreach ($this->snapshot['metrics'] as $metric) { if ($metric['id'] === $id) { return $metric; } }
        return [];
    }

    /** The dashboard has separate scales and captions for minimum and maximum targets. */
    private function comparisonChart(array &$sheet, string $direction, int $headerRow, array $anchor): void
    {
        $metrics = array_values(array_filter($this->snapshot['metrics'], function ($metric) use ($direction) { return $metric['direction'] === $direction; }));
        $this->headers($sheet, $headerRow, ['Indicador / condição', 'Resultado', 'Meta', 'Situação']);
        $definition = ['title' => $direction === 'gte' ? 'Metas mínimas: resultado ≥ meta' : 'Metas máximas: resultado ≤ meta', 'categories' => [], 'series' => [['name' => 'Resultado', 'values' => [], 'color' => ReportStyle::BLUE], ['name' => 'Meta', 'values' => [], 'color' => ReportStyle::NAVY]], 'kind' => 'bar', 'stacked' => false, 'unit' => 'percent', 'available' => true];
        foreach ($metrics as $index => $metric) {
            $row = $headerRow + $index + 1;
            $suffix = $metric['status'] === 'pending' ? ' (Pendente)' : ($metric['status'] === 'not_applicable' ? ' (N/A)' : ($metric['status'] === 'provisional' ? ' (Provisório)' : ''));
            $label = $metric['id'] . $suffix;
            $value = $metric['value'] === null ? null : $metric['value'] / 100;
            $this->put($sheet, $row, 1, $label, 4);
            $this->put($sheet, $row, 2, $value, 6, $value === null ? null : "'" . $metric['id'] . "'!B10");
            $this->put($sheet, $row, 3, $metric['target'] / 100, 6, "'" . $metric['id'] . "'!B11");
            $this->put($sheet, $row, 4, OfficePackage::status($metric), $this->statusStyle($metric), "'" . $metric['id'] . "'!B12");
            $definition['categories'][] = $label;
            $definition['series'][0]['values'][] = $value;
            $definition['series'][1]['values'][] = $metric['target'] / 100;
        }
        $sheet['charts'][] = $this->chartSpec($sheet['name'], $definition, $headerRow + 1, 1, $anchor);
    }

    private function attachComposition(array &$sheet, array $definition, int $headerRow, int $column, array $anchor): void
    {
        $this->put($sheet, $headerRow, $column, $definition['id'] === 'calls_daily' ? 'Dia de entrada' : ($definition['id'] === 'tickets_daily' ? 'Dia de solução' : (in_array($definition['id'], ['INS1', 'INS2', 'INS3', 'INS4'], true) ? 'Conformidade' : 'Composição (quantidades)')), 3);
        foreach ($definition['series'] as $index => $series) { $this->put($sheet, $headerRow, $column + 1 + $index, $series['name'], 3); }
        $sheet['heights'][$headerRow] = 42;
        foreach ($definition['categories'] as $index => $label) {
            $row = $headerRow + 1 + $index;
            $this->put($sheet, $row, $column, (string)$label, 0);
            foreach ($definition['series'] as $seriesIndex => $series) {
                $value = $series['values'][$index] ?? null;
                $formula = $value === null ? null : $this->compositionFormula($definition['id'], $index, $seriesIndex, $row, $column);
                $this->put($sheet, $row, $column + 1 + $seriesIndex, $value, 5, $formula);
            }
            $sheet['heights'][$row] = in_array($definition['id'], ['calls_daily', 'tickets_daily'], true) ? 26 : 42;
        }
        // INS10 combines monthly billed headcount with a three-month departure
        // window: those measures overlap and must not be added together.
        if ($definition['id'] !== 'INS10') {
            $totalRow = $headerRow + count($definition['categories']) + 1;
            $this->put($sheet, $totalRow, $column, 'Total geral', 4);
            foreach ($definition['series'] as $seriesIndex => $series) {
                $col = self::col($column + 1 + $seriesIndex);
                $hasValues = count(array_filter($series['values'], 'is_numeric')) > 0;
                $this->put($sheet, $totalRow, $column + 1 + $seriesIndex, $hasValues ? array_sum($series['values']) : 'Não disponível', 5,
                    $hasValues ? 'SUM(' . $col . ($headerRow + 1) . ':' . $col . ($totalRow - 1) . ')' : null);
            }
            $sheet['heights'][$totalRow] = 36;
        }
        if (!empty($definition['available'])) { $sheet['charts'][] = $this->chartSpec($sheet['name'], $definition, $headerRow + 1, $column, $anchor); }
    }

    private function ticketSurveyDistribution(array &$sheet, int $row): void
    {
        $distribution = ChartData::ticketSurveyDistribution($this->snapshot);
        $this->merged($sheet, $row++, 'Pesquisa de satisfação — chamados GLPI', 2);
        $this->merged($sheet, $row++, $distribution['available']
            ? 'Conferência da base completa de chamados solucionados: notas válidas, pesquisas não preenchidas e eventuais notas inválidas. Não preenchida e Nota inválida não entram no cálculo do INS12. A telefonia é apresentada separadamente na composição por fonte.'
            : 'A fonte de pesquisas GLPI não estava disponível nesta extração. O total abaixo é a quantidade de chamados; não informa se suas pesquisas foram preenchidas.', 9, 7, 64);
        $this->headers($sheet, $row++, ['Pesquisa de satisfação GLPI', 'Quantidade']);
        $first = $row;
        $range = $this->range('Base de Dados', 27, count($this->snapshot['tickets']));
        $ids = $this->range('Base de Dados', 1, count($this->snapshot['tickets']));
        foreach ($distribution['rows'] as $index => $values) {
            if (!$distribution['available']) {
                $formula = 'COUNTA(' . $ids . ')';
            } elseif ($index < 5) {
                $formula = 'COUNTIF(' . $range . ',' . ($index + 1) . ')';
            } elseif ($index === 5) {
                $formula = 'COUNTIFS(' . $range . ',"",' . $ids . ',"<>")';
            } else {
                $formula = 'COUNTA(' . $ids . ')-SUM(B' . $first . ':B' . ($row - 1) . ')';
            }
            $this->put($sheet, $row, 1, $values[0]);
            $this->put($sheet, $row++, 2, $values[1], 5, $formula);
        }
        $this->put($sheet, $row, 1, 'Total geral', 4);
        $this->put($sheet, $row, 2, $distribution['total'], 5, 'SUM(B' . $first . ':B' . ($row - 1) . ')');
        $sheet['heights'][$row] = 36;
    }

    /** Phone survey universe is independent of GLPI tickets and of inclusion mode. */
    private function phoneSurveyDistribution(array &$sheet, int $row): void
    {
        $distribution = ChartData::phoneSurveyDistribution($this->snapshot);
        $this->merged($sheet, $row++, 'Pesquisa de satisfação — telefonia', 2);
        $this->merged($sheet, $row++, !empty($distribution['available'])
            ? 'Uma pesquisa por ligação distinta, após excluir números de teste. Notas na escala considerada de 1 a 5; Não preenchida e Nota inválida não entram no percentual. '
                . (($this->snapshot['input']['ins12_mode'] ?? 'glpi_and_phone') === 'glpi_and_phone'
                    ? 'Esta fonte participa do INS12.' : 'Fonte demonstrada para conferência, sem participação no INS12 desta extração.')
            : 'A fonte de pesquisa telefônica não estava disponível. O total de ligações é conhecido, mas não informa se houve resposta.', 9, 7, 76);
        $this->headers($sheet, $row++, ['Pesquisa de satisfação telefônica', 'Quantidade']);
        $first = $row;
        $calls = count($this->snapshot['calls']);
        $representative = $this->range('Base de Dados Ligações', $this->phoneSurveyColumns['representative'], $calls);
        $responses = $this->range('Base de Dados Ligações', $this->phoneSurveyColumns['response'], $calls);
        $valid = $this->range('Base de Dados Ligações', $this->phoneSurveyColumns['valid'], $calls);
        $score = $this->range('Base de Dados Ligações', $this->phoneIns12Columns['score'], $calls);
        foreach ($distribution['rows'] as $index => $values) {
            if (empty($distribution['available'])) {
                $formula = 'SUM(' . $representative . ')';
            } elseif ($index < 5) {
                $formula = 'COUNTIFS(' . $score . ',' . ($index + 1) . ',' . $valid . ',1)';
            } elseif ($index === 5) {
                $formula = 'SUM(' . $representative . ')-SUM(' . $responses . ')';
            } else {
                $formula = 'SUM(' . $responses . ')-SUM(' . $valid . ')';
            }
            $this->put($sheet, $row, 1, $values[0]);
            $this->put($sheet, $row++, 2, $values[1], 5, $formula);
        }
        $this->put($sheet, $row, 1, 'Total geral de ligações distintas', 4);
        $this->put($sheet, $row, 2, $distribution['total'], 5, 'SUM(B' . $first . ':B' . ($row - 1) . ')');
        $sheet['heights'][$row] = 36;
    }

    private function compositionFormula(string $id, int $bucket, int $series, int $row, int $column): ?string
    {
        $tickets = count($this->snapshot['tickets']);
        $calls = count($this->snapshot['calls']);
        $tc = function ($col) use ($tickets) { return $this->range('Base de Dados', $col, $tickets); };
        $cc = function ($col) use ($calls) { return $this->range('Base de Dados Ligações', $col, $calls); };
        $previous = self::col($column + 1) . ($row - 1);
        if (in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true)) {
            $priority = (int)$this->snapshot['input']['priority_map'][$id];
            $label = ['Não excedido', 'Excedido', 'Apuração pendente'][$bucket];
            $scopeFilter = $id === 'INS4' ? ',' . $tc($this->ins4ScopeColumn) . ',1'
                : ($id === 'INS3' ? ',' . $tc($this->n3AssignedColumn) . ',0' : '');
            return 'COUNTIFS(' . $tc(5) . ',' . $priority . ',' . $tc(14) . ',"' . $label . '"' . $scopeFilter . ')';
        }
        if ($id === 'INS10') { return 'B' . (24 + $bucket); }
        if (in_array($id, ['INS5', 'INS11'], true)) { return $bucket === 0 ? 'B8' : 'MAX(0,B9-B8)'; }
        if ($id === 'INS6') {
            // Accepted tickets include the documented solver exception; no
            // unassigned-group slice may count those same tickets a second time.
            return $bucket === 0 ? 'B8' : 'MAX(0,B9-B8)';
        }
        if ($id === 'INS7' || $id === 'INS8') {
            if ($bucket === 0) { return 'B8'; }
            if ($bucket === 2) { return 'B9-B8-' . $previous; }
            $isTalk = $id === 'INS7';
            return 'COUNTIFS(' . $cc($isTalk ? 6 : 5) . ',">0",' . $cc($isTalk ? 12 : 13) . ',">"&' . ($isTalk ? '600' : '30') . '/86400,' . $cc($this->callValidityColumns[$isTalk ? 'talk' : 'wait']) . ',1)';
        }
        if ($id === 'INS9') {
            if ($bucket === 0) { return 'B8'; }
            if ($bucket === 1) { return 'COUNTIFS(' . $cc(9) . ',"Abandonada",' . $cc(14) . ',"<="&30/86400,' . $cc($this->callValidityColumns['abandon']) . ',1)'; }
            if ($bucket === 2) { return 'COUNTIF(' . $cc(9) . ',"Atendida")'; }
            return 'B9-SUM(' . self::col($column + 1) . ($row - 3) . ':' . self::col($column + 1) . ($row - 1) . ')';
        }
        if ($id === 'INS12') {
            return $series === 0
                ? 'COUNTIFS(' . $tc(27) . ',' . ($bucket + 1) . ',' . $tc($this->rawColumns['INS12']['den']) . ',1)'
                : 'COUNTIFS(' . $cc($this->phoneIns12Columns['score']) . ',' . ($bucket + 1) . ',' . $cc($this->phoneIns12Columns['den']) . ',1)';
        }
        if ($id === 'tickets_types') {
            if ($bucket < 2) { return 'COUNTIF(' . $tc(3) . ',"' . ($bucket === 0 ? 'Requisição' : 'Incidente') . '")'; }
            return 'COUNTA(' . $tc(1) . ')-COUNTIF(' . $tc(3) . ',"Requisição")-COUNTIF(' . $tc(3) . ',"Incidente")';
        }
        if ($id === 'tickets_daily') {
            $month = explode('-', $this->snapshot['period']['month']);
            $date = 'DATE(' . (int)$month[0] . ',' . (int)$month[1] . ',' . ($bucket + 1) . ')';
            return 'COUNTIFS(' . $tc($this->temporalColumns['tickets']['date']) . ',">="&' . $date . ',' . $tc($this->temporalColumns['tickets']['date']) . ',"<"&(' . $date . '+1),' . $tc($this->temporalColumns['tickets']['weekday']) . ',">0")';
        }
        if ($id === 'calls_daily') {
            $month = explode('-', $this->snapshot['period']['month']);
            $date = 'DATE(' . (int)$month[0] . ',' . (int)$month[1] . ',' . ($bucket + 1) . ')';
            return 'COUNTIFS(' . $cc($this->temporalColumns['calls']['date']) . ',">="&' . $date . ',' . $cc($this->temporalColumns['calls']['date']) . ',"<"&(' . $date . '+1),' . $cc(9) . ',"' . ['Atendida', 'Abandonada', 'Sem desfecho'][$series] . '")';
        }
        return null;
    }

    private function chartSpec(string $sheetName, array $definition, int $firstRow, int $column, array $anchor): array
    {
        $prefix = "'" . str_replace("'", "''", $sheetName) . "'!";
        $lastRow = $firstRow + count($definition['categories']) - 1;
        $definition['category_ref'] = $prefix . '$' . self::col($column) . '$' . $firstRow . ':$' . self::col($column) . '$' . $lastRow;
        foreach ($definition['series'] as $index => &$series) {
            $col = self::col($column + 1 + $index);
            $series['value_ref'] = $prefix . '$' . $col . '$' . $firstRow . ':$' . $col . '$' . $lastRow;
        }
        unset($series);
        $definition['anchor'] = $anchor;
        return $definition;
    }

    private function range(string $sheet, int $col, int $rows): string
    {
        return "'" . str_replace("'", "''", $sheet) . "'!$" . self::col($col) . '$7:$' . self::col($col) . '$' . max(7, 6 + $rows);
    }

    private function duration($seconds)
    {
        return $seconds === null ? null : (float)$seconds / 86400;
    }

    private function excelDate($value)
    {
        if ($value === null || $value === '') { return null; }
        try {
            // Database timestamps represent local civil time. Do not convert
            // their wall clock using the PHP server timezone when serializing.
            $date = new \DateTimeImmutable(str_replace('T', ' ', substr((string)$value, 0, 26)), new \DateTimeZone('UTC'));
            return (float)$date->format('U.u') / 86400 + 25569;
        } catch (\Exception $e) {
            return (string)$value;
        }
    }

    private function statusStyle(array $m): int
    {
        return $m['status'] === 'met' ? 11 : ($m['status'] === 'unmet' ? 12 : 13);
    }

    private static function col(int $value): string
    {
        $name = '';
        do { $value--; $name = chr(65 + $value % 26) . $name; $value = intdiv($value, 26); } while ($value > 0);
        return $name;
    }

    private function sheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $sheet['freeze'] . '" topLeftCell="A' . ($sheet['freeze'] + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A' . ($sheet['freeze'] + 1) . '" sqref="A' . ($sheet['freeze'] + 1) . '"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="25"/><cols>';
        foreach ($sheet['widths'] as $i => $width) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        ksort($sheet['rows']);
        foreach ($sheet['rows'] as $r => $cells) {
            $xml .= $this->rowXml($r, $cells, $sheet['heights'][$r] ?? null);
        }
        // Serialized rows follow the retained headings/summary block in both
        // raw worksheets and per-indicator evidence tables.
        $xml .= $sheet['evidence_xml'];
        $xml .= '</sheetData>';
        if ($sheet['filter'] !== '') { $xml .= '<autoFilter ref="' . $sheet['filter'] . '"/>'; }
        if ($sheet['merges']) {
            $xml .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $range) { $xml .= '<mergeCell ref="' . $range . '"/>'; }
            $xml .= '</mergeCells>';
        }
        foreach ($sheet['conditional'] as $index => $range) {
            $xml .= '<conditionalFormatting sqref="' . $range . '"><cfRule type="colorScale" priority="' . ($index + 1) . '"><colorScale><cfvo type="num" val="0"/><cfvo type="max"/><color rgb="FF' . ReportStyle::WHITE . '"/><color rgb="FF' . ReportStyle::HEATMAP_MAX . '"/></colorScale></cfRule></conditionalFormatting>';
        }
        if ($sheet['links']) {
            $xml .= '<hyperlinks>';
            foreach ($sheet['links'] as $link) {
                $xml .= '<hyperlink ref="' . OfficePackage::xml($link['ref']) . '" location="' . OfficePackage::xml($link['location']) . '"/>';
            }
            $xml .= '</hyperlinks>';
        }
        $xml .= '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/><headerFooter><oddFooter>&amp;LG4F | SES&amp;R&amp;P / &amp;N</oddFooter></headerFooter>';
        if ($sheet['charts']) { $xml .= '<drawing r:id="rIdChart"/>'; }
        if ($sheet['tables']) {
            $xml .= '<tableParts count="' . count($sheet['tables']) . '">';
            foreach ($sheet['tables'] as $index => $table) { $xml .= '<tablePart r:id="rIdEvidenceTable' . ($index + 1) . '"/>'; }
            $xml .= '</tableParts>';
        }
        return $xml . '</worksheet>';
    }

    /** Keep completed sheets compressed while the remaining indicators are built. */
    private function serializeSheet(array $sheet): array
    {
        // Do not duplicate the large evidence body by concatenating it into a
        // second worksheet XML string. Feed wrapper and body into zlib in order.
        $wrapper = $sheet;
        $wrapper['evidence_xml'] = '';
        $xml = $this->sheetXml($wrapper);
        unset($wrapper);
        $split = strpos($xml, '</sheetData>');
        $deflater = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        if ($split === false || $deflater === false) {
            throw new \RuntimeException('Não foi possível preparar a planilha para exportação.');
        }
        $head = deflate_add($deflater, substr($xml, 0, $split), ZLIB_NO_FLUSH);
        $body = deflate_add($deflater, $sheet['evidence_xml'], ZLIB_NO_FLUSH);
        $tail = deflate_add($deflater, substr($xml, $split), ZLIB_FINISH);
        if (!is_string($head) || !is_string($body) || !is_string($tail)) {
            throw new \RuntimeException('Não foi possível compactar os registros da planilha.');
        }
        $compressed = $head . $body . $tail;
        $checksum = hash_init('crc32b');
        hash_update($checksum, substr($xml, 0, $split));
        hash_update($checksum, $sheet['evidence_xml']);
        hash_update($checksum, substr($xml, $split));
        $sheet['serialized_entry'] = ['deflated' => $compressed,
            'size' => strlen($xml) + strlen($sheet['evidence_xml']), 'crc' => (int)hexdec(hash_final($checksum))];
        $sheet['rows'] = [];
        $sheet['heights'] = [];
        $sheet['evidence_xml'] = '';
        return $sheet;
    }

    private function rowXml(int $row, array $cells, ?int $height = null): string
    {
        $xml = '<row r="' . $row . '"' . ($height !== null ? ' ht="' . $height . '" customHeight="1"' : '') . '>';
        ksort($cells);
        foreach ($cells as $column => $cell) {
            $ref = self::col($column) . $row;
            $value = $cell['value'];
            $numeric = is_int($value) || is_float($value);
            if ($cell['formula'] !== null) {
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '"' . ($numeric ? '' : ' t="str"') . '><f>' . OfficePackage::xml($cell['formula']) . '</f><v>' . OfficePackage::xml($numeric ? sprintf('%.15g', $value) : $value) . '</v></c>';
            } elseif ($value === null) {
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '"/>';
            } elseif ($numeric) {
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '"><v>' . sprintf('%.15g', $value) . '</v></c>';
            } else {
                // Source text remains literal even when it starts with =,+,-,@.
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '" t="inlineStr"><is><t xml:space="preserve">' . OfficePackage::xml($value) . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    }

    private function styles(): string
    {
        $formats = [164 => '0.00%', 165 => 'dd/mm/yyyy hh:mm:ss', 166 => 'dd/mm/yyyy', 167 => '[h]:mm:ss'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="4">';
        foreach ($formats as $id => $code) { $xml .= '<numFmt numFmtId="' . $id . '" formatCode="' . $code . '"/>'; }
        $xml .= '</numFmts><fonts count="5"><font><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Arial"/></font><font><b/><sz val="21"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Arial"/></font><font><sz val="10"/><color rgb="FF' . ReportStyle::MUTED . '"/><name val="Arial"/></font></fonts><fills count="10"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ([ReportStyle::NAVY, ReportStyle::BLUE_DARK, ReportStyle::LIGHT_GRAY, ReportStyle::POSITIVE_FILL, ReportStyle::CAUTION_FILL, ReportStyle::LIGHT_GRAY, ReportStyle::HEATMAP_MAX, ReportStyle::WHITE] as $color) { $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>'; }
        $xml .= '</fills><borders count="2"><border/><border><bottom style="thin"><color rgb="FF' . ReportStyle::BORDER . '"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="54">';
        // font, fill, format, border, horizontal alignment
        $styles = [[0,0,0,1,'left'],[1,2,0,0,'left'],[2,3,0,0,'left'],[2,2,0,1,'left'],[3,4,0,1,'left'],[0,0,3,1,'right'],[3,4,164,1,'right'],[0,0,165,1,'left'],[0,0,166,1,'left'],[4,0,0,0,'left'],[0,0,167,1,'right'],[3,5,0,1,'left'],[3,6,0,1,'left'],[3,7,0,1,'left'],[0,0,49,1,'left'],[0,0,3,1,'center'],[0,8,3,1,'center'],[0,0,4,1,'right']];
        // Two explicit evidence-row variants of the existing formats: white
        // and light blue. Preserve the original number/date/text formats.
        $baseStyles = $styles;
        foreach ([9, 4] as $fillId) {
            foreach ($baseStyles as $base) {
                $base[0] = 0;
                $base[1] = $fillId;
                $styles[] = $base;
            }
        }
        foreach ($styles as $s) { $xml .= '<xf numFmtId="' . $s[2] . '" fontId="' . $s[0] . '" fillId="' . $s[1] . '" borderId="' . $s[3] . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . $s[4] . '" vertical="center" wrapText="1"/></xf>'; }
        return $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleLight1" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';
    }

    private function package(): string
    {
        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $files = [];
        // Index by reference so releasing each processed sheet also releases its
        // buffers; a by-value foreach would retain the pre-mutation array.
        for ($i = 0, $count = count($this->sheets); $i < $count; $i++) {
            $sheet =& $this->sheets[$i];
            $n = $i + 1;
            $workbook .= '<sheet name="' . OfficePackage::xml($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            if (isset($sheet['serialized_entry'])) {
                $files['xl/worksheets/sheet' . $n . '.xml'] = $sheet['serialized_entry'];
            } else {
                $files['xl/worksheets/sheet' . $n . '.xml'] = $this->sheetXml($sheet);
            }
            // Only table/chart metadata is needed by the remaining package steps.
            $this->sheets[$i]['rows'] = [];
            $this->sheets[$i]['heights'] = [];
            $this->sheets[$i]['evidence_xml'] = '';
            unset($this->sheets[$i]['serialized_entry']);
        }
        unset($sheet);
        $files['xl/workbook.xml'] = $workbook . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1" forceFullCalc="1"/></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = $rels . '</Relationships>';
        $files['xl/styles.xml'] = $this->styles();
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $this->addTables($files, $types);
        $this->addCharts($files, $types);
        $files['[Content_Types].xml'] = $types . '</Types>';
        return OfficePackage::write($files);
    }

    private function addTables(array &$files, string &$types): void
    {
        $number = 0;
        foreach ($this->sheets as $sheetIndex => $sheet) {
            foreach ($sheet['tables'] as $index => $table) {
                $number++;
                $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="' . $number . '" name="' . OfficePackage::xml($table['name']) . '" displayName="' . OfficePackage::xml($table['name']) . '" ref="' . $table['ref'] . '" totalsRowShown="0"><autoFilter ref="' . $table['ref'] . '"/><tableColumns count="' . count($table['headers']) . '">';
                foreach ($table['headers'] as $column => $header) {
                    $xml .= '<tableColumn id="' . ($column + 1) . '" name="' . OfficePackage::xml($header) . '"/>';
                }
                $files['xl/tables/table' . $number . '.xml'] = $xml . '</tableColumns><tableStyleInfo name="TableStyleLight1" showFirstColumn="0" showLastColumn="0" showRowStripes="0" showColumnStripes="0"/></table>';
                $types .= '<Override PartName="/xl/tables/table' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
                $this->sheetRelationship($files, $sheetIndex + 1, '<Relationship Id="rIdEvidenceTable' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table' . $number . '.xml"/>');
            }
        }
    }

    private function sheetRelationship(array &$files, int $sheetNumber, string $relationship): void
    {
        $path = 'xl/worksheets/_rels/sheet' . $sheetNumber . '.xml.rels';
        $xml = $files[$path] ?? '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        $files[$path] = str_replace('</Relationships>', $relationship . '</Relationships>', $xml);
    }

    private function addCharts(array &$files, string &$types): void
    {
        $chartNumber = 0;
        foreach ($this->sheets as $sheetIndex => $sheet) {
            if (!$sheet['charts']) { continue; }
            $drawingNumber = $sheetIndex + 1;
            $this->sheetRelationship($files, $drawingNumber, '<Relationship Id="rIdChart" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $drawingNumber . '.xml"/>');
            $relationships = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $drawing = '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
            foreach ($sheet['charts'] as $index => $chart) {
                $chartNumber++;
                $relationship = 'rId' . ($index + 1);
                $relationships .= '<Relationship Id="' . $relationship . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart' . $chartNumber . '.xml"/>';
                $drawing .= XlsxChart::anchor($chart, $relationship, $chartNumber);
                $files['xl/charts/chart' . $chartNumber . '.xml'] = XlsxChart::xml($chart);
                $types .= '<Override PartName="/xl/charts/chart' . $chartNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
            }
            $files['xl/drawings/drawing' . $drawingNumber . '.xml'] = $drawing . '</xdr:wsDr>';
            $files['xl/drawings/_rels/drawing' . $drawingNumber . '.xml.rels'] = $relationships . '</Relationships>';
            $types .= '<Override PartName="/xl/drawings/drawing' . $drawingNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
    }
}
