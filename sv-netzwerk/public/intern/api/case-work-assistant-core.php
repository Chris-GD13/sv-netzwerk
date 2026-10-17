<?php
declare(strict_types=1);

/** Existing case_work_turns / case_analysis_jobs, same DB and case source.
 * Provider injection separates deterministic simulation from reserved paid calls.
 */
require_once __DIR__.'/ai-budget-gateway.php';
require_once __DIR__.'/assistant-provider-diagnostic.php';
final class CaseWorkAssistant {
    private SvAiBudgetLedger $ledger;
    private bool $sqlite;
    private Closure $source;
    private Closure $clock;
    public function __construct(private PDO $db, callable $source, private array $release, private string $session, ?callable $clock=null,private ?object $provider=null) {
        $this->ledger=new SvAiBudgetLedger($db,$provider?'assistant':'simulation');
        $this->sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        $this->source=Closure::fromCallable($source);
        $this->clock=$clock ? Closure::fromCallable($clock) : fn()=>time();
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    private function now(): int { return ($this->clock)(); }
    private function month(): string { return (new DateTimeImmutable('@'.$this->now()))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m'); }
    private function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql);$s->execute($args);return $s; }
    private static function json(mixed $v): string { return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    private static function hash(mixed $v): string { return hash('sha256',self::json($v)); }
    private function fail(string $why): never { throw new RuntimeException($why); }
    public function schema(): void {
        $this->ledger->schema();$this->ledger->initializeMutex();
        $id=$this->sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $suffix=$this->sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        foreach([
            "CREATE TABLE IF NOT EXISTS case_analysis_jobs(id $id,folder_id VARCHAR(190) NOT NULL,task_key CHAR(64) NOT NULL,user_id BIGINT NOT NULL,status VARCHAR(20) NOT NULL,message TEXT NOT NULL,result_json MEDIUMTEXT NULL,error_text TEXT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL)",
            "CREATE TABLE IF NOT EXISTS case_work_turns(id $id,folder_id VARCHAR(190) NOT NULL,user_id BIGINT NOT NULL,request_key VARCHAR(64) NOT NULL,instructions TEXT NOT NULL,task_id TEXT NOT NULL,analysis_id BIGINT NULL,import_id BIGINT NULL,document_id BIGINT NULL,expert_profile VARCHAR(30) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,UNIQUE(user_id,request_key))",
            'CREATE TABLE IF NOT EXISTS case_work_assistant_contexts(token CHAR(64) PRIMARY KEY,session_hash CHAR(64) NOT NULL,tab_id VARCHAR(64) NOT NULL,user_id BIGINT NOT NULL,folder_id VARCHAR(190) NOT NULL,snapshot_json TEXT NOT NULL,expires_at BIGINT NOT NULL,active INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS case_work_budget(month_key VARCHAR(7) PRIMARY KEY,limit_micro BIGINT NOT NULL,spent_micro BIGINT NOT NULL,reserved_micro BIGINT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS case_work_usage(operation_id VARCHAR(80) PRIMARY KEY,month_key VARCHAR(7) NOT NULL,reserved_micro BIGINT NOT NULL,actual_micro BIGINT NULL,status VARCHAR(20) NOT NULL,usage_json TEXT NULL)',
            'CREATE TABLE IF NOT EXISTS case_work_assistant_events(id '.$id.',job_id BIGINT NULL,event_type VARCHAR(40) NOT NULL,created_at BIGINT NOT NULL,detail_hash CHAR(64) NOT NULL)'
        ] as $sql)$this->db->exec($sql.$suffix);
        $insert=$this->sqlite?'INSERT OR IGNORE':'INSERT IGNORE';
        // Global ledger mutex; one DB transaction serializes all context/job/budget mutations.
        $this->db->exec("$insert INTO case_work_budget VALUES('mutex',0,0,0)");
    }
    private function tx(callable $f): mixed {return $this->ledger->transaction($f);}
    private function event(?int $job,string $kind,mixed $details): void {$this->query('INSERT INTO case_work_assistant_events(job_id,event_type,created_at,detail_hash) VALUES(?,?,?,?)',[$job,$kind,$this->now(),self::hash($details)]);}
    private function case(array $user,string $folder): array {
        if(empty($user['active'])||!in_array($user['role']??'', ['administrator','projektleiter','pruefer','sachverstaendiger'],true))$this->fail('USER_FORBIDDEN');
        $case=($this->source)($user,$folder); // Must perform existing folder/profile ACL on every call.
        foreach(['folder_id','claim_number','profile','insurer','line','revision','documents'] as $key)if(!isset($case[$key])||$case[$key]==='')$this->fail('CASE_IDENTITY_INCOMPLETE');
        if($case['folder_id']!==$folder)$this->fail('CASE_IDENTITY_MISMATCH');
        return $case;
    }
    private function rules(array $case): array {
        if(($this->release['status']??'')!=='approved'||empty($this->release['id'])||empty($this->release['source_hash']))$this->fail('RULE_RELEASE_UNAPPROVED');
        $rules=[];
        foreach($this->release['rules']??[] as $rule){
            if(($rule['status']??'')!=='approved')continue;
            if(empty($rule['sources'])||empty($rule['review_basis']))$this->fail('RULE_PROVENANCE_MISSING');
            if(!in_array($rule['check']??'', ['threshold_report','payment_evidence','energy','restoration'],true))$this->fail('RULE_CHECK_UNSUPPORTED');
            if(!in_array('*',$rule['insurers'],true)&&!in_array($case['insurer'],$rule['insurers'],true))continue;
            if(!in_array('*',$rule['lines'],true)&&!in_array($case['line'],$rule['lines'],true))continue;
            $rules[]=$rule;
        }
        if(!$rules)$this->fail('NO_APPLICABLE_APPROVED_RULES');
        return $rules;
    }
    public function open(array $user,string $tab,string $folder,string $expected): array {
        if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$tab))$this->fail('TAB_INVALID');
        return $this->tx(function()use($user,$tab,$folder,$expected){
            $case=$this->case($user,$folder);if($case['claim_number']!==$expected)$this->fail('CLAIM_NUMBER_MISMATCH');
            $rules=$this->rules($case);
            $this->query('UPDATE case_work_assistant_contexts SET active=0 WHERE session_hash=? AND tab_id=?',[hash('sha256',$this->session),$tab]);
            $token=bin2hex(random_bytes(32));$snapshot=['case'=>$case,'release_hash'=>self::hash($this->release),'release_id'=>$this->release['id'],'billing_lane'=>$this->provider?'assistant':'simulation'];
            $this->query('INSERT INTO case_work_assistant_contexts VALUES(?,?,?,?,?,?,?,1)',[$token,hash('sha256',$this->session),$tab,$user['id'],$folder,self::json($snapshot),$this->now()+1800]);
            $this->event(null,'context_open',[$user['id'],$folder,$token]);
            return ['token'=>$token,'folder_id'=>$folder,'claim_number'=>$case['claim_number'],'profile'=>$case['profile'],'revision'=>$case['revision'],'release'=>$this->release['id'],'rules'=>array_map(fn($r)=>['id'=>$r['id'],'sources'=>$r['sources']],$rules),'mode'=>$this->provider?'live':'mock'];
        });
    }
    private function context(array $user,string $token,string $tab): array {
        $s=$this->query('SELECT * FROM case_work_assistant_contexts WHERE token=?',[$token])->fetch(PDO::FETCH_ASSOC);
        if(!$s||!(int)$s['active']||(int)$s['expires_at']<$this->now()||(int)$s['user_id']!==(int)$user['id']||!hash_equals($s['session_hash'],hash('sha256',$this->session))||$s['tab_id']!==$tab)$this->fail('CONTEXT_INVALID');
        $snapshot=json_decode($s['snapshot_json'],true,512,JSON_THROW_ON_ERROR);if(($snapshot['billing_lane']??'simulation')!==($this->provider?'assistant':'simulation'))$this->fail('CONTEXT_MODE_CHANGED');$case=$this->case($user,$s['folder_id']);
        if(!hash_equals(self::hash($snapshot['case']),self::hash($case)))$this->fail('CASE_CHANGED');
        if(!hash_equals($snapshot['release_hash'],self::hash($this->release)))$this->fail('RULE_RELEASE_CHANGED');
        $this->rules($case);return $s+['snapshot'=>$snapshot];
    }
    public function close(array $user,string $token,string $tab): array {
        return $this->tx(function()use($user,$token,$tab){
            $this->query('UPDATE case_work_assistant_contexts SET active=0 WHERE token=? AND user_id=? AND session_hash=? AND tab_id=?',[$token,$user['id'],hash('sha256',$this->session),$tab]);
            $this->event(null,'context_closed',[$user['id'],$token]);return ['closed'=>true];
        });
    }
    private function reserve(string $operation,int $micro): void {$this->ledger->reserve($operation,$this->month(),$micro);}
    public function start(array $user,string $token,string $tab,string $key,string $action,string $instruction,string $route='assistant',int $maximum=250000): array {
        if($this->provider&&$route==='assistant')$maximum=$this->provider->maximum();
        if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$key)||strlen($instruction)>24000)$this->fail('REQUEST_INVALID');
        if(!in_array($action,['check','invoices','report','tasks','custom'],true)||!in_array($route,['assistant','work'],true))$this->fail('ACTION_INVALID');
        if($route==='assistant'&&$maximum<100000)$this->fail('COST_BOUND_INVALID');
        return $this->tx(function()use($user,$token,$tab,$key,$action,$instruction,$route,$maximum){
            $ctx=$this->context($user,$token,$tab);$case=$ctx['snapshot']['case'];
            $payload=['context'=>$token,'action'=>$action,'instruction'=>$instruction,'route'=>$route,'maximum'=>$maximum,'release'=>$ctx['snapshot']['release_hash']];
            $old=$this->query('SELECT * FROM case_work_turns WHERE user_id=? AND request_key=?',[$user['id'],$key])->fetch(PDO::FETCH_ASSOC);
            if($old){if($old['instructions']!==self::json($payload))$this->fail('IDEMPOTENCY_CONFLICT');return ['job_id'=>(int)$old['analysis_id'],'replayed'=>true];}
            $running=$this->query("SELECT id FROM case_analysis_jobs WHERE folder_id=? AND status IN ('queued','running','paused','unknown','dispatching','handoff')",[$case['folder_id']])->fetchColumn();
            if($running)$this->fail('CASE_BUSY');
            $date=gmdate('Y-m-d H:i:s',$this->now());
            $this->query('INSERT INTO case_analysis_jobs(folder_id,task_key,user_id,status,message,result_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',[$case['folder_id'],self::hash($key),$user['id'],'queued','Auftrag angenommen.',self::json(['checkpoint'=>'reserved','context'=>$token]),$date,$date]);$id=(int)$this->db->lastInsertId();
            $this->reserve('assistant:'.$id,$route==='work'?0:$maximum);
            $this->query('INSERT INTO case_work_turns(folder_id,user_id,request_key,instructions,task_id,analysis_id,expert_profile,created_at) VALUES(?,?,?,?,?,?,?,?)',[$case['folder_id'],$user['id'],$key,self::json($payload),'assistant:v1',$id,$case['profile'],$date]);
            $this->event($id,'reserved',$payload);return ['job_id'=>$id,'replayed'=>false];
        });
    }
    private function job(array $user,string $token,string $tab,int $id): array {
        $ctx=$this->context($user,$token,$tab);
        $job=$this->query('SELECT a.*,w.instructions FROM case_analysis_jobs a JOIN case_work_turns w ON w.analysis_id=a.id WHERE a.id=? AND w.task_id=?',[$id,'assistant:v1'])->fetch(PDO::FETCH_ASSOC);
        if(!$job||(int)$job['user_id']!==(int)$user['id']||$job['folder_id']!==$ctx['folder_id'])$this->fail('JOB_FORBIDDEN');
        $payload=json_decode($job['instructions'],true,512,JSON_THROW_ON_ERROR);
        if($payload['context']!==$token)$this->fail('JOB_CONTEXT_MISMATCH');
        return [$job,$payload,$ctx['snapshot']['case']];
    }
    private function settle(int $id,int $actual,array $usage): void {$this->ledger->settle('assistant:'.$id,$actual,$usage);}
    private function update(int $id,string $status,array $result): void {$this->query('UPDATE case_analysis_jobs SET status=?,message=?,result_json=?,updated_at=? WHERE id=?',[$status,$status,self::json($result),gmdate('Y-m-d H:i:s',$this->now()),$id]);$this->event($id,$status,$result);}
    public function step(array $user,string $token,string $tab,int $id): array {
        if($this->provider){[, $p]=$this->job($user,$token,$tab,$id);if($p['route']==='assistant')return $this->paidStep($user,$token,$tab,$id);}
        return $this->tx(function()use($user,$token,$tab,$id){
            [$job,$p,$case]=$this->job($user,$token,$tab,$id);$state=$job['status'];
            if(in_array($state,['done','cancelled','failed','handoff','unknown'],true))return $this->statusUnlocked($job);
            if($state==='paused')$this->fail('RESUME_REQUIRED');
            if($state==='queued'){$this->update($id,'running',['checkpoint'=>'validated','context'=>$token]);return ['id'=>$id,'status'=>'running'];}
            $result=self::analyse($case,$this->rules($case),$p['action'],$p['instruction']);
            $result+=['context'=>$token,'release'=>$this->release['id'],'source_hash'=>$this->release['source_hash'],'route'=>$p['route'],'simulation'=>true,'checkpoint'=>'complete'];
            $actual=$p['route']==='work'?0:500*100+100*500;
            $this->settle($id,$actual,['provider'=>'simulation','tariff_version'=>'synthetic-v1','input_tokens'=>500,'output_tokens'=>100,'input_micro_per_token'=>100,'output_micro_per_token'=>500,'actual_micro'=>$actual,'real_charge_micro'=>0]);
            if($p['route']==='work'){$result['handoff']=['case'=>$case['claim_number'],'folder'=>$case['folder_id'],'profile'=>$case['profile'],'revision'=>$case['revision'],'release'=>$this->release['id'],'rules_source_hash'=>$this->release['source_hash'],'approved_rules'=>$this->rules($case),'instruction'=>$p['instruction'],'return_guard'=>['revision'=>$case['revision'],'release_hash'=>self::hash($this->release)],'notice'=>'Manuelle Übergabe. Quellen in Work erneut lesen und Hash/Regelstand abgleichen. Vor Rückübernahme Fallstand erneut prüfen. Kein Import/Versand ausgelöst.'];}
            $this->update($id,$p['route']==='work'?'handoff':'done',$result);
            return ['id'=>$id,'status'=>$p['route']==='work'?'handoff':'done','result'=>$result];
        });
    }
    private function paidStep(array $user,string $token,string $tab,int $id): array {
        $dispatch=$this->tx(function()use($user,$token,$tab,$id){
            [$job,$p,$case]=$this->job($user,$token,$tab,$id);
            if($job['status']==='queued'){$this->update($id,'running',['checkpoint'=>'validated','context'=>$token]);return ['status'=>['id'=>$id,'status'=>'running']];}
            if($job['status']!=='running')return ['status'=>$this->statusUnlocked($job)];
            // File/encoding/size failures occur BEFORE dispatch; reserved money can be safely released.
            try{$payload=$this->provider->prepare($case,$this->rules($case),$p['action'],$p['instruction'],$this->release['master_text']??'');}
            catch(Throwable $e){$this->settle($id,0,['not_dispatched'=>true,'error'=>'PREPARATION_FAILED']);$this->update($id,'failed',['checkpoint'=>'not_dispatched','error'=>preg_match('/^[A-Z_]+$/',$e->getMessage())?$e->getMessage():'PREPARATION_FAILED']);return ['status'=>['id'=>$id,'status'=>'failed','result'=>['notice'=>'Originalunterlagen konnten nicht vollständig vorbereitet werden. Keine KI-Kosten.','open_points'=>[$e->getMessage()]]]];}
            $gaps=$payload['_svnet_gaps']??[];unset($payload['_svnet_gaps']);
            $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $this->ledger->dispatch('assistant:'.$id,$hash);$this->update($id,'dispatching',['checkpoint'=>'provider_dispatch','context'=>$token]);
            return ['gaps'=>$gaps,'payload'=>$payload,'hash'=>$hash,'case'=>$case,'request'=>$p];
        });
        if(isset($dispatch['status']))return $dispatch['status'];
        // Persistent dispatch checkpoint committed. Network never holds the global DB mutex.
        try{$permit=SvAiPaidPermit::issue($this->db,'assistant:'.$id,$dispatch['hash']);$response=$this->provider->invoke($dispatch['payload'],$permit);}
        catch(Throwable){$response=['uncertain'=>true];}
        return $this->tx(function()use($user,$token,$tab,$id,$dispatch,$response){
            $s=$this->query('SELECT status FROM case_analysis_jobs WHERE id=?',[$id])->fetchColumn();
            if($s!=='dispatching')$this->fail('USAGE_STATE_INVALID');
            if(!empty($response['uncertain'])){$this->ledger->unknown('assistant:'.$id);$this->update($id,'unknown',['checkpoint'=>'provider_uncertain','response_id'=>$response['response_id']??null]);return ['id'=>$id,'status'=>'unknown','result'=>['notice'=>'Verbrauchsstatus unklar. Reservierung bleibt gesperrt; kein automatischer neuer KI-Aufruf.']];}
            if(!empty($response['rejected'])){
                $detail=$response['provider_error']??[];$diagnostic=svAssistantDiagnostic($detail);$notice='KI-Anfrage abgewiesen: HTTP '.(int)$response['http_status'].($diagnostic!==''?' · '.$diagnostic:'').'. Keine erfolgreiche Bearbeitung; kein Verbrauch gebucht.';
                $this->settle($id,0,['provider_rejected'=>true,'http_status'=>$response['http_status'],'provider_error'=>$detail]);
                $result=['checkpoint'=>'provider_rejected','http_status'=>$response['http_status'],'provider_error'=>$detail,'notice'=>$notice];$this->update($id,'failed',$result);
                return ['id'=>$id,'status'=>'failed','result'=>$result];
            }
            $actual=(int)$response['actual_micro'];$this->settle($id,$actual,$response['usage']);
            // Always account usage, even if user switched case or source/rights changed in flight.
            try{$this->context($user,$token,$tab);}catch(Throwable){$this->update($id,'failed',['checkpoint'=>'source_changed_after_dispatch','actual_micro'=>$actual]);return ['id'=>$id,'status'=>'failed','result'=>['notice'=>'Fallstand oder Berechtigung wurde geändert. Ergebnis verworfen; Verbrauch erfasst.']];}
            $r=$response['result']??null;$valid=is_array($r)&&is_string($r['answer']??null)&&is_array($r['open_points']??null)&&is_array($r['rule_checks']??null)&&is_array($r['source_checks']??null);
            if(!$valid||empty($response['complete'])||$actual>$dispatch['request']['maximum']){$this->update($id,'failed',['checkpoint'=>'response_or_cost_invalid','actual_micro'=>$actual]);return ['id'=>$id,'status'=>'failed','result'=>['notice'=>'Antwort nicht vollständig prüfbar. Verbrauch erfasst; keine fachliche Übernahme.']];}
            $result=['action'=>$dispatch['request']['action'],'simulation'=>false,'answer'=>$r['answer'],'notice'=>'KI-Arbeitsentwurf – fachlich prüfen. Keine Freigabe, kein Versand, keine native Formularspeicherung.',
                'findings'=>$r['rule_checks'],'open_points'=>array_merge($dispatch['gaps'],$r['open_points']),'source_checks'=>$r['source_checks'],'tasks'=>array_map(fn($t)=>['task'=>$t,'status'=>'open'],array_merge($dispatch['gaps'],$r['open_points'])),
                'release'=>$this->release['id'],'source_hash'=>$this->release['source_hash'],'context'=>$token,'actual_micro'=>$actual,'usage'=>$response['usage']];
            $this->update($id,'done',$result);return ['id'=>$id,'status'=>'done','result'=>$result];
        });
    }
    private function statusUnlocked(array $job): array {return ['id'=>(int)$job['id'],'status'=>$job['status'],'result'=>json_decode($job['result_json']??'null',true)];}
    public function recover(array $user,string $token,string $tab,int $id): array {
        return $this->tx(function()use($user,$token,$tab,$id){
            $ctx=$this->context($user,$token,$tab);
            $j=$this->query('SELECT a.*,w.id AS turn_id,w.instructions FROM case_analysis_jobs a JOIN case_work_turns w ON a.id=w.analysis_id WHERE a.id=? AND w.task_id=?',[$id,'assistant:v1'])->fetch(PDO::FETCH_ASSOC);
            if(!$j||(int)$j['user_id']!==(int)$user['id']||$j['folder_id']!==$ctx['folder_id'])$this->fail('JOB_FORBIDDEN');
            $p=json_decode($j['instructions'],true,512,JSON_THROW_ON_ERROR);
            $old=$this->query('SELECT * FROM case_work_assistant_contexts WHERE token=?',[$p['context']])->fetch(PDO::FETCH_ASSOC);
            if(!$old||!hash_equals($old['session_hash'],hash('sha256',$this->session))||$old['snapshot_json']!==$ctx['snapshot_json'])$this->fail('RECOVERY_SNAPSHOT_CHANGED');
            $p['context']=$token;$this->query('UPDATE case_work_turns SET instructions=? WHERE id=?',[self::json($p),$j['turn_id']]);
            $this->event($id,'recovered',$token);return ['id'=>$id,'status'=>$j['status']];
        });
    }
    public function history(array $user,string $token,string $tab): array {
        $ctx=$this->context($user,$token,$tab);
        return $this->query('SELECT a.id,a.status FROM case_analysis_jobs a JOIN case_work_turns w ON a.id=w.analysis_id WHERE a.folder_id=? AND a.user_id=? AND w.task_id=? ORDER BY a.id DESC LIMIT 10',[$ctx['folder_id'],$user['id'],'assistant:v1'])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function discard(array $user,string $token,string $tab,int $id): array {
        return $this->tx(function()use($user,$token,$tab,$id){
            $ctx=$this->context($user,$token,$tab);
            $j=$this->query('SELECT a.* FROM case_analysis_jobs a JOIN case_work_turns w ON a.id=w.analysis_id WHERE a.id=? AND w.task_id=?',[$id,'assistant:v1'])->fetch(PDO::FETCH_ASSOC);
            if(!$j||(int)$j['user_id']!==(int)$user['id']||$j['folder_id']!==$ctx['folder_id'])$this->fail('JOB_FORBIDDEN');
            if(!in_array($j['status'],['queued','running','paused','handoff'],true))$this->fail('TRANSITION_FORBIDDEN');
            if($j['status']!=='handoff')$this->settle($id,0,['cancelled_without_dispatch'=>true,'real_charge_micro'=>0]);
            $this->update($id,'cancelled',['checkpoint'=>'discarded']);return ['id'=>$id,'status'=>'cancelled'];
        });
    }
    /** Operator reconciliation of a simulated uncertain dispatch. Not exposed as an HTTP action. */
    public function uncertain(array $user,string $token,string $tab,int $id): void {
        $this->tx(function()use($user,$token,$tab,$id){[$j]=$this->job($user,$token,$tab,$id);if($j['status']!=='running')$this->fail('TRANSITION_FORBIDDEN');$this->ledger->unknown('assistant:'.$id);$this->update($id,'unknown',['checkpoint'=>'dispatch_uncertain']);});
    }
    public function reconcile(array $user,string $token,string $tab,int $id,int $actual,array $usage): void {
        $this->tx(function()use($user,$token,$tab,$id,$actual,$usage){[$j,$p]=$this->job($user,$token,$tab,$id);if($j['status']!=='unknown')$this->fail('TRANSITION_FORBIDDEN');$this->settle($id,$actual,$usage);$this->update($id,'failed',['checkpoint'=>'reconciled','budget_overrun'=>$actual>$p['maximum'],'actual_micro'=>$actual]);});
    }
    public function status(array $user,string $token,string $tab,int $id): array {[$j]=$this->job($user,$token,$tab,$id);return $this->statusUnlocked($j);}
    public function control(array $user,string $token,string $tab,int $id,string $action): array {
        return $this->tx(function()use($user,$token,$tab,$id,$action){
            [$j]=$this->job($user,$token,$tab,$id);$s=$j['status'];
            if($action==='resume'&&$s==='paused')$next='running';
            elseif($action==='pause'&&in_array($s,['queued','running'],true))$next='paused';
            elseif($action==='cancel'&&in_array($s,['queued','running','paused','handoff'],true))$next='cancelled';
            else $this->fail('TRANSITION_FORBIDDEN');
            if($next==='cancelled'&&$s!=='handoff')$this->settle($id,0,['real_charge_micro'=>0,'cancelled_before_simulation'=>true]);
            $r=json_decode($j['result_json'],true);$this->update($id,$next,$r);return ['id'=>$id,'status'=>$next];
        });
    }
    public function budget(): array {return $this->ledger->budget($this->month());}
    public static function analyse(array $case,array $rules,string $action,string $instruction): array {
        $open=[];$findings=[];$total=0;$seen=[];$replaced=[];$ids=[];
        foreach($case['documents'] as $d){if(isset($ids[$d['id']]))throw new RuntimeException('DUPLICATE_DOCUMENT_ID');$ids[$d['id']]=true;}
        foreach($case['documents'] as $d)if(!empty($d['supersedes_id'])){
            if(empty($d['evidence'])||!isset($ids[$d['supersedes_id']]))$open[]='Unbelegte Belegersetzung: '.$d['id'];
            else {if(isset($replaced[$d['supersedes_id']])||$d['supersedes_id']===$d['id'])throw new RuntimeException('OVERLAPPING_DOCUMENT_REPLACEMENT');$replaced[$d['supersedes_id']]=$d['id'];}
        }
        foreach(array_keys($replaced) as $node){$chain=[];while(isset($replaced[$node])){if(isset($chain[$node]))throw new RuntimeException('CYCLIC_DOCUMENT_REPLACEMENT');$chain[$node]=true;$node=$replaced[$node];}}
        foreach($case['documents'] as $doc){
            if(isset($replaced[$doc['id']])){$findings[]='Ersetzten Beleg nicht zusätzlich angesetzt: '.$doc['id'];continue;}
            if(empty($doc['sha256'])||empty($doc['evidence'])){$open[]='Quellenprüfung fehlt: '.$doc['id'];continue;}
            if(isset($seen[$doc['sha256']])){$findings[]='Doppelte Quelle nicht nochmals angesetzt: '.$doc['id'];continue;}$seen[$doc['sha256']]=$doc['id'];
            if(!isset($doc['amount_cents'])||!is_int($doc['amount_cents'])||$doc['amount_cents']<0){$open[]='Geprüfter Betrag fehlt: '.$doc['id'];continue;}
            if($doc['amount_cents']>1000000000||$total>1000000000-$doc['amount_cents'])throw new RuntimeException('AMOUNT_OUT_OF_RANGE');
            $total+=$doc['amount_cents'];
        }
        foreach($rules as $r){
            $findings[]='Angewendete Regel: '.$r['id'];
            if($r['check']==='threshold_report'&&$total>=($r['threshold_cents']??PHP_INT_MAX))$findings[]='Zwischenbericht erforderlich gemäß '.$r['id'];
            if($r['check']==='payment_evidence'&&empty($case['payments_verified']))$open[]='Zahlungsstand nicht nachgewiesen; keine Zahlung als erfolgt angesetzt.';
            if($r['check']==='energy'){
                $kwh=$case['energy_kwh_milli']??null;$tariff=$case['energy_tariff_micro']??($r['tariff_micro']??null);
                if(!is_int($kwh)||$kwh<0||$kwh>1000000000||!is_int($tariff)||$tariff<0||$tariff>10000000)$open[]='Stromverbrauch oder Tarif nicht belastbar.';
                else $findings[]='Trocknungsstrom separat: '.intdiv($kwh*$tariff+5000000,10000000).' Cent; bisherigen Ansatz ersetzen, nicht addieren.';
            }
            if($r['check']==='restoration'&&empty($case['has_quote'])){
                $items=$case['restoration_items']??[];$estimate=0;
                foreach($items as $item){$q=$item['quantity_milli']??null;$price=$item['unit_price_cents']??null;if(empty($item['source'])||!is_int($q)||!is_int($price)||$q<0||$price<0||$q>1000000000||$price>1000000){$open[]='Kalkulationsgrundlage nicht belastbar.';continue;}$estimate+=intdiv($q*$price+500,1000);}
                if(!$items)$open[]='Kein KVA: eigene SV-Kalkulation aus belegtem Umfang/Mengen/Preisgrundlagen vorbereiten.';
                else $findings[]='Eigene SV-Kalkulation ohne KVA: '.$estimate.' Cent; als Entwurf prüfen, keine Ersatzrechnung.';
            }
        }
        if(!$case['documents'])$open[]='Keine geprüften strukturierten Belege verfügbar.';
        $draft=$action==='report'?'SIMULIERTER BERICHTSENTWURF · '.$case['claim_number']."\nGeprüfte Belegsumme: ".number_format($total/100,2,',','.')." EUR.\n".implode("\n",$open):null;
        return ['action'=>$action,'total_cents'=>$total,'findings'=>$findings,'open_points'=>$open,'tasks'=>array_map(fn($text)=>['task'=>$text,'status'=>'open'],$open),
            'invoice_register'=>array_map(fn($d)=>['id'=>$d['id'],'amount_cents'=>$d['amount_cents']??null,'verified'=>!empty($d['evidence']),'superseded'=>isset($replaced[$d['id']]),'duplicate_of'=>isset($seen[$d['sha256']??''])&&$seen[$d['sha256']]!==$d['id']?$seen[$d['sha256']]:null],$case['documents']),
            'source_count'=>count($case['documents']),'draft'=>$draft,'instruction_recorded'=>$instruction,'notice'=>'Simulation mit strukturierten Belegen; keine freie KI-Auswertung, keine Freigabe oder native Formularspeicherung.'];
    }
}
