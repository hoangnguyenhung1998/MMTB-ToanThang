param([Parameter(Mandatory=$true)][string]$Python,[Parameter(Mandatory=$true)][PSCredential]$Credential)
$ErrorActionPreference='Stop'
$taskName='MMTB-DossierWorker'
if(Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue){throw 'Task already exists. Inspect its action/status; do not create or replace a duplicate.'}
$pythonPath=(Resolve-Path -LiteralPath $Python).Path
$workerRoot=(Resolve-Path -LiteralPath $PSScriptRoot).Path
if(-not (Test-Path -LiteralPath (Join-Path $workerRoot '.env'))){throw 'Create dossier-worker/.env first; never use Journal config.'}
& $pythonPath (Join-Path $workerRoot 'worker.py') --preflight
if($LASTEXITCODE -ne 0){throw 'Dossier preflight failed'}
$arguments='-NoProfile -NonInteractive -WindowStyle Hidden -File "'+(Join-Path $workerRoot 'Run-Worker.ps1')+'" -Python "'+$pythonPath+'"'
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $workerRoot
$trigger=New-ScheduledTaskTrigger -AtStartup
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
# Windows stores credentials for this one service account; never print/export them.
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -User $Credential.UserName -Password $Credential.GetNetworkCredential().Password -RunLevel Limited | Select-Object TaskName,State
Write-Output 'Registered only MMTB-DossierWorker. Start-ScheduledTask explicitly after release approval; verify heartbeat.'
