param([Parameter(Mandatory=$true)][string]$Python)
$ErrorActionPreference='Stop'
$workerRoot=$PSScriptRoot
# Task invokes only this separate worker. Journal/Collector task names never appear here.
while($true) {
    & $Python (Join-Path $workerRoot 'worker.py') --env (Join-Path $workerRoot '.env')
    if($LASTEXITCODE -eq 0){exit 0}
    Start-Sleep -Seconds 60
}
