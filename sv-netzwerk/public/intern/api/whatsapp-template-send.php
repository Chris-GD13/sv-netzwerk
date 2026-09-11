<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/profile-routing.php';

function wtEnv(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

function wtProfiles(): array
{
    return [
        'christian' => ['name'=>'Christian Wächter','number'=>'+4973673103045','phone_id'=>wtEnv('WHATSAPP_CHRISTIAN_PHONE_NUMBER_ID','1282747221593843'),'waba_id'=>wtEnv('WHATSAPP_CHRISTIAN_WABA_ID','2484676038720950'),'access_token'=>wtEnv('WHATSAPP_CHRISTIAN_ACCESS_TOKEN',wtEnv('WHATSAPP_ACCESS_TOKEN'))],
        'holger' => ['name'=>'Holger Roth','number'=>'+491731645162','phone_id'=>wtEnv('WHATSAPP_HOLGER_PHONE_NUMBER_ID'),'waba_id'=>wtEnv('WHATSAPP_HOLGER_WABA_ID'),'access_token'=>wtEnv('WHATSAPP_HOLGER_ACCESS_TOKEN')],
        'marc' => ['name'=>'Marc Schütt','number'=>'+4923926592751','phone_id'=>wtEnv('WHATSAPP_MARC_PHONE_NUMBER_ID'),'waba_id'=>wtEnv('WHATSAPP_MARC_WABA_ID'),'access_token'=>wtEnv('WHATSAPP_MARC_ACCESS_TOKEN')],
    ];
}

function wtTemplates(): array
{
    return [
        'meta_start' => ['label'=>'Kontakt eröffnen (Meta-Standard)','name'=>'hello_world','text'=>'Meta-Standardnachricht zur Eröffnung des WhatsApp-Kontakts. Nach einer Antwort kann innerhalb von 24 Stunden Freitext gesendet werden.','parameters'=>0],
        'kva' => ['label'=>'KVA anfordern','name'=>'sv_kva_anfrage_v1','text'=>'Guten Tag {{1}}, für die weitere Bearbeitung des Schadenfalls {{2}} benötigen wir einen Kostenvoranschlag für die schadenbedingten Reparaturarbeiten. Bitte senden Sie uns diesen per E-Mail oder WhatsApp. Mit freundlichen Grüßen {{3}}, SV-Netzwerk','parameters'=>3],
        'unterlagen' => ['label'=>'Unterlagen anfordern','name'=>'sv_unterlagen_anfordern_v1','text'=>'Guten Tag {{1}}, für die weitere Bearbeitung des Schadenfalls {{2}} benötigen wir noch die ausstehenden Unterlagen. Bitte senden Sie uns diese per E-Mail oder WhatsApp. Mit freundlichen Grüßen {{3}}, SV-Netzwerk'],
        'rueckruf' => ['label'=>'Rückruf erbeten','name'=>'sv_rueckrufbitte_v1','text'=>'Guten Tag {{1}}, wir möchten den Schadenfall {{2}} kurz mit Ihnen abstimmen. Bitte rufen Sie uns bei Gelegenheit zurück. Mit freundlichen Grüßen {{3}}, SV-Netzwerk'],
        'termin' => ['label'=>'Termin abstimmen','name'=>'sv_terminabstimmung_v1','text'=>'Guten Tag {{1}}, zum Schadenfall {{2}} möchten wir einen Besichtigungstermin mit Ihnen abstimmen. Bitte teilen Sie uns mit, wann Sie erreichbar sind. Mit freundlichen Grüßen {{3}}, SV-Netzwerk'],
        'allgemein' => ['label'=>'Allgemeine Rückmeldung','name'=>'sv_allgemeine_rueckmeldung_v1','text'=>'Guten Tag {{1}}, wir melden uns zum Schadenfall {{2}}. Bitte antworten Sie auf diese WhatsApp-Nachricht, damit wir die weitere Abstimmung direkt fortführen können. Mit freundlichen Grüßen {{3}}, SV-Netzwerk'],
    ];
}

function wtNormalizePhone(string $value): string
{
    $value = preg_replace('/[^0-9+]/', '', trim($value)) ?? '';
    if (str_starts_with($value, '00')) $value = '+' . substr($value, 2);
    if (str_starts_with($value, '0')) $value = '+49' . substr($value, 1);
    if (!str_starts_with($value, '+')) $value = '+' . $value;
    return preg_match('/^\+[1-9][0-9]{7,14}$/', $value) ? $value : '';
}

function wtTokenKey(): string
{
    $secret = wtEnv('WHATSAPP_TOKEN_ENCRYPTION_KEY', wtEnv('WHATSAPP_APP_SECRET'));
    if ($secret === '') throw new RuntimeException('Der sichere WhatsApp-Schlüssel ist noch nicht eingerichtet.');
    return hash('sha256', 'sv-netzwerk:whatsapp:v1:' . $secret, true);
}

function wtDecryptToken(string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', wtTokenKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

function wtConnection(string $profileKey): array
{
    $profile = wtProfiles()[$profileKey] ?? null;
    if (!$profile) throw new RuntimeException('Für dieses Bearbeiterprofil ist keine WhatsApp-Anbindung vorgesehen.');
    $stmt = db()->prepare('SELECT phone_number_id,waba_id,access_token_ciphertext FROM whatsapp_profile_connections WHERE profile_key=:profile LIMIT 1');
    $stmt->execute([':profile'=>$profileKey]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $token = wtDecryptToken((string)$row['access_token_ciphertext']);
        if ($token !== '') return ['phone_id'=>(string)$row['phone_number_id'],'waba_id'=>(string)$row['waba_id'],'token'=>$token];
    }
    return ['phone_id'=>(string)$profile['phone_id'],'waba_id'=>(string)$profile['waba_id'],'token'=>(string)$profile['access_token']];
}

function wtGraph(string $method, string $path, ?array $json, string $token): array
{
    if ($token === '') throw new RuntimeException('WhatsApp-Zugriff ist noch nicht eingerichtet.');
    $version = wtEnv('WHATSAPP_GRAPH_VERSION', 'v25.0');
    $ch = curl_init('https://graph.facebook.com/' . rawurlencode($version) . '/' . ltrim($path, '/'));
    $headers = ['Authorization: Bearer ' . $token];
    if ($json !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>120]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false || $error !== '') throw new RuntimeException('Externe WhatsApp-Verbindung fehlgeschlagen.');
    $data = json_decode((string)$response, true);
    if ($status < 200 || $status >= 300) {
        $message = trim((string)($data['error']['message'] ?? ''));
        $code = (int)($data['error']['code'] ?? 0);
        if ($message === '' || stripos($message, 'unknown error') !== false) $message = 'Meta hat den Versand abgelehnt. Bitte prüfen Sie, ob für Freitext ein 24-Stunden-Servicefenster besteht oder verwenden Sie einen freigegebenen Textbaustein.';
        throw new RuntimeException($message . ($code ? ' (Meta-Code ' . $code . ')' : ''));
    }
    return is_array($data) ? $data : [];
}

commonHeaders();
$user = requireAuth();
if (!in_array((string)($user['role'] ?? ''), ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');

try {
    $profileKey = svnetSelectedProfile($user, (string)($_SESSION['svnet_selected_expert'] ?? ''));
    $profile = wtProfiles()[$profileKey] ?? null;
    if (!$profile) throw new RuntimeException('Für dieses Bearbeiterprofil ist keine WhatsApp-Anbindung vorgesehen.');
    $connection = wtConnection($profileKey);
    $templates = wtTemplates();
    $action = (string)($_GET['action'] ?? 'list');

    if ($action === 'list') {
        $statuses = [];
        if ($connection['waba_id'] !== '' && $connection['token'] !== '') {
            $remote = wtGraph('GET', rawurlencode($connection['waba_id']) . '/message_templates?fields=name,status,language&limit=100', null, $connection['token']);
            foreach (($remote['data'] ?? []) as $row) $statuses[(string)($row['name'] ?? '')] = strtoupper((string)($row['status'] ?? ''));
        }
        $result = [];
        foreach ($templates as $key=>$tpl) $result[] = ['key'=>$key,'label'=>$tpl['label'],'name'=>$tpl['name'],'text'=>$tpl['text'],'parameters'=>(int)($tpl['parameters']??3),'status'=>$statuses[$tpl['name']] ?? 'MISSING'];
        apiJson(['ok'=>true,'templates'=>$result]);
    }

    if ($action === 'send') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
        $body = requestBody();
        $key = (string)($body['template'] ?? '');
        $tpl = $templates[$key] ?? null;
        if (!$tpl) apiError(400, 'Unbekannter WhatsApp-Textbaustein.');
        $phone = wtNormalizePhone((string)($body['phone'] ?? ''));
        if ($phone === '') apiError(400, 'Bitte eine gültige WhatsApp-Rufnummer angeben.');
        $contact = trim((string)($body['contact_name'] ?? '')) ?: 'Damen und Herren';
        $caseNo = trim((string)($body['case_no'] ?? '')) ?: 'ohne Schaden-Nr.';
        $contact = mb_substr($contact,0,120);
        $caseNo = mb_substr($caseNo,0,120);
        $templatePayload = ['name'=>$tpl['name'],'language'=>['code'=>$key==='meta_start'?'en_US':wtEnv('WHATSAPP_TEMPLATE_LANGUAGE','de')]];
        if ((int)($tpl['parameters']??3) > 0) $templatePayload['components'] = [['type'=>'body','parameters'=>[
            ['type'=>'text','text'=>$contact],
            ['type'=>'text','text'=>$caseNo],
            ['type'=>'text','text'=>(string)$profile['name']],
        ]]];
        $result = wtGraph('POST', rawurlencode($connection['phone_id']) . '/messages', [
            'messaging_product'=>'whatsapp',
            'to'=>ltrim($phone,'+'),
            'type'=>'template',
            'template'=>$templatePayload,
        ], $connection['token']);
        $wamid = (string)($result['messages'][0]['id'] ?? '');
        if ($wamid === '') throw new RuntimeException('WhatsApp hat keine Versandbestätigung geliefert.');
        apiJson(['ok'=>true,'wamid'=>$wamid,'template'=>$key,'label'=>$tpl['label']]);
    }

    apiError(400, 'Unbekannte WhatsApp-Vorlagenaktion.');
} catch (Throwable $error) {
    apiError(503, $error->getMessage());
}
