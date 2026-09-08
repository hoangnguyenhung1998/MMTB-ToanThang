$ErrorActionPreference = 'Stop'
# Run on the Collector laptop only after the release has passed PC testing.
$task = Get-ScheduledTask -TaskName 'MMTB-OpenClawReconciliationWorker' -ErrorAction SilentlyContinue
if ($null -ne $task) {
    Disable-ScheduledTask -TaskName $task.TaskName | Out-Null
    Stop-ScheduledTask -TaskName $task.TaskName
    Get-ScheduledTask -TaskName $task.TaskName | Select-Object TaskName, State
}
# JournalWorker remains running for machine intake / handover, with weekly claims disabled.
