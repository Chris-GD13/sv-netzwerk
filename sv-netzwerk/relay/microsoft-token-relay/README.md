# Microsoft-Token-Relay

Microsoft weist die IONOS-IPs des Portal-Servers am Token-Endpunkt
(`login.microsoftonline.com`) ab (HTTP 404). Dieser Cloudflare Worker leitet
nur Token-Anfragen weiter. Graph-Aufrufe laufen weiter direkt vom Server.

## Einrichten
1. `cd sv-netzwerk/relay/microsoft-token-relay`
2. `npx wrangler login`, dann `npx wrangler secret put RELAY_KEY` (langer Zufallswert) und `npx wrangler deploy`.
3. In der VPS-`.env` setzen: `MS_LOGIN_BASE=https://<worker>.workers.dev/<RELAY_KEY>`
4. Testen: Versand im Portal oder Token-Abruf. Zurück auf Direktbetrieb: `MS_LOGIN_BASE` entfernen.

## Hinweise
- Der Relay sieht das Client-Secret in der Token-Anfrage. Er speichert und protokolliert nichts; den Schlüssel `RELAY_KEY` geheim halten.
- Der Standard bleibt `https://login.microsoftonline.com`, solange `MS_LOGIN_BASE` fehlt.
