' Silent, waitable launcher for the encrypted registration intake worker.
' The scheduled task runs interactively as the DPAPI key-owning Windows user.
Set WshShell = CreateObject("WScript.Shell")
exitCode = WshShell.Run("powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File ""C:\xampp85\htdocs\optimized\tools\run_registration_intake_worker.ps1""", 0, True)
Set WshShell = Nothing
WScript.Quit exitCode
