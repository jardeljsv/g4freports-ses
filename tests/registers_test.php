<?php
namespace GlpiPlugin\G4freports {
    class Config { public static array $data = []; public static function save(array $values): void { self::$data = array_merge(self::$data, $values); } }
}
namespace {
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
class Config {
    public static function getConfigurationValues($context, array $names = []): array {
        $data = \GlpiPlugin\G4freports\Config::$data;
        return $names ? array_intersect_key($data, array_fill_keys($names, true)) : $data;
    }
}
$DB = new class {
    private array $before = [];
    public function beginTransaction(): void { $this->before = \GlpiPlugin\G4freports\Config::$data; }
    public function commit(): void { $this->before = []; }
    public function rollBack(): void { \GlpiPlugin\G4freports\Config::$data = $this->before; }
};
require dirname(__DIR__) . '/src/Ses/StaffRegister.php';
require dirname(__DIR__) . '/src/Ses/Settings.php';
require dirname(__DIR__) . '/src/Ses/RegisterHistory.php';
use GlpiPlugin\G4freports\Ses\StaffRegister as Staff;
use GlpiPlugin\G4freports\Ses\RegisterHistory as History;
use GlpiPlugin\G4freports\Config as PluginConfig;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function base($id, $name, $admission = '2026-01-01') { return ['id'=>$id,'name'=>$name,'profile'=>'Analista','not_billed'=>false,'admission_date'=>$admission,'departure_date'=>null,'monthly_value'=>'1000.00']; }
$initial = Staff::rows([base('b','Zélia'),base('a','Álvaro'),base('c','Bruno')]);
check(array_column($initial,'name') === ['Álvaro','Bruno','Zélia'], 'Portuguese name order');
$duplicate = base('d','  ÁLVARO ');
try { Staff::validateDuplicates(Staff::rows(array_merge($initial,[$duplicate])), $initial); throw new RuntimeException('Duplicate was accepted'); } catch (InvalidArgumentException $expected) {}
$rehire = base('d','Álvaro','2026-08-01'); Staff::validateDuplicates(Staff::rows(array_merge($initial,[$rehire])), $initial);
$homonym = $duplicate; $homonym['record_reference'] = 'Vínculo B'; Staff::validateDuplicates(Staff::rows(array_merge($initial,[$homonym])), $initial);
$legacy = Staff::rows(array_merge($initial,[$duplicate])); Staff::validateDuplicates($legacy,$legacy);
check(count(Staff::duplicateWarnings($legacy)) === 1, 'Legacy duplicates remain visible');
$before = [base('a','Álvaro'), array_merge(base('b','Bruno'),['departure_date'=>'2026-05-02','departure_reason'=>'pending']),array_merge(base('u','Não faturado'),['not_billed'=>true,'departure_date'=>'2026-05-04','departure_reason'=>'replacement'])];
$summary = Staff::summarize($before,'2026-06'); check($summary['active_total'] === 1 && $summary['replacements'] === 1 && $summary['unbilled_total'] === 1, 'INS10 counts historical departure dates without requiring a reason');
$legacyMarks = [['id'=>'legacy_excluded','name'=>'Excluído original','profile'=>'Analista','billed'=>true,'admission_date'=>'2025-01-01','departure_date'=>null,'monthly_value'=>'1000.00'],['id'=>'legacy_included','name'=>'Incluído original','profile'=>'Analista','billed'=>false,'admission_date'=>'2025-01-01','departure_date'=>null,'monthly_value'=>'1000.00']];
PluginConfig::$data = [Staff::CONFIG_KEY => json_encode($legacyMarks)];
$canonical = Staff::fromSettings(PluginConfig::$data);
History::save(PluginConfig::$data,'staff',$canonical,$canonical,42,'Administrador');
$persisted = json_decode(PluginConfig::$data[Staff::CONFIG_KEY],true);
check(!array_key_exists('billed',$persisted[0]), 'Unchanged save persists explicit exclusion field');
check(Staff::fromSettings(PluginConfig::$data)===$canonical, 'Persisted marks do not flip');
$historyLegacy = ['ses_register_staff_history_index'=>json_encode([['id'=>'legacy','slot'=>0,'parts'=>1]]),'ses_register_staff_history_0_0'=>json_encode($legacyMarks)];
check(History::revisionRows($historyLegacy,'staff','legacy')===$canonical, 'Old history restores original marks without inversion');
PluginConfig::$data = [];
$current = Staff::rows($before); $next = array_values(array_filter($current,fn($row)=>$row['id']!=='b'));
$entries = History::save(PluginConfig::$data,'staff',$current,$next,42,'Administrador');
check($entries[0]['removed'] === 1 && $entries[0]['user_id'] === 42, 'Deletion history audit');
$restorable = History::revisionRows(PluginConfig::$data,'staff',$entries[0]['id']);
check(count($restorable)===3 && in_array('b',array_column($restorable,'id')), 'Removed professional recoverable');
$current = $next;
for($i=0;$i<12;$i++) { $next=$current; $next[0]['monthly_value']=(string)(1001+$i).'.00'; History::save(PluginConfig::$data,'staff',$current,$next,42,'Administrador'); $current=$next; }
check(count(History::entries(PluginConfig::$data,'staff'))===10, 'Bounded revision retention');
$entries=History::entries(PluginConfig::$data,'staff'); foreach($entries as $entry) check(count(History::revisionRows(PluginConfig::$data,'staff',$entry['id']))===2,'Slots restore valid versions');
$phones=[['number'=>'00123','label'=>'Teste','active'=>true],['number'=>'456','label'=>'Outro','active'=>false]];
$entries=History::save(PluginConfig::$data,'tests',$phones,[$phones[1]],42,'Administrador');
check(History::revisionRows(PluginConfig::$data,'tests',$entries[0]['id'])===$phones,'Phone deletion recoverable with leading zeros and active state');
$large = [];
for ($i=0;$i<450;$i++) $large[]=['number'=>(string)(10000+$i),'label'=>str_repeat('á',100),'active'=>true];
$entries=History::save(PluginConfig::$data,'tests',$large,[],42,'Administrador');
check(History::revisionRows(PluginConfig::$data,'tests',$entries[0]['id'])===$large,'Large UTF-8 phone register restores all chunks');
foreach(PluginConfig::$data as $key=>$value) if(str_starts_with($key,'ses_register_') && !str_ends_with($key,'index')) check(strlen($value)<=50000,'History config chunk size');
echo "Register checks passed: ordering, duplicates, homonyms, readmission, retained legacy data, date-based INS10, restoration and bounded history.\n";
}
