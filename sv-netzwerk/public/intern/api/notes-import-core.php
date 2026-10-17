<?php
declare(strict_types=1);
function cfnNumber(string $value): string { return strtoupper(preg_replace('/[\s\x{00ad}\x{2010}-\x{2015}\x{2212}-]+/u','',$value) ?? ''); }
function cfnIdentity(array $identity): array {
    $out=['folderId'=>(string)($identity['folderId']??''),'claimId'=>strtolower((string)($identity['claimId']??'')),'profile'=>(string)($identity['profile']??''),'claimNumber'=>cfnNumber((string)($identity['claimNumber']??''))];
    if($out['folderId']==='' || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$out['claimId']) || !in_array($out['profile'],['christian','marc','holger','jens'],true) || $out['claimNumber']==='')throw new InvalidArgumentException('NOTES_CONTEXT_REQUIRED');
    return $out;
}
function cfnAssertIdentity(array $meta,array $identity,string $folderId,string $profile): array {
    $expected=cfnIdentity(['folderId'=>$folderId,'claimId'=>$meta['claimsforce_claim_id']??'','profile'=>$meta['claimsforce_profile']??'','claimNumber'=>$meta['schaden_nr']??'']);
    if($expected!==cfnIdentity($identity)||$profile!==$expected['profile'])throw new RuntimeException('NOTES_IDENTITY_MISMATCH');
    return $expected;
}
function cfnMergeNotes(array $existing,array $incoming): array {
    if(count($incoming)>10000)throw new InvalidArgumentException('NOTES_LIMIT');
    $merged=[];
    foreach(array_merge($existing,$incoming) as $note){
        if(!is_array($note)||!is_string($note['text']??null)||trim($note['text'])==='')continue;
        $key=json_encode(!empty($note['id'])?['id',(string)$note['id']]:['text',$note['createdAt']??'',$note['author']??'',$note['text']],JSON_THROW_ON_ERROR);
        if(isset($merged[$key])){
            $old=strtotime((string)(($merged[$key]['updatedAt']??'')?:($merged[$key]['createdAt']??'')));$new=strtotime((string)(($note['updatedAt']??'')?:($note['createdAt']??'')));
            if($merged[$key]['text']===$note['text']){if($new===false||($old!==false&&$new<=$old))continue;}
            elseif($old===false||$new===false||$old===$new)throw new RuntimeException('NOTES_VERSION_CONFLICT');
            if($old!==false&&$new<$old)continue;
        }
        $merged[$key]=$note;
    }
    return array_values($merged);
}
// Caller must hold the case metadata lock for the entire read/merge/write/readback sequence.
function cfnPersist(callable $read,callable $write,array $identity,string $folderId,string $profile,array $incoming): array {
    $meta=$read();$expected=cfnAssertIdentity($meta,$identity,$folderId,$profile);
    $notes=cfnMergeNotes(is_array($meta['claimsforce_notizen']??null)?$meta['claimsforce_notizen']:[],$incoming);
    $meta['claimsforce_notizen']=$notes;$write($meta);$stored=$read();
    cfnAssertIdentity($stored,$expected,$folderId,$profile);
    if(($stored['claimsforce_notizen']??null)!==$notes)throw new RuntimeException('NOTES_READBACK_MISMATCH');
    return ['identity'=>$expected,'notes'=>$notes];
}
