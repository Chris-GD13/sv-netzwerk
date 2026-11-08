# SV-Netzwerk Langdock Worker für Windows

Der Worker läuft lokal auf dem PC und verwendet eine bereits angemeldete Chrome- oder Edge-Sitzung. Er kommuniziert mit dem Portal unter `/intern/langdock/` und hält den Auftragstatus dauerhaft fest.

Voraussetzung:

1. Node.js 20 oder neuer.
2. Chrome oder Edge mit Remote-Debugging auf Port 9222.
3. Einmalige Anmeldung im verwendeten Browserprofil am SV-Netzwerk-Portal und in Claimsforce.

Einrichtung:

```powershell
npm install
node worker.mjs --install-startup
node worker.mjs
```

Die endgültige Dokumentbefüllung und die nativen SE/ZB werden als getrennte Worker-Phasen ergänzt. Der Worker startet bewusst mit der Aktenaufnahme und hält bei einer noch nicht implementierten Schreibphase an, statt Formulare zu verändern.
