param()

$ErrorActionPreference = 'Stop'

Write-Host 'SV-Netzwerk Telefonverknuepfung wird eingerichtet ...' -ForegroundColor Cyan

$ctiPath = $null
$running = Get-Process -Name 'cti_client' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($running -and $running.Path) {
    $ctiPath = $running.Path
}

$candidates = @(
    (Join-Path $env:ProgramFiles 'CTI Client\cti_client.exe'),
    (Join-Path $env:ProgramFiles 'xtelsio CTI Client\cti_client.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'CTI Client\cti_client.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'xtelsio CTI Client\cti_client.exe')
)

if (-not $ctiPath) {
    $ctiPath = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
}

if (-not $ctiPath) {
    throw 'xtelsio CTI Client wurde nicht gefunden. Bitte xtelsio starten und die Einrichtung erneut ausfuehren.'
}

$installDirectory = Join-Path $env:LOCALAPPDATA 'SV-Netzwerk\Telefon'
New-Item -ItemType Directory -Path $installDirectory -Force | Out-Null
$handlerPath = Join-Path $installDirectory 'SVNetzwerk-Telefon-Handler.ps1'

$handler = @'
param([Parameter(Mandatory=$true)][string]$Uri)

$ErrorActionPreference = 'Stop'
$parsed = $null
if (-not [Uri]::TryCreate($Uri, [UriKind]::Absolute, [ref]$parsed)) { exit 2 }
if ($parsed.Scheme -ne 'svnet-xtelsio') { exit 3 }

$settings = Get-ItemProperty 'HKCU:\Software\SV-Netzwerk\Telefon' -ErrorAction Stop
$ctiPath = [string]$settings.CtiPath
if (-not (Test-Path $ctiPath)) { exit 4 }

$action = $parsed.Host.ToLowerInvariant()
switch ($action) {
    'answer' { $commandArguments = @('/answer', '-ringing') }
    'drop'   { $commandArguments = @('/drop', '-current') }
    default  { exit 5 }
}

Start-Process -FilePath $ctiPath -ArgumentList $commandArguments -WindowStyle Hidden
'@

Set-Content -Path $handlerPath -Value $handler -Encoding UTF8

$settingsKey = 'HKCU:\Software\SV-Netzwerk\Telefon'
New-Item -Path $settingsKey -Force | Out-Null
Set-ItemProperty -Path $settingsKey -Name 'CtiPath' -Value $ctiPath

$protocolKey = 'HKCU:\Software\Classes\svnet-xtelsio'
New-Item -Path $protocolKey -Force | Out-Null
(Get-Item $protocolKey).SetValue('', 'URL:SV-Netzwerk xtelsio-Steuerung')
New-ItemProperty -Path $protocolKey -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
New-Item -Path "$protocolKey\shell\open\command" -Force | Out-Null

$powershellPath = (Get-Command powershell.exe).Source
$openCommand = '"' + $powershellPath + '" -NoProfile -ExecutionPolicy Bypass -File "' + $handlerPath + '" "%1"'
(Get-Item "$protocolKey\shell\open\command").SetValue('', $openCommand)

Write-Host ''
Write-Host 'Einrichtung abgeschlossen.' -ForegroundColor Green
Write-Host ('Verwendeter xtelsio CTI Client: ' + $ctiPath)
Write-Host 'Die Telefonzentrale im SV-Netzwerk kann jetzt Anrufe annehmen und beenden.'
Read-Host 'Mit Eingabetaste schliessen'
