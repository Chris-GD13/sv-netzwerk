[CmdletBinding(SupportsShouldProcess=$true)]
param([ValidateSet('Christian','Marc')][string]$Profile='Christian',[string]$Root,[string]$CaseName)
$ErrorActionPreference='Stop'
$relative=if($Profile -eq 'Christian'){'VS Schäden\Christian\Privatgutachten_NL Süd'}else{'VS Schäden\Marc\Privatgutachten'}
if(-not $Root){
  $candidates=@(Get-ChildItem -LiteralPath $env:USERPROFILE -Directory -Force | Where-Object Name -like 'OneDrive*SV Büro Marc Schütt*' | ForEach-Object {Join-Path $_.FullName $relative; Join-Path $_.FullName ('Dokumente - SV Büro Schütt\'+$relative)} | Where-Object {Test-Path -LiteralPath $_ -PathType Container})
  if($candidates.Count -ne 1){throw 'Stammordner nicht eindeutig gefunden. Bitte -Root angeben.'};$Root=$candidates[0]
}
$Root=(Resolve-Path -LiteralPath $Root).Path.TrimEnd('\')
if(-not $Root.EndsWith($relative,[StringComparison]::OrdinalIgnoreCase)){throw 'Stammordner passt nicht zum gewählten Profil.'}
$folders=@('Auftrag','Fotos','Gutachten','Angebot','Rechnungen','E-Mails','Bilder','Dateien','Sonstige Unterlagen','Eingang KI')
function Get-Category([IO.FileInfo]$File){
 $n=$File.Name.ToLowerInvariant();$ext=$File.Extension.ToLowerInvariant()
 if($ext -in '.msg','.eml'){return 'E-Mails'}
 if($ext -in '.m4a','.mp3','.wav','.aac' -or $n -match 'transcri|transkrip|plaud'){return 'Eingang KI'}
 if($ext -in '.jpg','.jpeg','.png','.gif','.webp','.heic','.heif','.tif','.tiff','.bmp','.mov','.mp4','.m4v','.avi','.mts','.3gp' -or $n -match 'fotos|bilder|medien|icloud'){return 'Fotos'}
 if($n -match 'vertrag|vereinbarung|vollmacht|auftrag|bestellung'){return 'Auftrag'}
 if($n -match '(?<!be)rechnung|honorar|invoice'){return 'Rechnungen'}
 if($n -match 'gutachten|stellungnahme|expertise|wertermittlung|bericht'){return 'Gutachten'}
 if($n -match 'angebot|kostenvoranschlag|(^|[^a-z])kv[ _-]|(^|[^a-z])kva([^a-z]|$)'){return 'Angebot'}
 return 'Sonstige Unterlagen'
}
$top=@(Get-ChildItem -LiteralPath $Root -Directory -Force)
$cases=@($top | Where-Object {$_.Name -notmatch '^(19|20)\d{2}$' -and $_.Name -notin ($folders+'Versicherungsaufträge')})
foreach($year in $top | Where-Object Name -match '^(19|20)\d{2}$'){$cases+=@(Get-ChildItem -LiteralPath $year.FullName -Directory -Force | Where-Object {$_.Name -notin $folders})}
if($CaseName){$cases=@($cases | Where-Object Name -eq $CaseName);if($cases.Count -ne 1){throw 'Auftrag nicht eindeutig gefunden.'}}
$moved=0;$skipped=0
foreach($case in $cases){
 $casePath=(Resolve-Path -LiteralPath $case.FullName).Path.TrimEnd('\')
 if(-not $casePath.StartsWith($Root+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Auftrag außerhalb des Stammordners.'}
 foreach($folder in $folders){$targetDir=Join-Path $casePath $folder;if(-not(Test-Path -LiteralPath $targetDir) -and $PSCmdlet.ShouldProcess($targetDir,'Ordner anlegen')){[IO.Directory]::CreateDirectory($targetDir)|Out-Null}}
 foreach($file in Get-ChildItem -LiteralPath $casePath -File -Force){
  if($file.Extension -in '.ps1','.ini','.lnk','.tmp' -or $file.Name -like '~$*'){continue}
  $target=[IO.Path]::GetFullPath((Join-Path (Join-Path $casePath (Get-Category $file)) $file.Name))
  if(-not $target.StartsWith($casePath+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Ziel außerhalb des Auftrags.'}
  if(Test-Path -LiteralPath $target){$skipped++;Write-Warning "Zieldatei vorhanden, Original bleibt erhalten: $($file.Name)";continue}
  if($PSCmdlet.ShouldProcess($file.FullName,"Verschieben nach $target")){Move-Item -LiteralPath $file.FullName -Destination $target;$moved++}
 }
}
Write-Host "Profil $Profile – $($cases.Count) Aufträge geprüft, $moved Dateien verschoben, $skipped vorhandene Ziele unverändert."