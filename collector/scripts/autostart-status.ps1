$TaskName = "MMTB-ZaloCollector"
$CollectorRoot = Split-Path -Parent $PSScriptRoot
$LogPath = Join-Path $CollectorRoot "data\collector.log"
$Task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if ($null -eq $Task) {
    Write-Host "Scheduled task is not installed: $TaskName"
    exit 1
}

$Info = Get-ScheduledTaskInfo -TaskName $TaskName
Write-Host "Task: $TaskName"
Write-Host "State: $($Task.State)"
Write-Host "Last run: $($Info.LastRunTime)"
Write-Host "Last result: $($Info.LastTaskResult)"

if (Test-Path $LogPath) {
    Write-Host "`nLatest Collector log:"
    Get-Content -Path $LogPath -Tail 20
} else {
    Write-Host "Log has not been created yet: $LogPath"
}
