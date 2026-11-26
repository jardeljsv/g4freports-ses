<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\MonthlyArchiveStore as Archive;
use GlpiPlugin\G4freports\Ses\MonthlySummary;
use GlpiPlugin\G4freports\Ses\SnapshotStore;

$checks = 0;
function archiveCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function archiveFailure(callable $operation, int $code, string $label): void
{
    try { $operation(); throw new LogicException('Accepted: ' . $label); }
    catch (InvalidArgumentException | RuntimeException $error) {
        archiveCheck($error->getCode() === $code, $label . ': expected ' . $code . ', got ' . $error->getCode());
        archiveCheck(strpos($error->getMessage(), sys_get_temp_dir()) === false, $label . ': error exposes a storage path');
    }
}
function archiveJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}
function archiveFingerprint(array $value): string { return hash('sha256', archiveJson($value)); }
function archiveFixture(string $month = '2026-06'): array
{
    $metrics = [];
    for ($number = 1; $number <= 12; $number++) {
        $id = 'INS' . $number;
        $less = in_array($id, ['INS5', 'INS9', 'INS10'], true);
        $metrics[] = ['id' => $id, 'label' => 'Indicador ' . $number, 'status' => 'met',
            'value' => $less ? 0 : 100, 'numerator' => $less ? 0 : 10, 'denominator' => 10,
            'target' => $less ? 10 : 90, 'direction' => $less ? 'lte' : 'gte',
            'method' => 'Regra preservada.', 'note' => 'Apuração mensal.'];
    }
    return ['schema_version' => 2, 'rules_version' => 'ses-2026-10-05-v16',
        'generated_at' => '2026-07-03T12:00:00-03:00',
        'period' => ['month' => $month, 'start' => $month . '-01 00:00:00', 'end_exclusive' => '2026-07-01 00:00:00',
            'label' => 'Junho de 2026', 'date_basis' => 'solvedate', 'call_date_basis' => 'queue_entry'],
        'input' => ['month' => $month, 'report_title' => 'Relatório conferido', 'entity_id' => 1,
            'group_ids' => [], 'queue_filter' => 'ssdf', 'timezone' => 'America/Sao_Paulo',
            'priority_map' => ['INS1' => 5, 'INS2' => 4, 'INS3' => 3, 'INS4' => 2],
            'n1_group_ids' => [55], 'n2_group_ids' => [56], 'n3_group_ids' => [57], 'n1_categories' => [171],
            'ins6_mode' => 'assigned_n1', 'ins12_mode' => 'glpi_and_phone', 'kb_mode' => 'category_proxy',
            'staff_mode' => 'register', 'test_numbers' => []],
        'source_identity' => ['glpi' => str_repeat('a', 64), 'callcenter' => str_repeat('b', 64)],
        'metrics' => $metrics, 'totals' => ['tickets' => 10, 'calls_received' => 10],
        'financial' => ['available' => true, 'complete' => true, 'gross_value' => '1000.01',
            'deduction_total' => '10.00', 'adjustment_total' => '-0.01', 'net_value' => '990.00', 'notes' => []],
        'manual' => ['executive_summary' => 'Resumo original.', 'actions' => 'Ação original.', 'notes' => 'Nota original.', 'phone_survey_actions' => ''],
        'warnings' => [], 'staff_register' => ['configured' => true, 'complete' => true],
        'tickets' => [['id' => 7, 'private_evidence' => 'Detalhe original.']], 'calls' => []];
}
function archiveRemoveTree(string $directory): void
{
    if (is_link($directory) || is_file($directory)) { unlink($directory); return; }
    if (!is_dir($directory)) return;
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isDot()) archiveRemoveTree($file->getPathname());
    }
    rmdir($directory);
}
function archiveChild(array $arguments): array
{
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, __FILE__], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start archive test subprocess.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}

$mode = $argv[1] ?? '';
if ($mode === 'missing-storage') {
    archiveFailure(static function () { Archive::listYear(1, 2026); }, 503, 'No temporary fallback without GLPI plugin storage');
    echo "missing storage rejected\n";
    exit;
}
if ($mode === 'save-child' || $mode === 'oversized-snapshot') {
    define('GLPI_PLUGIN_DOC_DIR', $argv[2]);
    $snapshot = archiveFixture();
    if ($mode === 'oversized-snapshot') {
        ini_set('memory_limit', '256M');
        $snapshot['tickets'][0]['private_evidence'] = str_repeat('x', Archive::MAX_SNAPSHOT_BYTES);
        archiveFailure(static function () use ($snapshot) { Archive::archive($snapshot, 20, archiveFingerprint($snapshot)); }, 400, 'Oversized snapshot rejected before writing');
        echo "snapshot bound enforced\n";
        exit;
    }
    try {
        $record = Archive::archive($snapshot, (int)$argv[3], archiveFingerprint($snapshot));
        echo $record['id'];
        exit;
    } catch (RuntimeException $error) {
        if ($error->getCode() === 409) { echo 'busy'; exit; }
        throw $error;
    }
}

$base = sys_get_temp_dir() . '/g4freports-archive-test-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
define('GLPI_PLUGIN_DOC_DIR', $base);
try {
    [$exit, $output, $error] = archiveChild(['missing-storage']);
    archiveCheck($exit === 0 && strpos($output, 'rejected') !== false, 'Missing storage subprocess: ' . $error);
    archiveCheck(Archive::listYear(1, 2026) === [], 'Empty archive is listed without creating data');
    archiveCheck(!file_exists($base . '/g4freports'), 'Read-only listing does not create directories');

    // GLPI may already own a normal 0755 plugin document parent.
    mkdir($base . '/g4freports', 0755); chmod($base . '/g4freports', 0755);
    $snapshot = archiveFixture();
    $revision = archiveFingerprint($snapshot);
    if (method_exists(SnapshotStore::class, 'fingerprint')) {
        archiveCheck(SnapshotStore::fingerprint($snapshot) === $revision, 'Store fingerprint matches draft API encoding');
    }
    $record = Archive::archive($snapshot, 1, $revision);
    $directory = $base . '/g4freports/monthly_archive/1/2026-06/' . $record['id'];
    archiveCheck($record['id'] === $revision && $record['snapshot_revision'] === $revision, 'Immutable content ID and expected snapshot fingerprint');
    archiveCheck($record['summary'] === MonthlySummary::fromSnapshot($snapshot), 'Archive stores exact summary projection');
    archiveCheck(Archive::loadSummary(1, $record['id'], '2026-06') === $record, 'Summary load returns original wrapper');
    archiveCheck(archiveJson(Archive::loadSnapshot(1, $record['id'], '2026-06')) === archiveJson($snapshot), 'Evidence download preserves serialized snapshot');
    archiveCheck((fileperms($base . '/g4freports') & 0777) === 0755, 'Existing plugin parent permissions are preserved');
    foreach (['monthly_archive', 'monthly_archive/1', 'monthly_archive/1/2026-06', 'monthly_archive/1/2026-06/' . $record['id']] as $path) {
        archiveCheck((fileperms($base . '/g4freports/' . $path) & 0777) === 0700, 'Private archive directory permissions');
    }
    foreach (['snapshot.json', 'summary.json', 'manifest.json', 'manifest.sha256'] as $file) {
        archiveCheck((fileperms($directory . '/' . $file) & 0777) === 0600, 'Private archive file permissions');
    }
    $listed = Archive::listYear(1, 2026);
    archiveCheck(count($listed) === 1 && $listed[0]['source_status'] === 'known', 'Year catalogue lists published own revisions');
    archiveCheck(!isset($listed[0]['summary'], $listed[0]['snapshot'], $listed[0]['manual']), 'Catalogue does not carry full summary or evidence');
    archiveCheck($listed[0]['title'] === 'Relatório conferido' && $listed[0]['financial_complete'], 'Catalogue fields usable without reopening monthly input');
    archiveCheck(Archive::archive($snapshot, 1, $revision) === $record && count(Archive::listYear(1, 2026)) === 1, 'Repeated request is idempotent and retains archival timestamp');

    archiveFailure(static function () use ($record) { Archive::loadSummary(2, $record['id'], '2026-06'); }, 404, 'Other owner cannot load a revision');
    archiveFailure(static function () use ($record) { Archive::loadSnapshot(1, $record['id'], '2026-05'); }, 404, 'Wrong month cannot select evidence');
    archiveFailure(static function () { Archive::loadSummary(1, '../outside', '2026-06'); }, 400, 'Path traversal ID rejected');
    archiveFailure(static function () { Archive::listYear(0, 2026); }, 400, 'Invalid owner rejected');
    archiveFailure(static function () { Archive::listYear(1, 1999); }, 400, 'Out-of-range year rejected');

    $changed = $snapshot;
    $changed['manual']['notes'] = 'Nota nova.';
    archiveFailure(static function () use ($changed, $revision) { Archive::archive($changed, 1, $revision); }, 409, 'Review fingerprint detects changed draft');
    archiveCheck(count(Archive::listYear(1, 2026)) === 1, 'Stale request writes no new revision');
    $second = Archive::archive($changed, 1, archiveFingerprint($changed));
    archiveCheck($second['id'] !== $record['id'] && count(Archive::listYear(1, 2026)) === 2, 'New narrative produces another immutable revision');
    archiveCheck(Archive::loadSummary(1, $record['id'], '2026-06')['summary']['manual']['notes'] === 'Nota original.', 'Original narrative remains unchanged');
    $snapshot['tickets'][0]['private_evidence'] = 'Changed in caller memory';
    archiveCheck(Archive::loadSnapshot(1, $record['id'], '2026-06')['tickets'][0]['private_evidence'] === 'Detalhe original.', 'Caller mutation cannot alter archived evidence');
    $snapshot = archiveFixture();
    $otherOwner = Archive::archive($snapshot, 2, $revision);
    archiveCheck($otherOwner['id'] === $record['id'] && count(Archive::listYear(2, 2026)) === 1, 'Identical content remains independently owner scoped');

    $incomplete = $snapshot; unset($incomplete['metrics'][0]);
    $partial = Archive::archive($incomplete, 4, archiveFingerprint($incomplete));
    archiveCheck(Archive::listYear(4, 2026)[0]['metric_statuses']['INS1'] === 'missing', 'Missing metric projection remains listable');
    archiveCheck(Archive::loadSummary(4, $partial['id'], '2026-06')['summary']['metrics']['INS1']['status'] === 'missing', 'Missing metric is never an approval');
    foreach ([['schema_version', 1], ['period', ['month' => '2026-13']], ['period', []], ['input', ['month' => '2026-05']]] as $change) {
        $invalid = $snapshot; $invalid[$change[0]] = $change[1];
        archiveFailure(static function () use ($invalid) { Archive::archive($invalid, 1, archiveFingerprint($invalid)); }, 400, 'Unsupported or inconsistent snapshot rejected before publication');
    }

    $monthDirectory = dirname($directory);
    mkdir($monthDirectory . '/.tmp-interrupted', 0700);
    file_put_contents($monthDirectory . '/.tmp-interrupted/snapshot.json', '{');
    archiveCheck(count(Archive::listYear(1, 2026)) === 2, 'Interrupted staging bundle is not a valid revision');
    $lock = fopen($monthDirectory . '/.archive.lock', 'r+b');
    flock($lock, LOCK_EX | LOCK_NB);
    [$exit, $output, $error] = archiveChild(['save-child', $base, '1']);
    archiveCheck($exit === 0 && $output === 'busy', 'Another worker gets bounded lock conflict: ' . $error);
    flock($lock, LOCK_UN); fclose($lock);
    archiveCheck(count(Archive::listYear(1, 2026)) === 2, 'Contention does not create revisions');

    // Listing must not open large snapshot/summary contents. Selected reads verify hashes.
    foreach (['snapshot.json', 'summary.json', 'manifest.json', 'manifest.sha256'] as $file) {
        $path = $directory . '/' . $file;
        $original = file_get_contents($path);
        $damaged = $original; $damaged[0] = $damaged[0] === 'x' ? 'y' : 'x';
        file_put_contents($path, $damaged);
        if ($file === 'snapshot.json') {
            archiveCheck(count(Archive::listYear(1, 2026)) === 2, 'Catalogue does not read large snapshot contents');
            archiveCheck(Archive::loadSummary(1, $record['id'], '2026-06') === $record, 'Annual summary read does not load evidence');
            archiveFailure(static function () use ($record) { Archive::loadSnapshot(1, $record['id'], '2026-06'); }, 503, 'Evidence checksum enforced');
            archiveFailure(static function () use ($snapshot, $revision) { Archive::archive($snapshot, 1, $revision); }, 503, 'Retry does not claim damaged evidence is archived');
        } elseif ($file === 'summary.json') {
            archiveCheck(count(Archive::listYear(1, 2026)) === 2, 'Catalogue does not load summary narratives');
            archiveFailure(static function () use ($record) { Archive::loadSummary(1, $record['id'], '2026-06'); }, 503, 'Summary checksum enforced');
        } else {
            archiveFailure(static function () { Archive::listYear(1, 2026); }, 503, 'Catalogue manifest integrity enforced');
        }
        file_put_contents($path, $original);
    }
    chmod($directory . '/summary.json', 0644);
    archiveFailure(static function () use ($record) { Archive::loadSummary(1, $record['id'], '2026-06'); }, 503, 'Publicly readable managed files rejected');
    chmod($directory . '/summary.json', 0600);
    chmod($base . '/g4freports/monthly_archive/1', 0755);
    archiveFailure(static function () { Archive::listYear(1, 2026); }, 503, 'Publicly traversable owner directory rejected');
    chmod($base . '/g4freports/monthly_archive/1', 0700);

    if (function_exists('symlink')) {
        symlink($base . '/g4freports/monthly_archive/1', $base . '/g4freports/monthly_archive/8');
        archiveFailure(static function () { Archive::listYear(8, 2026); }, 503, 'Owner directory symlink rejected');
        unlink($base . '/g4freports/monthly_archive/8');
        rename($directory . '/summary.json', $directory . '/summary.original');
        symlink($directory . '/summary.original', $directory . '/summary.json');
        archiveFailure(static function () use ($record) { Archive::loadSummary(1, $record['id'], '2026-06'); }, 503, 'Bundle file symlink rejected');
        unlink($directory . '/summary.json'); rename($directory . '/summary.original', $directory . '/summary.json');
        rename($monthDirectory . '/.archive.lock', $monthDirectory . '/.lock-original');
        symlink($monthDirectory . '/.lock-original', $monthDirectory . '/.archive.lock');
        archiveFailure(static function () use ($snapshot, $revision) { Archive::archive($snapshot, 1, $revision); }, 503, 'Lock symlink rejected');
        unlink($monthDirectory . '/.archive.lock'); rename($monthDirectory . '/.lock-original', $monthDirectory . '/.archive.lock');
    }
    if (function_exists('link')) {
        link($directory . '/snapshot.json', $base . '/extra-evidence-link');
        archiveFailure(static function () use ($record) { Archive::loadSnapshot(1, $record['id'], '2026-06'); }, 503, 'Multiply linked evidence rejected');
        unlink($base . '/extra-evidence-link');
    }

    $tooMuchSummary = $snapshot; $tooMuchSummary['manual']['notes'] = str_repeat('x', Archive::MAX_SUMMARY_BYTES);
    archiveFailure(static function () use ($tooMuchSummary) { Archive::archive($tooMuchSummary, 11, archiveFingerprint($tooMuchSummary)); }, 400, 'Summary byte limit enforced');
    archiveCheck(Archive::listYear(11, 2026) === [], 'Summary limit failure creates no published month');
    [$exit, $output, $error] = archiveChild(['oversized-snapshot', $base]);
    archiveCheck($exit === 0 && strpos($output, 'bound enforced') !== false, 'Snapshot bound subprocess: ' . $error);
    archiveCheck(Archive::listYear(20, 2026) === [], 'Snapshot size failure leaves no archive');

    for ($number = 0; $number < Archive::MAX_REVISIONS_PER_MONTH; $number++) {
        $revisionSnapshot = $snapshot; $revisionSnapshot['manual']['notes'] = 'Revision ' . $number;
        Archive::archive($revisionSnapshot, 3, archiveFingerprint($revisionSnapshot));
    }
    archiveCheck(count(Archive::listYear(3, 2026)) === 50, 'All bounded month revisions remain available');
    archiveFailure(static function () use ($snapshot, $revision) { Archive::archive($snapshot, 3, $revision); }, 409, 'Revision quota rejects instead of deleting');
    archiveCheck(count(Archive::listYear(3, 2026)) === 50, 'Revision quota does not discard history');
    archiveCheck(strlen(archiveJson(Archive::listYear(3, 2026))) < Archive::MAX_CATALOG_BYTES, 'Catalogue remains under its byte limit');
    echo 'Monthly archive store: ' . $checks . " assertions passed; private immutable revisions, owner scope, checksums, bounded contention and no source access.\n";
} finally {
    archiveRemoveTree($base);
}
