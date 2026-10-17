<?php
declare(strict_types=1);
/** Legacy remains on its existing budget; no request may select the legacy lane. */
function svAiBudgetPolicy(string $lane): array {
    return match($lane) {
        'legacy'=>['lane'=>'legacy','limit_micro'=>null,'billing'=>'existing-unallocated'],
        'assistant'=>['lane'=>'assistant','limit_micro'=>30000000,'billing'=>'additional-real'],
        'simulation'=>['lane'=>'simulation','limit_micro'=>30000000,'billing'=>'synthetic-only'],
        default=>throw new RuntimeException('AI_LANE_INVALID')
    };
}
function svAiLegacyTransports(): array {
    return ['bki-http','bki-upload','extract-cli','gf-http','gf-smart-http','gf-upload',
        'gf-evidence-upload','case-extract','kva-http','polycam-http','ai-service'];
}
function svAiTransportDecision(string $url,string $transport,string $lane): array {
    $policy=svAiBudgetPolicy($lane);
    // Even a legacy ID or non-provider URL cannot provide assistant egress.
    if($lane!=='legacy')throw new RuntimeException('PAID_AI_DISABLED');
    if(!in_array($transport,svAiLegacyTransports(),true))throw new RuntimeException('AI_TRANSPORT_UNREGISTERED');
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    $provider=in_array($host,['api.openai.com','api.anthropic.com','generativelanguage.googleapis.com'],true);
    // Verification uses deny; this cannot enable a paid assistant adapter.
    if($provider&&getenv('SVNET_AI_TRANSPORT_MODE')==='deny')throw new RuntimeException('PAID_AI_DISABLED');
    return $policy+['transport'=>$transport,'provider'=>$provider,'allowed'=>true];
}
function svAiGuardHandle($handle,string $transport=''): mixed {
    $lane=defined('SVNET_ASSISTANT_REQUEST')&&SVNET_ASSISTANT_REQUEST?'assistant':'legacy';
    svAiTransportDecision((string)curl_getinfo($handle,CURLINFO_EFFECTIVE_URL),$transport,$lane);
    return $handle;
}
/** Same DB and transaction as case queue. Caller holds its global mutex.
 * Simulation retains Phase-2 data. Real assistant accounts are separate and
 * unused until an approved provider adapter exists. Legacy never uses either.
 */
final class SvAiBudgetLedger {
    private string $accounts;
    private string $operations;
    private int $limit;
    private bool $locked=false;
    public function __construct(private PDO $db,private string $lane) {
        $policy=svAiBudgetPolicy($lane);
        if($lane==='legacy')throw new RuntimeException('LEGACY_BUDGET_NOT_ASSIGNED');
        $this->limit=$policy['limit_micro'];
        $this->accounts=$lane==='simulation'?'case_work_budget':'case_work_ai_budget';
        $this->operations=$lane==='simulation'?'case_work_usage':'case_work_ai_usage';
    }
    public function schema(): void {
        $suffix=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $this->db->exec("CREATE TABLE IF NOT EXISTS $this->accounts(month_key VARCHAR(7) PRIMARY KEY,limit_micro BIGINT NOT NULL,spent_micro BIGINT NOT NULL,reserved_micro BIGINT NOT NULL)".$suffix);
        $this->db->exec("CREATE TABLE IF NOT EXISTS $this->operations(operation_id VARCHAR(80) PRIMARY KEY,month_key VARCHAR(7) NOT NULL,reserved_micro BIGINT NOT NULL,actual_micro BIGINT NULL,status VARCHAR(20) NOT NULL,usage_json TEXT NULL)".$suffix);
    }
    public function transaction(callable $fn): mixed {
        if($this->locked)throw new RuntimeException('AI_TRANSACTION_NESTED');
        $sqlite=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if($sqlite)$this->db->exec('BEGIN IMMEDIATE');
        else {$this->db->beginTransaction();$this->db->query("SELECT * FROM case_work_budget WHERE month_key='mutex' FOR UPDATE")->fetch();}
        $this->locked=true;
        try {$value=$fn();if($sqlite)$this->db->exec('COMMIT');else $this->db->commit();return $value;}
        catch(Throwable $e){if($sqlite)$this->db->exec('ROLLBACK');elseif($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally {$this->locked=false;}
    }
    private function requireLock(): void {if(!$this->locked)throw new RuntimeException('AI_TRANSACTION_REQUIRED');}
    public function initializeMutex(): void {
        $mock=new self($this->db,'simulation');$mock->schema();
        $insert=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
        $this->db->exec("$insert INTO case_work_budget VALUES('mutex',0,0,0)");
    }
    private function query(string $sql,array $args=[]): PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function reserve(string $operation,string $month,int $micro): void {
        $this->requireLock();
        if($micro<0||$micro>$this->limit||!preg_match('/^\d{4}-\d{2}$/',$month))throw new RuntimeException('COST_BOUND_INVALID');
        $insert=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
        $this->query("$insert INTO $this->accounts VALUES(?,?,0,0)",[$month,$this->limit]);
        $b=$this->query("SELECT * FROM $this->accounts WHERE month_key=?",[$month])->fetch(PDO::FETCH_ASSOC);
        if((int)$b['spent_micro']+(int)$b['reserved_micro']+$micro>min($this->limit,(int)$b['limit_micro']))throw new RuntimeException('MONTHLY_BUDGET_EXCEEDED');
        $this->query("UPDATE $this->accounts SET reserved_micro=reserved_micro+? WHERE month_key=?",[$micro,$month]);
        $this->query("INSERT INTO $this->operations VALUES(?,?,?,NULL,?,NULL)",[$operation,$month,$micro,'reserved']);
    }
    public function settle(string $operation,int $actual,array $usage): void {
        $this->requireLock();
        $row=$this->query("SELECT * FROM $this->operations WHERE operation_id=?",[$operation])->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']==='settled')throw new RuntimeException('USAGE_STATE_INVALID');
        if($actual<0)throw new RuntimeException('USAGE_INVALID');
        $usage['budget_lane']=$this->lane;
        if($this->lane==='simulation')$usage['real_charge_micro']=0;
        $this->query("UPDATE $this->accounts SET reserved_micro=reserved_micro-?,spent_micro=spent_micro+? WHERE month_key=?",[$row['reserved_micro'],$actual,$row['month_key']]);
        $this->query("UPDATE $this->operations SET actual_micro=?,status=?,usage_json=? WHERE operation_id=?",[$actual,'settled',json_encode($usage,JSON_THROW_ON_ERROR),$operation]);
    }
    public function dispatch(string $operation,string $payloadHash): void {
        $this->requireLock();
        $s=$this->query("SELECT status FROM $this->operations WHERE operation_id=?",[$operation]);
        if($s->fetchColumn()!=='reserved')throw new RuntimeException('USAGE_STATE_INVALID');
        $this->query("UPDATE $this->operations SET status='dispatching',usage_json=? WHERE operation_id=?",[json_encode(['payload_hash'=>$payloadHash],JSON_THROW_ON_ERROR),$operation]);
    }
    public function unknown(string $operation): void {$this->requireLock();$this->query("UPDATE $this->operations SET status='unknown' WHERE operation_id=? AND status<>'settled'",[$operation]);}
    public function budget(string $month): array {
        $b=$this->query("SELECT * FROM $this->accounts WHERE month_key=?",[$month])->fetch(PDO::FETCH_ASSOC);
        $b=$b?:['month_key'=>$month,'limit_micro'=>$this->limit,'spent_micro'=>0,'reserved_micro'=>0];
        $b['limit_micro']=min($this->limit,(int)$b['limit_micro']);
        return $b+['budget_lane'=>$this->lane,'billing'=>svAiBudgetPolicy($this->lane)['billing']];
    }
}

/** A paid transport requires a committed, request-bound reservation. */
final class SvAiPaidPermit {
    private bool $used=false;
    private function __construct(public readonly string $operation,private string $hash){}
    public static function issue(PDO $db,string $operation,string $hash): self {
        $s=$db->prepare('SELECT * FROM case_work_ai_usage WHERE operation_id=?');$s->execute([$operation]);$r=$s->fetch(PDO::FETCH_ASSOC);
        $detail=$r?json_decode($r['usage_json']??'null',true):null;
        if(!$r||$r['status']!=='dispatching'||(int)$r['reserved_micro']<1100000||!hash_equals($detail['payload_hash']??'',$hash))throw new RuntimeException('AI_DISPATCH_NOT_RESERVED');
        return new self($operation,$hash);
    }
    public function consume(array $payload):void {
        if($this->used||!hash_equals($this->hash,hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))))throw new RuntimeException('AI_DISPATCH_INVALID');
        if(($payload['model']??'')!=='gpt-6-luna'||($payload['service_tier']??'')!=='default'||($payload['store']??true)!==false||($payload['max_output_tokens']??0)!==8000||!empty($payload['tools'])||isset($payload['_svnet_gaps']))throw new RuntimeException('AI_TARIFF_MISMATCH');
        $this->used=true;
    }
}
function svAiGuardPaidHandle($handle,SvAiPaidPermit $permit,array $payload): mixed {
    if(getenv('SVNET_AI_TRANSPORT_MODE')==='deny')throw new RuntimeException('PAID_AI_DISABLED');
    if((string)curl_getinfo($handle,CURLINFO_EFFECTIVE_URL)!=='https://api.openai.com/v1/responses')throw new RuntimeException('AI_TRANSPORT_UNREGISTERED');
    $permit->consume($payload);return $handle;
}
