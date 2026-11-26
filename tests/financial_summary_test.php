<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\FinanceSettings as Config;
use GlpiPlugin\G4freports\Ses\FinancialSummary as Finance;
use GlpiPlugin\G4freports\Ses\StaffRegister as Staff;

$checks = 0;
function financeCheck($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); }
financeCheck(Config::money('R$ 5.980,99', false) === '5980.99' && Config::money('-1.000,25', true) === '-1000.25', 'Brazilian currency and signed adjustments normalize without losing cents');
foreach (['INS1','INS2','INS3','INS4','INS6','INS7','INS8','INS11','INS12'] as $id) {
    $target = in_array($id, ['INS1','INS2','INS3','INS4']) ? 90 : (in_array($id,['INS7','INS8']) ? 85 : 80);
    for ($band = 0; $band < 4; $band++) {
        financeCheck(Finance::rate($id, $target - $band * 5) === $band, $id . ' exact lower boundary');
        financeCheck(Finance::rate($id, $target - $band * 5 - 0.00001) === $band + 1, $id . ' below boundary uses next band');
    }
}
foreach (['INS5'=>[2,4,6,8], 'INS9'=>[5,7,9,11], 'INS10'=>[10,15,20,25]] as $id=>$bounds) {
    foreach ($bounds as $rate=>$bound) {
        financeCheck(Finance::rate($id, $bound) === $rate, $id . ' upper bound inclusive');
        financeCheck(Finance::rate($id, $bound + 0.00001) === $rate+1, $id . ' excess uses next band');
    }
}
$rows = Staff::rows([
    ['id'=>'one','name'=>'Profissional A','profile'=>'Analista','admission_date'=>'2026-01-01','monthly_value'=>'1000.00'],
    ['id'=>'two','name'=>'Profissional B','profile'=>'Analista','admission_date'=>'2026-06-16','monthly_value'=>'1000.00'],
    ['id'=>'zero','name'=>'Profissional C','profile'=>'Analista','admission_date'=>'2026-01-01','monthly_value'=>'0.00'],
    ['id'=>'excluded','name'=>'Profissional D','profile'=>'Analista','admission_date'=>'2026-01-01','monthly_value'=>'999.00','not_billed'=>true],
]);
$staff = Staff::summarize($rows, '2026-06');
$metrics = [];
for ($i=1; $i<=12; $i++) {
    $id='INS'.$i; $less=in_array($id,['INS5','INS9','INS10']);
    $metrics[]=['id'=>$id,'label'=>$id,'value'=>$less?0:100,'numerator'=>$less?0:100,'denominator'=>100,
        'status'=>'met','target'=>$less?10:90,'direction'=>$less?'lte':'gte'];
}
// Deliberately fail INS3, INS5 and INS10: 4%+1%+1%=6%, not the customer's pending 2% appeal.
foreach ([2=>[2,12],4=>[251,6567],9=>[8,69]] as $index=>$pair) {
    $metrics[$index]['numerator']=$pair[0]; $metrics[$index]['denominator']=$pair[1];
    $metrics[$index]['value']=100*$pair[0]/$pair[1]; $metrics[$index]['status']='unmet';
}
$config=Config::normalize(['contract_fronts'=>[['id'=>'support','label'=>'Suporte','profiles'=>['Analista'],'contract_quantity'=>4,'monthly_rate'=>'1000.00']],
    'months'=>['2026-05'=>['deduction_overrides'=>['INS3'=>['rate'=>0,'justification'=>'Ajuste de maio aprovado']],'adjustments'=>[['description'=>'Crédito de maio','amount'=>'10.01']]]]]);
financeCheck(Config::normalize(Config::json($config))===$config,'Configuration roundtrip preserves month and values');
$snapshot=['period'=>['month'=>'2026-06'],'input'=>['financial_config'=>$config],'staff_register'=>$staff,'metrics'=>$metrics];
$f=Finance::build($snapshot);
financeCheck($f['complete'] && $f['available'], 'Known metrics and roster produce complete financial summary');
financeCheck($f['gross_value']==='1500.00' && count($f['roster'])===4,'Gross uses monthly overlap and exclusion, retains excluded detail');
financeCheck($staff['denominator']===3,'Zero rate still counts as billed person');
financeCheck($f['calculated_rate_total']===6 && $f['applied_rate_total']===6,'Unapproved June appeal is not silently accepted');
financeCheck($f['deduction_total']==='90.00' && $f['net_value']==='1410.00','Contract bands on monthly proportional gross');
financeCheck($f['adjustment_total']==='0.00','May credit cannot leak into June');
financeCheck($f['monthly_fronts'][0]['professionals']===3 && $f['monthly_fronts'][0]['billed_value']==='1500.00','Explicit profile mapping includes zero-rate headcount');
financeCheck($f['contract_quantity_total']===4 && $f['contract_value_total']==='4000.00','Configured baseline independent from actual monthly headcount');
$snapshot['input']['financial_config']['months']['2026-06']=['deduction_overrides'=>['INS3'=>['rate'=>0,'justification'=>'Decisão financeira aprovada em reunião.']],
    'adjustments'=>[['description'=>'Ajuste anterior aprovado','amount'=>'25.11'],['description'=>'Desconto informado','amount'=>'-5.05']]];
$f=Finance::build($snapshot);
financeCheck($f['calculated_rate_total']===6 && $f['applied_rate_total']==2,'Approved adjustment preserves calculated deduction');
financeCheck($f['deduction_total']==='30.00' && $f['adjustment_total']==='20.06' && $f['net_value']==='1490.06','Signed adjustments and separately rounded deductions reconcile');
financeCheck($snapshot['metrics'][2]['value']===100*2/12 && $f['deductions'][2]['override'],'Financial adjustment does not mark INS3 compliant');
$snapshot['metrics'][6]['value']=null; $snapshot['metrics'][6]['status']='pending';
$f=Finance::build($snapshot);
financeCheck(!$f['complete'] && $f['deductions'][6]['calculated_rate']===null && $f['deductions'][6]['amount']===null,'Missing metric cannot become a healthy zero deduction');
$bad=[['months'=>['2026-06'=>['deduction_overrides'=>['INS3'=>['rate'=>0,'justification'=>'']]]]],
    ['months'=>['2026-06'=>['deduction_overrides'=>['INS3'=>['rate'=>5,'justification'=>'Too high']]]]],
    ['months'=>['2026-13'=>[]]], ['months'=>['2026-06'=>['adjustments'=>[['description'=>'Credit','amount'=>'1.001']]]]],
    ['contract_fronts'=>[['id'=>'a','label'=>'A','profile_lines'=>[
        ['profile'=>'Analista','contract_quantity'=>1,'monthly_rate'=>'10'],['profile'=>'analista','contract_quantity'=>1,'monthly_rate'=>'10']]]]]];
foreach ($bad as $input) {
    try { Config::normalize($input); financeCheck(false,'Invalid finance config was accepted'); }
    catch (InvalidArgumentException $e) { financeCheck(true,'Invalid configuration rejected'); }
}
echo "Financial summary checks passed: $checks assertions.\n";
