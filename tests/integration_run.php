<?php
/** Private June evidence runner: php integration_run.php INPUT.json OUT_DIR [--allow-mismatch]. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\ActorEvidence;
use GlpiPlugin\G4freports\Ses\Calculator;
use GlpiPlugin\G4freports\Ses\ChartData;
use GlpiPlugin\G4freports\Ses\DocxExporter;
use GlpiPlugin\G4freports\Ses\Settings;
use GlpiPlugin\G4freports\Ses\XlsxExporter;

set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$out = rtrim($argv[2], '/');
if (!is_dir($out) && !mkdir($out, 0700, true)) throw new RuntimeException('Cannot create private output directory.');
$input = Settings::normalizeInput($fixture['input'], $fixture['settings']);
$data = $fixture['data'];
if (class_exists(ActorEvidence::class)) {
    $data['tickets'] = ActorEvidence::annotate($data['tickets'], $fixture['actor_rows']['status'],
        $fixture['actor_rows']['solutions'], $fixture['actor_rows']['members']);
}
$snapshot = Calculator::build($input, $data);
$failures = [];
$results = [];
foreach ($snapshot['metrics'] as $metric) {
    $results[$metric['id']] = ['numerator' => $metric['numerator'], 'denominator' => $metric['denominator'],
        'value' => $metric['value'], 'status' => $metric['status']];
    if (isset($fixture['expected'][$metric['id']])) {
        $actual = [$metric['numerator'], $metric['denominator']];
        if ($actual !== $fixture['expected'][$metric['id']]) $failures[] = [$metric['id'], 'actual' => $actual, 'expected' => $fixture['expected'][$metric['id']]];
    }
}
if (count($snapshot['tickets']) !== 6567) $failures[] = ['population', count($snapshot['tickets'])];
if (($snapshot['totals']['sla_scope_unresolved'] ?? -1) !== 0) $failures[] = ['unresolved_scope', $snapshot['totals']['sla_scope_unresolved']];
if (count($snapshot['ins5_administrative_events'] ?? []) !== 13) $failures[] = ['admin_events', count($snapshot['ins5_administrative_events'] ?? [])];
$fallback = array_column($snapshot['ins6_solver_inclusions'] ?? [], 'ticket_id');
if ($fallback !== [263097]) $failures[] = ['solver_fallback', $fallback];
file_put_contents($out . '/snapshot.json', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($out . '/charts.json', json_encode(ChartData::build($snapshot), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($out . '/report.docx', (new DocxExporter())->toBinary($snapshot));
file_put_contents($out . '/report.xlsx', (new XlsxExporter())->toBinary($snapshot));
if (in_array('--both-modes', $argv, true)) {
    $combinedInput = $input;
    $combinedInput['ins12_mode'] = 'glpi_and_phone';
    $combined = Calculator::build($combinedInput, $data);
    foreach ($combined['metrics'] as $metric) {
        if ($metric['id'] === 'INS12') {
            if ([$metric['numerator'], $metric['denominator']] !== [665, 785]) {
                $failures[] = ['INS12 combined', $metric['numerator'], $metric['denominator']];
            }
        } elseif ([$metric['numerator'], $metric['denominator']] !== [$results[$metric['id']]['numerator'], $results[$metric['id']]['denominator']]) {
            $failures[] = [$metric['id'], 'changed when selecting survey sources'];
        }
    }
    if (!is_dir($out . '/combined')) mkdir($out . '/combined', 0700, true);
    file_put_contents($out . '/combined/snapshot.json', json_encode($combined, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($out . '/combined/charts.json', json_encode(ChartData::build($combined), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($out . '/combined/report.docx', (new DocxExporter())->toBinary($combined));
    file_put_contents($out . '/combined/report.xlsx', (new XlsxExporter())->toBinary($combined));
}
$summary = ['metrics' => $results, 'failures' => $failures, 'totals' => $snapshot['totals'],
    'rules_version' => $snapshot['rules_version'], 'exports' => ['report.docx', 'report.xlsx'],
    'scope' => 'Full supplied June GLPI evidence; archived calls used only for presentation regression; personnel assertions follow the supplied fixture.'];
file_put_contents($out . '/result.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures && !in_array('--allow-mismatch', $argv, true) ? 1 : 0);
