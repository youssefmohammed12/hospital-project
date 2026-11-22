$script:HealthBridgeRegistrationKeyBstr = [IntPtr]::Zero

function Set-HealthBridgeRegistrationQueueEnvironment {
    $keyFile = 'C:\xampp85\private\healthbridge\registration-queue-keyring.dpapi'
    if (-not (Test-Path -LiteralPath $keyFile -PathType Leaf)) {
        throw 'The protected registration queue key file is missing.'
    }

    $protectedValue = [IO.File]::ReadAllText($keyFile).Trim()
    if ($protectedValue -eq '') {
        throw 'The protected registration queue key file is empty.'
    }

    $secureValue = ConvertTo-SecureString -String $protectedValue
    $script:HealthBridgeRegistrationKeyBstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureValue)

    try {
        $keyRingJson = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($script:HealthBridgeRegistrationKeyBstr)
        $keyRing = $keyRingJson | ConvertFrom-Json
        $activeVersion = 'v1'
        $encodedKey = [string]$keyRing.$activeVersion
        if ($encodedKey -eq '') {
            throw 'The active registration queue key version is missing.'
        }

        $keyBytes = [Convert]::FromBase64String($encodedKey)
        if ($keyBytes.Length -ne 32) {
            throw 'The active registration queue key must decode to 32 bytes.'
        }

        $env:HB_REGISTRATION_QUEUE_KEYS_JSON = $keyRingJson
        $env:HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION = $activeVersion
        [Array]::Clear($keyBytes, 0, $keyBytes.Length)
        $keyRingJson = $null
        $keyRing = $null
    }
    finally {
        if ($script:HealthBridgeRegistrationKeyBstr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($script:HealthBridgeRegistrationKeyBstr)
            $script:HealthBridgeRegistrationKeyBstr = [IntPtr]::Zero
        }
        if ($secureValue) {
            $secureValue.Dispose()
        }
    }
}

function Clear-HealthBridgeRegistrationQueueEnvironment {
    Remove-Item Env:HB_REGISTRATION_QUEUE_KEYS_JSON -ErrorAction SilentlyContinue
    Remove-Item Env:HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION -ErrorAction SilentlyContinue
}
