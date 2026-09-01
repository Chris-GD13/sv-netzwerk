<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
if(!is_string($source))throw new RuntimeException('KVA-Endpunkt fehlt.');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$assert(str_contains($source,"function krSignatureKey():string"),'Die KVA-Vorschau braucht eine eigene zentrale Schlüsselauflösung.');
$assert(str_contains($source,"env('KVA_SIGNATURE_SECRET',env('APP_SECRET',krMicrosoftSetting('CLIENT_SECRET')))"),'Explizite Signatur- und bestehende Anwendungsschlüssel müssen bevorzugt werden.');
$assert(str_contains($source,"if(\$secret==='')\$secret=env('OPENAI_API_KEY','')"),'Der bereits konfigurierte serverseitige KI-Schlüssel muss als sicherer Bestandsfallback nutzbar sein.');
$assert(str_contains($source,"hash_hmac('sha256','sv-netzwerk:kva-release:v1',\$secret,true)"),'Der Bestandswert darf nur zweckgebunden abgeleitet werden.');
$assert(str_contains($source,"env('MS_'.\$name,env('M365_'.\$name,''))"),'Bestehende MS- und M365-Bezeichnungen müssen unterstützt werden.');
$assert(str_contains($source,'$s=krSignatureKey();return krB64($j)')&&substr_count($source,'$s=krSignatureKey();')===2,'Signatur und Prüfung müssen exakt dieselbe Schlüsselauflösung verwenden.');
$assert(!str_contains($source,"throw new RuntimeException('Signaturschlüssel fehlt.')"),'Die irreführende alte Fehlermeldung darf nicht mehr erreichbar sein.');

echo "kva_signature_configuration_test: ok\n";
