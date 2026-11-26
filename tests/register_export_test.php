<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\OfficePackage;
use GlpiPlugin\G4freports\Ses\RegisterExporter;

$assertions = 0;
function registerExportVerify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function registerExportRead(string $binary): array
{
    $parts = OfficePackage::read($binary);
    registerExportVerify(isset($parts['xl/worksheets/sheet2.xml']) && in_array(count($parts), [7, 8], true),
        'Native XLSX contains the register and interchange control, plus staff monthly adjustments');
    foreach ($parts as $name => $xml) {
        $dom = new DOMDocument();
        registerExportVerify($dom->loadXML($xml), 'Valid XML: ' . $name);
    }
    $dom = new DOMDocument();
    $dom->loadXML($parts['xl/worksheets/sheet1.xml']);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    registerExportVerify($xp->evaluate('string(//s:sheetView/@showGridLines)') === '0', 'Standalone gridlines are hidden');
    registerExportVerify($xp->query('//s:f')->length === 0, 'Input values can never execute as formulas');
    registerExportVerify($xp->evaluate('string(//s:pane/@ySplit)') === '3', 'Headers remain frozen');
    registerExportVerify($xp->evaluate('string(/s:worksheet/s:sheetPr/s:pageSetUpPr/@fitToPage)') === '1'
        && $xp->evaluate('string(/s:worksheet/s:pageSetup/@fitToWidth)') === '1',
        'Print settings fit the complete table onto one horizontal page');
    return [$parts, $xp];
}
function registerExportText(DOMXPath $xp, string $cell): string
{
    return $xp->evaluate('string(//s:c[@r="' . $cell . '"])');
}
function registerExportPart(array $parts, string $path): DOMXPath
{
    $dom = new DOMDocument();
    $dom->loadXML($parts[$path]);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    return $xp;
}
function registerExportReject(callable $call, string $message): void
{
    try { $call(); } catch (InvalidArgumentException $e) { registerExportVerify(true, $message); return; }
    registerExportVerify(false, $message);
}

$rows = [
    ['id' => '00023', 'name' => '=HYPERLINK("https://example.invalid", "Test")', 'profile' => 'Técnico <N1> & suporte',
        'admission_date' => '2026-01-12', 'departure_date' => '2026-06-03', 'departure_reason' => 'pending',
        'monthly_value' => '5.980,99', 'record_reference' => '001234', 'billed' => true, 'ignored_field' => 'NEVER_EXPORT',
        'service_front' => 'ses_n2', 'billing_days_overrides' => ['2026-05' => 0, '2026-06' => 2]],
    ['id' => '00024', 'name' => '+123', 'profile' => '@text', 'admission_date' => 'incompleta', 'departure_date' => '',
        'departure_reason' => 'other', 'monthly_value' => 'valor ainda incompleto', 'record_reference' => '-123',
        'billed' => true, 'not_billed' => false],
    ['id' => 'draft', 'name' => '', 'profile' => '', 'admission_date' => '', 'monthly_value' => ''],
];
$original = $rows;
[$parts, $staff] = registerExportRead(RegisterExporter::export('staff', $rows, 'all'));
registerExportVerify($rows === $original, 'Export never mutates source data or saves it');
registerExportVerify(registerExportText($staff, 'F3') === 'Não Faturar', 'New checkbox label exported');
registerExportVerify(registerExportText($staff, 'F4') === 'Sim', 'Original checked legacy marks remain excluded');
registerExportVerify(registerExportText($staff, 'F5') === 'Não', 'Explicit canonical flag has precedence');
registerExportVerify(registerExportText($staff, 'F6') === 'Não', 'Missing flag defaults to included');
registerExportVerify(registerExportText($staff, 'B4') === $rows[0]['name'], 'Formula-looking name preserved as literal text');
registerExportVerify($staff->evaluate('string(//s:c[@r="B4"]/@t)') === 'inlineStr', 'Name is a text cell');
registerExportVerify(registerExportText($staff, 'C4') === $rows[0]['profile'], 'XML metacharacters and accents preserved');
registerExportVerify(registerExportText($staff, 'I4') === '00023', 'Leading-zero identifiers preserved');
registerExportVerify(registerExportText($staff, 'H4') === '001234', 'Employment references remain text');
registerExportVerify(registerExportText($staff, 'A4') === '1' && registerExportText($staff, 'A5') === '2', 'Roster has a presentation index');
registerExportVerify(!str_contains($parts['xl/worksheets/sheet1.xml'], 'Motivo da saída'), 'Optional departure reasons are not presented');
registerExportVerify(registerExportText($staff, 'G4') === '5980.99', 'Brazilian money becomes numeric currency');
registerExportVerify($staff->evaluate('string(//s:c[@r="D4"]/@t)') === 'n', 'Valid dates become Excel dates');
registerExportVerify(registerExportText($staff, 'D5') === 'incompleta', 'Incomplete date text remains exportable');
registerExportVerify(registerExportText($staff, 'G5') === 'valor ainda incompleto', 'Incomplete money text is preserved');
registerExportVerify(registerExportText($staff, 'G6') === '', 'Blank money is not converted to zero');
registerExportVerify(registerExportText($staff, 'B7') === '3', 'Total includes all entered rows including incomplete draft');
registerExportVerify($staff->evaluate('string(//s:autoFilter/@ref)') === 'A3:J6', 'Excel filter contains only header and data');
registerExportVerify(!str_contains($parts['xl/worksheets/sheet1.xml'], 'NEVER_EXPORT'), 'Unknown fields are not exported');
registerExportVerify(str_contains(registerExportText($staff, 'A2'), 'incluindo alterações ainda não salvas'), 'Export identifies current entered values');
registerExportVerify(registerExportText($staff, 'J3') === 'Frente de serviço'
    && registerExportText($staff, 'J4') === 'ses_n2', 'Service-front assignment is exported without a selected month');
$workbook = registerExportPart($parts, 'xl/workbook.xml');
registerExportVerify($workbook->evaluate('string(//s:sheet[@name="Controle"]/@state)') === 'hidden'
    && $workbook->query('//s:sheet[@name="Dias por competência"]')->length === 1, 'Control is hidden while monthly overrides stay editable');
$control = registerExportPart($parts, 'xl/worksheets/sheet2.xml');
registerExportVerify(registerExportText($control, 'A1') === 'g4freports-register-v1'
    && registerExportText($control, 'B1') === 'staff' && registerExportText($control, 'C1') === '', 'Import format and register kind are explicit');
registerExportVerify(registerExportText($control, 'A3') === '00023' && registerExportText($control, 'B3') === 'pending'
    && registerExportText($control, 'A4') === '00024' && registerExportText($control, 'B4') === 'other'
    && registerExportText($control, 'B5') === 'pending', 'Legacy departure metadata follows stable IDs and blank defaults stay pending');
$overrides = registerExportPart($parts, 'xl/worksheets/sheet3.xml');
registerExportVerify(registerExportText($overrides, 'A2') === '00023' && registerExportText($overrides, 'B2') === '2026-05'
    && registerExportText($overrides, 'C2') === '0' && registerExportText($overrides, 'B3') === '2026-06'
    && registerExportText($overrides, 'C3') === '2', 'Every month and explicit zero survives export without a selected month');
registerExportVerify($overrides->evaluate('string(//s:pane/@ySplit)') === '1'
    && $overrides->query('//s:f')->length === 0, 'Auxiliary headers stay frozen and values never become formulas');
[$monthlyParts, $monthly] = registerExportRead(RegisterExporter::export('staff', $rows, 'all', '2026-06'));
$monthlyOverrides = registerExportPart($monthlyParts, 'xl/worksheets/sheet3.xml');
$monthlyControl = registerExportPart($monthlyParts, 'xl/worksheets/sheet2.xml');
registerExportVerify(registerExportText($monthly, 'P4') === '2' && registerExportText($monthlyControl, 'C1') === '2026-06'
    && registerExportText($monthlyOverrides, 'B2') === '2026-05' && $monthlyOverrides->query('//s:row')->length === 2,
    'Selected month has exactly one editable source and other months remain in their own sheet');
[, $draftMonthly] = registerExportRead(RegisterExporter::export('staff', [[
    'id' => 'incomplete', 'admission_date' => 'incompleta', 'billing_days_overrides' => ['2026-06' => 3],
]], 'all', '2026-06'));
registerExportVerify(registerExportText($draftMonthly, 'P4') === '3' && registerExportText($draftMonthly, 'L4') === '',
    'Incomplete draft retains the entered override even when billing cannot be calculated');
[, $longText] = registerExportRead(RegisterExporter::export('staff', [['name' => str_repeat('Nome completo ', 10),
    'profile' => str_repeat('Perfil profissional detalhado ', 10)]]));
registerExportVerify((float)$longText->evaluate('string(//s:row[@r="4"]/@ht)') > 32,
    'Long entered names and profiles expand the wrapped row height');

$phoneRows = [
    ['number' => '00800-012-3456', 'label' => 'Teste =1+1', 'active' => true],
    ['number' => '+55 (61) 09999-0000', 'label' => '@SUM(1,2)', 'active' => false],
    ['number' => '', 'label' => 'Rascunho', 'active' => true],
];
[$phoneParts, $phone] = registerExportRead(RegisterExporter::export('tests', json_encode($phoneRows), 'all'));
registerExportVerify(registerExportText($phone, 'A4') === $phoneRows[0]['number'], 'Leading zeros and formatting retained');
registerExportVerify(registerExportText($phone, 'A5') === $phoneRows[1]['number'], 'Phone plus prefix preserved');
registerExportVerify($phone->evaluate('string(//s:c[@r="A4"]/@t)') === 'inlineStr', 'Phone numbers never numeric cells');
registerExportVerify(registerExportText($phone, 'B5') === '@SUM(1,2)', 'Formula-looking descriptions stay literal');
registerExportVerify(registerExportText($phone, 'C4') === 'Sim' && registerExportText($phone, 'C5') === 'Não', 'Phone exclusion status retained');
registerExportVerify(registerExportText($phone, 'A6') === '', 'Incomplete phone draft exportable');

[, $filtered] = registerExportRead(RegisterExporter::export('tests', [$phoneRows[1]], 'filtered'));
registerExportVerify(registerExportText($filtered, 'A4') === $phoneRows[1]['number'] && registerExportText($filtered, 'B5') === '1', 'Filtered export includes only submitted selection');
registerExportVerify(str_contains(registerExportText($filtered, 'A2'), 'seleção filtrada'), 'Filtered scope is explicit');
[, $empty] = registerExportRead(RegisterExporter::export('staff', '[]', 'filtered'));
registerExportVerify(registerExportText($empty, 'F3') === 'Não Faturar' && registerExportText($empty, 'B4') === '0', 'Empty filtered export has headers and zero count');
registerExportVerify($empty->evaluate('string(//s:autoFilter/@ref)') === 'A3:J3', 'Empty filter range remains valid');

registerExportReject(fn() => RegisterExporter::export('wrong', []), 'Unknown register rejected');
registerExportReject(fn() => RegisterExporter::export('staff', [], 'wrong'), 'Unknown scope rejected');
registerExportReject(fn() => RegisterExporter::export('staff', '{"name":"x"}'), 'Object payload rejected');
registerExportReject(fn() => RegisterExporter::export('staff', '[invalid'), 'Invalid JSON rejected');
registerExportReject(fn() => RegisterExporter::export('staff', array_fill(0, 501, [])), 'Row limit enforced');
registerExportReject(fn() => RegisterExporter::export('tests', '["string"]'), 'Scalar row rejected');
registerExportReject(fn() => RegisterExporter::export('staff', [['name' => ['nested']]]), 'Nested fields rejected');
registerExportReject(fn() => RegisterExporter::export('staff', [['not_billed' => 'maybe']]), 'Invalid exclusion flag rejected');
registerExportReject(fn() => RegisterExporter::export('tests', [['active' => 'maybe']]), 'Invalid call exclusion rejected');
registerExportReject(fn() => RegisterExporter::export('staff', [['name' => str_repeat('A', 2049)]]), 'Text size limit enforced');
registerExportReject(fn() => RegisterExporter::export('staff', '[' . str_repeat(' ', RegisterExporter::MAX_BYTES) . ']'), 'JSON byte limit enforced');

// Optional local output supports consumer/recalculation QA without customer data.
if (isset($argv[1])) {
    if (!is_dir($argv[1]) && !mkdir($argv[1], 0770, true) && !is_dir($argv[1])) throw new RuntimeException('Output directory unavailable');
    file_put_contents($argv[1] . '/staff.xlsx', RegisterExporter::export('staff', $rows));
    file_put_contents($argv[1] . '/phones.xlsx', RegisterExporter::export('tests', $phoneRows));
    file_put_contents($argv[1] . '/empty.xlsx', RegisterExporter::export('staff', [], 'filtered'));
}
echo 'Standalone register XLSX export: ' . $assertions . " assertions passed.\n";
