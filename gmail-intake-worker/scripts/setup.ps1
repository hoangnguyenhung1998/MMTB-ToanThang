$ErrorActionPreference = "Stop"
$WorkerRoot = Split-Path -Parent $PSScriptRoot
Set-Location $WorkerRoot
if (-not (Test-Path ".venv\Scripts\python.exe")) { py -3.12 -m venv .venv }
& ".\.venv\Scripts\python.exe" -m pip install --upgrade pip
& ".\.venv\Scripts\python.exe" -m pip install -e .
if (-not (Test-Path ".env")) { Copy-Item ".env.example" ".env" }
Write-Host "Setup complete. Add credentials.json, configure .env, then run scripts\authorize.ps1."
