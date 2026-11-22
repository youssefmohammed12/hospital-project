param(
    [string]$PasswordFile = 'C:\xampp85\private\healthbridge-mail\smtp-password.dpapi'
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $PasswordFile -PathType Leaf)) {
    exit 2
}

$securePassword = $null
$bstr = [IntPtr]::Zero
try {
    $protectedValue = [IO.File]::ReadAllText($PasswordFile).Trim()
    if ($protectedValue -eq '') {
        exit 3
    }

    # DPAPI decryption succeeds only for the Windows user who protected it.
    $securePassword = ConvertTo-SecureString -String $protectedValue
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) -replace '\s', ''
    if ($plainPassword -notmatch '^[A-Za-z0-9]{16}$') {
        exit 4
    }

    # Stdout is captured by the PHP process over a private pipe; never log it.
    [Console]::Out.Write($plainPassword)
}
catch {
    exit 5
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
