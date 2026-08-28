<?php
declare(strict_types=1);

require_once __DIR__.'/kva-contact-merge.php';

function kvaPersistCaseContacts(callable $load, callable $save, array $detected, string $source, string $quoteNumber, ?string $timestamp = null): array
{
    $current = $load();
    if (!is_array($current)) throw new RuntimeException('Falldaten konnten vor der KVA-Aktualisierung nicht geladen werden.');
    $merged = kvaMergeCaseContacts($current, $detected, $source, $quoteNumber, $timestamp);
    $save($merged['case']);
    $reloaded = $load();
    if (!is_array($reloaded)) throw new RuntimeException('Falldaten konnten nach der KVA-Aktualisierung nicht neu geladen werden.');
    foreach ($merged['applied'] as $field => $value) {
        if (!array_key_exists($field, $reloaded) || kvaContactString($reloaded[$field]) !== kvaContactString($value)) {
            throw new RuntimeException('KVA-Kontaktdaten wurden nicht dauerhaft gespeichert: '.$field);
        }
    }
    $merged['case'] = $reloaded;
    return $merged;
}
