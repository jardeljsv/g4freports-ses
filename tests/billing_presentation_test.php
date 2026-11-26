<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\ChartData;
use GlpiPlugin\G4freports\Ses\FinancialSummary;
use GlpiPlugin\G4freports\Ses\MetricEvidence;
use GlpiPlugin\G4freports\Ses\StaffRegister;
use GlpiPlugin\G4freports\Ses\XlsxExporter;

$checks = 0;
function billingPresentationVerify(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
$base = ['profile' => 'Técnico', 'admission_date' => '2026-01-01', 'monthly_value' => '100.00'];
$rows = [
    ['id' => 'active', 'name' => 'A Ativo', 'billed' => false] + $base,
    ['id' => 'exit-month', 'name' => 'B Saída no mês', 'departure_date' => '2026-06-02'] + $base,
    ['id' => 'exit-past', 'name' => 'C Saída anterior', 'departure_date' => '2026-04-02'] + $base,
    ['id' => 'excluded', 'name' => 'D Não faturar', 'billed' => true] + $base,
    ['id' => 'canonical', 'name' => 'E Canonical', 'billed' => true, 'not_billed' => false,
        'billing_days_overrides' => ['2026-06' => 2, '2026-07' => 0]] + $base,
    ['id' => 'zero', 'name' => 'F Zero', 'billing_days_overrides' => ['2026-06' => 0]] + $base,
];
foreach (['pending', 'replacement', 'contractor_reduction', 'other'] as $reason) {
    $variant = $rows;
    foreach ($variant as &$row) $row['departure_reason'] = $reason;
    unset($row);
    $staff = StaffRegister::summarize($variant, '2026-06');
    billingPresentationVerify($staff['denominator'] === 3 && $staff['numerator'] === 2,
        'Partial-month leaver counts once; marked and zero-day staff do not; optional reason does not change counts');
    $metric = ['id' => 'INS10', 'numerator' => 2, 'denominator' => 3, 'value' => 200 / 3,
        'target' => 10, 'direction' => 'lte', 'status' => 'unmet', 'method' => $staff['method'], 'note' => $staff['note']];
    $snapshot = ['period' => ['month' => '2026-06'], 'input' => [], 'metrics' => [$metric],
        'tickets' => [], 'calls' => [], 'staff_register' => $staff];
    $evidence = MetricEvidence::table($snapshot, 'INS10');
    billingPresentationVerify(array_column($evidence['rows'], 0) === ['A Ativo', 'B Saída no mês', 'C Saída anterior', 'E Canonical'],
        'Indicator evidence is denominator plus considered departure cohort');
    billingPresentationVerify(array_column($evidence['rows'], 5) === ['Sim', 'Sim', 'Não', 'Sim'], 'Explicit billing membership is auditable');
    billingPresentationVerify(!str_contains(implode('|', $evidence['headers']), 'Motivo'), 'Optional reason is absent from presentation');
    $audit = MetricEvidence::staffRegisterTable($snapshot);
    billingPresentationVerify(count($audit['rows']) === 6 && $audit['rows'][3][10] === 'Sim' && $audit['rows'][4][10] === 'Não',
        'Full audit preserves historical rows and original negative checkmarks');
    billingPresentationVerify($audit['rows'][4][18] === 30 && $audit['rows'][4][19] === 2 && $audit['rows'][4][15] === 2,
        'Automatic, entered and effective days remain distinct');
    $charts = ChartData::build($snapshot);
    billingPresentationVerify($charts['INS10']['series'][0]['values'] === [0.1]
        && abs($charts['INS10']['series'][1]['values'][0] - 2 / 3) < 1e-12,
        'INS10 chart compares threshold and result instead of incomparable headcount totals');
    $excel = new XlsxExporter();
    foreach (['snapshot' => $snapshot, 'chartData' => $charts, 'evidence' => ['INS10' => ['available' => false]]] as $key => $value) {
        (new ReflectionProperty(XlsxExporter::class, $key))->setValue($excel, $value);
    }
    $sheet = (new ReflectionMethod(XlsxExporter::class, 'metric'))->invoke($excel, $metric);
    billingPresentationVerify($sheet['rows'][24][2]['value'] === 3 && $sheet['rows'][25][2]['value'] === 2,
        'INS10 numerical caches use billed-month denominator');
    billingPresentationVerify($sheet['rows'][24][2]['formula'] === "'Base de Dados Colaboradores'!Q12"
        && str_contains($sheet['rows'][25][2]['formula'], 'COUNTIF($I$'), 'Workbook denominator links monthly roster while numerator retains three-month departures');
    billingPresentationVerify($sheet['charts'][0]['series'][0]['value_ref'] === "'INS10'!\$B\$11"
        && $sheet['charts'][0]['series'][1]['value_ref'] === "'INS10'!\$B\$10", 'Chart uses existing threshold/result cells without duplicate data tables');
    billingPresentationVerify(str_contains($sheet['evidence_xml'], 'IFERROR(INDEX(&apos;Base de Dados Colaboradores&apos;!$Q$7:$Q$11')
        && str_contains($sheet['evidence_xml'], 'MATCH($L'), 'Detailed membership and billed amounts follow stable-ID monthly input links');
    $personnel = (new ReflectionMethod(XlsxExporter::class, 'personnel'))->invoke($excel);
    billingPresentationVerify(count($personnel['tables']) === 1 && $personnel['tables'][0]['ref'] === 'A6:R11',
        'Monthly roster includes excluded and zero-day workers, omits earlier departures');
    billingPresentationVerify($personnel['rows'][7][6]['formula'] === 'ROUND(E7/30*D7,2)'
        && str_contains($personnel['rows'][10][4]['formula'], 'ISNUMBER(N10)'), 'Personnel amounts use actual month length and manual day override');
}
// Financial formulas retain contract boundary inclusivity and use entered overrides separately.
$metrics = [];
foreach (range(1, 12) as $n) {
    $id = 'INS' . $n;
    $low = in_array($n, [5, 9, 10], true);
    $metrics[] = ['id' => $id, 'label' => $id, 'numerator' => $n === 3 ? 2 : ($low ? 0 : 100), 'denominator' => $n === 3 ? 12 : 100,
        'value' => $n === 3 ? 200 / 12 : ($low ? 0 : 100), 'target' => $low ? 10 : 80, 'direction' => $low ? 'lte' : 'gte', 'status' => $n === 3 ? 'unmet' : 'met'];
}
$snapshot['metrics'] = $metrics;
$snapshot['input']['financial_config'] = ['months' => ['2026-06' => ['deduction_overrides' => ['INS3' => ['rate' => 0, 'justification' => 'Ajuste financeiro aprovado']],
    'adjustments' => [['description' => 'Ajuste de período anterior', 'amount' => '-10.00']]]],
    'contract_fronts' => [['id' => 'support', 'label' => 'Suporte', 'profiles' => ['Técnico'], 'contract_quantity' => 10, 'monthly_rate' => '100.00']]];
$snapshot['financial'] = FinancialSummary::build($snapshot);
$excel = new XlsxExporter();
(new ReflectionProperty(XlsxExporter::class, 'snapshot'))->setValue($excel, $snapshot);
$finance = (new ReflectionMethod(XlsxExporter::class, 'financial'))->invoke($excel);
$staffSheet = (new ReflectionMethod(XlsxExporter::class, 'personnel'))->invoke($excel);
billingPresentationVerify($finance['rows'][14][5]['value'] === 0.04 && $finance['rows'][14][6]['value'] === 0.0
    && $finance['rows'][14][9]['value'] === 0.0, 'Approved financial exception does not replace the calculated failing rate');
billingPresentationVerify($finance['rows'][14][4]['formula'] === "'INS3'!B10"
    && $finance['rows'][14][6]['formula'] === 'IF(ISNUMBER(I14),I14,E14)', 'Manual financial rate is explicit and live result remains linked');
billingPresentationVerify($finance['rows'][5][2]['formula'] === "'Base de Dados Colaboradores'!F12", 'Gross billing links complete monthly staff total');
billingPresentationVerify($staffSheet['rows'][7][16]['value'] === 'Suporte' && $staffSheet['rows'][7][18]['value'] === 'support',
    'Explicit configured profile mapping drives service-front aggregation');
billingPresentationVerify(str_contains($finance['rows'][16][5]['formula'], 'D16<=0.02'), 'INS5 exactly 2 percent is compliant');
$formula = (new ReflectionMethod(XlsxExporter::class, 'deductionRateFormula'))->invoke($excel, 'INS10', 'D1');
billingPresentationVerify(str_contains($formula, 'D1<=0.1') && str_contains($formula, "'INS10'!B12=\"Não aplicável\",0"),
    'INS10 correct upper bound and no-population handling are represented in formulas');
// A numeric provisional result and an approved override must not finalize missing evidence.
$snapshot['metrics'][2]['status'] = 'provisional';
$snapshot['financial'] = FinancialSummary::build($snapshot);
(new ReflectionProperty(XlsxExporter::class, 'snapshot'))->setValue($excel, $snapshot);
$pendingFinance = (new ReflectionMethod(XlsxExporter::class, 'financial'))->invoke($excel);
billingPresentationVerify($pendingFinance['rows'][14][5]['value'] === 'Não apurado'
    && $pendingFinance['rows'][14][6]['value'] === 0.0, 'Provisional numeric value remains uncalculated even with explicit financial override');
billingPresentationVerify(str_contains($pendingFinance['rows'][14][5]['formula'], "'INS3'!B12=\"Meta atingida\"")
    && str_contains($pendingFinance['rows'][14][5]['formula'], "'INS3'!B12=\"Meta não atingida\""), 'Recalculation gates contract bands on accepted metric status');
billingPresentationVerify($pendingFinance['rows'][6][2]['value'] === 'Não apurado'
    && str_contains($pendingFinance['rows'][6][2]['formula'], 'COUNT(E12:E23)=12')
    && str_contains($pendingFinance['rows'][6][2]['formula'], 'COUNT(G12:G23)=12'), 'Numeric overridden deductions cannot make incomplete finance final');
$snapshot['staff_register']['configured'] = false;
$snapshot['financial'] = FinancialSummary::build($snapshot);
(new ReflectionProperty(XlsxExporter::class, 'snapshot'))->setValue($excel, $snapshot);
$unconfigured = (new ReflectionMethod(XlsxExporter::class, 'financial'))->invoke($excel);
billingPresentationVerify($unconfigured['rows'][12][7]['formula'] === '"Não apurado"'
    && str_contains($unconfigured['rows'][6][2]['formula'], 'IF(FALSE,'), 'Absent roster cannot recalculate into a completed zero-value report');
$short = (new ReflectionMethod(XlsxExporter::class, 'compactHeaders'))->invoke($excel, [
    'Código da prioridade GLPI', 'Categoria da base de conhecimento', 'Prazo armazenado no GLPI',
    'INS12 · Pesquisas respondidas como satisfeito ou muito satisfeito (4 ou 5)',
    'Tempo útil armazenado GLPI (s)', 'Tempo útil armazenado no GLPI']);
billingPresentationVerify($short === ['Prioridade (código)', 'Categoria BC (ID)', 'Prazo GLPI',
    'INS12 · Respostas favoráveis (4–5)', 'Tempo útil GLPI (s)', 'Tempo útil GLPI'], 'Compact headers retain source, units and distinct column identities');
billingPresentationVerify(count(array_unique($short)) === count($short), 'Compact display names remain unique');
echo "Billing presentation checks: {$checks} assertions passed.\n";
