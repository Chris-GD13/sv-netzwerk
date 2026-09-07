<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
requireAuth();

$file = realpath(__DIR__ . '/../references/technische-kva-freigabe-referenz.pdf');
$referenceRoot = realpath(__DIR__ . '/../references');
if ($file === false || $referenceRoot === false || !str_starts_with($file, $referenceRoot . DIRECTORY_SEPARATOR) || !is_file($file)) {
    apiError(404, 'Das Referenzformular wurde nicht gefunden.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="Technische-KVA-Freigabe-Referenz.pdf"');
header('Cache-Control: private, no-store');
readfile($file);
exit;
