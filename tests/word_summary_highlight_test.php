<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** First Word indicator table: confirmed failures are visibly distinct. */
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\DocxExporter;
use GlpiPlugin\G4freports\Ses\OfficePackage;
use GlpiPlugin\G4freports\Ses\ReportStyle;

$assertions = 0;
function summaryHighlightVerify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = [
    ['INS1', 'gte', 90, 88, 'unmet'],
    ['INS2', 'gte', 90, 90, 'met'],
    ['INS3', 'gte', 90, null, 'pending'],
    ['INS4', 'gte', 90, null, 'not_applicable'],
    ['INS5', 'lte', 2, 3, 'unmet'],
    ['INS9', 'lte', 5, 0, 'met'],
    ['INS10', 'lte', 10, 2.5, 'met'],
    ['INS12', 'gte', 80, 79, 'provisional'],
];
$snapshot = ['input' => ['priority_map' => ['INS1' => 5]], 'metrics' => []];
foreach ($cases as [$id, $direction, $target, $value, $status]) {
    $snapshot['metrics'][] = compact('id', 'direction', 'target', 'value', 'status');
}
$original = $snapshot;
$summary = new ReflectionMethod(DocxExporter::class, 'metricsSummary');
$xml = $summary->invoke(new DocxExporter(), $snapshot);
$doc = new DOMDocument();
summaryHighlightVerify($doc->loadXML('<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $xml . '</root>'), 'Summary is valid WordprocessingML');
$xpath = new DOMXPath($doc);
$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
$rows = $xpath->query('//w:tbl/w:tr');
summaryHighlightVerify($rows->length === count($cases) + 1, 'Every metric and the header are preserved');
$header = $rows->item(0);
summaryHighlightVerify($xpath->query('./w:trPr/w:tblHeader', $header)->length === 1, 'Repeated table header preserved');
summaryHighlightVerify($xpath->query('./w:tc/w:tcPr/w:shd[@w:fill="' . ReportStyle::NAVY . '"]', $header)->length === 5, 'G4F navy header is unchanged');
summaryHighlightVerify($xpath->query('./w:tc//w:rPr/w:color[@w:val="' . ReportStyle::WHITE . '"]', $header)->length === 5, 'G4F white header labels are unchanged');

foreach ($snapshot['metrics'] as $index => $metric) {
    $row = $rows->item($index + 1);
    $cells = $xpath->query('./w:tc', $row);
    summaryHighlightVerify($cells->length === 5, $metric['id'] . ': all columns remain');
    summaryHighlightVerify($cells->item(0)->textContent === $metric['id'], $metric['id'] . ': ID preserved');
    summaryHighlightVerify($cells->item(4)->textContent === OfficePackage::status($metric), $metric['id'] . ': original status remains readable');
    $expectedResult = $metric['value'] === null ? ($metric['status'] === 'not_applicable' ? 'N/A' : 'Pendente') : OfficePackage::percent($metric['value']);
    summaryHighlightVerify($cells->item(3)->textContent === $expectedResult, $metric['id'] . ': result unchanged');
    $redFills = $xpath->query('./w:tc/w:tcPr/w:shd[@w:fill="FCE8E6"]', $row)->length;
    $redText = $xpath->query('./w:tc//w:rPr/w:color[@w:val="9C1C1C"]', $row)->length;
    if ($metric['status'] === 'unmet') {
        summaryHighlightVerify($redFills === 0 && $redText === 5, $metric['id'] . ': the violated row uses dark red text without red cell fill');
        summaryHighlightVerify($xpath->query('.//w:rPr/w:b', $cells->item(4))->length === 1, $metric['id'] . ': violated status is bold');
        $expectedFill = ($index + 1) % 2 === 0 ? ReportStyle::POSITIVE_FILL : ReportStyle::WHITE;
        summaryHighlightVerify($xpath->query('./w:tc/w:tcPr/w:shd[@w:fill="' . $expectedFill . '"]', $row)->length === 5, $metric['id'] . ': violated rows retain ordinary alternating banding');
    } else {
        summaryHighlightVerify($redFills === 0 && $redText === 0, $metric['id'] . ': met, unavailable and provisional rows are not colored as violations');
        $expectedFill = ($index + 1) % 2 === 0 ? ReportStyle::POSITIVE_FILL : ReportStyle::WHITE;
        summaryHighlightVerify($xpath->query('./w:tc/w:tcPr/w:shd[@w:fill="' . $expectedFill . '"]', $row)->length === 5, $metric['id'] . ': existing brand banding is unchanged');
    }
    summaryHighlightVerify($xpath->query('./w:trPr/w:cantSplit', $row)->length === 1, $metric['id'] . ': rows remain unsplit across pages');
}

summaryHighlightVerify($snapshot === $original, 'Highlighting never changes the metric values or statuses');

// Other tables do not opt in to status highlighting, even with identical text.
$plainTable = new ReflectionMethod(DocxExporter::class, 'table');
$plain = $plainTable->invoke(new DocxExporter(), ['Medição', 'Valor'], [['Situação', 'Meta não atingida']], [7100, 2400]);
summaryHighlightVerify(strpos($plain, 'FCE8E6') === false && strpos($plain, '9C1C1C') === false, 'Styling is limited to the consolidated summary');
echo "Word summary highlight: {$assertions} assertions passed.\n";
