<?php
declare(strict_types=1);

function kussResourceAttributes(array $resource): array { return is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource; }
function kussResourceId(array $resource): string { return trim((string)($resource['id'] ?? $resource['uuid'] ?? '')); }
function kussRelationIds(array $resource, string $name): array {
    $data = $resource['relationships'][$name]['data'] ?? [];
    if (isset($data['id'])) $data = [$data];
    return array_values(array_filter(array_map(static fn($v): string => is_array($v) ? kussResourceId($v) : (string)$v, is_array($data) ? $data : [])));
}
function kussImportJobMeta(array $job, array $detail): array {
    $a = array_merge(kussResourceAttributes($job), kussResourceAttributes($detail));
    $case = trim((string)($a['case'] ?? $a['case-number'] ?? $a['case_number'] ?? ''));
    $subject = trim((string)($a['subject'] ?? $a['object'] ?? ''));
    $field = trim((string)($a['field'] ?? $a['damage-type'] ?? $a['damage_type'] ?? ''));
    return ['schaden_nr' => $case, 'vn_objekt' => $subject, 'schadenart' => $field,
        'versicherung' => trim((string)($a['insurance-company-translated'] ?? $a['insurance-company'] ?? '')),
        'versicherungsschein_nr' => trim((string)($a['contract-number'] ?? $a['contract_number'] ?? '')),
        'kuss_job_id' => kussResourceId($detail) ?: kussResourceId($job),
        'kuss_reference' => trim((string)($a['kuss-reference'] ?? $a['reference'] ?? '')),
        'kuss_state' => trim((string)($a['state'] ?? '')), 'kuss_source' => 'KUSS Service-Portal', 'kuss_last_synced_at' => gmdate('c')];
}
function kussImportMessageBytes(array $message): string {
    $a = kussResourceAttributes($message); $content = (string)($a['content'] ?? $a['body'] ?? '');
    $author = (string)($a['author-name'] ?? $a['author'] ?? 'KUSS'); $created = (string)($a['created-at'] ?? $a['created_at'] ?? '');
    $safeContent = nl2br(htmlspecialchars(strip_tags($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    return '<!doctype html><meta charset="utf-8"><title>KUSS-Nachricht</title><h1>KUSS-Nachricht</h1><p><strong>Von:</strong> '.htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<br><strong>Datum:</strong> '.htmlspecialchars($created, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p><hr>'.$safeContent;
}
function kussImportAttachmentIds(array $message, array $detail, array $caseFile = []): array { return array_values(array_unique(array_merge(kussRelationIds($message, 'attachments'), kussRelationIds($detail, 'attachments'), kussRelationIds($detail, 'documents'), kussRelationIds($caseFile, 'attachments'), kussRelationIds($caseFile, 'documents')))); }
function kussImportJobs(array $user, string $token, int $maxPages = 2): array {
    $maxPages = min(20, max(1, $maxPages)); $summary = ['jobs_seen'=>0, 'cases_created'=>0, 'cases_updated'=>0, 'messages_imported'=>0, 'attachments_imported'=>0, 'duplicates'=>0, 'skipped'=>[], 'errors'=>[]];
    for ($page = 1; $page <= $maxPages; $page++) {
        $listing = kussRequestJobs($token, kussJobsQuery(['page' => $page]));
        $jobs = is_array($listing['data'] ?? null) ? $listing['data'] : []; if (!$jobs) break;
        foreach ($jobs as $job) {
            if (!is_array($job)) continue; $summary['jobs_seen']++; $jobId = kussResourceId($job); if ($jobId === '') continue;
            try {
                $detail = kussRequestJob($token, $jobId); $resource = is_array($detail['data'] ?? null) ? $detail['data'] : $detail; $meta = kussImportJobMeta($job, $resource);
                if ($meta['schaden_nr'] === '') { $summary['skipped'][] = ['job_id'=>$jobId, 'reason'=>'Keine Schadennummer']; continue; }
                $result = caseIdentitySave($user, ['case' => $meta]); $folderId = (string)$result['folder_id'];
                if (!empty($result['reused'])) $summary['cases_updated']++; else $summary['cases_created']++;
                $caseMeta = gdCaseMeta($folderId); $known = is_array($caseMeta['kuss_imported_ids'] ?? null) ? $caseMeta['kuss_imported_ids'] : [];
                $messages = kussRequestJobMessages($token, $jobId); $messageData = is_array($messages['data'] ?? null) ? $messages['data'] : [];
                $caseFileResponse = kussRequestJobCaseFile($token, $jobId); $caseFileData = is_array($caseFileResponse['data'] ?? null) ? $caseFileResponse['data'] : [];
                if (isset($caseFileData['id'])) $caseFileData = [$caseFileData];
                foreach ($messageData as $message) {
                    if (!is_array($message)) continue; $messageId = kussResourceId($message); if ($messageId === '') continue; $key = 'message:' . $messageId;
                    if (in_array($key, $known, true)) { $summary['duplicates']++; continue; }
                    $name = 'KUSS_'.$jobId.'_Nachricht_'.$messageId.'.html'; gdUploadCaseFile($folderId, $name, 'text/html', kussImportMessageBytes($message), 0); $known[] = $key; $summary['messages_imported']++;
                    foreach (kussImportAttachmentIds($message, $resource, $caseFileResponse) as $attachmentId) {
                        $aKey = 'attachment:' . $attachmentId; if (in_array($aKey, $known, true)) { $summary['duplicates']++; continue; }
                        $attachment = kussRequestAttachment($token, $attachmentId); $aa = kussResourceAttributes((array)($attachment['data'] ?? $attachment)); $filename = basename((string)($aa['filename'] ?? $aa['name'] ?? ($attachmentId.'.bin'))); $download = kussDownload($token, '/attachments/'.$attachmentId.'/download');
                        gdUploadCaseFile($folderId, 'KUSS_'.$jobId.'_Anhang_'.$attachmentId.'_'.$filename, (string)($aa['mime-type'] ?? $aa['mime_type'] ?? $download['mime']), $download['bytes'], 0); $known[] = $aKey; $summary['attachments_imported']++;
                    }
                }
                foreach ($caseFileData as $document) {
                    if (!is_array($document)) continue;
                    foreach (kussImportAttachmentIds($document, $resource, $document) as $attachmentId) {
                        $aKey = 'attachment:' . $attachmentId; if (in_array($aKey, $known, true)) { $summary['duplicates']++; continue; }
                        $attachment = kussRequestAttachment($token, $attachmentId); $aa = kussResourceAttributes((array)($attachment['data'] ?? $attachment)); $filename = basename((string)($aa['filename'] ?? $aa['name'] ?? ($attachmentId.'.bin'))); $download = kussDownload($token, '/attachments/'.$attachmentId.'/download');
                        gdUploadCaseFile($folderId, 'KUSS_'.$jobId.'_Dokument_'.$attachmentId.'_'.$filename, (string)($aa['mime-type'] ?? $aa['mime_type'] ?? $download['mime']), $download['bytes'], 0); $known[] = $aKey; $summary['attachments_imported']++;
                    }
                }
                $caseMeta['kuss_imported_ids'] = array_slice(array_values(array_unique($known)), -5000); $caseMeta['kuss_last_synced_at'] = gmdate('c'); gdUploadJson($folderId, CASE_META_NAME, $caseMeta);
            } catch (Throwable $e) { $summary['errors'][] = ['job_id'=>$jobId, 'error'=>'Import fehlgeschlagen']; error_log('[kuss-import] '.$jobId.': '.$e->getMessage()); }
        }
        $last = (int)(($listing['meta']['page']['last-page'] ?? $page)); if ($page >= $last) break;
    }
    return $summary;
}
