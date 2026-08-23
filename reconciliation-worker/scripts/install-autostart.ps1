$ErrorActionPreference = "Stop"

$TaskName = "MMTB-OpenClawReconciliationWorker"
$WorkerRoot = Split-Path -Parent $PSScriptRoot
$PythonExe = Join-Path $WorkerRoot ".venv\Scripts\python.exe"

if (-not (Test-Path $PythonExe)) {
    throw "Python virtual environment not found. Run: py -3.12 -m venv .venv"
}

$Principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive `
    -RunLevel Highest
$Action = New-ScheduledTaskAction `
    -Execute $PythonExe `
    -Argument "-m mmtb_reconciliation_worker" `
    -WorkingDirectory $WorkerRoot
$Trigger = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
$Settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -RestartCount 10 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger `
    -Principal $Principal -Settings $Settings -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Write-Host "Installed and started scheduled task: $TaskName"
Write-Host "Log file: $(Join-Path $WorkerRoot 'data\worker.log')"
