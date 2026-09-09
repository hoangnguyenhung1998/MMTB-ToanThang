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
$RestartDelaySeconds = 10
$StableRuntimeSeconds = 300
$MaximumRestartDelaySeconds = 300

while ($true) {
    Rotate-Log
    Write-SupervisorLog "Starting Collector with $NodePath"
    $StartedAt = Get-Date
    & $NodePath "src/index.js" *>> $LogPath
    $ExitCode = $LASTEXITCODE
    $RuntimeSeconds = ((Get-Date) - $StartedAt).TotalSeconds
    if ($RuntimeSeconds -ge $StableRuntimeSeconds) {
        $RestartDelaySeconds = 10
    }
    Write-SupervisorLog "Collector exited with code $ExitCode after $([int]$RuntimeSeconds)s. Restarting in ${RestartDelaySeconds}s."
    Start-Sleep -Seconds $RestartDelaySeconds
    $RestartDelaySeconds = [Math]::Min($RestartDelaySeconds * 2, $MaximumRestartDelaySeconds)
}
