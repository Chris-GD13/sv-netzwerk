<?php
declare(strict_types=1);

/**
 * Wiederkehrende E-Mail-Signatur-, Profil- und Systembilder, die niemals Teil
 * einer Schadenakte oder KI-Voranalyse werden sollen. Die Erkennung erfolgt
 * anhand des Dateiinhalts und ist deshalb unabhängig vom Dateinamen.
 */
function caseUploadExcludedAssetHashes(): array
{
    return [
        'd7e3662266444a6b1dd36cbb9a95546066eeae0d920a6f702ae716ba8aacb0cf' => 'b.v.s-Signaturgrafik',
        '008eabe9fac31bc3a22473dcd52b287630b8d7bdc4added8cd01aa47c73f9431' => 'SV-Büro-Schütt-Signaturgrafik',
        'a3ea5a503049dff4515211b7d41aba2f4e1ee33666df00a36a10fc862da3b1dc' => 'Microsoft-Teams-Systemgrafik',
        '20403ad77096c24cec3a46343b77e42ed990496ac6d926277150b2dd4bb4326f' => 'Leere Signaturgrafik',
        '8cd1bacc82ac1eccd328c2883a54d11e29f40e736be78f1e0520b982e2c95a2a' => 'Leere Signaturgrafik',
        '95eba1431847820b7e727679c0272100a962b938b14fea90eed19b7fcb5f519b' => 'Bekanntes Profilbild',
        // Bereits zuvor erfasste Variante; bleibt für bestehende Uploads erhalten.
        '102f119c25022500542ab12d312602666f7e210bfbe067170fa15b6a69899880' => 'Bekannte Signatur-, Profil- oder Systemgrafik',
    ];
}

function caseUploadExcludedAssetForHash(string $sha256): ?string
{
    return caseUploadExcludedAssetHashes()[strtolower(trim($sha256))] ?? null;
}

function caseUploadNormalizeAssetName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = strtr($name, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    return preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? '';
}

function caseUploadExcludedAsset(string $name, string $mime, string $bytes): ?string
{
    $reason = caseUploadExcludedAssetForHash(hash('sha256', $bytes));
    if ($reason !== null) return $reason;

    // Namensregeln gelten nur für Bilder; Dokumente mit ähnlichen Begriffen
    // dürfen nicht versehentlich ausgeschlossen werden.
    if (!str_starts_with(strtolower($mime), 'image/')) return null;
    $normalized = caseUploadNormalizeAssetName($name);
    $knownNames = [
        'bvslogo2 7ce28f0c 700d 454c b645 db96d1c775fb',
        'chatgptimage9 aug 2026 19 01 28 4631f03f 1db8 414c a636 9b7ee544d6e1',
        'klein 353f6da3 e1b8 46b3 9797 b35ae37e31b4',
        'teams 32x32 9178119f 105b 4f5c abab a3ecadf7ea7c',
        'teams 32x32 91084020 9e25 41aa b1a1 6681281811c7',
    ];
    foreach ($knownNames as $known) {
        if (str_contains($normalized, $known)) return 'Bekannte Signatur-, Profil- oder Systemgrafik';
    }
    return null;
}
