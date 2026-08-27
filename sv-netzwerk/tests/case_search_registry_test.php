<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/intern/api/config.php';

function registryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FEHLER: {$message}\n");
        exit(1);
    }
}

$pdo = db();
$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
registryExpect($userId > 0, 'Testbenutzer fehlt.');
$user = ['id'=>$userId,'email'=>'case-search-test@sv-netzwerk.eu'];
$folderId = 'case-search-registry-test';

try {
    registerCaseFolderOwner($folderId, $user, [
        'schaden_nr'=>'26-160966-8',
        'versicherungsschein_nr'=>'SV-4711',
        'vn_objekt'=>'WEG Testobjekt',
        'schadenart'=>'Leitungswasser',
        'fallart'=>'SV',
    ]);
    $results = searchCaseFolderIndex($user, '160966 Testobjekt');
    registryExpect(count($results) === 1, 'Registrierter Fall wurde nicht gefunden.');
    registryExpect(($results[0]['id']??'') === $folderId, 'Falscher Fall wurde zurückgegeben.');
} finally {
    $stmt = $pdo->prepare('DELETE FROM case_folder_owners WHERE folder_id=:id');
    $stmt->execute([':id'=>$folderId]);
}

echo "OK: Fallsuch-Register funktioniert mit dem produktiven Tabellenschema.\n";
