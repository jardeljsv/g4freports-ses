<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\OfficePackage;
use GlpiPlugin\G4freports\Ses\RegisterExporter;
use GlpiPlugin\G4freports\Ses\RegisterImporter;
use GlpiPlugin\G4freports\Ses\StaffRegister;
use GlpiPlugin\G4freports\Ses\Settings;

$assertions = 0;
function importCheck(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function importReject(callable $call, string $message, string $contains = ''): void
{
    try { $call(); } catch (InvalidArgumentException $e) {
        importCheck($contains === '' || str_contains($e->getMessage(), $contains), $message . ': ' . $e->getMessage());
        return;
    }
    importCheck(false, $message);
}
/** Edit the same workbook a user receives, keeping real packaging and cell types. */
function importCells(string $binary, array $edits): string
{
    $parts = OfficePackage::read($binary);
    foreach ($edits as $path => $cells) {
        $doc = new DOMDocument();
        $doc->loadXML($parts[$path]);
        $xp = new DOMXPath($doc);
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xp->registerNamespace('s', $ns);
        foreach ($cells as $ref => $spec) {
            [$value, $type] = is_array($spec) ? $spec : [$spec, 'inlineStr'];
            $cell = $xp->query('//s:c[@r="' . $ref . '"]')->item(0);
            if (!$cell instanceof DOMElement) throw new RuntimeException('Test cell missing: ' . $path . '!' . $ref);
            while ($cell->firstChild) $cell->removeChild($cell->firstChild);
            $cell->setAttribute('t', $type);
            if ($type === 'inlineStr') {
                $is = $doc->createElementNS($ns, 'is');
                $text = $doc->createElementNS($ns, 't');
                $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $text->appendChild($doc->createTextNode((string)$value));
                $is->appendChild($text); $cell->appendChild($is);
            } else {
                $node = $doc->createElementNS($ns, 'v');
                $node->appendChild($doc->createTextNode((string)$value)); $cell->appendChild($node);
            }
        }
        $parts[$path] = $doc->saveXML();
    }
    return OfficePackage::write($parts);
}
function importStaff(array $rows, string $month = '2026-06'): string
{
    return RegisterExporter::export('staff', $rows, 'all', $month);
}
function importPerson(array $rows, string $id): array
{
    foreach ($rows as $row) if ($row['id'] === $id) return $row;
    throw new RuntimeException('Test person missing: ' . $id);
}

$fronts = ['ses_n2', 'ses_monitoramento'];
$current = StaffRegister::rows([
    ['id' => '00023', 'name' => 'Ana Maria', 'profile' => 'Analista', 'admission_date' => '2025-01-15',
        'departure_date' => null, 'monthly_value' => '1234.56', 'not_billed' => false, 'record_reference' => '00111',
        'departure_reason' => 'replacement', 'service_front' => 'ses_n2',
        'billing_days_overrides' => ['2026-05' => 15, '2026-06' => 0, '2026-07' => 22]],
    ['id' => '00024', 'name' => 'Bruno Santos', 'profile' => 'Analista', 'admission_date' => '2026-06-10',
        'departure_date' => '2026-06-23', 'monthly_value' => '4321.09', 'billed' => true, 'record_reference' => '00999',
        'departure_reason' => 'other', 'service_front' => 'ses_monitoramento', 'billing_days_overrides' => ['2026-06' => 12]],
    ['id' => 'staff3', 'name' => 'Carlos Silva', 'profile' => 'Técnico', 'admission_date' => '2020-01-01',
        'monthly_value' => '3000.00', 'not_billed' => false],
]);
$original = $current;
$book = importStaff($current);
foreach (['', '2026-06', '2026-07'] as $month) {
    $result = RegisterImporter::preview('staff', importStaff($current, $month), $current, $fronts);
    importCheck($result['rows'] === $current, 'Round trip preserves every original staff field for month ' . $month);
    importCheck($result['summary'] === ['added' => 0, 'updated' => 0, 'unchanged' => 3, 'duplicates' => 0]
        && $result['changes'] === [], 'Round trip does not invent changes');
}
$fromEmpty = RegisterImporter::preview('staff', $book, [], $fronts);
importCheck($fromEmpty['rows'] === $current && $fromEmpty['summary']['added'] === 3,
    'Complete export can restore all values into an empty register');
importCheck($current === $original, 'Preview never mutates its caller input');

$changedBook = importCells($book, ['xl/worksheets/sheet1.xml' => ['G4' => ['2000.25', 'n'], 'F4' => 'Sim', 'P4' => ['2', 'n'], 'Q4' => 'ses_monitoramento']]);
$changed = RegisterImporter::preview('staff', $changedBook, $current, $fronts);
$ana = importPerson($changed['rows'], '00023');
importCheck($changed['summary']['updated'] === 1 && count($changed['rows']) === 3
    && $ana['monthly_value'] === '2000.25' && $ana['not_billed'] === true && $ana['service_front'] === 'ses_monitoramento',
    'Edits update only the matched staff row');
importCheck($ana['billing_days_overrides'] === ['2026-05' => 15, '2026-06' => 2, '2026-07' => 22]
    && $ana['departure_reason'] === 'replacement', 'Selected month edit preserves other months and hidden metadata');
importCheck(RegisterImporter::preview('staff', $changedBook, $changed['rows'], $fronts)['summary']['updated'] === 0,
    'Reimporting the same changes is idempotent');

$partial = RegisterImporter::preview('staff', importStaff([$current[0]]), $current, $fronts);
importCheck($partial['rows'] === $current && $partial['retained'] === 2, 'A partial workbook never removes omitted professionals');
$omittedMonths = $current[0]; $omittedMonths['billing_days_overrides'] = ['2026-06' => 0];
importCheck(RegisterImporter::preview('staff', importStaff([$omittedMonths]), $current, $fronts)['rows'] === $current,
    'Months absent from the file keep their saved overrides');
$cleared = RegisterImporter::preview('staff', importCells($book, ['xl/worksheets/sheet1.xml' => ['P4' => '']]), $current, $fronts);
importCheck(importPerson($cleared['rows'], '00023')['billing_days_overrides'] === ['2026-05' => 15, '2026-07' => 22],
    'Blank selected-month input clears that month alone');
$clearedOther = RegisterImporter::preview('staff', importCells($book, ['xl/worksheets/sheet3.xml' => ['C2' => '']]), $current, $fronts);
importCheck(importPerson($clearedOther['rows'], '00023')['billing_days_overrides'] === ['2026-06' => 0, '2026-07' => 22],
    'Blank auxiliary input clears its named month alone');
$changedDerived = importCells($book, ['xl/worksheets/sheet1.xml' => ['A4' => ['99', 'n'], 'L4' => ['99', 'n'], 'M4' => ['999999.00', 'n'], 'N4' => 'Inativo']]);
importCheck(RegisterImporter::preview('staff', $changedDerived, $current, $fronts)['rows'] === $current,
    'Presentation index and calculated billing/status cannot override source fields');

$duplicated = array_merge($current, [$current[0]]);
$duplicates = RegisterImporter::preview('staff', importStaff($duplicated), $current, $fronts);
importCheck($duplicates['rows'] === $current && $duplicates['summary']['duplicates'] === 1
    && $duplicates['summary']['unchanged'] === 3, 'Identical repeated staff rows are skipped once without losing the original population');
$duplicated[3]['monthly_value'] = '999.00';
importReject(fn() => RegisterImporter::preview('staff', importStaff($duplicated), $current, $fronts), 'Conflicting duplicates reject the entire import', 'repetidos');
importCheck($current === $original, 'Rejected import leaves source register unchanged');

$identityBase = [$current[2]];
$identityBook = importStaff($identityBase, '');
$noId = importCells($identityBook, ['xl/worksheets/sheet1.xml' => ['I4' => '']]);
importCheck(RegisterImporter::preview('staff', $noId, $current, $fronts)['rows'] === $current,
    'Blank staff ID resolves a unique natural employment match');
importReject(fn() => RegisterImporter::preview('staff', importCells($identityBook, ['xl/worksheets/sheet1.xml' => ['B4' => 'Outra pessoa']]), $current, $fronts),
    'Existing ID cannot silently move to another person', 'outro nome');
importReject(fn() => RegisterImporter::preview('staff', importCells($identityBook, ['xl/worksheets/sheet1.xml' => ['I4' => 'foreign-id']]), $current, $fronts),
    'New ID cannot duplicate an existing employment', 'Já existe vínculo');
$homonym = $current[2]; $homonym['id'] = 'homonym';
$ambiguous = StaffRegister::rows(array_merge($current, [$homonym]));
importReject(fn() => RegisterImporter::preview('staff', $noId, $ambiguous, $fronts), 'Ambiguous homonym match requires an explicit ID', 'ambíguo');
$separate = $current[2]; $separate['id'] = 'separate'; $separate['record_reference'] = 'outro-vinculo';
$separateResult = RegisterImporter::preview('staff', importStaff([$separate]), $current, $fronts);
importCheck(count($separateResult['rows']) === 4 && $separateResult['summary']['added'] === 1,
    'A genuinely distinct reference allows a second employment with the same name');

foreach ([
    ['D4', '', 'Missing admission'],
    ['D4', '31/02/2025', 'Impossible admission date'],
    ['E4', '01/01/2020', 'Departure before admission'],
    ['D4', ['60', 'n'], 'Fictional Excel leap day'],
    ['D4', ['45000.5', 'n'], 'Timestamp is not a whole employment date'],
    ['F4', 'talvez', 'Invalid exclusion flag'],
    ['P4', '31', 'Billing days beyond the June overlap'],
    ['G4', ['1.234', 'n'], 'Currency with excess precision'],
    ['I4', ['23', 'n'], 'Numeric identifier cannot lose leading zeros'],
    ['Q4', 'unknown-front', 'Unknown service front'],
] as [$cell, $value, $label]) {
    importReject(fn() => RegisterImporter::preview('staff', importCells($book, ['xl/worksheets/sheet1.xml' => [$cell => $value]]), $current, $fronts), $label);
}
importCheck(RegisterImporter::preview('staff', importCells($book, ['xl/worksheets/sheet1.xml' => ['D4' => '15/01/2025']]), $current, $fronts)['rows'] === $current,
    'Brazilian date text and exported Excel dates represent the same admission');
importReject(fn() => RegisterImporter::preview('staff', importCells($book, ['xl/worksheets/sheet1.xml' => ['B3' => 'Outro título']]), $current, $fronts),
    'Wrong columns require the exported template', 'Cabeçalhos');
importReject(fn() => RegisterImporter::preview('tests', $book, []), 'Staff workbook cannot be imported into the phone table');
importReject(fn() => RegisterImporter::preview('staff', importCells($book, ['xl/worksheets/sheet2.xml' => ['A1' => 'legacy-format']]), $current, $fronts),
    'Legacy or unrecognized format is refused');

$phones = Settings::testNumbers([
    ['number' => '00123', 'label' => 'Teste com zeros', 'active' => true],
    ['number' => '556199990000', 'label' => 'Telefone desativado', 'active' => false],
]);
$phoneBook = RegisterExporter::export('tests', $phones);
importCheck(RegisterImporter::preview('tests', $phoneBook, $phones)['rows'] === $phones,
    'Phone round trip preserves all leading zeros and both exclusion states');
importCheck(RegisterImporter::preview('tests', $phoneBook, [])['rows'] === $phones,
    'Phone file can restore the entire register');
$formatted = [['number' => '00 123', 'label' => 'Teste com zeros', 'active' => true],
    ['number' => '+55 (61) 9999-0000', 'label' => 'Telefone desativado', 'active' => false],
    ['number' => '00123', 'label' => 'Teste com zeros', 'active' => true]];
$phoneDuplicate = RegisterImporter::preview('tests', RegisterExporter::export('tests', $formatted), $phones);
importCheck($phoneDuplicate['rows'] === $phones && $phoneDuplicate['summary']['duplicates'] === 1,
    'Formatting-equivalent phone duplicates are counted and skipped without stripping leading zeros');
$formatted[2]['active'] = false;
importReject(fn() => RegisterImporter::preview('tests', RegisterExporter::export('tests', $formatted), $phones),
    'Conflicting phone duplicate flags reject the import', 'repetidos');
$phoneChange = importCells($phoneBook, ['xl/worksheets/sheet1.xml' => ['B4' => 'Novo motivo', 'C4' => 'Não']]);
$phoneUpdated = RegisterImporter::preview('tests', $phoneChange, $phones);
importCheck($phoneUpdated['summary']['updated'] === 1 && $phoneUpdated['rows'][0]['number'] === '00123'
    && $phoneUpdated['rows'][0]['label'] === 'Novo motivo' && $phoneUpdated['rows'][0]['active'] === false,
    'Phone update retains identity while editing its description and exclusion');
importCheck(RegisterImporter::preview('tests', $phoneChange, $phoneUpdated['rows'])['summary']['updated'] === 0,
    'Repeated phone import is idempotent');
importCheck(RegisterImporter::preview('tests', RegisterExporter::export('tests', [$phones[0]]), $phones)['rows'] === $phones,
    'Omitted phone entries stay in the register');
$distinctNumber = ['number' => '123', 'label' => 'Outro número', 'active' => true];
$phoneAdded = RegisterImporter::preview('tests', RegisterExporter::export('tests', [$distinctNumber]), $phones);
importCheck(count($phoneAdded['rows']) === 3 && $phoneAdded['summary']['added'] === 1,
    'Phone values 00123 and 123 remain distinct exact caller-number identities');
importReject(fn() => RegisterImporter::preview('tests', importCells($phoneBook, ['xl/worksheets/sheet1.xml' => ['A4' => ['123', 'n']]]), $phones),
    'Numeric phone cell is refused before it can erase leading zeros');
importReject(fn() => RegisterImporter::preview('tests', importCells($phoneBook, ['xl/worksheets/sheet1.xml' => ['C4' => 'talvez']]), $phones),
    'Invalid phone exclusion flag is rejected');
importCheck(RegisterImporter::preview('tests', RegisterExporter::export('tests', []), $phones)['rows'] === $phones,
    'An empty import never clears the existing table');

importReject(fn() => RegisterImporter::preview('staff', importCells($book, ['xl/worksheets/sheet1.xml' => ['B7' => 'Pessoa colocada na linha de total']]), $current, $fronts),
    'Malformed total row is not silently skipped');
importReject(fn() => RegisterImporter::preview('tests', importCells($phoneBook, ['xl/worksheets/sheet1.xml' => ['B6' => 'Registro na linha errada']]), $phones),
    'Phone data placed in the total row is refused');

// Spreadsheet writers may serialize the same numeric value using decimal or exponent notation.
$numericRows = StaffRegister::rows([['id' => 'numeric1', 'name' => 'Ana', 'profile' => 'Técnico',
    'admission_date' => '2000-01-01', 'monthly_value' => '1000.00', 'not_billed' => false,
    'billing_days_overrides' => ['2026-05' => 1, '2026-06' => 1]]]);
$numericBook = importStaff($numericRows);
foreach (['1E+3', '+0001.000E0003', '1000.0000', '1' . str_repeat('0', 1000) . 'E-997'] as $value) {
    $edited = importCells($numericBook, ['xl/worksheets/sheet1.xml' => ['G4' => [$value, 'n']]]);
    importCheck(RegisterImporter::preview('staff', $edited, $numericRows)['rows'] === $numericRows,
        'Exact numeric money serialization preserves 1000.00: ' . substr($value, 0, 40));
}
foreach (['1.2345e3' => '1234.50', '9.999999999e7' => '99999999.99', '.1E-1' => '0.01', '0e99999999999999999999' => '0.00'] as $value => $expected) {
    $edited = importCells($numericBook, ['xl/worksheets/sheet1.xml' => ['G4' => [$value, 'n']]]);
    importCheck(RegisterImporter::preview('staff', $edited, $numericRows)['rows'][0]['monthly_value'] === $expected,
        'Numeric money uses exact decimal shifts without rounding: ' . $value);
}
foreach (['3.6526E+4', '+36526.000'] as $value) {
    $edited = importCells($numericBook, ['xl/worksheets/sheet1.xml' => ['D4' => [$value, 'n']]]);
    importCheck(RegisterImporter::preview('staff', $edited, $numericRows)['rows'] === $numericRows,
        'Integral numeric date retains the employment calendar date');
}
foreach (['1E+0', '+01.000', '.1e1'] as $value) {
    $edited = importCells($numericBook, ['xl/worksheets/sheet1.xml' => ['P4' => [$value, 'n']],
        'xl/worksheets/sheet3.xml' => ['C2' => [$value, 'n']]]);
    importCheck(RegisterImporter::preview('staff', $edited, $numericRows)['rows'] === $numericRows,
        'Main and auxiliary numeric day counts accept exactly integral values');
}
foreach ([['G4', '1.005e0'], ['G4', '1e8'], ['G4', '1e-999999999'], ['G4', '-0'], ['G4', '1e999999999'],
    ['D4', '3.65265e4'], ['D4', '6E1'], ['D4', '-0'], ['P4', '1.1E0'], ['P4', '3.2e1'], ['P4', '-0']] as [$cell, $value]) {
    importReject(fn() => RegisterImporter::preview('staff', importCells($numericBook,
        ['xl/worksheets/sheet1.xml' => [$cell => [$value, 'n']]]), $numericRows),
        'Numeric normalization must not round, underflow, overflow or accept invalid dates/days: ' . $cell . '=' . $value);
}
foreach (['1E3', '1000.000'] as $value) {
    importReject(fn() => RegisterImporter::preview('staff', importCells($numericBook,
        ['xl/worksheets/sheet1.xml' => ['G4' => $value]]), $numericRows), 'Text money retains the existing strict grammar');
}
importCheck(RegisterImporter::preview('staff', importCells($numericBook,
    ['xl/worksheets/sheet1.xml' => ['G4' => 'R$ 1.000,00']]), $numericRows)['rows'] === $numericRows,
    'Existing Brazilian text money remains accepted without new rounding');
importReject(fn() => RegisterImporter::preview('staff', importCells($numericBook,
    ['xl/worksheets/sheet1.xml' => ['I4' => ['1E3', 'n']]]), $numericRows), 'Numeric normalization never applies to stable IDs');
importReject(fn() => RegisterImporter::preview('tests', importCells($phoneBook,
    ['xl/worksheets/sheet1.xml' => ['A4' => ['1E3', 'n']]]), $phones), 'Numeric normalization never applies to phone numbers');

foreach (['2000-01-01', '2000-01-01T00:00:00', '2000-01-01T00:00:00.000',
    '2000-01-01T00:00:00Z', '2000-01-01T00:00:00.000+00:00'] as $value) {
    $edited = importCells($numericBook, ['xl/worksheets/sheet1.xml' => ['D4' => [$value, 'd']]]);
    importCheck(RegisterImporter::preview('staff', $edited, $numericRows)['rows'] === $numericRows,
        'ISO calendar dates and unambiguous midnight values preserve the same day: ' . $value);
}
foreach (['2000-01-01T00:00:00.001', '2000-01-01T00:00:01Z', '2000-01-01T24:00:00'] as $value) {
    importReject(fn() => RegisterImporter::preview('staff', importCells($numericBook,
        ['xl/worksheets/sheet1.xml' => ['D4' => [$value, 'd']]]), $numericRows), 'ISO times cannot be silently truncated', 'meia-noite');
}
foreach (['2000-01-01T00:00:00+01:00', '2000-01-01T00:00:00-03:00', '2000-01-01T00:00:00-00:00'] as $value) {
    importReject(fn() => RegisterImporter::preview('staff', importCells($numericBook,
        ['xl/worksheets/sheet1.xml' => ['D4' => [$value, 'd']]]), $numericRows), 'Offsets never shift or ambiguously reinterpret employment days', 'fuso');
}
foreach (['2000-02-30T00:00:00Z', '1900-02-29T00:00:00', '2101-01-01', '2000-01-01T00:00:00 UTC'] as $value) {
    importReject(fn() => RegisterImporter::preview('staff', importCells($numericBook,
        ['xl/worksheets/sheet1.xml' => ['D4' => [$value, 'd']]]), $numericRows), 'Invalid or noncanonical ISO dates are refused', 'ISO');
}
importReject(fn() => RegisterImporter::preview('staff', importCells($numericBook,
    ['xl/worksheets/sheet1.xml' => ['D4' => '2000-01-01T00:00:00']]), $numericRows), 'Text dates retain their original grammar');
$date1904 = OfficePackage::read(importCells($numericBook, ['xl/worksheets/sheet1.xml' => ['D4' => ['3.5064E4', 'n']]]));
$date1904['xl/workbook.xml'] = str_replace('<sheets>', '<workbookPr date1904="1"/><sheets>', $date1904['xl/workbook.xml']);
importCheck(RegisterImporter::preview('staff', OfficePackage::write($date1904), $numericRows)['rows'] === $numericRows,
    'Exponent dates retain the workbook 1904 date system');

// Every valid saved month is exportable, including a near-limit register; reader budgets stay unchanged.
$allMonths = [];
for ($year = 2000; $year <= 2100; $year++) for ($m = 1; $m <= 12; $m++) $allMonths[sprintf('%04d-%02d', $year, $m)] = 1;
$longHistory = $numericRows[0];
$longHistory['billing_days_overrides'] = array_slice($allMonths, 0, 241, true);
$longHistory = StaffRegister::rows([$longHistory]);
importCheck(RegisterImporter::preview('staff', importStaff($longHistory), [])['rows'] === $longHistory,
    'A saved register containing 241 overrides exports and restores every month');
$nearLimit = [];
for ($i = 0; $i < 4; $i++) $nearLimit[] = ['id' => str_repeat('a', 63) . $i, 'name' => 'P' . $i, 'profile' => 'T',
    'admission_date' => '1900-01-01', 'monthly_value' => '0.00', 'not_billed' => false, 'billing_days_overrides' => $allMonths];
$remainingBytes = 60000 - strlen(StaffRegister::json(StaffRegister::rows($nearLimit)));
foreach ($nearLimit as &$historyRow) {
    $padding = min(299, $remainingBytes);
    $historyRow['profile'] .= str_repeat('T', $padding);
    $remainingBytes -= $padding;
}
unset($historyRow);
$nearLimit = StaffRegister::rows($nearLimit);
importCheck(strlen(StaffRegister::json($nearLimit)) === 60000 && count($allMonths) === 1212,
    'Fixture reaches the exact 60 KB storage limit and covers every permitted month');
foreach (['', '2026-06'] as $month) {
    $historyBook = importStaff($nearLimit, $month);
    importCheck(RegisterImporter::preview('staff', $historyBook, [])['rows'] === $nearLimit,
        'Near-limit export restores all 4,848 overrides within existing XLSX reader limits');
    importCheck(RegisterImporter::preview('staff', $historyBook, $nearLimit)['summary'] === ['added' => 0, 'updated' => 0, 'unchanged' => 4, 'duplicates' => 0],
        'Near-limit history reimport remains idempotent');
}

echo 'Register import checks: ' . $assertions . " assertions passed.\n";
