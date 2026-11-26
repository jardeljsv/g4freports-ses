<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\AnnualDocxExporter;
use GlpiPlugin\G4freports\Ses\AnnualReport;
use GlpiPlugin\G4freports\Ses\OfficePackage;

$checks = 0;
function annualDocxAssert(bool $condition, string $message): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function annualDocxXml(string $xml): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $valid = $dom->loadXML($xml);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    annualDocxAssert($valid, 'OOXML is well formed');
    $xp = new DOMXPath($dom);
    foreach (['w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'c' => 'http://schemas.openxmlformats.org/drawingml/2006/chart'] as $prefix => $uri) $xp->registerNamespace($prefix, $uri);
    return $xp;
}
$months = []; $records = [];
for ($m = 1; $m <= 12; $m++) {
    $month = sprintf('2026-%02d', $m); $months[] = $month;
    if (in_array($m, [7, 12], true)) continue;
    $metrics = [];
    for ($n = 1; $n <= 12; $n++) {
        $id = 'INS' . $n; $lower = in_array($n, [5, 9, 10], true);
        $value = [1 => 0, 2 => 100, 3 => 125, 4 => 120, 5 => 115, 6 => 110, 8 => 90, 9 => 92, 10 => 93, 11 => 94][$m];
        if ($lower) $value = $m === 1 ? 125 : 12 - $m;
        $metrics[$id] = ['id' => $id, 'label' => 'Rótulo arquivado ' . $id, 'title' => 'Título histórico ' . $id . ($m === 6 ? ' revisado' : ''),
            'status' => $m === 4 ? 'provisional' : ($m === 8 ? 'pending' : ($m === 1 ? 'unmet' : 'met')),
            'value' => $value, 'target' => $lower ? 10 : 85, 'direction' => $lower ? 'lte' : 'gte',
            'numerator' => $value, 'denominator' => $m === 1 ? 0 : 100, 'method' => 'Método histórico preservado',
            'note' => $m === 1 ? 'Nota arquivada: <texto> & =1+1' : '',
            'comparability' => ['known' => $m !== 5, 'fingerprint' => hash('sha256', $m === 6 ? 'changed-scope' : 'same-scope'), 'context' => ['rules_version'=>'frozen-rules-7','snapshot_schema'=>1,'timezone'=>'America/Sao_Paulo','source_glpi'=>str_repeat('a',64),'entity_id'=>1,'group_ids'=>[2],'date_basis'=>'solvedate','source_callcenter'=>str_repeat('b',64),'queue_filter'=>'support','test_numbers_revision'=>str_repeat('c',64),'call_date_basis'=>'queue_entry']]];
    }
    $days=[]; $dayCount=(int)(new DateTimeImmutable($month.'-01'))->format('t');
    for ($d=1; $d<=$dayCount; $d++) {
        $tickets=320+$m-80; $calls=1000+$m-230; $remaining=$dayCount-2;
        $days[]=['date'=>$month.'-'.sprintf('%02d',$d),'tickets'=>$d===1?0:($d===2?80:intdiv($tickets,$remaining)+($d-3<$tickets%$remaining?1:0)),'calls'=>$d===1?0:($d===2?230:intdiv($calls,$remaining)+($d-3<$calls%$remaining?1:0)),'excluded_calls'=>0];
    }
    if ($m===10) for($d=20;$d<$dayCount;$d++) $days[$d]['tickets']=$days[$d]['calls']=null;
    $activity=['schema_version'=>1,'revision'=>str_repeat('d',64),'month'=>$month,'status'=>$m===10?'provisional':'complete','period_completeness'=>$m===10?'provisional':'complete','generated_at'=>$month.'-28 10:00:00','timezone'=>'America/Sao_Paulo','days'=>$days,'series'=>[],'notes'=>$m===10?['Atividade diária provisória; dias futuros sem valores.']:[]];
    foreach(['tickets','calls','excluded_calls'] as $source) $activity['series'][$source]=['available'=>true,'status'=>$m===10?'partial':'complete','total'=>array_sum(array_column($days,$source)),'observed'=>array_sum(array_column($days,$source)),'assigned'=>array_sum(array_column($days,$source)),'unassigned'=>$m===10&&$source!=='excluded_calls'?($source==='tickets'?320+$m:1000+$m)-array_sum(array_column($days,$source)):0,'invalid_dates'=>0,'out_of_month'=>0,'future_dates'=>0,'reconciled'=>$m!==10,'date_basis'=>$source==='tickets'?'solvedate':'queue_entry'];
    $records[] = ['id' => 'revision-' . $month, 'month' => $month, 'archived_at' => $month . '-28 11:00:00', 'summary' => [
        'schema_version' => 1, 'month' => $month, 'generated_at' => $month . '-28 10:00:00', 'rules_version' => 'frozen-rules-7',
        'source_identity' => ['status' => 'known', 'value' => ['glpi' => str_repeat('a', 64), 'callcenter' => str_repeat('b', 64)]],
        'activity'=>$m===8?null:$activity, 'scope_label' => 'Escopo congelado ' . $month, 'period' => ['start' => $month . '-01', 'end_exclusive' => (new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m-d'), 'completeness' => $m === 10 ? 'provisional' : ($m === 11 ? 'unknown' : 'complete')],
        'metrics' => $metrics, 'financial' => ['available' => true, 'complete' => $m < 3,
            'gross_cents' => 100001, 'deduction_cents' => 101, 'adjustment_cents' => -10, 'net_cents' => 99890,
            'notes' => $m === 3 ? ['Apuração financeira parcial'] : []],
        'totals' => ['tickets' => 320 + $m, 'requests' => 300, 'incidents' => 20 + $m, 'calls_received' => 1000 + $m,
            'calls_excluded'=>0, 'calls_answered' => 900, 'mean_talk_seconds' => 125.5, 'mean_wait_seconds' => 12.25, 'mean_talk_count' => 89, 'mean_wait_count' => 90],
        'staffing' => ['billing_professionals_total' => 12, 'note' => 'Nota mensal de equipe'],
        'manual' => ['executive_summary' => 'Análise arquivada da competência ' . $month . '.', 'actions' => "Ação registrada.\nResponsável informado no mês.", 'notes' => '=SUM(A1:A2) & <literal>', 'phone_survey_actions' => 'Providência telefônica preservada.'],
        'warnings' => ['Ressalva histórica preservada.'],
    ]];
}
$report = AnnualReport::build($records, $months); $unchanged = $report;
$writer = new AnnualDocxExporter(); $binary = $writer->toBinary($report); $parts = OfficePackage::read($binary);
annualDocxAssert($report === $unchanged, 'Exporter does not mutate archived report');
$largeFinancial = $report;
$largeFinancial['financial']['subtotal_cents']['gross'] = 9007199254740991;
annualDocxAssert(str_contains(OfficePackage::read($writer->toBinary($largeFinancial))['word/document.xml'], 'R$ 90.071.992.547.409,91'), 'Frozen cent integers remain exact without floating-point money conversion');
annualDocxAssert($writer->filename($report) === 'Relatorio_Comparativo_SES_2026-01_a_2026-12.docx', 'Filename includes selected range');
$xp = annualDocxXml($parts['word/document.xml']); $text = $xp->evaluate('string(/w:document)');
foreach (['0,00%', '100,00%', '125,00%', 'Provisório', 'pendente', 'ausente',
    'origem', 'Título histórico INS1 revisado', 'Rótulo arquivado INS1', 'Método histórico preservado', 'Nota arquivada: <texto> & =1+1', 'Escopo congelado 2026-01',
    'Providência telefônica preservada.', 'R$ 2.000,02', 'R$ -0,20', 'Subtotal de 2 de 12', 'Não há indicador anual agregado', 'período provisório', 'encerramento não confirmado',
    'TMA: 0:02:06 (125,50 s)', 'TME: 0:00:12 (12,25 s)', 'conversas com duração válida: 89', 'requisições: 300',
    'Principais observações','Volumes mensais','Distribuição diária','80 (dia 02)','230 (dia 02)','nenhum registro atribuído ao dia','fora do calendário','Profissionais faturados: 12','Sem data atribuída'] as $needle) {
    annualDocxAssert(stripos($text, $needle) !== false, 'Frozen value, analysis or caveat preserved: ' . $needle);
}
annualDocxAssert($xp->query('//w:p[w:r/w:t[contains(.,"Ação registrada.")]]/w:r/w:br')->length>0,'Multiline manual notes retain explicit Word line breaks');
annualDocxAssert(!str_contains($text, str_repeat('a', 64)), 'Opaque source identity hashes are not exposed');
annualDocxAssert($xp->query('//w:rFonts[@w:ascii="Arial"]')->length > 0, 'G4F report font preserved');
annualDocxAssert($xp->evaluate('string(//w:pgMar/@w:top)') === '1560', 'Letterhead top margin preserved');
annualDocxAssert($xp->evaluate('string(//w:pgMar/@w:bottom)') === '1560', 'Letterhead bottom margin preserved');
annualDocxAssert($xp->query('//w:tblHeader')->length > 0, 'Long tables repeat header rows');
$original = OfficePackage::read(file_get_contents(dirname(__DIR__) . '/resources/docx/papel_timbrado_G4F.docx'));
foreach ($original as $path => $content) {
    if (preg_match('#^word/(?:header|footer|media)#', $path)) annualDocxAssert(($parts[$path] ?? '') === $content, 'Original G4F letterhead retained: ' . $path);
}
$chartCount = 0; $metricChart = null;
foreach ($parts as $path => $xml) {
    if (str_ends_with($path, '.xml') || str_ends_with($path, '.rels')) annualDocxXml($xml);
    if (!preg_match('#^word/charts/ses_chart_\d+\.xml$#', $path)) continue;
    $chartCount++; $chart = annualDocxXml($xml);
    annualDocxAssert($chart->query('//c:lineChart')->length === 1, 'Annual trends use native line charts');
    annualDocxAssert($chart->query('//c:dateAx')->length === 1, 'Real date axes preserve elapsed calendar time');
    annualDocxAssert($chart->evaluate('string(//c:dateAx/c:numFmt/@formatCode)')==='mm/yy','Numeric monthly dates stay readable in a Portuguese report');
    annualDocxAssert($chart->query('//c:ser/c:dLbls[c:showVal[@val="1"]]')->length > 0, 'Annual result series have native numeric labels');
    foreach ($chart->query('//c:ser') as $series) {
        $name = $chart->evaluate('string(c:tx/c:strRef/c:strCache/c:pt/c:v)', $series);
        if (str_starts_with($name, 'Meta arquivada')) { annualDocxAssert(str_contains($name, '%'), 'Target value is identified in the legend'); continue; }
        annualDocxAssert($chart->query('c:dLbls/c:showVal[@val="1"]', $series)->length === 1, 'Each result segment requests numeric labels');
        foreach ($chart->query('c:val/c:numRef/c:numCache/c:pt', $series) as $point) {
            $index = (int)$point->getAttribute('idx');
            annualDocxAssert($chart->query('c:dLbls/c:dLbl[c:idx[@val="'.$index.'"]]/c:delete[@val="1"]', $series)->length === 0, 'Every result point retains a visible native value label');
        }
    }
    annualDocxAssert($chart->query('//c:ser/c:val/c:numRef/c:numCache/c:pt[@idx="6"]')->length === 0, 'Missing selected month remains a gap in every series');
    annualDocxAssert($chart->query('//c:cat/c:numRef')->length > 0, 'Category values are numeric date serials');
    $book = OfficePackage::read($parts['word/embeddings/ses_chart_' . $chartCount . '.xlsx']);
    annualDocxAssert(isset($book['xl/worksheets/sheet1.xml']), 'Native Word chart has editable self-contained workbook');
    if ($chartCount===5) $metricChart=$chart;
}
annualDocxAssert($chartCount === 30, 'Two volumes, two durations, eight-month indicator panels and two readable financial panels');
annualDocxAssert($metricChart->query('//c:ser/c:val/c:numRef/c:numCache/c:pt[@idx="0"][c:v="0"]')->length > 0, 'Zero result remains numeric zero');
annualDocxAssert($metricChart->query('//c:ser/c:val/c:numRef/c:numCache/c:pt[@idx="2"][c:v="1.25"]')->length > 0, 'Above-100 percent raw point is retained');
annualDocxAssert((float)$metricChart->evaluate('string(//c:valAx/c:scaling/c:max/@val)') > 1.25, 'Indicator axis preserves values above 100 percent with label headroom');
annualDocxAssert($metricChart->query('//c:dLbls/c:numFmt[@formatCode="[$-416]0.00%"]')->length === $metricChart->query('//c:dLbls')->length, 'Numeric result labels retain two decimal percentage places');
annualDocxAssert($xp->query('//w:tbl[w:tr[1]/w:tc[2]//w:t[text()="1"]]/w:tr[2]/w:tc[3]//w:t[text()="80"]')->length === 1, 'Positive daily counts are visible inside the Word heatmap');
annualDocxAssert(str_contains($text, 'Total nos dias') && str_contains($text, 'Conciliação e cobertura') && str_contains($text, 'Conciliado · Completo'), 'Daily reconciliation accompanies the numeric heatmap');
annualDocxAssert(str_contains($parts['word/charts/ses_chart_5.xml'], 'prstDash val="dash"'), 'Frozen targets have dashed styling');
annualDocxAssert(str_contains($parts['word/charts/ses_chart_5.xml'], 'noFill'), 'Unverified and provisional points are marker-only');
annualDocxAssert(str_contains($parts['word/charts/ses_chart_3.xml'],'[h]:mm:ss'), 'Duration axis uses hours minutes seconds');
$gapRecords = [$records[0], $records[2]];
$gapRecords[0]['summary']['metrics']['INS1']['value'] = 80;
$gapRecords[1]['summary']['metrics']['INS1']['value'] = 90;
foreach ([80,90] as $i=>$value) {
    $gapRecords[$i]['summary']['metrics']['INS1']['numerator'] = $value;
    $gapRecords[$i]['summary']['metrics']['INS1']['denominator'] = 100;
}
$gap = AnnualReport::build($gapRecords, ['2026-01', '2026-03']);
$gapParts=OfficePackage::read($writer->toBinary($gap));
annualDocxAssert(str_contains($gapParts['word/document.xml'],'Intervalo entre meses não consecutivos'), 'Nonconsecutive comparison interval is explained');
annualDocxAssert(str_contains($gapParts['word/document.xml'], 'Comparações entre competências selecionadas'), 'Selected interval comparisons have a separate supporting heading');
annualDocxAssert(str_contains($gapParts['word/document.xml'], '2026-01 → 2026-03 (1 competência(s) não selecionada(s))'), 'Selected interval identifies both endpoints and skipped month');
annualDocxAssert(str_contains($gapParts['word/document.xml'], 'INS1: +10 p.p. (melhora)') && str_contains($gapParts['word/document.xml'], 'INS5: -116 p.p. (melhora)'), 'Frozen gap deltas retain their signs and direction-specific improvement labels');
annualDocxAssert(str_contains($gapParts['word/document.xml'], 'não variações mensais; os gráficos mantêm os pontos separados'), 'Selected interval deltas are not presented as monthly variation');
$gapChart = annualDocxXml($gapParts['word/charts/ses_chart_5.xml']);
annualDocxAssert($gapChart->query('//c:ser[c:val/c:numRef/c:numCache/c:ptCount/@val="2"]/c:spPr/*[local-name()="ln"]/*[local-name()="solidFill"]')->length===0, 'Nonconsecutive results remain unconnected in the metric trend');
$incompatibleGap = $gapRecords;
$incompatibleGap[1]['summary']['metrics']['INS1']['comparability']['fingerprint'] = hash('sha256', 'incompatible');
annualDocxAssert(!str_contains(OfficePackage::read($writer->toBinary(AnnualReport::build($incompatibleGap, ['2026-01','2026-03'])))['word/document.xml'], 'INS1: +10 p.p.'), 'Invalid interval comparison is never added to the supporting section');
$sparseRecords=[$records[0],$records[2]];
foreach(['2000-01','2100-01'] as $i=>$month) { $sparseRecords[$i]['month']=$month; $sparseRecords[$i]['summary']['month']=$month; $sparseRecords[$i]['summary']['activity']=null; }
$sparse=OfficePackage::read($writer->toBinary(AnnualReport::build($sparseRecords,['2000-01','2100-01'])));
$sparseChart=annualDocxXml($sparse['word/charts/ses_chart_1.xml']);
annualDocxAssert($sparseChart->evaluate('string(//c:ser[1]/c:cat/c:numRef/c:numCache/c:ptCount/@val)')==='2','Century-spanning selection retains only selected points without calendar expansion');
annualDocxAssert($sparseChart->query('//c:dateAx')->length===1,'Sparse dates retain real elapsed time');
annualDocxAssert($sparseChart->evaluate('string(//c:dateAx/c:numFmt/@formatCode)')==='yyyy','Century-spanning axis labels cannot confuse2000with2100');
$missing = OfficePackage::read($writer->toBinary(AnnualReport::build([], ['2026-01'])));
annualDocxAssert(!isset($missing['word/charts/ses_chart_1.xml']), 'All missing monthly values do not fabricate a chart');
annualDocxAssert(!str_contains($missing['word/document.xml'], 'R$ 0,00'), 'No complete financial month never displays a false zero subtotal');
$statusRecords=$records; $statusRecords[0]['summary']['metrics']['INS1']['status']='not_applicable';
$statusParts=OfficePackage::read($writer->toBinary(AnnualReport::build($statusRecords,$months)));
$statusXml=annualDocxXml($statusParts['word/document.xml']);
annualDocxAssert($statusXml->query('//w:tbl[1]/w:tr[2]/w:tc[2]//w:t[text()="NA"]')->length===1,'Not applicable is distinct from a missing month in the status matrix');
annualDocxAssert($xp->query('//w:tbl[1]//w:t[text()="Pd"]')->length>0,'Pending is distinct from provisional in the status matrix');
$negative=$report; $negative['financial']['months'][0]['net_cents']=-100000;
$negativeParts=OfficePackage::read($writer->toBinary($negative)); $negativeChart=annualDocxXml($negativeParts['word/charts/ses_chart_29.xml']);
annualDocxAssert($negativeChart->query('//c:val/c:numRef/c:numCache/c:pt/c:v[text()="-1000"]')->length>0,'Negative frozen net amount remains negative in financial trend');
annualDocxAssert($negativeChart->query('//c:valAx/c:scaling/c:min[@val="0"]')->length===0,'Financial trend does not force a zero lower bound over a negative amount');
annualDocxAssert(str_contains($negativeParts['word/charts/ses_chart_29.xml'],'Base faturada') && str_contains($negativeParts['word/charts/ses_chart_29.xml'],'Valor líquido'),'Gross and net have identified native series');
$sourceCoverage=$records; $sourceCoverage[0]['summary']['activity']['status']='partial';
$sourceCoverage[0]['summary']['activity']['series']['excluded_calls']['status']='unavailable';
$sourceParts=OfficePackage::read($writer->toBinary(AnnualReport::build($sourceCoverage,$months)));
annualDocxAssert(!str_contains($sourceParts['word/document.xml'],'2026-01*'),'Excluded-call incompleteness does not mislabel complete ticket/call heatmap rows');
$longMonths = [];
for ($y = 2025; $y <= 2026; $y++) for ($m = 1; $m <= 12; $m++) $longMonths[] = sprintf('%04d-%02d', $y, $m);
$longRecords = $records;
foreach ($records as $record) {
    $record = json_decode(str_replace('2026-', '2025-', json_encode($record)), true);
    $record['month'] = '2025-' . substr($record['month'], 5);
    $record['id'] = 'revision-' . $record['month'];
    $record['summary']['month'] = $record['month'];
    if (is_array($record['summary']['activity']??null)) { $record['summary']['activity']['month']=$record['month']; foreach($record['summary']['activity']['days'] as &$day) $day['date']='2025-'.substr($day['date'],5); unset($day); }
    $longRecords[] = $record;
}
$longReport = AnnualReport::build($longRecords, $longMonths);
$longBinary = $writer->toBinary($longReport);
$long = OfficePackage::read($longBinary);
annualDocxAssert(str_contains($long['word/document.xml'], '2025-01') && str_contains($long['word/document.xml'], '2026-12'), '24 months retain both years in exact tables');
annualDocxAssert(count(array_filter(array_keys($long), static fn($path) => (bool)preg_match('#^word/charts/ses_chart_\d+\.xml$#', $path))) === 48, '24-month report uses readable panels without dropping observations');
$longScales = [];
foreach ($long as $path => $xml) {
    if (!preg_match('#^word/charts/ses_chart_\d+\.xml$#', $path)) continue;
    $cx = annualDocxXml($xml);
    annualDocxAssert((int)$cx->evaluate('string(//c:ser[1]/c:cat/c:numRef/c:numCache/c:ptCount/@val)') <= 12, 'Each panel keeps at most twelve monthly observations');
    $cx->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    $title = $cx->evaluate('string(//c:title/c:tx/c:rich/a:p/a:r/a:t)');
    if (str_starts_with($title, 'INS1 ·')) $longScales[] = [$cx->evaluate('string(//c:valAx/c:scaling/c:min/@val)'), $cx->evaluate('string(//c:valAx/c:scaling/c:max/@val)')];
}
annualDocxAssert(count($longScales) === 3 && count(array_unique(array_map('serialize', $longScales))) === 1, 'Split indicator panels preserve one common scale');
$largeDailyRecords = [$records[0]];
foreach ($largeDailyRecords[0]['summary']['activity']['days'] as &$day) $day['tickets'] = substr($day['date'], -2) === '02' ? 100000 : 0;
unset($day);
$largeDailyRecords[0]['summary']['totals']['tickets'] = 100000;
foreach (['total','observed','assigned'] as $key) $largeDailyRecords[0]['summary']['activity']['series']['tickets'][$key] = 100000;
$largeDaily = OfficePackage::read($writer->toBinary(AnnualReport::build($largeDailyRecords, ['2026-01'])));
$dx = annualDocxXml($largeDaily['word/document.xml']);
$singleChart = annualDocxXml($largeDaily['word/charts/ses_chart_1.xml']);
annualDocxAssert($singleChart->query('//c:dateAx/c:scaling/c:min')->length === 1 && $singleChart->query('//c:dateAx/c:scaling/c:max')->length === 1, 'A singleton date axis has a nondegenerate visible range');
annualDocxAssert($dx->query('//w:tbl[w:tr[1]/w:tc[2]//w:t[text()="1"]]/w:tr[2]/w:tc[3]//w:t[text()="100.000"]')->length === 1, 'Six-digit daily volume is retained as a readable integer');
if (($output=getenv('ANNUAL_DOCX_LARGE_DAILY_OUTPUT')) !== false && $output !== '') file_put_contents($output, OfficePackage::write($largeDaily));
$zeroMoneyRecord = $records[0];
foreach (['gross_cents','net_cents','deduction_cents','adjustment_cents'] as $field) $zeroMoneyRecord['summary']['financial'][$field] = 0;
$zeroMoney = OfficePackage::read($writer->toBinary(AnnualReport::build([$zeroMoneyRecord], ['2026-01'])));
$zeroMoneyChart = annualDocxXml($zeroMoney['word/charts/ses_chart_17.xml']);
annualDocxAssert($zeroMoneyChart->query('//c:ser/c:val/c:numRef/c:numCache/c:pt/c:v[text()="0"]')->length === 2, 'Zero gross and net remain explicit real values');
annualDocxAssert($zeroMoneyChart->evaluate('string(//c:dateAx/c:tickLblPos/@val)') === 'low', 'Financial month labels stay below zero-value data labels');
annualDocxAssert((float)$zeroMoneyChart->evaluate('string(//c:valAx/c:scaling/c:min/@val)') < 0, 'Zero financial labels have lower plot headroom');
if (($output = getenv('ANNUAL_DOCX_ZERO_MONEY_OUTPUT')) !== false && $output !== '') file_put_contents($output, OfficePackage::write($zeroMoney));
// Changing targets remain recoverable by month without competing with result labels.
$varyingRecords = []; $varyingMonths = [];
for ($monthIndex = 1; $monthIndex <= 12; $monthIndex++) {
    $record = $records[0]; $month = sprintf('2026-%02d', $monthIndex); $varyingMonths[] = $month;
    $record['id'] = 'varying-' . $month; $record['month'] = $month; $record['summary']['month'] = $month;
    $record['summary']['activity'] = null; $record['summary']['period']['completeness'] = 'complete';
    foreach ($record['summary']['metrics'] as &$metric) {
        $metric['value'] = $monthIndex === 1 ? 250 : 99.50; $metric['target'] = 80 + $monthIndex / 10;
        $metric['numerator'] = $metric['value']; $metric['denominator'] = 100; $metric['status'] = 'met';
    }
    unset($metric);
    $record['summary']['financial']['gross_cents'] = 900000000000001 + $monthIndex;
    $record['summary']['financial']['net_cents'] = 899999999999997 + $monthIndex;
    $varyingRecords[] = $record;
}
$varying = OfficePackage::read($writer->toBinary(AnnualReport::build($varyingRecords, $varyingMonths)));
$vx = annualDocxXml($varying['word/document.xml']);
annualDocxAssert($vx->query('//w:tbl[w:tr[2]/w:tc[1]//w:t[text()="Meta"]]')->length === 24, 'Every varying target trend has an adjacent monthly target row');
annualDocxAssert($vx->query('//w:tbl[w:tr[2]/w:tc[1]//w:t[text()="Meta"]]/w:tr[2]/w:tc//w:t[text()="80,10%" or text()="81,20%"]')->length === 24, 'Varying target endpoints retain their exact frozen values');
$moneyPoints = 0; $moneyScales = [];
foreach ($varying as $path => $xml) {
    if (!preg_match('#^word/charts/ses_chart_\d+\.xml$#', $path) || !str_contains($xml, 'Base faturada e valor líquido')) continue;
    $cx = annualDocxXml($xml); $cx->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    $moneyPoints += $cx->query('//c:ser/c:val/c:numRef/c:numCache/c:pt')->length;
    annualDocxAssert($cx->query('//c:ser/c:dLbls/c:numFmt[@formatCode=\'[$-416]"R$" #,##0.00\']')->length === $cx->query('//c:ser')->length, 'Large money labels retain exact two-decimal currency format');
    annualDocxAssert($cx->query('//c:dLbls/c:txPr/a:bodyPr[@wrap="none"]')->length === $cx->query('//c:ser')->length, 'Long currency labels are not broken across lines');
    $moneyScales[] = [$cx->evaluate('string(//c:valAx/c:scaling/c:min/@val)'), $cx->evaluate('string(//c:valAx/c:scaling/c:max/@val)')];
}
annualDocxAssert($moneyPoints === 24, 'Adaptive large-money panels retain all gross and net monthly points');
annualDocxAssert(count(array_unique(array_map('serialize', $moneyScales))) === 1, 'Adaptive financial panels preserve one common scale');
if (($output = getenv('ANNUAL_DOCX_VARIED_OUTPUT')) !== false && $output !== '') file_put_contents($output, OfficePackage::write($varying));
foreach ([[['month' => '2026-01'], ['month' => '2026-01']], array_fill(0, 25, ['month' => '2026-01'])] as $invalid) {
    try { $writer->toBinary(['months' => $invalid]); annualDocxAssert(false, 'Invalid month selection should fail'); }
    catch (InvalidArgumentException $e) { annualDocxAssert(true, 'Duplicate or unbounded selection rejected'); }
}
if (($output = getenv('ANNUAL_DOCX_OUTPUT')) !== false && $output !== '') file_put_contents($output, $binary);
if (($output = getenv('ANNUAL_DOCX_LONG_OUTPUT')) !== false && $output !== '') file_put_contents($output, $longBinary);
if (($output=getenv('ANNUAL_DOCX_SPARSE_OUTPUT')) !== false && $output !== '') file_put_contents($output, OfficePackage::write($sparse));
if (($output=getenv('ANNUAL_DOCX_MISSING_OUTPUT')) !== false && $output !== '') file_put_contents($output, OfficePackage::write($missing));
if (($output=getenv('ANNUAL_DOCX_GAP_OUTPUT')) !== false && $output !== '') file_put_contents($output, OfficePackage::write($gapParts));
echo 'annual_docx_export_test: ' . $checks . " checks passed\n";
