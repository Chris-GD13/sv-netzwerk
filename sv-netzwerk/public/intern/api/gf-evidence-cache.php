<?php
declare(strict_types=1);

const GF_EVIDENCE_CACHE_VERSION = '4';

function gfEvidenceFileSignature(array $file): array
{
    return [
        'id' => (string)($file['id'] ?? ''),
        'modified' => (string)($file['modifiedTime'] ?? ''),
        'name' => (string)($file['name'] ?? ''),
        'mime' => (string)($file['mimeType'] ?? ''),
    ];
}

function gfEvidenceFileCacheKey(array $file): string
{
    $signature = gfEvidenceFileSignature($file);
    return 'gf_evidence_v'.GF_EVIDENCE_CACHE_VERSION.'_'.hash(
        'sha256',
        json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function gfEvidenceIsUsable(mixed $evidence): bool
{
    return is_array($evidence)
        && is_array($evidence['files'] ?? null)
        && count($evidence['files']) > 0;
}

function gfEvidenceNormalizedName(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    return mb_strtolower($name, 'UTF-8');
}

/**
 * Split a multi-file extraction into independently cacheable entries.
 * Ambiguous or incomplete model output is deliberately not cached.
 *
 * @return array<string,array{files:array<int,array>}>
 */
function gfEvidenceSplitBySource(array $evidence, array $sources): array
{
    if (!gfEvidenceIsUsable($evidence) || count($evidence['files']) !== count($sources)) return [];

    $sourceByName = [];
    foreach ($sources as $source) {
        $expectedName = (string)($source['_evidence_name'] ?? $source['name'] ?? '');
        $nameKey = gfEvidenceNormalizedName($expectedName);
        if ($nameKey === '' || isset($sourceByName[$nameKey])) return [];
        $sourceByName[$nameKey] = $source;
    }

    $evidenceByName = [];
    foreach ($evidence['files'] as $fileEvidence) {
        if (!is_array($fileEvidence)) return [];
        $nameKey = gfEvidenceNormalizedName((string)($fileEvidence['name'] ?? ''));
        if ($nameKey === '' || isset($evidenceByName[$nameKey])) return [];
        $evidenceByName[$nameKey] = $fileEvidence;
    }

    if (array_keys($sourceByName) !== array_keys($evidenceByName)) {
        ksort($sourceByName);
        ksort($evidenceByName);
        if (array_keys($sourceByName) !== array_keys($evidenceByName)) return [];
    }

    $split = [];
    foreach ($sourceByName as $nameKey => $source) {
        $split[gfEvidenceFileCacheKey($source)] = ['files' => [$evidenceByName[$nameKey]]];
    }
    return $split;
}
