<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\AnnualAnalytics;
use GlpiPlugin\G4freports\Ses\AnnualReport;
use GlpiPlugin\G4freports\Ses\MonthlySummary;
use GlpiPlugin\G4freports\Ses\Settings;

$checks = 0;
function analyticsCheck(bool $condition, string $message): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function analyticsSnapshot(string $month, int $step = 0): array
{
    $input = Settings::normalizeInput(['month' => $month], Settings::defaults());
    $metrics = [];
    for ($n = 1; $n <= 12; $n++) {
        $id = 'INS' . $n; $lower = in_array($n, [5, 9, 10], true);
        $value = $lower ? 8 - $step : 90 + $step;
        $metrics[] = ['id' => $id, 'label' => 'Rótulo ' . $id, 'value' => $value, 'numerator' => $value,
            'denominator' => 100, 'target' => $lower ? 10 : 90, 'direction' => $lower ? 'lte' : 'gte', 'status' => 'met'];
    }
    return ['schema_version' => 2, 'rules_version' => Settings::RULES_VERSION, 'input' => $input,
        'generated_at' => str_replace(' ', 'T', $input['end_exclusive']) . '-03:00',
        'period' => ['month' => $month, 'start' => $input['start'], 'end_exclusive' => $input['end_exclusive'],
            'date_basis' => 'solvedate', 'call_date_basis' => 'queue_entry'],
        'source_identity' => ['glpi' => str_repeat('a', 64), 'callcenter' => str_repeat('b', 64)], 'metrics' => $metrics,
        'totals' => ['tickets' => 100 + $step * 10, 'calls_received' => 1000 + $step * 100, 'calls_excluded' => 10,
            'mean_talk_seconds' => 123.456 + $step, 'mean_talk_count' => 800,
            'mean_wait_seconds' => 10 + $step, 'mean_wait_count' => 900],
        'financial' => ['available' => true, 'complete' => true, 'gross_value' => '1000.01', 'deduction_total' => '20.00',
            'adjustment_total' => '-0.03', 'net_value' => $step ? '980.01' : '979.98', 'notes' => []]];
}
function analyticsRecord(array $snapshot): array
{
    $summary = MonthlySummary::fromSnapshot($snapshot);
    return ['id' => 'archive-' . $summary['month'], 'month' => $summary['month'], 'archived_at' => '2026-10-01T00:00:00-03:00', 'summary' => $summary];
}
function analyticsBuild(array $snapshots, ?array $months = null): array
{
    $records = array_map('analyticsRecord', $snapshots);
    $months = $months ?? array_column($records, 'month');
    return AnnualAnalytics::build(AnnualReport::build($records, $months));
}
function analyticsEdges(array $trend, string $role = 'result'): array
{
    $edges = [];
    foreach ($trend['chart']['series'] as $series) if ($series['role'] === $role && $series['line_visible']) {
        $prior = null;
        foreach ($series['values'] as $index => $value) if ($value !== null) {
            if ($prior !== null) $edges[] = [$trend['rows'][$prior]['month'], $trend['rows'][$index]['month']];
            $prior = $index;
        }
    }
    return $edges;
}
function analyticsPoints(array $trend): array
{
    $points = [];
    foreach ($trend['chart']['series'] as $series) if ($series['role'] !== 'target') {
        foreach ($series['values'] as $index => $value) if ($value !== null) $points[$index] = $value;
    }
    ksort($points); return $points;
}

$jan = analyticsSnapshot('2026-01'); $feb = analyticsSnapshot('2026-02', 2); $mar = analyticsSnapshot('2026-03', 3);
$report = AnnualReport::build(array_map('analyticsRecord', [$jan, $feb, $mar]), ['2026-01', '2026-02', '2026-03']);
$unchanged = $report; $a = AnnualAnalytics::build($report);
analyticsCheck($report === $unchanged, 'Analytics never mutates pinned report values or original comparison decisions');
analyticsCheck($a['coverage'] === ['selected' => 3, 'archived' => 3, 'closed' => 3, 'provisional' => 0, 'unknown' => 0, 'missing' => 0], 'Coverage counts distinguish archived and closed selected months');
analyticsCheck($a['metrics']['INS1']['rows'][1]['comparison']['delta_pp'] === 2
    && $a['metrics']['INS5']['rows'][1]['comparison']['delta_pp'] === -2
    && $a['metrics']['INS5']['rows'][1]['comparison']['direction'] === 'improved', 'KPI changes preserve whole percentages and lower-is-better direction');
analyticsCheck($a['metrics']['INS1']['rows'][1]['margin_pp'] === 2 && $a['metrics']['INS1']['rows'][1]['shortfall_pp'] === 0
    && $a['metrics']['INS5']['rows'][1]['margin_pp'] === 4, 'Target margins respect the frozen target operator');
analyticsCheck($a['metrics']['INS1']['coverage'] === ['observations' => 3, 'eligible' => 3, 'comparable_pairs' => 2, 'target_met' => 3, 'target_evaluated' => 3], 'Eligible months and comparable pairs have separate counts');
analyticsCheck($a['volumes']['tickets']['rows'][1]['comparison']['delta_absolute'] === 20
    && $a['volumes']['tickets']['rows'][1]['comparison']['delta_percent'] === 20.0
    && $a['volumes']['tickets']['rows'][1]['comparison']['direction'] === 'increased', 'Volume increases are neutral counts and percentages, never quality claims');
analyticsCheck($a['durations']['tma']['rows'][0]['value'] === 123.456 && $a['durations']['tma']['rows'][0]['sample_count'] === 800
    && $a['durations']['tme']['rows'][1]['comparison']['direction'] === 'increased', 'Frozen unrounded durations and their own sample counts remain distinct from KPI percentages');
analyticsCheck(!isset($a['durations']['tma']['annual_value']) && !isset($a['metrics']['INS10']['annual_value']), 'No pooled TMA/TME or rolling-window INS10 is introduced');
analyticsCheck($a['financial']['trends']['net']['rows'][1]['comparison']['delta_absolute'] === 3
    && $a['financial']['subtotal_cents'] === $report['financial']['subtotal_cents'], 'Financial deltas and original subtotals keep integer cents');
analyticsCheck($a['metrics']['INS1']['chart']['categories'] === ['2026-01-01', '2026-02-01', '2026-03-01']
    && analyticsEdges($a['metrics']['INS1']) === [['2026-01', '2026-02'], ['2026-02', '2026-03']], 'Native date-axis categories and confirmed adjacent line segments are shared');
analyticsCheck(analyticsPoints($a['metrics']['INS1']) === [90, 92, 93], 'Raw chart points use whole percentages without export-specific conversion');
analyticsCheck(count($a['matrix']) === 12 && count($a['matrix'][0]['cells']) === 3, 'Status matrix retains every selected month for every indicator');
analyticsCheck(count($a['findings']) <= 5 && count($a['findings']) >= 3, 'Available evidence produces a short bounded set of findings');
foreach ($a['findings'] as $finding) analyticsCheck(is_string($finding['text']) && $finding['text'] !== ''
    && count($finding['months']) <= 2, 'Each factual finding has text and explicit referenced months');

$zero = $jan; $zero['totals']['tickets'] = 0; $zero['totals']['calls_received'] = 0;
$zero['totals']['mean_talk_seconds'] = 0;
$z = analyticsBuild([$zero, $feb]);
analyticsCheck($z['volumes']['tickets']['rows'][1]['comparison']['delta_absolute'] === 120
    && $z['volumes']['tickets']['rows'][1]['comparison']['delta_percent'] === null, 'Zero baseline retains absolute change but never invents a relative percentage');
analyticsCheck($z['durations']['tma']['rows'][1]['comparison']['delta_percent'] === null
    && analyticsPoints($z['volumes']['tickets'])[0] === 0, 'A measured zero is retained, including as a duration baseline');
$empty = AnnualAnalytics::build(AnnualReport::build([], ['2026-01', '2026-02']));
analyticsCheck($empty['coverage']['missing'] === 2 && !$empty['volumes']['tickets']['chart']['series']
    && $empty['volumes']['tickets']['rows'][0]['value'] === null && $empty['financial']['subtotal_cents']['net'] === null, 'Missing data never becomes zero points or a financial total');

$gapReport = AnnualReport::build([analyticsRecord($jan), analyticsRecord($mar)], ['2026-01', '2026-03']);
$gap = AnnualAnalytics::build($gapReport);
analyticsCheck($gapReport['metrics']['INS1']['months'][1]['comparison']['delta_pp'] === 3
    && !$gap['metrics']['INS1']['rows'][1]['comparison']['eligible'] && !analyticsEdges($gap['metrics']['INS1']),
    'Existing interval comparisons remain intact, while redesigned monthly lines do not join skipped calendar months');
analyticsCheck(analyticsPoints($gap['metrics']['INS1']) === [90, 93], 'Both isolated interval endpoints remain visible');
$missing = analyticsBuild([$jan, $mar], ['2026-01', '2026-02', '2026-03']);
analyticsCheck(!analyticsEdges($missing['metrics']['INS1']) && !$missing['volumes']['calls']['rows'][2]['comparison']['eligible'], 'A selected missing month blocks all bridges');

$partial = $feb; $partial['generated_at'] = '2026-02-10T12:00:00-03:00';
$p = analyticsBuild([$jan, $partial, $mar]);
analyticsCheck($p['coverage']['provisional'] === 1 && !$p['metrics']['INS1']['rows'][1]['eligible']
    && $p['metrics']['INS1']['rows'][1]['status'] === 'met', 'Period provisional status does not overwrite original metric status');
analyticsCheck(!analyticsEdges($p['metrics']['INS1']) && !analyticsEdges($p['financial']['trends']['gross'])
    && analyticsPoints($p['metrics']['INS1']) === [90, 92, 93], 'Open periods keep raw points while blocking both neighboring conclusive lines');
analyticsCheck(count(array_filter($p['metrics']['INS1']['chart']['series'], static fn($s) => $s['role'] === 'provisional' && !$s['line_visible'])) === 1, 'Provisional values use a separate marker-only series');
$bad = $feb; $bad['metrics'][0]['status'] = 'pending'; $bad['metrics'][6]['status'] = 'provisional';
$b = analyticsBuild([$jan, $bad]);
analyticsCheck(!$b['metrics']['INS1']['rows'][1]['eligible'] && !$b['durations']['tma']['rows'][1]['eligible']
    && $b['durations']['tma']['rows'][1]['sample_count'] === 800, 'Incomplete KPI/duration evidence remains descriptive even with a surviving numeric sample');
analyticsCheck($b['volumes']['calls']['rows'][1]['comparison']['eligible'], 'Known received-call cohort volumes do not depend on completed-outcome KPI status');
$bad = $feb; $bad['metrics'][0]['denominator'] = 0;
analyticsCheck(!analyticsBuild([$jan, $bad])['metrics']['INS1']['rows'][1]['eligible'], 'A malformed zero-denominator metric cannot acquire a conclusive trend');

$scope = $feb; $scope['input']['priority_map']['INS1'] = 2;
$s = analyticsBuild([$jan, $scope]);
analyticsCheck(!$s['metrics']['INS1']['rows'][1]['comparison']['eligible']
    && $s['volumes']['tickets']['rows'][1]['comparison']['eligible'], 'Priority-specific KPI changes do not falsely invalidate overall ticket volume');
$scope = $feb; $scope['input']['entity_id'] = 99;
$s = analyticsBuild([$jan, $scope]);
analyticsCheck(!$s['volumes']['tickets']['rows'][1]['comparison']['eligible']
    && $s['volumes']['calls']['rows'][1]['comparison']['eligible'], 'Volume scopes are source-specific');
$scope = $feb; $scope['source_identity']['callcenter'] = str_repeat('c', 64);
$s = analyticsBuild([$jan, $scope]);
analyticsCheck(!$s['volumes']['calls']['rows'][1]['comparison']['eligible']
    && !$s['durations']['tme']['rows'][1]['comparison']['eligible']
    && $s['volumes']['tickets']['rows'][1]['comparison']['eligible'], 'Changed telephone source breaks only dependent operational lines');
$scope = $feb; $scope['input']['test_numbers'] = [['number' => '6100000002', 'active' => true]];
$s = analyticsBuild([$jan, $scope]);
analyticsCheck(!$s['volumes']['calls']['rows'][1]['comparison']['eligible']
    && !$s['volumes']['excluded_calls']['rows'][1]['comparison']['eligible'], 'Applied test-exclusion changes break both included and excluded phone cohort comparisons');
$legacy = $feb; unset($legacy['source_identity']);
$l = analyticsBuild([$jan, $legacy]);
analyticsCheck(!$l['metrics']['INS1']['rows'][1]['eligible'] && !$l['volumes']['calls']['rows'][1]['eligible']
    && $l['metrics']['INS10']['rows'][1]['comparison']['eligible'], 'Unknown source provenance is not backfilled from current configuration and does not invalidate source-independent staff observations');
analyticsCheck($l['metrics']['INS1']['coverage']['target_met'] === 2 && $l['metrics']['INS1']['coverage']['target_evaluated'] === 2
    && $l['metrics']['INS1']['coverage']['eligible'] === 1 && $l['metrics']['INS1']['rows'][1]['target_evaluable'],
    'A finalized archived target assessment remains counted even when cross-month provenance is unknown');
analyticsCheck($l['matrix'][0]['cells'][1]['target_evaluable'] && !$l['matrix'][0]['cells'][1]['eligible'],
    'The dashboard matrix distinguishes known monthly attainment from unavailable cross-month comparison');
analyticsCheck($l['financial']['trends']['net']['rows'][1]['comparison']['eligible']
    && $s['financial']['trends']['net']['rows'][1]['comparison']['eligible'], 'Complete observed financial changes remain visible despite changed or unknown KPI source context');

$target = $feb; $target['metrics'][0]['target'] = 95; $target['metrics'][0]['status'] = 'unmet';
$t = analyticsBuild([$jan, $target]);
analyticsCheck(!$t['metrics']['INS1']['rows'][1]['comparison']['eligible'] && $t['metrics']['INS1']['rows'][1]['shortfall_pp'] === 3
    && $t['metrics']['INS1']['coverage']['target_met'] === 1 && $t['metrics']['INS1']['coverage']['target_evaluated'] === 2, 'Changed targets split direct comparisons while preserving each monthly target assessment');
analyticsCheck(!analyticsEdges($t['metrics']['INS1'], 'target'), 'Target lines split at a changed criterion instead of implying one unchanged threshold');
$lower = $feb; $lower['metrics'][4]['value'] = 12; $lower['metrics'][4]['numerator'] = 12; $lower['metrics'][4]['status'] = 'unmet';
$down = analyticsBuild([$jan, $lower]);
analyticsCheck($down['metrics']['INS5']['rows'][1]['shortfall_pp'] === 2
    && $down['metrics']['INS5']['rows'][1]['comparison']['direction'] === 'declined', 'A lower-is-better metric above its maximum has a positive shortfall and deterioration');
$flatA = $jan; $flatB = $feb;
foreach (['flatA', 'flatB'] as $name) { $$name['metrics'][0]['value'] = 85; $$name['metrics'][0]['numerator'] = 85; $$name['metrics'][0]['status'] = 'unmet'; }
$flat = analyticsBuild([$flatA, $flatB]);
analyticsCheck(count(array_filter($flat['findings'], static fn($finding) => $finding['kind'] === 'target'
    && $finding['months'] === ['2026-02'] && str_contains($finding['text'], '5 p.p. aquém'))) === 1,
    'An unchanged under-target result still produces a factual management finding');
$boundary = $feb; $boundary['metrics'][0]['value'] = 89.9999; $boundary['metrics'][0]['numerator'] = 89.9999;
$boundary['metrics'][0]['status'] = 'met';
$boundaryStart = $jan; $boundaryStart['metrics'][0]['value'] = 91; $boundaryStart['metrics'][0]['numerator'] = 91;
$border = analyticsBuild([$boundaryStart, $boundary]);
$found = array_values(array_filter($border['findings'], static fn($finding) => $finding['kind'] === 'metric_declined'));
analyticsCheck(count($found) === 1 && str_contains($found[0]['text'], 'atendeu à meta arquivada')
    && !str_contains($found[0]['text'], 'aquém'), 'A stored met status remains authoritative when its numeric margin disagrees at a rounded boundary');
$boundary['metrics'][0]['status'] = 'unmet';
$border = analyticsBuild([$jan, $boundary]);
$found = array_values(array_filter($border['findings'], static fn($finding) => $finding['kind'] === 'target'));
analyticsCheck(count($found) === 1 && str_contains($found[0]['text'], 'fora da meta arquivada')
    && !str_contains($found[0]['text'], '0,00 p.p. aquém') && $border['metrics']['INS1']['rows'][1]['value'] === 89.9999,
    'An unmet boundary is described by its archived status, not a misleading rounded zero shortfall; its value remains unchanged');
analyticsCheck(!array_filter($border['findings'], static fn($finding) => $finding['kind'] === 'metric_declined')
    && $border['metrics']['INS1']['rows'][1]['comparison']['delta_pp'] < 0,
    'Sub-display-resolution KPI changes remain exact in data without generating a misleading zero-change headline');

$largeA = $jan; $largeB = $feb;
$largeA['financial']['net_value'] = '99999999999999.98'; $largeB['financial']['net_value'] = '99999999999999.99';
$large = analyticsBuild([$largeA, $largeB]);
analyticsCheck($large['financial']['trends']['net']['rows'][1]['comparison']['delta_absolute'] === 1
    && $large['financial']['trends']['net']['chart']['series'][0]['values'][1] === 9999999999999999, 'Large monetary observations and single-cent changes remain exact integers in the shared model');
$incomplete = $feb; $incomplete['financial']['complete'] = false;
$f = analyticsBuild([$jan, $incomplete]);
analyticsCheck(!$f['financial']['trends']['net']['rows'][1]['eligible']
    && analyticsPoints($f['financial']['trends']['net'])[1] === 98001, 'Incomplete finances retain original amounts as isolated observations');

$record = analyticsRecord($jan); $daily = ['schema_version' => 1, 'status' => 'partial', 'series' => ['tickets' => ['available' => true, 'reconciled' => false]],
    'days' => [['date' => '2026-01-01', 'tickets' => 0, 'calls' => null]], 'notes' => ['Cobertura diária parcial']];
$record['summary']['activity'] = $daily;
$d = AnnualAnalytics::build(AnnualReport::build([$record], ['2026-01']));
analyticsCheck($d['activity'][0]['activity'] === $daily && $d['activity'][0]['activity']['days'][0]['calls'] === null,
    'Partial daily coverage and null/zero distinctions are passed through without reconstruction or source reads');
analyticsCheck($a['activity'][0]['activity'] === null, 'Legacy missing daily projection stays explicitly unavailable');

foreach ([[], ['schema_version' => 2, 'months' => [[]]], ['schema_version' => 1, 'months' => [['month' => '2026-02'], ['month' => '2026-01']]]] as $invalid) {
    try { AnnualAnalytics::build($invalid); analyticsCheck(false, 'Invalid analytical input accepted'); }
    catch (InvalidArgumentException $e) { analyticsCheck(true, 'Invalid schema or chronology is rejected'); }
}

echo "Annual analytics checks passed: $checks assertions.\n";
