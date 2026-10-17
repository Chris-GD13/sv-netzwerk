<?php
declare(strict_types=1);
require_once __DIR__.'/case-work-assistant-core.php';

/** Test router provides the same boundary with synthetic source/auth and SQLite.
 * Production uses existing authentication, ACL, IONOS and database only.
 */
if (!function_exists('svAssistantEnvironment')) {
    function svAssistantEnvironment(): array {
        require_once __DIR__.'/config.php';commonHeaders();$user=requireAuth();
        if(getenv('CASE_WORK_ASSISTANT_MODE')!=='mock')throw new RuntimeException('ASSISTANT_NOT_ENABLED');
        require_once __DIR__.'/ionos-storage.php';require_once __DIR__.'/profile-routing.php';
        require_once __DIR__.'/case-analysis-core.php';
        $path=(string)getenv('CASE_WORK_RULE_RELEASE');$expected=(string)getenv('CASE_WORK_RULE_RELEASE_SHA256');
        if(!$path||!is_file($path)||!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,hash_file('sha256',$path)))throw new RuntimeException('RULE_RELEASE_NOT_CONFIGURED');
        $release=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $loader=function(array $user,string $folder): array {
            requireCaseFolderAccess($folder,$user);
            $meta=[];$docs=[];
            foreach(caCaseFiles($folder) as $file){
                $hash=hash('sha256',ionosBytes($file['id']));
                if($file['name']==='00_Falldaten.json')$meta=json_decode(ionosBytes($file['id']),true,512,JSON_THROW_ON_ERROR);
                else $docs[]=['id'=>$file['id'],'sha256'=>$hash,'evidence'=>false];
            }
            $s=db()->prepare('SELECT user_email FROM case_folder_owners WHERE folder_id=?');$s->execute([$folder]);
            $profile=svnetUserProfile(['email'=>(string)$s->fetchColumn()]);
            return ['folder_id'=>$folder,'claim_number'=>(string)($meta['schaden_nr']??''),'profile'=>$profile,
                'insurer'=>(string)($meta['versicherer']??''),'line'=>(string)($meta['sparte']??''),
                'revision'=>hash('sha256',json_encode([$meta,$docs],JSON_THROW_ON_ERROR)),'documents'=>$docs,'payments_verified'=>false];
        };
        // No document amounts are inferred or marked verified by this adapter.
        return ['engine'=>new CaseWorkAssistant(db(),$loader,$release,session_id()),'user'=>array_merge($user,['active'=>!empty($user['is_active'])])];
    }
}

header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if($_SERVER['REQUEST_METHOD']!=='POST'||($_SERVER['HTTP_X_SV_ASSISTANT']??'')!=='1')throw new RuntimeException('REQUEST_FORBIDDEN');
    $origin=parse_url((string)($_SERVER['HTTP_ORIGIN']??''));
    $expected=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
    $actual=($origin['scheme']??'').'://'.($origin['host']??'').(isset($origin['port'])?':'.$origin['port']:'');
    if(!hash_equals($expected,$actual))throw new RuntimeException('ORIGIN_FORBIDDEN');
    $env=svAssistantEnvironment();$engine=$env['engine'];$user=$env['user'];$engine->schema();
    $raw=file_get_contents('php://input',false,null,0,65537);
    if(strlen($raw)>65536)throw new RuntimeException('REQUEST_TOO_LARGE');
    $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    $action=(string)($body['action']??'');$token=(string)($body['token']??'');$tab=(string)($body['tab_id']??'');$id=(int)($body['job_id']??0);
    $result=match($action){
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
