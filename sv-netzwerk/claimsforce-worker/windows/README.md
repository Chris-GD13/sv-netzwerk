# SV-Netzwerk Langdock Worker für Windows

Der Worker läuft lokal auf dem PC und verwendet eine bereits angemeldete Chrome- oder Edge-Sitzung.

Bei jedem Start wird die aktuelle Claimsforce-Brücke automatisch aus dem Portal geladen und nach

`C:\Users\chris\OneDrive\Dokumente\Brücke`

entpackt. Chrome und Edge lesen diese Fassung beim nächsten Neustart ein.

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

Der Worker übernimmt gespeicherte Langdock-Aufträge, hält die Phase fest und setzt nach Verbindungsabbrüchen fort. Die Dokumentbefüllung und die nativen SE/ZB werden als nächste feste Phasen ergänzt.
