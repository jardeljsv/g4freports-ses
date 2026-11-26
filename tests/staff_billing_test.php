<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\StaffRegister;

$checks = 0;
function billingCheck(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/staff_billing_cases.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
    $before = $case['row'];
    $result = StaffRegister::billingForMonth($case['row'], $case['month']);
    foreach ($case['expected'] as $field => $expected) {
        billingCheck($result[$field] === $expected, $case['label'] . ': ' . $field . ' = ' . var_export($result[$field], true));
    }
    billingCheck($before === $case['row'], 'Never mutate entered values');
}
$rows = [
    ['id'=>'older','name'=>'Older active','profile'=>'Técnico','admission_date'=>'2025-01-01','departure_date'=>'','monthly_value'=>30],
    ['id'=>'june','name'=>'June departure','profile'=>'Técnico','admission_date'=>'2026-01-01','departure_date'=>'2026-06-02','monthly_value'=>30],
    ['id'=>'may','name'=>'May departure','profile'=>'Técnico','admission_date'=>'2026-01-01','departure_date'=>'2026-05-02','monthly_value'=>30],
    ['id'=>'march','name'=>'March departure','profile'=>'Técnico','admission_date'=>'2026-01-01','departure_date'=>'2026-03-02','monthly_value'=>30],
    ['id'=>'excluded','name'=>'Excluded','profile'=>'Técnico','admission_date'=>'2026-01-01','departure_date'=>'2026-06-02','monthly_value'=>30,'billed'=>true],
];
$saved = StaffRegister::rows($rows);
$june = StaffRegister::summarize($saved, '2026-06');
billingCheck([$june['numerator'],$june['denominator'],$june['value']] === [2,2,100], 'INS10 includes proportional June departures in the billed headcount');
billingCheck($june['billed_days_total'] === 32 && $june['billed_value_total'] === '32.00', 'Sum each included professional once, including June departure');
billingCheck($june['monthly_value_total'] === '30.00', 'Nominal month-end salary total remains separate');
billingCheck($june['billing_professionals_total'] === 2, 'Two professionals overlap selected month');
billingCheck(StaffRegister::rows($june['rows']) === $saved, 'Derived fields do not pollute saved register or invert flags');
$feb = StaffRegister::summarize($saved, '2026-02');
billingCheck($feb['active_total'] === 4 && $feb['departures_total'] === 0, 'Future departures preserve historical headcount');
billingCheck($feb['billed_days_total'] === 112 && $feb['billed_value_total'] === '120.00', 'All historical active staff bill full February');
$again = StaffRegister::summarize($saved, '2026-06');
billingCheck($again === $june, 'Switching months does not retain derived state');
// Billing edits are month-specific, durable and leave source dates/nominal values intact.
$manual = $rows[0];
$manual['billing_days_overrides'] = ['2026-07' => 0, '2026-06' => '22', '2026-05' => '', '2026-04' => null];
$manual['service_front'] = 'Suporte ao usuário';
$normalized = StaffRegister::rows([$manual])[0];
billingCheck($normalized['billing_days_overrides'] === ['2026-06' => 22, '2026-07' => 0], 'Normalize sorted month keys and preserve explicit zero');
billingCheck($normalized['service_front'] === 'Suporte ao usuário', 'Explicit service front persists');
billingCheck(StaffRegister::rows(StaffRegister::json([$normalized])) === [$normalized], 'Save/reload preserves monthly overrides exactly');
$calculated = StaffRegister::billingForMonth($normalized, '2026-06');
billingCheck($calculated['automatic_billed_days'] === 30 && $calculated['billed_days_override'] === 22 && $calculated['billed_days'] === 22, 'Automatic and effective days remain distinguishable');
billingCheck($calculated['billing_first_day'] === '2026-06-01' && $calculated['billing_last_day'] === '2026-06-30', 'Manual attendance does not invent different employment dates');
billingCheck($calculated['billed_value'] === '22.00' && $normalized['monthly_value'] === '30.00', 'Proration uses effective days and leaves monthly rate intact');
billingCheck(StaffRegister::summarize([$normalized], '2026-07')['denominator'] === 0, 'An explicit zero-day adjustment removes only that month from billed headcount');
billingCheck(StaffRegister::summarize([$normalized], '2026-08')['denominator'] === 1, 'An override does not leak to a later month');
$cleared = $normalized;
$cleared['billing_days_overrides']['2026-06'] = '';
billingCheck(StaffRegister::billingForMonth(StaffRegister::rows([$cleared])[0], '2026-06')['billed_days'] === 30, 'Clearing manual days restores automatic days');
foreach ([['2026-06' => 31], ['2026-06' => -1], ['2026-06' => 1.5], ['2026-06' => true], ['2026-06' => '1e1'], ['2026-13' => 1], ['2026-06' => []]] as $invalid) {
    $candidate = $manual; $candidate['billing_days_overrides'] = $invalid;
    $rejected = false;
    try { StaffRegister::rows([$candidate]); } catch (InvalidArgumentException $e) { $rejected = true; }
    billingCheck($rejected, 'Invalid or out-of-period billing-day override is rejected');
}
$changedDate = $normalized;
$changedDate['departure_date'] = '2026-06-02';
$rejected = false;
try { StaffRegister::rows([$changedDate]); } catch (InvalidArgumentException $e) {
    $rejected = strpos($e->getMessage(), '2026-06') !== false && strpos($e->getMessage(), '0 a 2') !== false;
}
billingCheck($rejected, 'Changing dates cannot silently discard or overbill an existing monthly override');
$zeroValue = $normalized; $zeroValue['monthly_value'] = 0;
billingCheck(StaffRegister::summarize([$zeroValue], '2026-06')['denominator'] === 1, 'A zero monetary rate never removes a professional with billed days');
$excluded = $normalized; $excluded['not_billed'] = true;
$excludedSummary = StaffRegister::summarize([$excluded], '2026-06');
billingCheck($excludedSummary['rows'][0]['relevant_month'] && !$excludedSummary['rows'][0]['counted_billed_month'], 'Nonbilled overlap remains in monthly presentation but not metric denominator');
billingCheck($excludedSummary['rows'][0]['billed_days'] === 0 && $excludedSummary['rows'][0]['billed_value'] === '0.00', 'Explicit exclusion wins over saved manual days');

echo "Staff billing checks passed: {$checks} assertions.\n";
