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

/** Deterministic fallback for the IONOS VPS when no external extraction key is configured. */
function localCaseExtract(string $name, string $mime, string $bytes): array {
    $text = '';
    if ($mime === 'application/pdf' || str_ends_with(strtolower($name), '.pdf')) {
        $tmp = tempnam(sys_get_temp_dir(), 'svnet-pdf-');
        if ($tmp !== false) {
            file_put_contents($tmp, $bytes);
            $cmd = 'pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null';
            $text = (string)shell_exec($cmd);
            @unlink($tmp);
        }
    } elseif (str_starts_with($mime, 'text/') || in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['eml','txt','csv','md'], true)) {
        $text = $bytes;
    }
    $text = preg_replace('/[ \t]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $text)) ?? '';
    $text = preg_replace('/\n{2,}/u', "\n", $text) ?? '';
    $out = array_fill_keys(['schaden_nr','versicherungsschein_nr','vn_objekt','strasse','plz','ort','schaden_strasse','schaden_plz','schaden_ort','telefon','mobil','email','vorsteuer','schadenart','schadentag','meldedatum','reserve','kontakt','sanierer_firma','sanierer_ansprechpartner','sanierer_funktion','sanierer_telefon','sanierer_mobil','sanierer_email','vermittler_firma','vermittler_ansprechpartner','vermittler_telefon','vermittler_mobil','vermittler_fax','vermittler_email','versicherer','fallart','fallart_hinweis'], '');
    $pick = static function(string $pattern) use ($text): string { return preg_match($pattern, $text, $m) ? trim((string)($m[1] ?? '')) : ''; };
    $out['schaden_nr'] = $pick('/(?:Vers\.?\s*)?Schaden\s*Nr\.?\s*:\s*([0-9][0-9A-Za-z _\/-]*)/iu');
    if ($out['schaden_nr'] === '') $out['schaden_nr'] = $pick('/Schaden[- ]?Nr\.?\s*([0-9][0-9A-Za-z _\/-]*)/iu');
    $out['versicherer'] = $pick('/Versicherung\s*:\s*([^\n]+)/iu');
    $damage = $pick('/Schadenort\s+([^\n]+)/iu');
    if ($damage !== '') {
        if (preg_match('/^(.+?)\s+(\d{5})\s+(.+)$/u', $damage, $m)) { $out['schaden_strasse']=trim($m[1]); $out['schaden_plz']=$m[2]; $out['schaden_ort']=trim($m[3]); }
        else $out['schaden_ort']=$damage;
    }
    if (preg_match('/Stra(?:ß|ss)e\s*:\s*([^\n]+)\s+PLZ\s+Ort\s*:\s*(\d{5})\s+([^\n]+)/iu', $text, $m)) {
        $out['schaden_strasse']=trim($m[1]); $out['schaden_plz']=$m[2]; $out['schaden_ort']=trim($m[3]);
    }
    if ($out['schaden_strasse'] === '' && preg_match('/(?:BV|Lieferadresse)\s*:\s*([^,\n]+),?\s*(\d{5})\s+([^\n]+)/iu', $text, $m)) {
        $out['schaden_strasse']=trim($m[1]); $out['schaden_plz']=$m[2]; $out['schaden_ort']=trim($m[3]);
    }
    if (preg_match('/\b(Wasser(?:schaden)?|Brand(?:schaden)?|Sturm|Hagel|Leckage)\b/iu', $text, $m)) $out['schadenart']=trim($m[1]);
    if (preg_match('/\b(Rainbow\s+Sanierungen[^\n]+|Grillenberger\s+Schadenmanagement[^\n]*)/iu', $text, $m)) $out['sanierer_firma']=trim($m[1]);
    if ($out['sanierer_firma'] === '' && stripos($text, 'Rainbow') !== false) $out['sanierer_firma']='Rainbow Sanierungen';
    if (preg_match('/\b([\w.+-]+@[\w.-]+\.[A-Za-z]{2,})\b/u', $text, $m)) $out['sanierer_email']=trim($m[1]);
    return $out;
}

$apiKey = trim(env('OPENAI_API_KEY', ''));
if ($apiKey === '') apiJson(['ok'=>true,'file_name'=>$name,'fields'=>localCaseExtract($name,$mime,$bytes),'fallback'=>'local']);
$base64 = base64_encode($bytes);

$system = <<<'PROMPT'
Du analysierst Unterlagen zu deutschen Versicherungs-Schadenfällen und extrahierst ausschließlich eindeutig erkennbare Falldaten. Nichts erfinden. Unklare oder nicht vorhandene Werte als leeren String zurückgeben. Telefonnummern und E-Mail-Adressen exakt übernehmen. Schaden-Nr. und Versicherungsschein-Nr. nicht verwechseln.

Kontaktangaben müssen strikt der ausdrücklich bezeichneten Partei zugeordnet werden:
- telefon, mobil und email sind ausschließlich Kontaktdaten des Versicherungsnehmers (VN) oder eines eindeutig als VN-Ansprechpartner bezeichneten Kontakts.
- Niemals Kontaktdaten des Versicherers, der Sparkassenversicherung, eines Regulierers, Sachverständigen, Vermittlers oder Sanierers in telefon, mobil oder email eintragen.
- Ist die Zuordnung zum VN nicht eindeutig belegt, telefon, mobil und email leer lassen.
- Sanierer-Kontaktdaten ausschließlich in die sanierer_*-Felder eintragen. Das gilt insbesondere für POLYGON, Imle, Rainbow und vergleichbare Sanierungsunternehmen.
- Einen Objektleiter, Bauleiter, Projektleiter oder sonstigen Verantwortlichen des Sanierers in sanierer_ansprechpartner eintragen und dessen ausdrücklich genannte Funktion in sanierer_funktion.

Bestimme zusätzlich den Versicherer und die Fallart. Zulässige Werte für fallart sind ausschließlich "SV", "SV-GF" oder "Andere Versicherer".
- "SV-GF" nur bei Sparkassenversicherung und erkennbarem GF-, Großschaden- oder Groß-TF-Bezug beziehungsweise entsprechenden GF-Berichtsformularen.
- "SV" bei Sparkassenversicherung ohne belegten GF-/Großschadenbezug.
- "Andere Versicherer" bei allen anderen Versicherern.
- Ist die Einordnung nicht belastbar, fallart leer lassen und die Gründe in fallart_hinweis nennen.

Antworte ausschließlich als JSON mit genau diesen Feldern:
{"schaden_nr":"","versicherungsschein_nr":"","vn_objekt":"","strasse":"","plz":"","ort":"","schaden_strasse":"","schaden_plz":"","schaden_ort":"","telefon":"","mobil":"","email":"","vorsteuer":"","schadenart":"","schadentag":"","meldedatum":"","reserve":"","kontakt":"","sanierer_firma":"","sanierer_ansprechpartner":"","sanierer_funktion":"","sanierer_telefon":"","sanierer_mobil":"","sanierer_email":"","vermittler_firma":"","vermittler_ansprechpartner":"","vermittler_telefon":"","vermittler_mobil":"","vermittler_fax":"","vermittler_email":"","versicherer":"","fallart":"","fallart_hinweis":""}

Hinweise:
- vn_objekt = Versicherungsnehmer / Firmenname / versichertes Objekt, soweit klar erkennbar.\n- strasse, plz und ort sind ausschließlich die Anschrift des Versicherungsnehmers.\n- schaden_strasse, schaden_plz und schaden_ort sind ausschließlich die Besichtigungs- bzw. Schadenortanschrift. Diese kann von der VN-Anschrift abweichen. Nicht gleichsetzen oder aus der VN-Anschrift ableiten, wenn der Schadenort nicht ausdrücklich belegt ist.
- kontakt = zuständiger Ansprechpartner beim VN/Objekt, sofern separat genannt.
- sanierer_firma = beauftragtes oder tätiges Sanierungsunternehmen, nur wenn eindeutig genannt.
- sanierer_ansprechpartner und sanierer_funktion = verantwortliche Kontaktperson des Sanierers und deren Rolle, insbesondere Objektleitung oder Bauleitung, soweit ausdrücklich belegt.
- sanierer_telefon, sanierer_mobil und sanierer_email = ausschließlich dem Sanierer oder dessen verantwortlicher Kontaktperson eindeutig zugeordnete Kontaktdaten.
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

$allowed=['schaden_nr','versicherungsschein_nr','vn_objekt','strasse','plz','ort','schaden_strasse','schaden_plz','schaden_ort','telefon','mobil','email','vorsteuer','schadenart','schadentag','meldedatum','reserve','kontakt','sanierer_firma','sanierer_ansprechpartner','sanierer_funktion','sanierer_telefon','sanierer_mobil','sanierer_email','vermittler_firma','vermittler_ansprechpartner','vermittler_telefon','vermittler_mobil','vermittler_fax','vermittler_email','versicherer','fallart','fallart_hinweis'];
$out=[];
foreach($allowed as $key) $out[$key]=trim((string)($data[$key]??''));
apiJson(['ok'=>true,'file_name'=>$name,'fields'=>$out]);
