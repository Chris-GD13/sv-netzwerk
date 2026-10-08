<?php
declare(strict_types=1);

/**
 * Nur CLI: legt für Fallordner ohne 00_Falldaten.json die Falldaten aus den gespeicherten Unterlagen an
 * (KI-Auslese wie im Portal). Es werden ausschließlich neue Falldaten geschrieben, bestehende nie verändert.
 *
 *   runuser -u www-data -- php cli-extract-missing-falldaten.php [--apply] [--limit=N] [--fill-empty]
 *
 * Mit --fill-empty werden zusätzlich bestehende Falldaten ohne VN/Objekt oder Schadenart (z. B. aus dem
 * ClaimsForce-Import) aus den Unterlagen ergänzt; nur leere Felder werden gefüllt.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ionos-storage.php';

$apply = in_array('--apply', $argv, true);
$fillEmpty = in_array('--fill-empty', $argv, true);
$fillEmpty = in_array('--fill-empty', $argv, true);
$limit = 1000;
foreach ($argv as $arg) if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
$apiKey = trim(env('OPENAI_API_KEY', ''));
if ($apiKey === '') { fwrite(STDERR, "OPENAI_API_KEY fehlt.\n"); exit(1); }

const FIELDS = ['schaden_nr','versicherungsschein_nr','vn_objekt','strasse','plz','ort','schaden_strasse','schaden_plz','schaden_ort','telefon','mobil','email','vorsteuer','schadenart','schadentag','meldedatum','reserve','kontakt','sanierer_firma','sanierer_ansprechpartner','sanierer_funktion','sanierer_telefon','sanierer_mobil','sanierer_email','versicherer','fallart'];
const PROMPT = 'Du analysierst Unterlagen zu deutschen Versicherungs-Schadenfällen und extrahierst ausschließlich eindeutig erkennbare Falldaten. Nichts erfinden. Unklare oder nicht vorhandene Werte als leeren String zurückgeben. Telefonnummern und E-Mail-Adressen exakt übernehmen. Schaden-Nr. und Versicherungsschein-Nr. nicht verwechseln. telefon, mobil und email gehören ausschließlich dem Versicherungsnehmer (VN), niemals Versicherer, Regulierer, Sachverständigen, Vermittler oder Sanierer; Sanierer-Kontakte nur in sanierer_*. vn_objekt = Versicherungsnehmer/Firma/Objekt. strasse, plz, ort = Anschrift des VN; schaden_strasse, schaden_plz, schaden_ort = Schadenort (nicht aus der VN-Anschrift ableiten, wenn nicht belegt). fallart nur "SV", "SV-GF" oder "Andere Versicherer", sonst leer. schadentag und meldedatum als TT.MM.JJJJ. Antworte ausschließlich als JSON mit genau diesen Feldern: ';

function openAiExtract(string $apiKey, string $name, string $mime, string $bytes): array
{
    $content = [['type' => 'input_file', 'filename' => $name, 'file_data' => 'data:' . $mime . ';base64,' . base64_encode($bytes)], ['type' => 'input_text', 'text' => 'Extrahiere die Falldaten aus dieser Unterlage.']];
    $payload = ['model' => env('OPENAI_MODEL', 'gpt-5.4-mini'), 'instructions' => PROMPT . json_encode(array_fill_keys(FIELDS, ''), JSON_UNESCAPED_UNICODE), 'input' => [['role' => 'user', 'content' => $content]], 'max_output_tokens' => 2200];
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 180]);
    $response = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('KI-Anfrage fehlgeschlagen (HTTP ' . $status . ').');
    $decoded = json_decode((string)$response, true);
    $text = is_string($decoded['output_text'] ?? null) ? trim($decoded['output_text']) : '';
    if ($text === '') foreach (($decoded['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
    $start = strpos($text, '{'); $end = strrpos($text, '}');
    $data = $start !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    if (!is_array($data)) throw new RuntimeException('KI-Antwort nicht lesbar.');
    $out = [];
    foreach (FIELDS as $key) $out[$key] = trim((string)($data[$key] ?? ''));
    return $out;
}

$db = ionosStorageDb();
$rows = db()->query('SELECT folder_id,user_id,user_email FROM case_folder_owners')->fetchAll(PDO::FETCH_ASSOC);
$stat = ['kandidaten' => 0, 'angelegt' => 0, 'ohne_unterlage' => 0, 'ohne_ergebnis' => 0, 'fehler' => 0];
foreach ($rows as $row) {
    if ($stat['kandidaten'] >= $limit) break;
    $fid = (string)$row['folder_id'];
    $s = $db->prepare('SELECT name FROM items WHERE id=? AND verified=1'); $s->execute([$fid]);
    $folderName = $s->fetchColumn();
    if ($folderName === false) continue;
    $s = $db->prepare("SELECT id FROM items WHERE parent=? AND name='00_Falldaten.json' AND verified=1"); $s->execute([$fid]);
    $existingId = (string)$s->fetchColumn();
    $existing = [];
    if ($existingId !== '') {
        if (!$fillEmpty) continue;
        $existing = json_decode(ionosBytes($existingId), true);
        if (!is_array($existing) || (trim((string)($existing['vn_objekt'] ?? '')) !== '' && trim((string)($existing['schadenart'] ?? '')) !== '')) continue;
    }
    $stat['kandidaten']++;
    $q = $db->prepare("WITH RECURSIVE t(id,name,mime) AS (SELECT id,name,mime FROM items WHERE parent=? AND verified=1 UNION ALL SELECT i.id,i.name,i.mime FROM items i JOIN t ON i.parent=t.id WHERE i.verified=1) SELECT id,name FROM t WHERE mime='application/pdf'");
    $q->execute([$fid]);
    $docs = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $score = preg_match('/Erstbericht|Schadenmeldung|Auftrag|Schlusserkl|Beauftragung|Schadenprotokoll|Angebot/iu', $d['name']) ? 1 : 0;
        $size = (int)(ionosItem($d['id'])['size'] ?? 0);
        if ($size > 0 && $size < 12 * 1024 * 1024) $docs[] = [$score, -$size, $d];
    }
    usort($docs, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    $docs = array_slice($docs, 0, 3);
    if (!$docs) { $stat['ohne_unterlage']++; echo "Keine PDF-Unterlage: $folderName\n"; continue; }
    $case = [];
    try {
        foreach ($docs as [, , $d]) {
            foreach (openAiExtract($apiKey, (string)$d['name'], 'application/pdf', ionosBytes($d['id'])) as $key => $value) {
                if ($value !== '' && ($case[$key] ?? '') === '') $case[$key] = $value;
            }
        }
        if ($existingId !== '') {
            $added = 0;
            foreach ($case as $key => $value) if ($value !== '' && trim((string)($existing[$key] ?? '')) === '') { $existing[$key] = $value; $added++; }
            if ($added === 0) { $stat['ohne_ergebnis']++; echo "Nichts zu ergänzen: $folderName\n"; continue; }
            $existing['updated_at'] = gmdate('c'); $existing['updated_by'] = 'Automatische Auslese aus Fallunterlagen';
            echo ($apply ? 'Ergänzt' : 'Gefunden') . ': ' . $folderName . " => $added Felder\n";
            if ($apply) {
                ionosWrite([], json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), $existingId);
                registerCaseFolderOwner($fid, ['id' => (int)$row['user_id'], 'email' => (string)$row['user_email']], $existing);
            }
            $stat['angelegt']++;
            continue;
        }
        if (count(array_filter($case)) < 2) { $stat['ohne_ergebnis']++; echo "Zu wenig Daten: $folderName\n"; continue; }
        $case['updated_at'] = gmdate('c'); $case['updated_by'] = 'Automatische Auslese aus Fallunterlagen'; $case['created_at'] = $case['updated_at'];
        echo ($apply ? 'Angelegt' : 'Gefunden') . ': ' . $folderName . ' => ' . count(array_filter($case)) . " Felder\n";
        if ($apply) {
            ionosWrite(['name' => '00_Falldaten.json', 'mimeType' => 'application/json', 'parents' => [$fid]], json_encode($case, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            registerCaseFolderOwner($fid, ['id' => (int)$row['user_id'], 'email' => (string)$row['user_email']], $case);
        }
        $stat['angelegt']++;
    } catch (Throwable $e) {
        $stat['fehler']++;
        fwrite(STDERR, "Fehler bei $folderName: " . $e->getMessage() . "\n");
    }
}
echo ($apply ? 'ANGEWENDET' : 'TROCKENLAUF') . ' ' . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
