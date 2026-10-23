#!/usr/bin/env bash
set -euo pipefail
install -d -m 750 /opt/sv-netzwerk/claimsforce-worker
if ! command -v node >/dev/null 2>&1; then
  apt-get update
  apt-get install --yes nodejs npm
fi
if ! command -v chromium >/dev/null 2>&1 && ! command -v chromium-browser >/dev/null 2>&1; then
  apt-get update
  apt-get install --yes chromium
fi
cat > /opt/sv-netzwerk/claimsforce-worker/package.json <<'JSON'
{"name":"sv-netzwerk-claimsforce-worker","private":true,"type":"module","dependencies":{"playwright-core":"^1.55.0"}}
JSON
cd /opt/sv-netzwerk/claimsforce-worker
npm install --omit=dev --no-audit --no-fund
cat > /opt/sv-netzwerk/claimsforce-worker/README.txt <<'TXT'
Claimsforce Browser Worker Grundinstallation
Domain: sv-netzwerk.eu
Profile isolation: christian, marc, holger-roth, jens-maurer
Versand bleibt gesperrt.
TXT
chmod 640 /opt/sv-netzwerk/claimsforce-worker/README.txt
echo "CLAIMSFORCE_BROWSER_WORKER_INSTALL_OK"
