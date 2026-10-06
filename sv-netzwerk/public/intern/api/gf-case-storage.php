<?php
declare(strict_types=1);

function gfCaseStorageActivate(string $folder): void
{
    $GLOBALS['gf_storage_case'] = '';
    $GLOBALS['gf_storage_local_ids'] = [];
    if (!ionosStorageEnabled()) return;
    $item = ionosItem($folder);
    if (($item['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
        throw new RuntimeException('Aktive IONOS-Fallakte ist kein Ordner.');
    }
    $GLOBALS['gf_storage_case'] = $folder;
    $GLOBALS['gf_storage_local_ids'][$folder] = true;
}

/** Imported case IDs retain their Google format; ancestry, not a prefix, decides. */
function gfCaseStorageLocal(string $id): bool
{
    if (!ionosStorageEnabled()) return false;
    if (array_key_exists($id, $GLOBALS['gf_storage_local_ids'] ?? [])) return $GLOBALS['gf_storage_local_ids'][$id];
    $root = (string)($GLOBALS['gf_storage_case'] ?? '');
    if ($root === '') return str_starts_with($id, 'ionos_');
    $parent = $id;
    $seen = [];
    for ($depth = 0; $parent !== '' && $depth < 100; $depth++) {
        if ($parent === $root) return $GLOBALS['gf_storage_local_ids'][$id] = true;
        if (isset($seen[$parent])) throw new RuntimeException('Zyklische IONOS-Fallzuordnung.');
        $seen[$parent] = true;
        try { $item = ionosItem($parent); }
        catch (Throwable $error) {
            if (!str_starts_with($error->getMessage(), 'Datei nicht im geprüften IONOS-Bestand:')) throw $error;
            // Rule/template masters outside the active case remain external.
            return $GLOBALS['gf_storage_local_ids'][$id] = false;
        }
        $parent = (string)($item['parents'][0] ?? '');
    }
    return $GLOBALS['gf_storage_local_ids'][$id] = false;
}
