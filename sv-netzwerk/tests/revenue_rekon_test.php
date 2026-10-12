<?php
$base=__DIR__.'/../public/intern/api/';
require $base.'profile-routing.php';
$source=file_get_contents($base.'revenue-summary.php');
$source=substr($source,strpos($source,'const RS_SOURCE_URL'));
$source=substr($source,0,strpos($source,"\n\$action="));
$source=str_replace('commonHeaders();','',$source);
eval($source);
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
check(rsCanRefreshProfile(['email'=>'cw@sv-netzwerk.eu'],'rekon_marc'),'Christian REKON access');
check(!rsCanRefreshProfile(['email'=>'cw@sv-netzwerk.eu'],'claims_marc'),'Claims remains restricted');
check(!rsCanRefreshProfile(['email'=>'hr@sv-schuett.eu'],'rekon_marc'),'Holger cannot import Marc');
check(!rsCanRefreshProfile(['email'=>'random@example.org'],'rekon_marc'),'Unknown user denied');
$previous=[['year'=>2025,'id'=>'old'],['year'=>2026,'id'=>'replace']];
$merged=rsRekonMergeYears($previous,[['year'=>2026,'id'=>'new']]);
check(count($merged)===2&&$merged[0]['id']==='old'&&$merged[1]['id']==='new','Year preservation');
if(count($argv)>1){
 $sources=[];foreach(array_slice($argv,1)as$file)$sources[]=[['name'=>basename($file)],file_get_contents($file)];
 $rows=rsRekonEntries($sources);
 foreach(['rekon_marc','rekon_holger']as$profile){$own=array_values(array_filter($rows,fn($r)=>$r['profile']===$profile));$sum=round(array_sum(array_column($own,'payout_net')),2);$expected=$profile==='rekon_marc'?26269.74:1804.00;check(abs($sum-$expected)<0.001,'Complete source total '.$profile.' actual='.$sum.' rows='.count($own));echo $profile.' '.count($own).' entries '.$sum."\n";}
 check(count(array_filter($rows,fn($r)=>($r['is_adjustment']??false)&&$r['actual_order_net']!==null))===0,'No invented shares on adjustments');
}
echo "REKON permission, merge and reconciliation tests passed\n";
