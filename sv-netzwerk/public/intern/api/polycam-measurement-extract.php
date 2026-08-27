<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user=requireAuth();
if(!in_array($user['role']??'', ['administrator','projektleiter','pruefer','sachverstaendiger'],true))apiError(403,'Keine Berechtigung.');
if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
$folderId=trim((string)($_POST['folder_id']??''));requireCaseFolderAccess($folderId,$user);
if(!isset($_FILES['file'])||!is_uploaded_file($_FILES['file']['tmp_name']??''))apiError(400,'Polycam-Datei fehlt.');
$file=$_FILES['file'];$name=trim((string)($file['name']??'Polycam-Aufmass'));$size=(int)($file['size']??0);$mime=(string)(mime_content_type((string)$file['tmp_name'])?:($file['type']??'application/octet-stream'));
if(!in_array($mime,['application/pdf','image/jpeg','image/png','image/webp','application/zip','application/x-zip-compressed'],true))apiError(415,'Zulässig sind Polycam-PDF, JPEG, PNG, WEBP oder ZIP.');
if($size<=0||$size>500*1024*1024)apiError(413,'Die Polycam-Datei darf höchstens 500 MB groß sein.');
$apiKey=trim(env('OPENAI_API_KEY',''));if($apiKey==='')apiError(503,'OpenAI API-Key ist nicht konfiguriert.');
function pcHttp(string $url,array $headers,mixed $postFields,int $timeout=300):array{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$postFields,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>$timeout]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);if($body===false||$error!=='')throw new RuntimeException('Verbindung zur Aufmaßauswertung fehlgeschlagen.');return['status'=>$status,'body'=>(string)$body];}
function pcOutputText(array $response):string{if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);$text='';foreach(($response['output']??[])as$item)foreach(($item['content']??[])as$part)if(($part['type']??'')==='output_text')$text.=(string)($part['text']??'');return trim($text);}
function pcAudit(array $user,string $folderId,string $sourceName,string $status,?array $result=null,?string $error=null):void{try{db()->exec("CREATE TABLE IF NOT EXISTS ai_activity_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NULL,user_email VARCHAR(255) NOT NULL,folder_id VARCHAR(190) NOT NULL,operation VARCHAR(80) NOT NULL,source_name VARCHAR(500) NULL,status VARCHAR(30) NOT NULL,result_json MEDIUMTEXT NULL,error_text MEDIUMTEXT NULL,created_at DATETIME NOT NULL,INDEX idx_ai_user(user_email),INDEX idx_ai_folder(folder_id),INDEX idx_ai_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$stmt=db()->prepare('INSERT INTO ai_activity_log(user_id,user_email,folder_id,operation,source_name,status,result_json,error_text,created_at) VALUES(:uid,:email,:folder,\'polycam_extract\',:source,:status,:result,:error,NOW())');$stmt->execute([':uid'=>(int)($user['id']??0)?:null,':email'=>(string)($user['email']??''),':folder'=>$folderId,':source'=>$sourceName,':status'=>$status,':result'=>$result?json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,':error'=>$error]);}catch(Throwable $ignored){}}
function pcTextPart(string $entry,string $bytes):array{return['type'=>'input_text','text'=>"Polycam-Datei innerhalb ZIP: {$entry}\n".substr($bytes,0,2000000)];}
try{
  $sourceParts=[];$temporary=[];
  if(in_array($mime,['application/zip','application/x-zip-compressed'],true)){
    if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP-Auswertung ist auf dem Server derzeit nicht verfügbar.');
    $zip=new ZipArchive();if($zip->open((string)$file['tmp_name'])!==true)throw new RuntimeException('Die Polycam-ZIP-Datei konnte nicht geöffnet werden.');
    $imageEntries=[];$pdfEntries=[];$textEntries=[];
    $textExt=['json'=>true,'csv'=>true,'txt'=>true,'obj'=>true,'mtl'=>true,'gltf'=>true,'ply'=>true,'xyz'=>true,'svg'=>true,'dxf'=>true];
    $imageExt=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    for($i=0;$i<$zip->numFiles;$i++){
      $entry=(string)$zip->getNameIndex($i);$stat=$zip->statIndex($i);$entrySize=(int)($stat['size']??0);
      if($entry===''||str_ends_with($entry,'/')||str_contains($entry,'../')||$entrySize<=0)continue;
      $ext=strtolower(pathinfo($entry,PATHINFO_EXTENSION));
      if($ext==='pdf'&&$entrySize<=20*1024*1024)$pdfEntries[]=$i;
      elseif(isset($imageExt[$ext])&&$entrySize<=12*1024*1024)$imageEntries[]=$i;
      elseif(isset($textExt[$ext])&&$entrySize<=6*1024*1024)$textEntries[]=$i;
    }
    $priority=function(int $index)use($zip):int{$entry=strtolower((string)$zip->getNameIndex($index));$score=0;if(str_contains($entry,'floorplan'))$score-=100;if(str_contains($entry,'report'))$score-=80;if(str_contains($entry,'measure'))$score-=70;if(str_contains($entry,'mesh_info'))$score-=60;if(str_contains($entry,'thumbnail'))$score-=50;if(str_contains($entry,'corrected_images'))$score-=40;return$score;};
    usort($textEntries,fn($a,$b)=>$priority($a)<=>$priority($b));usort($imageEntries,fn($a,$b)=>$priority($a)<=>$priority($b));
    foreach(array_slice($textEntries,0,6)as$i){$entry=(string)$zip->getNameIndex($i);$bytes=$zip->getFromIndex($i);if(is_string($bytes)&&$bytes!=='')$sourceParts[]=pcTextPart($entry,$bytes);}
    foreach(array_slice($pdfEntries,0,2)as$i){$entry=(string)$zip->getNameIndex($i);$bytes=$zip->getFromIndex($i);if(!is_string($bytes)||$bytes==='')continue;$tmp=tempnam(sys_get_temp_dir(),'polycam_');if($tmp===false)continue;file_put_contents($tmp,$bytes);$temporary[]=$tmp;$sourceParts[]=['type'=>'_upload','path'=>$tmp,'mime'=>'application/pdf','name'=>basename($entry)];}
    foreach(array_slice($imageEntries,0,6)as$i){$entry=(string)$zip->getNameIndex($i);$bytes=$zip->getFromIndex($i);if(!is_string($bytes)||$bytes==='')continue;$ext=strtolower(pathinfo($entry,PATHINFO_EXTENSION));$tmp=tempnam(sys_get_temp_dir(),'polycam_');if($tmp===false)continue;file_put_contents($tmp,$bytes);$temporary[]=$tmp;$sourceParts[]=['type'=>'_upload','path'=>$tmp,'mime'=>$imageExt[$ext],'name'=>basename($entry)];}
    $zip->close();
    if(!$sourceParts)throw new RuntimeException('Die Polycam-ZIP-Datei enthält keine auswertbaren Aufmaßdaten. Erwartet werden insbesondere Floorplan-/Report-Daten (JSON/CSV/PDF) oder Bilddateien.');
  }else{
    $sourceParts[]=['type'=>'_upload','path'=>(string)$file['tmp_name'],'mime'=>$mime,'name'=>$name];
  }
  $content=[['type'=>'input_text','text'=>'Quelldatei: '.$name."\nLies sämtliche belastbar erkennbaren Maße aus. Antworte ausschließlich als json-Objekt gemäß dem vorgegebenen Format."]];
  foreach($sourceParts as$part){
    if(($part['type']??'')!=='_upload'){$content[]=$part;continue;}
    $upload=pcHttp('https://api.openai.com/v1/files',['Authorization: Bearer '.$apiKey],['purpose'=>'user_data','file'=>new CURLFile($part['path'],$part['mime'],$part['name'])]);
    $uploadJson=json_decode($upload['body'],true);$openAiFileId=(string)($uploadJson['id']??'');
    if($upload['status']<200||$upload['status']>=300||$openAiFileId==='')throw new RuntimeException('Polycam-Datei konnte nicht für die Auswertung vorbereitet werden.');
    $content[]=str_starts_with($part['mime'],'image/')?['type'=>'input_image','file_id'=>$openAiFileId,'detail'=>'high']:['type'=>'input_file','file_id'=>$openAiFileId];
  }
  $instructions='Du liest ein mit Polycam erstelltes digitales Aufmaß aus. Extrahiere nur Maße, Räume, Bauteile und Flächen, die in der Quelle tatsächlich erkennbar sind. Nichts schätzen oder erfinden. Werte mit unsicherer Zuordnung kennzeichnen. Dezimalzahlen als Zahlen mit Punkt ausgeben. Antworte ausschließlich als json-Objekt mit summary, measurements und warnings. measurements ist eine Liste aus label, room, element, length_m, width_m, height_m, area_m2, volume_m3, count, unit, value, confidence und source_reference. Nicht zutreffende Zahlenfelder sind null. confidence ist sicher, pruefen oder unklar. Die spätere Berichtsformulierung lautet neutral „Das mit Polycam erstellte digitale Aufmaß wurde ausgewertet und der weiteren Schadenbearbeitung zugrunde gelegt.“ Verwende niemals die Formulierung „übermitteltes Polycam-Aufmaß“.';
  $payload=['model'=>env('OPENAI_MODEL','gpt-5.4-mini'),'instructions'=>$instructions,'input'=>[['role'=>'user','content'=>$content]],'text'=>['format'=>['type'=>'json_object']],'max_output_tokens'=>7000];
  $response=pcHttp('https://api.openai.com/v1/responses',['Content-Type: application/json','Authorization: Bearer '.$apiKey],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),600);
  $responseJson=json_decode($response['body'],true);if($response['status']<200||$response['status']>=300)throw new RuntimeException(trim((string)($responseJson['error']['message']??''))?:'Polycam-Aufmaß konnte nicht ausgewertet werden.');
  $result=json_decode(pcOutputText(is_array($responseJson)?$responseJson:[]),true,512,JSON_INVALID_UTF8_SUBSTITUTE);if(!is_array($result))throw new RuntimeException('Die Aufmaßauswertung war nicht vollständig lesbar.');
  $out=['ok'=>true,'source_name'=>$name,'wording'=>'Das mit Polycam erstellte digitale Aufmaß wurde ausgewertet und der weiteren Schadenbearbeitung zugrunde gelegt.','summary'=>(string)($result['summary']??''),'measurements'=>array_values(array_filter(is_array($result['measurements']??null)?$result['measurements']:[],'is_array')),'warnings'=>array_values(array_map('strval',is_array($result['warnings']??null)?$result['warnings']:[])),'review_required'=>true,'processed_by'=>(string)($user['full_name']??$user['email']??''),'processed_at'=>date(DATE_ATOM)];
  foreach($temporary as$tmp)@unlink($tmp);pcAudit($user,$folderId,$name,'review_required',$out);apiJson($out);
}catch(Throwable $e){foreach(($temporary??[])as$tmp)@unlink($tmp);pcAudit($user,$folderId,$name,'failed',null,$e->getMessage());apiError(500,$e->getMessage());}
