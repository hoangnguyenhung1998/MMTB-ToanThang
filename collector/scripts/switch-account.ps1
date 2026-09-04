param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-z0-9][a-z0-9_-]{0,49}$')]
    [string]$AccountId
)

$ErrorActionPreference = "Stop"
$TaskName = "MMTB-ZaloCollector"
$CollectorRoot = Split-Path -Parent $PSScriptRoot
$NodePath = (Get-Command node -ErrorAction Stop).Source
$Task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if ($null -ne $Task) {
    Stop-ScheduledTask -TaskName $TaskName
    Start-Sleep -Seconds 2
}

try {
    & $NodePath (Join-Path $CollectorRoot "src\accounts.js") activate --id $AccountId
    if ($LASTEXITCODE -ne 0) { throw "Account activation failed." }
} finally {
    if ($null -ne $Task) { Start-ScheduledTask -TaskName $TaskName }
}

Write-Host "Switched Collector to Zalo account: $AccountId"
