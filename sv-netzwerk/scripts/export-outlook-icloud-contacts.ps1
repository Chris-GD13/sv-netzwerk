param(
    [Parameter(Mandatory = $true)]
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
$resolvedOutput = [IO.Path]::GetFullPath($OutputPath)
$outputDirectory = [IO.Path]::GetDirectoryName($resolvedOutput)
[IO.Directory]::CreateDirectory($outputDirectory) | Out-Null
$partialPath = "$resolvedOutput.partial"

$outlook = New-Object -ComObject Outlook.Application
$namespace = $outlook.GetNamespace('MAPI')
$store = $namespace.Stores | Where-Object DisplayName -eq 'iCloud' | Select-Object -First 1
if (-not $store) {
    throw 'Der Outlook-Datenspeicher "iCloud" wurde nicht gefunden.'
}

$contacts = $store.GetRootFolder().Folders | Where-Object { $_.DefaultItemType -eq 2 } | Select-Object -First 1
if (-not $contacts) {
    throw 'Der iCloud-Kontakteordner wurde in Outlook nicht gefunden.'
}

$encoding = New-Object Text.UTF8Encoding($false)
$writer = New-Object IO.StreamWriter($partialPath, $false, $encoding)
$exported = 0
$failed = 0

function ConvertTo-VCardValue([object]$Value) {
    if ($null -eq $Value) { return '' }
    return ([string]$Value).Replace('\', '\\').Replace("`r`n", '\n').Replace("`n", '\n').Replace(';', '\;').Replace(',', '\,').Trim()
}

function Add-VCardLine([Collections.Generic.List[string]]$Lines, [string]$Name, [object]$Value) {
    $text = ConvertTo-VCardValue $Value
    if ($text) { $Lines.Add("${Name}:$text") }
}

function Get-RowValue([object]$Row, [string]$Name) {
    try { return $Row.Item($Name) } catch { return '' }
}

$columnNames = @(
    'FullName', 'FirstName', 'LastName', 'MiddleName', 'Title', 'Suffix',
    'CompanyName', 'JobTitle', 'MobileTelephoneNumber', 'BusinessTelephoneNumber',
    'Business2TelephoneNumber', 'HomeTelephoneNumber', 'Home2TelephoneNumber',
    'OtherTelephoneNumber', 'BusinessFaxNumber', 'HomeFaxNumber', 'PagerNumber',
    'Email1Address', 'Email2Address', 'Email3Address',
    'BusinessAddressStreet', 'BusinessAddressCity', 'BusinessAddressState',
    'BusinessAddressPostalCode', 'BusinessAddressCountry',
    'HomeAddressStreet', 'HomeAddressCity', 'HomeAddressState',
    'HomeAddressPostalCode', 'HomeAddressCountry', 'BusinessHomePage', 'Body', 'Categories'
)
$table = $contacts.GetTable()
foreach ($columnName in $columnNames) {
    try { $table.Columns.Add($columnName) | Out-Null } catch {}
}
$total = $table.GetRowCount()

try {
    $index = 0
    while (-not $table.EndOfTable) {
        $index += 1
        try {
            $item = $table.GetNextRow()
            $lines = New-Object 'Collections.Generic.List[string]'
            $lines.Add('BEGIN:VCARD')
            $lines.Add('VERSION:3.0')
            $fullNameValue = Get-RowValue $item 'FullName'
            $companyValue = Get-RowValue $item 'CompanyName'
            $fullName = if ($fullNameValue) { $fullNameValue } elseif ($companyValue) { $companyValue } else { 'Unbenannter Kontakt' }
            Add-VCardLine $lines 'FN' $fullName
            $family = ConvertTo-VCardValue (Get-RowValue $item 'LastName')
            $given = ConvertTo-VCardValue (Get-RowValue $item 'FirstName')
            $middle = ConvertTo-VCardValue (Get-RowValue $item 'MiddleName')
            $prefix = ConvertTo-VCardValue (Get-RowValue $item 'Title')
            $suffix = ConvertTo-VCardValue (Get-RowValue $item 'Suffix')
            $lines.Add("N:$family;$given;$middle;$prefix;$suffix")
            Add-VCardLine $lines 'ORG' $companyValue
            Add-VCardLine $lines 'TITLE' (Get-RowValue $item 'JobTitle')
            Add-VCardLine $lines 'TEL;TYPE=CELL' (Get-RowValue $item 'MobileTelephoneNumber')
            Add-VCardLine $lines 'TEL;TYPE=WORK' (Get-RowValue $item 'BusinessTelephoneNumber')
            Add-VCardLine $lines 'TEL;TYPE=WORK' (Get-RowValue $item 'Business2TelephoneNumber')
            Add-VCardLine $lines 'TEL;TYPE=HOME' (Get-RowValue $item 'HomeTelephoneNumber')
            Add-VCardLine $lines 'TEL;TYPE=HOME' (Get-RowValue $item 'Home2TelephoneNumber')
            Add-VCardLine $lines 'TEL;TYPE=VOICE' (Get-RowValue $item 'OtherTelephoneNumber')
            Add-VCardLine $lines 'TEL;TYPE=FAX,WORK' (Get-RowValue $item 'BusinessFaxNumber')
            Add-VCardLine $lines 'TEL;TYPE=FAX,HOME' (Get-RowValue $item 'HomeFaxNumber')
            Add-VCardLine $lines 'TEL;TYPE=PAGER' (Get-RowValue $item 'PagerNumber')
            Add-VCardLine $lines 'EMAIL;TYPE=INTERNET' (Get-RowValue $item 'Email1Address')
            Add-VCardLine $lines 'EMAIL;TYPE=INTERNET' (Get-RowValue $item 'Email2Address')
            Add-VCardLine $lines 'EMAIL;TYPE=INTERNET' (Get-RowValue $item 'Email3Address')
            $businessStreet = Get-RowValue $item 'BusinessAddressStreet'
            $businessCity = Get-RowValue $item 'BusinessAddressCity'
            $businessPostalCode = Get-RowValue $item 'BusinessAddressPostalCode'
            if ($businessStreet -or $businessCity -or $businessPostalCode) {
                $lines.Add(('ADR;TYPE=WORK:;;{0};{1};{2};{3};{4}' -f
                    (ConvertTo-VCardValue $businessStreet),
                    (ConvertTo-VCardValue $businessCity),
                    (ConvertTo-VCardValue (Get-RowValue $item 'BusinessAddressState')),
                    (ConvertTo-VCardValue $businessPostalCode),
                    (ConvertTo-VCardValue (Get-RowValue $item 'BusinessAddressCountry'))))
            }
            $homeStreet = Get-RowValue $item 'HomeAddressStreet'
            $homeCity = Get-RowValue $item 'HomeAddressCity'
            $homePostalCode = Get-RowValue $item 'HomeAddressPostalCode'
            if ($homeStreet -or $homeCity -or $homePostalCode) {
                $lines.Add(('ADR;TYPE=HOME:;;{0};{1};{2};{3};{4}' -f
                    (ConvertTo-VCardValue $homeStreet),
                    (ConvertTo-VCardValue $homeCity),
                    (ConvertTo-VCardValue (Get-RowValue $item 'HomeAddressState')),
                    (ConvertTo-VCardValue $homePostalCode),
                    (ConvertTo-VCardValue (Get-RowValue $item 'HomeAddressCountry'))))
            }
            Add-VCardLine $lines 'URL' (Get-RowValue $item 'BusinessHomePage')
            Add-VCardLine $lines 'NOTE' (Get-RowValue $item 'Body')
            Add-VCardLine $lines 'CATEGORIES' (Get-RowValue $item 'Categories')
            $lines.Add('END:VCARD')
            $writer.WriteLine(($lines -join "`r`n"))
            $exported += 1
        } catch {
            $failed += 1
        }

        if (($index % 500) -eq 0) {
            $writer.Flush()
            Write-Output "Geprueft: $index / $total; exportiert: $exported; Fehler: $failed"
        }
    }
} finally {
    $writer.Dispose()
}

Move-Item -LiteralPath $partialPath -Destination $resolvedOutput -Force
Write-Output "Fertig: $exported Kontakte exportiert; $failed Fehler; Ausgabe: $resolvedOutput"
