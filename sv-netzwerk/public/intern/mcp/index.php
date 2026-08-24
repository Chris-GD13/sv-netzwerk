<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/oauth/lib.php';
commonHeaders();

function mcpResult(mixed $id, array $result): never { oauthJson(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]); }
function mcpFailure(mixed $id, int $code, string $message): never { oauthJson(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]], 200); }
function mcpAuthFailure(mixed $id, string $scope='cases:read'): never {
    $challenge = 'Bearer resource_metadata="https://www.sv-netzwerk.eu/.well-known/oauth-protected-resource", scope="'.$scope.'"';
    mcpResult($id,[
        'content'=>[['type'=>'text','text'=>'Bitte zuerst den persönlichen SV-Netzwerk-Zugang verbinden.']],
        'isError'=>true,
        '_meta'=>['mcp/www_authenticate'=>$challenge],
    ]);
}
function caseDraftSchema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS chatgpt_case_drafts(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      folder_id VARCHAR(190) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      title VARCHAR(500) NOT NULL,
      task_text MEDIUMTEXT NULL,
      content LONGTEXT NOT NULL,
      source VARCHAR(40) NOT NULL DEFAULT 'chatgpt_work',
      created_at DATETIME NOT NULL,
      INDEX idx_chatgpt_draft_folder(folder_id),
      INDEX idx_chatgpt_draft_user(user_id),
      INDEX idx_chatgpt_draft_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function caseUrl(string $folderId): string { return 'https://www.sv-netzwerk.eu/intern/versicherungsfaelle/?folder_id='.rawurlencode($folderId); }
function ownCases(array $user, string $query=''): array {
    $sql = "SELECT o.folder_id,o.registered_at,o.policy_no,o.case_type,
      COALESCE(NULLIF(o.case_no,''),(SELECT b.case_no FROM bki_calculations b WHERE b.folder_id=o.folder_id ORDER BY b.created_at DESC LIMIT 1)) case_no,
      COALESCE(NULLIF(o.object_name,''),(SELECT b.object_name FROM bki_calculations b WHERE b.folder_id=o.folder_id ORDER BY b.created_at DESC LIMIT 1)) object_name,
      COALESCE(NULLIF(o.damage_type,''),(SELECT b.damage_type FROM bki_calculations b WHERE b.folder_id=o.folder_id ORDER BY b.created_at DESC LIMIT 1)) damage_type
      FROM case_folder_owners o WHERE o.user_id=:user";
    $params=[':user'=>(int)$user['id']];
    if ($query!=='') { $sql .= " AND (o.folder_id LIKE :q OR o.case_no LIKE :q OR o.policy_no LIKE :q OR o.object_name LIKE :q OR o.damage_type LIKE :q OR o.case_type LIKE :q OR EXISTS(SELECT 1 FROM bki_calculations b WHERE b.folder_id=o.folder_id AND (b.case_no LIKE :q OR b.object_name LIKE :q OR b.damage_type LIKE :q)))"; $params[':q']='%'.$query.'%'; }
    $sql .= ' ORDER BY o.registered_at DESC LIMIT 50';
    $stmt=db()->prepare($sql);$stmt->execute($params);return $stmt->fetchAll() ?: [];
}
function caseTitle(array $row): string {
    $parts=array_filter([trim((string)($row['case_no']??'')),trim((string)($row['policy_no']??'')),trim((string)($row['object_name']??'')),trim((string)($row['damage_type']??''))]);
    return $parts?implode(' · ',$parts):'Versicherungsfall '.substr((string)$row['folder_id'],0,12);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauthJson(['name'=>'SV-Netzwerk Fallbearbeitung','version'=>'1.0.0','mcp'=>'POST erforderlich.'], 200);
$request=requestBody();$id=$request['id']??null;$method=(string)($request['method']??'');$params=is_array($request['params']??null)?$request['params']:[];
if ($method==='initialize') mcpResult($id,['protocolVersion'=>(string)($params['protocolVersion']??'2025-06-18'),'capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'sv-netzwerk-fallbearbeitung','version'=>'1.1.0'],'instructions'=>'Nutze ausschließlich Fälle des angemeldeten Portalbenutzers. Vor jeder inhaltlichen Bearbeitung zuerst search und anschließend fetch aufrufen. Ergebnisse nur auf ausdrücklichen Wunsch des Benutzers mit save_case_draft als neuen Entwurf zurückgeben. Originalunterlagen und Falldaten niemals verändern.']);
if ($method==='notifications/initialized') { http_response_code(202); exit; }
if ($method==='ping') mcpResult($id,(object)[]);
if ($method==='tools/list') mcpResult($id,['tools'=>[
  ['name'=>'search','title'=>'Eigene Versicherungsfälle suchen','description'=>'Use this when the user wants to find or review insurance claims assigned to their own SV-Netzwerk account.','inputSchema'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Schaden-Nr., VN/Objekt, Schadenart oder Suchbegriff']],'required'=>['query'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read']]],'annotations'=>['readOnlyHint'=>true,'openWorldHint'=>false,'destructiveHint'=>false]],
  ['name'=>'fetch','title'=>'Eigenen Versicherungsfall abrufen','description'=>'Use this when a case returned by search must be opened and its verified portal data reviewed.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string','description'=>'Fallordner-ID aus search']],'required'=>['id'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read']]],'annotations'=>['readOnlyHint'=>true,'openWorldHint'=>false,'destructiveHint'=>false]],
  ['name'=>'save_case_draft','title'=>'ChatGPT-Entwurf zum eigenen Fall speichern','description'=>'Use this when the user explicitly asks to return a completed text from ChatGPT Work to their own SV-Netzwerk case as a new draft. This never changes originals, case data, approvals, or sent documents.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string','description'=>'Fallordner-ID aus search/fetch'],'title'=>['type'=>'string','description'=>'Kurzer Titel des Entwurfs','maxLength'=>500],'content'=>['type'=>'string','description'=>'Vollständiger, vom Benutzer angeforderter Entwurf','maxLength'=>100000],'task'=>['type'=>'string','description'=>'Zugehöriger Arbeitsauftrag oder Berichtswunsch','maxLength'=>20000]],'required'=>['id','title','content'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read','cases:drafts.write']]],'annotations'=>['readOnlyHint'=>false,'openWorldHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false]]
]]);
if ($method==='tools/call') {
    $name=(string)($params['name']??'');$args=is_array($params['arguments']??null)?$params['arguments']:[];
    $requiredScope=$name==='save_case_draft'?'cases:drafts.write':'cases:read';
    $user = oauthBearerUserOrNull($requiredScope);
    if (!$user) mcpAuthFailure($id,$requiredScope);
    if ($name==='search') {
        $query=mb_substr(trim((string)($args['query']??'')),0,200);$results=[];
        foreach(ownCases($user,$query) as $row)$results[]=['id'=>(string)$row['folder_id'],'title'=>caseTitle($row),'url'=>caseUrl((string)$row['folder_id'])];
        mcpResult($id,['content'=>[['type'=>'text','text'=>json_encode(['results'=>$results],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]]);
    }
    if ($name==='fetch') {
        $folder=trim((string)($args['id']??''));requireCaseFolderAccess($folder,$user);$rows=ownCases($user,'');$row=null;foreach($rows as $candidate)if((string)$candidate['folder_id']===$folder){$row=$candidate;break;}
        if(!$row)mcpFailure($id,-32004,'Fall wurde nicht gefunden.');
        $calcs=db()->prepare('SELECT id,case_no,damage_type,object_name,location,net_total,vat_total,gross_total,created_at FROM bki_calculations WHERE folder_id=:folder ORDER BY created_at DESC LIMIT 10');$calcs->execute([':folder'=>$folder]);
        $payload=['id'=>$folder,'title'=>caseTitle($row),'text'=>'Eigener Versicherungsfall im SV-Netzwerk Prüfportal. Die Fallberechtigung wurde serverseitig geprüft.','url'=>caseUrl($folder),'metadata'=>['schaden_nr'=>(string)($row['case_no']??''),'versicherungsschein_nr'=>(string)($row['policy_no']??''),'vn_objekt'=>(string)($row['object_name']??''),'schadenart'=>(string)($row['damage_type']??''),'fallart'=>(string)($row['case_type']??''),'kalkulationen'=>$calcs->fetchAll() ?: []]];
        mcpResult($id,['content'=>[['type'=>'text','text'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]]);
    }
    if ($name==='save_case_draft') {
        $folder=trim((string)($args['id']??''));requireCaseFolderAccess($folder,$user);
        $title=mb_substr(trim((string)($args['title']??'')),0,500);
        $content=mb_substr(trim((string)($args['content']??'')),0,100000);
        $task=mb_substr(trim((string)($args['task']??'')),0,20000);
        if($title===''||$content==='')mcpFailure($id,-32602,'Titel und Entwurfstext sind erforderlich.');
        caseDraftSchema();
        $stmt=db()->prepare('INSERT INTO chatgpt_case_drafts(folder_id,user_id,title,task_text,content,source,created_at) VALUES(:folder,:user,:title,:task,:content,\'chatgpt_work\',UTC_TIMESTAMP())');
        $stmt->execute([':folder'=>$folder,':user'=>(int)$user['id'],':title'=>$title,':task'=>$task!==''?$task:null,':content'=>$content]);
        $draftId=(int)db()->lastInsertId();
        mcpResult($id,['content'=>[['type'=>'text','text'=>'Der ChatGPT-Entwurf wurde als neuer, ungeprüfter Entwurf im eigenen SV-Netzwerk-Fall gespeichert. Originalunterlagen und Falldaten wurden nicht verändert.']], 'structuredContent'=>['ok'=>true,'draft_id'=>$draftId,'case_id'=>$folder,'status'=>'draft','url'=>caseUrl($folder)]]);
    }
    mcpFailure($id,-32601,'Werkzeug nicht gefunden.');
}
mcpFailure($id,-32601,'Methode nicht gefunden.');
