<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array((string)($user['role'] ?? ''), ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) {
    apiError(403, 'Keine Berechtigung.');
}

function otEnv(string $key, string $default=''): string {
    $value = getenv($key);
    return $value === false || trim((string)$value) === '' ? $default : trim((string)$value);
}

function otMailbox(array $user): string {
    $email = mb_strtolower(trim((string)($user['email'] ?? '')), 'UTF-8');
    $name = mb_strtolower(trim((string)($user['full_name'] ?? '')), 'UTF-8');
    if ($email === 'ms@sv-schuett.eu' || str_contains($name, 'marc')) return 'ms@sv-schuett.eu';
    if ($email === 'hr@sv-schuett.eu' || str_contains($name, 'holger')) return 'hr@sv-schuett.eu';
    if ($email === 'ws@sv-schuett.eu' || str_contains($name, 'susanne')) return 'ws@sv-schuett.eu';
    return 'cw@sv-schuett.eu';
}

function otHttp(string $method, string $url, array $headers=[], ?string $body=null): array {
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

function otToken(): string {
    static $token = null;
    if ($token !== null) return $token;
    $tenant = otEnv('MS_TENANT_ID');
    $client = otEnv('MS_CLIENT_ID');
    $secret = otEnv('MS_CLIENT_SECRET');
    if ($tenant === '' || $client === '' || $secret === '') throw new RuntimeException('Microsoft-Verbindung ist nicht vollständig eingerichtet.');
    $response = otHttp('POST', 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
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

function otGraph(string $method, string $path, ?array $json=null): array {
    $headers = ['Authorization: Bearer ' . otToken()];
    $body = null;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $response = otHttp($method, 'https://graph.microsoft.com/v1.0/' . $path, $headers, $body);
    $data = $response['body'] !== '' ? json_decode($response['body'], true) : [];
    if ($response['status'] < 200 || $response['status'] >= 300) {
        if (in_array($response['status'], [401, 403], true)) throw new RuntimeException('Microsoft Graph verweigert den Zugriff auf die Outlook-Aufgabenordner.');
        $message = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
        throw new RuntimeException($message !== '' ? $message : 'Outlook-Aufgabe konnte nicht verarbeitet werden.');
    }
    return is_array($data) ? $data : [];
}

function otPage(string $path): array {
    $items = [];
    $next = $path;
    while ($next !== '' && count($items) < 500) {
        $data = otGraph('GET', $next);
        foreach (($data['value'] ?? []) as $item) if (is_array($item)) $items[] = $item;
        $next = (string)($data['@odata.nextLink'] ?? '');
        if ($next !== '' && str_starts_with($next, 'https://graph.microsoft.com/v1.0/')) {
            $next = substr($next, strlen('https://graph.microsoft.com/v1.0/'));
        } else {
            $next = '';
        }
    }
    return $items;
}

function otFolders(string $mailbox): array {
    $all = [];
    $walk = function (string $path, int $depth) use (&$walk, &$all, $mailbox): void {
        if ($depth > 4) return;
        foreach (otPage($path) as $folder) {
            $id = (string)($folder['id'] ?? '');
            if ($id === '') continue;
            $all[] = $folder;
            try {
                $walk('users/' . rawurlencode($mailbox) . '/mailFolders/' . rawurlencode($id) . '/childFolders?$top=100', $depth + 1);
            } catch (Throwable) {
                // A folder without readable children must not hide the remaining task folders.
            }
        }
    };
    $walk('users/' . rawurlencode($mailbox) . '/mailFolders?$top=100', 0);
    return $all;
}
function otFolderByName(string $mailbox, string $wanted): ?array {
    $needle = mb_strtolower(trim($wanted), 'UTF-8');
    foreach (otFolders($mailbox) as $folder) {
        if (mb_strtolower(trim((string)($folder['displayName'] ?? '')), 'UTF-8') === $needle) return $folder;
    }
    return null;
}

function otCaseNumber(string $text): string {
    if (preg_match('/\b\d{2}-\d{6,7}(?:-\d)?\b/u', $text, $match)) return $match[0];
    return '';
}

function otMessage(string $mailbox, string $folderId, string $id): array {
    return otGraph('GET', 'users/' . rawurlencode($mailbox) . '/mailFolders/' . rawurlencode($folderId) . '/messages/' . rawurlencode($id) . '?$select=id,subject,receivedDateTime,bodyPreview,webLink,from');
}

$profileMailbox = otMailbox($user);
$action = (string)($_GET['action'] ?? 'list');

try {
    if ($action === 'status') {
        $open = otFolderByName($profileMailbox, 'Zu erledigen');
        $done = otFolderByName($profileMailbox, 'Erledigt');
        apiJson(['ok' => true, 'mailbox' => $profileMailbox, 'open_folder' => $open['displayName'] ?? null, 'done_folder' => $done['displayName'] ?? null, 'ready' => $open !== null && $done !== null]);
    }

    if ($action === 'list') {
        $folder = otFolderByName($profileMailbox, 'Zu erledigen');
        if (!$folder) throw new RuntimeException('Der Outlook-Ordner „Zu erledigen“ wurde nicht gefunden.');
        $folderId = (string)$folder['id'];
        $messages = otPage('users/' . rawurlencode($profileMailbox) . '/mailFolders/' . rawurlencode($folderId) . '/messages?$select=id,subject,receivedDateTime,bodyPreview,webLink,from&$orderby=receivedDateTime%20asc&$top=100');
        $items = [];
        foreach ($messages as $message) {
            $subject = trim((string)($message['subject'] ?? ''));
            $preview = trim((string)($message['bodyPreview'] ?? ''));
            $case = otCaseNumber($subject . ' ' . $preview);
            $items[] = [
                'id' => (string)($message['id'] ?? ''),
                'subject' => $subject !== '' ? $subject : '(ohne Betreff)',
                'received_at' => (string)($message['receivedDateTime'] ?? ''),
                'from' => (string)($message['from']['emailAddress']['name'] ?? $message['from']['emailAddress']['address'] ?? ''),
                'preview' => $preview,
                'case_number' => $case,
                'web_link' => (string)($message['webLink'] ?? ''),
                'edit_url' => $case !== '' ? '/intern/versicherungsfaelle/?schaden_nr=' . rawurlencode($case) : '/intern/versicherungsfaelle/',
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['received_at'], $b['received_at']));
        apiJson(['ok' => true, 'mailbox' => $profileMailbox, 'folder' => $folder['displayName'] ?? 'Zu erledigen', 'items' => $items]);
    }

    if ($action === 'move') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
        $body = requestBody();
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') throw new RuntimeException('Die Outlook-Nachricht wurde nicht angegeben.');
        $open = otFolderByName($profileMailbox, 'Zu erledigen');
        $done = otFolderByName($profileMailbox, 'Erledigt');
        if (!$open || !$done) throw new RuntimeException('Die Outlook-Ordner „Zu erledigen“ und „Erledigt“ müssen vorhanden sein.');
        otGraph('POST', 'users/' . rawurlencode($profileMailbox) . '/messages/' . rawurlencode($id) . '/move', ['destinationId' => (string)$done['id']]);
        apiJson(['ok' => true, 'id' => $id, 'folder' => 'Erledigt']);
    }

    apiError(404, 'Unbekannte Aktion.');
} catch (Throwable $e) {
    apiError(500, $e->getMessage());
}
