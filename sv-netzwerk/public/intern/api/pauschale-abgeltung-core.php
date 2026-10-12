<?php
declare(strict_types=1);

function paTemplateBytes(): string {
    $bytes=file_get_contents(dirname(__DIR__).'/forms/pauschale-abgeltung.pdf');
    if(!is_string($bytes)||!str_starts_with($bytes,'%PDF-'))throw new RuntimeException('Originalformular für die pauschale Abgeltung fehlt.');
    return $bytes;
}

/** Prepare an unsigned original form, without inventing a settlement or payment. */
function paPrepareForm(string $folder,array $user): array {
    requireCaseFolderAccess($folder,$user);
    $bytes=paTemplateBytes();
    $children=ionosList(['q'=>"'".str_replace("'","\\'",$folder)."' in parents and trashed=false",'pageSize'=>1000])['files'];
    $parent='';$number='';
    foreach($children as$file){
        if(($file['mimeType']??'')==='application/vnd.google-apps.folder'&&str_starts_with((string)$file['name'],'08_'))$parent=(string)$file['id'];
        if(($file['name']??'')==='00_Falldaten.json'){$meta=json_decode(ionosBytes((string)$file['id']),true,512,JSON_THROW_ON_ERROR);$number=trim((string)($meta['schaden_nr']??''));}
    }
    if($number==='')throw new RuntimeException('Schadennummer der aktiven Fallakte fehlt.');
    if($parent==='')$parent=(string)ionosWrite(['name'=>'08_Abschluss','mimeType'=>'application/vnd.google-apps.folder','parents'=>[$folder]])['id'];
    $prefix=preg_replace('/[^\p{L}\p{N}._-]/u','_',$number);
    $name=$prefix.'_Pauschale-Abgeltung_Formular-Entwurf_'.date('Y-m-d_His').'.pdf';
    $file=ionosWrite(['name'=>$name,'mimeType'=>'application/pdf','parents'=>[$parent]],$bytes);
    if(!hash_equals(hash('sha256',$bytes),hash('sha256',ionosBytes((string)$file['id']))))throw new RuntimeException('Gespeichertes Originalformular konnte nicht verifiziert werden.');
    return ['id'=>$file['id'],'name'=>$name,'type'=>'pauschale_abgeltung','template_used'=>'Entschädigungsvereinbarrung.pdf',
        'webViewLink'=>'/intern/api/case-file-browser.php?action=file&folder_id='.rawurlencode($folder).'&file_id='.rawurlencode((string)$file['id']),
        'status'=>'unsigned_form_draft'];
}
