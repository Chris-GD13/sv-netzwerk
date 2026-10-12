<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/pauschale-abgeltung-core.php';
$written=[];$stored=[];$allowed=true;
function requireCaseFolderAccess($folder,$user){if(!$GLOBALS['allowed']||$folder!=='case')throw new RuntimeException('Fremder Fall',403);}
function ionosList($query){return ['files'=>[['id'=>'meta','name'=>'00_Falldaten.json'],['id'=>'end','name'=>'08_Abschluss','mimeType'=>'application/vnd.google-apps.folder']]];}
function ionosBytes($id){return $id==='meta'?'{"schaden_nr":"24-084591-5"}':$GLOBALS['stored'][$id];}
function ionosWrite($meta,$bytes=null){$id='form-'.count($GLOBALS['written']);$GLOBALS['written'][]=$meta;$GLOBALS['stored'][$id]=$bytes;return ['id'=>$id];}
$result=paPrepareForm('case',[]);
if($result['type']!=='pauschale_abgeltung'||$result['status']!=='unsigned_form_draft')throw new RuntimeException('Kein eindeutig ununterschriebener Entwurf.');
if($written[0]['parents']!==['end']||!str_starts_with($written[0]['name'],'24-084591-5_Pauschale-Abgeltung_Formular-Entwurf_'))throw new RuntimeException('Falsche Fallablage.');
if($stored[$result['id']]!==paTemplateBytes())throw new RuntimeException('Originalformular verändert.');
$allowed=false;$before=count($written);
try{paPrepareForm('case',[]);throw new RuntimeException('Fremder Fall akzeptiert');}catch(RuntimeException $error){if($error->getCode()!==403)throw$error;}
if(count($written)!==$before)throw new RuntimeException('Vor Berechtigungsprüfung geschrieben.');
$ui=file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
foreach(['value="pauschale_abgeltung"',"key==='pauschale_abgeltung'",'/intern/api/pauschale-abgeltung.php','Pauschale-Abgeltung|Dokumentenindex']as$needle)if(!str_contains($ui,$needle))throw new RuntimeException('Fehlende UI-Anbindung '.$needle);
echo "Abgeltung: Originalformular, Entwurfsstatus, Fallablage und Fremdzugriff geprüft.\n";
