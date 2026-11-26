<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Ses/StaffRegister.php';
require dirname(__DIR__) . '/src/Ses/Calculator.php';

use GlpiPlugin\G4freports\Ses\StaffRegister as Staff;

$assertions = 0;
function verify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function employee(string $id, array $changes = []): array
{
    return array_replace([
        'id' => $id, 'name' => 'Profissional ' . $id, 'profile' => 'Analista',
        'admission_date' => '2025-01-01', 'departure_date' => null,
        'departure_reason' => 'pending', 'monthly_value' => '1000.00',
    ], $changes);
}

foreach ([true, 1, '1'] as $value) {
    verify(Staff::isNotBilled(['billed' => $value]), 'Legacy checked mark must exclude, without inversion.');
    verify(Staff::isNotBilled(['not_billed' => $value]), 'Canonical checked mark must exclude.');
}
foreach ([false, 0, '0'] as $value) {
    verify(!Staff::isNotBilled(['billed' => $value]), 'Legacy unchecked mark must include.');
    verify(!Staff::isNotBilled(['not_billed' => $value]), 'Canonical unchecked mark must include.');
}
verify(!Staff::isNotBilled([]), 'Missing flag includes the record.');
verify(!Staff::isNotBilled(['billed' => true, 'not_billed' => false]), 'Canonical false wins over legacy true.');
verify(Staff::isNotBilled(['billed' => false, 'not_billed' => true]), 'Canonical true wins over legacy false.');
verify(!Staff::isNotBilled(['billed' => 'invalid ignored legacy', 'not_billed' => false]), 'Unused legacy field cannot override canonical field.');
foreach ([null, '', 'false', 'true', 2, -1, 0.0, [], new stdClass()] as $value) {
    foreach (['billed', 'not_billed'] as $key) {
        $rejected = false;
        try { Staff::isNotBilled([$key => $value]); }
        catch (InvalidArgumentException $expected) { $rejected = true; }
        verify($rejected, 'Ambiguous or invalid exclusion flag must not be cast to a boolean.');
    }
}

$legacy = [
    employee('checked', ['billed' => true]),
    employee('unchecked', ['billed' => false]),
    employee('missing'),
    employee('explicit', ['billed' => true, 'not_billed' => false]),
];
$normalized = Staff::rows($legacy);
$byId = array_column($normalized, null, 'id');
verify($byId['checked']['not_billed'] === true, 'Normalization preserves the original checked exclusion.');
verify($byId['unchecked']['not_billed'] === false && $byId['missing']['not_billed'] === false, 'Normalization includes unchecked and pre-flag records.');
verify($byId['explicit']['not_billed'] === false, 'Canonical precedence persists through normalization.');
foreach ($normalized as $row) verify(!array_key_exists('billed', $row), 'Normalized output must contain only canonical exclusion field.');
verify(Staff::rows($normalized) === $normalized, 'Normalization is idempotent.');
$roundTrip = $normalized;
for ($i = 0; $i < 5; $i++) {
    $roundTrip = Staff::rows(Staff::json($roundTrip));
    verify($roundTrip === $normalized, 'Repeated save/reload must not flip marks.');
}

// Synthetic regression case: checked, unclassified departures were wrongly
// treated as eligible; the historical unchecked active team was wrongly omitted.
$reported = [];
for ($i = 1; $i <= 80; $i++) $reported[] = employee('active' . $i, ['billed' => false]);
for ($i = 1; $i <= 9; $i++) $reported[] = employee('excluded' . $i, [
    'billed' => true, 'departure_date' => '2026-06-08', 'monthly_value' => '9999.00',
]);
$reported[] = employee('old', ['billed' => false, 'departure_date' => '2026-02-01']);
$reported[] = employee('old2', ['billed' => false, 'departure_date' => '2026-03-31']);
$june = Staff::summarize($reported, '2026-06');
verify(count($june['rows']) === 91, 'Historical roster retains every record.');
verify($june['active_total'] === 80 && $june['denominator'] === 80, 'All 80 unchecked active professionals count.');
verify($june['unbilled_total'] === 9 && $june['billed_total'] === 82, 'Eligibility summaries preserve original marks.');
verify($june['pending_departures'] === 0 && $june['pending_ids'] === [], 'Excluded pending departures cannot block INS10.');
verify($june['replacements'] === 0 && $june['numerator'] === 0 && $june['value'] === 0, 'No date-eligible departures yields zero turnover.');
verify($june['complete'] && $june['monthly_value_total'] === '80000.00', 'Eligible active value and completeness calculated independently.');
$savedReported = Staff::rows(Staff::json(Staff::rows($reported)));
verify(Staff::summarize($savedReported, '2026-06') === $june, 'Saved canonical register gives exactly the same report.');
foreach ($june['rows'] as $row) if ($row['not_billed']) {
    verify($row['relevant_month'] && !$row['counted_billed_month'] && !$row['active_at_month_end'] && !$row['departure_in_window'] && !$row['counted_replacement'], 'Excluded employment is visible for the overlapping month without INS10 contribution flags.');
}

$historical = [
    employee('ongoing', ['not_billed' => false]),
    employee('leaves-july', ['not_billed' => false, 'departure_date' => '2026-07-15', 'departure_reason' => 'replacement']),
    employee('future-join', ['not_billed' => false, 'admission_date' => '2026-08-01']),
    employee('old-exit', ['not_billed' => false, 'departure_date' => '2026-03-31']),
    employee('excluded-active', ['not_billed' => true]),
    employee('excluded-replacement', ['not_billed' => true, 'departure_date' => '2026-06-15', 'departure_reason' => 'replacement']),
    employee('excluded-reduction', ['not_billed' => true, 'departure_date' => '2026-06-15', 'departure_reason' => 'contractor_reduction']),
    employee('excluded-other', ['not_billed' => true, 'departure_date' => '2026-06-15', 'departure_reason' => 'other']),
];
$june = Staff::summarize($historical, '2026-06');
verify($june['active_total'] === 2 && $june['replacements'] === 0 && $june['pending_departures'] === 0, 'June uses historical dates, excludes future joins and ignores old exits.');
verify($june['reductions'] === 0 && $june['other_departures'] === 0, 'Excluded departures never enter classification subtotals.');
verify($june['value'] === 0 && $june['monthly_value_total'] === '2000.00', 'June zero-turnover percentage and value remain valid.');
$july = Staff::summarize($historical, '2026-07');
verify($july['active_total'] === 1 && $july['replacements'] === 1 && $july['value'] === 50 && $july['denominator'] === 2, 'July counts both professionals billed, including its partial-month exit.');
$august = Staff::summarize($historical, '2026-08');
verify($august['active_total'] === 2 && $august['replacements'] === 1 && $august['value'] === 50, 'August includes the later admission and its selected three-month window.');
$october = Staff::summarize($historical, '2026-10');
verify($october['active_total'] === 2 && $october['replacements'] === 0 && $october['value'] === 0, 'An exit outside the selected three-month window no longer counts.');

$withPending = $historical;
$withPending[] = employee('eligible-pending', ['not_billed' => false, 'departure_date' => '2026-06-10']);
$pending = Staff::summarize($withPending, '2026-06');
verify($pending['complete'] && $pending['pending_departures'] === 0 && $pending['pending_ids'] === [], 'An unspecified analytical reason cannot block INS10.');
verify($pending['active_total'] === 2 && $pending['denominator'] === 3 && $pending['monthly_value_total'] === '2000.00', 'Optional description does not suppress the independent active headcount and value.');
verify($pending['departures_total'] === 1 && $pending['numerator'] === 1 && abs($pending['value'] - 100 / 3) < 0.000001, 'An eligible unclassified exit counts solely by date.');
verify($pending['unclassified_departures'] === 1 && $pending['unclassified_ids'] === ['eligible-pending'], 'Unspecified reasons remain visible as an analytical subset.');
verify(Staff::departureReasonLabel(null) === 'Motivo não informado' && Staff::departureReasonLabel('pending') === 'Motivo não informado', 'Legacy pending and absent reasons use nonblocking wording.');

// Reproduce the supplied roster's quantities without private professional data.
$reported[89]['departure_date'] = '2026-05-01';
$reported[90]['departure_date'] = '2026-06-03';
$validated = Staff::summarize($reported, '2026-06');
verify($validated['active_total'] === 80 && $validated['unbilled_total'] === 9 && $validated['departures_total'] === 2, 'Corrected June shape identifies 80 active, nine excluded, two included exits.');
verify($validated['complete'] && abs($validated['value'] - 200 / 81) < 0.000001 && $validated['pending_departures'] === 0, 'Two exits with optional reasons use all 81 billed professionals, not pending.');
$baseline = null;
foreach (['pending', 'replacement', 'contractor_reduction', 'other', '', null] as $reason) {
    $variants = $reported;
    $variants[89]['departure_reason'] = $reason;
    $variants[90]['departure_reason'] = $reason;
    $summary = Staff::summarize($variants, '2026-06');
    $operational = array_intersect_key($summary, array_flip(['active_total','departures_total','numerator','denominator','value','monthly_value_total','complete']));
    if ($baseline === null) $baseline = $operational;
    verify($operational === $baseline, 'Changing any optional departure reason leaves all operational totals identical.');
    verify(count($summary['departure_ids']) === 2 && $summary['replacement_ids'] === $summary['departure_ids'], 'Compatibility aliases count all date-eligible exits.');
    verify($summary['classified_replacements'] + $summary['reductions'] + $summary['other_departures'] + $summary['unclassified_departures'] === 2, 'Analytical subsets partition the included exits without changing the total.');
}
$missingReasons = $reported;
unset($missingReasons[89]['departure_reason'], $missingReasons[90]['departure_reason']);
verify(abs(Staff::summarize($missingReasons, '2026-06')['value'] - 200 / 81) < 0.000001, 'Omitted departure descriptions count normally.');

// June's date window includes April 1 and June 30; the departure date itself
// remains the last active day, matching historical reports.
$boundary = Staff::summarize([
    employee('ongoing'), employee('before', ['departure_date' => '2026-03-31']),
    employee('first', ['departure_date' => '2026-04-01']),
    employee('last', ['departure_date' => '2026-06-30']),
    employee('after', ['departure_date' => '2026-07-01']),
    employee('future', ['admission_date' => '2026-07-01']),
    employee('no-date', ['departure_reason' => 'other']),
], '2026-06');
verify($boundary['departure_ids'] === ['first', 'last'], 'Three-month window boundaries are inclusive and exclude neighboring days.');
verify($boundary['active_total'] === 4 && $boundary['value'] === 50, 'Month-end exit, later exit and no departure date remain active under last-active-day convention.');
$boundaryById = array_column($boundary['rows'], null, 'id');
verify($boundaryById['last']['counted_departure'] && $boundaryById['last']['active_at_month_end'], 'An exit on month end is both active at close and counted in the window.');
verify($boundaryById['no-date']['departure_reason'] === 'other' && !$boundaryById['no-date']['counted_departure'], 'Optional reason is retained but absence of a departure date keeps the employment active.');

$empty = Staff::summarize([], '2026-06');
verify(!$empty['configured'] && !$empty['complete'] && $empty['numerator'] === null && $empty['denominator'] === null, 'An empty register still communicates absence of roster data.');
$partialOnly = Staff::summarize([employee('left', ['departure_date' => '2026-06-10'])], '2026-06');
verify($partialOnly['active_total'] === 0 && $partialOnly['denominator'] === 1 && $partialOnly['value'] === 100, 'A partial-month leaver counts even when no employee remains at month end.');
$zeroActive = Staff::summarize([employee('left', ['departure_date' => '2026-05-10'])], '2026-06');
verify($zeroActive['configured'] && $zeroActive['complete'] && $zeroActive['numerator'] === 1 && $zeroActive['denominator'] === 0 && $zeroActive['value'] === null, 'A configured zero-active roster is complete, with a zero denominator rather than classification pending.');
$onlyExcluded = Staff::summarize([employee('excluded', ['not_billed' => true])], '2026-06');
verify($onlyExcluded['complete'] && $onlyExcluded['numerator'] === 0 && $onlyExcluded['denominator'] === 0, 'A roster containing only exclusions also has a known zero denominator.');
$countMetric = new ReflectionMethod(\GlpiPlugin\G4freports\Ses\Calculator::class, 'countMetric');
$countMetric->setAccessible(true);
foreach ([$zeroActive, $onlyExcluded] as $summary) {
    $metric = $countMetric->invoke(null, 'INS10', 'Rotatividade', $summary['numerator'], $summary['denominator'], 10.0, 'lte', $summary['method'], $summary['note'], $summary['configured'] ? null : 'pending');
    verify($metric['status'] === 'not_applicable', 'Calculator treats a known zero denominator as not applicable, not pending.');
}
$metric = $countMetric->invoke(null, 'INS10', 'Rotatividade', $validated['numerator'], $validated['denominator'], 10.0, 'lte', $validated['method'], $validated['note'], $validated['configured'] ? null : 'pending');
verify($metric['status'] === 'met' && abs($metric['value'] - 200 / 81) < 0.000001, 'Calculator uses the monthly billed denominator and target attained.');

// Revised validated month: 67 at month end plus two proportional departures = 69 billed.
$reference = [];
for ($i = 0; $i < 67; $i++) $reference[] = employee('ref-active-' . $i);
for ($i = 0; $i < 2; $i++) $reference[] = employee('ref-june-' . $i, ['departure_date' => '2026-06-02']);
for ($i = 0; $i < 6; $i++) $reference[] = employee('ref-prior-' . $i, ['departure_date' => $i < 3 ? '2026-04-15' : '2026-05-15']);
for ($i = 0; $i < 13; $i++) $reference[] = employee('ref-excluded-' . $i, ['not_billed' => true]);
for ($i = 0; $i < 2; $i++) $reference[] = employee('ref-old-' . $i, ['departure_date' => '2026-03-31']);
$referenceSummary = Staff::summarize($reference, '2026-06');
verify($referenceSummary['active_total'] === 67 && $referenceSummary['denominator'] === 69 && $referenceSummary['numerator'] === 8, 'Validated June shape: eight exits divided by 69 billed, not 67 at month end.');
verify(count(array_filter($referenceSummary['rows'], static function ($r) { return $r['relevant_month']; })) === 82, 'Monthly roster has 69 billed plus 13 excluded overlapping professionals, keeping old exits out.');
verify(count($referenceSummary['billing_professional_ids']) === 69 && abs($referenceSummary['value'] - 800 / 69) < 0.000001, 'Auditable billed identities and percentage reconcile.');

echo "Staff exclusion checks passed: {$assertions} assertions; original marks preserved, optional analytical reasons, date-based counting, historical boundaries and zero-denominator behavior.\n";
