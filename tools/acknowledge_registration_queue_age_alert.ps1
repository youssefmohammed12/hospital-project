[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateLength(3, 240)]
    [string]$Note,
    [string]$LogDirectory = 'C:\xampp85\logs\healthbridge\registration-intake'
)

$ErrorActionPreference = 'Stop'
$allowedDirectories = @(
    'C:\xampp85\logs\healthbridge\registration-intake',
    'C:\xampp85\migration-backups\healthbridge\hb-auth-001-real-queue-age-scheduled-20261120\qa-logs'
)
$resolvedDirectory = [IO.Path]::GetFullPath($LogDirectory).TrimEnd('\')
if ($resolvedDirectory -notin $allowedDirectories) {
    throw 'Log directory is outside the explicit local production/QA allowlist.'
}
if (-not (Test-Path -LiteralPath $resolvedDirectory -PathType Container)) {
    throw 'Queue-age log directory does not exist.'
}

$markerPath = Join-Path $resolvedDirectory 'queue-age-alert.json'
$logPath = Join-Path $resolvedDirectory 'queue-age-alerts.log'
if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf)) {
    throw 'No open queue-age alert exists to acknowledge.'
}

$marker = Get-Content -LiteralPath $markerPath -Raw | ConvertFrom-Json -ErrorAction Stop
if ([string]$marker.status -ne 'open') {
    throw 'Only an open queue-age alert can be acknowledged.'
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
if ([string]::IsNullOrWhiteSpace($identity)) {
    throw 'Windows operator identity could not be determined.'
}
$identity = $identity -replace '[^A-Za-z0-9\\._@-]', '_'
$cleanNote = ($Note -replace '[\x00-\x1F\x7F]', ' ') -replace '\s{2,}', ' '
$cleanNote = $cleanNote.Trim()
if ($cleanNote.Length -lt 3 -or $cleanNote.Length -gt 240) {
    throw 'Acknowledgement note must contain 3–240 printable characters.'
}

$existingBy = [string]$marker.acknowledged_by
if (-not [string]::IsNullOrWhiteSpace($existingBy)) {
    if ($existingBy -eq $identity) {
        Write-Output 'QUEUE_AGE_ALERT=ALREADY_ACKNOWLEDGED'
        Write-Output ('ACKNOWLEDGED_BY=' + $existingBy)
        exit 0
    }
    throw 'This alert is already owned by another Windows operator; reassignment requires a separate reviewed action.'
}

$markerMap = @{}
foreach ($property in $marker.PSObject.Properties) {
    $markerMap[$property.Name] = $property.Value
}
$timestamp = (Get-Date).ToString('o')
$markerMap['owner'] = $identity
$markerMap['acknowledged_by'] = $identity
$markerMap['acknowledged_at'] = $timestamp
$markerMap['acknowledgement_note'] = $cleanNote

$tempPath = $markerPath + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
try {
    $json = ConvertTo-Json -InputObject $markerMap -Depth 5
    [IO.File]::WriteAllText($tempPath, $json, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $tempPath -Destination $markerPath -Force
}
finally {
    if (Test-Path -LiteralPath $tempPath) {
        Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
    }
}

$logNote = $cleanNote -replace '[\r\n]', ' '
Add-Content -LiteralPath $logPath -Value "[$timestamp] QUEUE_AGE_ALERT=ACKNOWLEDGED acknowledged_by=$identity note=$logNote" -Encoding UTF8
Write-Output 'QUEUE_AGE_ALERT=ACKNOWLEDGED'
Write-Output ('ACKNOWLEDGED_BY=' + $identity)
Write-Output ('ACKNOWLEDGED_AT=' + $timestamp)
Write-Output 'ALERT_STATUS=OPEN'