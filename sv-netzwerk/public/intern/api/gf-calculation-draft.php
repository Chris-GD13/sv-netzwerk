<?php
declare(strict_types=1);

function gfCalculationNumber(mixed $value): float
{
    if (is_int($value) || is_float($value)) return (float)$value;
    $text = trim((string)$value);
    if ($text === '') return 0.0;
    $text = preg_replace('/[^0-9,.-]+/u', '', $text) ?? '';
    if (str_contains($text, ',') && str_contains($text, '.')) {
        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
    } elseif (str_contains($text, ',')) {
        $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) ? (float)$text : 0.0;
}

function gfCalculationDraftState(array $result, array $meta, string $instructions): array
{
    $rawItems = is_array($result['items'] ?? null) ? $result['items'] : [];
    $lines = [];
    $errors = [];
    foreach ($rawItems as $index => $item) {
        if (!is_array($item)) continue;
        $line = [
            'position_code' => trim((string)($item['position_code'] ?? '')),
            'description' => trim((string)($item['description'] ?? '')),
            'quantity' => gfCalculationNumber($item['quantity'] ?? 0),
            'unit' => trim((string)($item['unit'] ?? '')),
            'unit_price' => gfCalculationNumber($item['unit_price'] ?? 0),
            'regional_factor' => gfCalculationNumber($item['regional_factor'] ?? 1),
            'source_name' => trim((string)($item['source_name'] ?? '')),
            'source_page' => trim((string)($item['source_page'] ?? '')),
        ];
        if ($line['regional_factor'] <= 0) $line['regional_factor'] = 1.0;
        $number = $index + 1;
        if ($line['description'] === '') $errors[] = 'Position '.$number.': Leistungsbeschreibung fehlt.';
        if ($line['quantity'] <= 0) $errors[] = 'Position '.$number.': belegte Menge fehlt.';
        if ($line['unit'] === '') $errors[] = 'Position '.$number.': Einheit fehlt.';
        if ($line['unit_price'] <= 0) $errors[] = 'Position '.$number.': belastbarer Einheitspreis fehlt.';
        if ($line['source_name'] === '') $errors[] = 'Position '.$number.': Preis- oder Mengenquelle fehlt.';
        $lines[] = $line;
    }
    if (!$lines) $errors[] = 'Keine belastbar kalkulierbare Position erkannt.';
    if (count($lines) > 200) $errors[] = 'Mehr als 200 Kalkulationspositionen sind nicht zulässig.';
    if ($errors) throw new RuntimeException('Kalkulations-QS-Sperre: '.implode(' ', $errors));

    $notes = ['KI-Erstentwurf – vor Verwendung sämtliche Positionen, Mengen und Preise fachlich prüfen.'];
    $summary = trim((string)($result['summary'] ?? ''));
    if ($summary !== '') $notes[] = $summary;
    foreach (['assumptions' => 'Annahmen', 'open_points' => 'Offene Punkte'] as $field => $label) {
        $values = is_array($result[$field] ?? null) ? array_values(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            $result[$field]
        ))) : [];
        if ($values) $notes[] = $label.': '.implode('; ', $values);
    }
    $userInstruction = trim($instructions);
    if ($userInstruction !== '') $notes[] = 'Berücksichtigte Vorgabe: '.$userInstruction;

    return [
        'query' => '',
        'location' => trim(implode(' ', array_filter([
            (string)($meta['schaden_plz'] ?? $meta['plz'] ?? ''),
            (string)($meta['schaden_ort'] ?? $meta['ort'] ?? ''),
        ]))),
        'qty' => '',
        'unit' => '',
        'level' => 'mid',
        'vat' => (string)max(0, gfCalculationNumber($result['vat'] ?? 19)),
        'note' => implode("\n", $notes),
        'lines' => $lines,
        'pendingQueries' => [],
        'updatedAt' => gmdate('c'),
    ];
}

function gfSaveCalculationDraft(string $draftKey, string $folderId, array $meta, array $state, string $user): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS bki_calculation_drafts(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        draft_key VARCHAR(255) NOT NULL,
        folder_id VARCHAR(190) NULL,
        case_no VARCHAR(190) NULL,
        damage_type VARCHAR(190) NULL,
        object_name VARCHAR(500) NULL,
        title VARCHAR(500) NULL,
        state_json LONGTEXT NOT NULL,
        updated_by VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_bki_draft_user_key(updated_by,draft_key),
        INDEX idx_bki_draft_folder(folder_id),
        INDEX idx_bki_draft_updated(updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $caseNo = trim((string)($meta['schaden_nr'] ?? ''));
    $damageType = trim((string)($meta['schadenart'] ?? ''));
    $objectName = trim((string)($meta['vn_objekt'] ?? ''));
    $title = 'KI-Kalkulation'.($caseNo !== '' ? ' – Schaden '.$caseNo : '');
    $statement = db()->prepare('INSERT INTO bki_calculation_drafts(draft_key,folder_id,case_no,damage_type,object_name,title,state_json,updated_by,created_at,updated_at) VALUES(:key,:folder,:case_no,:damage,:object_name,:title,:state,:user,NOW(),NOW()) ON DUPLICATE KEY UPDATE folder_id=VALUES(folder_id),case_no=VALUES(case_no),damage_type=VALUES(damage_type),object_name=VALUES(object_name),title=VALUES(title),state_json=VALUES(state_json),updated_at=NOW()');
    $statement->execute([
        ':key' => $draftKey,
        ':folder' => $folderId,
        ':case_no' => $caseNo !== '' ? $caseNo : null,
        ':damage' => $damageType !== '' ? $damageType : null,
        ':object_name' => $objectName !== '' ? $objectName : null,
        ':title' => $title,
        ':state' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ':user' => $user,
    ]);
}

function gfCalculationJobOwner(int $jobId): string
{
    $statement = db()->prepare('SELECT created_by FROM gf_ai_jobs WHERE id=:id LIMIT 1');
    $statement->execute([':id' => $jobId]);
    $owner = trim((string)($statement->fetchColumn() ?: ''));
    if ($owner === '') throw new RuntimeException('Kalkulationsentwurf konnte keinem Portalbenutzer zugeordnet werden.');
    return $owner;
}

function gfCalculationDraftLink(string $draftKey): string
{
    return '/intern/kalkulation/?draft_key='.rawurlencode($draftKey);
}
