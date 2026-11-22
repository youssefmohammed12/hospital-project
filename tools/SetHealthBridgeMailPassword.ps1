$ErrorActionPreference = 'Stop'

# Run this script as the same Windows user that runs XAMPP Apache and the
# hidden HealthBridge workers. The entered Google app password is never
# echoed, passed as a command-line argument, or written in plaintext.
$privateDirectory = 'C:\xampp85\private\healthbridge-mail'
$passwordFile = Join-Path $privateDirectory 'smtp-password.dpapi'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name

New-Item -ItemType Directory -Path $privateDirectory -Force | Out-Null

# The private directory is outside the web root and has an explicit,
# non-inheriting ACL for this Windows user, SYSTEM and local administrators.
& icacls.exe $privateDirectory /inheritance:r /grant:r "$($identity):(OI)(CI)F" 'NT AUTHORITY\SYSTEM:(OI)(CI)F' 'BUILTIN\Administrators:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Could not restrict permissions on the HealthBridge mail secret folder.'
}

$securePassword = Read-Host 'Enter the 16-character Google app password for llaunchboard@gmail.com' -AsSecureString
if ($null -eq $securePassword -or $securePassword.Length -eq 0) {
    throw 'No app password was entered.'
}

$bstr = [IntPtr]::Zero
try {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) -replace '\s', ''
    if ($plainPassword -notmatch '^[A-Za-z0-9]{16}$') {
        throw 'The Google app password must contain 16 letters/numbers. Spaces are allowed in the displayed form.'
    }

    # With no -Key argument, ConvertFrom-SecureString uses Windows DPAPI
    # for the current user. Only ciphertext is persisted.
    $protectedValue = ConvertFrom-SecureString -SecureString $securePassword
    $temporaryFile = $passwordFile + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    [IO.File]::WriteAllText($temporaryFile, $protectedValue, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryFile -Destination $passwordFile -Force

    & icacls.exe $passwordFile /inheritance:r /grant:r "$($identity):F" 'NT AUTHORITY\SYSTEM:F' 'BUILTIN\Administrators:F' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Remove-Item -LiteralPath $passwordFile -Force -ErrorAction SilentlyContinue
        throw 'Could not restrict permissions on the HealthBridge mail secret file.'
    }
}
finally {
    if ($bstr -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
    if ($securePassword) {
        $securePassword.Dispose()
    }
    $plainPassword = $null
    $protectedValue = $null
}

Write-Output 'HealthBridge Gmail app password stored using Windows DPAPI.'
Write-Output 'It is not stored in the project, .env, Git, or a command-line argument.'
Write-Output "Windows identity: $identity"
