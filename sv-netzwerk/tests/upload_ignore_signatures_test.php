<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/intern/api/case-upload-ignore.php';

$expected = [
    'd7e3662266444a6b1dd36cbb9a95546066eeae0d920a6f702ae716ba8aacb0cf',
    '008eabe9fac31bc3a22473dcd52b287630b8d7bdc4added8cd01aa47c73f9431',
    'a3ea5a503049dff4515211b7d41aba2f4e1ee33666df00a36a10fc862da3b1dc',
    '20403ad77096c24cec3a46343b77e42ed990496ac6d926277150b2dd4bb4326f',
    '8cd1bacc82ac1eccd328c2883a54d11e29f40e736be78f1e0520b982e2c95a2a',
    '95eba1431847820b7e727679c0272100a962b938b14fea90eed19b7fcb5f519b',
];

foreach ($expected as $hash) {
    if (caseUploadExcludedAssetForHash($hash) === null) {
        fwrite(STDERR, "Fehlende Upload-Sperre für Hash: {$hash}\n");
        exit(1);
    }
}

if (caseUploadExcludedAssetForHash(str_repeat('0', 64)) !== null) {
    fwrite(STDERR, "Unbekannter Dateiinhalt wurde fälschlich ausgeschlossen.\n");
    exit(1);
}

if (caseUploadExcludedAsset('Schadensfoto.jpg', 'image/jpeg', 'echtes-schadensfoto') !== null) {
    fwrite(STDERR, "Normales Schadenfoto wurde fälschlich ausgeschlossen.\n");
    exit(1);
}

echo "Upload-Sperre für bekannte Signatur- und Profilbilder geprüft.\n";
