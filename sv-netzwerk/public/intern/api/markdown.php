<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ionos-storage.php';
require_once __DIR__ . '/case-search.php';
commonHeaders();
$user = requireAuth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') apiError(405, 'POST erforderlich.');

$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) apiError(400, 'Keine gültige Datei empfangen.');
$size = (int)($file['size'] ?? 0);
if ($size <= 0 || $size > 300 * 1024 * 1024) apiError(413, 'Datei darf höchstens 300 MB groß sein.');
$name = basename((string)($file['name'] ?? ''));
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf','docx','pptx','xlsx','csv'], true)) apiError(415, 'Nur PDF, DOCX, PPTX, XLSX und CSV werden unterstützt.');
$tmp = (string)($file['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) apiError(400, 'Upload konnte nicht bestätigt werden.');

try {
    $converted = mdConvert($tmp, $ext, $name);
    if ($converted['markdown'] === '') apiError(422, 'Die Datei enthält keinen auslesbaren Inhalt.');
    $caseNumber = trim((string)($_POST['case_number'] ?? ''));
    $saved = $caseNumber !== '' ? mdSaveToCase($user, $caseNumber, pathinfo($name, PATHINFO_FILENAME).'.md', $converted['markdown']) : null;
    apiJson(['ok'=>true, 'markdown'=>$converted['markdown'], 'filename'=>pathinfo($name, PATHINFO_FILENAME).'.md', 'method'=>$converted['method'], 'case_number'=>$caseNumber, 'saved'=>$saved]);
} catch (Throwable $e) {
    error_log('[markdown] '.$e->getMessage());
    $message = $e->getMessage();
    if (str_contains($message, 'Schadennummer') || str_contains($message, 'Schadenfall') || str_contains($message, 'Fallakten') || str_contains($message, 'Fallablage')) {
        apiError(422, $message);
    }
    apiError(422, 'Die Datei konnte nicht in Markdown umgewandelt werden.');
}

function mdSaveToCase(array $user, string $caseNumber, string $sourceName, string $markdown): array
{
    $key = caseNumberKey($caseNumber);
    if ($key === '') throw new RuntimeException('Die Schadennummer ist ungültig.');
    $matches = [];
    foreach (searchCaseFolderIndex($user, $caseNumber, 1000) as $row) {
        $metaNumber = (string)($row['meta']['schaden_nr'] ?? '');
        if ($metaNumber !== '' && caseNumberKey($metaNumber) === $key) $matches[(string)$row['id']] = $row;
    }
    if (count($matches) === 0) throw new RuntimeException('Kein zugänglicher Schadenfall mit dieser Schadennummer gefunden.');
    if (count($matches) > 1) throw new RuntimeException('Mehrere Fallakten tragen diese Schadennummer. Bitte zuerst die Fallzuordnung klären.');
    $row = array_values($matches)[0];
    $folderId = trim((string)($row['id'] ?? ''));
    requireCaseFolderAccess($folderId, $user);
    if (!ionosStorageEnabled()) throw new RuntimeException('Die Fallablage ist derzeit nicht verfügbar.');
    $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', pathinfo($sourceName, PATHINFO_FILENAME)) ?? 'Unterlage';
    $base = trim($base, '-_.') ?: 'Unterlage';
    $name = 'Markdown_'.gmdate('Ymd-His').'_'.mb_substr($base, 0, 120, 'UTF-8').'.md';
    $saved = ionosWrite(['name'=>$name, 'mimeType'=>'text/markdown', 'parents'=>[$folderId]], $markdown);
    return ['case_number'=>(string)($row['meta']['schaden_nr'] ?? $caseNumber), 'folder_id'=>$folderId, 'file_id'=>(string)($saved['id'] ?? ''), 'name'=>$name, 'webViewLink'=>'/intern/api/case-file-browser.php?action=file&folder_id='.rawurlencode($folderId).'&file_id='.rawurlencode((string)($saved['id'] ?? ''))];
}

function mdConvert(string $path, string $ext, string $name): array
{
    return match ($ext) {
        'csv' => ['markdown'=>mdCsv((string)file_get_contents($path)), 'method'=>'CSV-Import'],
        'pdf' => ['markdown'=>mdPdf($path), 'method'=>'PDF'],
        'docx' => ['markdown'=>mdDocx($path), 'method'=>'Word (DOCX)'],
        'pptx' => ['markdown'=>mdPptx($path), 'method'=>'PowerPoint (PPTX)'],
        'xlsx' => ['markdown'=>mdXlsx($path), 'method'=>'Excel (XLSX)'],
        default => throw new RuntimeException('Format nicht unterstützt'),
    };
}

function mdCsv(string $bytes): string
{
    $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;
    $lines = preg_split('/\r\n|\n|\r/', trim($bytes)) ?: [];
    if (!$lines) return '';
    $sample = (string)($lines[0] ?? '');
    $delim = substr_count($sample, ';') >= max(substr_count($sample, ','), substr_count($sample, "\t")) ? ';' : (substr_count($sample, "\t") > substr_count($sample, ',') ? "\t" : ',');
    $rows = array_map(static fn(string $line): array => str_getcsv($line, $delim), $lines);
    $width = max(array_map('count', $rows));
    $pad = static fn(array $row): array => array_pad(array_map(static fn($v): string => str_replace(["\r","\n","|"], [' ',' ','\\|'], trim((string)$v)), $row), $width, '');
    $rows = array_map($pad, $rows);
    $out = ['| '.implode(' | ', $rows[0]).' |', '| '.implode(' | ', array_fill(0, $width, '---')).' |'];
    foreach (array_slice($rows, 1) as $row) $out[] = '| '.implode(' | ', $row).' |';
    return implode("\n", $out)."\n";
}

function mdPdf(string $path): string
{
    $text = mdCommand('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
    if (trim($text) !== '') return trim($text)."\n";
    $pages = (int)trim(mdCommand('pdfinfo '.escapeshellarg($path).' 2>/dev/null | awk \'/Pages:/ {print $2}\''));
    if ($pages <= 0) return '';
    $dir = sys_get_temp_dir().'/sv-md-'.bin2hex(random_bytes(5)); @mkdir($dir, 0700, true);
    try { mdCommand('pdftoppm -f 1 -l '.min($pages, 30).' -jpeg -r 150 '.escapeshellarg($path).' '.escapeshellarg($dir.'/page').' 2>/dev/null'); $out=[]; foreach (glob($dir.'/page-*.jpg') ?: [] as $image) { $out[] = trim(mdCommand('tesseract '.escapeshellarg($image).' stdout -l deu+eng 2>/dev/null')); } return trim(implode("\n\n", array_filter($out)))."\n"; } finally { foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
}

function mdDocx(string $path): string
{
    $xml = mdZipXml($path, 'word/document.xml'); if ($xml === '') return '';
    $dom = new DOMDocument(); @$dom->loadXML($xml); $out=[];
    foreach ($dom->getElementsByTagName('p') as $p) { $text=trim(preg_replace('/\s+/u',' ', $p->textContent) ?? ''); if ($text !== '') $out[]=$text; }
    return implode("\n\n", $out)."\n";
}

function mdPptx(string $path): string
{
    $dir = mdUnzip($path); $slides=[]; try { foreach (glob($dir.'/ppt/slides/slide*.xml') ?: [] as $file) { $dom=new DOMDocument(); @$dom->load($file); $text=trim(preg_replace('/\s+/u',' ', $dom->textContent) ?? ''); if ($text!=='') $slides[]=$text; } } finally { mdRm($dir); } return $slides ? implode("\n\n", array_map(static fn($s,$i)=>'## Folie '.($i+1)."\n\n".$s, $slides, array_keys($slides)))."\n" : '';
}

function mdXlsx(string $path): string
{
    $dir = mdUnzip($path); try { $shared=[]; $xml=$dir.'/xl/sharedStrings.xml'; if (is_file($xml)) { $dom=new DOMDocument(); @$dom->load($xml); foreach ($dom->getElementsByTagName('si') as $si) $shared[] = trim(preg_replace('/\s+/u',' ', $si->textContent) ?? ''); }
        $out=[]; foreach (glob($dir.'/xl/worksheets/sheet*.xml') ?: [] as $sheet) { $dom=new DOMDocument(); @$dom->load($sheet); $rows=[]; foreach ($dom->getElementsByTagName('row') as $row) { $cells=[]; foreach ($row->getElementsByTagName('c') as $cell) { $v=$cell->getElementsByTagName('v')->item(0)?->textContent ?? ''; if ($cell->getAttribute('t')==='s') $v=$shared[(int)$v]??$v; $cells[]=(string)$v; } if ($cells) $rows[]=$cells; } if ($rows) { $width=max(array_map('count',$rows)); $out[]='## '.basename($sheet, '.xml'); $out[]='| '.implode(' | ', array_pad($rows[0],$width,'')).' |'; $out[]='| '.implode(' | ',array_fill(0,$width,'---')).' |'; foreach(array_slice($rows,1) as $r)$out[]='| '.implode(' | ',array_pad($r,$width,'')).' |'; } } return implode("\n",$out)."\n"; } finally { mdRm($dir); }
}

function mdZipXml(string $path, string $entry): string { $zip=new ZipArchive(); if ($zip->open($path)!==true) throw new RuntimeException('ZIP konnte nicht geöffnet werden'); $data=(string)$zip->getFromName($entry); $zip->close(); return $data; }
function mdUnzip(string $path): string { $dir=sys_get_temp_dir().'/sv-md-'.bin2hex(random_bytes(6)); @mkdir($dir,0700,true); $zip=new ZipArchive(); if($zip->open($path)!==true||!$zip->extractTo($dir)){ $zip->close(); mdRm($dir); throw new RuntimeException('Office-Datei konnte nicht entpackt werden'); } $zip->close(); return $dir; }
function mdRm(string $dir): void { foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname()); @rmdir($dir); }
function mdCommand(string $command): string { if(!function_exists('shell_exec')) return ''; return (string)@shell_exec($command); }

