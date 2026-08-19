<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'], ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) {
    apiError(403, 'Keine Berechtigung.');
}

const DEFAULT_CASES_FOLDER_ID = '1dqdQkccdGLuo8ijKerGgYdmNO2cBtD4P';
const DEFAULT_KNOWLEDGE_FOLDER_ID = '1QeJ4Dz6Upg_W5rahWmKE_7KbC4MMgSGe';
const DEFAULT_BLANCO_FOLDER_ID = '1_3p6moLBt3cD5Gzy9jvPw3BXpqnAvl2k';
const CASE_META_NAME = '00_Falldaten.json';

$action = (string)($_GET['action'] ?? 'status');

function gdB64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

function gdAccessToken(): string {
    static $token = null;
    if ($token !== null) return $token;

    $serviceJson = trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON', ''));
    if ($serviceJson !== '') {
        if (!str_starts_with($serviceJson, '{')) {
            $decoded = base64_decode($serviceJson, true);
            if ($decoded !== false) $serviceJson = $decoded;
        }
        $svc = json_decode($serviceJson, true);
        if (is_array($svc) && !empty($svc['client_email']) && !empty($svc['private_key'])) {
            $now = time();
            $header = gdB64url(json_encode(['alg'=>'RS256','typ'=>'JWT'], JSON_UNESCAPED_SLASHES));
            $claims = gdB64url(json_encode([
                'iss'=>$svc['client_email'],
                'scope'=>'https://www.googleapis.com/auth/drive',
                'aud'=>'https://oauth2.googleapis.com/token',
                'iat'=>$now,
                'exp'=>$now + 3500,
            ], JSON_UNESCAPED_SLASHES));
            $input = $header . '.' . $claims;
            $signature = '';
            if (!openssl_sign($input, $signature, $svc['private_key'], OPENSSL_ALGO_SHA256)) {
                apiError(503, 'Google-Drive-Servicekonto konnte nicht signieren.');
            }
            $jwt = $input . '.' . gdB64url($signature);
            $resp = gdHttp('POST', 'https://oauth2.googleapis.com/token', [
                'Content-Type: application/x-www-form-urlencoded'
            ], http_build_query([
                'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'=>$jwt,
            ]), false);
            $body = json_decode($resp['body'], true);
            if ($resp['status'] === 200 && !empty($body['access_token'])) return $token = (string)$body['access_token'];
            error_log('[google-drive-sync] service account token error: ' . substr($resp['body'],0,800));
        }
    }

    $clientId = env('GOOGLE_DRIVE_CLIENT_ID', '');
    $clientSecret = env('GOOGLE_DRIVE_CLIENT_SECRET', '');
    $refreshToken = env('GOOGLE_DRIVE_REFRESH_TOKEN', '');
    if ($clientId !== '' && $clientSecret !== '' && $refreshToken !== '') {
        $resp = gdHttp('POST', 'https://oauth2.googleapis.com/token', [
            'Content-Type: application/x-www-form-urlencoded'
        ], http_build_query([
            'client_id'=>$clientId,
            'client_secret'=>$clientSecret,
            'refresh_token'=>$refreshToken,
            'grant_type'=>'refresh_token',
        ]), false);
        $body = json_decode($resp['body'], true);
        if ($resp['status'] === 200 && !empty($body['access_token'])) return $token = (string)$body['access_token'];
        error_log('[google-drive-sync] refresh token error: ' . substr($resp['body'],0,800));
    }

    apiError(503, 'Google Drive ist serverseitig noch nicht authentifiziert. Bitte Servicekonto oder OAuth-Refresh-Token in der Server-.env konfigurieren.');
}

function gdHttp(string $method, string $url, array $headers = [], ?string $body = null, bool $auth = true): array {
    if ($auth) $headers[] = 'Authorization: Bearer ' . gdAccessToken();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>12,
        CURLOPT_TIMEOUT=>45,
        CURLOPT_FOLLOWLOCATION=>true,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false || $err !== '') {
        apiError(503, 'Google-Drive-Verbindung fehlgeschlagen: ' . ($err ?: 'unbekannter Fehler'));
    }
    return ['status'=>$status,'body'=>(string)$response];
}

function gdApi(string $method, string $path, array $query = [], ?array $json = null): array {
    $url = 'https://www.googleapis.com/drive/v3/' . ltrim($path, '/');
    $query += ['supportsAllDrives'=>'true'];
    if ($query) $url .= '?' . http_build_query($query);
    $headers = [];$body=null;
    if ($json !== null) {
        $headers[]='Content-Type: application/json; charset=utf-8';
        $body=json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    $resp=gdHttp($method,$url,$headers,$body,true);
    $data=json_decode($resp['body'],true);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        error_log('[google-drive-sync] API '.$resp['status'].' '.$path.' '.substr($resp['body'],0,1200));
        apiError(503, 'Google-Drive-API-Fehler (' . $resp['status'] . ').');
    }
    return is_array($data) ? $data : [];
}

function gdFolderId(string $kind): string {
    return match ($kind) {
        'cases' => env('GOOGLE_DRIVE_CASES_FOLDER_ID', DEFAULT_CASES_FOLDER_ID),
        'knowledge' => env('GOOGLE_DRIVE_KNOWLEDGE_FOLDER_ID', DEFAULT_KNOWLEDGE_FOLDER_ID),
        'blanco' => env('GOOGLE_DRIVE_BLANCO_FOLDER_ID', DEFAULT_BLANCO_FOLDER_ID),
        default => '',
    };
}

function gdEscape(string $v): string { return str_replace("'", "\\'", $v); }

function gdListChildren(string $parentId, ?string $mime = null, int $limit = 1000): array {
    $q = "'" . gdEscape($parentId) . "' in parents and trashed=false";
    if ($mime !== null) $q .= " and mimeType='" . gdEscape($mime) . "'";
    $out=[];$pageToken='';
    do {
        $query=['q'=>$q,'fields'=>'nextPageToken,files(id,name,mimeType,modifiedTime,createdTime,size,webViewLink,parents)','pageSize'=>min(1000,$limit-count($out)),'orderBy'=>'name_natural'];
        if ($pageToken!=='') $query['pageToken']=$pageToken;
        $data=gdApi('GET','files',$query);
        foreach (($data['files']??[]) as $f) $out[]=$f;
        $pageToken=(string)($data['nextPageToken']??'');
    } while ($pageToken!=='' && count($out)<$limit);
    return $out;
}

function gdDownload(string $fileId): string {
    $url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?alt=media&supportsAllDrives=true';
    $resp=gdHttp('GET',$url,[],null,true);
    if ($resp['status']!==200) apiError(503,'Google-Drive-Datei konnte nicht geladen werden.');
    return $resp['body'];
}

function gdCreateFolder(string $parentId, string $name): array {
    return gdApi('POST','files', ['fields'=>'id,name,webViewLink'], [
        'name'=>$name,
        'mimeType'=>'application/vnd.google-apps.folder',
        'parents'=>[$parentId],
    ]);
}

function gdFindChildByName(string $parentId, string $name): ?array {
    $q="'".gdEscape($parentId)."' in parents and trashed=false and name='".gdEscape($name)."'";
    $data=gdApi('GET','files',['q'=>$q,'fields'=>'files(id,name,mimeType,modifiedTime,webViewLink)','pageSize'=>20]);
    return !empty($data['files'][0]) ? $data['files'][0] : null;
}

function gdUploadJson(string $parentId, string $name, array $payload): array {
    $existing=gdFindChildByName($parentId,$name);
    $meta=['name'=>$name,'mimeType'=>'application/json'];
    if (!$existing) $meta['parents']=[$parentId];
    $boundary='svnet'.bin2hex(random_bytes(8));
    $body='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".
        json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\r\n--".$boundary.
        "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".
        json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\r\n--".$boundary."--";
    $method=$existing?'PATCH':'POST';
    $url='https://www.googleapis.com/upload/drive/v3/files'.($existing?'/'.rawurlencode($existing['id']):'').'?uploadType=multipart&supportsAllDrives=true&fields=id,name,modifiedTime,webViewLink';
    $resp=gdHttp($method,$url,['Content-Type: multipart/related; boundary='.$boundary],$body,true);
    if ($resp['status']<200||$resp['status']>=300) apiError(503,'Falldaten konnten nicht in Google Drive gespeichert werden.');
    return json_decode($resp['body'],true)?:[];
}

function gdNormalize(string $s): string {
    $s=mb_strtolower(trim($s),'UTF-8');
    $s=strtr($s,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    return preg_replace('/[^a-z0-9]+/u',' ', $s) ?? '';
}

function gdCaseMeta(string $folderId): array {
    $metaFile=gdFindChildByName($folderId,CASE_META_NAME);
    if (!$metaFile) return [];
    $raw=gdDownload((string)$metaFile['id']);
    $data=json_decode($raw,true);
    return is_array($data)?$data:[];
}

function gdCaseName(array $d): string {
    $parts=[];
    foreach (['schaden_nr','vn_objekt','ort'] as $key) {
        $v=trim((string)($d[$key]??''));
        if ($v!=='') $parts[]=$v;
    }
    $name=implode(' - ',$parts);
    return $name!=='' ? mb_substr($name,0,180) : 'GF-Fall '.date('Y-m-d H-i');
}

switch ($action) {
    case 'status':
        gdAccessToken();
        $counts=[];
        foreach (['cases','knowledge','blanco'] as $kind) {
            $id=gdFolderId($kind);
            $items=gdListChildren($id,null,1000);
            $counts[$kind]=count($items);
        }
        apiJson(['ok'=>true,'connected'=>true,'folders'=>[
            'cases'=>['id'=>gdFolderId('cases'),'name'=>'Schadenfälle Christian Wächter','items'=>$counts['cases']],
            'knowledge'=>['id'=>gdFolderId('knowledge'),'name'=>'00_KI-Wissensbasis','items'=>$counts['knowledge']],
            'blanco'=>['id'=>gdFolderId('blanco'),'name'=>'Blanco','items'=>$counts['blanco']],
        ]]);

    case 'search_cases':
        $term=trim((string)($_GET['q']??''));
        $norm=gdNormalize($term);
        $folders=gdListChildren(gdFolderId('cases'),'application/vnd.google-apps.folder',1000);
        $results=[];
        foreach ($folders as $folder) {
            $name=(string)($folder['name']??'');
            $meta=[];
            $nameMatch=$norm==='' || str_contains(gdNormalize($name),$norm);
            if (!$nameMatch || $norm==='') {
                try { $meta=gdCaseMeta((string)$folder['id']); } catch (Throwable $e) { $meta=[]; }
            }
            $hay=gdNormalize($name.' '.implode(' ',array_map('strval',[ $meta['schaden_nr']??'', $meta['vn_objekt']??'', $meta['ort']??'', $meta['strasse']??'', $meta['versicherungsschein_nr']??'' ])));
            if ($norm!=='' && !str_contains($hay,$norm)) continue;
            $results[]=['id'=>$folder['id'],'name'=>$name,'modifiedTime'=>$folder['modifiedTime']??null,'webViewLink'=>$folder['webViewLink']??null,'meta'=>$meta];
            if (count($results)>=100) break;
        }
        apiJson(['ok'=>true,'query'=>$term,'results'=>$results]);

    case 'load_case':
        $id=trim((string)($_GET['id']??''));
        if ($id==='') apiError(400,'Fall-ID fehlt.');
        $meta=gdCaseMeta($id);
        $files=gdListChildren($id,null,1000);
        apiJson(['ok'=>true,'case'=>['id'=>$id,'meta'=>$meta,'files'=>$files]]);

    case 'save_case':
        if ($_SERVER['REQUEST_METHOD']!=='POST') apiError(405,'POST erforderlich.');
        $body=requestBody();
        $folderId=trim((string)($body['folder_id']??''));
        $data=is_array($body['case']??null)?$body['case']:[];
        $data['updated_at']=gmdate('c');
        $data['updated_by']=$user['full_name']??($user['email']??'');
        if ($folderId==='') {
            $folder=gdCreateFolder(gdFolderId('cases'),gdCaseName($data));
            $folderId=(string)($folder['id']??'');
            if ($folderId==='') apiError(503,'Fallordner konnte nicht angelegt werden.');
            $data['created_at']=$data['created_at']??gmdate('c');
        }
        gdUploadJson($folderId,CASE_META_NAME,$data);
        apiJson(['ok'=>true,'folder_id'=>$folderId,'name'=>gdCaseName($data)]);

    case 'sync_sources':
        $knowledge=gdListChildren(gdFolderId('knowledge'),null,1000);
        $blanco=gdListChildren(gdFolderId('blanco'),null,1000);
        $pick=function(array $items,array $needles): array {
            $out=[];
            foreach($items as $f){
                $n=gdNormalize((string)($f['name']??''));
                foreach($needles as $k=>$needle){ if(str_contains($n,gdNormalize($needle))){$out[$k]=$f;break;} }
            }
            return $out;
        };
        apiJson(['ok'=>true,'sources'=>[
            'knowledge'=>$pick($knowledge,[
                'master'=>'MASTER-ARBEITSSTANDARD',
                'engel'=>'QS-Engel',
                'gf_prompt'=>'Prompt GFGrossschaden',
                'gf_richtlinien'=>'Zusammenfassung Richtlinien - SV GF-Schäden',
                'erstbericht_anforderungen'=>'Groß TF SV Erstbericht Anforderungen Inhalt',
            ]),
            'blanco'=>$pick($blanco,[
                'erstbericht'=>'Erstbericht',
                'zwischenbericht'=>'Zwischenbericht',
                'schlussbericht'=>'Schlussbericht',
                'protokoll_gewerbe'=>'Schadenprotokoll_Gewerbe',
                'protokoll_privat'=>'Schadenprotokoll_Privat',
                'protokoll_frost'=>'Schadenprotokoll_Frost',
            ]),
        ]]);

    default:
        apiError(400,'Unbekannte Aktion.');
}
