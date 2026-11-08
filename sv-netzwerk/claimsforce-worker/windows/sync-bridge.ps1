$ErrorActionPreference = 'Stop'
$target = 'C:\Users\chris\OneDrive\Dokumente\Brücke'
$source = 'https://www.sv-netzwerk.eu/intern/downloads/svnet-claimsforce-bridge.zip'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$tmp = Join-Path $env:TEMP ('svnet-bridge-' + [guid]::NewGuid().ToString('N') + '.zip')
$stage = Join-Path $env:TEMP ('svnet-bridge-stage-' + [guid]::NewGuid().ToString('N'))
try {
  Invoke-WebRequest -Uri $source -OutFile $tmp -UseBasicParsing
  Expand-Archive -Path $tmp -DestinationPath $stage -Force
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null
  $backup = "$target.backup"
  if (Test-Path $backup) { Remove-Item $backup -Recurse -Force }
  if (Test-Path $target) { Move-Item $target $backup -Force }
  Move-Item $stage $target -Force
  Set-Content -Path (Join-Path $target '.svnet-bridge-source') -Value $source -Encoding UTF8
  Write-Host "SVNET_BRIDGE_EXTRACTED $target"
} finally {
  if (Test-Path $tmp) { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
  if (Test-Path $stage) { Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue }
}
