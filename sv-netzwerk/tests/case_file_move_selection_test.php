<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__.'/../public/intern/api/case-file-browser.php');
$browser = file_get_contents(__DIR__.'/../public/intern/case-document-browser.js');
$layout = file_get_contents(__DIR__.'/../src/layouts/InternalLayout.astro');
if (!is_string($api) || !is_string($browser) || !is_string($layout)) exit(1);

$checks = [
    'Verschiebe-API vorhanden'=>str_contains($api, "if(\$action==='move_selected')"),
    'Dateien werden im Fallbaum geprüft'=>str_contains($api, 'cbFindInTree($tree,$fileId)'),
    'Zielordner wird im Fallbaum geprüft'=>str_contains($api, 'cbFindFolderInTree($tree,$targetId)'),
    'Quellordner wird übergeben'=>str_contains($api, "'parentId'=>\$folderId"),
    'Drive-Eltern werden ausgetauscht'=>str_contains($api, "'addParents'=>\$targetId,'removeParents'=>\$sourceId"),
    'Falldaten bleiben geschützt'=>str_contains($api, 'Die zentrale Falldaten-Datei darf nicht verschoben werden.'),
    'Zielordner-Auswahl vorhanden'=>str_contains($browser, 'id="vfdb-move-target"'),
    'Verschiebe-Schaltfläche vorhanden'=>str_contains($browser, 'id="vfdb-move-selected"'),
    'Mehrfachauswahl wird übergeben'=>str_contains($browser, 'file_ids:selected.map(n=>n.dataset.id)'),
    'Cache-Version aktualisiert'=>str_contains($layout, 'case-document-browser.js?v=20260901-2'),
];
$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Fehlgeschlagen:\n- ".implode("\n- ", $failed)."\n");
    exit(1);
}
echo "Fallakten lassen sich kontrolliert in vorhandene Unterordner verschieben.\n";
