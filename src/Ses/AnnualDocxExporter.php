<?php

namespace GlpiPlugin\G4freports\Ses;

/** Comparative report over compact, pinned monthly archives. No extraction or KPI recalculation. */
final class AnnualDocxExporter
{
    private WordChart $charts;
    private array $metricNotes = [];

    public function filename(array $report): string
    {
        $months = array_column($report['months'] ?? [], 'month');
        return 'Relatorio_Comparativo_SES_' . preg_replace('/[^0-9-]/', '', (string)($months[0] ?? ''))
            . '_a_' . preg_replace('/[^0-9-]/', '', (string)($months[count($months) - 1] ?? '')) . '.docx';
    }

    public function toBinary(array $report): string
    {
        $months = $report['months'] ?? [];
        if (!is_array($months) || count($months) < 1 || count($months) > 24 || count($report['metrics'] ?? []) > 12
            || count($report['financial']['months'] ?? []) > 24) {
            throw new \InvalidArgumentException('Selecione de 1 a 24 competências.');
        }
        $previous = '';
        foreach ($months as $month) {
            $key = (string)($month['month'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $key) || $key <= $previous) {
                throw new \InvalidArgumentException('As competências devem ser únicas e estar em ordem cronológica.');
            }
            $previous = $key;
        }
        foreach ($report['metrics'] ?? [] as $id => $metric) {
            if (!preg_match('/^INS([1-9]|1[0-2])$/D', (string)$id) || count($metric['months'] ?? []) > 24) {
                throw new \InvalidArgumentException('Indicador ou número de competências inválido.');
            }
        }
        $binary = @file_get_contents(dirname(__DIR__, 2) . '/resources/docx/papel_timbrado_G4F.docx');
        if (!is_string($binary)) throw new \RuntimeException('O papel timbrado G4F não foi encontrado no pacote do plugin.');
        $files = OfficePackage::read($binary);
        if (!isset($files['word/document.xml'], $files['word/_rels/document.xml.rels'])) {
            throw new \RuntimeException('O modelo G4F não contém os componentes necessários.');
        }
        $this->charts = new WordChart();
        $this->metricNotes = [];
        $files['word/document.xml'] = $this->document($report);
        $files['word/styles.xml'] = $this->styles();
        $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Relatório comparativo SES</dc:title><dc:creator>G4F</dc:creator><dc:description>'
            . OfficePackage::xml(implode(', ', array_column($months, 'month'))) . '</dc:description></cp:coreProperties>';
        $this->charts->attach($files);
        return OfficePackage::write($files);
    }

    private function document(array $report): string
    {
        $a = AnnualAnalytics::build($report);
        $months = $a['months'];
        $body = $this->p('Relatório comparativo gerencial SES DF', 'Title', true, 34, 'left');
        $body .= $this->p('Contrato SES/DF 053592/2025 · ' . $months[0] . ' a ' . end($months), '', true, 21, 'left');
        $c = $a['coverage'];
        $body .= $this->p($c['selected'] . ' competências selecionadas · ' . $c['archived'] . ' revisões arquivadas · ' . $c['closed'] . ' meses encerrados', '', true, 21, 'left');
        $body .= $this->p('Evolução dos volumes, tempos de atendimento, indicadores e valores financeiros das revisões mensais selecionadas. As observações abaixo descrevem variações registradas; não atribuem causas.', '', false, 20, 'left');
        $body .= $this->heading('Principais observações', 2);
        foreach (array_slice($a['findings'], 0, 5) as $finding) $body .= $this->p('• ' . $finding['text'], '', false, 20, 'left');
        $body .= $this->heading('Situação dos indicadores por competência', 2);
        $body .= $this->matrix($a);
        $body .= $this->p('A = atingida · N = não atingida · P = provisório · Pd = pendente · NA = não aplicável · ? = encerramento não confirmado · — = ausente. A cor e a letra reproduzem a situação mensal; não substituem a verificação da comparabilidade.', '', false, 17, 'left');
        $body .= $this->p('Leitura dos gráficos: azul = resultado comparável; amarelo = provisório; cinza = sem comparação confirmada; linha tracejada = meta arquivada. Linhas só unem meses consecutivos com critérios equivalentes. Datas afastadas mantêm seu intervalo real. Não há indicador anual agregado nem média de percentuais mensais.', '', false, 18, 'left');

        $body .= $this->page() . $this->heading('Volumes mensais de atendimento');
        $body .= $this->p('Chamados solucionados e ligações consideradas aparecem em escalas separadas e no mesmo eixo de datas. Ligações de teste excluídas são informadas na tabela.', '', false, 19, 'left');
        foreach (['tickets', 'calls'] as $id) {
            if ($id === 'calls' && count($months) > 12) $body .= $this->page() . $this->heading('Volumes mensais de telefonia');
            $body .= $this->trendChart($a['volumes'][$id], 2850);
        }
        $rows = [];
        foreach ($months as $i => $month) $rows[] = [$month, $this->number($a['volumes']['tickets']['rows'][$i]['value']), $this->number($a['volumes']['calls']['rows'][$i]['value']), $this->number($a['volumes']['excluded_calls']['rows'][$i]['value'])];
        if (count($rows) > 12) {
            $paired=[]; foreach(array_slice($rows,0,12) as $i=>$row) $paired[]=array_merge($row,$rows[$i+12]??['','','','']);
            $body.=$this->table(['Mês','Chamados','Ligações','Testes','Mês','Chamados','Ligações','Testes'],$paired,[1050,1300,1300,1100,1050,1300,1300,1100],[],16);
        } else $body .= $this->table(['Mês', 'Chamados solucionados', 'Ligações consideradas', 'Testes excluídos'], $rows, [1400,2700,2700,2700], [], 17);

        $body .= $this->page() . $this->heading('Tempos médios mensais e amostras');
        $body .= $this->p('TMA e TME preservam os tempos médios arquivados e suas próprias amostras válidas. Não são calculadas médias entre competências.', '', false, 19, 'left');
        foreach (['tma', 'tme'] as $id) {
            if ($id === 'tme' && count($months) > 12) $body .= $this->page() . $this->heading('Tempo médio de espera e amostras');
            $body .= $this->trendChart($a['durations'][$id], 2700);
        }
        $rows = [];
        foreach ($months as $i => $month) {
            $t = $a['durations']['tma']['rows'][$i]; $w = $a['durations']['tme']['rows'][$i];
            $rows[] = [$month, $this->duration($t['value']), $this->number($t['sample_count']), $this->duration($w['value']), $this->number($w['sample_count'])];
        }
        if (count($rows) > 12) {
            $paired=[]; foreach(array_slice($rows,0,12) as $i=>$row) $paired[]=array_merge($row,$rows[$i+12]??['','','','','']);
            $body.=$this->p('n = quantidade de amostras válidas. Tempo em h:mm:ss; segundos entre parênteses.','',false,17,'left');
            $body.=$this->table(['Mês','TMA','n','TME','n','Mês','TMA','n','TME','n'],$paired,[800,1450,550,1450,500,800,1450,550,1450,500],[],14,[],true);
        } else $body .= $this->table(['Mês', 'TMA (tempo; segundos)', 'Conversas válidas', 'TME (tempo; segundos)', 'Esperas válidas'], $rows, [1200,2700,1450,2700,1450], [], 17);

        foreach (array_chunk(array_values($a['metrics']), count($months) > 8 ? 1 : 2) as $pair) {
            if (!array_filter($pair,static fn($t)=>array_filter($t['rows'],static fn($r)=>$r['value']!==null || $r['target']!==null))) continue;
            $body .= $this->page() . $this->heading('Evolução dos indicadores');
            foreach ($pair as $trend) {
                $body .= $this->heading($trend['id'] . ' — ' . $trend['label'], 2);
                $body .= $this->trendChart($trend, count($months) > 16 ? 3200 : 3600, $trend['id'] . ' · resultado e meta mensal');
                $body .= $this->p($this->trendCaption($trend), '', false, 18, 'left');
            }
        }
        $body .= $this->page() . $this->heading('Evolução financeira mensal');
        $moneyTrend = $a['financial']['trends']['net'];
        $moneyTrend['label'] = 'Base faturada e valor líquido'; $moneyTrend['chart']['series'] = [];
        foreach (['gross'=>'Base faturada','net'=>'Valor líquido'] as $id=>$label) foreach ($a['financial']['trends'][$id]['chart']['series'] as $series) {
            $series['name'] = $label . (['provisional'=>' · provisório','unverified'=>' · sem comparação'][$series['role']] ?? '');
            $series['legend_key'] = $id . ':' . $series['role'];
            $series['color'] = $id === 'gross' ? ReportStyle::BLUE : ReportStyle::NAVY;
            $series['label_position'] = $id === 'gross' ? 't' : 'b';
            $moneyTrend['chart']['series'][] = $series;
        }
        $body .= $this->trendChart($moneyTrend, 3000);
        $body .= $this->financial($report['financial'] ?? []);
        $body .= $this->page() . $this->heading('Distribuição diária dos atendimentos');
        $body .= $this->p('Cada célula identifica o dia na coluna e a competência na linha. A intensidade é relativa ao maior valor diário desta seleção, separadamente para chamados e ligações. Os volumes diários seguem as datas e as exclusões da revisão mensal.', '', false, 19, 'left');
        $body .= $this->p('Cada célula mostra a contagem exata: 0 = nenhum registro atribuído ao dia · ? = desconhecido, não preparado ou futuro · × = fora do calendário · * = cobertura parcial ou provisória. Os três blocos de dias usam a mesma escala por fonte.', '', false, 17, 'left');
        $body .= $this->heatmap($a['activity'], 'tickets', 'Chamados por dia de solução', $a['volumes']['tickets']['rows']);
        $body .= $this->page();
        $body .= $this->heatmap($a['activity'], 'calls', 'Ligações por dia de entrada na fila', $a['volumes']['calls']['rows']);
        $body .= $this->activityNotes($a['activity']);

        $body .= $this->page() . $this->heading('Valores e bases arquivados dos indicadores');
        $body .= $this->p('Em cada célula: resultado; meta com sentido; numerador/denominador. “—” preserva a ausência de valor. Resultados e metas também acompanham os gráficos nativos editáveis. A situação mensal consta da matriz inicial.', '', false, 18, 'left');
        foreach (array_chunk($months, 6) as $part => $chunk) {
            $rows = []; $offset = $part * 6;
            foreach ($a['metrics'] as $trend) {
                $cells = [$trend['id']];
                foreach (array_slice($trend['rows'], $offset, 6) as $r) $cells[] = $this->percent($r['value']) . "\n" . (($r['target'] ?? null) === null ? '—' : (($r['direction'] ?? '') === 'lte' ? '≤ ' : '≥ ') . $this->percent($r['target'])) . "\n" . $this->number($r['numerator']) . '/' . $this->number($r['denominator']);
                $rows[] = $cells;
            }
            $body .= $this->table(array_merge(['Indicador'], $chunk), $rows, array_merge([1100], array_fill(0,count($chunk),(int)floor(8400/count($chunk)))), [], 16);
        }
        $body .= $this->heading('Métodos e limites de comparação');
        $body .= $this->criteria($report, $a);
        $body .= $this->heading('Informações de apoio das competências');
        foreach ($report['warnings'] ?? [] as $warning) $body .= $this->p((string)$warning, '', false, 18, 'left');
        foreach ($report['months'] as $row) $body .= $this->monthlyNotes($row);
        $section = '<w:sectPr><w:headerReference w:type="even" r:id="rId11"/><w:headerReference w:type="default" r:id="rId12"/><w:footerReference w:type="default" r:id="rId13"/><w:headerReference w:type="first" r:id="rId14"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1560" w:right="1134" w:bottom="1560" w:left="1134" w:header="709" w:footer="709"/><w:cols w:space="708"/></w:sectPr>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><w:body>' . $body . $section . '</w:body></w:document>';
    }

    private function matrix(array $a): string
    {
        $body = '';
        foreach (array_chunk($a['months'], 12) as $part => $months) {
            $rows = []; $fills = [];
            foreach ($a['matrix'] as $metric) {
                $cells = [$metric['id']]; $colors = [ReportStyle::WHITE];
                foreach (array_slice($metric['cells'], $part * 12, 12) as $cell) {
                    $status = $cell['status']; $period = $cell['period_completeness'];
                    $code = ['met'=>'A','unmet'=>'N','provisional'=>'P','pending'=>'Pd','not_applicable'=>'NA'][$status] ?? '—';
                    if ($status !== 'missing' && $period !== 'complete') $code = $period === 'provisional' ? 'P' : '?';
                    $cells[] = $code;
                    $colors[] = ['A'=>'E7EDFF','N'=>'F8DADA','P'=>'FFF2A9','Pd'=>'FFF2A9','NA'=>'F1F1F1','?'=>'E5E7EB']['' . $code] ?? 'FFFFFF';
                }
                $rows[] = $cells; $fills[] = $colors;
            }
            $body .= $this->table(array_merge(['INS'], array_map(fn($m)=>substr($m,5).'/'.substr($m,2,2),$months)), $rows, array_merge([860],array_fill(0,count($months),(int)floor(8640/count($months)))), [], count($a['months']) > 12 ? 16 : 17, $fills, count($a['months']) > 12);
        }
        return $body;
    }

    private function trendChart(array $trend, int $height, ?string $title = null): string
    {
        $series = []; $unsafe = false;
        foreach ($trend['chart']['series'] as $s) {
            $role = $s['role'];
            $s['color'] = $s['color'] ?? ['result'=>ReportStyle::BLUE,'target'=>ReportStyle::NAVY,'provisional'=>'B58A00','unverified'=>'777F88'][$role] ?? ReportStyle::BLUE;
            $s['marker_size'] = 4;
            if ($role === 'target') $s['line_width'] = 12700;
            $s['values'] = array_map(function($v) use ($trend, &$unsafe) {
                if ($v === null) return null;
                // Keep exact large amounts in the table instead of drawing a rounded monetary point.
                if ($trend['unit'] === 'cents' && abs($v) > 999999999999999) { $unsafe = true; return null; }
                return $v / (['percent'=>100,'seconds'=>86400,'cents'=>100][$trend['unit']] ?? 1);
            }, $s['values']);
            if (count(array_filter($s['values'], static fn($v)=>$v !== null))) $series[] = $s;
        }
        if (!$series) return $this->p($trend['label'] . ': sem valores disponíveis para o gráfico.' . ($unsafe ? ' Os valores monetários exatos estão na tabela.' : ''), '', false, 18, 'left');
        $d = ['kind'=>'line','unit'=>$trend['unit'],'available'=>true,'title'=>$title ?? $trend['label'],
            'categories'=>$trend['chart']['categories'],'series'=>$series,'date_axis'=>true,'category_padding'=>true,'category_format'=>'mm/yy','show_data_labels'=>true,
            'data_label_policy'=>'all','force_legend'=>true,'show_legend'=>true,
            'label_format'=>['percent'=>'[$-416]0.00%','count'=>'[$-416]#,##0','seconds'=>'[h]:mm:ss','cents'=>'[$-416]"R$" #,##0.00'][$trend['unit']],
            'axis_value_title'=>['percent'=>'Percentual (%)','count'=>'Quantidade','seconds'=>'Tempo (h:mm:ss)','cents'=>'Valor (R$)'][$trend['unit']],
            'note'=>'Valores mensais arquivados; intervalos e critérios incompatíveis interrompem as linhas.'];
        $dates=$trend['chart']['categories'];
        $first=(int)substr($dates[0],0,4)*12+(int)substr($dates[0],5,2); $last=(int)substr(end($dates),0,4)*12+(int)substr(end($dates),5,2);
        if ($last-$first>120) { $d['category_format']='yyyy'; $d['title'].=' · '.substr($dates[0],0,4).' a '.substr(end($dates),0,4); }
        if ($trend['unit'] === 'seconds') $d['value_format'] = '[h]:mm:ss';
        if ($trend['unit'] === 'cents') { $d['value_format'] = '[$-416]#,##0.00'; $d['signed'] = true; }
        // Retain one scale for this complete trend even when its dates need separate readable panels.
        [$d['axis_min'], $d['axis_max']] = XlsxChart::valueBounds($d);
        $panelSize = $trend['unit'] === 'percent' ? 8 : 12;
        if ($trend['unit'] === 'cents') {
            $longest = 1;
            foreach ($series as $s) foreach ($s['values'] as $value) if ($value !== null) $longest = max($longest, strlen($this->money((int)round($value * 100))));
            $panelSize = max(2, min(6, intdiv(72, $longest)));
            $d['category_padding'] = true;
            $d['category_label_position'] = 'low';
            $range = $d['axis_max'] - $d['axis_min'];
            $d['axis_min'] -= $range * 0.15;
            $d['axis_max'] += $range * 0.10;
            if ($longest > 18) {
                $panelSize = 2;
                // Compact axis ticks leave room for exact currency labels; the values themselves are never scaled.
                $d['value_format'] = '0.0E+00';
                $d['axis_max'] *= 1.15;
            }
        }
        $body = '';
        foreach (array_chunk($d['categories'], $panelSize) as $part => $categories) {
            $panel = $d; $panel['categories'] = $categories; $panel['series'] = []; $seen = [];
            if (count($categories) === 1) $panel['category_padding'] = true;
            $panelTargets = array_fill(0, count($categories), null);
            foreach ($series as $s) if ($s['role'] === 'target') foreach (array_slice($s['values'], $part * $panelSize, $panelSize) as $index => $value) if ($value !== null) $panelTargets[$index] = $value;
            $distinctTargets = array_values(array_unique(array_filter($panelTargets, static fn($value) => $value !== null), SORT_REGULAR));
            $variableTargets = count($distinctTargets) > 1;
            foreach ($series as $s) {
                $s['values'] = array_slice($s['values'], $part * $panelSize, $panelSize);
                if (!array_filter($s['values'], static fn($value) => $value !== null)) continue;
                if ($s['role'] === 'target') {
                    $targets = array_values(array_unique(array_filter($s['values'], static fn($value) => $value !== null), SORT_REGULAR));
                    $s['name'] = $variableTargets ? 'Meta arquivada · valores abaixo' : 'Meta arquivada · ' . implode(' / ', array_map(static fn($value) => number_format($value * 100, 2, ',', '.') . '%', $targets));
                    $s['legend_key'] = 'target';
                    $s['show_data_labels'] = false;
                }
                $key = $s['legend_key'] ?? $s['role'];
                $s['show_legend'] = !isset($seen[$key]); $seen[$key] = true;
                $s['label_position'] = $s['label_position'] ?? ($s['role'] === 'target' ? 'b' : 't');
                $panel['series'][] = $s;
            }
            if (!$panel['series']) continue;
            if (count($d['categories']) > $panelSize) $panel['title'] .= ' · ' . substr($categories[0], 0, 7) . ' a ' . substr(end($categories), 0, 7);
            $body .= str_replace('<w:keepNext/>', '', $this->charts->paragraph($panel, $height));
            if ($variableTargets) $body .= $this->table(array_merge(['Mês'], array_map(static fn($date) => substr($date, 5, 2) . '/' . substr($date, 2, 2), $categories)),
                [array_merge(['Meta'], array_map(fn($value) => $this->percent($value === null ? null : $value * 100), $panelTargets))],
                array_merge([750], array_fill(0, count($categories), (int)floor(8750 / count($categories)))), [], 17, [], true);
        }
        if ($unsafe) $body .= $this->p('Valores acima da precisão do gráfico foram omitidos apenas da figura; os centavos exatos permanecem na tabela.', '', false, 17, 'left');
        return $body;
    }

    private function trendCaption(array $trend): string
    {
        $latest = null; $comparison = null;
        foreach ($trend['rows'] as $row) {
            if ($row['value'] !== null) $latest = $row;
            if ($row['comparison']['eligible']) $comparison = $row;
        }
        if ($latest === null) return 'Nenhum resultado mensal arquivado disponível.';
        $text = 'Último valor registrado: ' . $latest['month'] . ', ' . $this->percent($latest['value']) . '; meta ' . (($latest['direction'] ?? '') === 'lte' ? '≤ ' : '≥ ') . $this->percent($latest['target']) . '. ' . $this->status($latest['status']);
        if ($latest['period_completeness'] !== 'complete') $text .= $latest['period_completeness'] === 'provisional' ? ' · período provisório' : ' · encerramento não confirmado';
        $text .= '. ';
        if ($comparison !== null) {
            $c = $comparison['comparison']; $delta = $c['delta_pp'];
            $text .= 'Última comparação válida: ' . $c['previous_month'] . ' → ' . $comparison['month'] . ', ' . ($delta > 0 ? '+' : '') . $this->number($delta) . ' p.p. (' . ['improved'=>'melhora','declined'=>'piora','unchanged'=>'estável'][$c['direction']] . ').';
        } else $text .= 'Sem par consecutivo com comparabilidade confirmada.';
        return $text;
    }

    private function financial(array $financial): string
    {
        $rows = [];
        foreach ($financial['months'] ?? [] as $row) $rows[] = [$row['month'], ['complete'=>'Completo','provisional'=>'Provisório','incomplete'=>'Incompleto','unavailable'=>'Indisponível','missing'=>'Ausente'][$row['status'] ?? ''] ?? 'Indisponível', $this->money($row['gross_cents'] ?? null), $this->money($row['deduction_cents'] ?? null), $this->money($row['adjustment_cents'] ?? null), $this->money($row['net_cents'] ?? null)];
        $body = $this->table(['Mês','Situação','Base faturada','Glosas','Ajustes','Valor líquido'],$rows,[1050,1350,1775,1775,1775,1775],[],17,[],count($rows)>12);
        $s = $financial['subtotal_cents'] ?? [];
        $body .= $this->keep($this->p('Subtotal dos meses completos', '', true, 20, 'left'));
        $body .= $this->table(['Base faturada','Glosas','Ajustes','Valor líquido'],[[$this->money($s['gross']??null),$this->money($s['deduction']??null),$this->money($s['adjustment']??null),$this->money($s['net']??null)]],[2375,2375,2375,2375],[],17);
        $body .= $this->p((string)($financial['coverage_label']??''),'',false,18,'left');
        $notes=[]; foreach ($financial['months']??[] as $row) foreach ($row['notes']??[] as $note) $notes[$note][]=$row['month'];
        foreach ($notes as $note=>$months) $body .= $this->p($this->monthRanges($months).' · '.$note,'',false,17,'left');
        return $body;
    }

    private function heatmap(array $activity, string $source, string $title, array $monthlyRows): string
    {
        $max = 0;
        foreach ($activity as $row) foreach ($row['activity']['days'] ?? [] as $day) if (is_int($day[$source] ?? null)) $max = max($max, $day[$source]);
        $body = '';
        foreach ([[1,10],[11,20],[21,31]] as $block => [$first,$last]) {
            if (($block > 0 && count($activity) > 12) || ($block === 2 && count($activity) > 6)) $body .= $this->page();
            $body .= $this->heading($title . ' · dias ' . $first . ' a ' . $last, 2);
            if ($block === 0 || count($activity) > 12 || ($block === 2 && count($activity) > 6)) $body .= $this->keep($this->p('Escala diária comum: 0 a ' . $this->number($max) . ' atendimentos. Azul mais intenso = maior volume. 0 = sem registro atribuído; ? = desconhecido; × = fora do calendário; * = parcial/provisório.', '', false, 17, 'left'));
            $rows = []; $fills = [];
            foreach ($activity as $row) {
                $days = []; foreach ($row['activity']['days'] ?? [] as $d) $days[$d['date']] = $d[$source] ?? null;
                $cells = [$row['month'] . (in_array($row['activity']['series'][$source]['status'] ?? '', ['partial','provisional'], true) ? '*' : '')];
                $colors = [ReportStyle::WHITE];
                for ($day = $first; $day <= $last; $day++) {
                    $date = $row['month'] . '-' . sprintf('%02d', $day);
                    $valid = checkdate((int)substr($row['month'],5,2), $day, (int)substr($row['month'],0,4));
                    $v = $days[$date] ?? null;
                    if (!$valid) { $cells[] = '×'; $colors[] = 'D6DAE0'; }
                    elseif ($v === null) { $cells[] = '?'; $colors[] = 'F1F1F1'; }
                    else { $cells[] = $this->number($v); $colors[] = ReportStyle::heatmapColor($v / max(1, $max)); }
                }
                $rows[] = $cells; $fills[] = $colors;
            }
            $count = $last - $first + 1;
            $body .= $this->table(array_merge(['Mês'], range($first, $last)), $rows, array_merge([1100], array_fill(0, $count, (int)floor(8400 / $count))), [], 18, $fills, true);
        }
        $totals = array_column($monthlyRows, 'value', 'month'); $rows = [];
        foreach ($activity as $row) {
            $series = $row['activity']['series'][$source] ?? [];
            $total = $totals[$row['month']] ?? null;
            $assigned = $series['assigned'] ?? null; $unassigned = $series['unassigned'] ?? null;
            $reconciled = ($series['reconciled'] ?? false) === true && is_int($total) && is_int($assigned) && is_int($unassigned)
                && $assigned + $unassigned === $total;
            $coverage = ['complete'=>'Completo','partial'=>'Parcial','provisional'=>'Provisório','unavailable'=>'Indisponível'][$series['status'] ?? ''] ?? 'Não preparado';
            $maxDay = null; $dates = [];
            foreach ($row['activity']['days'] ?? [] as $day) if (is_int($day[$source] ?? null)) {
                if ($maxDay === null || $day[$source] > $maxDay) { $maxDay = $day[$source]; $dates = [$day['date']]; }
                elseif ($day[$source] === $maxDay) $dates[] = $day['date'];
            }
            $shortDates = array_map(static fn($date) => substr($date, 8, 2), array_slice($dates, 0, 4));
            $peak = $maxDay === null ? 'Indisponível' : $this->number($maxDay) . ' (dia' . (count($dates) > 1 ? 's ' : ' ') . implode(', ', $shortDates) . (count($dates) > 4 ? ' e +' . (count($dates)-4) : '') . ')';
            $rows[] = [$row['month'], $this->number($assigned), $this->number($unassigned), $this->number($total), $peak, ($reconciled ? 'Conciliado' : 'Não confirmado') . ' · ' . $coverage];
        }
        if (count($activity) > 16) $body .= $this->page();
        $body .= $this->heading('Conferência dos totais ' . ($source === 'tickets' ? 'de chamados' : 'de ligações'), 2);
        $body .= $this->keep($this->p('Total nos dias + sem data atribuída = total mensal, somente quando a conciliação está confirmada. A cobertura parcial permanece identificada.', '', false, 17, 'left'));
        $body .= $this->table(['Mês','Total nos dias','Sem data atribuída','Total mensal','Pico diário','Conciliação e cobertura'], $rows, [1050,1250,1550,1350,1850,2450], [], 17, [], true);
        return $body;
    }

    private function activityNotes(array $activity): string
    {
        $notes = [];
        foreach ($activity as $record) {
            $a = $record['activity'];
            if ($a !== null && ($a['status'] ?? '') !== 'complete') $notes['Cobertura ' . (['partial'=>'parcial','provisional'=>'provisória','unavailable'=>'indisponível'][$a['status'] ?? ''] ?? 'indisponível')][] = $record['month'];
            foreach ($a['notes'] ?? [] as $note) $notes[$note][] = $record['month'];
        }
        $body = '';
        foreach ($notes as $note => $months) $body .= $this->p($this->monthRanges($months) . ' · ' . $note, '', false, 17, 'left');
        return $body;
    }

    private function criteria(array $report, array $a): string
    {
        $groups = [];
        foreach ($a['metrics'] as $id=>$trend) foreach ($trend['rows'] as $row) {
            foreach (['label'=>'Rótulo arquivado','title'=>'Denominação arquivada','method'=>'Método arquivado','note'=>'Observação arquivada'] as $key=>$label) {
                $text = trim((string)($row[$key]??''));
                if ($text !== '' && $text !== $id && !($key === 'title' && $text === $trend['label'])) $groups[$label.': '.$text][$id][]=$row['month'];
            }
            if (!$row['comparison']['eligible'] && $row['comparison']['previous_month'] !== null) $groups['Comparabilidade: '.$row['comparison']['reason']][$id][]=$row['month'];
        }
        $intro = $this->p('Os métodos e textos originais são agrupados quando idênticos. Comparações são mensais: um intervalo não selecionado, uma ausência, um período provisório ou mudança de origem, meta ou critério interrompe a linha e a conclusão de evolução.','',false,18,'left');
        $body = $intro;
        foreach ($groups as $text=>$ids) {
            $byMonths=[]; foreach ($ids as $id=>$months) $byMonths[$this->monthRanges($months)][]=$id;
            foreach ($byMonths as $months=>$names) $body.=$this->p((count($names) === 12 ? 'INS1 a INS12' : implode(', ',$names)).' · '.$months.' · '.$text,'',false,17,'left');
        }
        $intervals = [];
        foreach ($report['metrics'] ?? [] as $id => $metric) foreach ($metric['months'] ?? [] as $row) {
            $comparison = $row['comparison'] ?? [];
            $delta = $comparison['delta_pp'] ?? null;
            if (($comparison['gap_months'] ?? 0) < 1 || !is_numeric($delta) || !is_finite((float)$delta)) continue;
            $interval = $comparison['interval_label'] ?? (($comparison['previous_month'] ?? '') . ' → ' . $row['month']);
            $direction = ['improved'=>'melhora','declined'=>'piora','unchanged'=>'estável'][$comparison['direction'] ?? ''] ?? 'sem classificação';
            $intervals[$interval][] = $id . ': ' . ($delta > 0 ? '+' : '') . $this->number($delta) . ' p.p. (' . $direction . ')';
        }
        if ($intervals) {
            $body = str_replace('Comparações são mensais:', 'As linhas e legendas de evolução são mensais:', $intro) . substr($body, strlen($intro));
            $body .= $this->heading('Comparações entre competências selecionadas', 2);
            $body .= $this->p('As diferenças abaixo preservam comparações válidas entre as competências indicadas. São variações do intervalo completo, não variações mensais; os gráficos mantêm os pontos separados.','',false,18,'left');
            foreach ($intervals as $interval => $values) $body .= $this->p($interval . ' · ' . implode('; ', $values) . '.', '', false, 17, 'left');
        }
        return $body;
    }
    private function monthRanges(array $months): string
    {
        $months=array_values(array_unique($months)); sort($months); $ranges=[]; $start=$last=null;
        foreach ($months as $month) {
            if ($last !== null && (int)substr($month,0,4)*12+(int)substr($month,5) !== (int)substr($last,0,4)*12+(int)substr($last,5)+1) { $ranges[]=$start===$last?$start:$start.' a '.$last; $start=null; }
            if ($start===null) $start=$month; $last=$month;
        }
        if ($start!==null) $ranges[]=$start===$last?$start:$start.' a '.$last;
        return implode(', ',$ranges);
    }
    private function duration($value): string { return $value === null ? '—' : OfficePackage::duration($value).' ('.$this->number($value).' s)'; }

    private function monthlyNotes(array $row): string
    {
        $body = $this->heading((string)$row['month'], 2);
        if (($row['status'] ?? '') !== 'available' || !is_array($row['summary'] ?? null)) return $body . $this->p('Competência sem revisão selecionada. Não foi substituída por zero nem interpolada.');
        $s = $row['summary'];
        $body .= $this->keep($this->p('Revisão: ' . (string)($row['record_id'] ?? '') . ' · Arquivamento: ' . OfficePackage::date($row['archived_at'] ?? '') . ' · Extração: ' . OfficePackage::date($s['generated_at'] ?? ''), '', false, 18));
        $body .= $this->keep($this->p('Escopo: ' . (string)($s['scope_label'] ?? 'Não registrado') . ' · Regras: ' . (string)($s['rules_version'] ?? 'Não registradas'), '', false, 18));
        $body .= $this->keep($this->p('Período: ' . (string)($s['period']['start'] ?? '') . ' até ' . (string)($s['period']['end_exclusive'] ?? '') . ' (limite final exclusivo).', '', false, 18));
        $body .= $this->p('Encerramento mensal: ' . $this->periodStatus($s['period']['completeness'] ?? 'unknown') . '. ' . (string)($s['period']['note'] ?? ''), '', false, 18);
        if (($s['source_identity']['status'] ?? 'unknown') !== 'known') $body .= $this->p('Identificação das fontes incompleta: a comparabilidade é avaliada individualmente por indicador.', '', false, 18);
        $body .= $this->operationalTotals($s['totals'] ?? []);
        $billed = $s['staffing']['billing_professionals_total'] ?? $s['staffing']['billed_total'] ?? null;
        if ($billed !== null) $body .= $this->p('Profissionais faturados: ' . $this->number($billed) . '.', '', false, 18, 'left');
        foreach (['executive_summary' => 'Análise executiva', 'actions' => 'Ações e propostas de melhoria', 'notes' => 'Observações', 'phone_survey_actions' => 'Providências da pesquisa telefônica'] as $key => $label) {
            $value = trim((string)($s['manual'][$key] ?? ''));
            if ($value !== '') { $body .= $this->p($label . ': ' . $value, '', false, 18, 'left'); }
        }
        if (trim((string)($s['staffing']['note'] ?? '')) !== '') $body .= $this->p('Equipe: ' . $s['staffing']['note'], '', false, 18);
        foreach ($s['warnings'] ?? [] as $warning) $body .= $this->p('Observação da extração: ' . $warning, '', false, 18);
        return $body;
    }

    /** Display already-archived monthly counts and durations; never average or recalculate them. */
    private function operationalTotals(array $totals): string
    {
        $groups = [
            'Chamados' => ['tickets' => 'solucionados', 'requests' => 'requisições', 'incidents' => 'incidentes', 'tickets_assigned_n3' => 'atribuídos ao N3'],
            'Telefonia' => ['calls_received' => 'recebidas consideradas', 'calls_answered' => 'atendidas', 'calls_abandoned' => 'abandonadas',
                'calls_abandoned_over_30' => 'abandono após 30 s', 'calls_abandoned_up_to_30' => 'abandono até 30 s',
                'calls_abandoned_unknown_wait' => 'abandono sem espera conhecida', 'calls_excluded' => 'excluídas', 'calls_unknown' => 'situação desconhecida'],
            'SLA' => ['missing_deadline' => 'sem prazo', 'sla_evaluated' => 'avaliados', 'sla_unresolved' => 'não resolvidos na avaliação',
                'sla_scope_count' => 'população selecionada', 'sla_scope_evaluated' => 'avaliados na população', 'sla_scope_unresolved' => 'não resolvidos na população',
                'sla_recovered' => 'recuperados', 'sla_differences' => 'divergências', 'sla_provisional' => 'provisórios'],
        ];
        $body = '';
        foreach ($groups as $name => $fields) {
            $values = [];
            foreach ($fields as $key => $label) if (array_key_exists($key, $totals)) $values[] = $label . ': ' . $this->number($totals[$key]);
            if ($values) $body .= $this->p($name . ' (quantidades) · ' . implode('; ', $values) . '.', '', false, 18, 'left');
        }
        $times = [];
        foreach (['mean_talk_seconds' => 'TMA', 'mean_wait_seconds' => 'TME'] as $key => $label) if (array_key_exists($key, $totals)) {
            $times[] = $label . ': ' . OfficePackage::duration($totals[$key]) . ($totals[$key] === null ? '' : ' (' . $this->number($totals[$key]) . ' s)');
        }
        foreach (['mean_talk_count' => 'conversas com duração válida', 'mean_wait_count' => 'esperas com duração válida'] as $key => $label) if (array_key_exists($key, $totals)) $times[] = $label . ': ' . $this->number($totals[$key]);
        if ($times) $body .= $this->p('Tempos médios mensais arquivados (h:mm:ss; segundos entre parênteses) · ' . implode('; ', $times) . '.', '', false, 18, 'left');
        return $body === '' ? '' : $this->keep($this->p('Totais operacionais arquivados', '', true, 20)) . $body;
    }

    private function status(string $status): string
    {
        return ['met' => 'Atingida', 'unmet' => 'Não atingida', 'pending' => 'Pendente', 'not_applicable' => 'Não aplicável', 'provisional' => 'Provisório', 'missing' => 'Ausente'][$status] ?? 'Indisponível';
    }
    private function periodStatus(string $status): string { return ['complete' => 'encerrado', 'provisional' => 'provisório', 'unknown' => 'encerramento não confirmado'][$status] ?? 'encerramento não confirmado'; }
    private function percent($value): string { return $value === null ? '—' : number_format((float)$value, 2, ',', '.') . '%'; }
    private function number($value): string { return $value === null ? '—' : (is_int($value) ? preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string)$value) : number_format((float)$value, floor((float)$value) == $value ? 0 : 2, ',', '.')); }
    private function money($cents): string
    {
        if ($cents === null) return '—';
        // Keep frozen cent integers exact, including values beyond floating-point precision.
        $digits = str_pad(ltrim((string)$cents, '-'), 3, '0', STR_PAD_LEFT);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', substr($digits, 0, -2));
        return 'R$ ' . ($cents < 0 ? '-' : '') . $whole . ',' . substr($digits, -2);
    }
    private function page(): string { return '<w:p><w:pPr><w:pageBreakBefore/><w:spacing w:after="0" w:before="0"/></w:pPr></w:p>'; }
    private function keep(string $paragraph): string { return str_replace('<w:pPr>', '<w:pPr><w:keepNext/>', $paragraph); }
    private function heading(string $text, int $level = 1): string { return $this->p($text, 'Heading' . $level, true, $level === 1 ? 28 : 23, 'left'); }
    private function text(string $text): string
    {
        $xml = '';
        foreach (preg_split('/\R/', $text) as $line) if (trim($line) !== '') $xml .= $this->p($line);
        return $xml;
    }
    private function p(string $text, string $style = '', bool $bold = false, int $size = 21, string $align = 'both', string $color = ReportStyle::NAVY): string
    {
        return '<w:p><w:pPr>' . ($style !== '' ? '<w:pStyle w:val="' . $style . '"/>' : '') . '<w:jc w:val="' . $align . '"/><w:widowControl/><w:spacing w:after="90" w:line="250" w:lineRule="auto"/>' . (str_starts_with($style, 'Heading') ? '<w:keepNext/>' : '') . '</w:pPr><w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>' . ($bold ? '<w:b/>' : '') . '<w:color w:val="' . $color . '"/><w:sz w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . str_replace(["\r\n","\r","\n"], '</w:t><w:br/><w:t xml:space="preserve">', OfficePackage::xml($text)) . '</w:t></w:r></w:p>';
    }
    private function table(array $headers, array $rows, array $widths, array $violations = [], int $size = 18, array $fills = [], bool $compact = false): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9500" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) $xml .= '<w:' . $edge . ' w:val="single" w:sz="4" w:color="' . ReportStyle::BORDER . '"/>';
        $xml .= '</w:tblBorders><w:tblCellMar><w:top w:w="65" w:type="dxa"/><w:left w:w="45" w:type="dxa"/><w:bottom w:w="65" w:type="dxa"/><w:right w:w="45" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $width) $xml .= '<w:gridCol w:w="' . $width . '"/>';
        $xml .= '</w:tblGrid>';
        foreach (array_merge([$headers], $rows) as $i => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($i === 0 ? '<w:tblHeader/>' : '') . '</w:trPr>';
            foreach ($row as $j => $cell) {
                $color = $i === 0 ? ReportStyle::WHITE : (!empty($violations[$i - 1]) ? '9C1C1C' : ReportStyle::NAVY);
                $paragraph = str_replace('w:after="90" w:line="250"', 'w:after="20" w:line="220"', $this->p((string)$cell, '', $i === 0, $size, $j === 0 ? 'left' : 'center', $color));
                $paragraph = str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', $paragraph);
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$j] . '" w:type="dxa"/><w:shd w:val="clear" w:fill="' . ($i === 0 ? ReportStyle::NAVY : ($fills[$i-1][$j] ?? ($i % 2 === 0 ? ReportStyle::POSITIVE_FILL : ReportStyle::WHITE))) . '"/><w:vAlign w:val="center"/></w:tcPr>' . $paragraph . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        if ($compact) $xml = str_replace(['w:w="65" w:type="dxa"','w:line="220"'],['w:w="30" w:type="dxa"','w:line="200"'],$xml);
        return $xml . '</w:tbl><w:p><w:pPr><w:spacing w:after="80" w:line="20" w:lineRule="exact"/><w:rPr><w:sz w:val="2"/></w:rPr></w:pPr></w:p>';
    }
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="21"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/><w:sz w:val="40"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="180"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200" w:after="140"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/></w:rPr></w:style></w:styles>';
    }
}
