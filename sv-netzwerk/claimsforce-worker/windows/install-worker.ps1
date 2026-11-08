$ErrorActionPreference = 'Stop'
$base = Join-Path $env:LOCALAPPDATA 'SV-Netzwerk\LangdockWorker'
New-Item -ItemType Directory -Force -Path $base | Out-Null
$repo = 'https://raw.githubusercontent.com/Chris-GD13/sv-netzwerk/main/sv-netzwerk/claimsforce-worker/windows'
Invoke-WebRequest "$repo/package.json" -OutFile (Join-Path $base 'package.json')
Invoke-WebRequest "$repo/worker.mjs" -OutFile (Join-Path $base 'worker.mjs')
Invoke-WebRequest "$repo/start-worker.cmd" -OutFile (Join-Path $base 'start-worker.cmd')
Invoke-WebRequest "$repo/sync-bridge.ps1" -OutFile (Join-Path $base 'sync-bridge.ps1')
if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Node.js 20 oder neuer muss zuerst installiert werden.' }
Push-Location $base
npm.cmd install --omit=dev --no-audit --no-fund
node worker.mjs --install-startup
Pop-Location
Write-Host "SVNET_LANGDOCK_WORKER_INSTALLED $base"
