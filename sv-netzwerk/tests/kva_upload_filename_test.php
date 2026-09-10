<?php
declare(strict_types=1);

require_once __DIR__.'/../public/intern/api/kva-upload-name.php';

function checkKvaUploadName(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

checkKvaUploadName(
    kvaOpenAiUploadName('Angebot AN2629552.PDF', 'application/octet-stream') === 'Angebot AN2629552.pdf',
    'Eine großgeschriebene PDF-Endung muss für den Dokumentenleser normalisiert werden.'
);
checkKvaUploadName(
    kvaOpenAiUploadName('KVA.DOcX', 'application/octet-stream') === 'KVA.docx',
    'Gemischt geschriebene Dokumentendungen müssen normalisiert werden.'
);
checkKvaUploadName(
    kvaOpenAiUploadName('KVA ohne Endung', 'application/pdf') === 'KVA ohne Endung.pdf',
    'Bei fehlender Endung muss der MIME-Typ eine PDF-Endung ergänzen.'
);

$endpoint = file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
$core = file_get_contents(__DIR__.'/../public/intern/api/kva-release-core-v2.php');
checkKvaUploadName(is_string($endpoint) && str_contains($endpoint, "require_once __DIR__.'/kva-upload-name.php';"), 'Die Dateinamensnormalisierung ist im KVA-Endpunkt nicht geladen.');
checkKvaUploadName(is_string($core) && substr_count($core, 'kvaOpenAiUploadName(') >= 2, 'Nicht alle KVA-Auslesewege verwenden die Dateinamensnormalisierung.');

echo "KVA-Dateinamen werden vor der Dokumentenauswertung formatkompatibel normalisiert.\n";
