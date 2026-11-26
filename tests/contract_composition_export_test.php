<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\DocxExporter;
use GlpiPlugin\G4freports\Ses\FinanceSettings;
use GlpiPlugin\G4freports\Ses\FinancialSummary;
use GlpiPlugin\G4freports\Ses\StaffRegister;
use GlpiPlugin\G4freports\Ses\XlsxExporter;

$checks = 0;
function compositionCheck(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function compositionWord(array $financial, array $staff): DOMXPath {
    $xml = (new ReflectionMethod(DocxExporter::class, 'frontComposition'))->invoke(new DocxExporter(), $financial, $staff);
    $doc = new DOMDocument();
    compositionCheck($doc->loadXML('<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $xml . '</root>'), 'Word composition XML is valid');
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    return $xpath;
}
function compositionSheet(array $snapshot, string $method): array {
    $exporter = new XlsxExporter();
    (new ReflectionProperty(XlsxExporter::class, 'snapshot'))->setValue($exporter, $snapshot);
    return (new ReflectionMethod(XlsxExporter::class, $method))->invoke($exporter);
}
function compositionRow(array $sheet, string $label): array {
    foreach ($sheet['rows'] as $row) if (($row[1]['value'] ?? null) === $label) return $row;
    throw new RuntimeException('Missing expected row: ' . $label);
}

$config = FinanceSettings::defaultBaseline();
$profile = $config['contract_fronts'][1]['profile_lines'][0]['profile'];
$shared = $config['contract_fronts'][6]['profile_lines'][0]['profile'];
$base = ['admission_date' => '2026-01-01', 'monthly_value' => '300.00'];
$staff = StaffRegister::summarize(StaffRegister::rows([
    ['id' => 'a', 'name' => 'A Integral', 'profile' => $profile] + $base,
    ['id' => 'b', 'name' => 'B Proporcional', 'profile' => $profile, 'departure_date' => '2026-06-02'] + $base,
    ['id' => 'c', 'name' => 'C Compartilhado', 'profile' => $shared] + $base,
    ['id' => 'd', 'name' => 'D Monitoramento', 'profile' => $shared, 'service_front' => 'monitoramento'] + $base,
    ['id' => 'e', 'name' => 'E Desconsiderado', 'profile' => $profile, 'not_billed' => true] + $base,
    ['id' => 'f', 'name' => 'F Sem dias', 'profile' => 'Perfil sem frente', 'billing_days_overrides' => ['2026-06' => 0]] + $base,
]), '2026-06');
$snapshot = ['period' => ['month' => '2026-06'], 'input' => ['financial_config' => $config], 'staff_register' => $staff, 'metrics' => []];
for ($i = 1; $i <= 12; $i++) {
    $low = in_array($i, [5, 9, 10], true);
    $snapshot['metrics'][] = ['id' => 'INS' . $i, 'value' => $low ? 0 : 100, 'numerator' => $low ? 0 : 1,
        'denominator' => 1, 'target' => $low ? 10 : 90, 'direction' => $low ? 'lte' : 'gte', 'status' => 'met'];
}
$snapshot['financial'] = FinancialSummary::build($snapshot);
$financial = $snapshot['financial'];
$word = compositionWord($financial, $staff);
$tables = $word->query('//w:tbl');
compositionCheck($tables->length === 2, 'Opening has contractual and monthly native tables');
$reference = $word->query('./w:tr', $tables->item(0));
$month = $word->query('./w:tr', $tables->item(1));
compositionCheck($reference->length === 9, 'Seven contractual fronts plus header and total');
compositionCheck($word->query('./w:tr[1]/w:tc', $tables->item(0))->length === 5, 'Customer table has five meaningful columns');
compositionCheck($word->query('./w:tr[1]/w:tc[5]', $tables->item(0))->item(0)->textContent === 'Valor mensal do item', 'Aggregated values are not mislabeled as unit rates');
compositionCheck($word->query('./w:tr[1]/w:tc[5]', $tables->item(1))->item(0)->textContent === 'Valor faturado', 'Monthly table identifies the actual billed amount');
$columnWidths = [];
foreach ($word->query('./w:tblGrid/w:gridCol', $tables->item(0)) as $column) $columnWidths[] = (int)$column->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w');
compositionCheck($columnWidths === [700, 4700, 850, 1350, 1900], 'Opening column widths keep Item and quantity headers readable');
$total = $word->query('./w:tc', $reference->item($reference->length - 1));
compositionCheck($total->item(1)->textContent === '83' && $total->item(2)->textContent === 'R$ 562.457,91', 'Approved contractual quantity and total are displayed');
$monthlyTotal = $word->query('./w:tc', $month->item($month->length - 1));
compositionCheck($monthlyTotal->item(1)->textContent === '4' && $monthlyTotal->item(2)->textContent === 'R$ 920,00', 'Monthly total includes partial worker and excludes checked and zero-day workers');
$unavailable = $financial; $unavailable['available'] = false;
$wordNoStaff = compositionWord($unavailable, []);
compositionCheck($wordNoStaff->query('//w:tbl')->length === 1 && str_contains($wordNoStaff->document->textContent, '562.457,91'), 'Contractual reference remains visible without staff data');
foreach ([$word, $wordNoStaff] as $xml) compositionCheck(!str_contains(mb_strtolower($xml->document->textContent), 'reconcilia'), 'No unrequested contractual reconciliation explanation');

$personnel = compositionSheet($snapshot, 'personnel');
compositionCheck($personnel['rows'][7][18]['value'] === 'n1' && $personnel['rows'][8][18]['value'] === 'n1', 'Unique profiles map to their contractual front');
compositionCheck($personnel['rows'][9][18]['value'] === '__unmapped' && $personnel['rows'][10][18]['value'] === 'monitoramento', 'Ambiguous profile requires explicit assignment in Excel just as in backend');
compositionCheck($personnel['rows'][8][6]['value'] === 20.0 && $personnel['rows'][8][6]['formula'] === 'ROUND(E8/30*D8,2)', 'Proportional billing remains formula-linked to effective days');
$sheet = compositionSheet($snapshot, 'financial');
$contract = compositionRow($sheet, 'Total contratual');
compositionCheck($contract[3]['value'] === 83 && $contract[5]['value'] === 562457.91, 'Excel displays approved total exactly');
compositionCheck(empty($contract[5]['formula']) && str_starts_with($contract[3]['formula'], 'SUM('), 'Excel recalculation cannot replace approved total with quantity-times-rate sum');
$line = compositionRow($sheet, $profile);
compositionCheck($line[3]['value'] === 10 && $line[4]['value'] === 4727.49 && $line[5]['value'] === 47274.9, 'Excel preserves profile quantity and individual contractual rate');
compositionCheck(str_starts_with($line[5]['formula'], 'ROUND(C'), 'Contract detail lines recalculate independently');
$unmapped = compositionRow($sheet, 'Sem frente informada');
compositionCheck($unmapped[3]['value'] === 1 && str_contains($unmapped[3]['formula'], 'COUNTIFS(')
    && str_contains($unmapped[5]['formula'], 'SUMIF('), 'Monthly unresolved bucket retains live headcount and billing formulas');
compositionCheck(str_contains($unmapped[5]['formula'], '$F$7:$F$12'), 'Monthly formulas include zero-day workers that may be edited later');

// An older/sparse snapshot can omit the currently zero-valued unresolved bucket.
// It must still appear in Excel when editable staff cells could activate a worker.
$zeroUnmapped = $snapshot;
$zeroRows = $staff['rows'];
foreach ($zeroRows as &$person) if ($person['id'] === 'c') $person['not_billed'] = true;
unset($person);
$zeroUnmapped['staff_register'] = StaffRegister::summarize($zeroRows, '2026-06');
$zeroUnmapped['financial'] = FinancialSummary::build($zeroUnmapped);
$zeroUnmapped['financial']['monthly_fronts'] = array_values(array_filter($zeroUnmapped['financial']['monthly_fronts'],
    static fn(array $front): bool => $front['id'] !== '__unmapped'));
$sparseBefore = $zeroUnmapped;
$sparseSheet = compositionSheet($zeroUnmapped, 'financial');
$zeroBucket = compositionRow($sparseSheet, 'Sem frente informada');
compositionCheck($zeroBucket[3]['value'] === 0 && $zeroBucket[4]['value'] === 0 && $zeroBucket[5]['value'] === 0.0,
    'Excel adds a missing unresolved bucket for currently excluded/zero-day people');
compositionCheck(str_contains($zeroBucket[3]['formula'], 'COUNTIFS(') && str_contains($zeroBucket[5]['formula'], '$F$7:$F$12'),
    'Added bucket recalculates headcount and value across all editable monthly staff rows');
compositionCheck($zeroUnmapped === $sparseBefore, 'Export-only bucket does not modify the underlying Word/financial snapshot');
$bucketRowNumber = 0;
foreach ($sparseSheet['rows'] as $number => $row) if (($row[1]['value'] ?? '') === 'Sem frente informada') $bucketRowNumber = $number;
$sparseTotal = compositionRow($sparseSheet, 'Total mensal');
compositionCheck(str_ends_with($sparseTotal[5]['formula'], ':E' . $bucketRowNumber . ')'),
    'Monthly total formula includes the added bucket after future staff edits');

$legacy = $snapshot;
$legacy['input']['financial_config'] = FinanceSettings::normalize(['contract_fronts' => [
    ['id' => 'support', 'label' => 'Suporte legado', 'profiles' => [$profile], 'contract_quantity' => 3, 'monthly_rate' => '100.00'],
]]);
$legacy['financial'] = FinancialSummary::build($legacy);
$legacySheet = compositionSheet($legacy, 'financial');
$legacyTotal = compositionRow($legacySheet, 'Total contratual');
compositionCheck($legacyTotal[5]['value'] === 300.0 && str_starts_with($legacyTotal[5]['formula'], 'SUM('), 'Legacy custom contract still uses automatic total');
$legacyLine = compositionRow($legacySheet, $profile);
compositionCheck($legacyLine[3]['value'] === 3 && $legacyLine[4]['value'] === 100.0, 'Legacy aggregate quantity and rate are preserved');
echo "Contract composition exports: $checks assertions passed.\n";
