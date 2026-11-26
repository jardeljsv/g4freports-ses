<?php
namespace GlpiPlugin\G4freports\Ses;

/** Native analytical workbook over bounded frozen summaries, without source reads. */
final class AnnualXlsxExporter
{
    private array $report = [];
    private array $analysis = [];
    private array $data = [];
    private array $sources = [];
    private array $dailyRows = [];
    private array $metricRows = [];
    private array $records = [];
    private array $sheets = [];
    private int $helperRow = 0;

    public function filename(array $report): string
    {
        $months = array_column($report['months'] ?? [], 'month');
        $first = preg_replace('/[^0-9-]/', '', (string)($months[0] ?? ''));
        $last = preg_replace('/[^0-9-]/', '', (string)($months ? end($months) : ''));
        return 'Analise_SES_' . $first . ($last !== $first ? '_a_' . $last : '') . '.xlsx';
    }

    public function toBinary(array $report): string
    {
        $this->analysis = AnnualAnalytics::build($report);
        if (count($report['financial']['months'] ?? []) > 24 || count($report['metrics'] ?? []) > 12) throw new \InvalidArgumentException('Resumo anual acima do limite.');
        foreach ($report['metrics'] ?? [] as $metric) if (count($metric['months'] ?? []) > 24) throw new \InvalidArgumentException('Indicador acima do limite mensal.');
        $this->report = $report;
        $this->sources = $this->dailyRows = $this->metricRows = $this->records = [];
        foreach ($report['months'] as $record) $this->records[$record['month']] = $record;
        $this->data = $this->monthlyData();
        $daily = $this->dailyData();
        $panel = $this->panel();
        $trends = $this->trends();
        $operation = $this->operation();
        $financial = $this->financial();
        $notes = $this->notes();
        $this->sheets = [$panel, $trends, $operation, $financial, $this->data, $daily, $notes];
        return $this->package();
    }

    private function sheet(string $name, array $widths, string $title): array
    {
        $s = ['name'=>$name,'widths'=>$widths,'rows'=>[],'heights'=>[],'merges'=>[],'charts'=>[],'conditional'=>[],'filter'=>'','freeze'=>7,'paper'=>9,'hidden_from'=>null,'print_last'=>null,'breaks'=>[],'fit_height'=>0];
        $this->merged($s,1,$title,1,34);
        $months=$this->analysis['months'];
        $this->merged($s,2,'G4F | SES/DF | '.$months[0].' a '.end($months).' | '.count($months).' competências selecionadas',2,24);
        $this->merged($s,3,'Fonte: revisões mensais arquivadas. Valores e critérios preservados; sem média anual de percentuais.',4,26);
        return $s;
    }

    private function panel(): array
    {
        $s=$this->sheet('Painel',array_merge([10,32],array_fill(0,12,10)),'Evolução dos indicadores');$s['paper']=8;$s['fit_height']=count($this->analysis['months'])<=12?1:2;
        $r=5;
        $findings=array_slice($this->analysis['findings'],0,5);
        foreach($findings as $finding)$this->merged($s,$r++,$finding['text'],$finding['kind']==='coverage'?5:4,$this->height($finding['text'],150));
        if(count($findings)<3)$this->merged($s,$r++,'As comparações disponíveis são insuficientes para mais conclusões. Os valores registrados permanecem visíveis abaixo.',4,28);
        $r++;$s['freeze']=$r;
        foreach(array_chunk($this->analysis['months'],12) as $months){
            $this->headers($s,$r++,array_merge(['Indicador','Descrição arquivada'],array_map(static fn($m)=>substr($m,5).'/'.substr($m,0,4),$months)));
            foreach($this->analysis['matrix'] as $metric){
                $this->put($s,$r,1,$metric['id'],5);$this->put($s,$r,2,$this->short($metric['label'],58),0);
                $cells=array_column($metric['cells'],null,'month');
                foreach($months as $i=>$month){$cell=$cells[$month];$value=self::number($cell['value']);$source=$this->sources[$metric['id']][$month]['result']??null;$this->put($s,$r,$i+3,$value===null?(['not_applicable'=>'N/A','pending'=>'Pend.','provisional'=>'Prov.'][$cell['status']]??'—'):$value/100,$this->metricStyle($cell),$value!==null?$source:null);}
                $s['heights'][$r++]=36;
            }$r+=2;
        }
        $this->merged($s,$r++,'Leitura: azul = meta arquivada atingida; vermelho = não atingida; P = provisório; E = em apuração; ? = resultado sem avaliação confirmada; — = valor ausente.',4,30);
        $this->merged($s,$r,'Tendências mostra as linhas e interrupções de comparabilidade. Operação mostra volumes, tempos e calendário diário. Dados mensais preserva a precisão completa.',4,30);
        return $s;
    }

    private function metricStyle(array $row): int
    {
        if(self::number($row['value']??null)===null)return 15;
        if(($row['period_completeness']??'')==='provisional'||($row['status']??'')==='provisional')return 13;
        if(($row['status']??'')==='pending')return 21;
        if(empty($row['target_evaluable'])&&empty($row['eligible']))return 14;
        return ($row['status']??'')==='met'?11:12;
    }

    private function trends(): array
    {
        $s = $this->sheet('Tendências', array_fill(0, 14, 11), 'Indicadores ao longo do tempo');
        $s['freeze'] = 4; $s['paper'] = 8;
        $this->merged($s, 4, 'Resultados em cada ponto; metas na legenda e na linha de referência. Linhas interrompidas preservam lacunas e mudanças de critério. Painéis do mesmo indicador usam a mesma escala.', 4, 34);
        $top = 5; $panels = 0;
        foreach ($this->analysis['metrics'] as $trend) {
            foreach ($this->chartPanels($trend, $trend['id'] . ' · ' . $this->short($trend['label'], 64), 14, $top) as $panelIndex=>$chart) {
                if ($panels > 0 && $panels % 2 === 0) $s['breaks'][] = $top;
                $s['charts'][] = $chart;
                $offset = $panelIndex * 12;
                $rows = array_slice($trend['rows'], $offset, 12);
                $this->put($s,$top+15,1,'Meta',5);
                foreach($rows as $i=>$row){$this->put($s,$top+15,$i+2,XlsxChart::dateSerial($row['month'].'-01'),16);$value=$this->display($row['target']??null,'percent');$this->put($s,$top+16,$i+2,$value===null?'—':$value,$value===null?15:6,$value!==null?($this->sources[$trend['id']][$row['month']]['target']??null):null);}
                $s['heights'][$top+15]=20;$s['heights'][$top+16]=22;
                $top += 17; $panels++;
            }
        }
        return $s;
    }

    private function monthlyData(): array
    {
        $s=$this->sheet('Dados mensais',[13,10,32,14,14,14,14,22,20,15,17,43,25],'Valores mensais e comparações');$s['paper']=8;
        $this->merged($s,5,'Valores congelados. Δ absoluto: p.p. nos indicadores, segundos nos tempos e unidades nos volumes. Fórmulas usam somente comparações autorizadas.',4,32);
        $this->headers($s,7,['Competência','Medida','Descrição arquivada','Resultado','Meta','Numerador / amostra','Denominador','Situação','Encerramento','Δ absoluto','Evolução / Δ relativo','Comparação / motivo','Revisão arquivada']);$r=8;
        foreach($this->analysis['metrics'] as $id=>$trend)foreach($trend['rows'] as $row){
            $month=$row['month'];$status=$row['status']==='missing'?'Ausente':OfficePackage::status($row);
            $this->row($s,$r,[XlsxChart::dateSerial($month.'-01'),$id,$row['label']??$id,$this->display($row['value'],'percent'),$this->display($row['target']??null,'percent'),$row['numerator'],$row['denominator'],$status,$this->periodStatus($row['period_completeness']),null,'Não comparável',$row['comparison']['reason'],$this->records[$month]['record_id']??''],[16,5,0,6,6,8,8,0,0,9,0,4,0],36);
            $comparison=$row['comparison'];foreach($this->report['metrics'][$id]['months']??[] as $original){$c=$original['comparison']??[];if(($original['month']??'')===$month&&empty($comparison['eligible'])&&($c['gap_months']??0)>0&&self::number($c['delta_pp']??null)!==null){$comparison=$c;$comparison['eligible']=true;$this->put($s,$r,12,($c['interval_label']??'Intervalo selecionado').' · '.($c['reason']??'').' · Não é uma variação mensal.',4);break;}}$prior=$this->metricRows[$id][$comparison['previous_month']??'']??null;
            if(!empty($comparison['eligible'])&&$prior!==null&&self::number($comparison['delta_pp'])!==null){$this->put($s,$r,10,$comparison['delta_pp'],9,'(D'.$r.'-D'.$prior.')*100');$direction=['improved'=>'Melhora','declined'=>'Piora','unchanged'=>'Estável'][$comparison['direction']]??'Não comparável';$operator=($row['direction']??'')==='lte'?'<':'>';$this->put($s,$r,11,$direction,0,'IF(J'.$r.'=0,"Estável",IF(J'.$r.$operator.'0,"Melhora","Piora"))');}
            $this->metricRows[$id][$month]=$r;$this->sources[$id][$month]=['result'=>"'Dados mensais'!D".$r,'target'=>"'Dados mensais'!E".$r];$r++;
        }
        $r+=2;$this->merged($s,$r++,'Volumes e tempos mensais arquivados · amostra própria em Numerador / amostra',3,26);$r++;
        foreach(array_merge($this->analysis['volumes'],$this->analysis['durations']) as $id=>$trend){$previousRows=[];foreach($trend['rows'] as $row){$month=$row['month'];$style=$trend['unit']==='seconds'?17:8;
            $this->row($s,$r,[XlsxChart::dateSerial($month.'-01'),$id,$trend['label'],$this->display($row['value'],$trend['unit']),null,$row['sample_count']??null,null,$row['value']===null?'Indisponível':'Registrado',$this->periodStatus($row['period_completeness']),null,null,$row['comparison']['reason'],$this->records[$month]['record_id']??''],[16,5,0,$style,0,8,0,0,0,9,6,4,0],32);
            $prior=$previousRows[$row['comparison']['previous_month']??'']??null;if(!empty($row['comparison']['eligible'])&&$prior!==null){$this->put($s,$r,10,$row['comparison']['delta_absolute'],9,'(D'.$r.'-D'.$prior.')'.($trend['unit']==='seconds'?'*86400':''));if(self::number($row['comparison']['delta_percent'])!==null)$this->put($s,$r,11,$row['comparison']['delta_percent']/100,6,'(D'.$r.'-D'.$prior.')/D'.$prior);}
            $this->sources[$id][$month]=['result'=>"'Dados mensais'!D".$r,'sample'=>"'Dados mensais'!F".$r];$previousRows[$month]=$r++;
        }}$s['filter']='A7:M'.($r-1);$s['print_last']=$r-1;$s['hidden_from']=$r+3;$this->helperRow=$r+3;return $s;
    }

    private function dailyData(): array
    {
        $s=$this->sheet('Dados diários',[14,15,18,18,18,24,25,35,56],'Atividade diária arquivada');$s['paper']=8;
        $this->merged($s,5,'Uma linha por data civil, sem registros individuais. Zero é uma contagem observada; ausência continua vazia. Contagens parciais preservam a cobertura informada.',4,34);
        $this->headers($s,7,['Data','Competência','Chamados','Ligações','Testes excluídos','Cobertura diária','Encerramento','Revisão','Observações']);$r=8;
        foreach($this->analysis['activity'] as $item){$month=$item['month'];$activity=$item['activity']??[];$days=[];foreach($activity['days']??[] as $day)if(is_array($day)&&is_string($day['date']??null))$days[$day['date']]=$day;$length=(int)(new \DateTimeImmutable($month.'-01'))->format('t');
            for($d=1;$d<=$length;$d++){$date=$month.'-'.sprintf('%02d',$d);$day=$days[$date]??[];$this->row($s,$r,[XlsxChart::dateSerial($date),XlsxChart::dateSerial($month.'-01'),self::count($day['tickets']??null),self::count($day['calls']??null),self::count($day['excluded_calls']??null),$activity['status']??'unavailable',$this->periodStatus($activity['period_completeness']??'unknown'),$this->records[$month]['record_id']??'',$d===1?'Ver cobertura e notas em Notas e critérios.':''],[18,16,8,8,8,0,0,0,4],25);$this->dailyRows[$month][$d]=['row'=>$r,'tickets'=>self::count($day['tickets']??null),'calls'=>self::count($day['calls']??null),'excluded_calls'=>self::count($day['excluded_calls']??null)];$r++;}
        }$s['filter']='A7:I'.($r-1);return $s;
    }

    private function operation(): array
    {
        $s = $this->sheet('Operação', array_merge([14], array_fill(0, 11, 11.5), [28]), 'Volumes, tempos e atividade diária');
        $s['paper'] = 8; $s['freeze'] = 4;
        $this->merged($s, 4, 'Chamados por solução; ligações após exclusões. Os valores de cada mês ficam nos pontos. Mudanças de volume ou duração, isoladamente, não demonstram qualidade.', 4, 34);
        $top = 5; $panels = 0;
        foreach (['tickets'=>'volumes', 'calls'=>'volumes', 'tma'=>'durations', 'tme'=>'durations'] as $id=>$group) {
            foreach ($this->chartPanels($this->analysis[$group][$id], $this->analysis[$group][$id]['label'], 13, $top) as $chart) {
                if ($panels > 0 && $panels % 2 === 0) $s['breaks'][] = $top;
                $s['charts'][] = $chart; $top += 17; $panels++;
            }
        }
        $s['breaks'][] = $top; $r = $top + 1;
        $this->merged($s, $r++, 'Valores mensais de referência', 3, 28);
        foreach ([[1,1,'Mês'],[2,2,'Chamados'],[3,3,'Ligações'],[4,4,'Testes excluídos'],[5,6,'TMA'],[7,7,'Amostra TMA'],[8,9,'TME'],[10,10,'Amostra TME'],[11,13,'Encerramento']] as [$a,$b,$label]) $this->mergeCells($s,$r,$a,$b,$label,3);
        $s['heights'][$r++] = 40;
        foreach ($this->analysis['months'] as $index=>$month) {
            $this->put($s,$r,1,XlsxChart::dateSerial($month.'-01'),16);
            foreach ([['tickets',2,2,'volumes'],['calls',3,3,'volumes'],['excluded_calls',4,4,'volumes'],['tma',5,6,'durations'],['tme',8,9,'durations']] as [$id,$a,$b,$group]) {
                $trend=$this->analysis[$group][$id];$row=$trend['rows'][$index];$value=$this->display($row['value'],$trend['unit']);
                $this->mergeCells($s,$r,$a,$b,$value,$group==='durations'?17:8,$value!==null?$this->sources[$id][$month]['result']:null);
                if($group==='durations')$this->put($s,$r,$b+1,$row['sample_count']??null,8,isset($row['sample_count'])?$this->sources[$id][$month]['sample']:null);
            }
            $state=$this->records[$month]['summary']['period']['completeness']??'unknown';
            $this->mergeCells($s,$r,11,13,($this->records[$month]['status']??'')==='missing'?'Arquivo ausente':$this->periodStatus($state),0);$s['heights'][$r++]=26;
        }
        $r+=2;
        foreach(['tickets'=>'Chamados por dia da solução','calls'=>'Ligações por dia da entrada na fila'] as $source=>$title) {
            $ranges=[];
            foreach ([[1,10],[11,20],[21,31]] as [$from,$to]) {
                $s['breaks'][]=$r-1;
                $this->merged($s,$r++,$title.' · dias '.$from.'–'.$to,3,28);
                $this->merged($s,$r++,'Contagens exatas. 0 = nenhum registro atribuído; — = desconhecido; × = data inexistente. A mesma escala azul vale para todos os blocos e meses desta fonte.',4,34);
                $this->put($s,$r,1,'Competência',3);for($day=$from;$day<=$to;$day++)$this->put($s,$r,$day-$from+2,$day,3);$this->put($s,$r,13,'Cobertura',3);$s['heights'][$r++]=28;$first=$r;
                foreach($this->analysis['activity'] as $item) {
                    $month=$item['month'];$activity=$item['activity']??[];$this->put($s,$r,1,$month,5);
                    for($day=$from;$day<=$to;$day++) {
                        $data=$this->dailyRows[$month][$day]??null;$value=$data[$source]??null;
                        $this->put($s,$r,$day-$from+2,$data===null?'×':($value===null?'—':$value),$data===null?19:($value===null?15:20),$value!==null?"'Dados diários'!".($source==='tickets'?'C':'D').$data['row']:null);
                    }
                    $series=$activity['series'][$source]??[];$label=['complete'=>'Completo','partial'=>'Parcial','provisional'=>'Provisório','unavailable'=>'Indisponível'][$series['status']??'unavailable']??'Não confirmado';
                    if(($series['unassigned']??0)>0)$label.=' · '.$series['unassigned'].' sem data';$this->put($s,$r,13,$label,4);$s['heights'][$r++]=25;
                }
                $ranges[]='B'.$first.':'.self::col($to-$from+2).($r-1);$r+=2;
            }
            // A single disjoint formatting range gives all day blocks one source-wide scale.
            $s['conditional'][]=implode(' ',$ranges);
            $s['breaks'][]=$r-1;
            $this->merged($s,$r++,$title.' · conciliação mensal',3,28);
            foreach([[1,1,'Mês'],[2,3,'Soma dos dias'],[4,5,'Sem data atribuída'],[6,7,'Soma + sem data'],[8,9,'Total mensal arquivado'],[10,13,'Conferência / cobertura']] as [$a,$b,$label])$this->mergeCells($s,$r,$a,$b,$label,3);
            $s['heights'][$r++]=40;
            foreach($this->analysis['activity'] as $item) {
                $month=$item['month'];$series=$item['activity']['series'][$source]??[];$available=($series['available']??false)===true;
                $total=self::count($this->records[$month]['summary']['totals'][$source==='tickets'?'tickets':'calls_received']??null);
                $assigned=$available?self::count($series['assigned']??null):null;$unassigned=$available?self::count($series['unassigned']??null):null;
                $days=$this->dailyRows[$month];$firstDay=reset($days);$lastDay=end($days);$col=$source==='tickets'?'C':'D';
                $this->put($s,$r,1,$month,5);
                $this->mergeCells($s,$r,2,3,$assigned,8,$assigned!==null?"SUM('Dados diários'!".$col.$firstDay['row'].':'.$col.$lastDay['row'].')':null);
                $this->mergeCells($s,$r,4,5,$unassigned,8);
                $combined=$assigned!==null&&$unassigned!==null?$assigned+$unassigned:null;
                $this->mergeCells($s,$r,6,7,$combined,8,$combined!==null?'B'.$r.'+D'.$r:null);
                $this->mergeCells($s,$r,8,9,$total,8);
                $status=$series['status']??'unavailable';$coverage=['complete'=>'Completo','partial'=>'Parcial','provisional'=>'Provisório','unavailable'=>'Indisponível'][$status]??'Não confirmado';
                $check=$combined===null||$total===null?'Não verificável':($combined===$total?'Confere':'Divergente');
                $formula=$combined!==null&&$total!==null?'IF(F'.$r.'=H'.$r.',"Confere","Divergente")&" · '.$coverage.'"':null;
                $this->mergeCells($s,$r,10,13,$check.' · '.$coverage,4,$formula);$s['heights'][$r++]=27;
            }
            $this->merged($s,$r++,'A soma diária usa as células de Dados diários. Registros sem data válida permanecem separados. Ausência e cobertura parcial não são convertidas em zero.',4,34);$r+=2;
        }
        return $s;
    }

    private function financial(): array
    {
        $s=$this->sheet('Financeiro',[14,23,22,22,22,22,48],'Evolução financeira');$s['paper']=8;
        $financial=$this->report['financial']??[];$this->merged($s,5,(string)($financial['coverage_label']??''),5,28);
        $this->merged($s,6,'Somente competências completas integram o subtotal. Valores parciais permanecem visíveis. Variações financeiras não comprovam economia ou melhora de qualidade.',4,32);
        $r=8;$this->headers($s,$r++,['Competência','Situação','Bruto (R$)','Glosa (R$)','Ajuste (R$)','Líquido (R$)','Observações']);$first=$r;$unsafe=[false,false,false,false];$absolute=[0,0,0,0];$precision=false;$byMonth=array_column($financial['months']??[],null,'month');
        foreach($this->analysis['months'] as $month){$data=$byMonth[$month]??['status'=>'missing'];$status=['complete'=>'Completo','incomplete'=>'Parcial','provisional'=>'Provisório','unavailable'=>'Indisponível','missing'=>'Ausente'][$data['status']]??'Indisponível';$values=[];
            foreach(['gross','deduction','adjustment','net'] as $i=>$id){$cents=$data[$id.'_cents']??null;$text=is_int($cents)&&!self::safeCents($cents);if($status==='Completo'&&is_int($cents)&&!$text){if($absolute[$i]>999999999999999-abs($cents))$unsafe[$i]=true;else $absolute[$i]+=abs($cents);}$values[]=$text?self::money($cents):$this->display($cents,'cents');if($text){$s['widths'][$i+2]=34;$precision=true;if($status==='Completo')$unsafe[$i]=true;}$this->sources[$id][$month]=['result'=>"'Financeiro'!".self::col($i+3).$r];}
            $this->row($s,$r++,array_merge([XlsxChart::dateSerial($month.'-01'),$status],$values,[$this->short($this->text($data['notes']??[]),105).(!empty($data['notes'])?' · texto completo em Notas e critérios.':'')]),[16,0,7,7,7,7,4],empty($data['notes'])?22:40);
        }
        $last=$r-1;$s['filter']='A8:G'.$last;$this->mergeCells($s,$r,1,2,'Subtotal completo',5);
        foreach(['gross','deduction','adjustment','net'] as $i=>$id){$cents=$financial['subtotal_cents'][$id]??null;$col=self::col($i+3);if(is_int($cents)&&(!self::safeCents($cents)||$unsafe[$i])){$this->put($s,$r,$i+3,self::money($cents),10);$s['widths'][$i+2]=34;$precision=true;}else $this->put($s,$r,$i+3,$cents===null?'':$cents/100,10,'IF(COUNTIF($B$'.$first.':$B$'.$last.',"Completo")=0,"",SUMIF($B$'.$first.':$B$'.$last.',"Completo",'.$col.$first.':'.$col.$last.'))');}$s['heights'][$r++]=38;
        if($precision)$this->merged($s,$r++,'Precisão: grandes montantes são preservados como texto monetário exato. Subtotais afetados também usam o valor arquivado em texto, sem fórmula; esses pontos não são desenhados nos gráficos.',4,46);
        $cost=$this->analysis['financial']['trends']['net'];$cost['chart']['series']=[];
        foreach(['gross'=>'Bruto','net'=>'Líquido'] as $id=>$label)foreach($this->analysis['financial']['trends'][$id]['chart']['series'] as $item){$item['name']=$label.' · '.$item['name'];$item['source_id']=$id;$item['color_override']=$id==='gross'?ReportStyle::NAVY:ReportStyle::BLUE;$cost['chart']['series'][]=$item;}
        $top=$r-1;$panels=0;$s['breaks'][]=$top;
        foreach([[$cost,'Bruto e líquido mensais'],[$this->analysis['financial']['trends']['deduction'],'Glosas mensais']] as [$trend,$title])foreach($this->chartPanels($trend,$title,7,$top) as $chart){if($panels>0&&$panels%2===0)$s['breaks'][]=$top;$s['charts'][]=$chart;$top+=17;$panels++;}
        return $s;
    }

    /** Slice existing series only: comparability and gaps are never recomputed for presentation. */
    private function chartPanels(array $trend, string $title, int $width, int $top): array
    {
        $all=[];
        foreach($trend['chart']['series'] as $series){$series['values']=array_map(function($value)use($trend){return $trend['unit']==='cents'&&is_int($value)&&!self::safeCents($value)?null:$this->display($value,$trend['unit']);},$series['values']);$all[]=$series;}
        [$min,$max]=XlsxChart::valueBounds(['series'=>$all,'signed'=>$trend['unit']==='cents']);
        $limit=$trend['unit']==='cents'?6:12;$wideMoney=false;
        if($trend['unit']==='cents')foreach($all as $series)foreach($series['values'] as $value)if($value!==null&&strlen(number_format($value,2,'.',','))+3>18)$wideMoney=true;
        if($wideMoney){$limit=3;$max=$max>0?$max*1.12:$max;$min=$min<0?$min*1.12:$min;}
        $panels=[];
        foreach(array_chunk($trend['rows'],$limit) as $index=>$rows){$offset=$index*$limit;$part=$trend;$part['rows']=$rows;$part['chart']['categories']=array_slice($trend['chart']['categories'],$offset,count($rows));$part['chart']['series']=[];
            foreach($trend['chart']['series'] as $series){$series['values']=array_slice($series['values'],$offset,count($rows));$part['chart']['series'][]=$series;}
            $period=$rows[0]['month'].' a '.$rows[count($rows)-1]['month'];$chart=$this->chart($part,$title.' · '.$period,[0,$top+$index*17,$width,$top+$index*17+14],true);$chart['axis_min']=$min;$chart['axis_max']=$max;if($wideMoney)$chart['value_format']='0.0E+00';$panels[]=$chart;
        }
        return $panels;
    }

    private function chart(array $trend,string $title,array $anchor,bool $legend): array
    {
        $start=$this->helperRow;$this->merged($this->data,$start,$title.' · células dos gráficos',3,25);$header=$start+1;$first=$start+2;$last=$first+count($trend['rows'])-1;
        $this->put($this->data,$header,1,'Data',3);foreach($trend['chart']['categories'] as $i=>$date)$this->put($this->data,$first+$i,1,XlsxChart::dateSerial($date),16);$series=[];$seen=[];$targetValues=[];
        foreach($trend['chart']['series'] as $item)if(($item['role']??'')==='target')foreach($item['values'] as $value)if($value!==null)$targetValues[]=OfficePackage::percent($value);
        $targetValues=array_values(array_unique($targetValues));
        foreach($trend['chart']['series'] as $item){$values=[];foreach($item['values'] as $value)$values[]=$trend['unit']==='cents'&&is_int($value)&&!self::safeCents($value)?null:$this->display($value,$trend['unit']);
            if(!array_filter($values,static fn($v)=>$v!==null))continue;
            $column=count($series)+2;$this->put($this->data,$header,$column,$item['name'],3);
            foreach($values as $i=>$converted){$month=$trend['rows'][$i]['month'];$role=$item['role']==='target'?'target':'result';$ref=$this->sources[$item['source_id']??$trend['id']][$month][$role]??null;$this->put($this->data,$first+$i,$column,$converted,$trend['unit']==='percent'?6:($trend['unit']==='seconds'?17:($trend['unit']==='cents'?7:8)),$converted!==null?$ref:null);}
            $item['color']=$item['color_override']??['result'=>ReportStyle::BLUE,'target'=>ReportStyle::YELLOW_DARK,'provisional'=>'B08300','unverified'=>ReportStyle::GRAY][$item['role']]??ReportStyle::BLUE;
            $item['values']=$values;$item['value_ref']="'Dados mensais'!$".self::col($column).'$'.$first.':$'.self::col($column).'$'.$last;
            $item['show_data_labels']=true;$item['label_position']=$item['role']==='target'||($item['source_id']??'')==='net'?'b':'t';
            if($item['role']==='target'){$item['line_width']=12700;$item['show_data_labels']=false;$item['name']='Meta arquivada · '.(count($targetValues)===1?$targetValues[0]:'valores abaixo');}
            $key=($item['source_id']??$trend['id']).'|'.$item['role'];$item['show_legend']=!isset($seen[$key]);$seen[$key]=true;$series[]=$item;
        }
        if(!$series)$series[]=['name'=>'Sem valores arquivados','color'=>ReportStyle::GRAY,'values'=>array_fill(0,count($trend['rows']),null),'value_ref'=>"'Dados mensais'!\$B\$".$first.':$B$'.$last,'line_visible'=>false,'marker'=>'none','show_data_labels'=>false];
        $this->helperRow=$last+3;
        $dates=$trend['chart']['categories'];$span=((int)substr(end($dates),0,4)-(int)substr($dates[0],0,4))*12+(int)substr(end($dates),5,2)-(int)substr($dates[0],5,2);
        return ['title'=>$title,'kind'=>'line','unit'=>$trend['unit']==='percent'?'percent':'count','date_axis'=>true,'category_padding'=>$trend['unit']==='cents'||count($dates)===1,'categories'=>$dates,'category_ref'=>"'Dados mensais'!\$A\$".$first.':$A$'.$last,'series'=>$series,'anchor'=>$anchor,'show_data_labels'=>true,'data_label_policy'=>'all','label_format'=>$trend['unit']==='percent'?'0.00%':($trend['unit']==='seconds'?'[h]:mm:ss':($trend['unit']==='cents'?'"R$" #,##0.00':'#,##0')),'plot_visible_only'=>false,'show_legend'=>$legend,'force_legend'=>true,'category_format'=>$span>120?'yyyy':'mm/yy','axis_category_title'=>'','axis_value_title'=>$trend['unit']==='percent'?'%':($trend['unit']==='seconds'?'hh:mm:ss':($trend['unit']==='cents'?'R$':'Quantidade')),'value_format'=>$trend['unit']==='percent'?'0%':($trend['unit']==='seconds'?'[h]:mm:ss':($trend['unit']==='cents'?'"R$" #,##0.00':'#,##0')),'signed'=>$trend['unit']==='cents'];
    }

    private function notes(): array
    {
        $s=$this->sheet('Notas e critérios',[31,27,110],'Notas, critérios e origem dos valores');$this->merged($s,5,'Textos idênticos são agrupados por competências. O conteúdo original é preservado; mudanças de método não são resumidas como causas de desempenho.',4,32);$this->headers($s,7,['Competências','Informação','Conteúdo arquivado']);$groups=[];
        foreach($this->records as $month=>$record){$summary=$record['summary']??[];$this->noteGroup($groups,$month,'Origem','Revisão: '.($record['record_id']??'ausente')."\nApuração: ".($summary['generated_at']??'')."\nRegras: ".($summary['rules_version']??'')."\nEscopo: ".($summary['scope_label']??'')."\nEncerramento: ".$this->periodStatus($summary['period']['completeness']??'unknown'));foreach(['executive_summary'=>'Resumo executivo','actions'=>'Ações','notes'=>'Observações','phone_survey_actions'=>'Ações de telefonia'] as $key=>$label)$this->noteGroup($groups,$month,$label,$this->text($summary['manual'][$key]??''));
            foreach($summary['metrics']??[] as $id=>$metric)foreach(['label'=>'Descrição','title'=>'Título','method'=>'Método','note'=>'Nota','exclusion_note'=>'Exclusões'] as $key=>$label){if($key==='title'&&($metric['title']??'')===($metric['label']??''))continue;$this->noteGroup($groups,$month,$id.' · '.$label,$this->text($metric[$key]??''));}
            foreach(['warnings'=>'Ressalvas','rule_notes'=>'Critérios','staffing'=>'Equipe'] as $key=>$label)$this->noteGroup($groups,$month,$label,($key==='staffing'?$this->compact($summary[$key]??[]):$this->text($summary[$key]??[])));$this->noteGroup($groups,$month,'Totais arquivados',$this->compact($summary['totals']??[]));$this->noteGroup($groups,$month,'Financeiro',$this->text($summary['financial']['notes']??[]));foreach($this->report['financial']['months']??[] as $financial)if(($financial['month']??'')===$month)$this->noteGroup($groups,$month,'Ressalvas financeiras',$this->text($financial['notes']??[]));$this->noteGroup($groups,$month,'Atividade diária',$this->text($summary['activity']['notes']??[]));$this->noteGroup($groups,$month,'Cobertura diária',$this->compact($summary['activity']['series']??[]));
        }$r=8;foreach($groups as $group)foreach($this->chunks($group['text'],100) as $part=>$text)$this->row($s,$r++,[$this->monthLabels($group['months']),$group['label'].($part?' (continuação)':''),$text],[0,5,4],max($this->height($text,100),$this->height($this->monthLabels($group['months']),27)));$s['filter']='A7:C'.max(7,$r-1);return $s;
    }
    private function compact($value): string
    {
        if (!is_array($value)) return $this->text($value);
        $parts=[]; foreach($value as $key=>$item) { $text=$this->compact($item); if($text!=='')$parts[]=(is_string($key)?$key.': ':'').$text; }
        return implode('; ',$parts);
    }
    private function monthLabels(array $months): string
    {
        $groups=[];$start=$last=null;foreach($months as $month){if($last!==null&&(new \DateTimeImmutable($last.'-01'))->modify('+1 month')->format('Y-m')!==$month){$groups[]=$start===$last?$start:$start.' a '.$last;$start=null;}if($start===null)$start=$month;$last=$month;}if($start!==null)$groups[]=$start===$last?$start:$start.' a '.$last;return implode(', ',$groups);
    }
    private function noteGroup(array &$groups,string $month,string $label,string $text): void { if($text==='')return;$key=hash('sha256',$label."\0".$text);if(!isset($groups[$key]))$groups[$key]=['label'=>$label,'text'=>$text,'months'=>[]];$groups[$key]['months'][]=$month; }
    private function display($value,string $unit) { $n=self::number($value);return $n===null?null:($unit==='percent'||$unit==='cents'?$n/100:($unit==='seconds'?$n/86400:$n)); }
    private static function number($v) { return (is_int($v)||is_float($v))&&is_finite((float)$v)?$v:null; }
    private static function count($v): ?int { return is_int($v)&&$v>=0?$v:null; }
    private static function safeCents(int $v): bool { return $v>=-999999999999999&&$v<=999999999999999; }
    private static function money(int $v): string { $t=(string)$v;$neg=$t[0]==='-';$d=str_pad($neg?substr($t,1):$t,3,'0',STR_PAD_LEFT);return ($neg?'-':'').'R$ '.preg_replace('/\B(?=(\d{3})+(?!\d))/','.',substr($d,0,-2)).','.substr($d,-2); }
    private function periodStatus(string $v): string { return ['complete'=>'Encerrado','provisional'=>'Provisório','unknown'=>'Não confirmado'][$v]??'Não confirmado'; }
    private function short(string $t,int $limit): string { return mb_strlen($t,'UTF-8')>$limit?mb_substr($t,0,$limit-1,'UTF-8').'…':$t; }
    private function text($v): string { if(!is_array($v))return is_bool($v)?($v?'Sim':'Não'):(is_scalar($v)?(string)$v:'');$p=[];foreach($v as $k=>$item){$t=$this->text($item);if($t!=='')$p[]=(is_string($k)?$k.': ':'').$t;}return implode("\n",$p); }
    private function height(string $text,int $width): int { $lines=0;foreach(explode("\n",$text) as $line)$lines+=max(1,(int)ceil(mb_strlen($line,'UTF-8')/$width));return min(390,max(26,14*$lines+8)); }
    private function chunks(string $text,int $width): array { if($text==='')return [''];$out=[];$current='';$lines=0;foreach(preg_split('/(?<=\n)/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[$text] as $line)while($line!==''){$part=mb_substr($line,0,$width,'UTF-8');$line=mb_substr($line,mb_strlen($part,'UTF-8'),null,'UTF-8');if($lines===20){$out[]=$current;$current='';$lines=0;}$current.=$part;$lines++;}if($current!=='')$out[]=$current;return $out; }
    private function mergeCells(array &$s,int $r,int $a,int $b,$v,int $style=0,?string $formula=null): void { $this->put($s,$r,$a,$v,$style,$formula);if($b>$a)$s['merges'][]=self::col($a).$r.':'.self::col($b).$r; }
    // OOXML serialization helpers follow.
    private function put(array &$s, int $row, int $col, $value, int $style = 0, ?string $formula = null): void
    {
        $s['rows'][$row][$col] = ['value' => $value, 'style' => $style, 'formula' => $formula];
    }

    private function row(array &$s, int $row, array $values, array $styles, int $height): void
    {
        foreach ($values as $i => $value) $this->put($s, $row, $i + 1, $value, $styles[$i] ?? 0);
        $s['heights'][$row] = $height;
    }

    private function merged(array &$s, int $row, string $text, int $style, int $height): void
    {
        $this->put($s, $row, 1, $text, $style);
        $s['merges'][] = 'A' . $row . ':' . self::col(count($s['widths'])) . $row;
        $s['heights'][$row] = $height;
    }

    private function headers(array &$s, int $row, array $values): void
    {
        $this->row($s, $row, $values, array_fill(0, count($values), 3), 40);
    }

    private static function col(int $col): string
    {
        $value = '';
        while ($col > 0) { $col--; $value = chr(65 + $col % 26) . $value; $col = intdiv($col, 26); }
        return $value;
    }

    private function worksheet(array $s): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $s['freeze'] . '" topLeftCell="A' . ($s['freeze'] + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols>';
        foreach ($s['widths'] as $index => $width) $xml .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $width . '" customWidth="1"/>';
        $xml .= '</cols><sheetData>';
        ksort($s['rows']);
        foreach ($s['rows'] as $row => $cells) {
            $xml .= '<row r="' . $row . '" ht="' . ($s['heights'][$row] ?? 26) . '" customHeight="1"' . ($s['hidden_from'] !== null && $row >= $s['hidden_from'] ? ' hidden="1"' : '') . '>';
            ksort($cells);
            foreach ($cells as $column => $cell) {
                $value = $cell['value'];
                $xml .= '<c r="' . self::col($column) . $row . '" s="' . $cell['style'] . '"';
                if ($cell['formula'] !== null) {
                    $numeric = self::number($value);
                    $xml .= ($numeric === null ? ' t="str"' : '') . '><f>' . OfficePackage::xml($cell['formula']) . '</f><v>' . ($numeric === null ? OfficePackage::xml($value) : sprintf('%.15g', $numeric)) . '</v></c>';
                } elseif ($value === null) $xml .= '/>';
                elseif (self::number($value) !== null) $xml .= '><v>' . sprintf('%.15g', $value) . '</v></c>';
                else $xml .= ' t="inlineStr"><is><t xml:space="preserve">' . OfficePackage::xml($value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($s['filter'] !== '') $xml .= '<autoFilter ref="' . $s['filter'] . '"/>';
        if ($s['merges']) {
            $xml .= '<mergeCells count="' . count($s['merges']) . '">';
            foreach ($s['merges'] as $range) $xml .= '<mergeCell ref="' . $range . '"/>';
            $xml .= '</mergeCells>';
        }
        foreach ($s['conditional'] as $i => $range) $xml .= '<conditionalFormatting sqref="' . $range . '"><cfRule type="colorScale" priority="' . ($i + 1) . '"><colorScale><cfvo type="num" val="0"/><cfvo type="max"/><color rgb="FFFFFFFF"/><color rgb="FF' . ReportStyle::HEATMAP_MAX . '"/></colorScale></cfRule></conditionalFormatting>';
        $xml .= '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/><pageSetup paperSize="' . $s['paper'] . '" orientation="landscape" fitToWidth="1" fitToHeight="' . $s['fit_height'] . '"/>';
        if($s['breaks']){$xml.='<rowBreaks count="'.count($s['breaks']).'" manualBreakCount="'.count($s['breaks']).'">';foreach($s['breaks'] as $row)$xml.='<brk id="'.$row.'" min="0" max="16383" man="1"/>';$xml.='</rowBreaks>';}
        if ($s['charts']) $xml .= '<drawing r:id="rIdChart"/>';
        return $xml . '</worksheet>';
    }

    private function package(): string
    {
        $ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $files = ['_rels/.rels' => $rels . '<Relationship Id="rId1" Type="' . $ns . 'officeDocument" Target="xl/workbook.xml"/></Relationships>', 'xl/styles.xml' => $this->styles()];
        $workbook = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $workbookRels = $rels . '<Relationship Id="rIdStyles" Type="' . $ns . 'styles" Target="styles.xml"/>';
        $chartNumber = 0;
        foreach ($this->sheets as $index => $s) {
            $number = $index + 1;
            $workbook .= '<sheet name="' . OfficePackage::xml($s['name']) . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
            $workbookRels .= '<Relationship Id="rId' . $number . '" Type="' . $ns . 'worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
            $files['xl/worksheets/sheet' . $number . '.xml'] = $this->worksheet($s);
            $types .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            if (!$s['charts']) continue;
            $files['xl/worksheets/_rels/sheet' . $number . '.xml.rels'] = $rels . '<Relationship Id="rIdChart" Type="' . $ns . 'drawing" Target="../drawings/drawing' . $number . '.xml"/></Relationships>';
            $drawing = '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
            $drawingRels = $rels;
            foreach ($s['charts'] as $chartIndex => $chart) {
                $chartNumber++;
                $id = 'rId' . ($chartIndex + 1);
                $drawing .= XlsxChart::anchor($chart, $id, $chartNumber);
                $drawingRels .= '<Relationship Id="' . $id . '" Type="' . $ns . 'chart" Target="../charts/chart' . $chartNumber . '.xml"/>';
                $files['xl/charts/chart' . $chartNumber . '.xml'] = XlsxChart::xml($chart);
                $types .= '<Override PartName="/xl/charts/chart' . $chartNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
            }
            $files['xl/drawings/drawing' . $number . '.xml'] = $drawing . '</xdr:wsDr>';
            $files['xl/drawings/_rels/drawing' . $number . '.xml.rels'] = $drawingRels . '</Relationships>';
            $types .= '<Override PartName="/xl/drawings/drawing' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
         $defined='<definedNames>';foreach($this->sheets as $i=>$sheet){$last=$sheet['print_last']??max(array_keys($sheet['rows']));foreach($sheet['charts'] as $chart)$last=max($last,($chart['anchor'][3]??0)+1);$name=str_replace("'","''",$sheet['name']);$defined.='<definedName name="_xlnm.Print_Area" localSheetId="'.$i.'">'.OfficePackage::xml("'".$name."'!\$A\$1:\$".self::col(count($sheet['widths'])).'$'.$last).'</definedName>';if(in_array($sheet['name'],['Dados mensais','Dados diários','Notas e critérios'],true))$defined.='<definedName name="_xlnm.Print_Titles" localSheetId="'.$i.'">'.OfficePackage::xml("'".$name."'!\$7:\$7").'</definedName>';}$defined.='</definedNames>';
        $files['xl/workbook.xml'] = $workbook . '</sheets>'.$defined.'<calcPr calcId="191029" fullCalcOnLoad="1" forceFullCalc="1"/></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = $workbookRels . '</Relationships>';
        $files['[Content_Types].xml'] = $types . '</Types>';
        return OfficePackage::write($files);
    }

    private function styles(): string
    {
        $xml = '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="9"><numFmt numFmtId="164" formatCode="0.00%"/><numFmt numFmtId="165" formatCode="&quot;R$&quot; #,##0.00;[Red](&quot;R$&quot; #,##0.00)"/><numFmt numFmtId="166" formatCode="+0.00;-0.00;0.00"/><numFmt numFmtId="167" formatCode="0.00%&quot; P&quot;"/><numFmt numFmtId="168" formatCode="0.00%&quot; ?&quot;"/><numFmt numFmtId="169" formatCode="mm/yyyy"/><numFmt numFmtId="170" formatCode="[h]:mm:ss"/><numFmt numFmtId="171" formatCode="dd/mm/yyyy"/><numFmt numFmtId="172" formatCode="0.00%&quot; E&quot;"/></numFmts><fonts count="5">';
        foreach ([[11, ReportStyle::NAVY, false], [21, ReportStyle::WHITE, true], [11, ReportStyle::WHITE, true], [10, ReportStyle::MUTED, false], [11, ReportStyle::NAVY, true]] as $font) {
            $xml .= '<font><sz val="' . $font[0] . '"/><color rgb="FF' . $font[1] . '"/><name val="Arial"/>' . ($font[2] ? '<b/>' : '') . '</font>';
        }
        $xml .= '</fonts><fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ([ReportStyle::NAVY, ReportStyle::BLUE, ReportStyle::LIGHT_GRAY, ReportStyle::CAUTION_FILL, 'E7EDFF', 'FCE3E3', 'E8E8E8'] as $color) $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';
        $xml .= '</fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="hair"><color rgb="FF' . ReportStyle::BORDER . '"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="22">';
        // base, title, subtitle, header, note, subtotal label, percent, money, count, delta, subtotal money
        foreach ([[0,0,0,1],[0,1,2,0],[0,2,3,0],[0,2,2,0],[0,3,0,1],[0,4,4,1],[164,0,0,1],[165,0,0,1],[3,0,0,1],[166,0,0,1],[165,4,4,1],[164,0,6,1],[164,0,7,1],[167,0,5,1],[168,0,8,1],[0,3,8,1],[169,0,0,1],[170,0,0,1],[171,0,0,1],[0,0,8,0],[3,0,0,1],[172,0,8,1]] as $index => $style) {
            $xml .= '<xf numFmtId="' . $style[0] . '" fontId="' . $style[1] . '" fillId="' . $style[2] . '" borderId="' . $style[3] . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . ($index === 20 ? 'center' : ($style[0] ? 'right' : 'left')) . '" vertical="center" wrapText="1" indent="' . ($index === 20 ? 0 : 1) . '"/></xf>';
        }
        return $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
