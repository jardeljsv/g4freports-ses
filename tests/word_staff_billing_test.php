<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Word monthly roster and financial projection preserve calculated source data. */
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\DocxExporter;
use GlpiPlugin\G4freports\Ses\StaffRegister;

$assertions = 0;
function wordBillingVerify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function wordSection(string $method, ...$args): string
{
    return (new ReflectionMethod(DocxExporter::class, $method))->invoke(new DocxExporter(), ...$args);
}
function wordXml(string $xml): DOMXPath
{
    $doc = new DOMDocument();
    wordBillingVerify($doc->loadXML('<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $xml . '</root>'), 'Section contains valid WordprocessingML');
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    return $xpath;
}

$rows = [];
foreach ([
    ['A Integral', '2026-01-01', '', false],
    ['B Admission', '2026-06-16', '', false],
    ['C Departure', '2026-01-01', '2026-06-02', false],
    ['D Recent exit', '2026-01-01', '2026-04-01', false],
    ['E Old exit', '2026-01-01', '2026-03-31', false],
    ['F Future hire', '2026-07-01', '', false],
    ['G Future exit', '2026-01-01', '2026-08-31', false],
    ['H Excluded old', '2026-01-01', '2026-03-31', true],
    ['I Excluded active', '2026-01-01', '', true],
] as $index => [$name, $admission, $departure, $excluded]) {
    $rows[] = ['id' => 'case-' . $index, 'name' => $name, 'profile' => 'Técnico de suporte',
        'admission_date' => $admission, 'departure_date' => $departure, 'not_billed' => $excluded,
        'monthly_value' => '30.00'];
}
$staff = StaffRegister::summarize($rows, '2026-06');
$original = $staff;
$xml = wordSection('staffRegister', $staff);
$xpath = wordXml($xml);
$tables = $xpath->query('//w:tbl');
wordBillingVerify($tables->length === 2, 'Only compact monthly roster and relevant exclusions remain');
$roster = $xpath->query('./w:tr', $tables->item(0));
$expected = [
    ['A Integral', '30', 'R$ 30,00'],
    ['B Admission', '15', 'R$ 15,00'],
    ['C Departure', '2', 'R$ 2,00'],
    ['G Future exit', '30', 'R$ 30,00'],
    ['I Excluded active', '0', 'R$ 0,00'],
];
wordBillingVerify($roster->length === 7, 'Five monthly staff include partially billed leaver and excluded active person');
foreach ($expected as $index => [$name, $days, $amount]) {
    $cells = $xpath->query('./w:tc', $roster->item($index + 1));
    wordBillingVerify($cells->length === 5, 'Short roster has five readable columns');
    wordBillingVerify($cells->item(0)->textContent === (string)($index + 1) && $cells->item(1)->textContent === $name,
        'Display index is sequential without changing staff identity: ' . $name);
    wordBillingVerify($cells->item(3)->textContent === $days && $cells->item(4)->textContent === $amount,
        'Effective monthly billing appears in the report: ' . $name);
}
$total = $xpath->query('./w:tc', $roster->item(6));
wordBillingVerify($total->item(1)->textContent === '77' && $total->item(2)->textContent === 'R$ 77,00', 'Roster totals equal the displayed people');
wordBillingVerify($xpath->query('./w:tc[1]/w:tcPr/w:gridSpan[@w:val="3"]', $roster->item(6))->length === 1, 'Roster total label spans narrow index/name/profile columns');
foreach (['D Recent exit', 'E Old exit', 'F Future hire', 'H Excluded old'] as $name) {
    wordBillingVerify(!str_contains($xml, $name), 'Outside-month professional does not pollute final list: ' . $name);
}
$excluded = $xpath->query('./w:tr', $tables->item(1));
wordBillingVerify($excluded->length === 3 && $excluded->item(1)->textContent === 'I Excluded activeSim', 'Exclusion evidence is restricted to the same competence');
wordBillingVerify(str_contains($xml, 'Profissional desconsiderado? (Não Faturado)'), 'Word preserves explanatory exclusion wording');
wordBillingVerify($staff === $original && count($staff['rows']) === 9, 'Word never mutates the saved historical register');
wordBillingVerify($staff['numerator'] === 2 && $staff['denominator'] === 4, 'INS10 counts every billed person in the month, including the June departure');
foreach ($tables as $table) {
    $width = 0;
    foreach ($xpath->query('./w:tblGrid/w:gridCol', $table) as $column) $width += (int)$column->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w');
    wordBillingVerify($width === 9500, 'Table fits portrait G4F content width');
    wordBillingVerify($xpath->query('./w:tr[1]/w:trPr/w:tblHeader', $table)->length === 1, 'Long tables repeat headers');
}
$departures = wordSection('staffDepartures', $staff);
$departureXpath = wordXml($departures);
wordBillingVerify(str_contains($departures, 'D Recent exit') && str_contains($departures, 'C Departure'), 'Three-month departures are retained in INS10');
wordBillingVerify(!str_contains($departures, 'Motivo') && !str_contains($departures, 'I Excluded active'), 'No unused departure reason or excluded person in INS10 exit list');
wordBillingVerify($departureXpath->query('//w:tbl/w:tr[1]/w:tc')->length === 3, 'Departure table has the requested three columns');
wordBillingVerify($departureXpath->query('//w:tbl//w:jc[not(@w:val="center")]')->length === 0, 'Departure table content is centered');
wordBillingVerify(wordSection('displayPeriod', ['month' => '2026-06']) === '01/06/2026 00:00 a 30/06/2026 23:59', 'Visible period ends on last day at23:59');
wordBillingVerify(wordSection('displayPeriod', ['month' => '2028-02']) === '01/02/2028 00:00 a 29/02/2028 23:59', 'Leap-month visible period remains correct');

$metric = ['id' => 'INS11', 'numerator' => 6, 'denominator' => 8, 'value' => 75, 'status' => 'unmet'];
$proxy = wordSection('metricNarrative', $metric, ['input' => ['kb_mode' => 'category_proxy']]);
$direct = wordSection('metricNarrative', $metric, ['input' => ['kb_mode' => 'direct_validated']]);
wordBillingVerify(str_contains($proxy, 'categoria associada') && !str_contains($proxy, 'vínculo direto'), 'Category proxy is not described as directly linked article');
wordBillingVerify(str_contains($direct, 'vínculo direto') && !str_contains($direct, 'categoria associada'), 'Direct mode has matching description');
$financial = ['available' => true, 'complete' => true, 'gross_value' => '1000.00', 'deduction_total' => '20.00', 'adjustment_total' => '-5.00', 'net_value' => '975.00',
    'calculated_rate_total' => 6, 'applied_rate_total' => 2,
    'deductions' => [
        ['id' => 'INS3', 'target' => 90, 'direction' => 'gte', 'value' => 16.67, 'calculated_rate' => 4, 'applied_rate' => 0, 'amount' => '0.00', 'override' => true, 'justification' => 'Ajuste aprovado e informado pela gestão'],
        ['id' => 'INS10', 'target' => 10, 'direction' => 'lte', 'value' => 11.59, 'calculated_rate' => 1, 'applied_rate' => 1, 'amount' => '10.00', 'override' => false, 'justification' => ''],
    ], 'adjustments' => [['description' => 'Regularização anterior', 'amount' => '-5.00']], 'notes' => []];
$financialOriginal = $financial;
$financeXml = wordSection('financialSummary', $financial);
wordXml($financeXml);
wordBillingVerify(str_contains($financeXml, '16,67%') && str_contains($financeXml, '4,00%') && str_contains($financeXml, '0,00%'), 'Applied financial override does not silently change raw metric or calculated rate');
wordBillingVerify(str_contains($financeXml, 'Ajuste aprovado e informado pela gestão') && str_contains($financeXml, 'R$ -5,00') && str_contains($financeXml, 'R$ 975,00'), 'Manual justification, signed adjustment and supplied net value appear');
wordBillingVerify($financial === $financialOriginal, 'Word renders one frozen financial calculation without recomputing it');
// A chart must end its block; keeping it with the next indicator creates blank pages.
$exporter = new DocxExporter();
(new ReflectionProperty(DocxExporter::class, 'charts'))->setValue($exporter, new \GlpiPlugin\G4freports\Ses\WordChart());
$figure = (new ReflectionMethod(DocxExporter::class, 'figure'))->invoke($exporter, [
    'id' => 'INS1', 'title' => 'Meta × Resultado', 'available' => true, 'kind' => 'column', 'unit' => 'percent',
    'categories' => ['INS1'], 'series' => [
        ['name' => 'Meta', 'values' => [0.9], 'color' => '041B2E'],
        ['name' => 'Resultado', 'values' => [1.0], 'color' => '3866FF'],
    ], 'note' => '',
], 2800, false);
wordBillingVerify(!str_contains($figure, '<w:keepNext/>'), 'A chart ends the metric pagination chain');
wordBillingVerify(!str_contains($figure, '<w:tbl>'), 'Charts do not append duplicate composition tables');
$twiceKept = wordSection('keepWithNext', wordSection('keepWithNext', '<w:p><w:pPr><w:spacing w:after="10"/></w:pPr></w:p>'));
wordBillingVerify(substr_count($twiceKept, '<w:keepNext/>') === 1, 'Keep-with-next helper does not emit duplicate OOXML properties');
echo "Word staff billing: {$assertions} assertions passed.\n";
