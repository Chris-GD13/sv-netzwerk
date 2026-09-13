$ErrorActionPreference = 'Stop'
$oneDrive = Get-ChildItem -Path $env:USERPROFILE -Directory -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'OneDrive*SV Büro Marc Schütt*' } | Select-Object -First 1
if (-not $oneDrive) { throw 'OneDrive-Ordner des SV-Büros nicht gefunden.' }
$roots = @(
  (Join-Path $oneDrive.FullName 'VS Schäden\Marc\Privatgutachten'),
  (Join-Path $oneDrive.FullName 'VS Schäden\Christian\Privatgutachten_NL Süd')
)
$folders = 'Auftrag','Fotos','Gutachten','Angebot','Rechnungen','E-Mails','Bilder','Dateien','Sonstige Unterlagen','Eingang KI'
function Get-Category([System.IO.FileInfo]$file) {
  $n = $file.Name.ToLowerInvariant()
  if ($file.Extension -in '.msg','.eml') { return 'E-Mails' }
  if ($file.Extension -in '.jpg','.jpeg','.png','.gif','.webp','.heic','.tif','.tiff','.bmp','.mov','.mp4','.m4v','.avi','.mts','.3gp') { return 'Bilder' }
  if ($n -match 'rechnung|honorar|invoice') { return 'Rechnungen' }
  if ($n -match 'gutachten|stellungnahme|expertise') { return 'Gutachten' }
  if ($n -match 'angebot|kostenvoranschlag|(^|[^a-z])kva([^a-z]|$)') { return 'Angebot' }
  if ($n -match 'vertrag|vereinbarung|vollmacht|auftrag') { return 'Auftrag' }
  return 'Dateien'
}
foreach ($root in $roots) {
  if (-not (Test-Path -LiteralPath $root)) { continue }
  $cases = Get-ChildItem -LiteralPath $root -Directory -Force
  foreach ($year in $cases | Where-Object Name -Match '^(19|20)\d{2}$') { $cases += Get-ChildItem -LiteralPath $year.FullName -Directory -Force }
  foreach ($case in $cases | Where-Object { $_.Name -notmatch '^(19|20)\d{2}$' }) {
    foreach ($folder in $folders) { New-Item -ItemType Directory -Path (Join-Path $case.FullName $folder) -Force | Out-Null }
    Get-ChildItem -LiteralPath $case.FullName -File -Force | ForEach-Object {
      $target = Join-Path $case.FullName (Get-Category $_)
      Move-Item -LiteralPath $_.FullName -Destination (Join-Path $target $_.Name) -Force
    }
  }
}
Write-Host 'Bestandssortierung für Marc und Christian abgeschlossen.'
