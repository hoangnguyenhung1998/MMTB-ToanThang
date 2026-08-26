$TaskName = "MMTB-AutomationHealthAgent"
$AgentRoot = Split-Path -Parent $PSScriptRoot
$Task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if (-not $Task) {
    Write-Host "Chưa cài Scheduled Task: $TaskName"
    exit 1
}

$Info = Get-ScheduledTaskInfo -TaskName $TaskName
Write-Host "Task: $TaskName"
Write-Host "State: $($Task.State)"
Write-Host "Last run: $($Info.LastRunTime)"
Write-Host "Last result: $($Info.LastTaskResult)"

$LogFile = Join-Path $AgentRoot "agent.log"
if (Test-Path $LogFile) {
    Write-Host "`nLog gần nhất:"
    Get-Content $LogFile -Tail 20
}
