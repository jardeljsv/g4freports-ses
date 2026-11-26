<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\MonthlyArchiveStore;
use GlpiPlugin\G4freports\Ses\MonthlyExport;
use GlpiPlugin\G4freports\Ses\SnapshotStore;

$root = sys_get_temp_dir() . '/g4fr-historic-monthly-' . bin2hex(random_bytes(8));
mkdir($root . '/docs', 0700, true); mkdir($root . '/tmp', 0700, true);
define('GLPI_PLUGIN_DOC_DIR', $root . '/docs');
define('GLPI_TMP_DIR', $root . '/tmp');
$checks = 0;
function historicMonthlyCheck(bool $condition, string $message): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function historicMonthlyStoredFiles(string $directory): array
{
    $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $result[substr($file->getPathname(), strlen($directory))] = hash_file('sha256', $file->getPathname());
    }
    ksort($result); return $result;
}
try {
    $snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/monthly_export_v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $snapshot = MonthlyExport::stamp($snapshot);
    $owner = 24;
    $token = SnapshotStore::save($snapshot, $owner);
    $draft = SnapshotStore::load($token, $owner);
    $revision = SnapshotStore::fingerprint($draft);
    $archive = MonthlyArchiveStore::archive($draft, $owner, $revision);
    $stored = MonthlyArchiveStore::loadSnapshot($owner, $revision, $draft['period']['month']);
    historicMonthlyCheck($stored === $draft, 'Archive preserves the full monthly snapshot including profile');
    $before = historicMonthlyStoredFiles($root . '/docs');
    $originalDownloads = [];
    foreach (['docx', 'xlsx'] as $format) {
        $originalDownloads[$format] = MonthlyExport::render($draft, $format);
        $historic = MonthlyExport::render($stored, $format);
        historicMonthlyCheck($historic === $originalDownloads[$format], 'Saved revision uses the same full monthly output as the temporary draft: ' . $format);
    }

    // A changed narrative is a separate revision; choosing the old one stays exact.
    $edited = $draft;
    $edited['manual']['notes'] = 'Texto da segunda revisão, sem alterar a primeira.';
    $edited['input']['notes'] = $edited['manual']['notes'];
    $edited['narrative_updated_at'] = '2026-10-26T12:00:00-03:00';
    $secondRevision = SnapshotStore::fingerprint($edited);
    MonthlyArchiveStore::archive($edited, $owner, $secondRevision);
    historicMonthlyCheck($secondRevision !== $revision, 'Two revisions of the same month retain distinct identities');
    foreach (['docx', 'xlsx'] as $format) {
        $first = MonthlyExport::render(MonthlyArchiveStore::loadSnapshot($owner, $revision, $draft['period']['month']), $format);
        $second = MonthlyExport::render(MonthlyArchiveStore::loadSnapshot($owner, $secondRevision, $draft['period']['month']), $format);
        historicMonthlyCheck($first === $originalDownloads[$format], 'Original selected revision remains exact after another archive: ' . $format);
        historicMonthlyCheck($second['binary'] !== $first['binary'], 'Second revision retains its own saved narrative: ' . $format);
    }

    // Simulate removal/expiry of every temporary draft: history remains independent.
    $temporaryFiles = glob($root . '/tmp/*/*.json') ?: [];
    historicMonthlyCheck(count($temporaryFiles) === 1, 'The live draft used a separate temporary store');
    foreach ($temporaryFiles as $file) unlink($file);
    try { SnapshotStore::load($token, $owner); historicMonthlyCheck(false, 'Removed temporary draft must not load'); }
    catch (RuntimeException $e) { historicMonthlyCheck(true, 'Temporary draft is no longer available'); }
    foreach (['docx', 'xlsx'] as $format) {
        $historic = MonthlyExport::render(MonthlyArchiveStore::loadSnapshot($owner, $revision, $draft['period']['month']), $format);
        historicMonthlyCheck($historic === $originalDownloads[$format], 'Historical monthly export survives draft expiry: ' . $format);
    }
    $after = historicMonthlyStoredFiles($root . '/docs');
    foreach ($before as $file => $hash) historicMonthlyCheck(($after[$file] ?? null) === $hash, 'Downloads never modify archived evidence: ' . $file);
    foreach (array_keys($after) as $file) historicMonthlyCheck(!str_ends_with($file, '.docx') && !str_ends_with($file, '.xlsx'), 'Office downloads are not persisted in the archive');
    historicMonthlyCheck(!class_exists('GlpiPlugin\\G4freports\\Ses\\Repository', false)
        && !class_exists('GlpiPlugin\\G4freports\\Ses\\Calculator', false), 'Historical generation never queries or re-extracts operational data');
    historicMonthlyCheck(!class_exists('GlpiPlugin\\G4freports\\Ses\\AnnualDocxExporter', false)
        && !class_exists('GlpiPlugin\\G4freports\\Ses\\AnnualXlsxExporter', false), 'Historical single-month downloads do not invoke annual exporters');
    historicMonthlyCheck(!glob($root . '/docs/g4freports/monthly_archive/' . $owner . '/*/*/activity*'), 'Normal monthly heatmaps do not require annual daily-cache preparation');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($root);
}
echo 'Historical monthly export: ' . $checks . " checks passed.\n";
