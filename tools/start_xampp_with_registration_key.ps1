$ErrorActionPreference = 'Stop'

$XamppRoot = 'C:\xampp85'
$ControlPanel = Join-Path $XamppRoot 'xampp-control.exe'
$HttpdPath = Join-Path $XamppRoot 'apache\bin\httpd.exe'
$KeyLoaderPath = Join-Path $PSScriptRoot 'RegistrationQueueKeyEnvironment.ps1'
$keyLoaded = $false

try {
    if (Get-Process -Name 'httpd' -ErrorAction SilentlyContinue) {
        throw 'Apache is already running. Stop Apache first, then rerun this launcher so it inherits the registration key.'
    }
    if (-not (Test-Path -LiteralPath $ControlPanel) -or -not (Test-Path -LiteralPath $HttpdPath)) {
        throw 'XAMPP Apache launch files are unavailable.'
    }
    if (-not (Test-Path -LiteralPath $KeyLoaderPath)) {
        throw 'The registration queue key loader is unavailable.'
    }

    . $KeyLoaderPath
    Set-HealthBridgeRegistrationQueueEnvironment
    $keyLoaded = $true

    if (-not (Get-Process -Name 'xampp-control' -ErrorAction SilentlyContinue)) {
        Start-Process -FilePath $ControlPanel | Out-Null
        Start-Sleep -Milliseconds 800
    }

    $httpd = Start-Process -FilePath $HttpdPath -ArgumentList @('-d', 'C:/xampp85/apache') -WorkingDirectory $XamppRoot -PassThru
    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        Start-Sleep -Milliseconds 250
        if (Get-NetTCPConnection -State Listen -LocalPort 80 -ErrorAction SilentlyContinue) {
            $ready = $true
            break
        }
        if ($httpd.HasExited) {
            throw ('Apache exited unexpectedly with code ' + $httpd.ExitCode)
        }
    }

    if (-not $ready) {
        throw 'Apache did not begin listening on port 80. Check the XAMPP Apache logs.'
    }

    Write-Output 'APACHE_STARTED_WITH_PROCESS_SCOPED_QUEUE_KEY=YES'
    Write-Output 'XAMPP_CONTROL_PANEL_AVAILABLE=YES'
    Write-Output 'REGISTRATION_QUEUE_KEY_VALUE=NOT_DISPLAYED'
}
finally {
    if ($keyLoaded -and (Get-Command Clear-HealthBridgeRegistrationQueueEnvironment -ErrorAction SilentlyContinue)) {
        Clear-HealthBridgeRegistrationQueueEnvironment
    }
}