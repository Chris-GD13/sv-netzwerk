<?php
declare(strict_types=1);

// Deliberately separate from payload_json: never passed to PDF, contract or mail generation.
function pbFactoringSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS private_business_factoring (record_id VARCHAR(36) PRIMARY KEY, customer_key VARCHAR(64) NOT NULL, status VARCHAR(30) NOT NULL, limit_cents BIGINT NULL, available_cents BIGINT NULL, source_url VARCHAR(1000) NOT NULL, checked_at VARCHAR(30) NOT NULL)');
}
function pbFactoringCustomerKey(array $r): string {
    $d=$r['data'];$v=[];
    foreach(['customer_type','company','first_name','last_name','billing_street','billing_postcode','billing_city'] as $k)$v[$k]=(string)($d[$k]??'');
    return hash('sha256',json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function pbFactoringRead(PDO $db,array $r): ?array {
    pbFactoringSchema($db);$q=$db->prepare('SELECT * FROM private_business_factoring WHERE record_id=?');$q->execute([$r['id']]);$v=$q->fetch();
    if(!$v)return null;
    $v['stale']=!hash_equals($v['customer_key'],pbFactoringCustomerKey($r));
    foreach(['limit_cents','available_cents'] as $k)$v[$k]=$v[$k]===null?null:(int)$v[$k];
    return $v;
}
function pbFactoringSave(PDO $db,array $r,array $in): array {
    if(!hash_equals(pbFactoringCustomerKey($r),(string)($in['customer_key']??'')))throw new RuntimeException('Kundendaten geändert. Finnya bitte neu prüfen.',409);
    $status=(string)($in['status']??'');
    if(!in_array($status,['confirmed','pending','declined'],true))throw new RuntimeException('Ungültiger Finnya-Status.',400);
    $url=(string)($in['source_url']??'');$parsed=parse_url($url);
    if(strlen($url)>1000||($parsed['scheme']??'')!=='https'||($parsed['host']??'')!=='factoringplus.dv-portal.de'||isset($parsed['user'])||isset($parsed['pass'])||isset($parsed['port'])||!str_starts_with($parsed['path']??'','/debtors'))throw new RuntimeException('Finnya-Quelladresse fehlt.',400);
    $amounts=[];foreach(['limit_cents','available_cents'] as $k){$v=$in[$k]??null;if($v!==null&&(!is_int($v)||$v<0||$v>100000000000))throw new RuntimeException('Ungültiger Limitbetrag.',400);$amounts[]=$v;}
    if($status==='confirmed'&&($amounts[0]===null||$amounts[1]===null))throw new RuntimeException('Bestätigtes Limit muss vollständig vorliegen.',400);
    if($status!=='confirmed'&&($amounts[0]!==null||$amounts[1]!==null))throw new RuntimeException('Unbestätigte Beträge dürfen nicht als Limit gespeichert werden.',400);
    pbFactoringSchema($db);$db->beginTransaction();try{
        // Lock the source record as well: concurrent customer edits must fail rather than attach an unrelated limit.
        $current=pbRecord($db,$r['id'],$r['profile'],true);
        if(!hash_equals(pbFactoringCustomerKey($current),$in['customer_key']))throw new RuntimeException('Kundendaten geändert.',409);
        $q=$db->prepare('DELETE FROM private_business_factoring WHERE record_id=?');$q->execute([$r['id']]);
        $q=$db->prepare('INSERT INTO private_business_factoring(record_id,customer_key,status,limit_cents,available_cents,source_url,checked_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$r['id'],$in['customer_key'],$status,...$amounts,$url,pbNow()]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return pbFactoringRead($db,$r);
}
