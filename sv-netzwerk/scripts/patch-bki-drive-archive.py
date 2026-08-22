from pathlib import Path

root = Path(__file__).resolve().parents[1]
api_path = root / 'public/intern/api/bki-calculator.php'
page_path = root / 'src/pages/intern/kalkulation/index.astro'

api = api_path.read_text(encoding='utf-8')
original_api = api

# Fuer fallbezogene Kalkulationen muss die bestehende Google-Drive-Verbindung
# nicht nur lesen, sondern auch in den bereits bekannten Fallordner schreiben koennen.
api = api.replace(
    "'scope'=>'https://www.googleapis.com/auth/drive.readonly'",
    "'scope'=>'https://www.googleapis.com/auth/drive'",
)

helpers = r'''
function bkSafeName(string $value):string{
  $value=trim($value);
  $value=preg_replace('/[^A-Za-z0-9ÄÖÜäöüß._-]+/u','-',$value)??'';
  return trim($value,'-_.')?:'Fall';
}
function bkEuro(float $value):string{return number_format($value,2,',','.').' €';}
function bkDriveUploadFile(string $folderId,string $name,string $mime,string $bytes):array{
  if($folderId==='')throw new RuntimeException('Fallordner fehlt.');
  $boundary='svnetbki'.bin2hex(random_bytes(8));
  $meta=['name'=>$name,'mimeType'=>$mime,'parents'=>[$folderId]];
  $body='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
    .json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ."\r\n--".$boundary."\r\nContent-Type: ".$mime."; charset=UTF-8\r\n\r\n"
    .$bytes."\r\n--".$boundary."--";
  $r=bkHttp(
    'POST',
    'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,webViewLink',
    ['Authorization: Bearer '.bkGoogleToken(),'Content-Type: multipart/related; boundary='.$boundary],
    $body,
    180
  );
  if($r['status']<200||$r['status']>=300)throw new RuntimeException('Kalkulationsdatei konnte nicht in den Fallordner geschrieben werden.');
  $j=json_decode($r['body'],true);
  if(!is_array($j)||empty($j['id']))throw new RuntimeException('Google Drive hat keine Datei-ID fuer die Kalkulation geliefert.');
  return $j;
}
function bkArchiveHtml(array $case,array $items,array $totals,string $location,string $note,int $calcId):string{
  $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $caseNo=trim((string)($case['schaden_nr']??''));
  $damage=trim((string)($case['schadenart']??''));
  $object=trim((string)($case['vn_objekt']??''));
  $created=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');
  $rows='';
  foreach($items as $index=>$item){
    if(!is_array($item))continue;
    $qty=(float)($item['quantity']??0);
    $unitPrice=(float)($item['unit_price']??0);
    $factor=(float)($item['regional_factor']??1);
    if($factor<=0)$factor=1;
    $sum=$qty*$unitPrice*$factor;
    $source=trim((string)($item['source_name']??''));
    $page=trim((string)($item['source_page']??''));
    if($page!=='')$source.=($source!==''?' · ':'').'Seite '.$page;
    $rows.='<tr>'
      .'<td>'.($index+1).'</td>'
      .'<td>'.$h($item['position_code']??'').'</td>'
      .'<td>'.$h($item['description']??'').'</td>'
      .'<td class="num">'.$h(number_format($qty,2,',','.')).'</td>'
      .'<td>'.$h($item['unit']??'').'</td>'
      .'<td class="num">'.$h(bkEuro($unitPrice)).'</td>'
      .'<td class="num">'.$h(number_format($factor,3,',','.')).'</td>'
      .'<td class="num">'.$h(bkEuro($sum)).'</td>'
      .'<td>'.$h($source).'</td>'
      .'</tr>';
  }
  $net=(float)($totals['net']??0);
  $vat=(float)($totals['vat']??19);
  $tax=(float)($totals['tax']??0);
  $gross=(float)($totals['gross']??0);
  $title='BKI-Kalkulation'.($caseNo!==''?' – Schaden '.$caseNo:'');
  return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    .'<title>'.$h($title).'</title><style>body{font-family:Arial,sans-serif;color:#17324a;margin:28px;font-size:13px}h1{margin:0 0 6px}p{line-height:1.45}.meta{background:#f3f6f8;padding:12px;border-radius:8px;margin:14px 0}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border:1px solid #d7e0e7;padding:7px;vertical-align:top}th{background:#eef3f6;text-align:left}.num{text-align:right;white-space:nowrap}.totals{margin-left:auto;width:320px;margin-top:16px}.totals td:first-child{font-weight:bold}.gross{font-size:15px;font-weight:bold}.note{margin-top:18px;padding:12px;border-left:4px solid #ff970f;background:#fff8ed}.source{margin-top:18px;font-size:11px;color:#65788a}@media print{body{margin:12mm}.source{page-break-inside:avoid}}</style></head><body>'
    .'<h1>'.$h($title).'</h1><p>Erstellt am '.$h($created).' · Kalkulations-ID '.$calcId.'</p>'
    .'<div class="meta"><strong>Schaden-Nr.:</strong> '.$h($caseNo?:'—').'<br><strong>Schadenart:</strong> '.$h($damage?:'—').'<br><strong>VN / Objekt:</strong> '.$h($object?:'—').'<br><strong>Ort / Region:</strong> '.$h($location?:'—').'</div>'
    .'<table><thead><tr><th>Pos.</th><th>BKI-Pos.</th><th>Leistung</th><th>Menge</th><th>Einheit</th><th>EP netto</th><th>Reg.-Faktor</th><th>Gesamt netto</th><th>Quelle</th></tr></thead><tbody>'.$rows.'</tbody></table>'
    .'<table class="totals"><tr><td>Netto</td><td class="num">'.$h(bkEuro($net)).'</td></tr><tr><td>USt. '.$h(number_format($vat,1,',','.')).' %</td><td class="num">'.$h(bkEuro($tax)).'</td></tr><tr class="gross"><td>Brutto</td><td class="num">'.$h(bkEuro($gross)).'</td></tr></table>'
    .($note!==''?'<div class="note"><strong>Hinweis / Abgeltungsvermerk</strong><br>'.$h($note).'</div>':'')
    .'<p class="source">Kalkulationsgrundlage: BKI Baukosten Positionen Altbau 2026 / BKI Baukosten Gebäude Altbau 2026. Die in der Aufstellung verwendeten Werte sind entsprechend der gespeicherten Auswahl dokumentiert.</p>'
    .'</body></html>';
}
'''

if 'function bkDriveUploadFile(' not in api:
    marker = '\nbkSchema();'
    if marker not in api:
        raise SystemExit('Einfügepunkt bkSchema() nicht gefunden')
    api = api.replace(marker, '\n' + helpers.strip() + marker, 1)

old_response = "apiJson(['ok'=>true,'id'=>(int)db()->lastInsertId(),'folder_id'=>$folder]);"
new_response = r'''$calcId=(int)db()->lastInsertId();$driveFile=null;
    if($folder!==''){
      try{
        $caseNo=trim((string)($case['schaden_nr']??''));
        $stamp=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d_Hi');
        $fileName='BKI-Kalkulation_'.bkSafeName($caseNo!==''?$caseNo:'Fall').'_'.$stamp.'.html';
        $html=bkArchiveHtml($case,$items,$tot,trim((string)($in['location']??'')),trim((string)($in['note']??'')),$calcId);
        $driveFile=bkDriveUploadFile($folder,$fileName,'text/html',$html);
      }catch(Throwable $archiveError){
        try{$del=db()->prepare('DELETE FROM bki_calculations WHERE id=:id');$del->execute([':id'=>$calcId]);}catch(Throwable){}
        throw new RuntimeException('Kalkulation konnte nicht in der Fallakte archiviert werden: '.$archiveError->getMessage());
      }
    }
    apiJson(['ok'=>true,'id'=>$calcId,'folder_id'=>$folder,'drive_file'=>$driveFile]);'''

if old_response in api:
    api = api.replace(old_response, new_response, 1)
elif "'drive_file'=>$driveFile" not in api:
    raise SystemExit('Speicherantwort der BKI-Kalkulation nicht gefunden')

if api != original_api:
    api_path.write_text(api, encoding='utf-8')
    print('BKI-Fallaktenarchivierung in API aktiviert.')
else:
    print('BKI-Fallaktenarchivierung in API bereits aktiv.')

page = page_path.read_text(encoding='utf-8')
original_page = page
old_ui = "$('bk-save-state').textContent=`Gespeichert · Kalkulation ${d.id}`;loadHistory()"
new_ui = "if(d.drive_file?.webViewLink){$('bk-save-state').innerHTML=`Gespeichert · Kalkulation ${d.id} · <a href=\"${esc(d.drive_file.webViewLink)}\" target=\"_blank\" rel=\"noreferrer\">in Fallakte archiviert</a>`}else{$('bk-save-state').textContent=`Gespeichert · Kalkulation ${d.id}${active?.folder_id?' · in Fallakte archiviert':''}`}loadHistory()"
if old_ui in page:
    page = page.replace(old_ui, new_ui, 1)
elif 'in Fallakte archiviert' not in page:
    raise SystemExit('Speicherrückmeldung der Kalkulationsseite nicht gefunden')

if page != original_page:
    page_path.write_text(page, encoding='utf-8')
    print('BKI-Archivlink in Oberfläche aktiviert.')
else:
    print('BKI-Archivlink in Oberfläche bereits aktiv.')
