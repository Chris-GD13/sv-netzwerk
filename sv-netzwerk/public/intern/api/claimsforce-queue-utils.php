<?php
declare(strict_types=1);
function cqSingleResultValid(array $job,array $result):bool{
    $normalize=static fn($v)=>strtolower(preg_replace('/[^A-Za-z0-9]/','',(string)$v));
    if(($job['sync_mode']??'')!=='single')return true;
    $inventory=$result['inventories']??[];
    if(!is_array($inventory)||count($inventory)!==1||!is_array($inventory[0]))return false;
    foreach(['files','messages','notes','tasks','attachments']as$field)if(!isset($inventory[0][$field])||!is_numeric($inventory[0][$field])||(int)$inventory[0][$field]<0)return false;
    return (int)($result['claims']??0)===1 && (int)($result['updated']??0)+(int)($result['skipped']??0)===1
        && (int)($result['failed']??0)===0 && $normalize($result['claimNumber']??'')===$normalize($job['claim_number']??'')
        && count($inventory)===1 && $normalize($inventory[0]['claimNumber']??'')===$normalize($job['claim_number']??'');
}
function cqStationKey(array$body):string{
    $id=(string)($body['stationId']??'');
    if(!preg_match('/^[a-f0-9-]{36}$/i',$id)||version_compare((string)($body['bridgeVersion']??'0'),'1.4.59','<')||($body['workerVersion']??'')!=='1.4.59'||(int)($body['protocol']??0)!==2)apiError(409,'Aktuelle Browser-Brücke 1.4.59 erforderlich. Diese alte Importstation darf keinen Auftrag übernehmen.');
    return hash('sha256',$id);
}
function cqRequireOwner(int$id,array$body):void{
    $key=cqStationKey($body);
    $s=db()->prepare("SELECT agent_key FROM claimsforce_import_jobs WHERE id=:id AND status='running'");
    $s->execute([':id'=>$id]);
    $stored=(string)($s->fetchColumn()?:'');
    if($stored===''||!hash_equals($stored,$key))apiError(409,'Der Auftrag gehört einer anderen Importstation oder läuft nicht mehr.');
}
function cqConfirmHeartbeat(int$id,array$body,int$changedRows):void{
    // MySQL meldet bei gleichen Daten und gleichem NOW()-Sekundenwert 0 Änderungen.
    // Das ist kein Abbruch: nur ein tatsächlich beendeter/fremder Auftrag wird abgelehnt.
    if($changedRows===0)cqRequireOwner($id,$body);
    elseif($changedRows!==1)apiError(409,'Importauftrag konnte nicht eindeutig aktualisiert werden.');
}
