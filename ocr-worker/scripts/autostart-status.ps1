$ErrorActionPreference = "Stop"

$TaskName = "MMTB-RapidOCRWorker"
$WorkerRoot = Split-Path -Parent $PSScriptRoot
$Task = Get-ScheduledTask -TaskName $TaskName
$Info = Get-ScheduledTaskInfo -TaskName $TaskName

Write-Host "Task: $TaskName"
Write-Host "State: $($Task.State)"
Write-Host "Last run: $($Info.LastRunTime)"
Write-Host "Last result: $($Info.LastTaskResult)"

$LogFile = Join-Path $WorkerRoot "data\worker.log"
if (Test-Path $LogFile) {
    Write-Host "`nLatest worker log:"
    Get-Content $LogFile -Tail 20
}
