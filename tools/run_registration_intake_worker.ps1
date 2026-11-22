$ErrorActionPreference = 'Stop'

$PhpPath = 'C:\xampp85\php\php.exe'
$WorkerPath = Join-Path $PSScriptRoot 'process_registration_intake.php'
$MailAlertPath = Join-Path $PSScriptRoot 'send_registration_queue_alert.php'
$KeyLoaderPath = Join-Path $PSScriptRoot 'RegistrationQueueKeyEnvironment.ps1'
$AlertHelperPath = Join-Path $PSScriptRoot 'RegistrationQueueAgeAlert.ps1'
$LogDirectory = 'C:\xampp85\logs\healthbridge\registration-intake'
$keyLoaded = $false

try {
    if (-not (Test-Path -LiteralPath $PhpPath)) { throw 'PHP CLI executable is unavailable.' }
    if (-not (Test-Path -LiteralPath $WorkerPath)) { throw 'Registration intake worker script is unavailable.' }
    if (-not (Test-Path -LiteralPath $MailAlertPath)) { throw 'Registration queue alert email sender is unavailable.' }
    if (-not (Test-Path -LiteralPath $KeyLoaderPath)) { throw 'Registration key loader is unavailable.' }
    if (-not (Test-Path -LiteralPath $AlertHelperPath)) { throw 'Registration queue-age alert helper is unavailable.' }

    . $AlertHelperPath
    . $KeyLoaderPath
    Set-HealthBridgeRegistrationQueueEnvironment
    $keyLoaded = $true

    New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
    $LogFile = Join-Path $LogDirectory ('worker-' + (Get-Date -Format 'yyyy-MM-dd') + '.log')
    Add-Content -LiteralPath $LogFile -Value ("[{0}] START" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))

    $originalPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $PhpPath $WorkerPath '--limit=50' 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $originalPreference
    }

    foreach ($line in $output) {
        Add-Content -LiteralPath $LogFile -Value ([string]$line)
    }

    $jsonLine = @($output | ForEach-Object { [string]$_ } | Where-Object { $_.Trim().StartsWith('{') } | Select-Object -Last 1)
    if ($jsonLine.Count -eq 0) { throw 'Registration intake worker did not emit its queue-age status.' }

    try {
        $stats = [string]$jsonLine[0] | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        throw 'Registration intake worker emitted invalid status JSON.'
    }

    $alert = Write-RegistrationQueueAgeAlert -Stats $stats -LogDirectory $LogDirectory
    if ($alert.Message) {
        Add-Content -LiteralPath $LogFile -Value ([string]$alert.Message)
    }

    $emailDeliveryFailed = [bool]$alert.EmailDeliveryPending
    if ($alert.EventType) {
        $mailOutput = $null
        $mailExitCode = 1
        $originalPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            $mailOutput = & $PhpPath $MailAlertPath ("--event=" + [string]$alert.EventType) ("--age-seconds=" + [string]$alert.AgeSeconds) ("--first-seen-at=" + [string]$alert.FirstSeenAt) 2>&1
            $mailExitCode = $LASTEXITCODE
        }
        finally {
            $ErrorActionPreference = $originalPreference
        }

        foreach ($line in $mailOutput) {
            Add-Content -LiteralPath $LogFile -Value ([string]$line)
        }

        $emailSent = ($mailExitCode -eq 0)
        $emailDeliveryFailed = -not $emailSent
        Set-RegistrationQueueAgeAlertDeliveryResult -LogDirectory $LogDirectory -EventType ([string]$alert.EventType) -Success $emailSent

        if ($emailSent) {
            Add-Content -LiteralPath $LogFile -Value ("[{0}] EMAIL_DELIVERY=SUCCESS EVENT={1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $alert.EventType)
        }
        else {
            $emailDeliveryFailed = $true
            Add-Content -LiteralPath $LogFile -Value ("[{0}] EMAIL_DELIVERY=FAILED EVENT={1} RETRY_MINUTES=5" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $alert.EventType)
        }
    }

    $exitCode = Get-RegistrationQueueWorkerExitCode -WorkerExitCode ([int]$exitCode) -QueueAgeWarning ([bool]$alert.IsWarning) -EmailDeliveryFailed $emailDeliveryFailed
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
        Add-Content -LiteralPath $LogFile -Value ("[{0}] WRAPPER_ERROR={1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $_.Exception.GetType().FullName)
    } catch {}
    exit 1
}
finally {
    if ($keyLoaded -and (Get-Command Clear-HealthBridgeRegistrationQueueEnvironment -ErrorAction SilentlyContinue)) {
        Clear-HealthBridgeRegistrationQueueEnvironment
    }
}
