<?php
declare(strict_types=1);

function cfaOrder(string $type, array $documents): bool
{
    return (bool)preg_grep('/^(?:Nachtrag(?:\s*\/\s*Stellungnahme)?|nachtrag_stellungnahme)$/iu',
        array_map(static fn($value) => trim((string)$value), [$type, ...$documents]));
}

function cfaText(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    return trim(implode("\n", array_map('trim', explode("\n", $text))));
}

function cfaInternal(string $text): bool
{
    return preg_match('/\b(?:Deutsche[\s-]+Assist|Regulierer[\s-]*kosten|interne[\s-]+SV[\s-]*Kosten)\b/iu', $text) === 1;
}

function cfaMey(string $text): bool
{
    return preg_match('/\bMey[\s-]*Generalbau\b/iu', $text) === 1;
}

function cfaCents(mixed $input): ?int
{
    $value = trim(str_replace("\u{00A0}", ' ', (string)$input));
    $value = trim(preg_replace('/\s*(?:EUR|€)$/u', '', $value) ?? '');
    if (!preg_match('/^(-?)(\d{1,10}|\d{1,3}(?:\.\d{3}){1,3})(?:,(\d{2}))?$/D', $value, $m)) return null;
    if (str_contains($m[2], '.') && !isset($m[3])) return null;
    return ($m[1] === '-' ? -1 : 1) * ((int)str_replace('.', '', $m[2]) * 100 + (int)($m[3] ?? 0));
}

function cfaMoney(int $cents): string
{
    $cents = abs($cents);
    return intdiv($cents, 100).','.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT).' EUR';
}

function cfaLedger(array $payments, ?int $basis): array
{
    $issues = [];
    $paid = 0;
    $open = 0;
    $external = [];
    $byRecipient = ['paid_vn'=>0,'paid_partner'=>0,'open_vn'=>0,'open_partner'=>0];
    foreach ($payments as $index => $row) {
        $field = 'Zahlung '.($index + 1);
        if (!is_array($row)) { $issues[] = "$field: Belegangaben fehlen."; continue; }
        $recipient = trim((string)($row['recipient'] ?? ''));
        $category = (string)($row['category'] ?? '');
        $status = (string)($row['status'] ?? '');
        $date = (string)($row['date'] ?? '');
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $amount = cfaCents($row['amount'] ?? '');
        if ($recipient === '' || $amount === null || $amount <= 0 || !$timestamp || $timestamp->format('Y-m-d') !== $date
            || !in_array($category, ['vn', 'partner', 'internal'], true) || !in_array($status, ['paid', 'open'], true)
            || trim((string)($row['evidence'] ?? '')) === '') {
            $issues[] = "$field: Datum, Empfänger, Kategorie, Status, Betrag und Beleg prüfen.";
            continue;
        }
        if ($category === 'internal' || cfaInternal($recipient)) continue;
        if (cfaMey($recipient) && $category !== 'partner') {
            $issues[] = "$field: Mey Generalbau gehört bei Direktzahlung zu den Partnerfirmen.";
            continue;
        }
        $key = json_encode([$date, $recipient, $category, $status, $amount, $row['evidence']], JSON_THROW_ON_ERROR);
        if (isset($external[$key])) { $issues[] = "$field: Doppelter Zahlungsposten."; continue; }
        $external[$key] = compact('date', 'recipient', 'category', 'status', 'amount');
        $byRecipient[$status.'_'.$category] += $amount;
        if ($status === 'paid') $paid += $amount;
        else $open += $amount;
    }
    if ($basis === null || $basis < 0) $issues[] = 'Schadenbetrag: belegten Ausgangsbetrag erfassen.';
    elseif ($basis - $paid !== $open) $issues[] = 'Offene Schlusszahlung: Belegte Zahlungen und offener Betrag stimmen mit dem Schadenbetrag nicht überein.';
    return ['issues'=>$issues, 'paid'=>$paid, 'open'=>$open, 'basis'=>$basis, 'rows'=>array_values($external), 'byRecipient'=>$byRecipient];
}

function cfaNativeText(string $value): string
{
    if (!preg_match('/<(?:p|div|br|ul|ol|span)\b/i', $value)) return cfaText($value);
    $value = preg_replace('/(?:<br\s*\/?\s*>|<\/(?:li|p|div|h[1-6])>)/i', "\n", $value) ?? '';
    return cfaText(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function cfaEquivalentText(string $value): string
{
    return preg_replace('/\s+/u', ' ', $value) ?? '';
}

function cfaPaymentText(array $ledger): string
{
    $lines = ['Schadenbetrag gemäß Abrechnung: '.cfaMoney($ledger['basis']).'.'];
    foreach (['paid'=>'Bereits geleistete Zahlungen', 'open'=>'Offene Schlusszahlung'] as $status=>$label) {
        $lines[] = $label.': '.cfaMoney($ledger[$status]).'.';
        foreach ($ledger['rows'] as $payment) {
            if ($payment['status'] !== $status) continue;
            $date = (new DateTimeImmutable($payment['date']))->format('d.m.Y');
            $category = $payment['category'] === 'vn' ? 'VN' : 'Partnerfirma';
            $lines[] = "$date · ".$payment['recipient']." ($category) · ".cfaMoney($payment['amount']);
        }
    }
    return implode("\n", $lines);
}

function cfaTemplate(array $plan): string
{
    return $plan['salutation']."\n\n".trim($plan['body'])
        .($plan['ledger'] && !$plan['ledger']['issues'] ? "\n\n".cfaPaymentText($plan['ledger']) : '')
        ."\n\n".$plan['rules']['sender'];
}

function cfaAttachmentKey(array $file): string
{
    return (string)($file['id'] ?? '');
}

function cfaAttachmentIssues(array $expected, mixed $actual): array
{
    if (!is_array($actual)) return ['Anlagen: gespeicherte Auswahl konnte nicht gelesen werden.'];
    $remaining = [];
    foreach ($expected as $file) $remaining[cfaAttachmentKey($file)] = $file;
    $issues = [];
    foreach ($actual as $file) {
        if (!is_array($file)) { $issues[] = 'Anlagen: unbekannter Eintrag.'; continue; }
        $key = cfaAttachmentKey($file);
        $name = $file['name'] ?? $key;
        if ($key === '' || !isset($remaining[$key])) {
            $issues[] = "Anlagen: unerwartete Anlage $name.";
            continue;
        }
        $want = $remaining[$key];
        if (($want['version'] ?? '') !== '' && $want['version'] !== ($file['version'] ?? null)) {
            $issues[] = 'Anlagen: falsche oder nicht belegte Fassung von '.$want['name'].'.';
        }
        unset($remaining[$key]);
    }
    foreach ($remaining as $file) $issues[] = 'Anlagen: '.$file['name'].' fehlt.';
    return $issues;
}

function cfaCheck(array $plan, array $result): array
{
    if (($result['source'] ?? '') !== 'claimsforce-native-addendum' || ($result['readOnly'] ?? null) !== true
        || ($result['operationId'] ?? '') !== $plan['operationId']) {
        return ['Rücklesen: unabhängiger Browsernachweis fehlt.'];
    }
    $issues = [];
    foreach (['caseId'=>'Claimsforce-Fall', 'claimNumber'=>'Schadennummer', 'profile'=>'Konto'] as $key=>$label) {
        if (($result[$key] ?? '') !== $plan['binding'][$key]) $issues[] = "$label: Zuordnung weicht vom Auftrag ab.";
    }
    $report = $result['report'] ?? [];
    if (($report['id'] ?? null) !== $plan['reportId'] || ($report['status'] ?? '') !== 'draft') {
        $issues[] = 'Nachtrag: der beauftragte gespeicherte Entwurf fehlt.';
    }
    $text = cfaNativeText((string)($report['text'] ?? ''));
    if (!str_starts_with($text, $plan['salutation']."\n")) $issues[] = 'Anrede: förmliche Anrede stimmt nicht mit dem Auftrag überein.';
    if (!str_ends_with(cfaEquivalentText($text), ' '.cfaEquivalentText($plan['rules']['sender']))) $issues[] = 'Absender: aktuelle Grußformel oder vollständiger Absenderblock fehlt.';
    if (cfaInternal($text)) $issues[] = 'Nachtrag: interne SV-Kosten im Entwurf.';
    if (cfaEquivalentText($text) !== cfaEquivalentText(cfaTemplate($plan))) $issues[] = 'Nachtragstext: gespeicherter Inhalt weicht von der Auftragsvorlage ab.';
    $issues = array_merge($issues, cfaAttachmentIssues($plan['attachments'], $report['attachments'] ?? null));

    foreach ($plan['forms'] as $kind=>$id) {
        $form = $result['forms'][$kind] ?? [];
        if (($form['formId'] ?? '') !== $id || ($form['status'] ?? '') !== 'draft' || ($form['dirty'] ?? null) !== false
            || ($form['claimNumber'] ?? '') !== $plan['binding']['claimNumber'] || ($form['profile'] ?? '') !== $plan['binding']['profile']
            || ($form['caseId'] ?? '') !== $plan['binding']['caseId']) {
            $issues[] = "$kind: ".($form['error'] ?? 'der beauftragte gespeicherte Formularentwurf fehlt.');
            continue;
        }
        $fields = $form['fields'] ?? [];
        $expected = $plan['paymentFields'][$kind] ?? [];
        $ledger = $plan['ledger'];
        $groups = $ledger['byRecipient'];
        $totals = $kind === 'SE'
            ? ['paid'=>$groups['paid_vn'], 'open'=>$groups['open_vn'], 'direct'=>$groups['paid_partner']+$groups['open_partner']]
            : ['paid'=>$ledger['paid'], 'open'=>$ledger['open']];
        foreach ($fields as $field) {
            $label = (string)($field['label'] ?? '');
            $value = (string)($field['value'] ?? '');
            if (cfaInternal($value)) $issues[] = "$kind / $label: interne SV-Kosten.";
            if ($kind === 'ZB' && cfaMey($value) && !preg_match('/(?:Partner|Direktzahlung|Abtretung)/iu', $label)) {
                $issues[] = "$kind / $label: Mey Generalbau gehört in den Partnerblock.";
            }
            if ($kind === 'ZB' && preg_match('/Versicherungsnehmer|\bVN\b/iu', $label)) {
                foreach ($plan['ledger']['rows'] as $payment) {
                    if ($payment['category'] === 'partner' && mb_stripos($value, $payment['recipient']) !== false) {
                        $issues[] = "$kind / $label: ".$payment['recipient'].' ist als Partnerfirma erfasst.';
                    }
                }
            }
        }
        foreach ($expected as $role=>$label) {
            $found = array_values(array_filter($fields, static fn($f) => ($f['label'] ?? '') === $label));
            if (count($found) !== 1 || cfaCents($found[0]['value'] ?? '') !== $totals[$role]) {
                $issues[] = "$kind / $label: Betrag stimmt nicht mit dem Zahlungsabgleich überein.";
            }
        }
        if ($kind === 'ZB') {
            foreach ($ledger['rows'] as $payment) {
                $recipient = $payment['recipient'];
                $isVn = $payment['category'] === 'vn';
                $section = $isVn ? '/Versicherungsnehmer|\bVN\b/iu' : '/Partner|Direktzahlung|Abtretung/iu';
                $matches = array_filter($fields, static fn($f) =>
                    preg_match($section, (string)($f['label'] ?? '')) && mb_stripos((string)($f['value'] ?? ''), $recipient) !== false);
                if (!$matches) {
                    $block = $isVn ? 'VN-Block' : 'Partnerblock';
                    $issues[] = "ZB / $block: Zahlungsempfänger $recipient fehlt.";
                }
            }
        }
    }
    return array_values(array_unique($issues));
}
