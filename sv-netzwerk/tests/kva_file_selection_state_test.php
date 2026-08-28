<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
$api = file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');

if (!is_string($page) || !is_string($api)) {
    fwrite(STDERR, "KVA-Quellen konnten nicht gelesen werden.\n");
    exit(1);
}

$checks = [
    'Fallwechsel wird ausdrücklich erkannt' => str_contains($page, "caseChanged=folder!==loadedFolder"),
    'Lokale KVA wird nur beim Fallwechsel verworfen' => str_contains($page, "if(caseChanged){loadedFolder=folder;token='';review.hidden=true;send.disabled=true;direct=null"),
    'Neuladen im selben Fall erhält die lokale Auswahl' => !str_contains($page, "async function loadFiles(){const current=active();token='';review.hidden=true;send.disabled=true;direct=null"),
    'Asynchrone Antworten alter Fälle werden ignoriert' => str_contains($page, "if(active()?.folder_id!==folder)return"),
    'Leere Datei-ID erhält eine verständliche Meldung' => str_contains($api, "if(\$id==='')throw new RuntimeException('Bitte eine KVA-Datei auswählen oder neu hochladen.')"),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Fehlgeschlagen:\n- ".implode("\n- ", $failed)."\n");
    exit(1);
}

fwrite(STDOUT, "KVA-Dateiauswahl bleibt beim Fensterfokus erhalten.\n");
