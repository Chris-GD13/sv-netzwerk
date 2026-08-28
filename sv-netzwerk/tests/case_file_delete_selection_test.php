<?php
declare(strict_types=1);

function checkDeleteSelection(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$browser = file_get_contents(__DIR__.'/../public/intern/case-document-browser.js');
$endpoint = file_get_contents(__DIR__.'/../public/intern/api/case-file-browser.php');

checkDeleteSelection(is_string($browser) && str_contains($browser, 'id="vfdb-delete-selected"'), 'Lösch-Button fehlt.');
checkDeleteSelection(str_contains($browser, 'Ausgewählte löschen'), 'Lösch-Button ist nicht eindeutig beschriftet.');
checkDeleteSelection(str_contains($browser, 'window.confirm'), 'Bestätigung vor dem Löschen fehlt.');
checkDeleteSelection(str_contains($browser, 'data-id="${esc(item.id)}"'), 'Dateiauswahl übermittelt keine Drive-Datei-ID.');
checkDeleteSelection(str_contains($browser, 'action=delete_selected'), 'Oberfläche ruft die Mehrfachlöschung nicht auf.');

checkDeleteSelection(is_string($endpoint) && str_contains($endpoint, "if(\$action==='delete_selected')"), 'Mehrfachlösch-Endpunkt fehlt.');
checkDeleteSelection(str_contains($endpoint, "\$_SERVER['REQUEST_METHOD']!=='POST'"), 'Löschen ist nicht auf POST beschränkt.');
checkDeleteSelection(str_contains($endpoint, "cbFindInTree(\$tree,\$fileId)"), 'Zugehörigkeit zum Schadenfall wird nicht geprüft.');
checkDeleteSelection(str_contains($endpoint, "'00_Falldaten.json'"), 'Zentrale Falldaten sind nicht geschützt.');
checkDeleteSelection(str_contains($endpoint, "json_encode(['trashed'=>true]"), 'Dateien werden nicht wiederherstellbar in den Papierkorb verschoben.');
checkDeleteSelection(str_contains($endpoint, "'recoverable'=>true"), 'Wiederherstellbarkeit wird nicht bestätigt.');

echo "Ausgewählte Fallunterlagen können bestätigt und wiederherstellbar gelöscht werden.\n";
