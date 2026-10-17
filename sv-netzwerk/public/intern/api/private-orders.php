<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/profile-routing.php';
require_once __DIR__ . '/private-order-classification.php';
commonHeaders();
$user = requireAuth();
$profile = svnetUserProfile($user);
$roots = [
    'christian' => getenv('MS_SHAREPOINT_CHRISTIAN_PRIVATE_PATH') ?: 'VS Schäden/Christian/Privatgutachten_NL Süd',
    'marc' => getenv('MS_SHAREPOINT_MARC_PRIVATE_PATH') ?: 'VS Schäden/Marc/Privatgutachten',
];
if (!isset($roots[$profile])) apiError(403, 'Für dieses Benutzerprofil sind keine Privataufträge freigegeben.');
$action = (string)($_GET['action'] ?? 'list');

require_once __DIR__ . '/private-order-storage.php';
require_once __DIR__ . '/private-order-inquiries.php';
$root=poItemByPath($roots[$profile]);
if (in_array($action, ['convert-inquiry','merge-inquiry'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    $id = trim((string)($input['id'] ?? ''));
    $result = $action === 'convert-inquiry'
        ? poConvertInquiry($id, (string)($input['name'] ?? ''), (string)$root['id'])
        : poMergeInquiry($id, trim((string)($input['target_id'] ?? '')), (string)$root['id']);
    apiJson(['ok'=>true] + $result);
}
if ($action==='list' || $action==='cases') {
    $top=poChildren((string)$root['id'],$roots[$profile]);$items=[];
    foreach($top as $item){if(!$item['folder']||poIsStandardFolder($item)||mb_strtolower($item['name'],'UTF-8')==='versicherungsaufträge')continue;if(poIsYearFolder($item)){foreach(poChildren($item['id'],$item['path']) as $candidate)if($candidate['folder']&&!poIsStandardFolder($candidate))$items[]=$candidate;}else $items[]=$item;}
    usort($items,fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));
    apiJson(['ok'=>true,'profile'=>$profile,'root'=>['name'=>$root['name']??basename($roots[$profile]),'path'=>$roots[$profile]],'items'=>$items,'standard_folders'=>poStandardFolders()]);
}
if ($action==='case') {
    $id=trim((string)($_GET['id']??''));if($id==='')apiError(400,'Auftrags-ID fehlt.');$item=poValidateScope($id,(string)$root['id']);if(empty($item['folder']))apiError(403,'Ungültiger Auftragsordner.');$path=ltrim((string)($item['name']??''),'/');$children=poChildren($id,$path);foreach($children as &$child){if($child['folder'])$child['children']=poChildren($child['id'],$child['path']);}unset($child);$item=['id'=>(string)$item['id'],'name'=>(string)($item['name']??''),'path'=>$path,'folder'=>true,'children'=>$children];apiJson(['ok'=>true,'item'=>$item]);
}
if ($action==='folder') {
    $id=trim((string)($_GET['id']??''));if($id==='')apiError(400,'Ordner-ID fehlt.');
    $item=poValidateScope($id,(string)$root['id']);if(empty($item['folder']))apiError(403,'Ungültiger Ordner.');
    apiJson(['ok'=>true,'item'=>['id'=>$id,'name'=>(string)$item['name'],'folder'=>true,'children'=>poChildren($id)]]);
}
if ($action==='organize-case' && $_SERVER['REQUEST_METHOD']==='POST') {
    $input=json_decode((string)file_get_contents('php://input'),true);$id=trim((string)($input['id']??''));
    if($id==='')apiError(400,'Auftrags-ID fehlt.');
    $order=poValidateScope($id,(string)$root['id']);
    if(empty($order['folder'])||$id===(string)$root['id']||poIsYearFolder($order)||poIsStandardFolder($order))apiError(403,'Ungültiger Auftragsordner.');
    $parent=(string)($order['parentReference']['id']??'');
    if($parent!==(string)$root['id']){
        $year=poRequest('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($parent).'?$select=id,name,folder,parentReference');
        if(!poIsYearFolder($year)||(string)($year['parentReference']['id']??'')!==(string)$root['id'])apiError(403,'Bitte einen Auftrag auswählen.');
    }
    $children=poChildren($id);$targets=[];foreach($children as $child)if(poIsStandardFolder($child))$targets[mb_strtolower($child['name'],'UTF-8')]=$child['id'];
    $moved=0;$categories=[];
    foreach($children as $entry){
        if(poIsStandardFolder($entry))continue;
        $category=poCategory($entry);$key=mb_strtolower($category,'UTF-8');
        if(!isset($targets[$key])){
            $created=poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($id).'/children',['name'=>$category,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);$targets[$key]=(string)$created['id'];
        }
        poMoveFile($entry['id'],$targets[$key]);$moved++;$categories[$category]=($categories[$category]??0)+1;
    }
    apiJson(['ok'=>true,'moved'=>$moved,'categories'=>$categories]);
}
if ($action==='organize-lite' && $_SERVER['REQUEST_METHOD']==='POST') {
    $top=poChildren((string)$root['id'],$roots[$profile]);$orders=[];
    foreach($top as $item){if(!$item['folder']||poIsStandardFolder($item)||mb_strtolower($item['name'],'UTF-8')==='versicherungsaufträge')continue;if(poIsYearFolder($item)){foreach(poChildren($item['id'],$item['path']) as $candidate)if($candidate['folder']&&!poIsStandardFolder($candidate))$orders[]=$candidate;}else $orders[]=$item;}
    $moved=0;$created=0;
    foreach($orders as $order){$children=poChildren($order['id'],$order['path']);$folders=[];foreach($children as $child)if($child['folder'])$folders[mb_strtolower($child['name'],'UTF-8')]=(string)$child['id'];foreach(poStandardFolders() as $folderName){$key=mb_strtolower($folderName,'UTF-8');if(isset($folders[$key]))continue;$new=poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($order['id']).'/children',['name'=>$folderName,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);if(!empty($new['id'])){$folders[$key]=(string)$new['id'];$created++;}}foreach($children as $file){if($file['folder'])continue;$target=$folders[mb_strtolower(poCategory($file),'UTF-8')]??'';if($target==='')continue;poMoveFile($file['id'],$target);$moved++;}}
    apiJson(['ok'=>true,'cases'=>count($orders),'moved'=>$moved,'created'=>$created]);
}
if ($action==='ensure' && $_SERVER['REQUEST_METHOD']==='POST') {
    $input=json_decode((string)file_get_contents('php://input'),true);$id=trim((string)($input['id']??''));
    $tree=poTree((string)$root['id']);$item=poFind($tree,$id);
    if(!$item||empty($item['folder'])||preg_match('/^20\\d{2}$/',(string)($item['name']??'')))apiError(403,'Ungültiger Auftragsordner.');
    poEnsureFolders($item);apiJson(['ok'=>true]);
}
if ($action==='organize' && $_SERVER['REQUEST_METHOD']==='POST') {
    $tree=poTree((string)$root['id']);$orders=poOrders($tree);$moved=0;$limit=max(1,min(40,(int)($_GET['limit']??20)));
    foreach($orders as $order){poEnsureFolders($order);}
    if($orders)$orders=poOrders(poTree((string)$root['id']));
    foreach($orders as $order){$targets=[];foreach((array)($order['children']??[])as$child)if(poIsStandardFolder($child))$targets[mb_strtolower((string)$child['name'],'UTF-8')]=(string)$child['id'];$files=[];poLooseFiles((array)($order['children']??[]),false,$files);foreach($files as$file){$category=poCategory($file);$target=$targets[mb_strtolower($category,'UTF-8')]??'';if($target==='')continue;poMoveFile((string)$file['id'],$target);$moved++;if($moved>=$limit)break 2;}}
    apiJson(['ok'=>true,'moved'=>$moved,'complete'=>$moved===0]);
}
if ($action==='file') { $id=trim((string)($_GET['id']??'')); if($id==='')apiError(400,'Datei-ID fehlt.'); $item=poValidateScope($id,(string)$root['id']); if(isset($item['folder']))apiError(403,'Diese Datei gehört nicht zum eigenen Privatauftragsbereich.'); $r=poRequest('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($id).'/content',true); header('Content-Type: '.($r['content_type']?:'application/octet-stream')); header('Content-Disposition: inline; filename="'.str_replace('"','',basename((string)$item['name'])).'"'); echo $r['body']; exit; }
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') { $input=json_decode((string)file_get_contents('php://input'),true);$name=trim((string)($input['name']??''));$parent=trim((string)($input['parent_id']??$root['id']));if($name===''||mb_strlen($name)>120||preg_match('/[\\\/\x00-\x1F]/u',$name))apiError(400,'Bitte einen gültigen Auftragsnamen eingeben.');if($parent!==(string)$root['id']){$tree=poTree((string)$root['id']);if(!poFind($tree,$parent))apiError(403,'Der Zielordner gehört nicht zum eigenen Privatauftragsbereich.');}$created=poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($parent).'/children',['name'=>$name,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'rename']);foreach(['Auftrag','Fotos','Gutachten','Angebot','Rechnungen','E-Mails','Bilder','Dateien','Sonstige Unterlagen','Eingang KI'] as $sub)poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode((string)($created['id']??'')).'/children',['name'=>$sub,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);apiJson(['ok'=>true,'item'=>$created]);}
if ($action==='upload' && $_SERVER['REQUEST_METHOD']==='POST') { $parent=trim((string)($_POST['folder_id']??''));if($parent==='')apiError(400,'Zielordner fehlt.');$folder=poValidateScope($parent,(string)$root['id']);if(!isset($folder['folder']))apiError(403,'Der Zielordner gehört nicht zum eigenen Privatauftragsbereich.');$file=$_FILES['file']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)apiError(400,'Keine gültige Datei empfangen.');if((int)($file['size']??0)>4194304)apiError(413,'Dateien bis 4 MB können direkt abgelegt werden.');$name=basename((string)($file['name']??'Datei'));if($name===''||preg_match('/[\\\/\x00-\x1F]/u',$name))apiError(400,'Ungültiger Dateiname.');$bytes=file_get_contents((string)$file['tmp_name']);if(!is_string($bytes))apiError(400,'Datei konnte nicht gelesen werden.');$mime=(string)($file['type']??'application/octet-stream');$uploaded=poRequestUpload('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($parent).':/'.rawurlencode($name).':/content',$name,$bytes,$mime);$children=poChildren($parent,(string)($folder['name']??''));$hasStandard=false;$targets=[];foreach($children as $child)if($child['folder']&&poIsStandardFolder($child)){$hasStandard=true;$targets[mb_strtolower($child['name'],'UTF-8')]=(string)$child['id'];}if($hasStandard){$category=poCategory(['name'=>$name,'path'=>$name]);$target=$targets[mb_strtolower($category,'UTF-8')]??'';if($target!=='')poMoveFile((string)($uploaded['id']??''),$target);}apiJson(['ok'=>true,'sorted'=>$hasStandard,'category'=>$hasStandard?poCategory(['name'=>$name,'path'=>$name]):null,'item'=>['id'=>(string)($uploaded['id']??''),'name'=>(string)($uploaded['name']??$name)]]);}
apiError(404,'Unbekannte Aktion für Privataufträge.');

