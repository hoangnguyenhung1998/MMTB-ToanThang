param(
    [string]$NodePath = ""
)

$ErrorActionPreference = "Continue"
$CollectorRoot = Split-Path -Parent $PSScriptRoot
$DataDirectory = Join-Path $CollectorRoot "data"
$LogPath = Join-Path $DataDirectory "collector.log"
$PreviousLogPath = Join-Path $DataDirectory "collector.previous.log"

New-Item -ItemType Directory -Path $DataDirectory -Force | Out-Null

if ([string]::IsNullOrWhiteSpace($NodePath)) {
    $NodePath = (Get-Command node -ErrorAction Stop).Source
}

function Rotate-Log {
    if ((Test-Path $LogPath) -and (Get-Item $LogPath).Length -ge 10MB) {
        Move-Item -Path $LogPath -Destination $PreviousLogPath -Force
    }
}

function Write-SupervisorLog([string]$Message) {
    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $Message" | Out-File -FilePath $LogPath -Append -Encoding utf8
}

Set-Location $CollectorRoot

while ($true) {
    Rotate-Log
    Write-SupervisorLog "Starting Collector with $NodePath"
    & $NodePath "src/index.js" *>> $LogPath
    $ExitCode = $LASTEXITCODE
    Write-SupervisorLog "Collector exited with code $ExitCode. Restarting in 10 seconds."
    Start-Sleep -Seconds 10
}
