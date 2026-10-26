<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/oauth/lib.php';
require_once dirname(__DIR__) . '/api/case-search.php';
require_once __DIR__ . '/drive-read.php';
require_once __DIR__ . '/claims-tasks.php';
require_once __DIR__ . '/claims-source.php';
require_once __DIR__ . '/native-forms.php';
require_once __DIR__ . '/docx-content.php';
require_once __DIR__ . '/pdf-content.php';
commonHeaders();

function mcpResult(mixed $id, array $result): never { oauthJson(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]); }
function mcpFailure(mixed $id, int $code, string $message): never { oauthJson(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]], 200); }
function mcpAuthFailure(mixed $id, string $scope='cases:read'): never {
    $challenge = 'Bearer resource_metadata="https://www.sv-netzwerk.eu/.well-known/oauth-protected-resource/intern/mcp/", scope="'.$scope.'"';
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
      FROM case_folder_owners o WHERE ".caseSearchOwnerScope($user);
    $params=[':user_id'=>(int)$user['id'], ':user_email'=>(string)($user['email']??'')];
    if ($query!=='') { $sql .= " AND (o.folder_id LIKE :q OR o.case_no LIKE :q OR o.policy_no LIKE :q OR o.object_name LIKE :q OR o.damage_type LIKE :q OR o.case_type LIKE :q OR EXISTS(SELECT 1 FROM bki_calculations b WHERE b.folder_id=o.folder_id AND (b.case_no LIKE :q OR b.object_name LIKE :q OR b.damage_type LIKE :q)))"; $params[':q']='%'.$query.'%'; }
    $sql .= ' ORDER BY o.registered_at DESC LIMIT 50';
    $stmt=db()->prepare($sql);$stmt->execute($params);return $stmt->fetchAll() ?: [];
}
function ownCase(array $user, string $folderId): ?array {
    $stmt=db()->prepare("SELECT o.folder_id,o.registered_at,o.policy_no,o.case_type,
      COALESCE(NULLIF(o.case_no,''),(SELECT b.case_no FROM bki_calculations b WHERE b.folder_id=o.folder_id ORDER BY b.created_at DESC LIMIT 1)) case_no,
      COALESCE(NULLIF(o.object_name,''),(SELECT b.object_name FROM bki_calculations b WHERE b.folder_id=o.folder_id ORDER BY b.created_at DESC LIMIT 1)) object_name,
      COALESCE(NULLIF(o.damage_type,''),(SELECT b.damage_type FROM bki_calculations b WHERE b.folder_id=o.folder_id ORDER BY b.created_at DESC LIMIT 1)) damage_type
      FROM case_folder_owners o WHERE ".caseSearchOwnerScope($user)." AND o.folder_id=:folder LIMIT 1");
    $stmt->execute([':user_id'=>(int)$user['id'],':user_email'=>(string)($user['email']??''),':folder'=>$folderId]);
    $row=$stmt->fetch();
    return $row ?: null;
}
function caseTitle(array $row): string {
    $parts=array_filter([trim((string)($row['case_no']??'')),trim((string)($row['policy_no']??'')),trim((string)($row['object_name']??'')),trim((string)($row['damage_type']??''))]);
    return $parts?implode(' · ',$parts):'Versicherungsfall '.substr((string)$row['folder_id'],0,12);
}

// OAuth-aware MCP clients probe the endpoint with GET before sending an
// initialize request. Return the same RFC 9728 challenge as an unauthenticated
// POST; a 200 informational document is otherwise mistaken for auth metadata.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauthChallenge('cases:read cases:drafts.write');

// Challenge the MCP client before initialization so ChatGPT starts the
// personal OAuth flow instead of merely listing an unauthenticated server.
if (!oauthBearerUserOrNull('cases:read')) oauthChallenge('cases:read cases:drafts.write');

$request=requestBody();$id=$request['id']??null;$method=(string)($request['method']??'');$params=is_array($request['params']??null)?$request['params']:[];
if ($method==='initialize') mcpResult($id,['protocolVersion'=>(string)($params['protocolVersion']??'2025-06-18'),'capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'sv-netzwerk-fallbearbeitung','version'=>'1.3.0'],'instructions'=>'Nutze ausschließlich Fälle des angemeldeten Portalbenutzers. Die Fallakten und Unterlagen liegen im geprüften IONOS-Prüfportal; Google Drive ist keine Fallablage. Vor jeder inhaltlichen Bearbeitung zuerst search und anschließend fetch aufrufen. Relevante Akten anschließend gezielt mit read_case_file lesen. Normale Schadenberichte und SV-GF-/Groß-TF-Schäden haben unterschiedliche verbindliche Gliederungen. Gib nur die angeforderten Falldaten oder Ergebnisse aus. Interne Test-, Browser-, QS- und Verbindungsdetails gehören nicht in die Antwort. Ergebnisse nur auf ausdrücklichen Wunsch des Benutzers mit save_case_draft als neuen Entwurf zurückgeben. Originalbelege und versandte Fassungen niemals verändern. Ausdrücklich beauftragte Änderungen an vorhandenen nativen Formularentwürfen ausschließlich mit claimsforce_native_form und bestätigtem Rücklesen ausführen. save_case_draft ist nur Portaltextablage. Weder Speichern noch ein erfolgreicher Toolaufruf beweisen vollständige fachliche Bearbeitung, QS oder Versand.']);
if ($method==='notifications/initialized') { http_response_code(202); exit; }
if ($method==='ping') mcpResult($id,(object)[]);
if ($method==='tools/list') mcpResult($id,['tools'=>[
  ['name'=>'claimsforce_native_form','title'=>'Natives Claimsforce-Originalformular lesen und Entwurf speichern','description'=>'Use the existing browser bridge (1.4.70+) for native SE/ZB drafts. inspect without form_id lists existing forms; inspect with form_id and exact claim_number reads actual fields and a snapshot hash. save requires that hash and exact before/after values; only existing drafts, never sent originals. Use a fresh UUID operation_id per logical request, reuse it on uncertain retries. poll retrieves the same operation. Saving is verified only after native save plus reopen/readback. Bridge 1.4.77+: inspect_templates reads current inventory hash and original template names; create with that expected_hash, exact claim_number and template_name creates a new native SE/ZB draft only if no matching draft exists, then reopens and verifies it. Reuse operation_id after uncertainty; never blindly create again. Sent originals remain unchanged. No report upload, attachments, QS or sending. A successful field save does not establish financial correctness or document completeness.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string'],'operation_id'=>['type'=>'string'],'action'=>['type'=>'string','enum'=>['inspect','save','inspect_templates','create','poll']],'form_id'=>['type'=>'string'],'template_name'=>['type'=>'string','enum'=>['SV Schlusserklärung','SV Zahlungsbefürwortung']],'claim_number'=>['type'=>'string'],'expected_hash'=>['type'=>'string'],'changes'=>['type'=>'array','maxItems'=>100,'items'=>['type'=>'object','properties'=>['field'=>['type'=>'string'],'before'=>['type'=>'string'],'after'=>['type'=>'string','maxLength'=>10000]],'required'=>['field','before','after'],'additionalProperties'=>false]]],'required'=>['id','operation_id','action'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read','cases:drafts.write']]],'annotations'=>['readOnlyHint'=>false,'openWorldHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true]],
  ['name'=>'read_claimsforce_source','title'=>'Claimsforce-Mail oder Originalbeleg direkt lesen','description'=>'Read a case source through the existing authenticated Claimsforce bridge, independently of an incomplete portal file import. Use id from fetch; kind messages lists native message IDs, message reads the selected message_id, document reads a CLAIM_FILE attachment ID. Set refresh=true once to request a read, then poll refresh=false. The browser bridge must be active. No Claimsforce writes, notes or status changes. PDF results include extracted text or local OCR; never treat a list entry as read evidence.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string'],'kind'=>['type'=>'string','enum'=>['messages','message','document']],'source_id'=>['type'=>'string','description'=>'Native message UUID or CLAIM_FILE UUID; omit for messages'],'refresh'=>['type'=>'boolean','default'=>false]],'required'=>['id','kind'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read']]],'annotations'=>['readOnlyHint'=>false,'openWorldHint'=>false,'destructiveHint'=>false]],
  ['name'=>'list_claimsforce_tasks','title'=>'Eigene Claimsforce-Aufgaben lesen und aktualisieren','description'=>'Read the actual Claimsforce task snapshot from the existing authenticated browser bridge. Set refresh=true to queue a fresh read; poll later with refresh=false. Never treat stale or pending data as current. This does not edit or complete Claimsforce tasks.','inputSchema'=>['type'=>'object','properties'=>['refresh'=>['type'=>'boolean','default'=>false]],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read']]],'annotations'=>['readOnlyHint'=>false,'openWorldHint'=>false,'destructiveHint'=>false]],
  ['name'=>'search','title'=>'Eigene Versicherungsfälle suchen','description'=>'Use this when the user wants to find or review insurance claims assigned to their own SV-Netzwerk account.','inputSchema'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Schaden-Nr., VN/Objekt, Schadenart oder Suchbegriff']],'required'=>['query'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read']]],'annotations'=>['readOnlyHint'=>true,'openWorldHint'=>false,'destructiveHint'=>false]],
  ['name'=>'fetch','title'=>'Eigenen Versicherungsfall abrufen','description'=>'Use this when a case returned by search must be opened and its verified portal data reviewed.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string','description'=>'Fallordner-ID aus search']],'required'=>['id'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read']]],'annotations'=>['readOnlyHint'=>true,'openWorldHint'=>false,'destructiveHint'=>false]],
  ['name'=>'read_case_file','title'=>'Unterlage aus dem eigenen Schadenfall lesen','description'=>'Use this after fetch to read one relevant document, image, PDF, spreadsheet, or drone recording from the authenticated user’s own case.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string','description'=>'Fallordner-ID aus search/fetch'],'file_id'=>['type'=>'string','description'=>'Datei-ID aus der Dokumentenliste von fetch'],'include_images'=>['type'=>'boolean','default'=>false,'description'=>'Optional PDF page images for explicit visual review; default returns compact extracted text or OCR without binary data']],'required'=>['id','file_id'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read']]],'annotations'=>['readOnlyHint'=>true,'openWorldHint'=>false,'destructiveHint'=>false]],
  ['name'=>'save_case_draft','title'=>'ChatGPT-Entwurf zum eigenen Fall speichern','description'=>'Use this when the user explicitly asks to return a completed text from ChatGPT Work to their own SV-Netzwerk case as a new draft. This never changes originals, case data, approvals, or sent documents.','inputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string','description'=>'Fallordner-ID aus search/fetch'],'title'=>['type'=>'string','description'=>'Kurzer Titel des Entwurfs','maxLength'=>500],'content'=>['type'=>'string','description'=>'Vollständiger, vom Benutzer angeforderter Entwurf','maxLength'=>100000],'task'=>['type'=>'string','description'=>'Zugehöriger Arbeitsauftrag oder Berichtswunsch','maxLength'=>20000]],'required'=>['id','title','content'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read','cases:drafts.write']]],'annotations'=>['readOnlyHint'=>false,'openWorldHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false]]
]]);
if ($method==='tools/call') {
    $name=(string)($params['name']??'');$args=is_array($params['arguments']??null)?$params['arguments']:[];
    $requiredScope=in_array($name,['save_case_draft','claimsforce_native_form'],true)?'cases:drafts.write':'cases:read';
    $user = oauthBearerUserOrNull($requiredScope);
    if (!$user) mcpAuthFailure($id,$requiredScope);
    if($name==='claimsforce_native_form'){
        try{$snapshot=mcpNativeForms($user,$args);}catch(Throwable $error){mcpFailure($id,-32014,$error->getMessage());}
        mcpResult($id,['content'=>[['type'=>'text','text'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$snapshot]);
    }
    if ($name==='read_claimsforce_source') {
        try { $snapshot=mcpClaimsSource($user,trim((string)($args['id']??'')),(string)($args['kind']??''),(string)($args['source_id']??''),($args['refresh']??false)===true); $blocks=mcpSourceContent($snapshot); }
        catch (Throwable $error) { mcpFailure($id,-32013,$error->getMessage()); }
        mcpResult($id,['content'=>$blocks]);
    }
    if ($name==='list_claimsforce_tasks') {
        try { $snapshot=mcpClaimsTasks($user,($args['refresh']??false)===true); }
        catch (Throwable $error) { mcpFailure($id,-32012,$error->getMessage()); }
        mcpResult($id,['content'=>[['type'=>'text','text'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$snapshot]);
    }
    if ($name==='search') {
        $query=mb_substr(trim((string)($args['query']??'')),0,200);$results=[];
        foreach(ownCases($user,$query) as $row)$results[]=['id'=>(string)$row['folder_id'],'title'=>caseTitle($row),'url'=>caseUrl((string)$row['folder_id'])];
        mcpResult($id,['content'=>[['type'=>'text','text'=>json_encode(['results'=>$results],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]]);
    }
    if ($name==='fetch') {
        $folder=trim((string)($args['id']??''));requireCaseFolderAccess($folder,$user);$row=ownCase($user,$folder);
        if(!$row)mcpFailure($id,-32004,'Fall wurde nicht gefunden.');
        $calcs=db()->prepare('SELECT id,case_no,damage_type,object_name,location,net_total,vat_total,gross_total,created_at FROM bki_calculations WHERE folder_id=:folder ORDER BY created_at DESC LIMIT 10');$calcs->execute([':folder'=>$folder]);
        try{$files=mcpDriveCaseFiles($folder);$driveMeta=mcpDriveCaseMeta($folder,$files);}catch(Throwable $error){mcpFailure($id,-32010,$error->getMessage());}
        $metadata=array_merge(['schaden_nr'=>(string)($row['case_no']??''),'versicherungsschein_nr'=>(string)($row['policy_no']??''),'vn_objekt'=>(string)($row['object_name']??''),'schadenart'=>(string)($row['damage_type']??''),'fallart'=>(string)($row['case_type']??'')],$driveMeta);
        $metadata['kalkulationen']=$calcs->fetchAll() ?: [];
        $payload=['id'=>$folder,'title'=>caseTitle($row),'text'=>'Eigener Versicherungsfall im SV-Netzwerk Prüfportal. Fallberechtigung und jede ausgegebene Datei wurden serverseitig geprüft.','url'=>caseUrl($folder),'metadata'=>$metadata,'documents'=>$files,'document_count'=>count($files),'truncated'=>count($files)>=MCP_MAX_CASE_FILES];
        mcpResult($id,['content'=>[['type'=>'text','text'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]]);
    }
    if ($name==='read_case_file') {
        $folder=trim((string)($args['id']??''));$fileId=trim((string)($args['file_id']??''));requireCaseFolderAccess($folder,$user);
        if($fileId==='')mcpFailure($id,-32602,'Eine Datei-ID aus fetch ist erforderlich.');
        try{$file=mcpDriveFindCaseFile($folder,$fileId);$download=mcpDriveDownload($fileId,(string)$file['mime_type']);}catch(Throwable $error){mcpFailure($id,-32011,$error->getMessage());}
        $mime=(string)$download['mime_type'];$uri='svnet://case/'.rawurlencode($folder).'/file/'.rawurlencode($fileId);
        $header=['type'=>'text','text'=>json_encode(['case_id'=>$folder,'file'=>$file,'effective_mime_type'=>$mime],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $contentBlocks=[$header];
        if (mcpDriveIsText($mime)) {
            $contentBlocks[]=['type'=>'text','text'=>mb_substr((string)$download['bytes'],0,500000)];
        } elseif ($mime==='application/pdf') {
            $contentBlocks=array_merge($contentBlocks,mcpPdfContent((string)$download['bytes'],($args['include_images']??false)===true));
        } elseif ($mime==='application/vnd.openxmlformats-officedocument.wordprocessingml.document'||preg_match('/\.docx$/i',(string)$file['name'])) {
            try{$contentBlocks=array_merge($contentBlocks,mcpDocxContent((string)$download['bytes']));}catch(Throwable $error){mcpFailure($id,-32015,$error->getMessage());}
        } else {
            $contentBlocks[]=['type'=>'resource','resource'=>['uri'=>$uri,'mimeType'=>$mime,'blob'=>base64_encode((string)$download['bytes'])]];
        }
        mcpResult($id,['content'=>$contentBlocks,'structuredContent'=>['case_id'=>$folder,'file_id'=>$fileId,'name'=>$file['name'],'mime_type'=>$mime,'read_only'=>true]]);
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
