<?php
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\ArchiveAccess;
use GlpiPlugin\G4freports\Ses\AnnualSelection;
use GlpiPlugin\G4freports\Ses\ExportAdmission;
use GlpiPlugin\G4freports\Ses\MonthlyArchiveStore;
use GlpiPlugin\G4freports\Ses\SharedArchiveCatalogue;
use GlpiPlugin\G4freports\Ses\SnapshotStore;

define('UPDATE', 2);
$testUser = 42; $testAdmin = false;
class Session {
    public static function getLoginUserID(): int { return $GLOBALS['testUser']; }
    public static function haveRight($right, $level): bool { return $GLOBALS['testAdmin']; }
}
class SharedTestDatabase {
    public array $memberships = [42 => [55, 56], 43 => [55], 44 => [56]];
    public int $queries = 0;
    public bool $fail = false;
    public function request(array $query): array {
        $this->queries++;
        if ($this->fail) throw new RuntimeException('Unavailable host membership');
        if (($query['FROM'] ?? '') !== 'glpi_groups_users') throw new RuntimeException('Unexpected source query');
        return array_map(static fn($id) => ['groups_id' => $id], $this->memberships[$query['WHERE']['users_id']] ?? []);
    }
}
$DB = new SharedTestDatabase();
$_SESSION = ['glpigroups' => [55, 56]];
$root = sys_get_temp_dir() . '/g4fr-shared-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/documents', 0700); mkdir($root . '/temporary', 0700);
define('GLPI_PLUGIN_DOC_DIR', $root . '/documents'); define('GLPI_TMP_DIR', $root . '/temporary'); define('GLPI_ROOT', $root . '/installation');
$checks = 0;
function sharedCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function sharedThrows(callable $callback, int $code, string $message): void {
    try { $callback(); } catch (RuntimeException $error) { sharedCheck($error->getCode() === $code, $message . ': ' . $error->getMessage()); return; }
    throw new RuntimeException($message . ': expected rejection');
}
function sharedHashes(string $path): array {
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $hashes[substr($file->getPathname(), strlen($path))] = hash_file('sha256', $file->getPathname());
    }
    return $hashes;
}
function sharedRemove(string $path): void {
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) sharedRemove($entry->getPathname()); rmdir($path); }
    else unlink($path);
}
try {
    $configuration = ['ses_archive_shared_groups' => '[55]'];
    sharedCheck(ArchiveAccess::status($configuration) === ['enabled' => true, 'allowed' => true], 'Authoritative group grants sharing');
    sharedCheck(ArchiveAccess::status(['ses_archive_shared_groups' => '[1]'])['allowed'] === false, 'Numeric session list index is never group membership');
    sharedCheck(ArchiveAccess::status(['ses_archive_shared_groups' => '55']) === ['enabled' => false, 'allowed' => false], 'Malformed stored configuration fails closed');
    sharedCheck(ArchiveAccess::status([])['allowed'] === false, 'No configured audience means no automatic sharing');
    $DB->fail = true;
    sharedCheck(!ArchiveAccess::status($configuration)['allowed'], 'Host membership failure fails closed');
    $DB->fail = false;
    $testAdmin = true;
    sharedCheck(ArchiveAccess::status($configuration)['allowed'] && !ArchiveAccess::status([])['allowed'], 'Administrator requires configured sharing scope');
    $testAdmin = false;

    $snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/monthly_export_v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $id = SnapshotStore::fingerprint($snapshot); $month = $snapshot['period']['month']; $year = (int)substr($month, 0, 4);
    $record = MonthlyArchiveStore::archive($snapshot, 42, $id);
    $other = MonthlyArchiveStore::archive($snapshot, 43, $id);
    $before = sharedHashes(GLPI_PLUGIN_DOC_DIR . '/g4freports/monthly_archive/42');
    $listed = ArchiveAccess::listYear(42, $year, $configuration);
    sharedCheck(count($listed) === 1 && $listed[0]['visibility'] === 'private' && $listed[0]['can_share'], 'Existing personal history is not automatically published');
    $shared = ArchiveAccess::publish(42, $id, $month, $configuration);
    sharedCheck($shared['creator_id'] === 42 && $shared['visibility'] === 'shared' && !$shared['can_share'], 'Publication preserves author and exposes shared identity');
    sharedCheck(sharedHashes(GLPI_PLUGIN_DOC_DIR . '/g4freports/monthly_archive/42') === $before, 'Sharing does not mutate archived snapshot, summary, manifest or digest');
    sharedCheck(ArchiveAccess::publish(42, $id, $month, $configuration) === $shared, 'Publication retry is idempotent');
    $reference = $shared['archive_ref'];
    sharedCheck($reference !== $id, 'Public reference differs from content hash');
    sharedCheck(count(SharedArchiveCatalogue::listYear($year)) === 1, 'Idempotent publish keeps one catalogue entry');
    sharedCheck(count(ArchiveAccess::listYear(42, $year, $configuration)) === 1, 'Owner catalogue merges personal and published revision once');

    $testUser = 43;
    $resolved = ArchiveAccess::resolve(43, $id, $month, $reference, $configuration);
    sharedCheck($resolved['owner'] === 42, 'Shared reference resolves original owner rather than requester');
    $otherShared = ArchiveAccess::publish(43, $id, $month, $configuration);
    sharedCheck($otherShared['archive_ref'] !== $reference && $otherShared['id'] === $id, 'Identical snapshots from different creators retain separate identities');
    sharedCheck(count(ArchiveAccess::listYear(43, $year, $configuration)) === 2, 'Both authors remain visible without duplicate personal row');
    $selections = [['id' => $id, 'month' => $month, 'archive_ref' => $reference], ['id' => $id, 'month' => $month, 'archive_ref' => $otherShared['archive_ref']]];
    $DB->queries = 0;
    sharedCheck(count(ArchiveAccess::resolveMany(43, $selections, $configuration)) === 2 && $DB->queries === 1, 'Batch checks membership once rather than once per selected archive');
    $parsed = AnnualSelection::parse(json_encode(['months' => [$month], 'selections' => [$selections[0]]]));
    sharedCheck($parsed['selections'][0]['archive_ref'] === $reference, 'Annual payload preserves explicit shared reference');
    $batch = AnnualSelection::records($parsed, 43, $configuration);
    sharedCheck($batch[0]['id'] === $id && $batch[0]['summary']['month'] === $month, 'Annual read uses authorized original compact summary');

    $testUser = 44;
    sharedCheck(ArchiveAccess::listYear(44, $year, $configuration) === [], 'Unrelated group cannot see shared metadata');
    sharedThrows(fn() => ArchiveAccess::resolve(44, $id, $month, $reference, $configuration), 404, 'Cross-scope reference is denied without disclosure');
    sharedThrows(fn() => ArchiveAccess::publish(44, $id, $month, $configuration), 403, 'Unrelated group cannot publish');
    sharedThrows(fn() => ArchiveAccess::resolve(43, $id, $month, $reference, $configuration), 404, 'Caller cannot claim another requester identity');
    $testUser = 43; $DB->memberships[43] = [];
    sharedThrows(fn() => ArchiveAccess::resolve(43, $id, $month, $reference, $configuration), 404, 'Membership removal revokes next shared read');
    sharedCheck(ArchiveAccess::resolve(43, $id, $month, $otherShared['archive_ref'], $configuration)['owner'] === 43, 'Owner retains own published report after removal');
    sharedCheck(ArchiveAccess::listYear(43, $year, $configuration)[0]['visibility'] === 'personal', 'Owner-only view does not falsely label prior publication private');
    $DB->memberships[43] = [55];

    $index = GLPI_PLUGIN_DOC_DIR . '/g4freports/monthly_archive/shared_catalogue/' . $year . '.json';
    $original = file_get_contents($index); file_put_contents($index, '{damaged');
    sharedThrows(fn() => ArchiveAccess::listYear(43, $year, $configuration), 503, 'Shared index corruption fails explicitly');
    $testUser = 42;
    sharedCheck(count(ArchiveAccess::listYear(42, $year, [])) === 1, 'Disabled sharing preserves private history despite corrupt index');
    sharedCheck(ArchiveAccess::resolve(42, $id, $month, $reference, [])['owner'] === 42, 'Own saved link bypasses unused corrupt catalogue');
    $testUser = 44;
    sharedThrows(fn() => ArchiveAccess::resolve(44, $id, $month, $reference, $configuration), 404, 'Unauthorized requester never reads corrupt catalogue');
    file_put_contents($index, $original); chmod($index, 0600);
    $testUser = 43;
    chmod($index, 0644);
    sharedThrows(fn() => ArchiveAccess::listYear(43, $year, $configuration), 503, 'Overpermissive shared metadata is rejected');
    chmod($index, 0600);
    rename($index, $index . '.original'); symlink($index . '.original', $index);
    sharedThrows(fn() => ArchiveAccess::listYear(43, $year, $configuration), 503, 'Shared index symlink is not followed');
    unlink($index); rename($index . '.original', $index);
    link($index, $index . '.hardlink');
    sharedThrows(fn() => ArchiveAccess::listYear(43, $year, $configuration), 503, 'Shared index hardlink is rejected');
    unlink($index . '.hardlink');
    file_put_contents($index, str_repeat('X', SharedArchiveCatalogue::MAX_BYTES + 1));
    sharedThrows(fn() => ArchiveAccess::listYear(43, $year, $configuration), 503, 'Catalogue byte bound is enforced before decoding');
    file_put_contents($index, $original);
    sharedCheck(count(ArchiveAccess::listYear(43, $year, $configuration)) === 2, 'Restoring valid index recovers shared views without changing archives');

    $lock = ExportAdmission::acquire();
    sharedThrows(fn() => ExportAdmission::acquire(), 409, 'Concurrent monthly admission is nonblocking');
    ExportAdmission::release($lock); ExportAdmission::release(null);
    $lock = ExportAdmission::acquire(); ExportAdmission::release($lock);
    sharedCheck(true, 'Admission slot is reusable after completion');
    sharedCheck(sharedHashes(GLPI_PLUGIN_DOC_DIR . '/g4freports/monthly_archive/42') === $before, 'All authorized reads preserve original archive bytes');
    echo "Shared archive access: $checks checks passed.\n";
} finally { sharedRemove($root); }
