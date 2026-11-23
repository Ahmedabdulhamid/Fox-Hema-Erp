[CmdletBinding()]
param(
    [ValidateSet('smoke', 'baseline', 'light', 'medium', 'heavy', 'stress', 'extreme')]
    [string]$Profile = 'smoke',

    [ValidateSet('smoke', 'login-page', 'authentication', 'dashboard', 'resources-listing', 'mixed-workload', 'livewire-interactions')]
    [string]$Scenario = 'smoke',

    [string]$BaseUrl = 'http://127.0.0.1:8090',

    [switch]$ConfirmHighLoad
)

$profileVus = @{
    smoke = 1
    baseline = 5
    light = 10
    medium = 25
    heavy = 50
    stress = 100
    extreme = 200
}

if ($Scenario -notin @('smoke', 'login-page')) {
    throw "Scenario '$Scenario' is disabled until authentication is verified."
}

if ($BaseUrl -notmatch '^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(?:/.*)?$') {
    throw 'BaseUrl must point to localhost or 127.0.0.1.'
}

if ($profileVus[$Profile] -gt 10 -and -not $ConfirmHighLoad) {
    throw "Profile '$Profile' exceeds 10 VUs. Pass -ConfirmHighLoad only with separate explicit approval."
}

try {
    $k6Command = Get-Command k6 -ErrorAction Stop
}
catch {
    [Console]::Error.WriteLine("Unable to locate k6: $($_.Exception.Message)")
    exit 127
}

$runId = [Guid]::NewGuid().ToString('N')
$summaryJson = Join-Path $PSScriptRoot "reports-$runId-summary.json"
$summaryText = Join-Path $PSScriptRoot "reports-$runId-summary.txt"

if ((Test-Path -LiteralPath $summaryJson) -or (Test-Path -LiteralPath $summaryText)) {
    throw "Report path for Run ID '$runId' already exists. Refusing to overwrite."
}

$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$mainScript = Join-Path $PSScriptRoot 'main.js'
$k6Arguments = @(
    'run',
    '-e', "K6_RUN_ID=$runId",
    '-e', "LOAD_SCENARIO=$Scenario",
    '-e', "TEST_PROFILE=$Profile",
    '-e', "BASE_URL=$BaseUrl"
)

if ($profileVus[$Profile] -gt 10) {
    $k6Arguments += @('-e', 'CONFIRM_HIGH_LOAD=I_CONFIRM_HIGH_LOAD')
}

$k6Arguments += $mainScript

$k6ExitCode = $null
$k6StartFailure = $null

Push-Location $projectRoot
try {
    try {
        & $k6Command.Source @k6Arguments
        $k6ExitCode = $LASTEXITCODE
    }
    catch {
        $k6StartFailure = $_
    }
}
finally {
    Pop-Location
}

if ($null -ne $k6StartFailure) {
    [Console]::Error.WriteLine("Unable to start k6: $($k6StartFailure.Exception.Message)")
    exit 1
}

if ($null -eq $k6ExitCode) {
    [Console]::Error.WriteLine('Unable to determine the k6 process exit code.')
    exit 1
}

if ($k6ExitCode -ne 0) {
    [Console]::Error.WriteLine("k6 exited with code $k6ExitCode.")
    exit $k6ExitCode
}

$reportValidationFailures = @()
foreach ($reportPath in @($summaryJson, $summaryText)) {
    if (-not (Test-Path -LiteralPath $reportPath -PathType Leaf)) {
        $reportValidationFailures += "Missing report: $reportPath"
        continue
    }

    try {
        $reportFile = Get-Item -LiteralPath $reportPath -ErrorAction Stop
    }
    catch {
        $reportValidationFailures += "Unable to inspect report: $reportPath"
        continue
    }

    if ($reportFile.Length -eq 0) {
        $reportValidationFailures += "Empty report: $reportPath"
    }
}

if ($reportValidationFailures.Count -gt 0) {
    foreach ($failure in $reportValidationFailures) {
        [Console]::Error.WriteLine($failure)
    }
    exit 2
}

try {
    $jsonContent = Get-Content -LiteralPath $summaryJson -Raw -ErrorAction Stop
    $null = ConvertFrom-Json -InputObject $jsonContent -ErrorAction Stop
}
catch {
    [Console]::Error.WriteLine("Invalid JSON report: $summaryJson")
    exit 2
}

Write-Output "k6 completed successfully and both reports passed validation: $summaryJson; $summaryText"
