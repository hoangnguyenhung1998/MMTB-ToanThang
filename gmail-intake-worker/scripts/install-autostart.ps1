$ErrorActionPreference = "Stop"
$TaskName = "MMTB-GmailIntakeWorker"
$WorkerRoot = Split-Path -Parent $PSScriptRoot
$PythonExe = Join-Path $WorkerRoot ".venv\Scripts\python.exe"
if (-not (Test-Path $PythonExe)) { throw "Python virtual environment not found." }
if (-not (Test-Path (Join-Path $WorkerRoot "token.json"))) { throw "Run Gmail authorization before installing autostart." }
$Principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest
$Action = New-ScheduledTaskAction -Execute $PythonExe -Argument "-m mmtb_gmail_intake_worker" -WorkingDirectory $WorkerRoot
$Trigger = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
$Settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$Settings.MultipleInstances = 2
Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Principal $Principal -Settings $Settings -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Write-Host "Installed and started: $TaskName"
