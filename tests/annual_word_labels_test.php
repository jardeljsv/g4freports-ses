<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\WordChart;
$checks=0;
function wlCheck(bool $ok,string $message):void { global $checks;$checks++;if(!$ok)throw new RuntimeException($message); }
function wlChart(array $d):DOMXPath { $w=new WordChart();$w->paragraph($d);$files=['word/_rels/document.xml.rels'=>'</Relationships>','[Content_Types].xml'=>'</Types>'];$w->attach($files);$dom=new DOMDocument();wlCheck($dom->loadXML($files['word/charts/ses_chart_1.xml']),'Valid chart XML');$x=new DOMXPath($dom);$x->registerNamespace('c','http://schemas.openxmlformats.org/drawingml/2006/chart');return $x; }
$d=['available'=>true,'title'=>'Precisão mensal','kind'=>'line','unit'=>'percent','categories'=>array_map(fn($n)=>'Mês '.$n,range(1,24)),'data_label_policy'=>'all','label_format'=>'0.00%','value_format'=>'0%','show_data_labels'=>true,'force_legend'=>true,'series'=>[
 ['name'=>'Resultado','values'=>array_merge([.995,0,null,1.25],array_fill(0,20,null))],
 ['name'=>'Resultado após lacuna','values'=>array_merge(array_fill(0,12,null),[.996],array_fill(0,11,null))],
 ['name'=>'Meta','values'=>array_fill(0,24,.99),'label_indices'=>[0,12,23],'label_position'=>'b']]];
$x=wlChart($d);
wlCheck($x->query('//c:ser/c:dLbls/c:showVal[@val="1"]')->length===3,'Every result segment retains labels beyond twelve months');
wlCheck($x->query('//c:dLbls/c:numFmt[@formatCode="0.00%"]')->length===3,'Data labels use two decimals independently of axis');
wlCheck($x->evaluate('string(//c:valAx/c:numFmt/@formatCode)')==='0%','Axis remains independently formatted');
wlCheck($x->query('//c:ser[1]/c:dLbls/c:dLbl[c:idx[@val="0" or @val="1" or @val="3"]]')->length===0,'Nonzero, zero and above100 result labels are never deleted');
wlCheck($x->query('//c:ser[1]/c:dLbls/c:dLbl[c:idx[@val="2"]]/c:delete[@val="1"]')->length===1,'Missing result is not mislabeled zero');
wlCheck($x->query('//c:ser[2]/c:dLbls/c:dLbl[c:idx[@val="12"]]')->length===0,'Later result segment remains labeled');
wlCheck($x->query('//c:ser[3]/c:dLbls/c:dLbl')->length===21,'Target label strategy only retains explicit endpoints and change');
wlCheck($x->evaluate('string(//c:ser[3]/c:dLbls/c:dLblPos/@val)')==='b','Targets can sit below result labels');
$d['series']=[$d['series'][0]];$x=wlChart($d);wlCheck($x->query('//c:legend')->length===1,'Explicit annual legend retained for a single result series');
$d['series'][0]['show_data_labels']=false;$x=wlChart($d);wlCheck($x->query('//c:dLbls')->length===0,'Per-series explicit suppression respected');
echo "Annual Word labels: $checks checks passed\n";
