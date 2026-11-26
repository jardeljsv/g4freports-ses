<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\WordChart;
use GlpiPlugin\G4freports\Ses\XlsxChart;
use GlpiPlugin\G4freports\Ses\OfficePackage;
$checks = 0;
function chartOptionAssert(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function chartOptionParts(array $definition): array {
    $writer = new WordChart(); $paragraph = $writer->paragraph($definition);
    $parts = ['word/_rels/document.xml.rels'=>'</Relationships>', '[Content_Types].xml'=>'</Types>'];
    $writer->attach($parts); $parts['paragraph'] = $paragraph; return $parts;
}
function chartOptionXml(string $xml): DOMXPath {
    $dom = new DOMDocument(); chartOptionAssert($dom->loadXML($xml), 'Well-formed native chart XML');
    $xp = new DOMXPath($dom);
    foreach (['c'=>'http://schemas.openxmlformats.org/drawingml/2006/chart','a'=>'http://schemas.openxmlformats.org/drawingml/2006/main','s'=>'http://schemas.openxmlformats.org/spreadsheetml/2006/main'] as $prefix=>$uri) $xp->registerNamespace($prefix,$uri);
    return $xp;
}
$definition = ['available'=>true,'title'=>'Volume & meta','kind'=>'line','unit'=>'percent','categories'=>['Jan','Fev','Mar'],'category_ref'=>'Dados!$A$2:$A$4','series'=>[
 ['name'=>'Resultado','color'=>'007FA3','values'=>[0,1.25,null],'value_ref'=>'Dados!$B$2:$B$4'],
 ['name'=>'Meta','color'=>'999999','values'=>[.8,.9,.9],'value_ref'=>'Dados!$C$2:$C$4']]];
// SHA-256 fixtures were captured from delivered v1.8.22 before modifying either helper.
$baseline = json_decode(<<<'JSON'
{
  "bar": {
    "word": "c9f87e0a92cae802214d0ddd977b57fe7e28b4c40c4e33fcf1176bd970d2e495",
    "workbook": "b3e47cb1047ba1e8d77aa10d88cfcd91beb25fd775d199e175307de01ac29eba",
    "paragraph": "865fd41126850b471c7e8c2115394a6d1044415e3597009b02f82e927eb0020b",
    "xlsx": "402b4bdefffcb94917744b98701e33276958149deb4823b018375ad5e9481d46"
  },
  "line": {
    "word": "8a12885217a8cfe1df224ee9ce3b8b420ce7645b6e8a33f6079d79d6fae6ae8e",
    "workbook": "b3e47cb1047ba1e8d77aa10d88cfcd91beb25fd775d199e175307de01ac29eba",
    "paragraph": "865fd41126850b471c7e8c2115394a6d1044415e3597009b02f82e927eb0020b",
    "xlsx": "1cb9631ad20c9e39610a77da7876e000b10e1fa38efbd8e130532a3a9bd2162b"
  },
  "column": {
    "word": "f1266b08b1058323f639ce6d39fe8c291d3f5445bf6b4dcb10c3443410f24aaa",
    "workbook": "b3e47cb1047ba1e8d77aa10d88cfcd91beb25fd775d199e175307de01ac29eba",
    "paragraph": "865fd41126850b471c7e8c2115394a6d1044415e3597009b02f82e927eb0020b",
    "xlsx": "7649b10f53a3da3a4b47e778d500442b9c13a05a8b62cd0d02c9dcd147b4a583"
  },
  "pie": {
    "word": "acb0ac256db69e907f0474719a1c859679ae063022b6131a3c578fc9aa04d368",
    "workbook": "b3e47cb1047ba1e8d77aa10d88cfcd91beb25fd775d199e175307de01ac29eba",
    "paragraph": "865fd41126850b471c7e8c2115394a6d1044415e3597009b02f82e927eb0020b",
    "xlsx": "2a50b2c4db540fb140914eaa3aa10e08820793db7d91647432cba5e9328c9a89"
  },
  "doughnut": {
    "word": "e1ed4c590adc7e5e6eadc5f052782d429167d777d5db2ed4d4855d41c33fbfe4",
    "workbook": "b3e47cb1047ba1e8d77aa10d88cfcd91beb25fd775d199e175307de01ac29eba",
    "paragraph": "865fd41126850b471c7e8c2115394a6d1044415e3597009b02f82e927eb0020b",
    "xlsx": "27a938840f1b23f881b7e14bd3aff99052bd1a1232f5d0ad75185de3b894b97f"
  }
}
JSON, true);
foreach ($baseline as $kind=>$hashes) {
    $legacy = $definition; $legacy['kind'] = $kind; $parts = chartOptionParts($legacy);
    $actual = ['word'=>$parts['word/charts/ses_chart_1.xml'], 'workbook'=>$parts['word/embeddings/ses_chart_1.xlsx'], 'paragraph'=>$parts['paragraph'], 'xlsx'=>XlsxChart::xml($legacy)];
    foreach ($actual as $part=>$bytes) chartOptionAssert(hash('sha256',$bytes)===$hashes[$part], 'Legacy '.$kind.' '.$part.' remains byte-identical');
}
$annual = $definition;
$annual['date_axis'] = true; $annual['categories'] = ['2026-01-01','2026-02-01','2026-04-01'];
$annual['show_data_labels'] = false;
$annual['series'][0]['line_visible'] = false; $annual['series'][0]['marker'] = 'diamond';
$annual['series'][0]['values'] = [0,null,1.25];
$annual['series'][1]['line_dash'] = 'dash'; $annual['series'][1]['line_width'] = 12700; $annual['series'][1]['marker'] = 'none';
$annual['series'][] = ['name'=>'Resultado (segmento)','color'=>'007FA3','values'=>[0,.5,null],'value_ref'=>'Dados!$D$2:$D$4','show_legend'=>false];
$parts = chartOptionParts($annual);
foreach (['Word'=>$parts['word/charts/ses_chart_1.xml'],'Excel'=>XlsxChart::xml($annual)] as $kind=>$xml) {
    $xp=chartOptionXml($xml);
    chartOptionAssert($xp->query('//c:dateAx')->length===1 && $xp->query('//c:catAx')->length===0, $kind.' uses an actual date axis');
    chartOptionAssert($xp->evaluate('string(//c:ser[1]/c:cat/c:numRef/c:numCache/c:pt[1]/c:v)')==='46023', $kind.' category cache uses Excel date serial');
    chartOptionAssert($xp->query('//c:ser[1]/c:cat/c:numRef/c:numCache/c:pt')->length===3, $kind.' keeps only selected dates');
    chartOptionAssert($xp->query('//c:dLbls')->length===0, $kind.' suppresses dense numeric data labels');
    chartOptionAssert($xp->query('//c:ser[1]/c:spPr/a:ln/a:noFill')->length===1, $kind.' isolated values have no connecting line');
    chartOptionAssert($xp->evaluate('string(//c:ser[1]/c:marker/c:symbol/@val)')==='diamond', $kind.' provisional markers are distinct');
    chartOptionAssert($xp->evaluate('string(//c:ser[2]/c:spPr/a:ln/a:prstDash/@val)')==='dash', $kind.' targets are dashed');
    chartOptionAssert($xp->evaluate('string(//c:ser[2]/c:spPr/a:ln/@w)')==='12700', $kind.' targets use a thinner stroke');
    chartOptionAssert($xp->evaluate('string(//c:legendEntry/c:idx/@val)')==='2', $kind.' duplicate segment legend is suppressed');
    chartOptionAssert($xp->query('//c:ser[1]/c:val/c:numRef/c:numCache/c:pt[@idx="1"]')->length===0, $kind.' absent points remain gaps');
    chartOptionAssert($xp->evaluate('string(//c:dispBlanksAs/@val)')==='gap' && $xp->query('//c:smooth[@val="1"]')->length===0, $kind.' does not interpolate gaps or smooth values');
}
$book=OfficePackage::read($parts['word/embeddings/ses_chart_1.xlsx']); $xp=chartOptionXml($book['xl/worksheets/sheet1.xml']);
chartOptionAssert($xp->evaluate('string(//s:c[@r="A2"]/s:v)')==='46023' && $xp->query('//s:c[@r="A2"]/@t')->length===0, 'Word embedded source uses numeric dates');
$hiddenSources=$annual; $hiddenSources['plot_visible_only']=false;
$hiddenXp=chartOptionXml(XlsxChart::xml($hiddenSources));
chartOptionAssert($hiddenXp->evaluate('string(//c:plotVisOnly/@val)')==='0','Annual native charts can use hidden helper cells');
$visibleXp=chartOptionXml(XlsxChart::xml($annual));
chartOptionAssert($visibleXp->evaluate('string(//c:plotVisOnly/@val)')==='1','Default charts retain visible-only plotting');
$duration=$annual; $duration['unit']='duration'; $duration['value_format']='[h]:mm:ss';
foreach ($duration['series'] as &$series) foreach ($series['values'] as &$value) if ($value!==null) $value/=86400; unset($series,$value);
$durationParts=chartOptionParts($duration);
foreach ([$durationParts['word/charts/ses_chart_1.xml'],XlsxChart::xml($duration)] as $xml) {
 $xp=chartOptionXml($xml); chartOptionAssert($xp->evaluate('string(//c:valAx/c:numFmt/@formatCode)')==='[h]:mm:ss','Duration axis keeps elapsed-time format');
 chartOptionAssert($xp->query('//c:valAx/c:majorUnit')->length===0,'Duration axis is not forced to whole Excel days');
}
$money=$annual; $money['unit']='currency'; $money['value_format']='"R$" #,##0.00'; $money['signed']=true;
$money['series'][0]['values']=[-120.5,0,50]; $money['axis_max']=100;
foreach ([chartOptionParts($money)['word/charts/ses_chart_1.xml'],XlsxChart::xml($money)] as $xml) {
 $xp=chartOptionXml($xml); chartOptionAssert((float)$xp->evaluate('string(//c:valAx/c:scaling/c:min/@val)') < -120.5,'Signed values are not clipped at zero');
 chartOptionAssert($xp->evaluate('string(//c:valAx/c:scaling/c:max/@val)')==='100','Explicit upper bound retained');
 chartOptionAssert($xp->evaluate('string(//c:valAx/c:numFmt/@formatCode)')==='"R$" #,##0.00','Custom formats are XML escaped');
}
$wide=$annual; $wide['categories']=['2000-01-01','2050-01-01','2100-12-01']; $wideXml=XlsxChart::xml($wide);$xp=chartOptionXml($wideXml);
chartOptionAssert($xp->query('//c:ser[1]/c:cat/c:numRef/c:numCache/c:pt')->length===3,'Century-spanning sparse selection stays bounded');
chartOptionAssert((int)$xp->evaluate('string(//c:dateAx/c:majorUnit/@val)')>100,'Sparse axis has a bounded number of tick labels');
$leap=$annual; $leap['categories']=['2028-01-01','2028-06-01','2028-12-01'];$xp=chartOptionXml(XlsxChart::xml($leap));
chartOptionAssert($xp->evaluate('string(//c:dateAx/c:majorUnit/@val)')==='1','Leap year retains each monthly axis label');
$timezone=date_default_timezone_get(); date_default_timezone_set('America/Sao_Paulo');
chartOptionAssert(XlsxChart::dateSerial('2026-01-01')===46023,'Date serial is independent of server timezone');date_default_timezone_set($timezone);
foreach(['2026-02-30','2026-1-01','1900-02-28','not-a-date'] as $invalid) {
 try { XlsxChart::dateSerial($invalid);chartOptionAssert(false,'Invalid date rejected'); } catch (InvalidArgumentException $e) { chartOptionAssert(true,'Invalid date rejected'); }
}
try { XlsxChart::valueBounds(['series'=>[],'axis_min'=>5,'axis_max'=>4]); chartOptionAssert(false,'Invalid bounds rejected'); } catch (InvalidArgumentException $e) { chartOptionAssert(true,'Invalid bounds rejected'); }
// Annual label policy is opt-in and must bypass both writers' legacy density heuristics.
$readable=$annual;$readable['categories']=[];for($i=0;$i<24;$i++)$readable['categories'][]=(new DateTimeImmutable('2024-01-01'))->modify('+'.$i.' months')->format('Y-m-d');
$readable['show_data_labels']=true;$readable['data_label_policy']='all';$readable['value_format']='0%';$readable['label_format']='0.00%';$readable['force_legend']=true;
$readable['series']=[['name'=>'Resultado','color'=>'376BFF','values'=>array_merge([0,.995,null],array_fill(0,21,.9)),'value_ref'=>'Dados!$B$2:$B$25'],['name'=>'Resultado posterior','color'=>'376BFF','values'=>array_merge(array_fill(0,13,null),[1.1234],array_fill(0,10,null)),'value_ref'=>'Dados!$C$2:$C$25'],['name'=>'Meta','color'=>'997700','values'=>array_fill(0,24,.9),'value_ref'=>'Dados!$D$2:$D$25','show_data_labels'=>false]];
$readableParts=chartOptionParts($readable);
foreach(['Word'=>$readableParts['word/charts/ses_chart_1.xml'],'Excel'=>XlsxChart::xml($readable)] as $kind=>$xml){$xp=chartOptionXml($xml);chartOptionAssert($xp->query('//c:ser[position()<=2]/c:dLbls/c:showVal[@val="1"]')->length===2,$kind.' labels every result segment for24months');chartOptionAssert($xp->evaluate('string(//c:ser[2]/c:dLbls/c:numFmt/@formatCode)')==='0.00%',$kind.' labels retain two decimal places independently of axis');chartOptionAssert($xp->evaluate('string(//c:valAx/c:numFmt/@formatCode)')==='0%',$kind.' compact axis remains independently formatted');chartOptionAssert($xp->query('//c:ser[3]/c:dLbls')->length===0,$kind.' target labels can be controlled separately');chartOptionAssert($xp->query('//c:ser[1]/c:val/c:numRef/c:numCache/c:pt[@idx="0"]/c:v[text()="0"]')->length===1,$kind.' genuine zero remains a labeled observation');chartOptionAssert($xp->query('//c:ser[1]/c:val/c:numRef/c:numCache/c:pt[@idx="2"]')->length===0,$kind.' missing point remains absent');}
$single=$readable;$single['series']=[$single['series'][0]];$singleParts=chartOptionParts($single);foreach([$singleParts['word/charts/ses_chart_1.xml'],XlsxChart::xml($single)] as $xml)chartOptionAssert(chartOptionXml($xml)->query('//c:legend')->length===1,'Annual one-series charts can explicitly retain their legend');
$padded=$annual;$padded['category_padding']=true;
foreach([chartOptionParts($padded)['word/charts/ses_chart_1.xml'],XlsxChart::xml($padded)] as $xml){$xp=chartOptionXml($xml);chartOptionAssert((int)$xp->evaluate('string(//c:dateAx/c:scaling/c:min/@val)')===XlsxChart::dateSerial('2025-12-01'),'Opt-in date bounds preserve one calendar month of label clearance');chartOptionAssert((int)$xp->evaluate('string(//c:dateAx/c:scaling/c:max/@val)')===XlsxChart::dateSerial('2026-05-01'),'Opt-in upper date bound remains calendar aligned');}
chartOptionAssert(chartOptionXml(XlsxChart::xml($annual))->query('//c:dateAx/c:scaling/c:min | //c:dateAx/c:scaling/c:max')->length===0,'Legacy temporal bounds remain automatic');
$lowDates=$annual;$lowDates['category_label_position']='low';
foreach([chartOptionParts($lowDates)['word/charts/ses_chart_1.xml'],XlsxChart::xml($lowDates)] as $xml)chartOptionAssert(chartOptionXml($xml)->evaluate('string(//c:dateAx/c:tickLblPos/@val)')==='low','Opt-in temporal labels can stay below signed data');
$invalidPosition=$annual;$invalidPosition['category_label_position']='invalid';chartOptionAssert(XlsxChart::xml($invalidPosition)===XlsxChart::xml($annual),'Invalid temporal label position preserves legacy default');
echo "Chart option checks: $checks passed\n";
