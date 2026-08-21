<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array((string)($user['role'] ?? ''), ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');

function omEnv(string $key, string $default=''): string {
    $value = getenv($key);
    return $value === false || trim((string)$value) === '' ? $default : trim((string)$value);
}

function omProfile(array $user): array {
    $email = mb_strtolower(trim((string)($user['email'] ?? '')), 'UTF-8');
    $name = mb_strtolower(trim((string)($user['full_name'] ?? '')), 'UTF-8');

    if ($email === 'ms@sv-schuett.eu' || str_contains($name, 'marc')) {
        return ['sender' => 'ms@sv-schuett.eu', 'sender_name' => 'Marc Schütt'];
    }
    if ($email === 'hr@sv-schuett.eu' || str_contains($name, 'holger')) {
        return ['sender' => 'hr@sv-schuett.eu', 'sender_name' => 'Holger Roth'];
    }
    if ($email === 'ws@sv-schuett.eu' || str_contains($name, 'susanne')) {
        return ['sender' => 'ws@sv-schuett.eu', 'sender_name' => 'Susanne Wächter'];
    }
    return ['sender' => 'cw@sv-schuett.eu', 'sender_name' => 'Christian Wächter'];
}

function omHttp(string $method, string $url, array $headers=[], ?string $body=null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false || $error !== '') throw new RuntimeException('Microsoft-Verbindung fehlgeschlagen.');
    return ['status' => $status, 'body' => (string)$response];
}

function omToken(): string {
    static $token = null;
    if ($token !== null) return $token;
    $tenant = omEnv('MS_TENANT_ID');
    $client = omEnv('MS_CLIENT_ID');
    $secret = omEnv('MS_CLIENT_SECRET');
    if ($tenant === '' || $client === '' || $secret === '') throw new RuntimeException('Microsoft-Verbindung ist nicht vollständig eingerichtet.');
    $response = omHttp(
        'POST',
        'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $client,
            'client_secret' => $secret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ])
    );
    $json = json_decode($response['body'], true);
    if ($response['status'] !== 200 || empty($json['access_token'])) throw new RuntimeException('Microsoft-Anmeldung ist fehlgeschlagen.');
    return $token = (string)$json['access_token'];
}

function omGraph(string $method, string $path, ?array $json=null): array {
    $headers = ['Authorization: Bearer ' . omToken()];
    $body = null;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $response = omHttp($method, 'https://graph.microsoft.com/v1.0/' . $path, $headers, $body);
    $data = $response['body'] !== '' ? json_decode($response['body'], true) : [];
    if ($response['status'] < 200 || $response['status'] >= 300) {
        if (in_array($response['status'], [401, 403], true)) throw new RuntimeException('Mail.Send für Microsoft Graph ist noch nicht freigegeben.');
        throw new RuntimeException('Outlook-Mailversand fehlgeschlagen.');
    }
    return is_array($data) ? $data : [];
}

function omValidRecipients(string $value): array {
    $parts = preg_split('/[;,\s]+/', trim($value)) ?: [];
    $out = [];
    foreach ($parts as $part) {
        if ($part === '') continue;
        if (!filter_var($part, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige E-Mail-Adresse: ' . $part);
        $out[] = ['emailAddress' => ['address' => $part]];
    }
    return $out;
}

function omHtml(string $value): string {
    return nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

function omAttachments(): array {
    $out = [];
    foreach ($_FILES as $file) {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $name = basename((string)($file['name'] ?? 'Anhang'));
        $size = (int)($file['size'] ?? 0);
        if ($size > 3 * 1024 * 1024) throw new RuntimeException('Der Anhang „' . $name . '“ ist größer als 3 MB.');
        $bytes = file_get_contents((string)$file['tmp_name']);
        if (!is_string($bytes)) continue;
        $out[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $name,
            'contentBytes' => base64_encode($bytes),
        ];
    }
    return $out;
}

$profile = omProfile($user);
$action = (string)($_GET['action'] ?? 'status');

try {
    if ($action === 'status') {
        apiJson(['ok' => true, 'sender' => $profile['sender'], 'sender_name' => $profile['sender_name']]);
    }

    if ($action === 'send') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
        $to = omValidRecipients((string)($_POST['to'] ?? ''));
        if (!$to) throw new RuntimeException('Empfänger fehlt.');
        $cc = omValidRecipients((string)($_POST['cc'] ?? ''));
        $caseNo = trim((string)($_POST['case_no'] ?? ''));
        $damageType = trim((string)($_POST['damage_type'] ?? ''));
        if ($caseNo === '') throw new RuntimeException('Schaden-Nr. fehlt im aktiven Fall.');
        $subject = $caseNo . ($damageType !== '' ? ' – ' . $damageType : '');
        $text = trim((string)($_POST['body'] ?? ''));
        if ($text === '') throw new RuntimeException('E-Mail-Text fehlt.');

        $signature = '<p>Mit freundlichen Grüßen<br>'
            . htmlspecialchars((string)$profile['sender_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '<br>SV-Büro Marc Schütt e.K.</p>';

        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => '<p>' . omHtml($text) . '</p>' . $signature],
            'toRecipients' => $to,
        ];
        if ($cc) $message['ccRecipients'] = $cc;
        $attachments = omAttachments();
        if ($attachments) $message['attachments'] = $attachments;

        omGraph('POST', 'users/' . rawurlencode((string)$profile['sender']) . '/sendMail', [
            'message' => $message,
            'saveToSentItems' => true,
        ]);

        apiJson(['ok' => true, 'subject' => $subject, 'sender' => $profile['sender']]);
    }

    apiError(404, 'Unbekannte Aktion.');
} catch (Throwable $e) {
    apiError(500, $e->getMessage());
}
