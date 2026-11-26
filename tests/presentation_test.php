<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Synthetic reconciliation checks; no customer records or connection required. */
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\ChartData;
use GlpiPlugin\G4freports\Ses\MetricEvidence;
use GlpiPlugin\G4freports\Ses\PhoneSurveySummary;

function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
foreach ([5 => 1, 4 => 2, 3 => 3, 2 => 4] as $raw => $severity) {
    verify(MetricEvidence::contractSeverity($raw) === $severity, 'Contract severity mapping');
}
verify(str_contains(MetricEvidence::title('INS4', 5), 'Severidade 1'), 'Alternative selection preserves actual contractual severity');
verify(MetricEvidence::priorityName(5) === 'Muito alta', 'Named priority');
$calls = [
    ['id' => 'call-a', 'queue' => 'first', 'entered_at' => '2026-06-01 08:00:00', 'satisfaction_raw' => 1],
    ['id' => 'call-a', 'queue' => 'second', 'entered_at' => '2026-06-01 08:01:00', 'satisfaction_raw' => 1],
    ['id' => 'call-b', 'queue' => 'first', 'entered_at' => '2026-06-01 09:00:00', 'satisfaction_raw' => null],
    ['id' => 'call-c', 'queue' => 'first', 'entered_at' => '2026-06-01 10:00:00', 'satisfaction_raw' => ''],
    ['id' => 'call-d', 'queue' => 'first', 'entered_at' => '2026-06-01 11:00:00', 'satisfaction_raw' => 7],
];
foreach (['glpi_and_phone', 'glpi_only'] as $mode) {
    $summary = PhoneSurveySummary::build($calls, true, '', $mode);
    $dist = ChartData::phoneSurveyDistribution(['calls' => $calls, 'phone_survey' => $summary]);
    $counts = array_column($dist['rows'], 1, 0);
    verify($dist['total'] === 4, 'Distinct-call distribution does not count second queue twice');
    verify($counts[5] === 1 && $counts['Não preenchida'] === 2 && $counts['Nota inválida'] === 1, 'Separate rating, blank and invalid classifications');
    verify($summary['valid_responses'] === 1 && $summary['favorable_responses'] === 1, 'Blank and invalid surveys excluded from percentage');
    verify($summary['without_response'] === $counts['Não preenchida'], 'Summary reconciles with full distribution');
    verify($summary['included'] === ($mode === 'glpi_and_phone'), 'Selection changes participation, not the independent source evidence');
}
$missing = ChartData::phoneSurveyDistribution(['calls' => $calls, 'phone_survey' => PhoneSurveySummary::build($calls, false)]);
verify(!$missing['available'], 'Unavailable source is not zero answers');
$empty = ChartData::phoneSurveyDistribution(['calls' => [], 'phone_survey' => PhoneSurveySummary::build([], true)]);
verify($empty['available'] && $empty['total'] === 0, 'Available empty population distinguished from missing source');
echo "Presentation checks passed: severity, source separation, distinct calls, blanks, invalid and unavailable surveys.\n";

// Each service-level chart represents a percentage comparison, never a mix of
// population counts, optional departure reasons, or combined survey sources.
$targets = [1 => 90, 2 => 90, 3 => 90, 4 => 90, 5 => 2, 6 => 80,
    7 => 85, 8 => 85, 9 => 5, 10 => 10, 11 => 80, 12 => 80];
$metrics = [];
foreach ($targets as $number => $target) {
    $metrics[] = ['id' => 'INS' . $number, 'target' => $target,
        'direction' => in_array($number, [5, 9, 10], true) ? 'lte' : 'gte',
        'numerator' => 17, 'denominator' => 100, 'value' => 17, 'status' => 'unmet'];
}
$definitions = ChartData::build(['metrics' => $metrics, 'period' => ['month' => '2026-06']]);
$wordWriter = new GlpiPlugin\G4freports\Ses\WordChart();
$wordChart = new ReflectionMethod($wordWriter, 'chart');
$wordChart->setAccessible(true);
foreach ($metrics as $index => $metric) {
    $d = $definitions[$metric['id']];
    verify($d['kind'] === 'bar' && $d['unit'] === 'percent' && $d['categories'] === [$metric['id']], 'Individual INS chart uses comparable horizontal percentage bars');
    verify(array_column($d['series'], 'name') === ['Meta', 'Resultado Apurado'], 'Two clear chart legend entries');
    verify($d['series'][0]['values'] === [$metric['target'] / 100] && $d['series'][1]['values'] === [0.17], 'Chart cache uses official target and result');
    verify(str_contains($d['title'], $metric['direction'] === 'lte' ? '≤' : '≥'), 'Title preserves lower/higher-is-better interpretation');
    $xlsx = $d;
    $xlsx['category_ref'] = "'" . $metric['id'] . "'!A1";
    $xlsx['series'][0]['value_ref'] = "'" . $metric['id'] . "'!B11";
    $xlsx['series'][1]['value_ref'] = "'" . $metric['id'] . "'!B10";
    foreach ([$wordChart->invoke($wordWriter, $d, $index + 1), GlpiPlugin\G4freports\Ses\XlsxChart::xml($xlsx)] as $xml) {
        $document = new DOMDocument();
        verify($document->loadXML($xml), 'Chart package XML is well formed');
        $xp = new DOMXPath($document);
        $xp->registerNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        verify($xp->query('//c:barChart/c:ser')->length === 2 && $xp->query('//c:barDir[@val="bar"]')->length === 1, 'Native clustered horizontal bars for both measures');
        verify($xp->query('//c:catAx[c:axPos/@val="l"]/c:scaling/c:orientation[@val="maxMin"]')->length === 1, 'Horizontal categories use the left axis in reading order');
        verify($xp->query('//c:valAx[c:axPos/@val="b"]/c:crosses[@val="max"]')->length === 1, 'Percentage scale stays below the horizontal bars');
        verify($xp->query('//c:legend')->length === 1, 'Native chart includes readable series legend');
        verify($xp->query('//c:barChart/c:ser/c:dLbls[c:showVal/@val="1"]')->length === 2, 'Both target and result have value labels');
        verify($xp->query('//c:barChart/c:ser/c:dLbls/c:numFmt[contains(@formatCode,"%") and @sourceLinked="0"]')->length === 2, 'Percent labels do not depend on workstation number formats');
        verify($xp->evaluate('string(//c:valAx/c:title/c:tx/c:rich/a:p/a:r/a:t)') === 'Percentual (%)', 'Percent axis title is present');
        verify($xp->evaluate('string(//c:catAx/c:title/c:tx/c:rich/a:p/a:r/a:t)') === 'Indicador', 'Category axis title is present');
        foreach ($xp->query('//c:barChart/c:ser') as $series) {
            $children = [];
            foreach ($series->childNodes as $child) if ($child instanceof DOMElement) $children[] = $child->localName;
            verify(array_search('dLbls', $children, true) < array_search('cat', $children, true), 'Office-safe label ordering is retained');
        }
    }
}
$zero = $metrics[9]; $zero['value'] = 0; $zero['numerator'] = 0; $zero['status'] = 'met';
$zeroChart = ChartData::build(['metrics' => [$zero]])['INS10'];
verify($zeroChart['available'] && $zeroChart['series'][1]['values'] === [0.0], 'A genuine zero turnover remains visible');
$zero['denominator'] = 0; $zero['value'] = null; $zero['status'] = 'not_applicable';
$absentChart = ChartData::build(['metrics' => [$zero]])['INS10'];
verify(!$absentChart['available'] && $absentChart['series'][1]['values'] === [null], 'No population is never shown as a successful zero percentage');
$excess = $metrics[9]; $excess['value'] = 200;
verify(ChartData::build(['metrics' => [$excess]])['INS10']['series'][1]['values'] === [2.0], 'Turnover over 100 percent is preserved, not clipped to the target');
$operational = ChartData::build(['period' => ['month' => '2026-06'], 'tickets' => [['id' => 1, 'type' => 2, 'solved_at' => '2026-06-03 10:00:00']]]);
verify($operational['tickets_types']['kind'] === 'pie', 'Operational type composition remains a pie');
verify($operational['tickets_daily']['kind'] === 'column' && $operational['calls_daily']['kind'] === 'column', 'Daily operational columns retain chronological presentation');
verify($operational['tickets_daily']['axis_category_title'] === 'Dia do mês' && $operational['tickets_daily']['axis_value_title'] === 'Quantidade de chamados', 'Operational day and count axes remain clear');
echo "Chart checks passed: 12 percentage comparisons, legends, labels, axes, Office element order, zero/unavailable/over-100 and operational plots.\n";

$literalChart = $definitions['INS10'];
$literalChart['series'][0]['value_ref'] = "'INS10'!B11";
$literalChart['series'][1]['value_ref'] = "'INS10'!B10";
$literalDocument = new DOMDocument();
verify($literalDocument->loadXML(GlpiPlugin\G4freports\Ses\XlsxChart::xml($literalChart)), 'Literal INS category generates well-formed native chart');
$literalXp = new DOMXPath($literalDocument);
$literalXp->registerNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
verify($literalXp->query('//c:ser/c:cat/c:strLit/c:pt[c:v="INS10"]')->length === 2, 'Literal category is present for both comparison series');
verify($literalXp->query('//c:ser/c:cat/c:strRef')->length === 0, 'Missing category reference does not become an empty workbook formula');
verify($literalXp->query('//c:ser/c:val/c:numRef/c:f')->length === 2, 'Literal category does not remove editable source links for values');
echo "Literal category checks passed: native strLit and retained linked metric values.\n";
