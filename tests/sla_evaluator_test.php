<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Standalone focused regression; optional argv[1] is the local June evidence
// fixture. The private customer evidence is intentionally not bundled here.
require_once dirname(__DIR__) . '/src/Ses/SlaEvaluator.php';

use GlpiPlugin\G4freports\Ses\SlaEvaluator;

$checks = 0;
function expectSame($actual, $expected, string $label): void
{
    global $checks;
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

$context = [
    'timezone' => 'America/Sao_Paulo',
    'agreements' => [6 => ['type' => 0, 'number_time' => 8, 'definition_time' => 'hour', 'calendars_id' => 0]],
    'entities' => [], 'calendars' => [], 'history_available' => false,
];
$evaluator = new SlaEvaluator($context, []);
$base = [
    'id' => 1, 'priority' => 2, 'opened_at' => '2026-06-01 08:00:00',
    'solved_at' => '2026-06-01 18:00:00', 'created_at' => '2026-06-01 08:00:00',
    'sla_id' => 6, 'deadline' => '2026-06-02 08:00:00',
    'solve_seconds' => 0, 'waiting_seconds' => 3600, 'sla_waiting_seconds' => 3600,
];

$r = $evaluator->evaluate($base);
expectSame($r['sla_report_duration_seconds'], 32400, 'zero statistic uses elapsed less general waiting');
expectSame($r['within_sla'], false, 'nine hours exceeds eight-hour target despite native deadline');
expectSame($r['sla_stored_within'], true, 'native deadline kept independently');
expectSame($r['sla_operational_within'], true, 'operational outcome kept independently');
expectSame($r['sla_difference'], true, 'report/native difference visible');
expectSame($r['sla_method'], 'report_elapsed_less_waiting', 'positive elapsed difference method');
expectSame($r['sla_provisional'], false, 'measured difference does not mark result provisional');

foreach ([28800 => true, 28801 => false] as $seconds => $within) {
    $r = $evaluator->evaluate(array_replace($base, ['solve_seconds' => $seconds]));
    expectSame($r['sla_report_duration_seconds'], $seconds, 'positive statistic retained at boundary');
    expectSame($r['within_sla'], $within, 'boundary compared in seconds');
    expectSame($r['sla_method'], 'report_stored_duration', 'stored statistic takes precedence');
}

foreach ([36000, 40000] as $waiting) {
    $r = $evaluator->evaluate(array_replace($base, ['waiting_seconds' => $waiting]));
    expectSame($r['sla_report_duration_seconds'], 36000, 'nonpositive difference uses whole elapsed');
    expectSame($r['sla_method'], 'report_elapsed', 'elapsed method for nonpositive difference');
    expectSame($r['within_sla'], false, 'zero statistic is not automatic compliance');
}

$r = $evaluator->evaluate(array_replace($base, [
    'created_at' => '2026-06-01 17:00:00',
    'sla_history' => [['id' => 123, 'date_mod' => '2026-06-01 17:00:00', 'is_clone' => true]],
]));
expectSame($r['sla_report_duration_seconds'], 32400, 'clone retains original opening');
expectSame(in_array('copied_lifecycle', $r['sla_flags'], true), true, 'clone provenance retained');

$r = $evaluator->evaluate(array_replace($base, ['sla_id' => 0, 'deadline' => null]));
expectSame($r['within_sla'], false, 'missing calendar/SLA/deadline does not exclude calculable duration');
expectSame($r['sla_stored_within'], null, 'missing native deadline not classified as compliant');
expectSame($r['sla_gross_seconds'], null, 'missing calendar remains unavailable rather than zero');

foreach ([null, -1] as $waiting) {
    $r = $evaluator->evaluate(array_replace($base, ['waiting_seconds' => $waiting]));
    expectSame($r['sla_report_duration_seconds'], 36000, 'invalid waiting does not invent a suspension');
    expectSame(in_array('general_waiting_unavailable', $r['sla_flags'], true), true, 'invalid waiting audit flag');
}

$r = $evaluator->evaluate(array_replace($base, ['solve_seconds' => -1]));
expectSame($r['sla_report_duration_seconds'], 32400, 'negative statistic is not a positive duration');
$r = $evaluator->evaluate(array_replace($base, ['solved_at' => '2026-05-31 18:00:00']));
expectSame($r['within_sla'], null, 'inverted interval without positive statistic not fabricated');
$r = $evaluator->evaluate(array_replace($base, ['opened_at' => '2026-02-30 08:00:00']));
expectSame($r['within_sla'], null, 'invalid timestamp not normalized silently');
$r = $evaluator->evaluate(array_replace($base, ['priority' => 1]));
expectSame($r['within_sla'], null, 'unsupported priority not mapped silently');
$r = $evaluator->evaluate(array_replace($base, ['solved_at' => $base['opened_at'], 'waiting_seconds' => 0]));
expectSame($r['sla_report_duration_seconds'], 0, 'genuine zero elapsed retained');
expectSame($r['within_sla'], true, 'genuine zero elapsed is within target');

$withoutTimezone = new SlaEvaluator([], []);
$r = $withoutTimezone->evaluate(array_replace($base, ['solve_seconds' => 60]));
expectSame($r['within_sla'], true, 'positive stored seconds need no timezone conversion');
$r = $withoutTimezone->evaluate($base);
expectSame($r['within_sla'], null, 'elapsed fallback needs confirmed source timezone');

$overrideContext = $context;
$overrideContext['calendars'][7] = ['name' => 'Short diagnostic calendar', 'segments' => [
    ['day' => 1, 'begin' => '08:00:00', 'end' => '09:00:00'],
]];
$override = new SlaEvaluator($overrideContext, ['n1_group_ids' => [55], 'sla_n1_calendar_id' => 7]);
$r = $override->evaluate(array_replace($base, ['group_ids' => [55]]));
expectSame($r['sla_gross_seconds'], 3600, 'selected calendar still measured for audit');
expectSame($r['sla_report_duration_seconds'], 32400, 'selected calendar cannot replace agreed report duration');
expectSame($r['within_sla'], false, 'calendar-only override cannot reverse report classification');

$corpus = null;
if (isset($argv[1])) {
    $fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $evaluator = new SlaEvaluator($fixture['context'], $fixture['input']);
    $cohorts = [];
    $methods = [];
    $grossCompared = 0;
    foreach ($fixture['tickets'] as $case) {
        $ticket = $case['ticket'];
        $expected = $case['expected'];
        $r = $evaluator->evaluate($ticket);
        $label = 'ticket ' . $ticket['id'];
        expectSame($r['sla_report_duration_seconds'], $expected['duration'], $label . ' validated duration');
        expectSame($r['within_sla'], $expected['within'], $label . ' validated automated classification');
        expectSame($r['sla_method'], $expected['method'], $label . ' duration origin');
        expectSame($r['sla_stored_within'], $expected['native_within'], $label . ' untouched native deadline evidence');
        if ($expected['calendar_gross'] !== null) {
            expectSame($r['sla_gross_seconds'], $expected['calendar_gross'], $label . ' unchanged calendar gross');
            $grossCompared++;
        }
        $methods[$r['sla_method']] = ($methods[$r['sla_method']] ?? 0) + 1;
        if ($expected['cohort']) {
            $id = $expected['cohort'];
            if (!isset($cohorts[$id])) $cohorts[$id] = ['within' => 0, 'total' => 0];
            $cohorts[$id]['total']++;
            $cohorts[$id]['within'] += $r['within_sla'] ? 1 : 0;
        }
    }
    expectSame(count($fixture['tickets']), 6567, 'complete validated population');
    expectSame($methods['report_stored_duration'] ?? 0, 6487, 'positive stored branch population');
    expectSame($methods['report_elapsed_less_waiting'] ?? 0, 75, 'elapsed less waiting branch population');
    expectSame($methods['report_elapsed'] ?? 0, 5, 'elapsed branch population');
    expectSame($cohorts['INS1'], ['within' => 1, 'total' => 1], 'INS1 accepted baseline');
    expectSame($cohorts['INS2'], ['within' => 1, 'total' => 1], 'INS2 accepted baseline');
    expectSame($cohorts['INS3'], ['within' => 2, 'total' => 12], 'INS3 automated result before human reassessment');
    expectSame($cohorts['INS4'], ['within' => 6123, 'total' => 6223], 'INS4 accepted result without manual overrides');
    $corpus = ['tickets' => count($fixture['tickets']), 'methods' => $methods, 'cohorts' => $cohorts, 'calendar_gross_compared' => $grossCompared];
}
echo json_encode(['passed' => true, 'checks' => $checks, 'corpus' => $corpus], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
