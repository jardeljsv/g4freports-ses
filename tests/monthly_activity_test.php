<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\{MonthlyActivity, MonthlyActivityReader, MonthlyArchiveStore as Archive, MonthlySummary, SnapshotStore};

$checks = 0;
function activityCheck(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException($message); }
function activityFailure(callable $call, int $code): void {
    try { $call(); } catch (RuntimeException | InvalidArgumentException $error) {
        activityCheck($error->getCode() === $code, 'Expected safe error code ' . $code . ', got ' . $error->getCode()); return;
    }
    throw new RuntimeException('Expected rejection ' . $code);
}
function activitySnapshot(string $month = '2024-02'): array {
    $start = $month . '-01 00:00:00';
    $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d H:i:s');
    return ['schema_version' => 2, 'rules_version' => 'frozen-rule', 'generated_at' => str_replace(' ', 'T', $end) . '-03:00',
        'period' => ['month' => $month, 'start' => $start, 'end_exclusive' => $end, 'date_basis' => 'solvedate', 'call_date_basis' => 'queue_entry'],
        'input' => ['month' => $month, 'timezone' => 'America/Sao_Paulo'], 'metrics' => [],
        'tickets' => [['id' => 1, 'solved_at' => $month . '-01 00:00:00'], ['id' => 2, 'solved_at' => $month . '-29 23:59:59.123456']],
        'calls' => [['id' => 'one', 'queue' => 'queue-a', 'entered_at' => $month . '-29 23:59:59', 'completed_at' => $end],
            ['id' => 'one', 'queue' => 'queue-b', 'entered_at' => $month . '-29 23:59:59']],
        'excluded_calls' => [['id' => 'test', 'queue' => 'queue-a', 'entered_at' => $month . '-01 10:00:00']],
        'totals' => ['tickets' => 2, 'calls_received' => 2, 'calls_excluded' => 1]];
}
function activityProject(array $snapshot): array {
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $project = new MonthlyActivity($snapshot['period']['month'], hash('sha256', $json));
    $handle = fopen('php://temp', 'w+b'); fwrite($handle, $json); rewind($handle);
    try { $metadata = MonthlyActivityReader::read($handle, [$project, 'add']); } finally { fclose($handle); }
    return $project->finish($metadata, MonthlySummary::fromSnapshot($snapshot));
}
function activityRemove(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $child) if (!$child->isDot()) activityRemove($child->getPathname());
    rmdir($path);
}

if (in_array($argv[1] ?? '', ['prepare-child', 'prepare-memory'], true)) {
    define('GLPI_PLUGIN_DOC_DIR', $argv[2]);
    try {
        $result = Archive::prepareActivity((int)$argv[3], $argv[4], $argv[5]);
        echo ($argv[1] === 'prepare-memory') ? json_encode(['peak' => memory_get_peak_usage(true), 'status' => $result['status'],
            'tickets' => $result['series']['tickets']['total']]) : 'prepared';
    }
    catch (RuntimeException $error) { if ($error->getCode() !== 409) throw $error; echo 'busy'; }
    exit;
}

$snapshot = activitySnapshot(); $activity = activityProject($snapshot);
activityCheck($activity['status'] === 'complete' && count($activity['days']) === 29, 'Leap month has 29 complete daily observations');
activityCheck($activity['days'][0]['tickets'] === 1 && $activity['days'][28]['tickets'] === 1, 'Strict stored solution timestamps group by recorded date');
activityCheck($activity['days'][28]['calls'] === 2 && $activity['days'][0]['excluded_calls'] === 1, 'Queue grain and test-call exclusion are preserved independently');
activityCheck($activity['days'][1]['tickets'] === 0 && $activity['days'][1]['calls'] === 0, 'Covered no-activity days are real zero');
activityCheck(MonthlyActivity::valid($activity, $activity['revision'], '2024-02'), 'Projection passes independent compact validation');
$broken = $activity; $broken['days'][0]['tickets']++;
activityCheck(!MonthlyActivity::valid($broken, $activity['revision'], '2024-02'), 'Compact validation rejects daily/assigned sum mismatch');
$broken = $activity; $broken['series']['tickets']['reconciled'] = false;
activityCheck(!MonthlyActivity::valid($broken, $activity['revision'], '2024-02'), 'Available projection requires reconciliation');
$broken = $activity; $broken['series']['tickets']['total']++;
activityCheck(!MonthlyActivity::valid($broken, $activity['revision'], '2024-02'), 'Available projection requires observed/frozen total agreement');
$broken = $activity; $broken['series']['tickets']['available'] = false; $broken['series']['tickets']['status'] = 'unavailable';
activityCheck(!MonthlyActivity::valid($broken, $activity['revision'], '2024-02'), 'Unavailable projection cannot carry colored daily values');
foreach ($broken['days'] as &$day) $day['tickets'] = null; unset($day); $broken['series']['tickets']['total'] = 99;
activityCheck(!MonthlyActivity::valid($broken, $activity['revision'], '2024-02'), 'Reconciled flag itself requires total/observed agreement even for unavailable date basis');
activityCheck(!str_contains(json_encode($activity), 'queue-a') && !str_contains(json_encode($activity), '"id":'), 'Projection contains no row identifiers or private evidence');
foreach (['tickets', 'calls', 'excluded_calls'] as $series) {
    activityCheck(array_sum(array_column($activity['days'], $series)) === $activity['series'][$series]['total'], 'Daily counts reconcile: ' . $series);
}
$bad = $snapshot;
$bad['tickets'] = [['solved_at' => '2024-02-30 12:00:00'], ['solved_at' => '2024-03-01 00:00:00'], ['solved_at' => null], ['solved_at' => '2024-02-01T00:00:00Z'], ['solved_at' => '2024-02-02 00:00:00']];
$bad['totals']['tickets'] = 5; $a = activityProject($bad);
activityCheck($a['series']['tickets']['status'] === 'partial' && $a['series']['tickets']['available'], 'Partial date coverage is explicit without rewriting frozen counts');
activityCheck($a['series']['tickets']['invalid_dates'] === 3 && $a['series']['tickets']['out_of_month'] === 1
    && $a['series']['tickets']['assigned'] === 1 && $a['series']['tickets']['unassigned'] === 4, 'Invalid, missing, offset and out-of-month dates reconcile');
$missing = $snapshot; unset($missing['tickets']); $a = activityProject($missing);
activityCheck(!$a['series']['tickets']['available'] && $a['days'][0]['tickets'] === null, 'Absent legacy collection is unavailable, never zero');
$zero = $snapshot; $zero['tickets'] = []; $zero['totals']['tickets'] = 0; $a = activityProject($zero);
activityCheck($a['series']['tickets']['available'] && array_unique(array_column($a['days'], 'tickets')) === [0], 'Present empty collection plus zero total supports a zero month');
$mismatch = $snapshot; $mismatch['totals']['tickets'] = 99; $a = activityProject($mismatch);
activityCheck(!$a['series']['tickets']['available'] && !$a['series']['tickets']['reconciled'] && $a['series']['tickets']['total'] === 99 && $a['days'][0]['tickets'] === null, 'Row mismatch hides daily series but preserves monthly total');
$wrongBasis = $snapshot; $wrongBasis['period']['date_basis'] = 'opened_at'; $a = activityProject($wrongBasis);
activityCheck(!$a['series']['tickets']['available'] && $a['series']['calls']['available'], 'Wrong date basis disables only affected population');
$partial = $snapshot; $partial['generated_at'] = '2024-02-02T02:00:00Z'; $a = activityProject($partial);
activityCheck($a['period_completeness'] === 'provisional' && $a['days'][0]['tickets'] === 1 && $a['days'][1]['tickets'] === null, 'Future dates respect extraction instant in archived timezone');
activityCheck($a['series']['tickets']['future_dates'] === 1 && $a['series']['tickets']['assigned'] === 1 && $a['series']['tickets']['unassigned'] === 1, 'Impossible future observations remain accounted as unassigned');
$unknown = $snapshot; $unknown['generated_at'] = 'tomorrow'; $a = activityProject($unknown);
activityCheck($a['period_completeness'] === 'unknown' && $a['days'][0]['tickets'] === 1 && $a['days'][1]['tickets'] === null, 'Unknown closure retains positive observations but never invents covered zero days');
$noTotals = $snapshot; unset($noTotals['totals']['tickets']); $a = activityProject($noTotals);
activityCheck(!$a['series']['tickets']['available'] && $a['series']['tickets']['total'] === null, 'Missing frozen total cannot be silently reconstructed');
foreach (['2026-04' => 30, '2026-01' => 31, '2100-02' => 28] as $month => $length) {
    $s = activitySnapshot($month); $s['tickets'] = []; $s['calls'] = []; $s['excluded_calls'] = [];
    $s['totals'] = ['tickets' => 0, 'calls_received' => 0, 'calls_excluded' => 0];
    activityCheck(count(activityProject($s)['days']) === $length, 'Exact Gregorian month length ' . $month);
}

$base = sys_get_temp_dir() . '/g4freports-activity-test-' . bin2hex(random_bytes(8)); mkdir($base, 0700);
define('GLPI_PLUGIN_DOC_DIR', $base);
try {
    $id = SnapshotStore::fingerprint($snapshot); $record = Archive::archive($snapshot, 1, $id);
    $directory = $base . '/g4freports/monthly_archive/1/2024-02/' . $id;
    $original = []; foreach (['snapshot.json', 'summary.json', 'manifest.json', 'manifest.sha256'] as $file) $original[$file] = hash_file('sha256', $directory . '/' . $file);
    activityCheck(Archive::loadActivity(1, $id, '2024-02') === null, 'Existing unprepared archive remains readable without daily detail');
    $raw = file_get_contents($directory . '/snapshot.json'); $damage = $raw; $damage[0] = '[';
    file_put_contents($directory . '/snapshot.json', $damage);
    activityCheck(Archive::loadActivity(1, $id, '2024-02') === null, 'Compact-only load does not open corrupted raw evidence');
    activityFailure(fn() => Archive::prepareActivity(1, $id, '2024-02'), 503);
    activityCheck(!file_exists($directory . '/activity-v1.json'), 'Failed full validation publishes no partial cache');
    file_put_contents($directory . '/snapshot.json', $raw);
    $prepared = Archive::prepareActivity(1, $id, '2024-02');
    activityCheck($prepared === $activity && Archive::loadActivity(1, $id, '2024-02') === $prepared, 'Prepared projection matches direct reducer and reload');
    activityCheck(Archive::prepareActivity(1, $id, '2024-02') === $prepared, 'Repeat preparation is idempotent');
    foreach ($original as $file => $hash) activityCheck(hash_file('sha256', $directory . '/' . $file) === $hash, 'Original immutable member unchanged: ' . $file);
    activityCheck((fileperms($directory . '/activity-v1.json') & 0777) === 0600 && filesize($directory . '/activity-v1.json') < Archive::MAX_ACTIVITY_BYTES, 'Private compact sidecar');
    activityCheck(Archive::loadSummary(1, $id, '2024-02') === $record, 'Original frozen summary remains byte-for-byte unchanged');
    activityFailure(fn() => Archive::loadActivity(2, $id, '2024-02'), 404);
    activityFailure(fn() => Archive::prepareActivity(1, '../escape', '2024-02'), 400);
    activityFailure(fn() => Archive::prepareActivity(1, $id, '2024-01'), 404);

    Archive::archive($snapshot, 2, $id);
    $lock = fopen($base . '/g4freports/monthly_archive/.activity.lock', 'r+b'); flock($lock, LOCK_EX | LOCK_NB);
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'prepare-child', $base, '2', $id, '2024-02'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $result = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    activityCheck($exit === 0 && $result === 'busy', 'Global nonblocking preparation admission works across users/processes: ' . $error);
    activityCheck(Archive::prepareActivity(1, $id, '2024-02') === $prepared, 'Existing compact cache needs no heavy preparation slot');
    flock($lock, LOCK_UN); fclose($lock);
    activityCheck(Archive::prepareActivity(2, $id, '2024-02') === $prepared, 'Admission released after contention');

    // End-to-end preparation, including manifest, summary and sidecar, must not decode
    // an entire 48 MiB evidence file. Build the large immutable fixture as a stream.
    Archive::archive($snapshot, 3, $id);
    $largeDirectory = $base . '/g4freports/monthly_archive/3/2024-02/' . $id;
    $largePath = $largeDirectory . '/snapshot.json';
    $stream = fopen($largePath, 'wb'); fwrite($stream, '{"unused_evidence":"'); $chunk = str_repeat('x', 65536);
    for ($part = 0; $part < 768; $part++) fwrite($stream, $chunk);
    fwrite($stream, '",' . substr($raw, 1)); fclose($stream); unset($chunk);
    $largeId = hash_file('sha256', $largePath); $manifest = json_decode(file_get_contents($largeDirectory . '/manifest.json'), true);
    $manifest['id'] = $manifest['snapshot_revision'] = $manifest['snapshot']['sha256'] = $largeId;
    clearstatcache(true, $largePath); $manifest['snapshot']['bytes'] = filesize($largePath);
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($largeDirectory . '/manifest.json', $manifestJson); file_put_contents($largeDirectory . '/manifest.sha256', hash('sha256', $manifestJson));
    $newDirectory = dirname($largeDirectory) . '/' . $largeId; rename($largeDirectory, $newDirectory);
    $process = proc_open([PHP_BINARY, '-n', '-d', 'memory_limit=24M', __FILE__, 'prepare-memory', $base, '3', $largeId, '2024-02'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $result = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    $proof = json_decode($result, true);
    activityCheck($exit === 0 && is_array($proof) && $proof['status'] === 'complete' && $proof['tickets'] === 2
        && $proof['peak'] < 24 * 1024 * 1024, '48 MiB archive preparation stays below24MiB PHP memory, including sidecar publication: ' . $error);
    activityCheck(hash_file('sha256', $newDirectory . '/snapshot.json') === $largeId, 'Large original evidence unchanged after bounded preparation');

    $cachePath = $directory . '/activity-v1.json'; $cache = file_get_contents($cachePath); $broken = json_decode($cache, true); $broken['payload'] .= ' ';
    file_put_contents($cachePath, json_encode($broken));
    activityCheck(Archive::loadActivity(1, $id, '2024-02') === null, 'Corrupt optional cache cannot prevent annual values from loading');
    activityCheck(Archive::loadSummary(1, $id, '2024-02') === $record, 'Corrupt cache leaves original summary usable');
    activityFailure(fn() => Archive::prepareActivity(1, $id, '2024-02'), 503);
    file_put_contents($cachePath, $cache);
    if (function_exists('symlink')) {
        rename($cachePath, $cachePath . '.original'); symlink($cachePath . '.original', $cachePath);
        activityCheck(Archive::loadActivity(1, $id, '2024-02') === null, 'Optional symlink cache is never followed');
        activityFailure(fn() => Archive::prepareActivity(1, $id, '2024-02'), 503);
        unlink($cachePath); rename($cachePath . '.original', $cachePath);
    }
    chmod($cachePath, 0644);
    activityCheck(Archive::loadActivity(1, $id, '2024-02') === null, 'Publicly readable optional cache rejected');
    chmod($cachePath, 0600);
    foreach ($original as $file => $hash) activityCheck(hash_file('sha256', $directory . '/' . $file) === $hash, 'All failure paths leave original member unchanged: ' . $file);
    echo "Monthly activity: $checks assertions passed; immutable daily projection, bounded preparation, owner scope and no SQL.\n";
} finally { activityRemove($base); }
