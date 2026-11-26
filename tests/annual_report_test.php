<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\AnnualReport;
use GlpiPlugin\G4freports\Ses\MonthlySummary;
use GlpiPlugin\G4freports\Ses\Settings;
use GlpiPlugin\G4freports\Ses\Calculator;

$checks = 0;
function annualCheck(bool $condition, string $message): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function annualReject(callable $call, string $message): void
{
    try { $call(); } catch (InvalidArgumentException $e) { annualCheck(true, $message); return; }
    annualCheck(false, $message);
}
function annualSnapshot(string $month, int $step = 0): array
{
    $input = Settings::normalizeInput(['month' => $month, 'entity_id' => 1, 'group_ids' => [7, 4]], Settings::defaults());
    $metrics = [];
    for ($i = 1; $i <= 12; $i++) {
        $id = 'INS' . $i; $lower = in_array($i, [5, 9, 10], true);
        $metrics[] = ['id' => $id, 'label' => 'Rótulo congelado ' . $id, 'status' => 'met',
            'value' => $lower ? 8 - $step : 90 + $step, 'target' => $lower ? 10 : 90,
            'numerator' => $lower ? 8 - $step : 90 + $step, 'denominator' => 100,
            'direction' => $lower ? 'lte' : 'gte', 'method' => 'Método da extração', 'note' => 'Nota preservada',
            'evidence_ids' => ['EVIDENCE_SENTINEL'], 'denominator_ids' => ['DENOMINATOR_SENTINEL']];
    }
    return ['schema_version' => 2, 'rules_version' => Settings::RULES_VERSION,
        'source_identity' => ['glpi' => str_repeat('a', 64), 'callcenter' => str_repeat('b', 64)],
        'generated_at' => str_replace(' ', 'T', $input['end_exclusive']) . '-03:00', 'input' => $input,
        'period' => ['month' => $month, 'start' => $input['start'], 'end_exclusive' => $input['end_exclusive'],
            'label' => $month, 'date_basis' => 'solvedate', 'call_date_basis' => 'queue_entry'],
        'metrics' => $metrics, 'totals' => ['tickets' => 100, 'mean_talk_seconds' => 123.75,
            'mean_talk_count' => 80, 'unknown' => 'DO_NOT_COPY'],
        'manual' => ['executive_summary' => 'Resumo mensal', 'actions' => 'Ação registrada', 'notes' => 'Observação',
            'phone_survey_actions' => 'Providência', 'unknown' => 'DO_NOT_COPY'],
        'financial' => ['available' => true, 'complete' => true, 'gross_value' => '1000.01',
            'deduction_total' => '20.02', 'adjustment_total' => '-0.03', 'net_value' => '979.96',
            'notes' => ['Nota financeira'], 'roster' => [['id' => 'ROSTER_SENTINEL']]],
        'staff_register' => ['configured' => true, 'complete' => true, 'active_total' => 5, 'denominator' => 5,
            'rows' => [['name' => 'STAFF_SENTINEL']]],
        'warnings' => ['Aviso mensal'], 'rule_notes' => ['Regra aplicada'],
        'tickets' => [['id' => 'TICKET_SENTINEL']], 'calls' => [['number' => 'CALL_SENTINEL']]];
}
function annualRecord(array $snapshot, string $id = ''): array
{
    $summary = MonthlySummary::fromSnapshot($snapshot);
    return ['id' => $id !== '' ? $id : 'archive-' . $summary['month'], 'month' => $summary['month'],
        'archived_at' => '2026-10-01T09:00:00-03:00', 'summary' => $summary];
}
function annualFingerprint(array $snapshot, string $id): string
{
    return MonthlySummary::fromSnapshot($snapshot)['metrics'][$id]['comparability']['fingerprint'];
}

$snapshot = annualSnapshot('2026-01'); $unchanged = $snapshot;
$snapshot['metrics'][0]['value'] = 100 * 2 / 3;
$summary = MonthlySummary::fromSnapshot($snapshot);
annualCheck($snapshot['metrics'][0]['value'] === $summary['metrics']['INS1']['value']
    && $summary['metrics']['INS1']['raw_ratio'] === $snapshot['metrics'][0]['value'], 'Unrounded stored percentages are copied, never recalculated');
annualCheck($summary['metrics']['INS1']['numerator'] === 90, 'A stored numerator is preserved even when it does not reconstruct the stored value');
annualCheck(count($summary['metrics']) === 12 && $summary['metrics']['INS1']['label'] === 'Rótulo congelado INS1'
    && str_contains($summary['metrics']['INS1']['title'], 'Severidade 1'), 'IDs, original labels and presentation titles are frozen');
annualCheck($summary['manual'] === array_diff_key($snapshot['manual'], ['unknown' => true])
    && $summary['warnings'] === $snapshot['warnings'] && $summary['rule_notes'] === $snapshot['rule_notes'], 'Narratives, warnings and rule notes survive');
annualCheck($summary['totals'] === ['tickets' => 100, 'mean_talk_seconds' => 123.75, 'mean_talk_count' => 80]
    && $summary['staffing']['active_total'] === 5, 'Compact scalar totals and staffing counts survive');
$encoded = json_encode($summary, JSON_THROW_ON_ERROR);
foreach (['EVIDENCE_SENTINEL', 'DENOMINATOR_SENTINEL', 'ROSTER_SENTINEL', 'STAFF_SENTINEL', 'TICKET_SENTINEL', 'CALL_SENTINEL', 'DO_NOT_COPY'] as $secret) {
    annualCheck(!str_contains($encoded, $secret), 'Detailed evidence is absent: ' . $secret);
}
annualCheck($summary['financial']['gross_cents'] === 100001 && $summary['financial']['deduction_cents'] === 2002
    && $summary['financial']['adjustment_cents'] === -3 && $summary['financial']['net_cents'] === 97996, 'Exact signed money is projected to integer cents');
annualCheck($summary['source_identity']['status'] === 'known' && $summary['period']['completeness'] === 'complete', 'Source provenance and closed period are frozen');
annualCheck(str_contains($summary['scope_label'], 'Entidade 1') && str_contains($summary['scope_label'], '4, 7'), 'Revision selection has a compact scope label');
annualCheck($unchanged === annualSnapshot('2026-01'), 'Source fixture and current settings remain unchanged');
$inputNotes = $snapshot; unset($inputNotes['manual']['phone_survey_actions']);
$inputNotes['input']['phone_survey_actions'] = 'Providência registrada no formulário';
annualCheck(MonthlySummary::fromSnapshot($inputNotes)['manual']['phone_survey_actions'] === 'Providência registrada no formulário',
    'Original snapshot input preserves phone survey actions when the manual projection is absent');

$base = annualSnapshot('2026-01'); $reordered = $base;
$reordered['input']['group_ids'] = ['7', 4, 7]; $reordered['input']['n3_group_ids'] = array_reverse($base['input']['n3_group_ids']);
foreach (['INS1', 'INS3', 'INS4', 'INS5', 'INS6'] as $id) annualCheck(annualFingerprint($base, $id) === annualFingerprint($reordered, $id), 'Set ordering and duplicate IDs do not invalidate ' . $id);
$changed = $base; $changed['input']['priority_map']['INS1'] = 2;
annualCheck(annualFingerprint($base, 'INS1') !== annualFingerprint($changed, 'INS1')
    && annualFingerprint($base, 'INS2') === annualFingerprint($changed, 'INS2'), 'Priority mapping affects only its own severity indicator');
annualCheck(str_contains(MonthlySummary::fromSnapshot($changed)['metrics']['INS1']['title'], 'Severidade 4'), 'Frozen severity title follows the selected priority');
$changed = $base; $changed['input']['n3_group_ids'][] = 9000;
foreach (['INS3', 'INS4', 'INS5', 'INS6'] as $id) annualCheck(annualFingerprint($base, $id) !== annualFingerprint($changed, $id), 'Relevant N3 eligibility changes invalidate ' . $id);
foreach (['INS1', 'INS2', 'INS7', 'INS10', 'INS11', 'INS12'] as $id) annualCheck(annualFingerprint($base, $id) === annualFingerprint($changed, $id), 'Unrelated N3 configuration does not invalidate ' . $id);
$changed = $base; $changed['input']['staff_register'] = [['id' => 'new-person', 'monthly_value' => '2000.99']];
$changed['input']['financial_config'] = ['months' => ['2026-01' => ['changed' => true]]];
$changed['staff_register']['denominator'] = 10;
annualCheck(annualFingerprint($base, 'INS10') === annualFingerprint($changed, 'INS10'), 'Normal roster, denominator and rate changes are valid staff trend observations');
$changed = $base; $changed['input']['validated_kb_ids'] = [30, 20];
annualCheck(annualFingerprint($base, 'INS11') !== annualFingerprint($changed, 'INS11')
    && annualFingerprint($base, 'INS12') === annualFingerprint($changed, 'INS12'), 'Validated article criteria only affect INS11');
$baseProxy = $base; $baseProxy['input']['kb_mode'] = 'category_proxy';
$changed['input']['kb_mode'] = 'category_proxy';
annualCheck(annualFingerprint($baseProxy, 'INS11') === annualFingerprint($changed, 'INS11'), 'Unused validated article list does not affect category proxy mode');
$changed = $base; $changed['input']['test_numbers'] = [['number' => '+55 (61) 1234-5678', 'label' => 'Teste', 'active' => true]];
foreach (['INS7', 'INS8', 'INS9', 'INS12'] as $id) annualCheck(annualFingerprint($base, $id) !== annualFingerprint($changed, $id), 'Active test exclusions affect phone indicator ' . $id);
annualCheck(annualFingerprint($base, 'INS1') === annualFingerprint($changed, 'INS1'), 'Phone exclusions do not affect GLPI indicators');
$sameNumbers = $changed; $sameNumbers['input']['test_numbers'][0]['number'] = '556112345678';
$sameNumbers['input']['test_numbers'][0]['label'] = 'Descrição alterada';
$sameNumbers['input']['test_numbers'][] = ['number' => '123', 'active' => false];
annualCheck(annualFingerprint($changed, 'INS7') === annualFingerprint($sameNumbers, 'INS7'), 'Labels, formatting and inactive tests do not change applied phone exclusion criteria');
annualCheck(!str_contains(json_encode(MonthlySummary::fromSnapshot($changed)), '556112345678'), 'Phone identifiers are represented only by a digest');
$changed = $base; $changed['source_identity']['callcenter'] = str_repeat('c', 64);
annualCheck(annualFingerprint($base, 'INS7') !== annualFingerprint($changed, 'INS7')
    && annualFingerprint($base, 'INS1') === annualFingerprint($changed, 'INS1'), 'A changed source only invalidates metrics using that source');
$baseGlpi = $base; $baseGlpi['input']['ins12_mode'] = 'glpi_only';
$changed['input']['ins12_mode'] = 'glpi_only';
annualCheck(annualFingerprint($baseGlpi, 'INS12') === annualFingerprint($changed, 'INS12'), 'GLPI-only satisfaction does not depend on callcenter identity');
$legacy = $base; unset($legacy['source_identity']); $old = MonthlySummary::fromSnapshot($legacy);
annualCheck($old['source_identity']['status'] === 'unknown' && !$old['metrics']['INS1']['comparability']['known']
    && !$old['metrics']['INS7']['comparability']['known'] && $old['metrics']['INS10']['comparability']['known'], 'Legacy provenance remains unknown; manual staff metric has no DB dependency');
$bad = $base; unset($bad['input']['n3_group_ids']);
annualCheck(!MonthlySummary::fromSnapshot($bad)['metrics']['INS3']['comparability']['known'], 'Missing applicable eligibility criteria prevent confident comparisons');

$partial = annualSnapshot('2026-02'); $partial['generated_at'] = '2026-02-10T23:00:00-03:00';
$partialSummary = MonthlySummary::fromSnapshot($partial);
annualCheck($partialSummary['period']['completeness'] === 'provisional' && $partialSummary['financial']['complete'], 'Period incompleteness is frozen separately from the original financial completeness');
$boundary = $base; $boundary['generated_at'] = '2026-02-01T02:59:59Z';
annualCheck(MonthlySummary::fromSnapshot($boundary)['period']['completeness'] === 'provisional', 'Closure comparison respects the archived local month timezone');
$boundary['generated_at'] = '2026-02-01T03:00:00Z';
annualCheck(MonthlySummary::fromSnapshot($boundary)['period']['completeness'] === 'complete', 'Exact exclusive end is a closed monthly period');
foreach (['2026-02-30T12:00:00-03:00', 'tomorrow', '2026-02-01T03:00:00-00:00', ''] as $date) {
    $bad = $base; $bad['generated_at'] = $date;
    annualCheck(MonthlySummary::fromSnapshot($bad)['period']['completeness'] === 'unknown', 'Malformed or ambiguous generation timestamp cannot prove closure');
}
$bad = $base; $bad['period']['end_exclusive'] = '2026-01-31 23:59:59';
annualCheck(MonthlySummary::fromSnapshot($bad)['period']['completeness'] === 'unknown', 'A partial or inconsistent period boundary is not treated as a full month');
$bad = $base; $bad['input']['timezone'] = 'invalid/timezone';
annualCheck(MonthlySummary::fromSnapshot($bad)['period']['completeness'] === 'unknown', 'Unrecorded or invalid timezone cannot prove closure');

$jan = annualRecord($base); $feb = annualRecord(annualSnapshot('2026-02', 3)); $mar = annualRecord(annualSnapshot('2026-03', 5));
$records = [$mar, $jan, $feb]; $recordsBefore = $records;
$report = AnnualReport::build($records, ['2026-03', '2026-01', '2026-02']);
annualCheck($records === $recordsBefore && array_column($report['months'], 'month') === ['2026-01', '2026-02', '2026-03'], 'Pinned summaries stay immutable and selected months sort chronologically');
annualCheck($report['metrics']['INS1']['months'][1]['comparison']['delta_pp'] === 3
    && $report['metrics']['INS1']['months'][1]['comparison']['direction'] === 'improved', 'Higher-is-better change uses whole percentage points');
foreach (['INS5', 'INS9', 'INS10'] as $id) annualCheck($report['metrics'][$id]['months'][1]['comparison']['delta_pp'] === -3
    && $report['metrics'][$id]['months'][1]['comparison']['direction'] === 'improved', 'Lower-is-better direction is frozen for ' . $id);
annualCheck($report['metrics']['INS1']['months'][0]['comparison']['delta_pp'] === null, 'First selected month has no invented baseline');
$decrease = annualRecord(annualSnapshot('2026-02', -2));
$declining = AnnualReport::build([$jan, $decrease], ['2026-01', '2026-02']);
annualCheck($declining['metrics']['INS1']['months'][1]['comparison']['direction'] === 'declined'
    && $declining['metrics']['INS10']['months'][1]['comparison']['direction'] === 'declined', 'Both directions correctly classify deterioration');
$equal = AnnualReport::build([$jan, annualRecord(annualSnapshot('2026-02'))], ['2026-01', '2026-02']);
annualCheck($equal['metrics']['INS1']['months'][1]['comparison']['direction'] === 'unchanged', 'Equal stored percentages are unchanged');
$gap = AnnualReport::build([$jan, $mar], ['2026-01', '2026-03']); $comparison = $gap['metrics']['INS1']['months'][1]['comparison'];
annualCheck($comparison['delta_pp'] === 5 && $comparison['gap_months'] === 1
    && str_contains($comparison['interval_label'], 'não selecionada'), 'Nonconsecutive selected months have an explicit interval, not an implied monthly change');
$missing = AnnualReport::build([$jan, $mar], ['2026-01', '2026-02', '2026-03']);
annualCheck($missing['coverage'] === ['requested' => 3, 'available' => 2, 'missing_months' => ['2026-02']]
    && $missing['metrics']['INS1']['months'][1]['value'] === null, 'A missing selected month is explicit, never zero');
annualCheck($missing['metrics']['INS1']['months'][2]['comparison']['previous_month'] === '2026-02'
    && $missing['metrics']['INS1']['months'][2]['comparison']['delta_pp'] === null, 'Comparisons never bridge a missing selected month');
foreach (['pending', 'provisional', 'not_applicable'] as $status) {
    $record = $feb; $record['summary']['metrics']['INS1']['status'] = $status;
    $r = AnnualReport::build([$jan, $record], ['2026-01', '2026-02']);
    annualCheck($r['metrics']['INS1']['months'][1]['status'] === $status
        && $r['metrics']['INS1']['months'][1]['value'] === 93 && $r['metrics']['INS1']['months'][1]['comparison']['delta_pp'] === null, 'Nonfinal metric status and value survive without improvement claims: ' . $status);
}
$unknown = annualSnapshot('2026-02', 3); unset($unknown['source_identity']);
$r = AnnualReport::build([$jan, annualRecord($unknown)], ['2026-01', '2026-02']);
annualCheck($r['metrics']['INS1']['months'][1]['comparison']['delta_pp'] === null
    && $r['metrics']['INS10']['months'][1]['comparison']['delta_pp'] === -3, 'Unknown legacy sources suppress only dependent source comparisons');
$changed = annualSnapshot('2026-02', 3); $changed['input']['entity_id'] = 9;
$r = AnnualReport::build([$jan, annualRecord($changed)], ['2026-01', '2026-02']);
annualCheck($r['metrics']['INS1']['months'][1]['comparison']['delta_pp'] === null
    && $r['metrics']['INS7']['months'][1]['comparison']['delta_pp'] === 3, 'Different GLPI entity scope does not invalidate the unchanged phone scope');
$r = AnnualReport::build([$jan, annualRecord($partial)], ['2026-01', '2026-02']);
annualCheck($r['metrics']['INS1']['months'][1]['status'] === 'met'
    && $r['metrics']['INS1']['months'][1]['comparison']['delta_pp'] === null
    && str_contains($r['metrics']['INS1']['months'][1]['comparison']['reason'], 'provisória'), 'A partial archived period never yields a confident improvement even with met metric status');
annualCheck($r['financial']['months'][1]['status'] === 'provisional'
    && $r['financial']['months'][1]['financial_complete'] && !$r['financial']['months'][1]['complete']
    && $r['financial']['months'][1]['net_cents'] === 97996 && $r['financial']['complete_months'] === ['2026-01'], 'Provisional finances retain original flags and cents, and are excluded with an explanation');

annualCheck($report['financial']['subtotal_cents'] === ['gross' => 300003, 'deduction' => 6006, 'adjustment' => -9, 'net' => 293988]
    && !$report['financial']['is_partial'], 'Annual financials are exact sums of complete monthly cents');
$incomplete = annualSnapshot('2026-02'); $incomplete['financial']['complete'] = false;
$r = AnnualReport::build([$jan, annualRecord($incomplete)], ['2026-01', '2026-02', '2026-03']);
annualCheck($r['financial']['subtotal_cents']['net'] === 97996 && $r['financial']['complete_months'] === ['2026-01']
    && $r['financial']['excluded_months'] === ['2026-02', '2026-03'] && $r['financial']['is_partial'], 'Incomplete and missing months are excluded from a labeled partial subtotal');
annualCheck($r['financial']['months'][1]['net_cents'] === 97996
    && str_contains($r['financial']['coverage_label'], '1 de 3'), 'Original incomplete financial values and coverage remain visible');
$empty = AnnualReport::build([], ['2026-01']);
annualCheck($empty['financial']['subtotal_cents'] === ['gross' => null, 'deduction' => null, 'adjustment' => null, 'net' => null]
    && $empty['months'][0]['status'] === 'missing', 'An empty archive selection never reports a meaningful zero financial total');
$large = $base; $large['financial']['gross_value'] = '99999999999999.99';
$largeRecord = annualRecord($large);
annualCheck($largeRecord['summary']['financial']['gross_cents'] === 9999999999999999, 'Cents exceeding binary floating-point integer precision remain exact');
foreach (['1.001', '1e5', 1.01, 'not-money'] as $money) {
    $invalid = $base; $invalid['financial']['gross_value'] = $money;
    $s = MonthlySummary::fromSnapshot($invalid);
    annualCheck(!$s['financial']['complete'] && $s['financial']['gross_cents'] === null, 'Unrecognized money cannot be rounded or silently included');
}
annualCheck(!isset($report['totals']) && !isset($report['metrics']['INS1']['annual_value'])
    && $report['months'][0]['summary']['totals']['mean_talk_seconds'] === 123.75, 'No annual KPI or average of monthly TMA values is invented');

foreach ([[], ['2026-01', '2026-01'], ['1999-12'], ['2101-01'], ['2026-13'], ['2026-1']] as $months) annualReject(fn() => AnnualReport::build([], $months), 'Invalid or duplicated selected month list is rejected');
$months = []; for ($i = 0; $i < 25; $i++) $months[] = (new DateTimeImmutable('2024-01-01'))->modify('+' . $i . ' months')->format('Y-m');
annualReject(fn() => AnnualReport::build([], $months), 'More than 24 selected months is rejected');
annualCheck(count(AnnualReport::build([], array_slice($months, 0, 24))['months']) === 24, 'The full bounded 24-month selection is supported');
annualReject(fn() => AnnualReport::build([$jan, $jan], ['2026-01']), 'Two revisions for the same month are never chosen implicitly');
$sameId = $feb; $sameId['id'] = $jan['id'];
annualReject(fn() => AnnualReport::build([$jan, $sameId], ['2026-01', '2026-02']), 'An archive ID cannot claim two months');
annualReject(fn() => AnnualReport::build([$jan], ['2026-02']), 'Records outside the selected interval are rejected');
$wrong = $jan; $wrong['summary']['month'] = '2026-02';
annualReject(fn() => AnnualReport::build([$wrong], ['2026-01']), 'Archive and summary month must agree');
$wrong = $jan; $wrong['summary']['schema_version'] = 2;
annualReject(fn() => AnnualReport::build([$wrong], ['2026-01']), 'Unknown summary schemas are not interpreted as current');
$duplicate = $base; $duplicate['metrics'][] = $duplicate['metrics'][0];
annualReject(fn() => MonthlySummary::fromSnapshot($duplicate), 'Duplicate metric IDs do not overwrite frozen data');

// Integration with the established monthly calculator: the projection requires no additional source or current settings.
$actualInput = Settings::normalizeInput(['month' => '2026-01'], Settings::defaults());
$actual = Calculator::build($actualInput, ['tickets' => [], 'calls' => []]);
$actual['source_identity'] = $base['source_identity']; $actual['generated_at'] = '2026-02-02T00:00:00-03:00';
$before = $actual; $projected = MonthlySummary::fromSnapshot($actual);
annualCheck($actual === $before && $projected['month'] === '2026-01' && count($projected['metrics']) === 12,
    'A real current monthly snapshot projects without mutating the original calculation');
foreach ($actual['metrics'] as $metric) annualCheck($projected['metrics'][$metric['id']]['value'] === $metric['value']
    && $projected['metrics'][$metric['id']]['status'] === $metric['status'], 'Real calculator value and status preserved for ' . $metric['id']);

echo "Annual report checks passed: $checks assertions.\n";
