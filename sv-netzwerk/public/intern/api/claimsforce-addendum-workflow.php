<?php
declare(strict_types=1);
require_once __DIR__.'/claimsforce-addendum-check.php';
require_once __DIR__.'/claimsforce-addendum-rules.php';
require_once __DIR__.'/case-identity.php';
require_once __DIR__.'/case-search.php';
require_once __DIR__.'/profile-routing.php';
require_once __DIR__.'/../mcp/native-forms.php';

function cfaSchema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS claimsforce_addendum_orders (
        workflow_id VARCHAR(64) PRIMARY KEY,
        plan_json MEDIUMTEXT NOT NULL,
        revision INT UNSIGNED NOT NULL DEFAULT 0,
        job_id BIGINT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function cfaJson(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function cfaUuid(string $value): bool
{
    return preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $value) === 1;
}

function cfaOperationId(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20);
}

function cfaMeta(string $folder): array
{
    $s = ionosStorageDb()->prepare("SELECT id FROM items WHERE parent=? AND name='00_Falldaten.json' AND mime='application/json' AND verified=1 LIMIT 2");
    $s->execute([$folder]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? json_decode(ionosBytes($ids[0]), true, 512, JSON_THROW_ON_ERROR) : [];
}

function cfaCase(array $user, string $claim, string $insurer, string $profile): array
{
    if (!svnetCanAccessCaseProfile($user, $profile)) throw new InvalidArgumentException('Konto: Bearbeiterprofil ist nicht freigegeben.');
    $s = db()->prepare('SELECT folder_id,case_no FROM case_folder_owners WHERE '.caseSearchOwnerScope($user));
    $s->execute([':user_id'=>(int)$user['id'], ':user_email'=>(string)$user['email']]);
    $matches = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (caseNumberKey($row['case_no']) !== caseNumberKey($claim) || drStandalone($row['folder_id'])) continue;
        $folder = caseIdentityResolve($row['folder_id']);
        $meta = cfaMeta($folder);
        if (($meta['claimsforce_profile'] ?? '') === $profile && cfaUuid((string)($meta['claimsforce_claim_id'] ?? ''))) {
            $matches[$folder] = $meta;
        }
    }
    if (count($matches) !== 1) throw new InvalidArgumentException('Schadennummer / Konto: Keine eindeutige bestehende Claimsforce-Zuordnung im Portal.');
    $folder = (string)array_key_first($matches);
    requireCaseFolderAccess($folder, $user);
    $meta = $matches[$folder];
    $storedInsurer = trim((string)($meta['versicherer'] ?? ''));
    $claimNumber = trim((string)($meta['claimsforce_quelle']['claim']['insurerClaimId'] ?? $meta['schaden_nr'] ?? ''));
    if ($storedInsurer === '' || $claimNumber === '' || ($insurer !== '' && strcasecmp($storedInsurer, $insurer) !== 0)) {
        throw new InvalidArgumentException('Versicherer / Schadennummer: Angaben weichen von der Portalakte ab oder fehlen dort.');
    }
    return [$folder,$storedInsurer,['profile'=>$profile, 'caseId'=>$meta['claimsforce_claim_id'], 'claimNumber'=>$claimNumber]];
}

function cfaCandidates(array $row): array
{
    $meta = cfaMeta($row['folder_id']);
    $s = db()->prepare('SELECT a.plan_json FROM claimsforce_addendum_orders a JOIN claimsforce_workflow_jobs w USING(workflow_id)
        WHERE w.folder_id=? AND w.created_by=? AND w.id<? ORDER BY w.id DESC LIMIT 1');
    $s->execute([$row['folder_id'], $row['created_by'], $row['id']]);
    $prior = json_decode((string)$s->fetchColumn(), true);
    $old = array_column($prior['candidates'] ?? [], 'version', 'id');
    $files = [];
    foreach ($meta['claimsforce_file_versions'] ?? [] as $version) {
        [$id,$date,,$name] = array_pad(explode('|', (string)$version, 4), 4, '');
        if (!cfaUuid($id) || $name === '' || ($old[$id] ?? null) === $version || !preg_match('/rechnung|angebot|kostenvoranschlag|kva|protokoll|messung|aufma[ßs]|nachweis|zahlbrief|freigabe|foto|\.jpe?g$|\.png$/iu', $name)
            || preg_match('/\.docx?$/i', $name)) continue;
        $files[$id] = compact('id','name','version','date');
    }
    uasort($files, static fn($a,$b) => strcmp($b['date'], $a['date']));
    // Existing selections survive a refreshed proposal, including explicit removals.
    return array_slice($files, 0, 30, true);
}

function cfaState(array $row, bool $lock = false): array
{
    $s = db()->prepare('SELECT * FROM claimsforce_addendum_orders WHERE workflow_id=?'.($lock ? ' FOR UPDATE' : ''));
    $s->execute([$row['workflow_id']]);
    $state = $s->fetch();
    if (!$state) return ['revision'=>0, 'job_id'=>null, 'plan'=>[]];
    $state['plan'] = json_decode($state['plan_json'], true, 512, JSON_THROW_ON_ERROR);
    return $state;
}

function cfaWorkflowView(array $row): array
{
    $state = cfaState($row);
    $plan = $state['plan'];
    $messages = $plan ? [] : ['Auftrag: bestehenden Nachtrag im Prüfauftrag zuordnen.'];
    $status = $plan ? 'accepted' : 'correction_required';
    if (!empty($plan['ledger']['issues'])) $status = 'correction_required';
    $ms = null;
    if ($state['job_id'] !== null) {
        $q = db()->prepare('SELECT status,message,result_json FROM claimsforce_import_jobs WHERE id=? AND sync_mode=\'nativeform\'');
        $q->execute([$state['job_id']]);
        $job = $q->fetch();
        if ($job && in_array($job['status'], ['queued','running'], true)) $status = 'running';
        else {
            try {
                if (!$job || $job['status'] !== 'done') {
                    $messages[] = 'Rücklesen: '.($job['message'] ?? 'Browserauftrag fehlt.');
                } elseif (cfaRuleManifest($plan['rules']['route']) !== $plan['rules']['sources']) {
                    $messages[] = 'Regelstand: Drive-Quellen haben sich geändert. Vorlage aktualisieren und erneut prüfen.';
                } else {
                    $result = json_decode((string)$job['result_json'], true, 512, JSON_THROW_ON_ERROR);
                    $messages = cfaCheck($plan, $result);
                    $current = cfaState($row);
                    if ($current['revision'] !== $state['revision']) {
                        $messages[] = 'Auftrag: Der Prüfstand wurde zwischenzeitlich geändert.';
                    }
                    $ms = $result['durationMs'] ?? null;
                }
            } catch (Throwable $error) {
                $messages[] = 'Regelstand / Rücklesen: '.$error->getMessage();
            }
            $status = $messages ? 'correction_required' : 'completed';
        }
    }
    return ['workflow_id'=>$row['workflow_id'],'folder_id'=>$row['folder_id'],'claim_no'=>$row['claim_no'],'insurer'=>$row['insurer'],
        'profile'=>$row['profile'],'order_type'=>$row['order_type'],'documents'=>json_decode($row['documents_json'],true) ?: [],
        'instructions'=>$row['instructions'] ?? '', 'status'=>$status,'phase'=>$status === 'completed' ? 'ergebnis' : 'abschlusspruefung',
        'addendum'=>true,'plan'=>$plan,'draft'=>!empty($plan['body']) ? cfaTemplate($plan) : '',
        'revision'=>(int)$state['revision'],'queue_job_id'=>$state['job_id'] !== null ? (int)$state['job_id'] : null,
        'corrections'=>$messages,'duration_ms'=>$ms,'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']];
}

function cfaPrepare(array $row, array $current, array $in): array
{
    $plan = $current['plan'];
    if (!$plan) throw new InvalidArgumentException('Bitte den Altauftrag als neuen Nachtrags-Prüfauftrag erfassen.');
    $plan['rules'] = cfaRules($plan['rules']['route'], $plan['rules']);
    $plan['reportId'] = strtolower(trim((string)($in['report_id'] ?? '')));
    $plan['body'] = cfaText((string)($in['body'] ?? ''));
    if (!cfaUuid($plan['reportId']) || $plan['body'] === '' || strlen($plan['body']) > 24000) {
        throw new InvalidArgumentException('Nachtrag: Entwurfs-ID und Fachtext sind erforderlich.');
    }
    if (cfaInternal($plan['body'])) throw new InvalidArgumentException('Nachtragstext: interne SV-Kosten entfernen.');
    $contact = trim((string)($in['contact'] ?? ''));
    $reference = trim((string)($in['contact_evidence'] ?? ''));
    if ($contact !== '' && (!preg_match('/^(?:Frau|Herr) [\pL .’\x27-]{2,120}$/uD', $contact) || $reference === '')) {
        throw new InvalidArgumentException('Anrede: Bitte „Frau …“ oder „Herr …“ mit Aktenbeleg erfassen.');
    }
    $plan['contact'] = $contact;
    $plan['contactEvidence'] = $reference;
    $plan['salutation'] = str_starts_with($contact, 'Frau ') ? 'Sehr geehrte '.$contact.','
        : (str_starts_with($contact, 'Herr ') ? 'Sehr geehrter '.$contact.',' : 'Sehr geehrte Damen und Herren,');
    $fresh = cfaCandidates($row);
    $choices = $in['choices'] ?? null;
    if (!is_array($choices) || count($choices) > 100) throw new InvalidArgumentException('Anlagen: Auswahl bestätigen.');
    $attachments = array_column($plan['attachments'],null,'id');
    foreach ($choices as $id=>$include) {
        if (!isset($plan['candidates'][$id]) || !is_bool($include)) throw new InvalidArgumentException('Anlagen: unbekannte Auswahl.');
        if ($include) $attachments[$id] = $plan['candidates'][$id];
        else unset($attachments[$id]);
    }
    $plan['decisions'] = $choices + $plan['decisions'];
    $plan['attachments'] = array_values($attachments);
    $plan['candidates'] = $fresh + $plan['candidates'];
    $plan['forms'] = [];
    $plan['paymentFields'] = [];
    foreach (['SE','ZB'] as $kind) {
        if (!in_array($kind, json_decode($row['documents_json'], true), true)) continue;
        $id = strtolower(trim((string)($in['forms'][$kind] ?? '')));
        if (!cfaUuid($id) || in_array($id,$plan['forms'],true)) throw new InvalidArgumentException("$kind: eindeutige Entwurfs-ID erforderlich.");
        $plan['forms'][$kind] = $id;
        $roles = $kind === 'SE' ? ['paid','open','direct'] : ['paid','open'];
        foreach ($roles as $role) {
            $label = trim((string)($in['payment_fields'][$kind][$role] ?? ''));
            if ($label === '' || in_array($label,$plan['paymentFields'][$kind] ?? [],true)) {
                throw new InvalidArgumentException("$kind: eindeutige Feldbeschriftung für jeden beauftragten Zahlungswert erfassen.");
            }
            $plan['paymentFields'][$kind][$role] = $label;
        }
    }
    $plan['payments'] = $in['payments'] ?? [];
    if (!is_array($plan['payments']) || count($plan['payments']) > 100) throw new InvalidArgumentException('Zahlungsabgleich: ungültige Liste.');
    $plan['basisInput'] = trim((string)($in['basis'] ?? ''));
    $plan['ledger'] = $plan['forms'] || $plan['payments'] || $plan['basisInput'] !== ''
        ? cfaLedger($plan['payments'], cfaCents($plan['basisInput'])) : null;
    unset($plan['operationId']);
    if (strlen(cfaJson($plan)) > 200000) throw new InvalidArgumentException('Nachtragsauftrag ist zu umfangreich.');
    return $plan;
}

function cfaUpdate(array $row, array $user, array $in, string $action): void
{
    cfaSchema();
    mcpNativeSchema();
    $current = cfaState($row);
    if ((int)$current['revision'] !== ($in['revision'] ?? null)) apiError(409,'Der Nachtragsauftrag wurde inzwischen geändert. Bitte aktualisieren.');
    $plan = $action === 'prepare_addendum' ? cfaPrepare($row, $current, $in) : $current['plan'];
    if (!$plan || empty($plan['reportId']) || empty($plan['body'])) apiError(409,'Zuerst Nachtragsvorlage und Anlagen speichern.');
    if ($action === 'check_addendum') {
        if (!empty($plan['ledger']['issues'])) apiError(409,implode(' ', $plan['ledger']['issues']));
        [$folder,,$binding] = cfaCase($user,$row['claim_no'],$row['insurer'],$plan['binding']['profile']);
        if ($folder !== $row['folder_id'] || $binding !== $plan['binding']) apiError(409,'Schadennummer / Konto: Fallzuordnung wurde geändert.');
        if (cfaRuleManifest($plan['rules']['route']) !== $plan['rules']['sources']) apiError(409,'Regelstand geändert. Vorlage aktualisieren und anschließend erneut prüfen.');
    }
    db()->beginTransaction();
    try {
        $locked = cfaState($row,true);
        if ($locked['revision'] !== $current['revision']) throw new InvalidArgumentException('Nachtragsauftrag wurde gleichzeitig geändert. Bitte aktualisieren.');
        if ($locked['job_id'] !== null) {
            $q = db()->prepare("SELECT status FROM claimsforce_import_jobs WHERE id=? FOR UPDATE");
            $q->execute([$locked['job_id']]);
            if (in_array($q->fetchColumn(), ['queued','running'],true)) throw new InvalidArgumentException('Die Abschlussprüfung läuft bereits.');
        }
        $jobId = null;
        if ($action === 'check_addendum') {
            $plan['operationId'] = $operation = cfaOperationId();
            $profile = $plan['binding']['profile'];
            $request = cfaJson(['action'=>'check_addendum','operationId'=>$operation,'reportId'=>$plan['reportId'],
                'forms'=>$plan['forms'], ...$plan['binding']]);
            $q = db()->prepare("INSERT INTO claimsforce_import_jobs(profile,status,requested_by,message,phase,sync_mode,claim_number,source_profiles_json,created_at)
                VALUES(?,'queued',?,'Nachtragsentwürfe gezielt rücklesen','CF-NACH-QUEUED','nativeform',?,?,NOW())");
            $q->execute([$profile,$user['email'],$operation,cfaJson([$profile])]);
            $jobId = (int)db()->lastInsertId();
            $q = db()->prepare('INSERT INTO claimsforce_native_operations(operation_id,user_id,folder_id,profile,request_hash,request_json,job_id,created_at)
                VALUES(?,?,?,?,?,?,?,NOW())');
            $q->execute([$operation,$user['id'],$row['folder_id'],$profile,hash('sha256',$request),$request,$jobId]);
        }
        $q = db()->prepare('UPDATE claimsforce_addendum_orders SET plan_json=?,job_id=?,revision=revision+1 WHERE workflow_id=?');
        $q->execute([cfaJson($plan),$jobId,$row['workflow_id']]);
        $q = db()->prepare('UPDATE claimsforce_workflow_jobs SET updated_at=NOW() WHERE workflow_id=?');
        $q->execute([$row['workflow_id']]);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}
