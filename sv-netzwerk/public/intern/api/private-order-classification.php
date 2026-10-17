<?php
declare(strict_types=1);

function poIsOrderContainer(array $item): bool { return isset($item['folder']) && $item['folder'] !== false && (preg_match('/^(?:19|20)\d{2}(?: und älter)?$/iu', trim((string)($item['name'] ?? ''))) === 1 || mb_strtolower(trim((string)($item['name'] ?? '')), 'UTF-8') === 'gutachtenfälle'); }

function poCategory(array $file): string {
    $name = mb_strtolower((string)($file['name'] ?? ''), 'UTF-8');
    $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION), 'UTF-8');
    if (in_array($ext, ['msg','eml'], true) || (!empty($file['folder']) && preg_match('/mail/u', $name))) return 'E-Mails';
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','heic','tif','tiff','bmp','mov','mp4','m4v','avi','mts','3gp'], true) || preg_match('/foto|bilder|medien|icloud/u', $name)) return 'Fotos';
    if (preg_match('/vertrag|vereinbarung|vollmacht|auftrag|bestellung|(^|[ _-])ab([ _-]|$)/u', $name)) return 'Auftrag';
    if (preg_match('/(?<!be)rechnung|honorar|invoice/u', $name)) return 'Rechnungen';
    if (preg_match('/gutachten|stellungnahme|expertise|wertermittlung|begutachtung|bericht/u', $name)) return 'Gutachten';
    if (preg_match('/angebot|kostenvoranschlag|kostenschätzung|(^|[^a-z])kv[ _-]|(^|[^a-z])kva([^a-z]|$)/u', $name)) return 'Angebot';
    return 'Sonstige Unterlagen';
}

// Return the requested item, not the ancestor used to prove authorization.
function poValidateScope(string $id, string $rootId): array {
    $current = $id; $requested = null;
    for ($i = 0; $i < 12; $i++) {
        $item = poRequest('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($current).'?$select=id,name,size,file,folder,createdDateTime,lastModifiedDateTime,webUrl,parentReference');
        $requested ??= $item;
        if ((string)($item['id'] ?? '') === $rootId) return $requested;
        $parent = (string)($item['parentReference']['id'] ?? '');
        if ($parent === '' || $parent === $current) break;
        $current = $parent;
    }
    apiError(403, 'Dieser Eintrag gehört nicht zum eigenen Privatauftragsbereich.');
    return [];
}
