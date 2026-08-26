$TaskName = "MMTB-AutomationHealthAgent"
$Task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if (-not $Task) {
    Write-Host "Không tìm thấy Scheduled Task: $TaskName"
    exit 0
}

Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
Write-Host "Đã gỡ Scheduled Task: $TaskName"
