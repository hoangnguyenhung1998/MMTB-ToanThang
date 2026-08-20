$ErrorActionPreference = "Stop"
$TaskName = "MMTB-ZaloCollector"
$CollectorRoot = Split-Path -Parent $PSScriptRoot
$RunnerPath = Join-Path $PSScriptRoot "run-forever.ps1"
$EnvironmentPath = Join-Path $CollectorRoot ".env"
$CredentialsPath = Join-Path $CollectorRoot "data\credentials.json"

if (-not (Test-Path $EnvironmentPath)) {
    throw "Missing $EnvironmentPath. Configure the Collector before installing auto-start."
}
if (-not (Test-Path $CredentialsPath)) {
    throw "Missing Zalo credentials. Run npm start and scan the QR code before installing auto-start."
}

$NodePath = (Get-Command node -ErrorAction Stop).Source
$NodeVersion = [version](& $NodePath -p "process.versions.node")
if ($NodeVersion -lt [version]"22.13.0") {
    throw "Node.js 22.13 or newer is required. Found $NodeVersion."
}

$PowerShellPath = Join-Path $PSHOME "powershell.exe"
$Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$RunnerPath`" -NodePath `"$NodePath`""
$Action = New-ScheduledTaskAction -Execute $PowerShellPath -Argument $Arguments -WorkingDirectory $CollectorRoot
$Trigger = New-ScheduledTaskTrigger -AtLogOn
$Principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Principal $Principal `
    -Settings $Settings -Description "MMTB durable Zalo Collector" -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Host "Installed and started scheduled task: $TaskName"
Write-Host "Log file: $(Join-Path $CollectorRoot 'data\collector.log')"
