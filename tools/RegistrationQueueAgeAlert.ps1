function Save-RegistrationQueueAgeMarker {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][psobject]$Marker
    )

    $temporaryPath = $Path + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    try {
        $json = ConvertTo-Json -InputObject $Marker -Depth 5
        [IO.File]::WriteAllText($temporaryPath, $json, [Text.UTF8Encoding]::new($false))
        Move-Item -LiteralPath $temporaryPath -Destination $Path -Force
    }
    finally {
        if (Test-Path -LiteralPath $temporaryPath) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
    }
}

function Read-RegistrationQueueAgeMarker {
    param([Parameter(Mandatory = $true)][string]$Path)

    try {
        $marker = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json -ErrorAction Stop
        $markerStatus = [string]$marker.status
        $firstSeenAt = [string]$marker.first_seen_at
        if (($markerStatus -ne 'open' -and $markerStatus -ne 'resolved') -or $firstSeenAt -eq '') {
            throw 'Invalid marker shape.'
        }
        return $marker
    }
    catch {
        throw 'The existing queue-age alert marker is unreadable; manual inspection is required.'
    }
}

function Test-RegistrationQueueAlertRetryDelay {
    param(
        [Parameter(Mandatory = $true)][psobject]$Marker,
        [Parameter(Mandatory = $true)][datetime]$Now,
        [Parameter(Mandatory = $true)][ValidateSet('OPEN', 'REMINDER', 'RESOLVED')][string]$EventType,
        [ValidateRange(1, 1440)][int]$RetryMinutes = 5
    )

    if ([string]$Marker.last_email_attempted_event -ne $EventType) {
        return $false
    }

    $lastAttemptText = [string]$Marker.last_email_attempted_at
    if ([string]::IsNullOrWhiteSpace($lastAttemptText)) {
        return $false
    }

    try {
        $lastAttempt = [datetime]::Parse(
            $lastAttemptText,
            [Globalization.CultureInfo]::InvariantCulture,
            [Globalization.DateTimeStyles]::RoundtripKind
        )
    }
    catch {
        throw 'The queue-age email attempt timestamp is invalid; manual inspection is required.'
    }

    return (($Now - $lastAttempt).TotalMinutes -lt $RetryMinutes)
}

function Write-RegistrationQueueAgeAlert {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Stats,
        [Parameter(Mandatory = $true)][string]$LogDirectory,
        [datetime]$Now = (Get-Date),
        [ValidateRange(1, 1440)][int]$ReminderMinutes = 30,
        [ValidateRange(1, 1440)][int]$RetryMinutes = 5
    )

    $ageProperty = $Stats.PSObject.Properties['oldest_pending_age_seconds']
    $warningProperty = $Stats.PSObject.Properties['queue_age_warning']
    if ($null -eq $ageProperty -or $null -eq $warningProperty) {
        throw 'Registration worker output is missing queue-age fields.'
    }

    $ageSeconds = [int]$ageProperty.Value
    $warning = [bool]$warningProperty.Value
    if ($ageSeconds -lt 0 -or $warning -ne ($ageSeconds -gt 120)) {
        throw 'Registration worker queue-age fields are inconsistent.'
    }

    if (-not (Test-Path -LiteralPath $LogDirectory -PathType Container)) {
        New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
    }

    $alertPath = Join-Path $LogDirectory 'queue-age-alert.json'
    $alertLogPath = Join-Path $LogDirectory 'queue-age-alerts.log'
    $timestamp = $Now.ToString('o')
    $markerExists = Test-Path -LiteralPath $alertPath -PathType Leaf
    $marker = $null
    $eventType = $null
    $message = $null
    $transitionLogged = $false

    if ($markerExists) {
        $marker = Read-RegistrationQueueAgeMarker -Path $alertPath
    }

    if ($warning) {
        $newIncident = $null -eq $marker -or [string]$marker.status -eq 'resolved'
        if ($newIncident) {
            $marker = @{
                status = 'open'
                first_seen_at = $timestamp
                last_seen_at = $timestamp
                oldest_pending_age_seconds = $ageSeconds
                reminder_interval_minutes = $ReminderMinutes
                retry_interval_minutes = $RetryMinutes
                last_email_attempted_at = ''
                last_email_attempted_event = ''
                last_email_sent_at = ''
                opened_email_sent_at = ''
                resolved_email_sent_at = ''
                delivery_failures = 0
            }
            $eventType = 'OPEN'
            Add-Content -LiteralPath $alertLogPath -Value "[$timestamp] QUEUE_AGE_ALERT=OPEN oldest_pending_age_seconds=$ageSeconds first_seen_at=$timestamp" -Encoding UTF8
            $transitionLogged = $true
        }
        else {
            $marker = @{
                status = 'open'
                first_seen_at = [string]$marker.first_seen_at
                last_seen_at = $timestamp
                oldest_pending_age_seconds = $ageSeconds
                reminder_interval_minutes = $ReminderMinutes
                retry_interval_minutes = $RetryMinutes
                last_email_attempted_at = [string]$marker.last_email_attempted_at
                last_email_attempted_event = [string]$marker.last_email_attempted_event
                last_email_sent_at = [string]$marker.last_email_sent_at
                opened_email_sent_at = [string]$marker.opened_email_sent_at
                resolved_email_sent_at = ''
                delivery_failures = [int]$marker.delivery_failures
            }

            if ([string]::IsNullOrWhiteSpace($marker.opened_email_sent_at)) {
                $eventType = 'OPEN'
            }
            elseif (-not [string]::IsNullOrWhiteSpace($marker.last_email_sent_at)) {
                try {
                    $lastSent = [datetime]::Parse(
                        $marker.last_email_sent_at,
                        [Globalization.CultureInfo]::InvariantCulture,
                        [Globalization.DateTimeStyles]::RoundtripKind
                    )
                }
                catch {
                    throw 'The queue-age email delivery timestamp is invalid; manual inspection is required.'
                }
                if (($Now - $lastSent).TotalMinutes -ge $ReminderMinutes) {
                    $eventType = 'REMINDER'
                }
            }
        }

        $emailDeliveryPending = [bool]$eventType
        if ($eventType -and (Test-RegistrationQueueAlertRetryDelay -Marker ([pscustomobject]$marker) -Now $Now -EventType $eventType -RetryMinutes $RetryMinutes)) {
            $eventType = $null
        }

        Save-RegistrationQueueAgeMarker -Path $alertPath -Marker $marker
        if ($eventType) {
            if (-not $transitionLogged -and $eventType -eq 'REMINDER') {
                Add-Content -LiteralPath $alertLogPath -Value "[$timestamp] QUEUE_AGE_ALERT=REMINDER oldest_pending_age_seconds=$ageSeconds first_seen_at=$($marker.first_seen_at)" -Encoding UTF8
            }
            $message = "QUEUE_AGE_ALERT=$eventType oldest_pending_age_seconds=$ageSeconds first_seen_at=$($marker.first_seen_at)"
        }
        else {
            $message = "QUEUE_AGE_ALERT=ACTIVE oldest_pending_age_seconds=$ageSeconds first_seen_at=$($marker.first_seen_at)"
        }

        return [pscustomobject]@{
            IsWarning = $true
            Message = $message
            EventType = $eventType
            AgeSeconds = $ageSeconds
            FirstSeenAt = [string]$marker.first_seen_at
            EmailDeliveryPending = $emailDeliveryPending
            AlertPath = $alertPath
        }
    }

    if ($null -eq $marker) {
        return [pscustomobject]@{
            IsWarning = $false
            Message = $null
            EventType = $null
            AgeSeconds = $ageSeconds
            FirstSeenAt = $null
            EmailDeliveryPending = $false
            AlertPath = $alertPath
        }
    }

    if ([string]$marker.status -eq 'open') {
        $marker = @{
            status = 'resolved'
            first_seen_at = [string]$marker.first_seen_at
            last_seen_at = $timestamp
            oldest_pending_age_seconds = $ageSeconds
            reminder_interval_minutes = $ReminderMinutes
            retry_interval_minutes = $RetryMinutes
            last_email_attempted_at = [string]$marker.last_email_attempted_at
            last_email_attempted_event = [string]$marker.last_email_attempted_event
            last_email_sent_at = [string]$marker.last_email_sent_at
            opened_email_sent_at = [string]$marker.opened_email_sent_at
            resolved_email_sent_at = ''
            delivery_failures = [int]$marker.delivery_failures
            resolved_at = $timestamp
        }
        Add-Content -LiteralPath $alertLogPath -Value "[$timestamp] QUEUE_AGE_ALERT=RESOLVED oldest_pending_age_seconds=$ageSeconds first_seen_at=$($marker.first_seen_at)" -Encoding UTF8
    }

    $emailDeliveryPending = [string]::IsNullOrWhiteSpace([string]$marker.resolved_email_sent_at)
    if ($emailDeliveryPending) {
        $eventType = 'RESOLVED'
        if (Test-RegistrationQueueAlertRetryDelay -Marker ([pscustomobject]$marker) -Now $Now -EventType 'RESOLVED' -RetryMinutes $RetryMinutes) {
            $eventType = $null
        }
        Save-RegistrationQueueAgeMarker -Path $alertPath -Marker $marker
        if ($eventType) {
            $message = "QUEUE_AGE_ALERT=RESOLVED oldest_pending_age_seconds=$ageSeconds first_seen_at=$($marker.first_seen_at)"
        }
    }
    else {
        Remove-Item -LiteralPath $alertPath -Force
    }

    return [pscustomobject]@{
        IsWarning = $false
        Message = $message
        EventType = $eventType
        AgeSeconds = $ageSeconds
        FirstSeenAt = [string]$marker.first_seen_at
        EmailDeliveryPending = $emailDeliveryPending
        AlertPath = $alertPath
    }
}

function Set-RegistrationQueueAgeAlertDeliveryResult {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$LogDirectory,
        [Parameter(Mandatory = $true)][ValidateSet('OPEN', 'REMINDER', 'RESOLVED')][string]$EventType,
        [Parameter(Mandatory = $true)][bool]$Success,
        [datetime]$Now = (Get-Date)
    )

    $alertPath = Join-Path $LogDirectory 'queue-age-alert.json'
    if (-not (Test-Path -LiteralPath $alertPath -PathType Leaf)) {
        throw 'Queue-age alert marker disappeared before email delivery could be recorded.'
    }

    $marker = Read-RegistrationQueueAgeMarker -Path $alertPath
    $markerMap = @{}
    foreach ($property in $marker.PSObject.Properties) {
        $markerMap[$property.Name] = $property.Value
    }

    $timestamp = $Now.ToString('o')
    $markerMap['last_email_attempted_at'] = $timestamp
    $markerMap['last_email_attempted_event'] = $EventType

    if ($Success) {
        $markerMap['delivery_failures'] = 0
        if ($EventType -eq 'OPEN') {
            $markerMap['opened_email_sent_at'] = $timestamp
            $markerMap['last_email_sent_at'] = $timestamp
        }
        elseif ($EventType -eq 'REMINDER') {
            $markerMap['last_email_sent_at'] = $timestamp
        }
        else {
            $markerMap['resolved_email_sent_at'] = $timestamp
        }
    }
    else {
        $markerMap['delivery_failures'] = [int]$markerMap['delivery_failures'] + 1
    }

    Save-RegistrationQueueAgeMarker -Path $alertPath -Marker $markerMap

    if ($Success -and $EventType -eq 'RESOLVED') {
        Remove-Item -LiteralPath $alertPath -Force
    }
}

function Get-RegistrationQueueWorkerExitCode {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][ValidateRange(0, 255)][int]$WorkerExitCode,
        [Parameter(Mandatory = $true)][bool]$QueueAgeWarning,
        [bool]$EmailDeliveryFailed = $false
    )

    if ($WorkerExitCode -ne 0) {
        return $WorkerExitCode
    }
    if ($QueueAgeWarning) {
        return 2
    }
    if ($EmailDeliveryFailed) {
        return 3
    }

    return 0
}
