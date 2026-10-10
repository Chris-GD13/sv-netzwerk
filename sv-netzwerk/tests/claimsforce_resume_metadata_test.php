<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/case-search.php';
$calls=[];
$rows=[['id'=>'greif','meta'=>['schaden_nr'=>'26-085905-9']],['id'=>'other','meta'=>['schaden_nr'=>'26-076821-6']]];
$fresh=['schaden_nr'=>'26-085905-9','claimsforce_file_versions'=>['file-1'],'claimsforce_message_versions'=>['mail-1'],'claimsforce_sync_signature'=>'complete'];
$hydrated=caseSearchHydrateExactRows($rows,'26-085905-9',function($id)use(&$calls,$fresh){$calls[]=$id;return$fresh;});
if($calls!==['greif']||$hydrated[0]['meta']!==$fresh||$hydrated[1]!==$rows[1])throw new RuntimeException('Der genaue Schaden muss seinen aktuellen vollständigen Übernahmestand liefern.');
$failed=false;try{caseSearchHydrateExactRows($rows,'26-085905-9',fn()=>[]);}catch(RuntimeException){$failed=true;}
if(!$failed)throw new RuntimeException('Ein nicht lesbarer Übernahmestand darf nicht als leerer Bestand weitergegeben werden.');
echo "Aktuelle Datei-/Mailversionen und sichere Fehlerbehandlung bei der Wiederaufnahme geprüft.\n";
