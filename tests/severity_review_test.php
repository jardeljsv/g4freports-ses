<?php
require_once dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\SeverityReview;
use GlpiPlugin\G4freports\Ses\MonthlyExport;
use GlpiPlugin\G4freports\Ses\Monthly\V1\Profile;
use GlpiPlugin\G4freports\Ses\Monthly\V2\DocxExporter;

function reviewCheck($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function event(int $id, int $option, string $old, string $new, string $at): array {
    return ['id'=>$id, 'linked_action'=>0, 'id_search_option'=>$option,
        'old_value'=>$old, 'new_value'=>$new, 'date_mod'=>$at];
}
$s = ['schema_version'=>Profile::SCHEMA, 'rules_version'=>Profile::RULES,
    'input'=>['entity_id'=>1], 'period'=>['month'=>'2026-07'], 'tickets'=>[], 'metrics'=>[],
    'financial'=>['deductions'=>[]]];
$ctx = ['history_available'=>true,'histories'=>[]];
foreach (range(1,4) as $i) {
    $a = 100*$i+1; $b = $a+1; $c = $a+2;
    foreach ([$a,$b,$c] as $ticketId) {
        $s['tickets'][] = ['id'=>$ticketId,'entity_id'=>1,'opened_at'=>'2026-07-01 08:00:00',
            'solved_at'=>'2026-07-03 11:00:00','deadline'=>'2026-07-02 10:00:00'];
        $ctx['histories'][$ticketId] = [
            event(1,80,'SES-DF (0)','Central (1)','2026-07-03 10:00:00'),
            event(2,12,'2','4','2026-07-03 10:30:00')];
    }
    // Within-SLA ticket and a ticket that left CS must never be proposed.
    $ctx['histories'][$c][] = event(3,80,'Central (1)','Other (2)','2026-07-03 10:40:00');
    $s['metrics'][] = ['id'=>'INS'.$i,'denominator'=>3,'numerator'=>1,'value'=>100/3,
        'target'=>90,'status'=>'unmet','denominator_ids'=>[$a,$b,$c],'evidence_ids'=>[$a]];
}
$original = $s['metrics'];
$s['severity_review'] = SeverityReview::build($s,$ctx);
reviewCheck($s['metrics']===$original,'Original metrics unchanged');
$word = new DocxExporter();
$method = new ReflectionMethod(DocxExporter::class,'severityNarrative');
$method->setAccessible(true);
foreach ($s['metrics'] as $i=>$m) {
    $r = $s['severity_review'][$m['id']];
    reviewCheck(count($r['candidates'])===1,'One eligible transfer per indicator');
    reviewCheck($r['candidates'][0]['id']===100*($i+1)+2,'Correct late ticket');
    reviewCheck($r['candidates'][0]['pending_at']===['2026-07-03 10:30:00'],'Pending after transfer');
    $xml=$method->invoke($word,$m,$s);
    reviewCheck(strpos($xml,'50,00%')!==false,'Correct simulation');
    reviewCheck(strpos($xml,'Severidade '.($i+1))!==false,'Correct severity');
}
$empty = $s['metrics'][0]; $empty['denominator']=1; $empty['numerator']=0; $empty['value']=0;
reviewCheck(strpos($method->invoke($word,$empty,$s),'N/A')!==false,'Zero revised denominator');
$without = SeverityReview::build($s,['history_available'=>false]);
reviewCheck(!$without['INS1']['history_available'] && !$without['INS1']['candidates'],'Unavailable history is not inferred');
$legacy = $s; unset($legacy['severity_review']);
reviewCheck(MonthlyExport::profile(MonthlyExport::stamp($legacy))===Profile::ID,'Legacy profile stays V1');
reviewCheck(MonthlyExport::profile(MonthlyExport::stamp($s))==='ses-monthly-v2','New snapshots use V2');
echo "Severity review tests OK\n";
