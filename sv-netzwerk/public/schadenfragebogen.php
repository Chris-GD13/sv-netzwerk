<?php
declare(strict_types=1);

require_once __DIR__ . '/form-handler-core.php';

const INVITE_HASH = 'fee546a2b4b0bb6160a55c58141f4bdf3705432ee1ed228c008feafa32f67aa6';
const RECIPIENT = 'ws@sv-schuett.eu';
const SENDER = 'info@sv-netzwerk.eu';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

ini_set('session.use_strict_mode', '1');
session_name('sv_schadenfragebogen');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/schadenfragebogen.php',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function escapeForm(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formValue(string $name, int $maxBytes): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || strlen($value) > $maxBytes || !preg_match('//u', $value)) {
        throw new InvalidArgumentException('Ungültige Eingabe.');
    }
    return trim(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $value));
}

$groups = [
    'Kontakt und Versicherungsfall' => [
        'name' => ['Name des Versicherungsnehmers', 'text', 'name'],
        'telefon' => ['Telefon', 'tel', 'tel'],
        'email' => ['E-Mail', 'email', 'email'],
        'versicherer' => ['Versicherer', 'text'],
        'schadennummer' => ['Schadennummer', 'text'],
        'versicherungsschein' => ['Versicherungsschein-Nummer', 'text'],
        'schadenort' => ['Anschrift des Schadenobjekts', 'text'],
        'anschrift' => ['Ihre Anschrift, falls abweichend', 'text', 'street-address'],
    ],
    'Gebäude und Nutzung' => [
        'gebaeudeart' => ['Gebäudeart', 'select', [
            'Einfamilienhaus', 'Mehrfamilienhaus', 'Wohnung', 'Sonstiges',
        ]],
        'baujahr' => ['Baujahr, ungefähr', 'numeric'],
        'geschosse' => ['Anzahl der Geschosse', 'numeric'],
        'wohnungen' => ['Anzahl der Wohnungen im Gebäude', 'numeric'],
        'flaeche' => ['Wohnfläche oder Nutzfläche in m², ungefähr', 'decimal'],
        'bauart' => ['Bauart', 'select', [
            'Mauerwerk', 'Beton', 'Holzbau', 'Fachwerk', 'Stahlbau', 'Gemischte Bauweise', 'Sonstiges',
        ]],
        'dach' => ['Dachform', 'select', [
            'Satteldach', 'Flachdach', 'Pultdach', 'Walmdach', 'Krüppelwalmdach',
            'Zeltdach', 'Mansarddach', 'Mehrere Dachformen', 'Sonstiges',
        ]],
        'dachdeckung' => ['Dachdeckung', 'select', [
            'Dachziegel oder Dachsteine', 'Schiefer', 'Metall oder Blech', 'Bitumen',
            'Kunststoff- oder Gummibahn', 'Begrüntes Dach', 'Mehrere Dachdeckungen', 'Sonstiges',
        ]],
        'keller' => ['Gibt es einen Keller?', 'select', ['Ja', 'Nein', 'Teilweise unterkellert']],
        'bereich' => ['Betroffener Gebäudeteil', 'select', [
            'Keller', 'Erdgeschoss', 'Obergeschoss', 'Dachgeschoss', 'Dach', 'Fassade',
            'Außenbereich', 'Mehrere Gebäudeteile', 'Sonstiges',
        ]],
        'rolle' => ['In welcher Rolle melden Sie den Schaden?', 'select', [
            'Eigentümer oder Eigentümerin', 'Mieter oder Mieterin', 'Hausverwaltung', 'Sonstiges',
        ]],
        'nutzung' => ['Wie wurde der Bereich vor dem Schaden genutzt?', 'select', [
            'Selbst bewohnt', 'Vermietet', 'Gewerblich genutzt', 'Gemischt genutzt', 'Leerstand', 'Sonstiges',
        ]],
        'nutzung_seit' => ['Bei Vermietung oder Leerstand: seit wann?', 'text'],
        'leerstand' => ['Bei Leerstand: Wurde er dem Versicherer gemeldet?', 'select', ['Ja', 'Nein']],
        'leerstand_meldedatum' => ['Falls ja: Wann wurde der Leerstand gemeldet?', 'text'],
        'vorschaden' => ['Bekannte Vorschäden, frühere Reparaturen oder Sanierungsbedarf', 'textarea'],
    ],
    'Bankverbindung' => [
        'iban' => ['IBAN für eine mögliche Versicherungsleistung', 'text'],
        'kontoinhaber' => ['Name des Kontoinhabers', 'text'],
        'vorsteuer' => ['Können Sie die Vorsteuer abziehen?', 'select', ['Ja', 'Nein']],
    ],
    'Schadenereignis' => [
        'schadenart' => ['Um welche Art von Schaden handelt es sich?', 'select', ['Leitungswasser', 'Brand oder Rauch', 'Sturm oder Hagel', 'Starkregen oder Rückstau', 'Haftpflicht', 'Sonstiges']],
        'schadentag' => ['Wann ist der Schaden passiert?', 'text'],
        'entdeckung' => ['Wer hat den Schaden wann und wo zuerst bemerkt?', 'textarea'],
        'beobachtung' => ['Was haben Sie beobachtet? Wie ging es danach weiter?', 'textarea'],
        'betroffene_raeume' => ['Welche Räume, Wohnungen oder Bereiche sind betroffen?', 'text'],
        'vermutung' => ['Welche Ursache vermuten Sie?', 'textarea'],
        'feststellung' => ['Was hat eine Firma oder Behörde festgestellt? Wer und wann?', 'textarea'],
        'meldung' => ['Wem wurde der Schaden wann gemeldet? Falls bekannt, mit Aktenzeichen.', 'textarea'],
        'anlage' => ['Falls eine Anlage betroffen ist: Hersteller, Typ, Alter und Einbauort', 'textarea'],
        'brand' => ['Nur bei Brand: Wo wurden Rauch oder Feuer zuerst bemerkt? Wer hat gelöscht? Wo gab es Ruß oder Löschwasser?', 'textarea'],
    ],
    'Maßnahmen und Nutzungsausfall' => [
        'massnahmen' => ['Was wurde wann und von wem unternommen, um den Schaden zu begrenzen?', 'textarea'],
        'firma' => ['Welche Firmen wurden von wem und wann beauftragt?', 'textarea'],
        'arbeiten' => ['Welche Arbeiten sind erledigt? Was steht noch an?', 'textarea'],
        'trocknung' => ['Falls getrocknet wird oder wurde: Zeitraum und bekannte Messwerte', 'textarea'],
        'nutzungsausfall' => ['Welche Räume oder Bereiche können Sie seit wann nicht nutzen?', 'textarea'],
        'mietausfall' => ['Gab es eine Ausweichunterkunft, Mietminderung oder Mietausfall? Für welchen Zeitraum?', 'textarea'],
        'beteiligte' => ['Sind Hausrat oder fremdes Eigentum betroffen? Gibt es weitere Beteiligte?', 'textarea'],
        'hinweise' => ['Weitere Hinweise oder vorhandene Unterlagen', 'textarea'],
    ],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$now = time();
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if ($method === 'POST' && ($_POST['action'] ?? '') === 'open') {
    $key = $_POST['key'] ?? '';
    if (!is_string($key) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $key)
        || !hash_equals(INVITE_HASH, hash('sha256', $key))) {
        http_response_code(403);
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['access_until'] = $now + 10800;
    $_SESSION['form_nonce'] = bin2hex(random_bytes(32));
    unset($_SESSION['sent']);
    http_response_code(204);
    exit;
}

$mayFill = (int) ($_SESSION['access_until'] ?? 0) >= $now;
$sent = $mayFill && !empty($_SESSION['sent']);
$values = [];
$error = '';
if ($method === 'POST') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 42000) {
        http_response_code(413);
        exit;
    }
    $csrf = $_POST['csrf'] ?? null;
    if (($_POST['action'] ?? '') !== 'send' || !$mayFill || $sent || !is_string($csrf)
        || !isset($_SESSION['form_nonce']) || !hash_equals($_SESSION['form_nonce'], $csrf)) {
        http_response_code(403);
        $error = 'Der Fragebogen konnte nicht gesendet werden. Bitte öffnen Sie den Link aus Ihrer E-Mail erneut.';
    } else {
        try {
            $body = ["Schadenfragebogen vom " . date('d.m.Y H:i')];
            foreach ($groups as $heading => $fields) {
                $lines = [];
                foreach ($fields as $key => $field) {
                    [$label, $type] = $field;
                    $value = formValue($key, $type === 'textarea' ? 2500 : 500);
                    if ($type === 'select' && $value !== '' && !in_array($value, $field[2], true)) {
                        throw new InvalidArgumentException('Ungültige Auswahl.');
                    }
                    $values[$key] = $value;
                    if ($value !== '') {
                        $lines[] = $label . ":\n" . $value;
                    }
                }
                if ($lines !== []) {
                    $body[] = $heading . "\n\n" . implode("\n\n", $lines);
                }
            }
            if ($values['name'] === '' || ($values['schadennummer'] === '' && $values['schadenort'] === '')) {
                http_response_code(422);
                $error = 'Bitte geben Sie Ihren Namen und die Schadennummer oder den Schadenort an.';
            } elseif (sv_send_mail(RECIPIENT, 'SV-Netzwerk: Neuer Schadenfragebogen', implode("\n\n", $body), SENDER, '', '')) {
                $_SESSION['sent'] = true;
                unset($_SESSION['form_nonce']);
                header('Location: /schadenfragebogen.php', true, 303);
                exit;
            } else {
                http_response_code(503);
                $error = 'Das Senden hat nicht geklappt. Ihre Eingaben stehen noch im Formular. Bitte versuchen Sie es erneut.';
            }
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $error = 'Eine Eingabe ist zu lang oder ungültig. Bitte prüfen Sie Ihre Angaben und versuchen Sie es erneut.';
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Schadenfragebogen | SV-Netzwerk</title>
<style nonce="<?= escapeForm($nonce) ?>">
:root { color-scheme: light; font: 1.125rem/1.55 system-ui,sans-serif; color:#172d3c; background:#f1f5f7; }
* { box-sizing:border-box; }
main { max-width:52rem; padding:clamp(1rem,4vw,2.5rem); margin:auto; }
.brand { font-weight:800; letter-spacing:.02em; }
.brand span { color:#994600; }
h1 { font-size:clamp(2rem,6vw,2.7rem); line-height:1.15; margin:.7rem 0; }
h2 { font-size:1.3rem; margin:0 0 .85rem; }
section { background:white; border:1px solid #c5d2da; border-radius:.6rem; padding:clamp(1rem,3vw,1.5rem); margin:1.2rem 0; }
label { display:block; font-weight:650; margin:1rem 0 .25rem; }
input, select, textarea { display:block; width:100%; font:inherit; color:inherit; background:white; border:1px solid #566c7a; border-radius:.35rem; padding:.55rem .68rem; }
textarea { min-height:5rem; resize:vertical; }
:focus-visible { outline:3px solid #99500d; outline-offset:3px; }
button { font:inherit; font-weight:700; color:white; background:#163b51; padding:.65rem 1.3rem; border:0; border-radius:.35rem; cursor:pointer; }
a { color:#164b68; }
.error { padding:.85rem; border-left:4px solid #992722; background:#fff1ef; }
</style>
</head>
<body><main>
<div class="brand"><span>SV</span>-Netzwerk</div>
<h1>Schaden<wbr>fragebogen</h1>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= escapeForm($error) ?></p><?php endif; ?>
<?php if ($sent): ?>
    <p>Vielen Dank. Ihr Schadenfragebogen wurde abgesendet.</p>
<?php elseif ($mayFill): ?>
    <p>Bitte füllen Sie alle Felder aus, die Sie beantworten können. Was Sie nicht wissen, lassen Sie einfach frei.</p>
    <form action="/schadenfragebogen.php" method="post">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="csrf" value="<?= escapeForm((string) $_SESSION['form_nonce']) ?>">
        <?php foreach ($groups as $heading => $fields): ?>
            <section><h2><?= escapeForm($heading) ?></h2>
            <?php foreach ($fields as $key => $field):
                [$label, $type] = $field;
                $value = $values[$key] ?? '';
            ?>
                <label for="<?= escapeForm($key) ?>"><?= escapeForm($label) ?></label>
                <?php if ($type === 'textarea'): ?>
                    <textarea id="<?= escapeForm($key) ?>" name="<?= escapeForm($key) ?>" maxlength="800"><?= escapeForm($value) ?></textarea>
                <?php elseif ($type === 'select'): ?>
                    <select id="<?= escapeForm($key) ?>" name="<?= escapeForm($key) ?>">
                        <option value="">Bitte auswählen</option>
                        <?php foreach ($field[2] as $option): ?>
                            <option<?= $value === $option ? ' selected' : '' ?>><?= escapeForm($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else:
                    $inputMode = in_array($type, ['numeric', 'decimal'], true) ? $type : null;
                ?>
                    <input id="<?= escapeForm($key) ?>" name="<?= escapeForm($key) ?>" type="<?= escapeForm($inputMode === null ? $type : 'text') ?>" value="<?= escapeForm($value) ?>" maxlength="<?= $key === 'iban' ? '50' : '160' ?>" autocomplete="<?= escapeForm($field[2] ?? 'off') ?>"<?= $inputMode === null ? '' : ' inputmode="' . escapeForm($inputMode) . '"' ?><?= $key === 'iban' ? ' spellcheck="false" autocapitalize="characters"' : '' ?>>
                <?php endif; ?>
            <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <p><a href="/datenschutzerklaerung/">Datenschutzhinweise</a></p>
        <button type="submit">Absenden</button>
    </form>
<?php else: ?>
    <p id="open-status" role="status">Bitte öffnen Sie den Link aus unserer E-Mail.</p>
    <noscript><p>Zum Öffnen des Fragebogens muss JavaScript im Browser aktiviert sein.</p></noscript>
    <script nonce="<?= escapeForm($nonce) ?>">
    const invite = location.hash.slice(1);
    if (/^[A-Za-z0-9_-]{43}$/.test(invite)) {
        history.replaceState(null, '', location.pathname);
        const status = document.getElementById('open-status');
        status.textContent = 'Der Fragebogen wird geöffnet …';
        fetch(location.pathname, {
            method: 'POST',
            body: new URLSearchParams({action: 'open', key: invite}),
            credentials: 'same-origin',
        }).then(response => {
            if (response.status !== 204) throw new Error();
            location.reload();
        }).catch(() => {
            status.textContent = 'Der Fragebogen konnte nicht geöffnet werden. Bitte öffnen Sie den Link aus unserer E-Mail erneut.';
        });
    }
    </script>
<?php endif; ?>
</main></body>
</html>
