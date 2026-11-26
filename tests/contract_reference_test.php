<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\FinanceSettings as Config;
use GlpiPlugin\G4freports\Ses\FinancialSummary as Finance;
use GlpiPlugin\G4freports\Ses\StaffRegister as Staff;

$checks = 0;
function referenceCheck($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); }

$default = Config::fromSettings([]);
referenceCheck(count($default['contract_fronts']) === 7, 'First use loads the seven service fronts');
referenceCheck(array_sum(array_map(static function ($front) { return count($front['profile_lines']); }, $default['contract_fronts'])) === 14, 'Fourteen separately priced profiles in service order');
referenceCheck(array_sum(array_column($default['contract_fronts'], 'contract_quantity')) === 83, 'Reference has 83 contracted professionals');
referenceCheck($default['contract_total'] === '562457.91', 'Customer approved contractual total is exact');
referenceCheck(Config::normalize(Config::json($default)) === $default, 'Profile lines and approved total round trip');
referenceCheck(Config::normalize([]) === ['contract_fronts' => [], 'months' => []], 'Pure normalization does not inject baseline');
referenceCheck(Config::fromSettings([Config::CONFIG_KEY => ['contract_fronts' => [], 'contract_total' => null]]) ===
    ['contract_fronts' => [], 'months' => [], 'contract_total' => null], 'Explicit empty reference is retained');
referenceCheck(Config::normalize(['contract_total' => ''])['contract_total'] === null, 'Blank explicit total means use computed amount');
referenceCheck(Config::normalize(['contract_total' => 'R$ 562.457,91'])['contract_total'] === '562457.91', 'Brazilian explicit total retains cents');

$month = ['deduction_overrides' => ['INS3' => ['rate' => 0, 'justification' => 'Decisão registrada pela gestão']],
    'adjustments' => [['description' => 'Ajuste existente', 'amount' => '12.34']]];
$seeded = Config::fromSettings([Config::CONFIG_KEY => Config::json(Config::normalize(['months' => ['2026-06' => $month]]))]);
referenceCheck($seeded['months']['2026-06'] === Config::normalize(['months' => ['2026-06' => $month]])['months']['2026-06'], 'Initial reference preserves existing monthly decisions');

$legacy = Config::normalize(['contract_fronts' => [
    ['id' => 'custom', 'label' => 'Frente cadastrada', 'profiles' => ['Analista'], 'contract_quantity' => 3, 'monthly_rate' => '1000.00'],
], 'months' => ['2026-06' => $month]]);
referenceCheck(Config::fromSettings([Config::CONFIG_KEY => Config::json($legacy)]) === $legacy, 'Nonempty legacy configuration is not overwritten or migrated');
referenceCheck(!array_key_exists('contract_total', $legacy) && !array_key_exists('profile_lines', $legacy['contract_fronts'][0]), 'Legacy revision shape remains stable');
$oldSummary = Finance::build(['period' => ['month' => '2026-06'], 'input' => ['financial_config' => $legacy]]);
referenceCheck($oldSummary['contract_value_total'] === '3000.00' && $oldSummary['contract_quantity_total'] === 3, 'Legacy single-rate front still computes exactly');
referenceCheck(!$oldSummary['available'] && $oldSummary['contract_fronts'][0]['contract_value'] === '3000.00', 'Contract is available independently of staff register');

$f = Finance::build(['period' => ['month' => '2026-06']]);
referenceCheck($f['contract_quantity_total'] === 83 && $f['contract_value_total'] === '562457.91', 'Unconfigured monthly register retains standard contract table');
referenceCheck(!$f['available'] && !$f['complete'] && $f['gross_value'] === '0.00', 'Default contract does not fabricate actual monthly billing');
referenceCheck($f['contract_total'] === '562457.91', 'Export can distinguish approved total from computed total');
$byId = array_column($f['contract_fronts'], null, 'id');
$expected = ['gestao' => '28923.41', 'n1' => '107084.80', 'n2' => '263992.93', 'aplicacoes' => '32915.46',
    'conectividade' => '63759.38', 'seguranca' => '42546.62', 'monitoramento' => '23235.39'];
foreach ($expected as $id => $value) referenceCheck($byId[$id]['contract_value'] === $value, 'Exact profile-rate sum for ' . $id);
referenceCheck(array_sum(array_map(static function ($front) { return Config::cents($front['contract_value']); }, $f['contract_fronts'])) === 56245799, 'Displayed reference rows retain actual unit-rate products');
referenceCheck($byId['n2']['profile_lines'][1]['contract_value'] === '124959.46', 'Malformed printed item total does not affect quantity × unit-rate calculation');
referenceCheck($byId['conectividade']['profile_lines'][0]['monthly_rate'] === '6757.55', 'Approved grand total does not change profile rates');
referenceCheck($byId['n2']['contract_quantity'] === 49 && $byId['n2']['monthly_rate'] === '0.00', 'Mixed-rate front aggregate cannot masquerade as a unit rate');

$computed = $default;
$computed['contract_total'] = null;
$computedSummary = Finance::build(['input' => ['financial_config' => $computed]]);
referenceCheck($computedSummary['contract_value_total'] === '562457.99' && $computedSummary['contract_total'] === null, 'Explicit automatic total computes from current profile lines');
$zero = $default;
$zero['contract_total'] = '0.00';
referenceCheck(Finance::build(['input' => ['financial_config' => $zero]])['contract_value_total'] === '0.00', 'Explicit zero total is not treated as empty');

$fronts = $default['contract_fronts'];
referenceCheck(Config::resolveFront(['profile' => "  TÉCNICO de suporte ao usuário de tecnologia da informação\nSÊNIOR "], $fronts) === 'n1', 'Case and whitespace normalized unique profile assignment');
referenceCheck(Config::resolveFront(['profile' => 'Analista de suporte computacional Pleno'], $fronts) === '__unmapped', 'N2 versus monitoring ambiguity cannot silently choose first');
referenceCheck(Config::resolveFront(['profile' => 'Analista de suporte computacional Pleno', 'service_front' => 'monitoramento'], $fronts) === 'monitoramento', 'Explicit assignment disambiguates shared profile');
referenceCheck(Config::resolveFront(['profile' => 'Analista de suporte computacional Pleno', 'service_front' => 'deleted'], $fronts) === '__unmapped', 'Deleted assignment cannot infer a shared profile');
referenceCheck(Config::resolveFront(['profile' => 'Técnico de suporte ao usuário de tecnologia da informação Pleno', 'service_front' => 'deleted'], $fronts) === '__unmapped', 'Deleted explicit assignment cannot silently move to a unique profile front');
referenceCheck(Config::resolveFront(['profile' => 'Not registered'], $fronts) === '__unmapped', 'Unknown profile remains explicitly unassigned');

$rows = Staff::rows([
    ['id' => 'full', 'name' => 'Profissional A', 'profile' => 'Técnico de suporte ao usuário de tecnologia da informação Pleno', 'admission_date' => '2026-01-01', 'monthly_value' => '900.00'],
    ['id' => 'partial', 'name' => 'Profissional B', 'profile' => 'Técnico de suporte ao usuário de tecnologia da informação Pleno', 'admission_date' => '2026-06-16', 'monthly_value' => '900.00'],
    ['id' => 'exit', 'name' => 'Profissional C', 'profile' => 'Analista de suporte computacional Pleno', 'admission_date' => '2026-01-01', 'departure_date' => '2026-06-02', 'monthly_value' => '900.00', 'service_front' => 'monitoramento'],
    ['id' => 'ambiguous', 'name' => 'Profissional D', 'profile' => 'Analista de suporte computacional Sênior', 'admission_date' => '2026-01-01', 'monthly_value' => '0.00'],
    ['id' => 'excluded', 'name' => 'Profissional E', 'profile' => 'Técnico de suporte ao usuário de tecnologia da informação Pleno', 'admission_date' => '2026-01-01', 'monthly_value' => '999.00', 'not_billed' => true],
    ['id' => 'older', 'name' => 'Profissional F', 'profile' => 'Técnico de suporte ao usuário de tecnologia da informação Pleno', 'admission_date' => '2026-01-01', 'departure_date' => '2026-05-30', 'monthly_value' => '999.00'],
]);
$staff = Staff::summarize($rows, '2026-06');
$monthly = Finance::build(['period' => ['month' => '2026-06'], 'input' => ['financial_config' => $default], 'staff_register' => $staff]);
$frontMonths = array_column($monthly['monthly_fronts'], null, 'id');
referenceCheck($monthly['gross_value'] === '1410.00' && count($monthly['roster']) === 5, 'Monthly actuals use overlap and saved individual value, not contract rate');
referenceCheck($frontMonths['n1']['professionals'] === 2 && $frontMonths['n1']['billed_days'] === 45 && $frontMonths['n1']['billed_value'] === '1350.00', 'Full and proportional workers aggregate into same front');
referenceCheck($frontMonths['monitoramento']['professionals'] === 1 && $frontMonths['monitoramento']['billed_value'] === '60.00', 'Departure day included in billing and explicitly mapped front');
referenceCheck($frontMonths['__unmapped']['professionals'] === 1 && $frontMonths['__unmapped']['billed_value'] === '0.00', 'Zero-rate billed worker retained without guessing shared front');
referenceCheck(array_sum(array_column($monthly['monthly_fronts'], 'professionals')) === $staff['denominator'] && $staff['denominator'] === 4, 'Front totals reconcile with INS10 billed count');
referenceCheck(array_sum(array_map(static function ($row) { return Config::cents($row['billed_value']); }, $monthly['monthly_fronts'])) === Config::cents($monthly['gross_value']), 'Every billed amount reconciles with front totals');
referenceCheck($monthly['contract_value_total'] === '562457.91', 'Staff values, exclusion, and day changes cannot change contractual baseline');

$excludedUnknown = Staff::summarize(Staff::rows([
    ['id' => 'excludedunknown', 'name' => 'Pessoa desconhecida', 'profile' => 'Perfil não cadastrado', 'admission_date' => '2026-01-01', 'monthly_value' => '900.00', 'not_billed' => true],
]), '2026-06');
$unknown = Finance::build(['period' => ['month' => '2026-06'], 'staff_register' => $excludedUnknown]);
$unknownMonths = array_column($unknown['monthly_fronts'], null, 'id');
referenceCheck(isset($unknownMonths['__unmapped']) && $unknownMonths['__unmapped']['professionals'] === 0, 'Unmapped zero row supports workbook changes without hiding newly billed workers');

$bad = [
    ['contract_total' => '-0.01'], ['contract_total' => '562457.911'],
    ['contract_fronts' => [['id' => 'a', 'label' => 'A', 'profile_lines' => [['profile' => 'Analista', 'contract_quantity' => '-1', 'monthly_rate' => '1.00']]]]],
    ['contract_fronts' => [['id' => 'a', 'label' => 'A', 'profile_lines' => [['profile' => 'Analista', 'contract_quantity' => 1, 'monthly_rate' => '1.001']]]]],
];
foreach ($bad as $input) {
    try { Config::normalize($input); referenceCheck(false, 'Invalid contractual input accepted'); }
    catch (InvalidArgumentException $e) { referenceCheck(true, 'Invalid contractual input rejected'); }
}
echo "Contract reference checks passed: $checks assertions.\n";
