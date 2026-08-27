<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/case-upload-ignore.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'] ?? '', ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
if (empty($_FILES['file'])) apiError(400, 'Keine Datei hochgeladen.');

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) apiError(400, 'Datei konnte nicht hochgeladen werden.');
if ((int)($file['size'] ?? 0) <= 0) apiError(400, 'Datei ist leer.');
if ((int)$file['size'] > 40 * 1024 * 1024) apiError(413, 'Datei ist größer als 40 MB.');

$name = basename((string)($file['name'] ?? 'Unterlage'));
$mime = mime_content_type((string)$file['tmp_name']) ?: (string)($file['type'] ?? 'application/octet-stream');
$bytes = file_get_contents((string)$file['tmp_name']);
if ($bytes === false) apiError(400, 'Datei konnte nicht gelesen werden.');
$excludedReason = caseUploadExcludedAsset($name, $mime, $bytes);
if ($excludedReason !== null) {
    apiJson(['ok'=>true,'excluded'=>true,'file_name'=>$name,'reason'=>$excludedReason,'fields'=>[]]);
}

$apiKey = trim(env('OPENAI_API_KEY', ''));
if ($apiKey === '') apiError(503, 'OpenAI API-Key ist nicht konfiguriert.');
$base64 = base64_encode($bytes);

$system = <<<'PROMPT'
Du analysierst Unterlagen zu deutschen Versicherungs-Schadenfällen und extrahierst ausschließlich eindeutig erkennbare Falldaten. Nichts erfinden. Unklare oder nicht vorhandene Werte als leeren String zurückgeben. Telefonnummern und E-Mail-Adressen exakt übernehmen. Schaden-Nr. und Versicherungsschein-Nr. nicht verwechseln.

Bestimme zusätzlich den Versicherer und die Fallart. Zulässige Werte für fallart sind ausschließlich "SV", "SV-GF" oder "Andere Versicherer".
- "SV-GF" nur bei Sparkassenversicherung und erkennbarem GF-, Großschaden- oder Groß-TF-Bezug beziehungsweise entsprechenden GF-Berichtsformularen.
- "SV" bei Sparkassenversicherung ohne belegten GF-/Großschadenbezug.
- "Andere Versicherer" bei allen anderen Versicherern.
- Ist die Einordnung nicht belastbar, fallart leer lassen und die Gründe in fallart_hinweis nennen.

Antworte ausschließlich als JSON mit genau diesen Feldern:
{"schaden_nr":"","versicherungsschein_nr":"","vn_objekt":"","strasse":"","plz":"","ort":"","schaden_strasse":"","schaden_plz":"","schaden_ort":"","telefon":"","mobil":"","email":"","vorsteuer":"","schadenart":"","schadentag":"","meldedatum":"","reserve":"","kontakt":"","vermittler_firma":"","vermittler_ansprechpartner":"","vermittler_telefon":"","vermittler_mobil":"","vermittler_fax":"","vermittler_email":"","versicherer":"","fallart":"","fallart_hinweis":""}

Hinweise:
- vn_objekt = Versicherungsnehmer / Firmenname / versichertes Objekt, soweit klar erkennbar.\n- strasse, plz und ort sind ausschließlich die Anschrift des Versicherungsnehmers.\n- schaden_strasse, schaden_plz und schaden_ort sind ausschließlich die Besichtigungs- bzw. Schadenortanschrift. Diese kann von der VN-Anschrift abweichen. Nicht gleichsetzen oder aus der VN-Anschrift ableiten, wenn der Schadenort nicht ausdrücklich belegt ist.
- kontakt = zuständiger Ansprechpartner beim VN/Objekt, sofern separat genannt.
- vorsteuer = z. B. "ja", "nein" oder leer.
- schadentag und meldedatum möglichst im Format TT.MM.JJJJ.
- reserve nur mit Betrag/Währung, wenn ausdrücklich genannt.
PROMPT;

$content = [['type'=>'input_text','text'=>'Extrahiere die Falldaten aus dieser Unterlage.']];
if (str_starts_with($mime, 'image/')) {
    array_unshift($content, ['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.$base64,'detail'=>'high']);
} elseif (str_starts_with($mime, 'text/') || $mime === 'application/csv') {
    $text = mb_substr((string)$bytes, 0, 180000, 'UTF-8');
    array_unshift($content, ['type'=>'input_text','text'=>'Datei: '.$name."\n\n".$text]);
} else {
    // Responses API erwartet file_data als Data-URL, nicht als nackten Base64-String.
    array_unshift($content, ['type'=>'input_file','filename'=>$name,'file_data'=>'data:'.$mime.';base64,'.$base64]);
}

$payload = [
    'model'=>env('OPENAI_MODEL','gpt-5.4-mini'),
    'instructions'=>$system,
    'input'=>[[
        'role'=>'user',
        'content'=>$content,
    ]],
    'max_output_tokens'=>2200,
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
    CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    CURLOPT_CONNECTTIMEOUT=>12,
    CURLOPT_TIMEOUT=>180,
]);
$response = curl_exec($ch);
$status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);
if ($response === false || $error !== '') apiError(503, 'KI-Verbindung fehlgeschlagen.');
if ($status < 200 || $status >= 300) {
    error_log('[insurance-case-extract] OpenAI '.$status.' '.substr((string)$response,0,1600));
    $decodedError = json_decode((string)$response,true);
    $msg = trim((string)($decodedError['error']['message'] ?? ''));
    apiError(503, $msg !== '' ? 'Falldaten konnten nicht automatisch ausgelesen werden: '.$msg : 'Falldaten konnten nicht automatisch ausgelesen werden.');
}

$decoded = json_decode((string)$response,true);
$text = '';
if (isset($decoded['output_text']) && is_string($decoded['output_text'])) $text = trim($decoded['output_text']);
if ($text === '') {
    foreach (($decoded['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach (($item['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) $text .= (string)$part['text'];
        }
    }
    $text = trim($text);
}
if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s',$text,$m)) $text=$m[1];
$data = json_decode($text,true);
if (!is_array($data)) {
    $start=strpos($text,'{');$end=strrpos($text,'}');
    if ($start!==false && $end!==false && $end>$start) $data=json_decode(substr($text,$start,$end-$start+1),true);
}
if (!is_array($data)) apiError(503, 'KI-Antwort konnte nicht als Falldaten gelesen werden.');

$allowed=['schaden_nr','versicherungsschein_nr','vn_objekt','strasse','plz','ort','schaden_strasse','schaden_plz','schaden_ort','telefon','mobil','email','vorsteuer','schadenart','schadentag','meldedatum','reserve','kontakt','vermittler_firma','vermittler_ansprechpartner','vermittler_telefon','vermittler_mobil','vermittler_fax','vermittler_email','versicherer','fallart','fallart_hinweis'];
$out=[];
foreach($allowed as $key) $out[$key]=trim((string)($data[$key]??''));
apiJson(['ok'=>true,'file_name'=>$name,'fields'=>$out]);
