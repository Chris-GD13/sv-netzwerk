<?php
declare(strict_types=1);

$core=file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate-core.php');
if(!is_string($core))throw new RuntimeException('Generator konnte nicht gelesen werden.');

$checks=[
    'Berichte und Nachträge'=>str_contains($core,"default=>'05_Berichte_Nachtraege'"),
    'Freigaben und Zahlungen'=>str_contains($core,"'schlusserklaerung','zahlungsbefuerwortung','vorauszahlung'=>'06_Freigaben_Zahlungen'"),
    'Abschlussberichte'=>str_contains($core,"'schlussbericht'=>'08_Abschluss'"),
    'Upload verwendet Zielordner'=>str_contains($core,"gfUpload(gfGeneratedFolder(\$folderId,(string)\$item['type'])"),
];
foreach($checks as$name=>$ok)if(!$ok)throw new RuntimeException($name.' wird nicht korrekt einsortiert.');
echo "gf_generated_document_folder_test: ok\n";
