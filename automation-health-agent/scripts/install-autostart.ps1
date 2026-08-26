$ErrorActionPreference = "Stop"

$TaskName = "MMTB-AutomationHealthAgent"
$AgentRoot = Split-Path -Parent $PSScriptRoot
$PythonExe = Join-Path $AgentRoot ".venv\Scripts\python.exe"
$AgentScript = Join-Path $AgentRoot "agent.py"
$EnvFile = Join-Path $AgentRoot ".env"

if (-not (Test-Path $PythonExe)) {
    throw "Chưa có môi trường Python. Chạy: py -3.12 -m venv `"$AgentRoot\.venv`""
}
if (-not (Test-Path $EnvFile)) {
    throw "Chưa có file .env. Hãy copy .env.example thành .env và điền token."
}
$TokenLine = Get-Content $EnvFile | Where-Object { $_ -match '^AUTOMATION_HEALTH_TOKEN=.+$' } | Select-Object -First 1
if (-not $TokenLine) {
    throw "AUTOMATION_HEALTH_TOKEN đang trống trong .env."
}

$Principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive `
    -RunLevel Highest
$Action = New-ScheduledTaskAction `
    -Execute $PythonExe `
    -Argument "`"$AgentScript`"" `
    -WorkingDirectory $AgentRoot
$Trigger = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
$Settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger `
    -Principal $Principal -Settings $Settings -Description "MMTB central automation health heartbeat" -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Write-Host "Đã cài và chạy Scheduled Task: $TaskName"
Write-Host "Log: $(Join-Path $AgentRoot 'agent.log')"
