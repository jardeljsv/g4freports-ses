<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\ChartData;
use GlpiPlugin\G4freports\Ses\MetricEvidence;
use GlpiPlugin\G4freports\Ses\OfficePackage;
use GlpiPlugin\G4freports\Ses\RegisterExporter;
use GlpiPlugin\G4freports\Ses\StaffRegister;
use GlpiPlugin\G4freports\Ses\XlsxExporter;

$assertions = 0;
function billingExportCheck(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function billingExportSheet(string $binary): DOMXPath
{
    $parts = OfficePackage::read($binary);
    foreach ($parts as $name => $xml) {
        $doc = new DOMDocument();
        billingExportCheck($doc->loadXML($xml), 'Valid XML: ' . $name);
    }
    $doc = new DOMDocument();
    $doc->loadXML($parts['xl/worksheets/sheet1.xml']);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    return $xp;
}
function billingExportValue(DOMXPath $xp, string $ref): string
{
    return $xp->evaluate('string(//s:c[@r="' . $ref . '"])');
}

$base = ['profile' => 'Técnico', 'admission_date' => '2026-01-01', 'monthly_value' => '3000.00', 'not_billed' => false];
$roster = [
    ['id' => 'a', 'name' => 'A Integral'] + $base,
    ['id' => 'b', 'name' => 'B Admissão', 'admission_date' => '2026-06-16'] + $base,
    ['id' => 'c', 'name' => 'C Saída', 'departure_date' => '2026-06-02'] + $base,
    ['id' => 'd', 'name' => 'D Saída anterior', 'departure_date' => '2026-05-15'] + $base,
    ['id' => 'e', 'name' => 'E Desconsiderado', 'not_billed' => true] + $base,
    ['id' => 'f', 'name' => 'F Fora da janela', 'departure_date' => '2026-03-15'] + $base,
    ['id' => 'g', 'name' => 'G Futuro', 'admission_date' => '2026-07-01'] + $base,
];
$summary = StaffRegister::summarize($roster, '2026-06');
$metric = ['id' => 'INS10', 'numerator' => 2, 'denominator' => 3, 'value' => 200 / 3,
    'target' => 10, 'direction' => 'lte', 'status' => 'unmet', 'method' => $summary['method'], 'note' => $summary['note']];
$snapshot = ['period' => ['month' => '2026-06'], 'input' => [], 'metrics' => [$metric],
    'tickets' => [], 'calls' => [], 'staff_register' => $summary];
$original = $snapshot;
$details = MetricEvidence::table($snapshot, 'INS10');
$audit = MetricEvidence::staffRegisterTable($snapshot);
billingExportCheck(count($details['headers']) === 20 && $details['headers'][10] === 'Não Faturar'
    && $details['headers'][4] === 'Valor mensal (R$)' && $details['headers'][16] === 'Valor Faturado (R$)',
    'Stable evidence positions plus monthly automatic/manual audit columns');
billingExportCheck(array_column($details['rows'], 0) === ['A Integral', 'B Admissão', 'C Saída', 'D Saída anterior'],
    'Monthly population contains applicable workers and recent departures only');
billingExportCheck(array_column($details['rows'], 15) === [30, 15, 2, 0], 'Inclusive monthly billed days are exported');
billingExportCheck(array_column($details['rows'], 16) === [3000.0, 1500.0, 200.0, 0.0], 'Prorated amounts are numeric');
billingExportCheck(array_column($details['rows'], 17) === ['Ativo', 'Ativo', 'Inativo', 'Inativo'], 'Status follows recorded departure');
billingExportCheck($details['rows'][2][13] === '2026-06-01' && $details['rows'][2][14] === '2026-06-02',
    'First and last billed dates include departure day');
billingExportCheck(count($audit['rows']) === 7 && array_sum(array_column($audit['rows'], 15)) === 47
    && array_sum(array_column($audit['rows'], 16)) === 4700.0, 'Full historical audit retains records without inflating monthly billing');
billingExportCheck($audit['rows'][4][4] === 3000.0 && $audit['rows'][4][10] === 'Sim'
    && $audit['rows'][4][15] === 0 && $audit['rows'][4][16] === 0.0, 'Excluded mark and nominal value are unchanged while billing is zero');

$excel = new XlsxExporter();
foreach (['snapshot' => $snapshot, 'chartData' => ChartData::build($snapshot), 'evidence' => ['INS10' => ['available' => false]]] as $property => $value) {
    (new ReflectionProperty(XlsxExporter::class, $property))->setValue($excel, $value);
}
$sheet = (new ReflectionMethod(XlsxExporter::class, 'metric'))->invoke($excel, $metric);
billingExportCheck($sheet['rows'][8][2]['formula'] === 'B25' && $sheet['rows'][9][2]['formula'] === 'B24'
    && abs($sheet['rows'][10][2]['value'] - 2 / 3) < 0.0000001, 'INS10 divides departures by every billed professional, including current-month exit');
billingExportCheck($sheet['rows'][29][2]['value'] === 9000.0 && $sheet['rows'][31][2]['value'] === 47
    && $sheet['rows'][32][2]['value'] === 4700.0, 'Monthly nominal salary and billed amount remain distinct totals');
billingExportCheck($sheet['rows'][31][2]['formula'] === "'Base de Dados Colaboradores'!D11"
    && $sheet['rows'][32][2]['formula'] === "'Base de Dados Colaboradores'!F11", 'Summary links authoritative monthly billing totals');
billingExportCheck(str_contains($sheet['evidence_xml'], 'SUBTOTAL(109,P') && str_contains($sheet['evidence_xml'], 'SUBTOTAL(109,Q'),
    'Evidence footers total visible billed days and money');
$auditSheet = (new ReflectionMethod(XlsxExporter::class, 'exclusionAudit'))->invoke($excel);
$staffTable = array_values(array_filter($auditSheet['tables'], static fn(array $t): bool => $t['name'] === 'Cadastro_profissionais'))[0];
billingExportCheck(preg_match('/^A\d+:T\d+$/D', $staffTable['ref']) === 1, 'Full historical audit filter covers all twenty columns');
$auditTotals = [];
foreach ($auditSheet['rows'] as $cells) foreach ($cells as $cell) {
    if (str_starts_with($cell['formula'] ?? '', 'SUBTOTAL(109,P')) $auditTotals['days'] = $cell['value'];
    if (str_starts_with($cell['formula'] ?? '', 'SUBTOTAL(109,Q')) $auditTotals['amount'] = $cell['value'];
}
billingExportCheck($auditTotals === ['days' => 47, 'amount' => 4700.0], 'Audit billing formulas have reconciled cached totals');
billingExportCheck($snapshot === $original, 'Exports never mutate the saved roster or INS10 results');

// Standalone export uses the entered raw values, not posted computed fields; drafts stay exportable.
$draft = ['id' => 'draft', 'name' => '=1+1', 'admission_date' => 'incompleta', 'monthly_value' => 'rascunho',
    'billed_days' => 99, 'billed_value' => '999999.99', 'billing_first_day' => '2026-06-01'];
$standalone = billingExportSheet(RegisterExporter::export('staff', array_merge($roster, [$draft]), 'all', '2026-06'));
billingExportCheck($standalone->query('//s:f')->length === 0 && billingExportValue($standalone, 'B11') === '=1+1',
    'Standalone entered text never becomes an executable formula');
billingExportCheck(str_contains(billingExportValue($standalone, 'A2'), '06/2026'), 'Export identifies the selected billing month');
billingExportCheck(billingExportValue($standalone, 'J3') === 'Primeiro dia Faturado'
    && billingExportValue($standalone, 'N3') === 'Status', 'Standalone billing columns append to existing register fields');
billingExportCheck(billingExportValue($standalone, 'L4') === '30' && billingExportValue($standalone, 'M4') === '3000.00'
    && billingExportValue($standalone, 'L6') === '2' && billingExportValue($standalone, 'M6') === '200.00', 'Standalone inclusive proration agrees with monthly export');
billingExportCheck($standalone->evaluate('string(//s:c[@r="J4"]/@t)') === 'n'
    && $standalone->evaluate('string(//s:c[@r="M4"]/@t)') === 'n', 'Derived dates and currency use native numeric cells');
billingExportCheck(billingExportValue($standalone, 'D11') === 'incompleta' && billingExportValue($standalone, 'G11') === 'rascunho'
    && billingExportValue($standalone, 'J11') === '' && billingExportValue($standalone, 'L11') === '' && billingExportValue($standalone, 'M11') === '',
    'Incomplete raw values remain visible while unverifiable computed fields stay blank');
billingExportCheck(billingExportValue($standalone, 'B12') === '8' && billingExportValue($standalone, 'L12') === '47'
    && billingExportValue($standalone, 'M12') === '4700.00', 'Standalone total counts draft rows but sums only computable billing');
billingExportCheck($standalone->evaluate('string(//s:autoFilter/@ref)') === 'A3:Q11', 'Standalone filter spans complete enhanced register');
$filtered = billingExportSheet(RegisterExporter::export('staff', [$roster[2]], 'filtered', '2026-06'));
billingExportCheck(billingExportValue($filtered, 'L5') === '2' && billingExportValue($filtered, 'M5') === '200.00', 'Filtered standalone totals include only supplied rows');
$empty = billingExportSheet(RegisterExporter::export('staff', [], 'filtered', '2026-06'));
billingExportCheck(billingExportValue($empty, 'L4') === '0' && billingExportValue($empty, 'M4') === '0.00', 'Empty monthly selection has explicit zero totals');
try {
    RegisterExporter::export('staff', [], 'all', '2026-13');
    billingExportCheck(false, 'Invalid month must be rejected');
} catch (InvalidArgumentException $e) {
    billingExportCheck(true, 'Invalid month rejected');
}
billingExportCheck(RegisterExporter::export('tests', [['number' => '00123', 'label' => 'Teste']], 'all')
    === RegisterExporter::export('tests', [['number' => '00123', 'label' => 'Teste']], 'all', '2026-06'),
    'Phone register export remains unchanged when a report month is supplied');

// Monthly day edits survive export; calculated inputs never override the saved month value.
$manualPerson = ['id' => 'manual', 'name' => 'Ajuste mensal', 'billing_days_overrides' => ['2026-06' => '2', '2026-07' => 0],
    'billed_days' => 99, 'billed_value' => 999999] + $base;
$manualJune = billingExportSheet(RegisterExporter::export('staff', [$manualPerson], 'all', '2026-06'));
billingExportCheck(billingExportValue($manualJune, 'L4') === '2' && billingExportValue($manualJune, 'M4') === '200.00'
    && billingExportValue($manualJune, 'O4') === '30' && billingExportValue($manualJune, 'P4') === '2',
    'Standalone preserves entered month-specific override and distinct automatic audit count');
$manualJuly = billingExportSheet(RegisterExporter::export('staff', [$manualPerson], 'all', '2026-07'));
billingExportCheck(billingExportValue($manualJuly, 'L4') === '0' && billingExportValue($manualJuly, 'M4') === '0.00'
    && billingExportValue($manualJuly, 'O4') === '31', 'Explicit zero differs from omitted automatic day count in another month');

if (isset($argv[1])) {
    if (!is_dir($argv[1])) mkdir($argv[1], 0770, true);
    file_put_contents($argv[1] . '/staff_billing.xlsx', RegisterExporter::export('staff', array_merge($roster, [$draft]), 'all', '2026-06'));
}
echo 'Staff billing export checks: ' . $assertions . " assertions passed.\n";
