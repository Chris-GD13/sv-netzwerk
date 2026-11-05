<?php
declare(strict_types=1);

const CRC_MASTER_NAME = 'MASTER-ARBEITSSTANDARD.md';
const CRC_MASTER_SHA256 = 'D3151085C71967C9903D48D7408857FD8815A9E83CF656DB81FF525C8CB12B45';
const CRC_MASTER_VERSION = '4.41';
const CRC_DRIVE_SOURCE_ID = '1vnhgUieiMOoX_S0AmCKWazSGg8OxFuS2';

function crcClassify(string $path, string $name, int $size, string $sha256, string $canonicalPath): array
{
    $isCanonical = strcasecmp($path, $canonicalPath) === 0 && strcasecmp($name, CRC_MASTER_NAME) === 0;
    $identical = hash_equals(strtoupper(CRC_MASTER_SHA256), strtoupper($sha256));
    return [
        'path'=>$path,
        'name'=>$name,
        'size'=>$size,
        'sha256'=>strtoupper($sha256),
        'classification'=>$isCanonical ? 'kanonisch' : ($identical ? 'identisch' : 'Alt-/Doppelfassung'),
        'canonical'=>$isCanonical,
    ];
}

function crcReleaseText(?DateTimeImmutable $now = null): string
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    return "Drive-Quell-ID: ".CRC_DRIVE_SOURCE_ID."\n"
        ."Version: ".CRC_MASTER_VERSION."\n"
        ."Zeitstempel (Europe/Berlin): ".$now->format('Y-m-d H:i:s P')."\n"
        ."SHA-256: ".CRC_MASTER_SHA256."\n";
}

function crcArchiveName(string $path, string $id, string $name = CRC_MASTER_NAME): string
{
    $slug = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', trim($path, '/')) ?? 'unbekannter-pfad';
    $slug = trim($slug, '-_.') ?: 'unbekannter-pfad';
    return gmdate('Ymd-His').'_'.mb_substr($slug, 0, 150, 'UTF-8').'__'.substr(hash('sha256', $id), 0, 12).'__'.$name;
}

function crcBuildPlan(array $files, string $canonicalPath): array
{
    $masters = array_values(array_filter($files, static fn(array $file): bool => strcasecmp((string)($file['name'] ?? ''), CRC_MASTER_NAME) === 0));
    $canonical = array_values(array_filter($masters, static fn(array $file): bool => strcasecmp((string)($file['path'] ?? ''), $canonicalPath) === 0));
    $keep = $canonical[0] ?? null;
    $archive = [];
    foreach ($masters as $file) {
        if ($keep !== null && (string)($file['id'] ?? '') === (string)($keep['id'] ?? '')) continue;
        $archive[] = $file;
    }
    return [
        'master_count'=>count($masters),
        'canonical_count'=>count($canonical),
        'active_id'=>$keep['id'] ?? null,
        'archive_count'=>count($archive),
        'archive'=>$archive,
        'ready'=>count($canonical) === 1 && hash_equals(CRC_MASTER_SHA256, strtoupper((string)($keep['sha256'] ?? ''))),
    ];
}
