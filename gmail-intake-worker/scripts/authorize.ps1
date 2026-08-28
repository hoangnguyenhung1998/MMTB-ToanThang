$ErrorActionPreference = "Stop"
$WorkerRoot = Split-Path -Parent $PSScriptRoot
Set-Location $WorkerRoot
& ".\.venv\Scripts\python.exe" -m mmtb_gmail_intake_worker.auth
