param(
    [Parameter(Mandatory=$true)][ValidatePattern('^p175_[a-z0-9_]+$')][string]$Database,
    [string]$Php='C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
)
$ErrorActionPreference='Stop'
$fixtureRepo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
Set-Location -LiteralPath $fixtureRepo
$fixtureOutput=Join-Path $fixtureRepo 'storage/p174-fixtures'
New-Item -ItemType Directory -Path $fixtureOutput -Force | Out-Null
$env:MMTB_AI_TEST_DB=$Database
Remove-Item Env:\MMTB_AI_FIXTURE_CLOCK -ErrorAction SilentlyContinue
$fixtureHarness=Join-Path $PSScriptRoot 'AiRescueMySql.php'
function Invoke-Fixture([string]$Mode,[int]$ExpectedExit=0) {
    & $Php $fixtureHarness $Mode
    if($LASTEXITCODE -ne $ExpectedExit){throw "Fixture $Mode exit $LASTEXITCODE, expected $ExpectedExit"}
}
function Invoke-Pair([string]$Mode) {
    $fixtureGate=Join-Path $fixtureOutput "$Database-$Mode-gate"
    $pair=@()
    foreach($label in @('a','b')) {
        $stdout=Join-Path $fixtureOutput "$Database-$Mode-$label.out"
        $stderr=Join-Path $fixtureOutput "$Database-$Mode-$label.err"
        $process=Start-Process -FilePath $Php -ArgumentList @(('"'+$fixtureHarness+'"'),$Mode,('"'+$fixtureGate+'"')) -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
        $pair+=@{Process=$process;Stdout=$stdout;Stderr=$stderr}
    }
    Start-Sleep -Milliseconds 300
    New-Item -ItemType File -Path $fixtureGate | Out-Null
    foreach($entry in $pair) {
        if(-not $entry.Process.WaitForExit(20000)){throw 'Fixture process timeout; inspect the recorded process outputs'}
        Write-Output "Native $Mode process ID $($entry.Process.Id), exit $($entry.Process.ExitCode)"
        Get-Content -LiteralPath $entry.Stdout,$entry.Stderr
        if($entry.Process.ExitCode -ne 0){throw "Native $Mode failed"}
    }
}
Invoke-Fixture setup
Invoke-Pair dispatch
Invoke-Fixture change
Invoke-Fixture crash-uncommitted 78
Invoke-Fixture work
Invoke-Fixture verify
$env:MMTB_AI_FIXTURE_CLOCK='after-expiry'
Invoke-Fixture crash-committed 77
Invoke-Fixture verify
Invoke-Pair work
Invoke-Fixture drain
Invoke-Fixture verify-final
Invoke-Fixture mock-ocr
Invoke-Fixture dispatch
Invoke-Fixture drain
Invoke-Fixture verify-final
Remove-Item Env:\MMTB_AI_FIXTURE_CLOCK -ErrorAction SilentlyContinue
