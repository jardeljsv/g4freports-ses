<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\MonthlyActivityReader as Reader;

if (($argv[1] ?? '') === '--memory') {
    $handle = fopen($argv[2], 'rb'); $count = 0;
    $metadata = Reader::read($handle, static function () use (&$count) { $count++; });
    fclose($handle);
    echo json_encode(['bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256'],
        'count' => $count, 'peak' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
    exit;
}

$checks = 0;
function readerCheck(bool $condition, string $message): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function readerParse(string $json, array $limits = []): array
{
    $handle = fopen('php://temp', 'w+b'); fwrite($handle, $json); rewind($handle); $rows = [];
    try {
        $metadata = Reader::read($handle, static function ($name, $row) use (&$rows) { $rows[$name][] = $row; }, $limits);
        return [$metadata, $rows];
    } finally { fclose($handle); }
}
function readerReject(string $json, string $message, array $limits = []): void
{
    try { readerParse($json, $limits); }
    catch (RuntimeException $error) {
        readerCheck($error->getCode() === 503 && strpos($error->getMessage(), 'safe-path-sentinel') === false, $message);
        return;
    }
    readerCheck(false, 'Accepted: ' . $message);
}

$snapshot = ['schema_version' => 2, 'generated_at' => '2026-07-01T00:00:00-03:00',
    'period' => ['month' => '2026-06', 'start' => '2026-06-01 00:00:00', 'end_exclusive' => '2026-07-01 00:00:00',
        'date_basis' => 'solvedate', 'call_date_basis' => 'queue_entry', 'private' => ['sentinel']],
    'input' => ['month' => '2026-06', 'timezone' => 'America/Sao_Paulo', 'password' => 'PRIVATE'],
    'totals' => ['tickets' => 1, 'calls_received' => 1, 'calls_excluded' => 1, 'other' => ['PRIVATE']],
    'tickets' => [['id' => 8, 'solved_at' => '2026-06-01 12:00:00', 'evidence' => ['áé€😀', '"\\/\b\f\n\r\t', [true, false, null, -0.025e3]]]],
    'calls' => [['id' => 'abc', 'queue' => 'fila-ç', 'call_key' => 'abc|fila-ç', 'entered_at' => '2026-06-02 12:00:00', 'caller' => 'PRIVATE']],
    'excluded_calls' => [['id' => 'test', 'entered_at' => null, 'extra' => ['PRIVATE']]],
    'unknown' => [['tickets' => [['id' => 'not-a-top-level-ticket']]]]];
$json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$expectedRows = ['tickets' => [['id' => 8, 'solved_at' => '2026-06-01 12:00:00']],
    'calls' => [['id' => 'abc', 'queue' => 'fila-ç', 'call_key' => 'abc|fila-ç', 'entered_at' => '2026-06-02 12:00:00']],
    'excluded_calls' => [['id' => 'test', 'entered_at' => null]]];
foreach ([1, 2, 3, 4, 5, 7, 16, 31, 64, 256, 65536] as $chunk) {
    [$metadata, $rows] = readerParse($json, ['chunk_bytes' => $chunk]);
    readerCheck($rows === $expectedRows, 'Selected rows and Unicode across chunk ' . $chunk);
    readerCheck($metadata['sha256'] === hash('sha256', $json) && $metadata['bytes'] === strlen($json), 'Exact full-stream hash and byte count across chunk ' . $chunk);
    readerCheck($metadata['collections'] === ['tickets' => true, 'calls' => true, 'excluded_calls' => true]
        && $metadata['row_counts'] === ['tickets' => 1, 'calls' => 1, 'excluded_calls' => 1], 'Collection availability/counts across chunk ' . $chunk);
    readerCheck(!str_contains(json_encode([$metadata, $rows]), 'PRIVATE')
        && !isset($metadata['period']['private'], $metadata['unknown']), 'Private evidence is not captured');
}
[$metadata] = readerParse(" { \"tickets\":[], \"unknown\":null } \r\n");
readerCheck($metadata['collections'] === ['tickets' => true, 'calls' => false, 'excluded_calls' => false]
    && $metadata['row_counts'] === ['tickets' => 0, 'calls' => 0, 'excluded_calls' => 0], 'Empty and absent collections remain distinct');
[$metadata, $rows] = readerParse('{"tickets":[null,9,"text",[],true,{"solved_at":["wrong type"]}]}');
readerCheck($metadata['row_counts']['tickets'] === 6 && count($rows['tickets']) === 6
    && $rows['tickets'][5] === ['solved_at' => null], 'Malformed row values remain counted as unassigned observations');
[$metadata, $rows] = readerParse('{"tickets":[{"id":"\\uD83D\\uDE00","solved_at":null}],"input":{"timezone":"A\\/B"}}', ['chunk_bytes' => 1]);
readerCheck($rows['tickets'][0]['id'] === '😀' && $metadata['input']['timezone'] === 'A/B', 'Escaped surrogate pair and slash decode across single-byte chunks');
[$metadata] = readerParse('{"schema_version":2,"period":null,"input":[],"totals":5}');
readerCheck($metadata['period'] === [] && $metadata['input'] === [] && $metadata['totals'] === [], 'Malformed metadata containers cannot masquerade as recorded fields');

foreach (['', '[]', '{', '{}x', '{}{}', '{"tickets":[]', '{"tickets":[],}',
    '{"tickets":{}}', '{"calls":null}', '{"excluded_calls":"x"}',
    '{"tickets":[,]}', '{"tickets":[{},]}', '{"tickets":[{id:1}]}', '{"tickets":[{"id":1,}]}',
    '{"tickets":[],"tickets":[]}', '{"tickets":[],"\\u0074ickets":[]}',
    '{"input":{"timezone":"A","timezone":"B"}}', '{"tickets":[{"id":1,"id":2}]}',
    '{"totals":{"tickets":1,"tickets":2}}', '{"period":{"month":"2026-01","month":"2026-02"}}',
    '{"x":truefalse}', '{"x":NULL}', '{"x":NaN}', '{"x":+1}', '{"x":01}', '{"x":1.}', '{"x":.1}', '{"x":1e}', '{"x":--1}',
    '{"x":"\\q"}', '{"x":"\\uXXXX"}', '{"x":"\\uD800"}', '{"x":"\\uDC00"}', '{"x":"\\uD800\\u0041"}',
    "{\"x\":\"line\nfeed\"}", "{\"x\":\"\xC0\xAF\"}", "{\"x\":\"\xE2\x82\"}", "{\"x\":\"\xED\xA0\x80\"}",
    "{\"x\":\"\xF4\x90\x80\x80\"}", "{\"x\":\"\x80\"}", "{\"x\":\"\xF0\x9F\\n\"}", '{"totals":{"tickets":1e9999}}'] as $bad) {
    readerReject($bad, 'Reject malformed JSON or ambiguous critical fields: ' . bin2hex(substr($bad, 0, 30)), ['chunk_bytes' => 3]);
}
foreach (['0', '-0', '1', '-1', '1.25', '-0.25', '1e9', '1E-9', '1e+9', '1e9999'] as $number) {
    readerCheck(readerParse('{"unused":' . $number . '}')[0]['bytes'] > 0, 'Accept syntactically valid unused number ' . $number);
}
$exact = '{"tickets":[]}';
readerCheck(readerParse($exact, ['max_bytes' => strlen($exact)])[0]['bytes'] === strlen($exact), 'Exact byte budget is accepted');
readerReject($exact, 'Byte budget rejects before reading beyond its sentinel', ['max_bytes' => strlen($exact) - 1]);
readerReject('{"tickets":[{},{},{}]}', 'Aggregate row budget', ['max_rows' => 2]);
readerReject('{"tickets":[{},{},{}]}', 'Per-collection row budget', ['max_collection_rows' => 2]);
readerReject('{"calls":[{},{}],"excluded_calls":[{}]}', 'Included and excluded call row budget is shared', ['max_call_rows' => 2]);
readerReject('{"x":[[[[]]]]}', 'Depth budget also covers skipped evidence', ['max_depth' => 4]);
readerReject('{"tickets":[]}', 'Depth budget covers an empty collection', ['max_depth' => 1]);
readerReject('{}', 'Zero time budget fails immediately', ['max_seconds' => 0]);
readerReject('{"tickets":[{"id":"' . str_repeat('x', 4097) . '"}]}', 'Captured scalar length budget');
readerReject('{"x":' . str_repeat('1', 4097) . '}', 'Numeric token length budget');
readerCheck(readerParse('{"x":"' . str_repeat('á😀', 10000) . '","calls":[]}')[0]['collections']['calls'], 'Large unused string is streamed and validated');
readerReject('{"x":"' . str_repeat('a', 100000) . "\xFF\"}", 'Malformed UTF-8 in a large unused string is checked');

// Deterministic generated nested evidence checks that delimiters inside strings cannot change the projection.
for ($n = 0; $n < 40; $n++) {
    $snapshot['tickets'][0]['evidence'] = ['text' => str_repeat('á"\\}{][😀', $n), 'nested' => [[['n' => $n, 'v' => $n % 2 === 0]]]];
    $raw = json_encode($snapshot, ($n % 2 ? JSON_UNESCAPED_UNICODE : 0) | JSON_THROW_ON_ERROR);
    readerCheck(readerParse($raw, ['chunk_bytes' => 1 + $n])[1] === $expectedRows, 'Nested evidence differential case ' . $n);
}

$path = tempnam(sys_get_temp_dir(), 'g4fr-stream-');
try {
    $handle = fopen($path, 'wb'); fwrite($handle, '{"unknown":"');
    $block = str_repeat('a', 65536);
    for ($n = 0; $n < 768; $n++) fwrite($handle, $block);
    fwrite($handle, '","tickets":[{"id":1,"solved_at":"2026-06-01 00:00:00"}]}'); fclose($handle);
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-n', '-d', 'memory_limit=24M', __FILE__, '--memory', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    readerCheck(is_resource($process), 'Memory-limited reader subprocess starts');
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    readerCheck($exit === 0, '48 MiB unused evidence is read under a 24 MiB PHP heap: ' . $error . $output);
    $proof = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    readerCheck($proof['bytes'] === filesize($path) && $proof['sha256'] === hash_file('sha256', $path)
        && $proof['count'] === 1 && $proof['peak'] < 24 * 1024 * 1024, 'Large stream hash, callback and bounded peak memory');
} finally { if (is_file($path)) unlink($path); }

echo 'Monthly activity reader: ' . $checks . " assertions passed; bounded selective parsing, complete syntax/UTF-8 validation and 48 MiB streaming proof.\n";
