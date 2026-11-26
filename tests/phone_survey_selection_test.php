<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Synthetic telephone survey regressions; no customer data or database access. */
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\Calculator;
use GlpiPlugin\G4freports\Ses\ChartData;
use GlpiPlugin\G4freports\Ses\PhoneSurveyScore;
use GlpiPlugin\G4freports\Ses\PhoneSurveySummary;
use GlpiPlugin\G4freports\Ses\Repository;
use GlpiPlugin\G4freports\Ses\Settings;

$assertions = 0;
function surveyVerify(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}

function surveyCall(string $id, $atendimento, $nota, array $changes = []): array
{
    return array_replace([
        'id' => $id, 'queue' => 'contract', 'caller' => '6100000000',
        'entered_at' => '2026-06-01 08:00:00',
        'connected_at' => '2026-06-01 08:00:10',
        'completed_at' => '2026-06-01 08:01:10',
        'wait_seconds' => 10, 'talk_seconds' => 60,
        'satisfaction_raw' => $atendimento, 'satisfaction_nota_raw' => $nota,
    ], $changes);
}

function surveyScores(array $calls): array
{
    return array_column($calls, 'satisfaction');
}

// The reported anomaly: one pressed key has several distinct treated scores.
$june = [surveyCall('a', '1', '1'), surveyCall('b', '1', '4'), surveyCall('c', '1', '5')];
$juneOriginal = $june;
$resolvedJune = PhoneSurveyScore::resolve($june);
surveyVerify($resolvedJune['policy'] === 'nota_preferred', 'Ambiguous atendimento selects the treated nota for this extraction.');
surveyVerify($resolvedJune['ambiguous_atendimento'] === [1], 'Audit identifies the ambiguous raw key.');
surveyVerify(surveyScores($resolvedJune['calls']) === [1, 4, 5], 'Treated nota is used directly, without inversion.');
surveyVerify(array_column($resolvedJune['calls'], 'satisfaction_source') === ['nota', 'nota', 'nota'], 'Each selected score records its source.');
surveyVerify($june === $juneOriginal, 'Resolution must not mutate the original evidence.');
surveyVerify(trim($resolvedJune['explanation']) !== '', 'The extraction records a human-readable selection explanation.');
foreach ($resolvedJune['calls'] as $call) {
    surveyVerify(PhoneSurveySummary::normalizedRating($call) === $call['satisfaction'], 'The summary consumes the final score without a second inversion.');
}
$juneSummary = PhoneSurveySummary::build($resolvedJune['calls'], true, '', 'glpi_and_phone', $resolvedJune);
surveyVerify($juneSummary['responses'] === 3 && $juneSummary['valid_responses'] === 3 && $juneSummary['favorable_responses'] === 2,
    'June anomaly preserves the unfavorable response in the denominator.');
surveyVerify(surveyScores(PhoneSurveyScore::resolve($resolvedJune['calls'])['calls']) === [1, 4, 5],
    'Repeated resolution does not invert already resolved scores.');

// Detection is an association check, not a test for every response being equal.
$healthy = [];
for ($key = 1; $key <= 5; $key++) $healthy[] = surveyCall('healthy-' . $key, $key, 6 - $key);
$normal = PhoneSurveyScore::resolve($healthy);
surveyVerify($normal['policy'] === 'atendimento_standard' && $normal['ambiguous_atendimento'] === [], 'Consistent months retain the IVR conversion.');
surveyVerify(surveyScores($normal['calls']) === [5, 4, 3, 2, 1], 'Every valid IVR key keeps its established inverse mapping.');
surveyVerify(array_unique(array_column($normal['calls'], 'satisfaction_source')) === ['atendimento'], 'Healthy records remain attributed to atendimento.');
$constantNota = PhoneSurveyScore::resolve([surveyCall('fixed-a', 1, 5), surveyCall('fixed-b', 2, 5), surveyCall('fixed-c', 5, 5)]);
surveyVerify($constantNota['policy'] === 'atendimento_standard' && surveyScores($constantNota['calls']) === [5, 4, 1],
    'A constant nota paired with varying pressed keys does not override healthy atendimento.');
$singleMismatch = PhoneSurveyScore::resolve([surveyCall('single', 1, 1)]);
surveyVerify($singleMismatch['policy'] === 'atendimento_standard' && surveyScores($singleMismatch['calls']) === [5],
    'A single valid pair is insufficient to infer the monthly anomaly.');
$allFives = PhoneSurveyScore::resolve([surveyCall('same-a', 1, 5), surveyCall('same-b', 1, 5)]);
surveyVerify($allFives['policy'] === 'atendimento_standard' && surveyScores($allFives['calls']) === [5, 5],
    'Genuinely identical responses do not trigger anomaly selection.');
surveyVerify(surveyScores(PhoneSurveyScore::resolve($healthy)['calls']) === [5, 4, 3, 2, 1],
    'A previous anomalous extraction cannot affect a later healthy month.');
surveyVerify($resolvedJune['calls'][0]['satisfaction'] === 1, 'Calculating another month cannot rewrite an existing extraction.');

// Even in a nota-preferred month, strange or missing nota uses 6 - atendimento.
$invalidNotas = [null, '', 'invalid', 0, 6, -1, 1.5, '4.5'];
foreach ($invalidNotas as $index => $invalidNota) {
    $resolution = PhoneSurveyScore::resolve(array_merge($june, [surveyCall('fallback-' . $index, 2, $invalidNota)]));
    $call = $resolution['calls'][3];
    surveyVerify($resolution['policy'] === 'nota_preferred' && $call['satisfaction'] === 4 && $call['satisfaction_source'] === 'atendimento',
        'Invalid nota falls back to the converted valid pressed key (case ' . $index . ').');
}
$invalidDoesNotTrigger = PhoneSurveyScore::resolve([surveyCall('valid-pair', 1, 5), surveyCall('bad-pair', 1, '4.5')]);
surveyVerify($invalidDoesNotTrigger['policy'] === 'atendimento_standard', 'Invalid nota cannot establish a second valid mapping.');
$invalidKeyDoesNotTrigger = PhoneSurveyScore::resolve([surveyCall('bad-key-a', 9, 1), surveyCall('bad-key-b', 9, 5)]);
surveyVerify($invalidKeyDoesNotTrigger['policy'] === 'atendimento_standard', 'Invalid atendimento cannot establish an ambiguous valid key.');
surveyVerify(surveyScores($invalidKeyDoesNotTrigger['calls']) === [1, 5], 'Valid treated scores recover records whose pressed key is invalid.');

$recovery = PhoneSurveyScore::resolve([
    surveyCall('nota-only', null, 4),
    surveyCall('invalid-atendimento', 'not-a-key', 1),
    surveyCall('absent', null, null),
    surveyCall('empty', '', ''),
    surveyCall('invalid', 7, 8),
    surveyCall('fractional', 1.5, '4.5'),
]);
surveyVerify(surveyScores($recovery['calls']) === [4, 1, null, null, null, null], 'Valid nota recovers missing keys; absent and invalid scores are never fabricated.');
surveyVerify(array_column($recovery['calls'], 'satisfaction_source') === ['nota', 'nota', 'none', 'none', 'none', 'none'],
    'Recovery and unavailable scores retain explicit source attribution.');
surveyVerify(PhoneSurveySummary::hasResponse($recovery['calls'][0]), 'A nota-only response must be recognized as answered.');
surveyVerify(!PhoneSurveySummary::hasResponse($recovery['calls'][2]) && !PhoneSurveySummary::hasResponse($recovery['calls'][3]),
    'Null and blank raw values are unanswered.');
surveyVerify(PhoneSurveySummary::hasResponse($recovery['calls'][4]), 'Out-of-scale raw values are an invalid response, not unanswered.');
$recoverySummary = PhoneSurveySummary::build($recovery['calls'], true, '', 'glpi_and_phone', $recovery);
surveyVerify($recoverySummary['responses'] === 4 && $recoverySummary['valid_responses'] === 2 && $recoverySummary['favorable_responses'] === 1,
    'Response totals include nota-only recovery and retain valid-score denominator semantics.');
surveyVerify($recoverySummary['without_response'] === 2 && $recoverySummary['invalid_responses'] === 2,
    'Missing and invalid responses remain separate classifications.');
$distribution = ChartData::phoneSurveyDistribution(['calls' => $recovery['calls'], 'phone_survey' => $recoverySummary]);
$counts = array_column($distribution['rows'], 1, 0);
surveyVerify($counts[4] === 1 && $counts[1] === 1 && $counts['Não preenchida'] === 2 && $counts['Nota inválida'] === 2,
    'Graph distribution uses the same final scores and missing/invalid classification as INS12.');

// Older schemas and saved evidence remain interpretable without a nota column.
$oldSchema = [surveyCall('old-raw', 2, null)];
unset($oldSchema[0]['satisfaction_nota_raw']);
$oldResolution = PhoneSurveyScore::resolve($oldSchema);
surveyVerify(surveyScores($oldResolution['calls']) === [4] && $oldResolution['policy'] === 'atendimento_standard',
    'An older schema with no nota column retains the previous conversion.');
$legacy = surveyCall('legacy', null, null, ['satisfaction' => 2]);
unset($legacy['satisfaction_raw'], $legacy['satisfaction_nota_raw']);
$legacyResult = PhoneSurveyScore::resolve([$legacy]);
surveyVerify($legacyResult['calls'][0]['satisfaction'] === 2 && $legacyResult['calls'][0]['satisfaction_source'] === 'legacy',
    'Historical evidence containing only the treated score must not be inverted.');
surveyVerify(PhoneSurveySummary::normalizedRating($legacyResult['calls'][0]) === 2, 'Legacy scores remain stable in the summary.');

// Transfers/multiple selected queues still count one survey per unique call.
$withQueues = array_merge($june, [surveyCall('a', 1, 1, ['queue' => 'second', 'entered_at' => '2026-06-01 08:02:00'])]);
$queueResult = PhoneSurveyScore::resolve($withQueues);
$queueSummary = PhoneSurveySummary::build($queueResult['calls'], true, '', 'glpi_and_phone', $queueResult);
surveyVerify($queueSummary['unique_calls'] === 3 && $queueSummary['valid_responses'] === 3 && $queueSummary['favorable_responses'] === 2,
    'Duplicate queue membership cannot duplicate a telephone response.');
surveyVerify(count($queueSummary['representative_call_keys']) === 3, 'Exactly one queue row represents each call in INS12 evidence.');
$excludedMode = PhoneSurveySummary::build($queueResult['calls'], true, '', 'glpi_only', $queueResult);
surveyVerify(!$excludedMode['included'] && $excludedMode['valid_responses'] === 3 && $excludedMode['favorable_responses'] === 2,
    'The GLPI-only option preserves independent telephone audit counts.');
$empty = PhoneSurveyScore::resolve([]);
surveyVerify($empty['calls'] === [] && $empty['policy'] === 'atendimento_standard', 'An empty period is a valid empty result.');

// Score policy is selected before saved test-number exclusions, while INS12
// includes only remaining calls. Excluding a test must not rescore real calls.
$cohort = [
    surveyCall('real', 1, 1, ['caller' => '6100000001']),
    surveyCall('test', 1, 5, ['caller' => '6100000002']),
];
$input = Settings::normalizeInput(['month' => '2026-06'], Settings::defaults());
$data = ['tickets' => [], 'calls' => $cohort, 'capabilities' => ['phone_survey' => true, 'satisfaction' => true]];
$all = Calculator::build($input, $data);
$input['test_numbers'] = [['number' => '6100000002', 'label' => 'Synthetic test', 'active' => true]];
$filtered = Calculator::build($input, $data);
surveyVerify(count($filtered['calls']) === 1 && count($filtered['excluded_calls']) === 1, 'Saved test-number exclusion still applies normally.');
surveyVerify($all['calls'][0]['ins12_score'] === 1 && $filtered['calls'][0]['ins12_score'] === 1,
    'The remaining real call keeps its treated score when the test number is excluded.');
surveyVerify($filtered['excluded_calls'][0]['satisfaction'] === 5 && $filtered['excluded_calls'][0]['satisfaction_source'] === 'nota',
    'Excluded-call audit keeps the same monthly source selection.');
surveyVerify($filtered['phone_survey']['valid_responses'] === 1 && $filtered['phone_survey']['favorable_responses'] === 0,
    'Excluded test survey cannot enter the INS12 fraction.');
$metric = array_column($filtered['metrics'], null, 'id')['INS12'];
surveyVerify($metric['numerator'] === 0 && $metric['denominator'] === 1 && (float)$metric['value'] === 0.0,
    'Calculator produces the corrected unfavorable result instead of manufacturing a score of five.');

// Schema compatibility: source-table SQL must not reference nota when absent.
// The selected pair comes from one latest survey row; it cannot mix fields
// from unrelated responses or discard a row answered through nota alone.
$queryMethod = new ReflectionMethod(Repository::class, 'callSql');
$noSurveySql = $queryMethod->invoke(null, false, false);
surveyVerify(!str_contains($noSurveySql, 'tab_pesquisa') && str_contains($noSurveySql, 'NULL AS satisfaction_nota'),
    'Call extraction still works when the survey table is unavailable.');
foreach ([false, true] as $hasNota) {
    $sql = $queryMethod->invoke(null, true, $hasNota);
    surveyVerify(preg_match('/phone_survey_ranked AS \((.*?)\n\)/s', $sql, $matches) === 1,
        'The survey query retains a single ranked survey source.');
    $surveySql = $matches[1];
    if ($hasNota) {
        surveyVerify(str_contains($surveySql, 'p.atendimento, p.nota AS nota'),
            'Both original fields are selected from the same physical survey row.');
        surveyVerify(str_contains($surveySql, '(p.atendimento IS NOT NULL OR p.nota IS NOT NULL)'),
            'A response with only nota is not dropped before the score can be recovered.');
    } else {
        surveyVerify(!str_contains($surveySql, 'p.nota') && str_contains($surveySql, 'NULL AS nota'),
            'An older physical schema never receives a reference to its missing nota column.');
        surveyVerify(str_contains($surveySql, 'WHERE p.atendimento IS NOT NULL'),
            'Older schemas preserve the original non-null survey selection.');
    }
    surveyVerify(str_contains($surveySql, 'PARTITION BY p.uniqueid ORDER BY p.`date` DESC, p.id DESC')
        && str_contains($sql, 'LEFT JOIN phone_survey_ranked p ON p.uniqueid = e.call_id AND p.rn = 1'),
        'The most recent survey is selected consistently by date and ID, once per call.');
}

echo "Phone survey selection checks passed: {$assertions} assertions.\n";
