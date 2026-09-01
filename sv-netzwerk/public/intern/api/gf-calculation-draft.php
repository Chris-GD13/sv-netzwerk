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
    if (count($lines) > 200) $errors[] = 'Mehr als 200 Kalkulationspositionen sind nicht zulässig.';
    if ($errors) throw new RuntimeException('Kalkulations-QS-Sperre: '.implode(' ', $errors));

    $notes = ['KI-Erstentwurf – vor Verwendung sämtliche Positionen, Mengen und Preise fachlich prüfen.'];
    $requiresManualCompletion = !$lines;
    if ($requiresManualCompletion) {
        $notes[] = 'Aus den belegten Aktenangaben konnte keine vollständig kalkulierbare Position mit Menge, Einheitspreis und Quelle abgeleitet werden. Der leere Entwurf wurde deshalb zur manuellen Ergänzung auf der Kalkulationsseite angelegt; es wurden keine Positionen oder Preise erfunden.';
    }
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
        'requiresManualCompletion' => $requiresManualCompletion,
        'updatedAt' => gmdate('c'),
    ];
}

function gfCalculationInputPart(array $reference): array
{
    $fileId = trim((string)($reference['file_id'] ?? ''));
    $mime = mb_strtolower(trim((string)($reference['mime'] ?? '')), 'UTF-8');
    if (str_starts_with($mime, 'image/')) {
        return ['type' => 'input_image', 'file_id' => $fileId, 'detail' => 'high'];
    }
    return ['type' => 'input_file', 'file_id' => $fileId];
}

function gfCalculationCompactValue(mixed $value, int $maxStringChars = 1800): mixed
{
    if (is_string($value)) {
        return mb_strlen($value, 'UTF-8') > $maxStringChars
            ? mb_substr($value, 0, $maxStringChars, 'UTF-8').'…'
            : $value;
    }
    if (!is_array($value)) return $value;
    $result = [];
    foreach ($value as $key => $item) $result[$key] = gfCalculationCompactValue($item, $maxStringChars);
    return $result;
}

function gfCalculationEvidenceText(array $groups, int $maxChars = 85000): string
{
    $maxChars = max(12000, min(120000, $maxChars));
    $files = [];
    foreach ($groups as $group) {
        foreach ((is_array($group['files'] ?? null) ? $group['files'] : []) as $file) {
            if (!is_array($file)) continue;
            $name = trim((string)($file['name'] ?? 'Unterlage'));
            $kind = trim((string)($file['document_type'] ?? 'Dokument'));
            $haystack = mb_strtolower($name.' '.$kind, 'UTF-8');
            $score = 0;
            foreach (['aufmaß', 'aufmass', 'kostenvoranschlag', 'kva', 'angebot', 'rechnung', 'schaden', 'foto', 'bild', 'polycam', 'mess'] as $needle) {
                if (str_contains($haystack, $needle)) $score += 10;
            }
            if (!empty($file['measurements'])) $score += 30;
            if (!empty($file['amounts'])) $score += 25;
            if (!empty($file['visual_findings'])) $score += 30;
            $files[] = ['score' => $score, 'file' => $file];
        }
    }
    usort($files, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);

    $parts = [];
    $used = 0;
    foreach ($files as $entry) {
        $file = $entry['file'];
        $selected = [
            'name' => trim((string)($file['name'] ?? 'Unterlage')),
            'document_type' => trim((string)($file['document_type'] ?? 'Dokument')),
            'facts' => array_slice(is_array($file['facts'] ?? null) ? $file['facts'] : [], 0, 80),
            'amounts' => array_slice(is_array($file['amounts'] ?? null) ? $file['amounts'] : [], 0, 100),
            'measurements' => array_slice(is_array($file['measurements'] ?? null) ? $file['measurements'] : [], 0, 100),
            'visual_findings' => array_slice(is_array($file['visual_findings'] ?? null) ? $file['visual_findings'] : [], 0, 80),
            'open_points' => array_slice(is_array($file['open_points'] ?? null) ? $file['open_points'] : [], 0, 40),
        ];
        $json = json_encode(gfCalculationCompactValue($selected), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) continue;
        if (mb_strlen($json, 'UTF-8') > 18000) {
            $selected['facts'] = array_slice($selected['facts'], 0, 30);
            $selected['amounts'] = array_slice($selected['amounts'], 0, 50);
            $selected['measurements'] = array_slice($selected['measurements'], 0, 50);
            $selected['visual_findings'] = array_slice($selected['visual_findings'], 0, 40);
            $selected['open_points'] = array_slice($selected['open_points'], 0, 20);
            $json = json_encode(gfCalculationCompactValue($selected, 900), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (!is_string($json)) continue;
        $length = mb_strlen($json, 'UTF-8') + 1;
        if ($used + $length > $maxChars) continue;
        $parts[] = $json;
        $used += $length;
    }
    if (!$parts) throw new RuntimeException('Für die Kalkulation konnten keine verdichteten Fallinformationen erstellt werden.');
    return implode("\n", $parts);
}

function gfCalculationOpenAIRequest(array $payload): array
{
    $apiKey = trim(env('OPENAI_API_KEY', ''));
    if ($apiKey === '') throw new RuntimeException('OpenAI API-Key ist nicht konfiguriert.');
    $response = gfHttp(
        'POST',
        'https://api.openai.com/v1/responses',
        ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        false,
        600
    );
    $decoded = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = trim((string)($decoded['error']['message'] ?? ''));
        throw new RuntimeException($message !== '' ? $message : 'KI-Kalkulation konnte nicht erzeugt werden.');
    }
    $text = trim((string)($decoded['output_text'] ?? ''));
    if ($text === '') {
        foreach (($decoded['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') continue;
            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text') $text .= (string)($content['text'] ?? '');
            }
        }
        $text = trim($text);
    }
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match)) $text = $match[1];
    $result = json_decode($text, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($result)) throw new RuntimeException('KI-Kalkulation war unvollständig oder kein gültiges JSON.');
    return $result;
}

function gfCalculationVectorStore(array $bkiReferences): string
{
    $apiKey = trim(env('OPENAI_API_KEY', ''));
    if ($apiKey === '') throw new RuntimeException('OpenAI API-Key ist nicht konfiguriert.');
    $configured = trim(env('OPENAI_BKI_VECTOR_STORE_ID', ''));
    $cached = json_decode(gfSettingGet('openai_bki_vector_store', '{}'), true);
    $storeId = $configured !== '' ? $configured : trim((string)($cached['vector_store_id'] ?? ''));
    if ($storeId !== '') return $storeId;

    $fileIds = array_values(array_filter(array_map(
        static fn(array $reference): string => trim((string)($reference['file_id'] ?? '')),
        $bkiReferences
    )));
    if (count($fileIds) < 2) throw new RuntimeException('Die beiden BKI-Quellen stehen für die Kalkulation nicht vollständig bereit.');
    $headers = ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey];
    $created = gfHttp('POST', 'https://api.openai.com/v1/vector_stores', $headers, json_encode([
        'name' => 'SV-Netzwerk BKI Altbau 2026',
        'expires_after' => ['anchor' => 'last_active_at', 'days' => 30],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, 180);
    $createdJson = json_decode($created['body'], true);
    $storeId = trim((string)($createdJson['id'] ?? ''));
    if ($created['status'] < 200 || $created['status'] >= 300 || $storeId === '') throw new RuntimeException('BKI-Suchindex konnte nicht angelegt werden.');
    $batch = gfHttp('POST', 'https://api.openai.com/v1/vector_stores/'.rawurlencode($storeId).'/file_batches', $headers, json_encode([
        'file_ids' => $fileIds,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, 180);
    $batchJson = json_decode($batch['body'], true);
    $batchId = trim((string)($batchJson['id'] ?? ''));
    if ($batch['status'] < 200 || $batch['status'] >= 300 || $batchId === '') throw new RuntimeException('BKI-Dateien konnten dem Suchindex nicht zugeordnet werden.');
    $deadline = time() + 300;
    do {
        $state = gfHttp('GET', 'https://api.openai.com/v1/vector_stores/'.rawurlencode($storeId).'/file_batches/'.rawurlencode($batchId), ['Authorization: Bearer '.$apiKey], null, false, 90);
        $stateJson = json_decode($state['body'], true);
        $status = (string)($stateJson['status'] ?? '');
        if ($status === 'completed') break;
        if (in_array($status, ['failed', 'cancelled', 'expired'], true)) throw new RuntimeException('BKI-Suchindex konnte nicht fertiggestellt werden.');
        usleep(800000);
    } while (time() < $deadline);
    if (($status ?? '') !== 'completed') throw new RuntimeException('BKI-Suchindex wird noch aufgebaut. Bitte den Auftrag erneut starten.');
    gfSettingSet('openai_bki_vector_store', json_encode(['vector_store_id' => $storeId, 'created_at' => date(DATE_ATOM)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $storeId;
}

function gfGenerateCalculation(array $caseEvidence, array $meta, string $instructions, array $bkiReferences): array
{
    $storeId = gfCalculationVectorStore($bkiReferences);
    $system = 'Du erstellst für einen deutschen Sachverständigen einen fachlich zu prüfenden Kalkulationsentwurf. Nutze für Einheitspreise ausschließlich die über die Dateisuche zugänglichen lizenzierten BKI-Unterlagen oder eindeutig belegte KVA-/Rechnungspositionen. Durchsuche BKI gezielt je erforderlicher Leistung. Erfinde niemals Mengen, Maße, Preise, Positionen, Seiten oder Quellen. Fotos sind Tatsachenquellen für sichtbare Schäden und Bauteile; verdeckte Bereiche und nicht sichtbare Maße dürfen daraus nicht abgeleitet werden. Vorgaben aus dem Arbeitsauftrag sind verbindlich. Antworte ausschließlich als JSON {"summary":"...","items":[{"position_code":"BKI-Position oder leer","description":"konkrete Leistung","quantity":1.0,"unit":"m²|m|St|Std|psch","unit_price":123.45,"regional_factor":1.0,"source_name":"exakter Dateiname oder BKI-Quelle","source_page":"Seite"}],"vat":19,"assumptions":["..."],"open_points":["..."]}.';
    $limits = [85000, 42000];
    $lastError = null;
    foreach ($limits as $attempt => $limit) {
        $evidence = gfCalculationEvidenceText($caseEvidence, $limit);
        $input = "Falldaten:\n".json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nArbeitsauftrag:\n".$instructions
            ."\n\nPriorisierter, dateibezogener Evidenzbestand einschließlich Bildauswertung:\n".$evidence;
        try {
            return gfCalculationOpenAIRequest([
                'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
                'instructions' => $system,
                'input' => $input,
                'tools' => [[
                    'type' => 'file_search',
                    'vector_store_ids' => [$storeId],
                    'max_num_results' => $attempt === 0 ? 20 : 12,
                ]],
                'max_output_tokens' => 12000,
            ]);
        } catch (RuntimeException $error) {
            $lastError = $error;
            $message = mb_strtolower($error->getMessage(), 'UTF-8');
            if ($attempt === 0 && (str_contains($message, 'context window') || str_contains($message, 'input exceeds'))) continue;
            throw $error;
        }
    }
    throw $lastError ?? new RuntimeException('KI-Kalkulation konnte nicht erzeugt werden.');
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
