<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'] ?? '', ['administrator', 'projektleiter', 'pruefer', 'sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');
$relative = str_replace('\\', '/', trim((string)($_GET['path'] ?? '')));
if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) apiError(400, 'Ungültiger Dateipfad.');
$root = realpath(photosDir() . '/insurance-knowledge/files');
$file = realpath(photosDir() . '/insurance-knowledge/files/' . $relative);
if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) apiError(404, 'Unterlage nicht gefunden.');
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="' . addcslashes(basename($file), "\\\"") . '"');
readfile($file);
exit;
