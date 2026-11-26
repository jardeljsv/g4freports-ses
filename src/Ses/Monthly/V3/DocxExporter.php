<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V3;

use GlpiPlugin\G4freports\Ses\FinancialSummary;
use GlpiPlugin\G4freports\Ses\Monthly\V1\OfficePackage;
use GlpiPlugin\G4freports\Ses\Monthly\V1\WordChart;
use GlpiPlugin\G4freports\Ses\Monthly\V1\ChartData;
use GlpiPlugin\G4freports\Ses\Monthly\V1\HeatmapData;
use GlpiPlugin\G4freports\Ses\Monthly\V1\MetricEvidence;
use GlpiPlugin\G4freports\Ses\Monthly\V1\StaffRegister;
use GlpiPlugin\G4freports\Ses\Monthly\V1\ReportStyle;

/** SES managerial report built from the same frozen dataset as the workbook. */
final class DocxExporter
{
    private const VIOLATION_TEXT = '9C1C1C';

    private WordChart $charts;

    public function filename(array $snapshot): string
    {
        return 'Relatorio_Mensal_SES_' . preg_replace('/[^0-9-]/', '', $snapshot['period']['month'] ?? '') . '.docx';
    }

    public function toBinary(array $snapshot): string
    {
        $path = dirname(__DIR__, 4) . '/resources/monthly/v1/papel_timbrado_G4F.docx';
        $binary = @file_get_contents($path);
        if (!is_string($binary)) {
            throw new \RuntimeException('O papel timbrado G4F não foi encontrado no pacote do plugin.');
        }
        $files = OfficePackage::read($binary);
        if (!isset($files['word/document.xml'], $files['word/_rels/document.xml.rels'])) {
            throw new \RuntimeException('O modelo G4F não contém os componentes necessários.');
        }
        $this->charts = new WordChart();
        $files['word/document.xml'] = $this->document($snapshot);
        $this->charts->attach($files);
        $files['word/styles.xml'] = $this->styles();
        $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Relatório mensal SES</dc:title><dc:creator>G4F</dc:creator><dc:description>' . OfficePackage::xml(($snapshot['period']['month'] ?? '') . ' | ' . ($snapshot['rules_version'] ?? '')) . '</dc:description></cp:coreProperties>';
        return OfficePackage::write($files);
    }

    private function document(array $s): string
    {
        $period = $s['period'];
        $totals = $s['totals'];
        $input = $s['input'] ?? [];
        $manual = $s['manual'] ?? [];
        $figures = ChartData::build($s);
        $heatmaps = HeatmapData::build($s);
        $title = trim((string)($input['report_title'] ?? '')) ?: 'Relatório Mensal Gerencial — SES-DF';
        $body = $this->p('G4F  |  Secretaria de Estado de Saúde do Distrito Federal', '', false, 20, 'center');
        $body .= '<w:p><w:pPr><w:spacing w:before="3000" w:after="320"/></w:pPr></w:p>';
        $body .= $this->p($title, 'Title', true, 40, 'center');
        $body .= $this->p('Contrato SES/DF 053592/2025', '', true, 25, 'center');
        $body .= $this->p((string)($period['label'] ?? $period['month']), '', false, 26, 'center');
        $body .= $this->p('Chamados por data de solução e telefonia por entrada na fila', '', false, 21, 'center');
        $body .= $this->p('Extração: ' . OfficePackage::date($s['generated_at'] ?? ''), '', false, 18, 'center');
        $body .= $this->page();
        $body .= $this->heading('1 Apresentação e objetivo');
        $body .= $this->p('O presente relatório apresenta à fiscalização a execução mensal dos serviços de Tecnologia da Informação e Comunicação (TIC) do Contrato SES/DF nº 053592/2025. Reúne os registros operacionais, a composição da equipe considerada no faturamento e os resultados dos Indicadores de Nível de Serviço (INS), para acompanhamento da prestação dos serviços e análise da medição mensal.');
        $body .= $this->p('O período de apuração compreende ' . $this->displayPeriod($period) . '. Os chamados são selecionados pela data de solução, inclusive quando abertos em meses anteriores. As ligações são selecionadas pela entrada na fila. As informações são provenientes do GLPI, da central telefônica Asterisk e do cadastro de profissionais mantido pela gestão contratual.');
        $body .= $this->frontComposition($s['financial'] ?? [], $s['staff_register'] ?? []);
        $body .= $this->heading('2 Resumo de chamados');
        $body .= $this->table(['Descrição', 'Quantidade'], [
            ['Total de chamados solucionados', OfficePackage::number($totals['tickets'] ?? 0)],
            ['Requisições', OfficePackage::number($totals['requests'] ?? 0)],
            ['Incidentes', OfficePackage::number($totals['incidents'] ?? 0)],
        ], [7100, 2400]);
        $body .= $this->figure($figures['tickets_types'], 2800, false);
        $body .= $this->heading('3 Resumo de telefonia');
        $body .= $this->table(['Descrição', 'Apuração'], [
            ['Ligações recebidas consideradas', OfficePackage::number($totals['calls_received'] ?? 0)],
            ['Ligações atendidas', OfficePackage::number($totals['calls_answered'] ?? 0)],
            ['Ligações abandonadas no total', OfficePackage::number($totals['calls_abandoned'] ?? 0)],
            ['Tempo Médio de Atendimento (TMA)', OfficePackage::duration($totals['mean_talk_seconds'] ?? null)],
            ['Tempo Médio de Espera (TME)', OfficePackage::duration($totals['mean_wait_seconds'] ?? null)],
        ], [7100, 2400]);
        $body .= $this->p('O TMA corresponde à duração média das conversas atendidas. O TME corresponde à espera média na fila até a conexão com o agente. As médias consideram as ligações com tempos válidos no período.');
        $body .= $this->figure($figures['calls_daily'], 3000, false);
        if (trim((string)($manual['executive_summary'] ?? '')) !== '') {
            $body .= $this->heading('3.1 Análise executiva', 2);
            $body .= $this->text($manual['executive_summary']);
        }
        $body .= $this->page();
        $body .= $this->heading('4 Indicadores do contrato');
        $body .= $this->p('Os resultados a seguir consolidam o desempenho da competência frente às metas contratuais. A medição preserva as fontes e as opções de cálculo selecionadas para esta extração. Os registros que compõem cada indicador e as exceções consideradas estão disponíveis na planilha de apoio.');
        $body .= $this->heading('4.1 Resultados e metas', 2);
        $body .= $this->metricsSummary($s);
        $body .= $this->p('A fonte vermelha identifica os indicadores cuja meta não foi atingida. Nos indicadores com meta mínima, o resultado deve ser maior ou igual à meta; nos indicadores com limite máximo, deve ser menor ou igual ao limite.');
        $body .= $this->page();
        $body .= $this->heading('4.2 Comparativo dos resultados e metas', 2);
        $body .= $this->figure($this->overview($s['metrics'], 'gte'), 5400, false);
        $body .= $this->figure($this->overview($s['metrics'], 'lte'), 3500, false);
        $body .= $this->page();
        $body .= $this->heading('5 Medição dos indicadores');
        foreach ($s['metrics'] as $index => $m) {
            $id = (string)$m['id'];
            $body .= $this->heading('5.' . (int)substr($id, 3) . ' ' . $id . ' — ' . MetricEvidence::title($id, $input['priority_map'][$id] ?? null), 2);
            $labels = MetricEvidence::labels($id);
            if ($id === 'INS10') $labels['denominator'] = 'Quantidade de profissionais faturados na competência';
            if ($id === 'INS11' && ($input['kb_mode'] ?? '') === 'category_proxy') $labels['numerator'] = 'Chamados com categoria vinculada à base de conhecimento';
            $body .= $this->keepWithNext($this->table(['Medição', 'Valor'], [
                [$labels['numerator'], OfficePackage::number($m['numerator'])],
                [$labels['denominator'], OfficePackage::number($m['denominator'])],
                ['Resultado', $m['value'] === null ? ($m['status'] === 'not_applicable' ? 'N/A' : 'Pendente') : OfficePackage::percent($m['value'])],
                ['Meta', ($m['direction'] === 'lte' ? '≤ ' : '≥ ') . OfficePackage::percent($m['target'])],
                ['Situação', OfficePackage::status($m)],
            ], [7100, 2400], 18));
            if (in_array($id, ['INS1', 'INS2', 'INS3', 'INS4'], true)) {
                $body .= $this->severityNarrative($m, $s);
            } else {
                $body .= $this->keepWithNext($this->p($this->metricNarrative($m, $s), '', false, 20));
            }
            $body .= $this->figure($figures[$id], 2800, false);
            if ($id === 'INS10') {
                $body .= $this->heading('5.10.1 Saídas consideradas na apuração', 2);
                $body .= $this->staffDepartures($s['staff_register'] ?? []);
            }
            if ($id === 'INS12') {
                $body .= $this->heading('5.12.1 Respostas por fonte', 2);
                $body .= $this->ins12Sources($m, $input);
                $body .= $this->surveyDistributions($s);
                if (trim((string)($input['phone_survey_actions'] ?? '')) !== '') {
                    $body .= $this->heading('5.12.4 Providências informadas', 2);
                    $body .= $this->text($input['phone_survey_actions']);
                }
            }
        }
        $body .= $this->page();
        $body .= $this->heading('6 Distribuição temporal da operação');
        $body .= $this->p('A evolução diária apresenta o volume de chamados solucionados. Os mapas de calor mostram o volume acumulado da competência por hora e dia da semana, conforme o horário local dos registros.');
        $body .= $this->figure($figures['tickets_daily'], 3500, false);
        foreach (array_values($heatmaps) as $index => $heatmap) {
            $body .= $this->page();
            $body .= $this->heading('6.' . ($index + 1) . ' ' . $heatmap['title'], 2);
            $body .= $this->heatmap($heatmap);
        }
        $body .= $this->page();
        $body .= $this->heading('7 Faturamento e ajustes financeiros');
        $body .= $this->financialSummary($s['financial'] ?? []);
        $body .= $this->heading('8 Propostas de melhoria e ações corretivas');
        $body .= $this->text(trim((string)($manual['actions'] ?? '')) ?: 'Não foram registradas ações gerenciais complementares nesta extração.');
        $body .= $this->heading('9 Lista de profissionais da competência');
        $body .= $this->staffRegister($s['staff_register'] ?? []);
        $body .= $this->heading('10 Observações e encerramento');
        $body .= $this->text(trim((string)($manual['notes'] ?? '')) ?: 'Não foram registradas observações complementares.');
        $body .= $this->heading('10.1 Fontes e evidências', 2);
        $body .= $this->p('Este relatório e a planilha de apoio utilizam a mesma extração, realizada em ' . OfficePackage::date($s['generated_at'] ?? '') . '. A planilha reúne os registros de cada indicador, as bases operacionais, as exclusões, a memória de cálculo e o cadastro completo de profissionais. Os ajustes informados pela gestão permanecem identificados separadamente dos resultados operacionais calculados.');
        $body .= $this->heading('11 Aprovação');
        $body .= $this->p('Responsável pela elaboração: ____________________________________');
        $body .= $this->p('Responsável pela validação: _____________________________________');
        $body .= $this->p('Data: ____/____/________');
        $section = '<w:sectPr><w:headerReference w:type="even" r:id="rId11"/><w:headerReference w:type="default" r:id="rId12"/><w:footerReference w:type="default" r:id="rId13"/><w:headerReference w:type="first" r:id="rId14"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1560" w:right="1134" w:bottom="1560" w:left="1134" w:header="709" w:footer="709"/><w:cols w:space="708"/></w:sectPr>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><w:body>' . $body . $section . '</w:body></w:document>';
    }

    private function displayPeriod(array $period): string
    {
        $month = (string)($period['month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $first = new \DateTimeImmutable($month . '-01');
            return $first->format('d/m/Y') . ' 00:00 a ' . $first->modify('last day of this month')->format('d/m/Y') . ' 23:59';
        }
        return OfficePackage::date($period['start'] ?? '') . ' a ' . OfficePackage::date($period['end_exclusive'] ?? '');
    }

    /** Managerial facts only: no inferred causes, approvals or historical attribution. */
    private function metricNarrative(array $m, array $s): string
    {
        if ($m['value'] === null) return ($m['status'] ?? '') === 'not_applicable'
            ? 'Não houve população aplicável a este indicador na competência.'
            : 'Não foi possível concluir este indicador com as informações disponíveis nesta extração. O detalhamento da limitação consta na planilha de apoio.';
        $n = OfficePackage::number($m['numerator']);
        $d = OfficePackage::number($m['denominator']);
        $result = OfficePackage::percent($m['value']);
        $conclusion = ($m['status'] ?? '') === 'met' ? 'A meta contratual foi atingida.' : 'A meta contratual não foi atingida.';
        $text = '';
        switch ($m['id']) {
            case 'INS1': case 'INS2': case 'INS3': case 'INS4':
                $text = 'Foram avaliados ' . $d . ' chamados' . ($m['id'] === 'INS4' ? ' dos grupos N1 e N2' : '') . ', dos quais ' . $n . ' não excederam o prazo de solução e ' . OfficePackage::number(max(0, (int)$m['denominator'] - (int)$m['numerator'])) . ' excederam esse prazo. O resultado de conformidade foi de ' . $result . '.';
                break;
            case 'INS5':
                $text = 'Entre os ' . $d . ' chamados solucionados considerados, ' . $n . ' apresentaram reabertura considerada ou execução incompleta, resultando em ' . $result . ' no indicador.';
                break;
            case 'INS6':
                $text = 'Dos ' . $d . ' chamados de categorias elegíveis ao primeiro nível, ' . $n . ' atenderam aos critérios de solução pelo N1. O índice de resolução no primeiro nível foi de ' . $result . '.';
                break;
            case 'INS7':
                $text = 'Das ' . $d . ' ligações consideradas, ' . $n . ' foram atendidas com duração de até 10 minutos, resultando em ' . $result . ' de conformidade. O Tempo Médio de Atendimento (TMA) das conversas com duração válida foi de ' . OfficePackage::duration($s['totals']['mean_talk_seconds'] ?? null) . '.';
                break;
            case 'INS8':
                $text = 'Das ' . $d . ' ligações consideradas, ' . $n . ' foram conectadas a um agente em até 30 segundos, resultando em ' . $result . ' de conformidade. O Tempo Médio de Espera (TME) antes da conexão foi de ' . OfficePackage::duration($s['totals']['mean_wait_seconds'] ?? null) . '.';
                break;
            case 'INS9':
                $text = 'Das ' . $d . ' ligações consideradas, ' . $n . ' foram abandonadas após mais de 30 segundos na fila, sem atendimento, resultando em ' . $result . ' de abandono.';
                break;
            case 'INS10':
                $staff = $s['staff_register'] ?? [];
                $text = 'A apuração considerou ' . $n . ' saídas entre ' . OfficePackage::date($staff['window_start'] ?? '') . ' e ' . OfficePackage::date($staff['window_end'] ?? '') . ' e ' . $d . ' profissionais faturados integral ou proporcionalmente na competência. O índice de rotatividade foi de ' . $result . '.';
                break;
            case 'INS11':
                $text = 'Dos ' . $d . ' chamados solucionados, ' . $n . (($s['input']['kb_mode'] ?? '') === 'category_proxy'
                    ? ' possuíam categoria associada à base de conhecimento, conforme a modalidade de apuração selecionada. Essa associação por categoria'
                    : ' possuíam vínculo direto com artigo da base de conhecimento constante da lista de artigos validados informada. A vinculação') . ' resultou em ' . $result . ' no indicador.';
                break;
            case 'INS12':
                $text = 'Das ' . $d . ' pesquisas válidas respondidas, ' . $n . ' receberam avaliações Satisfeito ou Muito satisfeito (notas 4 ou 5). O índice de satisfação foi de ' . $result . '. As fontes consideradas foram: ' . $this->ins12ModeLabel($s['input'] ?? []) . '.';
                break;
        }
        return $text . ' ' . $conclusion;
    }

    /** The official measurement remains unchanged; transfer exclusions are hypothetical. */
    private function severityNarrative(array $m, array $s): string
    {
        $id = $m['id'];
        $severity = substr($id, 3);
        $period = $s['period'] ?? [];
        $month = (string)($period['month'] ?? '');
        $months = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
        $label = ($months[substr($month, 5, 2)] ?? 'competência') . ' de ' . substr($month, 0, 4);
        $prefix = 'Durante o mês de ' . $label . ', ';
        if (($m['value'] ?? null) === null) {
            return $this->p($prefix . (($m['status'] ?? '') === 'not_applicable'
                ? 'não houve chamados classificados como Severidade ' . $severity . ' elegíveis à apuração. O indicador foi considerado não aplicável.'
                : 'não foi possível concluir a apuração dos chamados classificados como Severidade ' . $severity . '. O indicador permanece pendente.'), '', false, 20);
        }
        $d = (int)$m['denominator'];
        $n = (int)$m['numerator'];
        $late = max(0, $d - $n);
        $target = (float)$m['target'];
        $value = (float)$m['value'];
        $comparison = $value < $target ? 'inferior' : ($value > $target ? 'superior' : 'igual');
        $xml = $this->p($prefix . 'foram avaliados ' . OfficePackage::number($d) . ' chamados classificados como Severidade ' . $severity
            . ', dos quais ' . OfficePackage::number($n) . ' foram contabilizados como atendidos dentro do prazo, resultando no percentual de '
            . OfficePackage::percent($value) . ', ' . $comparison . ' à meta contratual de ≥ ' . OfficePackage::percent($target) . '.', '', false, 20);
        if (!$late) return $xml . $this->p('Não foram identificados chamados excedidos na competência.', '', false, 20);
        $review = $s['severity_review'][$id] ?? [];
        if (empty($review['history_available'])) return $xml . $this->p('Não foi possível verificar as transferências de entidade com o histórico disponível. Os ' . OfficePackage::number($late) . ' chamados excedidos permanecem na apuração original.', '', false, 20);
        $rows = $review['candidates'] ?? [];
        $k = count($rows);
        if (!$k) return $xml . $this->p('Dos ' . OfficePackage::number($late) . ' chamados excedidos, não foram identificadas transferências comprovadas da entidade SES à Central de Serviços no histórico consultado.', '', false, 20);
        if ($k > $late || $d < $k) return $xml . $this->p('A simulação não possui base de cálculo válida; os resultados originais permanecem preservados.', '', false, 20);
        $sim = $d > $k ? 100 * $n / ($d - $k) : null;
        if ($k === 1) {
            $transfer = $late === 1 ? 'o único chamado considerado excedido foi encaminhado' : '1 dos ' . OfficePackage::number($late) . ' chamados considerados excedidos foi encaminhado';
        } elseif ($k === $late) {
            $transfer = 'os ' . OfficePackage::number($k) . ' chamados considerados excedidos foram encaminhados';
        } else {
            $transfer = OfficePackage::number($k) . ' dos ' . OfficePackage::number($late) . ' chamados considerados excedidos foram encaminhados';
        }
        $xml .= $this->p('Após reanálise dos registros, observou-se que ' . $transfer
            . ' da entidade SES à Central de Serviços. A abertura registrada antecede o recebimento pela Central de Serviços. A parcela efetivamente consumida do prazo e a governabilidade sobre esse período devem ser avaliadas individualmente, considerando as pendências e eventuais transferências anteriores.', '', false, 20);
        $deduction = null;
        foreach ($s['financial']['deductions'] ?? [] as $item) if (($item['id'] ?? '') === $id) { $deduction = $item; break; }
        $amount = $deduction['amount'] ?? null;
        $simRate = $sim === null ? null : FinancialSummary::rate($id, $sim);
        $request = 'Diante disso, solicita-se à Fiscalização a reavaliação do ' . $id . ' quanto aos registros relacionados, considerando os períodos anteriores ao recebimento pela Central de Serviços.';
        if ($value < $target && $amount !== null && (float)$amount > 0) {
            $request .= $simRate === 0 ? ' Solicita-se a desconsideração da glosa de ' . $this->staffAmount($amount) . ', caso as exclusões sejam aceitas.'
                : ' Solicita-se a revisão da glosa de ' . $this->staffAmount($amount) . ', conforme a faixa contratual resultante da análise.';
        }
        if ($value >= $target && $amount !== null && (float)$amount === 0.0) {
            $request .= ' A meta contratual foi atingida e não há incidência de glosa neste indicador.';
        } elseif ($value >= $target) {
            $request .= ' A meta contratual foi atingida; a reavaliação tem finalidade de registro e análise de governabilidade.';
        }
        $xml .= $this->p($request, '', false, 20);
        $simulation = $sim === null ? 'não restará população aplicável ao indicador (N/A).' : 'o resultado simulado do indicador será de ' . OfficePackage::percent($sim) . ' de atendimento dentro do prazo.';
        $condition = $k === 1 ? 'Caso seja desconsiderado o registro apontado, ' : 'Caso sejam desconsiderados os ' . OfficePackage::number($k) . ' registros apontados, ';
        $conclusion = $value >= $target
            ? ' A revisão fica condicionada à análise e à manifestação favorável da Fiscalização, sem alterar a medição original.'
            : ' A revisão e eventual alteração da glosa ficam condicionadas à análise e à manifestação favorável da Fiscalização.';
        $xml .= $this->p($condition . $simulation . $conclusion, '', false, 20);
        $xml .= $this->keepWithNext($this->p($k === 1 ? 'Chamado submetido à reavaliação:' : 'Relação dos ' . OfficePackage::number($k) . ' chamados submetidos à reavaliação:', '', true, 20));
        $xml .= $this->keepWithNext($this->p($k === 1 ? 'Chamado no qual houve transferência de entidade.' : 'Chamados nos quais houve transferência de entidade.', '', false, 20));
        foreach ($rows as $row) {
            $line = ' — Chamado chegou à Central de Serviços em ' . $this->reviewDate($row['received_at']);
            foreach ($row['pending_at'] ?? [] as $pending) $line .= ', foi colocado em pendência em ' . $this->reviewDate($pending);
            $line .= ' e foi solucionado em ' . $this->reviewDate($row['solved_at']) . '.';
            $xml .= $this->reviewBullet((int)$row['id'], $line);
        }
        return $xml;
    }

    private function reviewBullet(int $id, string $line): string
    {
        $font = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:color w:val="' . ReportStyle::NAVY . '"/><w:sz w:val="20"/>';
        return '<w:p><w:pPr><w:keepLines/><w:widowControl/><w:spacing w:after="100" w:line="270" w:lineRule="auto"/><w:ind w:left="360" w:hanging="300"/></w:pPr>'
            . '<w:r><w:rPr>' . $font . '</w:rPr><w:t>•</w:t><w:tab/></w:r>'
            . '<w:r><w:rPr>' . $font . '<w:b/></w:rPr><w:t>' . $id . '</w:t></w:r>'
            . '<w:r><w:rPr>' . $font . '</w:rPr><w:t xml:space="preserve">' . OfficePackage::xml($line) . '</w:t></w:r></w:p>';
    }

    private function reviewDate(string $value): string
    {
        return substr(OfficePackage::date($value), 0, 10) . ' às ' . substr($value, 11, 5);
    }

    private function keepWithNext(string $xml): string
    {
        return preg_replace_callback('/<w:pPr>(.*?)<\/w:pPr>/s', static function (array $match): string {
            return strpos($match[1], '<w:keepNext') !== false ? $match[0] : '<w:pPr><w:keepNext/>' . $match[1] . '</w:pPr>';
        }, $xml);
    }

    private function staffRegister(array $staff): string
    {
        if (empty($staff['configured'])) return $this->p('Não há profissionais cadastrados nesta extração.');
        $relevant = array_values(array_filter($staff['rows'] ?? [], static function (array $row): bool {
            return !empty($row['relevant_month']);
        }));
        $xml = $this->p('A relação apresenta todos os profissionais com vínculo em pelo menos um dia da competência, incluindo os faturados integralmente, proporcionalmente e os desconsiderados do faturamento. Profissionais desligados em meses anteriores permanecem nas informações específicas do INS10 e no cadastro histórico.');
        $xml .= $this->monthlyStaffTable($relevant);
        $xml .= $this->p('Os dias faturados refletem o período de atuação na competência ou o ajuste mensal informado pela gestão. O valor faturado é proporcional aos dias corridos do mês, arredondado em centavos por profissional. Profissionais desconsiderados têm zero dias e zero valor faturado.', '', false, 18);
        $excluded = array_values(array_filter($relevant, static function (array $row): bool { return StaffRegister::isNotBilled($row); }));
        if ($excluded) {
            $xml .= $this->heading('9.1 Profissionais desconsiderados do faturamento', 2);
            $rows = [];
            foreach ($excluded as $row) $rows[] = [(string)($row['name'] ?? ''), 'Sim'];
            $rows[] = ['Total geral (profissionais)', OfficePackage::number(count($excluded))];
            $xml .= $this->table(['Nome', 'Profissional desconsiderado? (Não Faturado)'], $rows, [6700, 2800], 18);
        }
        return $xml;
    }

    private function monthlyStaffTable(array $staffRows): string
    {
        if (!$staffRows) return $this->p('Não há profissionais com vínculo nesta competência.');
        $rows = [];
        $days = $billedCents = 0;
        foreach ($staffRows as $index => $row) {
            $rows[] = [(string)($index + 1), (string)($row['name'] ?? ''), (string)($row['profile'] ?? ''),
                OfficePackage::number($row['billed_days'] ?? 0), $this->staffAmount($row['billed_value'] ?? 0)];
            $days += (int)($row['billed_days'] ?? 0);
            $billedCents += (int)round((float)($row['billed_value'] ?? 0) * 100);
        }
        $rows[] = ['Total geral', OfficePackage::number(count($staffRows)) . ' profissionais', '', OfficePackage::number($days), $this->staffAmount($billedCents / 100)];
        return $this->mergeTotalLabel($this->table(['Nº', 'Nome', 'Perfil Profissional', 'Dias Faturado', 'Valor Faturado'], $rows, [550, 2800, 3350, 1000, 1800], 18), 3, 6700, 'Total geral (' . count($staffRows) . ' profissionais)');
    }

    private function staffDepartures(array $staff): string
    {
        $rows = [];
        foreach ($staff['rows'] ?? [] as $row) {
            if (empty($row['counted_departure'] ?? $row['counted_replacement'] ?? false)) continue;
            $rows[] = [(string)($row['name'] ?? ''), OfficePackage::date($row['departure_date'] ?? ''), 'Sim'];
        }
        if (!$rows) return $this->p('Não houve saídas consideradas na janela de três meses desta apuração.');
        $rows[] = ['Total geral (registros)', OfficePackage::number(count($rows)), ''];
        return $this->table(['Nome', 'Data de saída', 'Saída considerada no INS10'], $rows, [4900, 1700, 2900], 18, [], 'center');
    }

    private function frontComposition(array $financial, array $staff): string
    {
        $xml = '';
        if (!empty($financial['contract_fronts'])) {
            $xml .= $this->heading('1.1 Composição contratual de referência', 2);
            $rows = [];
            foreach ($financial['contract_fronts'] as $index => $front) {
                $rows[] = [(string)($index + 1), (string)$front['label'], 'Mensal',
                    OfficePackage::number($front['contract_quantity']), $this->staffAmount($front['contract_value'])];
            }
            $rows[] = ['Total geral', '', '', OfficePackage::number($financial['contract_quantity_total'] ?? 0), $this->staffAmount($financial['contract_value_total'] ?? 0)];
            $xml .= $this->compositionTable($rows, 'Qtde. profissionais', 'Valor mensal do item');
        }
        // The reference contract is independent of the monthly staff register.
        if (empty($financial['available'])) return $xml;
        $xml .= $this->heading((empty($financial['contract_fronts']) ? '1.1' : '1.2') . ' Composição do faturamento da competência', 2);
        $xml .= $this->p('Foram considerados ' . OfficePackage::number($staff['billing_professionals_total'] ?? 0) . ' profissionais com faturamento integral ou proporcional no mês. A distribuição a seguir utiliza as frentes de serviço informadas no cadastro.');
        $rows = [];
        foreach ($financial['monthly_fronts'] ?? [] as $index => $front) {
            $rows[] = [(string)($index + 1), (string)$front['label'], 'Mensal',
                OfficePackage::number($front['professionals']), $this->staffAmount($front['billed_value'])];
        }
        $rows[] = ['Total geral', '', '', OfficePackage::number($staff['billing_professionals_total'] ?? 0), $this->staffAmount($financial['gross_value'] ?? 0)];
        $xml .= $this->compositionTable($rows, 'Qtde. profissionais', 'Valor faturado');
        if (empty($financial['contract_fronts'])) $xml .= $this->p('A composição contratual de referência não foi informada nesta extração.', '', false, 18);
        return $xml;
    }

    /** Parallel opening tables show each service-front total, never a blended unit rate. */
    private function compositionTable(array $rows, string $quantityLabel, string $valueLabel): string
    {
        return $this->mergeTotalLabel($this->table(
            ['Item', 'Especificação', 'Métrica', $quantityLabel, $valueLabel],
            $rows, [700, 4700, 850, 1350, 1900], 18
        ), 3, 6250, 'Total geral');
    }

    private function financialSummary(array $financial): string
    {
        if (empty($financial['available'])) return $this->p('A base de profissionais necessária ao faturamento não está disponível nesta extração.');
        $xml = $this->p('O valor bruto corresponde ao faturamento proporcional dos profissionais da competência. As glosas calculadas seguem as faixas contratuais de cada indicador. Ajustes aprovados informados pela gestão são apresentados separadamente e não alteram o resultado operacional dos indicadores.');
        $rows = $overrides = [];
        foreach ($financial['deductions'] ?? [] as $deduction) {
            $rows[] = [$deduction['id'], ($deduction['direction'] === 'lte' ? '≤ ' : '≥ ') . OfficePackage::percent($deduction['target']),
                OfficePackage::percent($deduction['value']), OfficePackage::percent($deduction['calculated_rate']),
                OfficePackage::percent($deduction['applied_rate']), $this->staffAmount($deduction['amount'])];
            if (!empty($deduction['override'])) $overrides[] = [$deduction['id'], (string)($deduction['justification'] ?? '')];
        }
        $rows[] = ['Total geral', '', '', OfficePackage::percent($financial['calculated_rate_total'] ?? 0), OfficePackage::percent($financial['applied_rate_total'] ?? 0), $this->staffAmount($financial['deduction_total'] ?? 0)];
        $xml .= $this->heading('7.1 Resultado financeiro dos indicadores', 2);
        $xml .= $this->mergeTotalLabel($this->table(['INS', 'Meta', 'Resultado', 'Glosa calculada', 'Glosa aplicada', 'Valor da glosa'], $rows, [900, 1250, 1350, 1700, 1700, 2600], 18), 3, 3500, 'Total geral');
        if ($overrides) {
            $xml .= $this->heading('7.2 Ajustes de glosa informados', 2);
            $xml .= $this->table(['INS', 'Justificativa do ajuste aprovado informado'], $overrides, [850, 8650], 18);
        }
        if (!empty($financial['adjustments'])) {
            $xml .= $this->heading($overrides ? '7.3 Regularizações informadas' : '7.2 Regularizações informadas', 2);
            $adjustments = [];
            foreach ($financial['adjustments'] as $row) $adjustments[] = [(string)$row['description'], $this->staffAmount($row['amount'])];
            $adjustments[] = ['Total geral dos ajustes', $this->staffAmount($financial['adjustment_total'] ?? 0)];
            $xml .= $this->table(['Descrição', 'Crédito (+) / débito (−)'], $adjustments, [6900, 2600], 18);
        }
        $xml .= $this->table(['Consolidação do faturamento', 'Valor'], [
            ['Valor bruto da competência', $this->staffAmount($financial['gross_value'] ?? 0)],
            ['Glosas dos indicadores', $this->staffAmount($financial['deduction_total'] ?? 0)],
            ['Regularizações informadas', $this->staffAmount($financial['adjustment_total'] ?? 0)],
            ['Total líquido' . (empty($financial['complete']) ? ' — cálculo incompleto' : ''), $this->staffAmount($financial['net_value'] ?? null)],
        ], [6900, 2600], 20);
        $xml .= $this->p('Os valores são arredondados a centavos por profissional e por glosa. A planilha de apoio apresenta o detalhamento financeiro e os ajustes informados pela gestão.', '', false, 18);
        if (empty($financial['complete'])) $xml .= $this->p('A consolidação financeira está incompleta. Confira os indicadores sem apuração ou o cadastro da equipe antes de concluir o faturamento.', '', false, 18);
        return $xml;
    }

    /** Keep total labels readable when the leading column is only an index. */
    private function mergeTotalLabel(string $xml, int $span, int $width, string $label): string
    {
        preg_match_all('/<w:tr>.*?<\/w:tr>/s', $xml, $matches);
        $last = end($matches[0]);
        if (!is_string($last)) return $xml;
        preg_match_all('/<w:tc>.*?<\/w:tc>/s', $last, $cells);
        if (count($cells[0]) < $span) return $xml;
        $paragraph = str_replace('w:after="140" w:line="270"', 'w:after="20" w:line="220"', $this->p($label, '', true, 18, 'left'));
        $merged = '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/><w:gridSpan w:val="' . $span . '"/><w:shd w:val="clear" w:fill="' . ReportStyle::POSITIVE_FILL . '"/><w:vAlign w:val="center"/></w:tcPr>' . $paragraph . '</w:tc>';
        $replacement = str_replace(implode('', array_slice($cells[0], 0, $span)), $merged, $last);
        $position = strrpos($xml, $last);
        return substr_replace($xml, $replacement, $position, strlen($last));
    }

    private function staffAmount($value): string
    {
        return $value === null || $value === '' ? 'Não informado' : 'R$ ' . number_format((float)$value, 2, ',', '.');
    }

    private function ins12ModeLabel(array $input): string
    {
        return ($input['ins12_mode'] ?? 'glpi_and_phone') === 'glpi_and_phone'
            ? 'GLPI e pesquisa telefônica' : 'Somente GLPI; pesquisa telefônica não incluída';
    }

    private function ins12Sources(array $metric, array $input): string
    {
        $xml = $this->p('Fontes desta apuração: ' . $this->ins12ModeLabel($input), '', true, 19);
        $rows = [];
        foreach (['tickets' => 'Pesquisa GLPI', 'phone' => 'Pesquisa telefônica'] as $key => $label) {
            $counts = $metric['source_counts'][$key] ?? [];
            $available = !empty($counts['available']);
            $included = $key === 'tickets' || !empty($counts['included']);
            $rows[] = [$label, $included ? ($available ? 'Incluída' : 'Incluída / indisponível') : 'Não incluída',
                $available ? OfficePackage::number($counts['numerator'] ?? 0) : 'Não disponível',
                $available ? OfficePackage::number($counts['denominator'] ?? 0) : 'Não disponível'];
        }
        $rows[] = ['Total oficial do INS12', OfficePackage::status($metric),
            $metric['numerator'] === null ? 'Pendente' : OfficePackage::number($metric['numerator']),
            $metric['denominator'] === null ? 'Pendente' : OfficePackage::number($metric['denominator'])];
        $xml .= $this->table(['Fonte', 'Participação', 'Satisfeito / muito satisfeito (4 ou 5)', 'Pesquisas respondidas (1 a 5)'], $rows, [2600, 2500, 2300, 2100], 18);
        return $xml;
    }

    private function surveyDistributions(array $snapshot): string
    {
        $xml = $this->heading('5.12.2 Pesquisa de satisfação dos chamados GLPI', 2);
        $tickets = ChartData::ticketSurveyDistribution($snapshot);
        $xml .= $this->surveyDistributionTable($tickets, 'Pesquisa de satisfação — GLPI');
        $xml .= $this->p($tickets['available']
            ? 'Conferência de todos os chamados solucionados na competência. Não preenchida identifica pesquisa sem nota; não é nota zero nem resposta desfavorável. Somente respostas válidas de 1 a 5 participam do percentual, sendo favoráveis as notas 4 e 5.'
            : 'A fonte de pesquisas GLPI não estava disponível. O total representa os chamados do recorte, sem determinar o preenchimento de suas pesquisas.', '', false, 18);

        $xml .= $this->heading('5.12.3 Pesquisa de satisfação da telefonia', 2);
        $phone = ChartData::phoneSurveyDistribution($snapshot);
        $xml .= $this->surveyDistributionTable($phone, 'Pesquisa de satisfação — Telefonia');
        $included = ($snapshot['input']['ins12_mode'] ?? 'glpi_and_phone') === 'glpi_and_phone';
        $xml .= $this->p(($included ? 'Telefonia incluída nesta apuração. ' : 'Quadro de conferência: telefonia não incluída no percentual selecionado. ')
            . ($phone['available']
                ? 'Cada ligação distinta após exclusões de teste aparece uma vez, com a nota considerada na mesma escala de satisfação do GLPI. Não preenchida e nota inválida não entram no percentual.'
                : 'A fonte telefônica não estava disponível; a ausência de dados não é interpretada como ausência de respostas.')
            . ' Os totais GLPI e telefonia representam universos distintos; não são um total de pessoas.', '', false, 18);
        return $xml;
    }

    private function surveyDistributionTable(array $distribution, string $label): string
    {
        $rows = array_map(static function (array $row): array {
            return [$row[0], OfficePackage::number($row[1])];
        }, $distribution['rows']);
        $rows[] = ['Total geral', OfficePackage::number($distribution['total'])];
        return $this->table([$label, 'Quantidade'], $rows, [7300, 2200], 18);
    }

    private function figure(array $definition, int $height, bool $showNote = true): string
    {
        if (empty($definition['available'])) {
            return $this->p('Gráfico não apresentado: ' . ($definition['note'] ?? 'medição pendente ou não aplicável.'), '', false, 20);
        }
        // A chart ends its visual block. Keeping it with the following heading
        // would join every indicator into one unbreakable pagination chain.
        $xml = str_replace('<w:keepNext/>', '', $this->charts->paragraph($definition, $height));
        return $xml . ($showNote ? $this->p((string)($definition['note'] ?? ''), '', false, 19) : '');
    }


    private function heatmap(array $definition): string
    {
        if (empty($definition['available'])) {
            return $this->p('Mapa de calor não apresentado: ' . $definition['note'], '', false, 20);
        }
        $maximum = max(1, (int)$definition['maximum']);
        $xml = $this->p('Volume representado: ' . OfficePackage::number($definition['total']) . ' | Registros fora da matriz: ' . OfficePackage::number($definition['omitted']), '', false, 20);
        $xml .= '<w:tbl><w:tblPr><w:tblW w:w="9500" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
            $xml .= '<w:' . $edge . ' w:val="single" w:sz="4" w:color="' . ReportStyle::BORDER . '"/>';
        }
        $xml .= '</w:tblBorders><w:tblCellMar><w:top w:w="65" w:type="dxa"/><w:left w:w="60" w:type="dxa"/><w:bottom w:w="65" w:type="dxa"/><w:right w:w="60" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="1100"/>' . str_repeat('<w:gridCol w:w="1200"/>', 7) . '</w:tblGrid>';
        $rows = array_merge([array_merge(['Hora'], $definition['column_labels'])], array_map(static function ($label, $values) { return array_merge([$label], $values); }, $definition['row_labels'], $definition['values']));
        $totals = ['Total geral'];
        foreach ($definition['column_labels'] as $weekday => $label) $totals[] = array_sum(array_column($definition['values'], $weekday));
        $rows[] = $totals;
        foreach ($rows as $rowIndex => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($rowIndex === 0 ? '<w:tblHeader/>' : '') . '</w:trPr>';
            foreach ($row as $columnIndex => $value) {
                $heading = $rowIndex === 0 || $columnIndex === 0 || $rowIndex === count($rows) - 1;
                $ratio = $heading ? 0 : (int)$value / $maximum;
                $fill = $heading ? ReportStyle::NAVY : ReportStyle::heatmapColor($ratio);
                $color = $heading ? ReportStyle::WHITE : ReportStyle::NAVY;
                $text = $heading ? (string)$value : OfficePackage::number((int)$value);
                $paragraph = str_replace('w:after="140" w:line="270"', 'w:after="0" w:line="210"', $this->p($text, '', $heading, 19, 'center', $color));
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . ($columnIndex === 0 ? '1100' : '1200') . '" w:type="dxa"/><w:shd w:val="clear" w:fill="' . $fill . '"/><w:vAlign w:val="center"/></w:tcPr>' . $paragraph . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl>' . $this->p('Escala própria deste mapa: 0 (branco) → ' . OfficePackage::number($maximum) . ' (azul). Células mais escuras representam maior volume; os valores exatos permanecem visíveis.', '', false, 18);
        return $xml . $this->p($definition['note'], '', false, 19);
    }

    private function overview(array $metrics, string $direction): array
    {
        $categories = [];
        $values = [];
        $targets = [];
        foreach ($metrics as $metric) {
            if (($metric['direction'] ?? '') !== $direction || $metric['value'] === null) {
                continue;
            }
            $categories[] = $metric['id'];
            $values[] = $metric['value'] / 100;
            $targets[] = $metric['target'] / 100;
        }
        return [
            'id' => 'overview_' . $direction,
            'title' => $direction === 'gte' ? 'Indicadores com meta mínima' : 'Indicadores com limite máximo',
            'categories' => $categories,
            'series' => [
                ['name' => 'Resultado', 'values' => $values, 'color' => ReportStyle::BLUE],
                ['name' => $direction === 'gte' ? 'Meta mínima' : 'Limite máximo', 'values' => $targets, 'color' => ReportStyle::NAVY],
            ],
            'kind' => 'bar', 'stacked' => false, 'unit' => 'percent', 'available' => count($categories) > 0,
            'note' => $direction === 'gte'
                ? 'Regra: resultado maior ou igual à meta. O método e eventuais informações necessárias ausentes estão descritos na medição de cada indicador.'
                : 'Regra: resultado menor ou igual ao limite. Valores menores são favoráveis; zero é um resultado válido quando há população para apuração.',
        ];
    }

    private function text(string $text): string
    {
        $xml = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (trim($line) !== '') {
                $xml .= $this->p($line);
            }
        }
        return $xml;
    }

    private function heading(string $text, int $level = 1): string
    {
        return $this->p($text, 'Heading' . $level, true, $level === 1 ? 28 : 23, 'left');
    }

    private function p(string $text, string $style = '', bool $bold = false, int $size = 21, string $align = 'both', string $color = ReportStyle::NAVY): string
    {
        return '<w:p><w:pPr>' . ($style !== '' ? '<w:pStyle w:val="' . $style . '"/>' : '') . '<w:jc w:val="' . $align . '"/><w:widowControl/><w:spacing w:after="140" w:line="270" w:lineRule="auto"/>' . ($style === 'Heading1' || $style === 'Heading2' ? '<w:keepNext/>' : '') . '</w:pPr><w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>' . ($bold ? '<w:b/>' : '') . '<w:color w:val="' . ($style !== '' ? ReportStyle::NAVY : $color) . '"/><w:sz w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . OfficePackage::xml($text) . '</w:t></w:r></w:p>';
    }

    private function page(): string
    {
        return '<w:p><w:pPr><w:pageBreakBefore/><w:spacing w:after="0" w:before="0"/></w:pPr></w:p>';
    }

    /** Color only confirmed target violations, using the calculated status. */
    private function metricsSummary(array $snapshot): string
    {
        $rows = [];
        $violatedRows = [];
        foreach ($snapshot['metrics'] as $metric) {
            $rows[] = [$metric['id'], MetricEvidence::title($metric['id'], $snapshot['input']['priority_map'][$metric['id']] ?? null), ($metric['direction'] === 'lte' ? '≤ ' : '≥ ') . OfficePackage::percent($metric['target']), $metric['value'] === null ? ($metric['status'] === 'not_applicable' ? 'N/A' : 'Pendente') : OfficePackage::percent($metric['value']), OfficePackage::status($metric)];
            $violatedRows[] = ($metric['status'] ?? '') === 'unmet';
        }
        return $this->table(['INS', 'Indicador', 'Meta', 'Resultado', 'Situação'], $rows, [850, 4050, 1150, 1250, 2200], 18, $violatedRows);
    }

    private function table(array $headers, array $rows, array $widths, int $size = 20, array $violatedRows = [], string $align = 'left'): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9500" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
            $xml .= '<w:' . $edge . ' w:val="single" w:sz="4" w:color="' . ReportStyle::BORDER . '"/>';
        }
        $xml .= '</w:tblBorders><w:tblCellMar><w:top w:w="65" w:type="dxa"/><w:left w:w="95" w:type="dxa"/><w:bottom w:w="65" w:type="dxa"/><w:right w:w="95" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $width) {
            $xml .= '<w:gridCol w:w="' . $width . '"/>';
        }
        $xml .= '</w:tblGrid>';
        foreach (array_merge([$headers], $rows) as $i => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($i === 0 ? '<w:tblHeader/>' : '') . '</w:trPr>';
            $violated = $i > 0 && ($violatedRows[$i - 1] ?? false);
            foreach ($row as $j => $cell) {
                $total = $i > 0 && strpos((string)($row[0] ?? ''), 'Total ') === 0;
                $fill = $i === 0 ? ReportStyle::NAVY : ($total || $i % 2 === 0 ? ReportStyle::POSITIVE_FILL : ReportStyle::WHITE);
                $color = $i === 0 ? ReportStyle::WHITE : ($violated ? self::VIOLATION_TEXT : ReportStyle::NAVY);
                $bold = $i === 0 || $total || ($violated && $j === count($row) - 1);
                $cellParagraph = str_replace('w:after="140" w:line="270"', 'w:after="20" w:line="220"', $this->p((string)$cell, '', $bold, $size, $align, $color));
                if (count($rows) <= 14 && $i < count($rows)) $cellParagraph = $this->keepWithNext($cellParagraph);
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$j] . '" w:type="dxa"/><w:shd w:val="clear" w:fill="' . $fill . '"/><w:vAlign w:val="center"/></w:tcPr>' . $cellParagraph . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl><w:p><w:pPr><w:spacing w:after="80"/></w:pPr></w:p>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="21"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/><w:sz w:val="40"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="180"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200" w:after="140"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/></w:rPr></w:style></w:styles>';
    }
}
