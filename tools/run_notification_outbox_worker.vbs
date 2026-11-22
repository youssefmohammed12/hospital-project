' Silent, waitable launcher for the HealthBridge notification outbox worker.
' Both Windows Script Host and the child PowerShell process are hidden.
Set WshShell = CreateObject("WScript.Shell")
exitCode = WshShell.Run("powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File ""C:\xampp85\htdocs\optimized\tools\run_notification_outbox_worker.ps1""", 0, True)
Set WshShell = Nothing
WScript.Quit exitCode
