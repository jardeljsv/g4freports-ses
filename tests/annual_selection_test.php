<?php
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\AnnualSelection;

$checks = 0;
function selectionCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function selectionReject($value): void {
    try { AnnualSelection::parse($value); } catch (InvalidArgumentException $e) { selectionCheck(true, ''); return; }
    throw new RuntimeException('Invalid selection accepted: ' . json_encode($value));
}
$id = str_repeat('a', 64);
$good = ['months' => ['2026-03', '2026-01'], 'selections' => [['month' => '2026-01', 'id' => $id]]];
$parsed = AnnualSelection::parse(json_encode($good));
selectionCheck($parsed['months'] === ['2026-01', '2026-03'] && $parsed['selections'] === $good['selections'], 'Month ordering and pinned revision');
selectionCheck(AnnualSelection::parse('{"months":["2000-01","2100-12"]}')['selections'] === [], 'Missing selections remain missing, not inferred');
$months = []; for ($n = 0; $n < 24; $n++) $months[] = sprintf('%04d-%02d', 2025 + intdiv($n, 12), $n % 12 + 1);
selectionCheck(count(AnnualSelection::parse(json_encode(['months' => $months]))['months']) === 24, '24 month bound');
foreach ([null, [], '', '[]', '{}', '{broken', str_repeat(' ', 16385) . '{}',
    '{"months":{"one":"2026-01"}}', '{"months":["2026-01"],"selections":null}',
    '{"months":{"0":"2026-01"}}', '{"months":["2026-01"],"selections":{}}',
    json_encode(['months' => array_merge($months, ['2027-01'])]),
    json_encode(['months' => ['2026-01', '2026-01']]),
    json_encode(['months' => ['2026-01'], 'metrics' => ['INS1' => 99]]),
    json_encode(['months' => ['2026-01'], 'selections' => [['month' => '2026-02', 'id' => $id]]]),
    json_encode(['months' => ['2026-01'], 'selections' => [['month' => '2026-01', 'id' => $id], ['month' => '2026-01', 'id' => $id]]]),
    json_encode(['months' => ['2026-01'], 'selections' => [['month' => '2026-01', 'id' => $id, 'owner' => 7]]]),
    json_encode(['months' => ['2026-01'], 'selections' => [['month' => '2026-01', 'id' => [$id]]]]),
    json_encode(['months' => ['2026-01'], 'selections' => [['month' => '2026-01', 'id' => '../archive']]]),
    json_encode(['months' => ['2026-01'], 'selections' => [['month' => '2026-01', 'id' => str_repeat('A', 64)]]]),
] as $bad) selectionReject($bad);
foreach (['1999-12', '2101-01', '2026-00', '2026-13', '2026-1', '2026-01\n', ['2026-01'], 202601] as $bad) selectionReject(json_encode(['months' => [$bad]]));
foreach (['2000', 2100, 2026] as $year) selectionCheck(AnnualSelection::year($year) === (int)$year, 'Valid year');
foreach ([1999, 2101, 2026.0, '2026x', ['2026'], null] as $year) {
    try { AnnualSelection::year($year); } catch (InvalidArgumentException $e) { selectionCheck(true, ''); continue; }
    throw new RuntimeException('Invalid year accepted');
}
echo "Annual selection validation: $checks checks passed.\n";
