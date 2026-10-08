<?php
declare(strict_types=1);

/** Reuse an unchanged source only while its uploaded file still exists. */
function bkCachedOpenAIFile(array $cached, string $modified): ?array
{
    $fileId = trim((string)($cached['file_id'] ?? ''));
    if ($fileId === '' || ($cached['modified'] ?? '') !== $modified) return null;
    try {
        $live = bkOpenAIJson('GET', 'files/' . rawurlencode($fileId), null, 90);
    } catch (RuntimeException $error) {
        // Re-upload missing files; authentication and temporary failures must remain visible.
        if ($error->getCode() === 404) return null;
        throw $error;
    }
    if (($live['id'] ?? '') !== $fileId) return null;
    return ['file_id' => $fileId, 'name' => (string)($cached['name'] ?? '')];
}
