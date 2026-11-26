<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\MonthlyExport;
use GlpiPlugin\G4freports\Ses\Monthly\V1\OfficePackage;
use GlpiPlugin\G4freports\Ses\Monthly\V1\Profile;

$checks = 0;
function monthlyProfileCheck(bool $condition, string $message): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function monthlyProfileParts(string $binary, string $prefix = ''): array
{
    $result = [];
    foreach (OfficePackage::read($binary) as $name => $bytes) {
        if (substr($name, -5) === '.xlsx') {
            $result += monthlyProfileParts($bytes, $prefix . $name . '!/');
        } else $result[$prefix . $name] = hash('sha256', $bytes);
    }
    ksort($result);
    return $result;
}
function monthlyProfileReject(array $snapshot, string $format = 'docx'): void
{
    try {
        MonthlyExport::render($snapshot, $format);
        monthlyProfileCheck(false, 'Unsupported metadata must not render');
    } catch (InvalidArgumentException $e) {
        monthlyProfileCheck($format !== 'docx' && $format !== 'xlsx', 'Invalid output format rejected');
    } catch (RuntimeException $e) {
        monthlyProfileCheck($e->getCode() === 409, 'Unknown schema/rules/profile receives explicit compatibility status');
        monthlyProfileCheck(str_contains($e->getMessage(), 'JSON'), 'Compatibility failure preserves JSON access');
    }
}

$snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/monthly_export_v1.json'), true, 512, JSON_THROW_ON_ERROR);
$golden = json_decode(file_get_contents(__DIR__ . '/fixtures/monthly_export_v1_parts.json'), true, 512, JSON_THROW_ON_ERROR);
$original = $snapshot;
$tagged = MonthlyExport::stamp($snapshot);
monthlyProfileCheck($snapshot === $original, 'Stamp never mutates the source argument');
monthlyProfileCheck($tagged['monthly_export'] === ['profile' => Profile::ID], 'New monthly snapshot records its explicit profile');
$restored = $tagged; unset($restored['monthly_export']);
monthlyProfileCheck($restored === $snapshot, 'Stamp leaves every calculated field and source value untouched');
monthlyProfileCheck(MonthlyExport::stamp($tagged) === $tagged, 'Stamp is idempotent for a supported presentation');
monthlyProfileCheck(MonthlyExport::profile($snapshot) === Profile::ID, 'Known untagged v16 snapshot maps to the verified monthly baseline');

foreach (['docx', 'xlsx'] as $format) {
    $legacy = MonthlyExport::render($snapshot, $format);
    $current = MonthlyExport::render($tagged, $format);
    monthlyProfileCheck($legacy['legacy'] && !$current['legacy'], 'Legacy compatibility is explicitly distinguished from recorded provenance');
    monthlyProfileCheck($legacy['binary'] === $current['binary'], 'Profile metadata cannot change normal monthly document contents');
    monthlyProfileCheck($legacy['profile'] === Profile::ID, 'Both routes resolve the same fixed normal monthly profile');
    monthlyProfileCheck(substr($legacy['filename'], -strlen($format) - 1) === '.' . $format, 'Monthly filename matches selected format');
    $parts = monthlyProfileParts($legacy['binary']);
    ksort($golden[$format]);
    monthlyProfileCheck(array_keys($parts) === array_keys($golden[$format]), 'Full monthly document component inventory matches original 1.8.23 output');
    foreach ($golden[$format] as $name => $expected) monthlyProfileCheck($parts[$name] === $expected, 'Original monthly component preserved: ' . $format . '/' . $name);
    $raw = OfficePackage::read($legacy['binary']);
    if ($format === 'docx') {
        monthlyProfileCheck(str_contains($raw['word/document.xml'], 'Relatório Mensal'), 'Historical Word retains the normal monthly report');
        monthlyProfileCheck(isset($raw['word/header1.xml']) && isset($raw['word/footer1.xml']), 'Monthly letterhead resources retained');
    } else {
        foreach (['INS1', 'INS12', 'Base de Dados', 'Base de Dados Ligações', 'Base de Dados Colaboradores', 'Resultado Final Indicadores'] as $name) {
            monthlyProfileCheck(str_contains($raw['xl/workbook.xml'], 'name="' . $name . '"'), 'Normal monthly evidence/calculation sheet retained: ' . $name);
        }
        monthlyProfileCheck(str_contains($raw['xl/workbook.xml'], 'fullCalcOnLoad="1"'), 'Normal monthly workbook remains recalculable');
    }
}
monthlyProfileCheck($snapshot === $original, 'Rendering preserves every archived field');
monthlyProfileCheck(!class_exists('GlpiPlugin\\G4freports\\Ses\\Repository', false)
    && !class_exists('GlpiPlugin\\G4freports\\Ses\\Calculator', false)
    && !class_exists('GlpiPlugin\\G4freports\\Config', false), 'Monthly re-export does not load source extraction, calculation or live settings');
monthlyProfileCheck(!class_exists('GlpiPlugin\\G4freports\\Ses\\AnnualDocxExporter', false)
    && !class_exists('GlpiPlugin\\G4freports\\Ses\\AnnualXlsxExporter', false), 'Historical monthly exports never use annual renderers');

foreach ([null, [], 'ses-monthly-v1', ['profile' => '../../front/ses_api.php'], ['profile' => 'ses-monthly-v2'],
    ['profile' => Profile::ID, 'template' => '/tmp/custom.docx'], ['profile' => Profile::ID, 'class' => 'AnnualDocxExporter']] as $metadata) {
    $invalid = $snapshot; $invalid['monthly_export'] = $metadata; monthlyProfileReject($invalid);
}
foreach ([1, '2', null, 3] as $schema) { $invalid = $tagged; $invalid['schema_version'] = $schema; monthlyProfileReject($invalid); }
foreach (['ses-2026-10-05-v15', 'ses-future-v17', '', null] as $rules) { $invalid = $tagged; $invalid['rules_version'] = $rules; monthlyProfileReject($invalid); }
monthlyProfileReject($tagged, 'json');
monthlyProfileReject($tagged, '../docx');

// Ensure every relative class dependency stays inside the preserved namespace.
$classes = ['DocxExporter','XlsxExporter','ChartData','HeatmapData','MetricEvidence','ReportStyle','WordChart','XlsxChart','OfficePackage','StaffRegister','FinanceSettings','PhoneSurveySummary','PhoneSurveyScore'];
foreach ($classes as $name) {
    $class = 'GlpiPlugin\\G4freports\\Ses\\Monthly\\V1\\' . $name;
    monthlyProfileCheck(class_exists($class), 'Preserved dependency is installed: ' . $name);
    $source = file_get_contents((new ReflectionClass($class))->getFileName());
    preg_match_all('/(?<![A-Za-z\\\\])([A-Z][A-Za-z0-9]+)::/', $source, $matches);
    foreach (array_unique($matches[1]) as $dependency) monthlyProfileCheck(in_array($dependency, $classes, true), 'Rendering dependency stays within the versioned profile: ' . $dependency);
}
Profile::assertInstalled();

// Simulate a future live implementation changing every rendering helper.
// The historical profile must not resolve any of these replacement classes.
final class CurrentMonthlyRendererMustNotBeUsed
{
    public function __construct() { throw new RuntimeException('Live monthly implementation was used'); }
    public static function __callStatic($name, $arguments) { throw new RuntimeException('Live monthly helper was used'); }
}
foreach ($classes as $name) {
    $live = 'GlpiPlugin\\G4freports\\Ses\\' . $name;
    monthlyProfileCheck(!class_exists($live, false), 'Live helper not loaded by historical export: ' . $name);
    class_alias(CurrentMonthlyRendererMustNotBeUsed::class, $live);
}
foreach (['docx', 'xlsx'] as $format) {
    $rendered = MonthlyExport::render($tagged, $format);
    monthlyProfileCheck(monthlyProfileParts($rendered['binary']) === $golden[$format], 'Historical output independent of replacement live helpers: ' . $format);
}

// Check damaged installations in a temporary copy, never modify the working plugin.
$temporary = sys_get_temp_dir() . '/g4fr-monthly-profile-' . bin2hex(random_bytes(8));
mkdir($temporary . '/src/Ses/Monthly/V1', 0700, true);
mkdir($temporary . '/resources/monthly/v1', 0700, true);
$profileDirectory = dirname(__DIR__) . '/src/Ses/Monthly/V1';
$template = dirname(__DIR__) . '/resources/monthly/v1/papel_timbrado_G4F.docx';
foreach (array_merge($classes, ['Profile']) as $name) copy($profileDirectory . '/' . $name . '.php', $temporary . '/src/Ses/Monthly/V1/' . $name . '.php');
copy($template, $temporary . '/resources/monthly/v1/papel_timbrado_G4F.docx');
$worker = <<<'PHP'
<?php
require $argv[1] . '/src/Ses/Monthly/V1/Profile.php';
try {
    GlpiPlugin\G4freports\Ses\Monthly\V1\Profile::assertInstalled();
    echo 'ready';
} catch (RuntimeException $e) {
    echo $e->getCode() . ':' . $e->getMessage();
}
PHP;
file_put_contents($temporary . '/check.php', $worker);
$checkInstallation = static function () use ($temporary): string {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-n', $temporary . '/check.php', $temporary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not launch isolated profile validation');
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    monthlyProfileCheck(proc_close($process) === 0 && $stderr === '', 'Isolated installation validation completed');
    return $stdout;
};
try {
    monthlyProfileCheck($checkInstallation() === 'ready', 'Complete isolated profile is valid');
    file_put_contents($temporary . '/resources/monthly/v1/papel_timbrado_G4F.docx', 'wrong template');
    monthlyProfileCheck(str_starts_with($checkInstallation(), '503:'), 'Altered template fails explicitly instead of using the live letterhead');
    copy($template, $temporary . '/resources/monthly/v1/papel_timbrado_G4F.docx');
    file_put_contents($temporary . '/src/Ses/Monthly/V1/StaffRegister.php', "\n", FILE_APPEND);
    monthlyProfileCheck(str_starts_with($checkInstallation(), '503:'), 'Changed historical formula helper cannot silently alter the preserved profile');
    copy($profileDirectory . '/StaffRegister.php', $temporary . '/src/Ses/Monthly/V1/StaffRegister.php');
    unlink($temporary . '/src/Ses/Monthly/V1/ChartData.php');
    monthlyProfileCheck(str_starts_with($checkInstallation(), '503:'), 'Missing profile dependency fails without live fallback');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($temporary);
}
echo 'Monthly export profile: ' . $checks . " checks passed.\n";
