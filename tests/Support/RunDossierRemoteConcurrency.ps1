param([Parameter(Mandatory=$true)][string]$EvidenceRoot,[string]$Php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe',[string]$ProofSuffix='')
$ErrorActionPreference='Stop'
$env:P18_ACCEPTANCE_ROOT=[IO.Path]::GetFullPath($EvidenceRoot)
$env:P18_REMOTE_ACCEPTANCE='1'
if($ProofSuffix -notmatch '^[-a-z0-9]*$'){throw 'Invalid proof suffix'}
$env:P18_PROOF_SUFFIX=$ProofSuffix
$harness=Join-Path $PSScriptRoot 'DossierRemoteConcurrency.php'
function Run-Harness([string]$Mode,[string]$Round='') { $result=& $Php $harness $Mode $Round; if($LASTEXITCODE -ne 0){throw "Harness $Mode failed: $result"}; $result }
Run-Harness remote-setup
$proof=@()
foreach($round in @('claim','complete','reclaim')) {
    if($round -eq 'complete'){Run-Harness remote-complete-setup}
    if($round -eq 'reclaim'){Run-Harness remote-reclaim-setup}
    $base=Join-Path $EvidenceRoot "remote-$round$ProofSuffix"
    if(Test-Path "$base.go"){throw 'Preserve prior evidence; use a fresh isolated evidence run'}
    $pair=@()
    foreach($role in @('leader','follower')) {
        $out="$base-$role.out"; $err="$base-$role.err"
        $process=Start-Process -FilePath $Php -ArgumentList @(('"'+$harness+'"'),'remote-worker',$round,$role) -WindowStyle Hidden -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
        $pair+=@{Process=$process;Out=$out;Err=$err}
    }
    $until=(Get-Date).AddSeconds(30)
    while(-not ((Test-Path "$base-leader.ready") -and (Test-Path "$base-follower.ready"))){if((Get-Date)-gt $until){throw 'Ready barrier timeout'};Start-Sleep -Milliseconds 50}
    New-Item -ItemType File -Path "$base.go" | Out-Null
    $results=@()
    foreach($entry in $pair){if(-not $entry.Process.WaitForExit(40000)){throw 'Child timed out'};if($entry.Process.ExitCode -ne 0){Get-Content $entry.Err;throw 'Child failed'};$results+=Get-Content -Raw $entry.Out | ConvertFrom-Json}
    if($results[0].pid -eq $results[1].pid -or $results[0].connection_id -eq $results[1].connection_id -or $results[1].elapsed_ms -lt 1000){throw 'Real process/connection contention not demonstrated'}
    $verification=Run-Harness remote-verify $round | ConvertFrom-Json
    $row=[pscustomobject]@{Round=$round;Processes=$results;Verification=$verification};$proof+=$row;$row | ConvertTo-Json -Depth 8 -Compress
}
$proof | ConvertTo-Json -Depth 10 | Set-Content -Encoding utf8 (Join-Path $EvidenceRoot "remote-concurrency-proof$ProofSuffix.json")
