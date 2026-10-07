<?php
declare(strict_types=1);

/**
 * Einmal-/Wartungswerkzeug (nur CLI): ergänzt leere Falldaten-Felder aus der in 00_Falldaten.json
 * gespeicherten ClaimsForce-Rohquelle (claimsforce_quelle) und gleicht den Fallindex ab.
 *
 *   runuser -u www-data -- php cli-repair-claimsforce-meta.php            # Trockenlauf
 *   runuser -u www-data -- php cli-repair-claimsforce-meta.php --apply    # schreiben
 *
 * Es werden ausschließlich leere Felder gefüllt; vorhandene Werte bleiben unverändert.
 * Die Ausführung muss als Webserver-Benutzer erfolgen, damit die Dateirechte stimmen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ionos-storage.php';

$apply = in_array('--apply', $argv, true);
$text = static fn($v): string => is_string($v) || is_int($v) || is_float($v) ? trim((string)$v) : '';
$path = static function (array $a, string $p) {
    foreach (explode('.', $p) as $part) { if (!is_array($a) || !array_key_exists($part, $a)) return null; $a = $a[$part]; }
    return $a;
};
$street = static fn($addr): string => is_array($addr) ? $text($addr['line1'] ?? $addr['street'] ?? '') : '';
$hasAddr = static fn($addr): bool => is_array($addr) && ($text($addr['line1'] ?? $addr['street'] ?? '') !== '' || $text($addr['postalCode'] ?? '') !== '' || $text($addr['city'] ?? '') !== '');

function cfMapFromSource(array $src, callable $text, callable $street, callable $hasAddr): array
{
    $claim = is_array($src['claim'] ?? null) ? $src['claim'] : [];
    $holder = is_array($claim['policyholder'] ?? null) ? $claim['policyholder'] : [];
    if (!$holder) $holder = is_array($src['communication']['stakeholders']['policyholder'] ?? null) ? $src['communication']['stakeholders']['policyholder'] : [];
    $name = $holder['name'] ?? null;
    $given = $text(is_array($name) ? ($name['givenName'] ?? '') : ($holder['givenName'] ?? ''));
    $family = $text(is_array($name) ? ($name['familyName'] ?? '') : ($holder['familyName'] ?? ''));
    $vn = trim($given . ' ' . $family);
    if ($vn === '' && is_string($name)) $vn = $text($name);
    if ($vn === '') $vn = $text($holder['companyName'] ?? '');
    $own = is_array($holder['address'] ?? null) ? $holder['address'] : [];
    $damage = [];
    $candidates = [$claim['damageAddress'] ?? null, $claim['appointments'][0]['address'] ?? null, $claim['actualAppointmentAddress'] ?? null];
    foreach ($candidates as $candidate) { if ($hasAddr($candidate)) { $damage = $candidate; break; } }
    $reserve = '';
    $r = $claim['reserve'] ?? null;
    if (is_array($r) && isset($r['amount']) && is_numeric($r['amount'])) {
        $precision = isset($r['precision']) && is_numeric($r['precision']) ? (int)$r['precision'] : 2;
        $reserve = number_format(((float)$r['amount']) / (10 ** $precision), 2, ',', '.') . ' ' . ($text($r['currency'] ?? '') ?: 'EUR');
    } elseif (is_scalar($r)) {
        $reserve = $text($r);
    }
    return [
        'versicherungsschein_nr' => $text($claim['insurerPolicyId'] ?? ''),
        'vn_objekt' => $vn,
        'strasse' => $street($own),
        'plz' => $text($own['postalCode'] ?? ''),
        'ort' => $text($own['city'] ?? ''),
        'schaden_strasse' => $street($damage),
        'schaden_plz' => $text($damage['postalCode'] ?? ''),
        'schaden_ort' => $text($damage['city'] ?? ''),
        'schadenart' => $text($claim['danger']['dangerId'] ?? ''),
        'reserve' => $reserve,
        'telefon' => $text($holder['phone'] ?? ''),
        'mobil' => $text($holder['mobile'] ?? ''),
        'email' => $text($holder['email'] ?? ''),
    ];
}

$db = ionosStorageDb();
$rows = db()->query('SELECT folder_id,user_id,user_email FROM case_folder_owners')->fetchAll(PDO::FETCH_ASSOC);
$stat = ['faelle' => count($rows), 'ordner_fehlt' => 0, 'ohne_falldaten' => 0, 'ohne_claimsforce_quelle' => 0, 'unveraendert' => 0, 'ergaenzt' => 0, 'felder' => 0, 'fehler' => 0];
foreach ($rows as $row) {
    $fid = (string)$row['folder_id'];
    $s = $db->prepare('SELECT 1 FROM items WHERE id=? AND verified=1'); $s->execute([$fid]);
    if (!$s->fetchColumn()) { $stat['ordner_fehlt']++; continue; }
    $s = $db->prepare("SELECT id FROM items WHERE parent=? AND name='00_Falldaten.json' AND verified=1 LIMIT 1"); $s->execute([$fid]);
    $mid = (string)$s->fetchColumn();
    if ($mid === '') { $stat['ohne_falldaten']++; continue; }
    try {
        $case = json_decode(ionosBytes($mid), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($case)) throw new RuntimeException('Falldaten sind kein Objekt.');
        $src = $case['claimsforce_quelle'] ?? null;
        if (!is_array($src)) { $stat['ohne_claimsforce_quelle']++; continue; }
        $filled = 0;
        foreach (cfMapFromSource($src, $text, $street, $hasAddr) as $key => $value) {
            if ($value !== '' && trim((string)($case[$key] ?? '')) === '') { $case[$key] = $value; $filled++; }
        }
        if ($filled === 0) { $stat['unveraendert']++; continue; }
        $stat['ergaenzt']++; $stat['felder'] += $filled;
        if ($apply) {
            $case['updated_at'] = gmdate('c');
            ionosWrite([], json_encode($case, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), $mid);
            registerCaseFolderOwner($fid, ['id' => (int)$row['user_id'], 'email' => (string)$row['user_email']], $case);
        }
    } catch (Throwable $e) {
        $stat['fehler']++;
        fwrite(STDERR, "Fehler bei $fid: " . $e->getMessage() . "\n");
    }
}
echo ($apply ? 'ANGEWENDET' : 'TROCKENLAUF') . ' ' . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
