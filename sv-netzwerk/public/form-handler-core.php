<?php
declare(strict_types=1);

function sv_clean_text(string $value, bool $singleLine = true): string
{
    $value = trim(strip_tags($value));
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if ($singleLine) {
        $value = str_replace("\n", ' ', $value);
    }
    return $value;
}

function sv_field(string $key, bool $singleLine = true): string
{
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        $value = implode(', ', array_map(static fn($item) => trim((string)$item), $value));
    }
    return sv_clean_text((string)$value, $singleLine);
}

function sv_list_field(string $key): string
{
    $value = $_POST[$key] ?? [];
    if (!is_array($value)) {
        $value = [$value];
    }

    $clean = [];
    foreach ($value as $item) {
        $item = sv_clean_text((string)$item);
        if ($item !== '') {
            $clean[] = $item;
        }
    }

    return $clean ? implode(', ', $clean) : 'Keine Angabe';
}

function sv_log_event(string $channel, string $message): void
{
    $logFile = __DIR__ . '/form-events.log';
    $line = sprintf(
        "[%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        $channel,
        $message,
        PHP_EOL
    );
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function sv_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function sv_send_mail(
    string $to,
    string $subject,
    string $body,
    string $from,
    string $replyName,
    string $replyEmail
): bool {
    $headers = [];
    $headers[] = 'From: SV-Netzwerk Formular <' . $from . '>';
    if ($replyEmail !== '' && filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyName . ' <' . $replyEmail . '>';
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    return mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        implode("\r\n", $headers),
        '-f' . $from
    );
}

function sv_collect_uploads(string $field): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }

    $files = $_FILES[$field];
    $items = [];
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']],
        ];
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'heic'];
    $maxSize = 10 * 1024 * 1024;

    foreach ($files['name'] as $index => $originalName) {
        $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $items[] = 'Uploadfehler bei Datei: ' . sv_clean_text((string)$originalName);
            continue;
        }

        $size = (int)($files['size'][$index] ?? 0);
        if ($size > $maxSize) {
            $items[] = 'Datei zu gross: ' . sv_clean_text((string)$originalName);
            continue;
        }

        $safeName = basename((string)$originalName);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            $items[] = 'Dateityp nicht erlaubt: ' . sv_clean_text($safeName);
            continue;
        }

        $targetName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $safeName);
        $targetPath = $uploadDir . '/' . $targetName;
        $tmpPath = (string)($files['tmp_name'][$index] ?? '');
        if (!is_uploaded_file($tmpPath)) {
            $items[] = 'Unsicherer Upload verworfen: ' . sv_clean_text($safeName);
            continue;
        }

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            $items[] = 'Speichern fehlgeschlagen: ' . sv_clean_text($safeName);
            continue;
        }

        $items[] = 'Gespeichert: ' . $targetName . ' (' . number_format($size / 1024, 0) . ' KB)';
    }

    return $items;
}

function sv_process_form(array $config): void
{
    $returnUrl = $config['return_url'];
    $successUrl = $config['success_url'];
    $from = 'info@sv-netzwerk.eu';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sv_redirect($returnUrl);
    }

    if (!empty($_POST['website'] ?? '')) {
        sv_log_event($config['form_key'], 'Honeypot ausgelost.');
        sv_redirect($successUrl);
    }

    $payload = [];
    $errors = [];

    foreach ($config['fields'] as $field) {
        $name = $field['name'];
        $label = $field['label'];
        $required = (bool)($field['required'] ?? false);
        $mode = $field['mode'] ?? 'single';

        if ($mode === 'multiline') {
            $value = sv_field($name, false);
        } elseif ($mode === 'list') {
            $value = sv_list_field($name);
        } else {
            $value = sv_field($name, true);
        }

        $payload[$label] = $value;
        if ($required && trim($value) === '') {
            $errors[] = $label;
        }
    }

    $replyEmail = sv_field($config['reply_email_field'] ?? 'E-Mail');
    $replyName = sv_field($config['reply_name_field'] ?? 'Name');
    if (!filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'gueltige E-Mail';
    }

    $uploadSummary = [];
    if (!empty($config['upload_field'])) {
        $uploadSummary = sv_collect_uploads($config['upload_field']);
    }

    if ($errors) {
        sv_log_event($config['form_key'], 'Validierungsfehler: ' . implode(', ', $errors));
        sv_redirect($config['error_url']);
    }

    $body = $config['intro'] . "\n\n";
    foreach ($payload as $label => $value) {
        $body .= $label . ': ' . ($value !== '' ? $value : 'Keine Angabe') . "\n";
    }
    if ($uploadSummary) {
        $body .= "\nDatei-Status:\n- " . implode("\n- ", $uploadSummary) . "\n";
    }
    $body .= "\n---\n";
    $body .= 'Gesendet am: ' . date('d.m.Y H:i:s') . "\n";
    $body .= 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt') . "\n";

    $sentToTeam = sv_send_mail(
        $config['to'],
        $config['subject'],
        $body,
        $from,
        $replyName,
        $replyEmail
    );

    $confirmationBody = "Vielen Dank fuer Ihre Anfrage bei SV-Netzwerk.\n\n";
    $confirmationBody .= "Wir haben Ihre Angaben erhalten und melden uns zeitnah.\n\n";
    $confirmationBody .= "Formular: " . $config['subject'] . "\n";
    $confirmationBody .= "Zeitpunkt: " . date('d.m.Y H:i:s') . "\n";

    $sentToSender = sv_send_mail(
        $replyEmail,
        'Eingangsbestätigung: ' . $config['subject'],
        $confirmationBody,
        $from,
        'SV-Netzwerk',
        $from
    );

    if ($sentToTeam && $sentToSender) {
        sv_log_event($config['form_key'], 'Erfolgreich verarbeitet.');
        sv_redirect($successUrl);
    }

    sv_log_event($config['form_key'], 'Mailversand fehlgeschlagen.');
    sv_redirect($config['mail_error_url']);
}
