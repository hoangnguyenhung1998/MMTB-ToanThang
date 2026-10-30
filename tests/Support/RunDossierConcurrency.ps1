param([Parameter(Mandatory=$true)][string]$EvidenceRoot,[string]$Php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe')
$ErrorActionPreference='Stop'
$env:P18_ACCEPTANCE_ROOT=[IO.Path]::GetFullPath($EvidenceRoot)
$dossierHarness=Join-Path $PSScriptRoot 'DossierConcurrency.php'
function Invoke-DossierFixture([string]$Mode,[string]$Round='') {
    $result=& $Php $dossierHarness $Mode $Round
    if($LASTEXITCODE -ne 0){throw "Native $Mode $Round failed: $result"}
    $result
}
Invoke-DossierFixture setup
$dossierProof=@()
foreach($round in @('confirm','replace','replaceFirst','exportFirst','retry','jobRetry')) {
    if($round -in @('replaceFirst','exportFirst')){Invoke-DossierFixture prepare}
    $base=Join-Path $EvidenceRoot "native-$round"
    if(Test-Path -LiteralPath "$base.go"){throw 'Existing evidence; never overwrite'}
    $pair=@()
    foreach($role in @('leader','follower')) {
        $stdout="$base-$role.out"; $stderr="$base-$role.err"
        $proc=Start-Process -FilePath $Php -ArgumentList @(('"'+$dossierHarness+'"'),'worker',$round,$role) -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
        $pair+=@{Process=$proc;Role=$role;Out=$stdout;Err=$stderr}
    }
    $until=(Get-Date).AddSeconds(30)
    while(-not ((Test-Path "$base-leader.ready") -and (Test-Path "$base-follower.ready"))){if((Get-Date)-gt $until){throw 'Ready barrier timeout'};Start-Sleep -Milliseconds 50}
    New-Item -ItemType File -Path "$base.go" | Out-Null
    $outputs=@()
    foreach($entry in $pair){
        if(-not $entry.Process.WaitForExit(40000)){throw 'Process did not complete; preserve evidence'}
        if($entry.Process.ExitCode -ne 0){Get-Content -LiteralPath $entry.Err;throw "Process $($entry.Process.Id) failed"}
        $output=Get-Content -Raw -LiteralPath $entry.Out | ConvertFrom-Json
        $outputs+= $output
    }
    if($outputs[0].pid -eq $outputs[1].pid -or $outputs[0].connection_id -eq $outputs[1].connection_id){throw 'Not independent processes/connections'}
    if($outputs[1].elapsed_ms -lt 1000){throw 'Contention was not demonstrated'}
    $verified=Invoke-DossierFixture verify $round | ConvertFrom-Json
    $item=[pscustomobject]@{Round=$round;Processes=$outputs;Verification=$verified};$dossierProof+=$item
    $item | ConvertTo-Json -Depth 8 -Compress
}
$dossierProof | ConvertTo-Json -Depth 10 | Set-Content -Encoding utf8 (Join-Path $EvidenceRoot 'mysql-concurrency-proof.json')
