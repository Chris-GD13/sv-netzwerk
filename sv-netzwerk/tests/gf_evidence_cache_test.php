<?php
declare(strict_types=1);

require_once __DIR__.'/../public/intern/api/gf-evidence-cache.php';

$file = [
    'id' => 'drive-1',
    'modifiedTime' => '2026-08-27T08:00:00Z',
    'name' => 'Rechnung 1.pdf',
    'mimeType' => 'application/pdf',
];
$sameFile = $file;
$changedFile = $file;
$changedFile['modifiedTime'] = '2026-08-27T09:00:00Z';

if (gfEvidenceFileCacheKey($file) !== gfEvidenceFileCacheKey($sameFile)) {
    throw new RuntimeException('Unveränderte Dateien müssen denselben Evidenz-Cache verwenden.');
}
if (gfEvidenceFileCacheKey($file) === gfEvidenceFileCacheKey($changedFile)) {
    throw new RuntimeException('Geänderte Dateien dürfen keinen alten Evidenz-Cache verwenden.');
}

$second = [
    'id' => 'drive-2',
    'modifiedTime' => '2026-08-27T08:10:00Z',
    'name' => 'Foto 1.jpg',
    'mimeType' => 'image/jpeg',
    '_evidence_name' => 'Foto 1.jpg',
];
$first = $file + ['_evidence_name' => 'Rechnung 1.pdf'];
$evidence = ['files' => [
    ['name' => 'Rechnung 1.pdf', 'facts' => ['Rechnungsbetrag belegt']],
    ['name' => 'Foto 1.jpg', 'visual_findings' => ['Wasserfleck sichtbar']],
]];
$split = gfEvidenceSplitBySource($evidence, [$first, $second]);
if (count($split) !== 2 || !isset($split[gfEvidenceFileCacheKey($file)], $split[gfEvidenceFileCacheKey($second)])) {
    throw new RuntimeException('Eindeutige Mehrdatei-Evidenz wurde nicht zuverlässig getrennt.');
}

$ambiguous = $second;
$ambiguous['id'] = 'drive-3';
$ambiguous['_evidence_name'] = 'Rechnung 1.pdf';
if (gfEvidenceSplitBySource($evidence, [$first, $ambiguous]) !== []) {
    throw new RuntimeException('Mehrdeutige Dateinamen dürfen nicht einzeln zwischengespeichert werden.');
}

if (gfEvidenceSplitBySource(['files' => [$evidence['files'][0]]], [$first, $second]) !== []) {
    throw new RuntimeException('Unvollständige Evidenz darf nicht einzeln zwischengespeichert werden.');
}

$normalizedUpload = [
    'id' => 'drive-noname',
    'modifiedTime' => '2026-08-27T08:20:00Z',
    'name' => '.noname2',
    'mimeType' => 'application/pdf',
    '_evidence_name' => 'Unterlage.pdf',
];
$normalizedSplit = gfEvidenceSplitBySource(
    ['files' => [['name' => 'Unterlage.pdf', 'facts' => ['Inhalt vollständig gelesen']]]],
    [$normalizedUpload]
);
if (!isset($normalizedSplit[gfEvidenceFileCacheKey($normalizedUpload)])) {
    throw new RuntimeException('Normalisierte Uploadnamen müssen dem ursprünglichen Drive-Dokument zugeordnet werden.');
}

echo "GF evidence cache tests passed\n";
