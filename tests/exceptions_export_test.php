<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\ActorEvidence;
use GlpiPlugin\G4freports\Ses\ChartData;
use GlpiPlugin\G4freports\Ses\MetricEvidence;
use GlpiPlugin\G4freports\Ses\XlsxExporter;

set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$assertions = 0;
function exceptionVerify(bool $ok, string $message): void {
    global $assertions;
    $assertions++;
    if (!$ok) throw new RuntimeException($message);
}
function exceptionEvent(int $log, int $actor = 50): array {
    return ['log_id' => $log, 'occurred_at' => '2026-06-03 11:00:00',
        'reopener_id' => $actor, 'reopener_name' => $actor === 50 ? 'Técnico A' : 'Solicitante',
        'solution_id' => 200, 'solution_at' => '2026-06-02 10:00:00',
        'solver_id' => 50, 'solver_name' => 'Técnico A', 'solver_group_ids' => [55]];
}
$input = ['n1_group_ids' => [55], 'n2_group_ids' => [56], 'n3_group_ids' => [57]];
$tickets = [];
$events = [];
foreach ([101 => [exceptionEvent(1), exceptionEvent(2)],
    102 => [exceptionEvent(3), exceptionEvent(4, 60)],
    103 => [exceptionEvent(5)], 104 => []] as $id => $history) {
    $ticket = ActorEvidence::classify(['id' => $id, 'title' => '=Título ' . $id,
        'category' => 'Categoria A', 'category_id' => 1, 'group_ids' => [],
        'solved_at' => '2026-06-04 15:00:00', 'ins6_eligible' => false,
        'ins5_reopening_events' => $history], $input, $id === 103);
    $tickets[] = $ticket;
    foreach ($ticket['ins5_reopening_events'] as $event) {
        if (!$event['administrative_exempt']) continue;
        $events[] = $event + ['ticket_id' => $id, 'ticket_title' => $ticket['title'],
            'ticket_still_counted' => $ticket['ins5'],
            'ticket_result_reason' => $ticket['incomplete_execution'] ? 'Execução incompleta informada manualmente.'
                : ($ticket['ins5'] ? 'Há outra reabertura não dispensada.' : 'Todas as reaberturas registradas atendem à dispensa administrativa.')];
    }
}
$metric = ['id' => 'INS5', 'numerator' => 2, 'denominator' => 4, 'value' => 50,
    'evidence_ids' => [102, 103], 'denominator_ids' => [101, 102, 103, 104],
    'direction' => 'lte', 'target' => 2, 'status' => 'unmet', 'method' => 'Método preservado'];
$snapshot = ['period' => ['month' => '2026-06'], 'input' => $input,
    'tickets' => $tickets, 'calls' => [], 'metrics' => [$metric], 'ins5_administrative_events' => $events];
$original = $snapshot;
$evidence = MetricEvidence::ins5ExceptionTables($snapshot);
exceptionVerify($evidence['summary'] === ['events' => 4, 'tickets' => 3,
    'removed_from_reopened' => 1, 'still_counted' => 2, 'in_base' => 3],
    'Multiple events, mixed valid reopening and manual incomplete execution have distinct totals');
exceptionVerify(array_column($evidence['tickets']['rows'], 0) === ['101', '102', '103'],
    'Exactly one summary row per affected ticket; ordinary tickets are absent');
exceptionVerify(array_column($evidence['tickets']['rows'], 4) === [2, 1, 1], 'Event counts remain separate from unique tickets');
exceptionVerify(array_column($evidence['tickets']['rows'], 5) === ['Não', 'Sim', 'Sim'],
    'Only purely administrative ticket leaves the reopened count');
exceptionVerify(array_column($evidence['tickets']['rows'], 6) === ['Sim', 'Sim', 'Sim'],
    'All three exception tickets remain in the denominator');
exceptionVerify(str_contains($evidence['tickets']['rows'][1][7], 'outra reabertura'), 'Mixed reopening reason is explicit');
exceptionVerify(str_contains($evidence['tickets']['rows'][2][7], 'incompleta'), 'Manual incomplete execution reason is explicit');
exceptionVerify($evidence['events']['rows'][0][3] === '2026-06-03 11:00:00'
    && $evidence['events']['rows'][0][6] === 200 && $evidence['events']['rows'][0][7] === '2026-06-02 10:00:00',
    'Event detail retains reopening and previous solution ID/time');
exceptionVerify($evidence['events']['rows'][0][4] === 50 && $evidence['events']['rows'][0][8] === 50,
    'Reopener and previous solver identities are independently visible');
$variant = $snapshot;
foreach ($variant['ins5_administrative_events'] as &$event) $event['ticket_still_counted'] = false;
unset($event);
exceptionVerify(MetricEvidence::ins5ExceptionTables($variant)['summary']['still_counted'] === 2,
    'Official calculation sets determine participation, not a stale event display flag');

$exporter = new XlsxExporter();
$property = new ReflectionProperty(XlsxExporter::class, 'snapshot');
$property->setValue($exporter, $snapshot);
$sheet = (new ReflectionMethod(XlsxExporter::class, 'ins5Exceptions'))->invoke($exporter);
exceptionVerify($sheet['name'] === 'Exceções INS5', 'Dedicated exceptions sheet is identifiable');
exceptionVerify($sheet['rows'][8][4]['value'] === 4 && $sheet['rows'][9][4]['value'] === 3
    && $sheet['rows'][10][4]['value'] === 1 && $sheet['rows'][11][4]['value'] === 2
    && $sheet['rows'][12][4]['value'] === 3, 'Summary distinguishes ignored events and actual ticket impact');
$tables = array_column($sheet['tables'], null, 'name');
foreach (['Excecoes_INS5_chamados' => 3, 'Excecoes_INS5_eventos' => 4] as $name => $count) {
    exceptionVerify(isset($tables[$name]), 'Independently filterable table: ' . $name);
    preg_match('/^A(\d+):[A-Z]+(\d+)$/', $tables[$name]['ref'], $range);
    exceptionVerify((int)$range[2] - (int)$range[1] === $count, 'Table row count: ' . $name);
    $footer = (int)$range[2] + 1;
    exceptionVerify($sheet['rows'][$footer][2]['value'] === $count
        && str_starts_with($sheet['rows'][$footer][2]['formula'], 'SUBTOTAL(103,'), 'Filtered record total: ' . $name);
    exceptionVerify(str_contains($sheet['rows'][$footer][1]['value'], $count === 3 ? 'chamados distintos' : 'eventos'),
        'Total explicitly names its unit: ' . $name);
    $style = $sheet['rows'][(int)$range[1] + 1][2]['style'];
    exceptionVerify($style >= 18 && $style < 54, 'Evidence uses readable white/banded styles');
}
$xml = (new ReflectionMethod(XlsxExporter::class, 'sheetXml'))->invoke($exporter, $sheet);
$document = new DOMDocument();
exceptionVerify($document->loadXML($xml), 'Exception worksheet is well-formed');
$xpath = new DOMXPath($document);
$xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
exceptionVerify($xpath->query('//s:c[@t="inlineStr"]/s:is/s:t[text()="=Título 101"]')->length === 3,
    'Customer title is literal in ticket and event details, never an executable formula');
exceptionVerify($xpath->query('//s:tablePart')->length === 2, 'Both filterable tables are packaged');
exceptionVerify($snapshot === $original, 'Evidence export never mutates the calculation snapshot');

// Check the INS5 calculation and its direct exception link remain independent.
(new ReflectionProperty(XlsxExporter::class, 'evidence'))->setValue($exporter,
    ['INS5' => ['available' => true, 'num' => [102 => true, 103 => true], 'den' => array_fill_keys([101,102,103,104], true), 'excluded' => []]]);
(new ReflectionProperty(XlsxExporter::class, 'rawColumns'))->setValue($exporter,
    ['INS5' => ['sheet' => 'Base de Dados', 'num' => 30, 'den' => 31, 'date' => 9, 'rows' => 4]]);
(new ReflectionProperty(XlsxExporter::class, 'chartData'))->setValue($exporter, ChartData::build($snapshot));
$metricSheet = (new ReflectionMethod(XlsxExporter::class, 'metric'))->invoke($exporter, $metric);
exceptionVerify($metricSheet['rows'][8][2]['value'] === 2 && $metricSheet['rows'][9][2]['value'] === 4,
    'The official counts have not been altered by presentation of exceptions');
exceptionVerify(str_contains($metricSheet['rows'][9][2]['formula'], "'Base de Dados'!")
    && !str_contains($metricSheet['rows'][9][2]['formula'], 'Exceções'), 'Denominator formula continues using the full authoritative base');
exceptionVerify(in_array(['ref' => 'A17', 'location' => "'Exceções INS5'!A1"], $metricSheet['links'], true),
    'INS5 links directly to the dedicated exceptions');

$audit = (new ReflectionMethod(XlsxExporter::class, 'exclusionAudit'))->invoke($exporter);
$auditLinks = array_column($audit['links'], 'location', 'ref');
exceptionVerify(($auditLinks['A6'] ?? '') === "'Exceções INS5'!A1", 'Audit directory links INS5');
foreach (['A7', 'A8', 'A9', 'A10', 'A11'] as $link) {
    exceptionVerify(isset($auditLinks[$link]) && preg_match('/!A(\d+)$/', $auditLinks[$link], $target) === 1
        && isset($audit['rows'][(int)$target[1]][1]), 'Audit directory target exists: ' . $link);
}
exceptionVerify(str_contains($audit['rows'][8][1]['value'], 'incluídos'), 'INS6 complementary inclusion is not called an exclusion');

$empty = $snapshot;
$empty['ins5_administrative_events'] = [];
$property->setValue($exporter, $empty);
$emptySheet = (new ReflectionMethod(XlsxExporter::class, 'ins5Exceptions'))->invoke($exporter);
exceptionVerify($emptySheet['rows'][8][4]['value'] === 0 && $emptySheet['rows'][9][4]['value'] === 0, 'No exceptions means explicit zero events/tickets');
exceptionVerify($emptySheet['tables'] === [], 'No malformed empty Excel table is created');
$emptyXml = (new ReflectionMethod(XlsxExporter::class, 'sheetXml'))->invoke($exporter, $emptySheet);
exceptionVerify(substr_count($emptyXml, 'Nenhum registro desta regra na extração.') === 2,
    'Both empty detail sections explain the absence of records');
echo "PASS exceptions export: {$assertions} assertions (events, tickets, impact, filters, totals, navigation and preserved metrics).\n";
