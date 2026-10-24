<?php
declare(strict_types=1);
if(!defined('SVNET_ASSISTANT_REQUEST'))define('SVNET_ASSISTANT_REQUEST',true);
require_once __DIR__.'/case-work-assistant-core.php';

/** Test router provides the same boundary with synthetic source/auth and SQLite.
 * Production uses existing authentication, ACL, IONOS and database only.
 */
if (!function_exists('svAssistantEnvironment')) {
    function svAssistantEnvironment(array $request=[]): array {
        require_once __DIR__.'/config.php';commonHeaders();$user=requireAuth();$session=session_id();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        if(!in_array(env('CASE_WORK_ASSISTANT_MODE','live'),['mock','review','live'],true))throw new RuntimeException('ASSISTANT_NOT_ENABLED');
        require_once __DIR__.'/ionos-storage.php';require_once __DIR__.'/profile-routing.php';
        require_once __DIR__.'/case-analysis-core.php';
        require_once __DIR__.'/case-work-assistant-setup.php';
        $release=svAssistantRelease();
        $loader=function(array $user,string $folder): array {
            require_once __DIR__.'/case-work-assistant-access.php';
            svAssistantCaseAccess(db(),$user,$folder,(string)($_SESSION['svnet_selected_expert']??''));
            $meta=[];$docs=[];
            foreach(caCaseFiles($folder) as $file){
                $hash=hash('sha256',ionosBytes($file['id']));
                if($file['name']==='00_Falldaten.json')$meta=json_decode(ionosBytes($file['id']),true,512,JSON_THROW_ON_ERROR);
                else $docs[]=['id'=>$file['id'],'sha256'=>$hash,'evidence'=>false];
            }
            $s=db()->prepare('SELECT user_email FROM case_folder_owners WHERE folder_id=?');$s->execute([$folder]);
            $profile=svnetUserProfile(['email'=>(string)$s->fetchColumn()]);
            return ['folder_id'=>$folder,'claim_number'=>(string)($meta['schaden_nr']??''),'profile'=>$profile,
                'insurer'=>in_array($meta['fallart']??'',['SV','SV-GF'],true)?'SV':(string)($meta['versicherer']??''),'line'=>($meta['fallart']??'')==='SV-GF'?'GF':(string)($meta['sparte']??'unclassified'),
                'revision'=>hash('sha256',json_encode([$meta,$docs],JSON_THROW_ON_ERROR)),'documents'=>$docs,'payments_verified'=>false];
        };
        $read=function(string $id):array{$file=ionosItem($id);return ['name'=>$file['name'],'mime'=>$file['mimeType'],'bytes'=>ionosBytes($id)];};
        if(($request['sandbox']??false)===true){
            $demoFolder='sv-demo-'.(int)$user['id'];$demoProfile=svnetUserProfile($user);
            $demoBytes="SYNTHETISCHER VERBINDUNGSTEST. DEMO-SV-001. Rechnung R-1: 100,00 EUR netto, Umsatzsteuer 19,00 EUR, brutto 119,00 EUR. Keine Zahlung nachgewiesen. Keine echte Fallakte.";
            $loader=function(array $auth,string $folder)use($demoFolder,$demoProfile,$demoBytes,$user):array{
                if((int)$auth['id']!==(int)$user['id']||$folder!==$demoFolder)throw new RuntimeException('CASE_FORBIDDEN');
                return ['folder_id'=>$demoFolder,'claim_number'=>'DEMO-SV-001','profile'=>$demoProfile,'insurer'=>'SV','line'=>'GF','revision'=>hash('sha256',$demoBytes),'documents'=>[['id'=>'sv-demo-document','sha256'=>hash('sha256',$demoBytes),'evidence'=>false]],'payments_verified'=>false];
            };
            $read=fn(string $id)=>$id==='sv-demo-document'?['name'=>'Synthetischer-Verbindungstest.txt','mime'=>'text/plain','bytes'=>$demoBytes]:throw new RuntimeException('CASE_FORBIDDEN');
        }
        require_once __DIR__.'/case-work-assistant-provider.php';
        $provider=env('CASE_WORK_ASSISTANT_MODE','live')==='live'?new SvAssistantProvider(trim(env('OPENAI_ASSISTANT_API_KEY','')),Closure::fromCallable($read)):null;
        return ['engine'=>new CaseWorkAssistant(db(),$loader,$release,$session,null,$provider),'user'=>array_merge($user,['active'=>!empty($user['is_active'])])];
    }
}

header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if($_SERVER['REQUEST_METHOD']!=='POST'||($_SERVER['HTTP_X_SV_ASSISTANT']??'')!=='1')throw new RuntimeException('REQUEST_FORBIDDEN');
    $origin=parse_url((string)($_SERVER['HTTP_ORIGIN']??''));
    $expected=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
    $actual=($origin['scheme']??'').'://'.($origin['host']??'').(isset($origin['port'])?':'.$origin['port']:'');
    if(!hash_equals($expected,$actual))throw new RuntimeException('ORIGIN_FORBIDDEN');
    $raw=file_get_contents('php://input',false,null,0,65537);
    if(strlen($raw)>65536)throw new RuntimeException('REQUEST_TOO_LARGE');
    $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(in_array($body['action']??'',['assistant_connections','assistant_sync_master'],true)){require_once __DIR__.'/config.php';commonHeaders();$auth=requireAuth();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();require_once __DIR__.'/case-work-assistant-setup.php';$data=$body['action']==='assistant_sync_master'?svAssistantSyncMaster($auth):svAssistantConnections($auth);echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;}
    $env=svAssistantEnvironment($body);$engine=$env['engine'];$user=$env['user'];$engine->schema();
    $action=(string)($body['action']??'');$token=(string)($body['token']??'');$tab=(string)($body['tab_id']??'');$id=(int)($body['job_id']??0);
    if($action==='assistant_step'){ignore_user_abort(true);set_time_limit(210);}
    $result=match($action){
        'assistant_demo'=>($body['sandbox']??false)===true?$engine->open($user,$tab,'sv-demo-'.(int)$user['id'],'DEMO-SV-001'):throw new RuntimeException('REQUEST_FORBIDDEN'),
        'assistant_open'=>$engine->open($user,$tab,(string)($body['folder_id']??''),(string)($body['claim_number']??'')),
        'assistant_close'=>$engine->close($user,$token,$tab),
        'assistant_start'=>$engine->start($user,$token,$tab,(string)($body['request_key']??''),(string)($body['task']??''),(string)($body['instruction']??''),(string)($body['route']??'assistant')),
        'assistant_step'=>$engine->step($user,$token,$tab,$id),
        'assistant_history'=>$engine->history($user,$token,$tab),
        'assistant_recover'=>$engine->recover($user,$token,$tab,$id),
        'assistant_status'=>$engine->status($user,$token,$tab,$id),
        'assistant_pause'=>$engine->control($user,$token,$tab,$id,'pause'),
        'assistant_resume'=>$engine->control($user,$token,$tab,$id,'resume'),
        'assistant_cancel'=>$engine->discard($user,$token,$tab,$id),
        default=>throw new RuntimeException('ACTION_INVALID')
    };
    echo json_encode(['ok'=>true,'data'=>$result,'budget'=>$engine->budget()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){
    $code=$error->getMessage();
    if(!preg_match('/^[A-Z][A-Z_]+$/',$code)){$code='INTERNAL_ERROR';error_log('[case-work-assistant] '.get_class($error));}
    http_response_code(str_contains($code,'FORBIDDEN')?403:409);echo json_encode(['ok'=>false,'error'=>$code],JSON_UNESCAPED_UNICODE);
}
