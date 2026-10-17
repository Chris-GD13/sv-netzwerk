<?php
declare(strict_types=1);

function poIsInquiryName(string $name): bool {
    return preg_match('/^Anfragen?(?:[ _·-]|$)/iu', trim($name)) === 1;
}

function poValidateTopOrder(string $id, string $rootId): array {
    if ($id === '' || $id === $rootId) apiError(400, 'Bitte einen Auftrag auswählen.');
    $order = poValidateScope($id, $rootId);
    if (!isset($order['folder']) || poIsYearFolder($order) || poIsStandardFolder($order)) apiError(403, 'Bitte einen Auftrag auswählen.');
    $parent = (string)($order['parentReference']['id'] ?? '');
    if ($parent !== $rootId) {
        $year = poRequest('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($parent).'?$select=id,name,folder,parentReference');
        if (!poIsYearFolder($year) || (string)($year['parentReference']['id'] ?? '') !== $rootId) apiError(403, 'Bitte einen Auftrag aus der Auftragsliste auswählen.');
    }
    return $order;
}

function poConvertInquiry(string $id, string $name, string $rootId): array {
    $source = poValidateTopOrder($id, $rootId);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120 || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1F]/u', $name) || preg_match('/[. ]$/u', $name) || poIsInquiryName($name)) apiError(400, 'Bitte einen gültigen Auftragsnamen ohne den Zusatz Anfrage eingeben.');
    if (!poIsInquiryName((string)$source['name'])) {
        if ((string)$source['name'] === $name) return ['order_id'=>$id, 'already_done'=>true];
        apiError(409, 'Dieser Eintrag ist keine offene Anfrage mehr.');
    }
    // Rename the existing folder; all file/folder IDs and contents are retained.
    poRequestJson('PATCH', 'https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($id), ['name'=>$name, '@microsoft.graph.conflictBehavior'=>'fail']);
    return ['order_id'=>$id];
}

function poMergeInquiry(string $id, string $targetId, string $rootId): array {
    if ($id === $targetId) apiError(400, 'Anfrage und Auftrag müssen verschieden sein.');
    $target = poValidateTopOrder($targetId, $rootId);
    if (poIsInquiryName((string)$target['name'])) apiError(400, 'Bitte einen bestehenden Auftrag auswählen.');
    $source = poValidateScope($id, $rootId);
    $folders = poChildren($targetId);
    $archive = null;
    foreach ($folders as $folder) if ($folder['folder'] && $folder['name'] === 'Sonstige Unterlagen') $archive = $folder;
    // Retrying a completed move must not copy or move the inquiry again.
    if ($archive && (string)($source['parentReference']['id'] ?? '') === $archive['id'] && str_starts_with((string)$source['name'], 'Übernommene Anfrage - ')) return ['order_id'=>$targetId, 'already_done'=>true];
    $source = poValidateTopOrder($id, $rootId);
    if (!poIsInquiryName((string)$source['name'])) apiError(409, 'Dieser Eintrag ist keine offene Anfrage mehr.');
    $base = 'https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/';
    if (!$archive) $archive = poRequestJson('POST', $base.rawurlencode($targetId).'/children', ['name'=>'Sonstige Unterlagen','folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);
    $name = preg_replace('/^Anfragen?(?:[ _·-]+|$)/iu', '', (string)$source['name']);
    $name = 'Übernommene Anfrage - '.mb_substr((string)$name, 0, 100).' - '.substr(hash('sha256', $id), 0, 8);
    // One atomic move preserves all nested documents, attachments and IDs.
    // No existing target document is replaced or deleted.
    poRequestJson('PATCH', $base.rawurlencode($id), ['name'=>$name,'parentReference'=>['id'=>$archive['id']], '@microsoft.graph.conflictBehavior'=>'fail']);
    return ['order_id'=>$targetId, 'inquiry_id'=>$id, 'folder'=>'Sonstige Unterlagen/'.$name];
}
