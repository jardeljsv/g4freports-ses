<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\OfficePackage;
use GlpiPlugin\G4freports\Ses\RegisterExporter;
use GlpiPlugin\G4freports\Ses\RegisterWorkbookReader;

$assertions = 0;
function readerCheck(bool $ok, string $message): void
{
    global $assertions; $assertions++;
    if (!$ok) throw new RuntimeException($message);
}
function readerReject(callable $call, string $message): void
{
    try { $call(); } catch (InvalidArgumentException $e) { readerCheck(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function readerParts(string $cells = '<row r="1"><c r="A1" t="inlineStr"><is><t>Nome</t></is></c></row>'): array
{
    $s = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $p = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    return [
        '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
        '_rels/.rels' => '<Relationships xmlns="' . $p . '"><Relationship Id="root" Type="' . $r . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<workbook xmlns="' . $s . '" xmlns:r="' . $r . '"><sheets><sheet name="Cadastro" sheetId="1" r:id="sheet1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="' . $p . '"><Relationship Id="sheet1" Type="' . $r . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<worksheet xmlns="' . $s . '"><sheetData>' . $cells . '</sheetData></worksheet>',
    ];
}
function readerWithCell(string $cell): array { return readerParts('<row r="1">' . $cell . '</row>'); }
function readerRead(array $parts): array { return RegisterWorkbookReader::read(OfficePackage::write($parts)); }
function readerZip(array $parts, int $method = 8, bool $descriptor = false): string
{
    $body = $central = ''; $flags = 0x800 | ($descriptor ? 8 : 0);
    foreach ($parts as $name => $value) {
        $offset = strlen($body); $crc = crc32($value); $size = strlen($value);
        $compressed = $method === 8 ? gzdeflate($value) : $value;
        $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, 0, 33,
            $descriptor ? 0 : $crc, $descriptor ? 0 : strlen($compressed), $descriptor ? 0 : $size, strlen($name), 0) . $name . $compressed;
        if ($descriptor) $body .= pack('VVVV', 0x08074b50, $crc, strlen($compressed), $size);
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, 0, 33, $crc, strlen($compressed), $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $body . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($parts), count($parts), strlen($central), strlen($body), 0);
}

$result = readerRead(readerParts());
readerCheck($result['sheets']['Cadastro'][1]['A'] === 'Nome', 'Inline strings read');
readerCheck($result['types']['Cadastro'][1]['A'] === 'inlineStr' && !$result['date1904'], 'Cell type and default date system retained');
$result = readerRead(readerParts('<row r="1"><c r="A1" t="inlineStr"><is><r><t>Mar</t></r><r><t>ília</t></r><rPh sb="0" eb="1"><t>not a value</t></rPh></is></c><c r="B1"><v>1.25E+3</v></c><c r="C1" t="b"><v>1</v></c><c r="D1" t="d"><v>2026-06-30</v></c><c r="E1" s="1"/></row>'));
readerCheck($result['sheets']['Cadastro'][1] === ['A' => 'Marília', 'B' => '1.25E+3', 'C' => '1', 'D' => '2026-06-30'], 'Rich text, numbers, logical and ISO date values retained, blanks ignored');

$parts = readerWithCell('<c r="A1" t="s"><v>0</v></c>');
$parts['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="strings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', $parts['xl/_rels/workbook.xml.rels']);
$parts['xl/sharedStrings.xml'] = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>00</t></r><r><t>123</t></r></si></sst>';
$parts['xl/workbook.xml'] = str_replace('<sheets>', '<workbookPr date1904="1"/><sheets>', $parts['xl/workbook.xml']);
$result = readerRead($parts);
readerCheck($result['sheets']['Cadastro'][1]['A'] === '00123' && $result['types']['Cadastro'][1]['A'] === 's' && $result['date1904'], 'Shared rich strings preserve leading zeros and 1904 dates');

readerCheck(RegisterWorkbookReader::read(readerZip(readerParts(), 8, true))['sheets']['Cadastro'][1]['A'] === 'Nome', 'Streaming ZIP data descriptors supported');
readerCheck(RegisterWorkbookReader::read(readerZip(readerParts(), 0))['sheets']['Cadastro'][1]['A'] === 'Nome', 'Stored ZIP parts supported');
$parts = readerParts();
$parts['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet1.xml"', 'Target="/xl/worksheets/sheet1.xml"', $parts['xl/_rels/workbook.xml.rels']);
readerCheck(readerRead($parts)['sheets']['Cadastro'][1]['A'] === 'Nome', 'Absolute package paths supported');

$staff = [['id' => '00123', 'name' => 'Teste', 'profile' => 'Técnico', 'admission_date' => '2026-06-01', 'monthly_value' => '1000.50', 'not_billed' => true]];
$result = RegisterWorkbookReader::read(RegisterExporter::export('staff', $staff, 'all', '2026-06'));
readerCheck($result['sheets']['Profissionais'][4]['I'] === '00123' && $result['sheets']['Profissionais'][4]['G'] === '1000.50', 'Actual staff export compatible and identifiers retained');
$result = RegisterWorkbookReader::read(RegisterExporter::export('tests', [['number' => '001234', 'label' => '=literal', 'active' => true]]));
readerCheck($result['sheets']['Números desconsiderados'][4]['A'] === '001234' && $result['sheets']['Números desconsiderados'][4]['B'] === '=literal', 'Actual telephone export compatible and formula-like text stays literal');

readerReject(fn() => RegisterWorkbookReader::read('bad'), 'Wrong file type');
readerReject(fn() => RegisterWorkbookReader::read(str_repeat('x', RegisterWorkbookReader::MAX_BYTES + 1)), 'Compressed size cap');
readerReject(fn() => RegisterWorkbookReader::read(substr(OfficePackage::write(readerParts()), 0, -8)), 'Truncated ZIP');
readerReject(fn() => readerRead(readerWithCell('<c r="A1"><f>1+1</f><v>2</v></c>')), 'Cached formula never imported');
readerReject(fn() => readerRead(readerWithCell('<c r="A1" t="e"><v>#REF!</v></c>')), 'Excel error rejected');
readerReject(fn() => readerRead(readerWithCell('<c r="A1"><v>NaN</v></c>')), 'Invalid number rejected');
readerReject(fn() => readerRead(readerWithCell('<c r="A1"><v>1e9999</v></c>')), 'Numeric infinity rejected');
readerReject(fn() => readerRead(readerWithCell('<c r="A1" t="b"><v>2</v></c>')), 'Invalid logical value rejected');
readerReject(fn() => readerRead(readerWithCell('<c r="A1" t="s"><v>123</v></c>')), 'Absent shared string rejected');
readerReject(fn() => readerRead(readerWithCell('<c r="A1"><v>1</v><v>2</v></c>')), 'Multiple cell values rejected');
readerReject(fn() => readerRead(readerWithCell('<c r="A1"><v>1</v></c><c r="A1"><v>2</v></c>')), 'Duplicate cells rejected');
readerReject(fn() => readerRead(readerWithCell('<c r="A2"><v>1</v></c>')), 'Cell and row coordinates must agree');
readerReject(fn() => readerRead(readerWithCell('<c r="XFE1"><v>1</v></c>')), 'Invalid Excel column rejected');
readerReject(fn() => readerRead(readerParts('<row r="1"/><row r="1"/>')), 'Duplicate rows rejected');
readerReject(fn() => readerRead(readerParts('<row r="15001"/>')), 'Row cap');
readerCheck(readerRead(readerParts('<row r="15000"><c r="A15000"><v>1</v></c></row>'))['sheets']['Cadastro'][15000]['A'] === '1', 'Row cap inclusive');
readerReject(fn() => readerRead(readerWithCell('<c r="A1" t="inlineStr"><is><t>' . str_repeat('x', 10001) . '</t></is></c>')), 'Text limit');

$parts = readerParts(); $parts['unused.xml'] = '<root><f>1+1</f></root>';
readerReject(fn() => readerRead($parts), 'Formula in unreferenced XML rejected');
$parts = readerParts(); $parts['unused.xml'] = '<!DOCTYPE root [<!ENTITY test "value">]><root>&test;</root>';
readerReject(fn() => readerRead($parts), 'Entity/DOCTYPE rejected without expansion');
$parts = readerParts(); $parts['unused.xml'] = '<root>';
readerReject(fn() => readerRead($parts), 'Malformed unreferenced XML rejected');
$parts = readerParts(); $parts['xl/vbaProject.bin'] = 'macro';
readerReject(fn() => readerRead($parts), 'Macro part rejected');
$parts = readerParts(); $parts['[Content_Types].xml'] = str_replace('spreadsheetml.sheet', 'ms-excel.sheet.macroEnabled', $parts['[Content_Types].xml']);
readerReject(fn() => readerRead($parts), 'Macro-enabled content type rejected');
$parts = readerParts(); $parts['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="external" Type="hyperlink" Target="https://example.invalid" TargetMode="External"/></Relationships>', $parts['xl/_rels/workbook.xml.rels']);
readerReject(fn() => readerRead($parts), 'External relationship rejected');
$parts = readerParts(); $parts['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet1.xml"', 'Target="../../outside.xml"', $parts['xl/_rels/workbook.xml.rels']);
readerReject(fn() => readerRead($parts), 'Relationship traversal rejected');
$parts = readerParts(); $parts['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet1.xml"', 'Target="%2e%2e/outside.xml"', $parts['xl/_rels/workbook.xml.rels']);
readerReject(fn() => readerRead($parts), 'Encoded traversal rejected');
$parts = readerParts(); $parts['xl/_rels/workbook.xml.rels'] = str_replace('Target="worksheets/sheet1.xml"', 'Target="worksheets/missing.xml"', $parts['xl/_rels/workbook.xml.rels']);
readerReject(fn() => readerRead($parts), 'Broken relationship rejected');
$parts = readerParts(); $parts['xl/workbook.xml'] = str_replace('</sheets>', '<sheet name="Cadastro" sheetId="2" r:id="sheet1"/></sheets>', $parts['xl/workbook.xml']);
readerReject(fn() => readerRead($parts), 'Duplicate sheet names rejected');

$parts = readerParts(); $parts['../outside.xml'] = '<root/>';
readerReject(fn() => readerRead($parts), 'ZIP traversal rejected');
$parts = readerParts(); $parts['huge.xml'] = str_repeat('x', 4194305);
readerReject(fn() => readerRead($parts), 'Per-part inflated cap before inflation');
$parts = readerParts(); for ($i = 0; $i < 4; $i++) $parts['big' . $i . '.txt'] = str_repeat('x', 3200000);
readerReject(fn() => readerRead($parts), 'Total inflated cap');
$parts = readerParts(); for ($i = 0; $i < 96; $i++) $parts['part' . $i . '.txt'] = '';
readerReject(fn() => readerRead($parts), 'Part count cap');
$binary = OfficePackage::write(readerParts());
$central = strpos($binary, "PK\x01\x02");
$corrupt = substr_replace(substr_replace($binary, pack('V', 0), 14, 4), pack('V', 0), $central + 16, 4);
readerReject(fn() => RegisterWorkbookReader::read($corrupt), 'CRC corruption rejected');
$encrypted = substr_replace(substr_replace($binary, pack('v', 0x801), 6, 2), pack('v', 0x801), $central + 8, 2);
readerReject(fn() => RegisterWorkbookReader::read($encrypted), 'Encrypted ZIP rejected');
$zip64 = substr_replace(substr_replace($binary, pack('v', 45), 4, 2), pack('v', 45), $central + 6, 2);
readerReject(fn() => RegisterWorkbookReader::read($zip64), 'ZIP64 rejected');
$multipart = substr_replace($binary, pack('v', 1), strlen($binary) - 18, 2);
readerReject(fn() => RegisterWorkbookReader::read($multipart), 'Multidisk ZIP rejected');
$duplicate = readerParts(); $duplicate['abc.txt'] = ''; $duplicate['xyz.txt'] = '';
$duplicate = str_replace('xyz.txt', 'abc.txt', OfficePackage::write($duplicate));
readerReject(fn() => RegisterWorkbookReader::read($duplicate), 'Duplicate ZIP names rejected');

echo 'Bounded register XLSX reader: ' . $assertions . " assertions passed.\n";
