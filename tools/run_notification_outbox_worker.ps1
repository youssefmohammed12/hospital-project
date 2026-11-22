$ErrorActionPreference = 'Stop'

$PhpPath = 'C:\xampp85\php\php.exe'
$WorkerPath = Join-Path $PSScriptRoot 'process_notification_outbox.php'
$LogDirectory = 'C:\xampp85\logs\healthbridge\notification-outbox'

try {
    if (-not (Test-Path -LiteralPath $PhpPath)) {
        throw "PHP CLI not found: $PhpPath"
    }
    if (-not (Test-Path -LiteralPath $WorkerPath)) {
        throw "Outbox worker not found: $WorkerPath"
    }

    New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
    $LogFile = Join-Path $LogDirectory ('worker-' + (Get-Date -Format 'yyyy-MM-dd') + '.log')
    Add-Content -LiteralPath $LogFile -Value ("[{0}] START" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))

    $origEap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $PhpPath $WorkerPath '--limit=100' 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $origEap
    }

    foreach ($line in $output) {
        Add-Content -LiteralPath $LogFile -Value ([string]$line)
    }
    Add-Content -LiteralPath $LogFile -Value ("[{0}] EXIT_CODE={1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $exitCode)

    Get-ChildItem -LiteralPath $LogDirectory -Filter 'worker-*.log' -File -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-30) } |
        Remove-Item -Force -ErrorAction SilentlyContinue

    exit $exitCode
}
catch {
    try {
        New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
        $LogFile = Join-Path $LogDirectory ('worker-' + (Get-Date -Format 'yyyy-MM-dd') + '.log')
        $safeMsg = ($_.Exception.Message -replace "[\r\n]+", " ").Trim()
        Add-Content -LiteralPath $LogFile -Value ("[{0}] WRAPPER_ERROR={1}: {2}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $_.Exception.GetType().FullName, $safeMsg)
    } catch {}
    exit 1
}