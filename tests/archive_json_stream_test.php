<?php
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\MonthlyArchiveStore;
use GlpiPlugin\G4freports\Ses\SnapshotStore;

if (($argv[1] ?? '') === '--read') {
    define('GLPI_PLUGIN_DOC_DIR', $argv[2]);
    $download = MonthlyArchiveStore::openSnapshotDownload(42, $argv[3], $argv[4]);
    $hash = hash_init('sha256'); $bytes = 0;
    while (!feof($download['handle'])) {
        $chunk = fread($download['handle'], 8192);
        if ($chunk === false) throw new RuntimeException('Stream read failed');
        $bytes += strlen($chunk); hash_update($hash, $chunk);
    }
    fclose($download['handle']);
    if ($bytes !== $download['bytes'] || hash_final($hash) !== $argv[3]) throw new RuntimeException('Exact streamed evidence mismatch');
    echo json_encode(['bytes' => $bytes, 'peak_bytes' => memory_get_peak_usage(true)]);
    exit;
}

$directory = sys_get_temp_dir() . '/g4fr-json-stream-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); define('GLPI_PLUGIN_DOC_DIR', $directory);
$checks = 0;
function streamCheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function streamReject(callable $callback, int $code, string $message): void {
    try { $callback(); } catch (RuntimeException $error) { streamCheck($error->getCode() === $code, $message); return; }
    throw new RuntimeException($message . ': unexpectedly accepted');
}
function streamCleanup(string $path): void {
    if (is_dir($path) && !is_link($path)) { foreach (new DirectoryIterator($path) as $file) if (!$file->isDot()) streamCleanup($file->getPathname()); rmdir($path); }
    else unlink($path);
}
try {
    $snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/monthly_export_v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $id = SnapshotStore::fingerprint($snapshot); $month = $snapshot['period']['month'];
    MonthlyArchiveStore::archive($snapshot, 42, $id);
    $path = $directory . '/g4freports/monthly_archive/42/' . $month . '/' . $id . '/snapshot.json';
    $original = file_get_contents($path);
    $download = MonthlyArchiveStore::openSnapshotDownload(42, $id, $month);
    streamCheck(ftell($download['handle']) === 0 && $download['bytes'] === strlen($original), 'Verified stream is rewound with exact length');
    streamCheck(stream_get_contents($download['handle']) === $original, 'Download returns exact archived bytes, not re-encoded JSON');
    fclose($download['handle']);
    streamReject(fn() => MonthlyArchiveStore::openSnapshotDownload(43, $id, $month), 404, 'Storage owner boundary preserved');
    file_put_contents($path, str_repeat('X', strlen($original)));
    streamReject(fn() => MonthlyArchiveStore::openSnapshotDownload(42, $id, $month), 503, 'Same-size corruption rejected before caller receives a stream');
    file_put_contents($path, $original . 'x');
    streamReject(fn() => MonthlyArchiveStore::openSnapshotDownload(42, $id, $month), 503, 'Manifest size mismatch rejected');
    file_put_contents($path, $original);
    chmod($path, 0644);
    streamReject(fn() => MonthlyArchiveStore::openSnapshotDownload(42, $id, $month), 503, 'Overpermissive evidence rejected');
    chmod($path, 0600);
    rename($path, $path . '.original'); symlink($path . '.original', $path);
    streamReject(fn() => MonthlyArchiveStore::openSnapshotDownload(42, $id, $month), 503, 'Evidence symlink rejected');
    unlink($path); rename($path . '.original', $path);
    link($path, $path . '.hardlink');
    streamReject(fn() => MonthlyArchiveStore::openSnapshotDownload(42, $id, $month), 503, 'Evidence hardlink rejected');
    unlink($path . '.hardlink');
    $download = MonthlyArchiveStore::openSnapshotDownload(42, $id, $month); fclose($download['handle']);
    streamCheck(hash_file('sha256', $path) === $id, 'Failed reads never alter evidence');

    $snapshot['_stream_probe'] = str_repeat('x', 24 * 1024 * 1024);
    $largeId = SnapshotStore::fingerprint($snapshot);
    MonthlyArchiveStore::archive($snapshot, 42, $largeId);
    unset($snapshot);
    $command = [PHP_BINARY, '-n', '-d', 'memory_limit=8M', __FILE__, '--read', $directory, $largeId, $month];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start bounded reader');
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    streamCheck($exit === 0, '24MiB archive streams under8MiB memory: ' . $output . $error);
    $result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    streamCheck($result['bytes'] > 24 * 1024 * 1024 && $result['peak_bytes'] < 8 * 1024 * 1024, 'Streaming memory remains bounded below snapshot size');
    echo "Archive JSON stream: $checks checks passed; " . $result['bytes'] . ' evidence bytes with ' . $result['peak_bytes'] . " peak bytes.\n";
} finally { streamCleanup($directory); }
