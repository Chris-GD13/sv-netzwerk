<?php
declare(strict_types=1);

function checkDownloadSelection(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$browser = file_get_contents(__DIR__.'/../public/intern/case-document-browser.js');
$endpoint = file_get_contents(__DIR__.'/../public/intern/api/case-file-browser.php');

checkDownloadSelection(is_string($browser) && str_contains($browser, 'id="vfdb-download-selected"'), 'Download-Schaltfläche fehlt in den Fallunterlagen.');
checkDownloadSelection(str_contains($browser, 'Ausgewählte herunterladen'), 'Download-Schaltfläche ist nicht eindeutig beschriftet.');
checkDownloadSelection(str_contains($browser, 'action=download'), 'Die Oberfläche ruft den Download-Endpunkt nicht auf.');
checkDownloadSelection(str_contains($browser, 'link.download=file.dataset.name'), 'Der Originaldateiname wird dem Browser nicht zum Speichern übergeben.');
checkDownloadSelection(is_string($endpoint) && str_contains($endpoint, "\$action==='file'||\$action==='download'"), 'Der abgesicherte Download-Endpunkt fehlt.');
checkDownloadSelection(str_contains($endpoint, "cbStreamFile(\$file,\$action==='download')"), 'Der Download erzwingt keine Anlage mit Originaldateinamen.');

echo "Markierte Fallunterlagen können mit Originaldateinamen heruntergeladen werden.\n";
