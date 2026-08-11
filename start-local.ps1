param(
    [switch]$Build,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$envPath = Join-Path $projectPath '.env.local'
$composePath = Join-Path $projectPath 'compose.local.yml'
$dockerDesktopPath = 'C:\Program Files\Docker\Docker\Docker Desktop.exe'

function Test-DockerReady {
    docker info *> $null
    return $LASTEXITCODE -eq 0
}

if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Run install-local.ps1 first.'
}

if (-not (Test-DockerReady)) {
    if (-not (Test-Path -LiteralPath $dockerDesktopPath)) {
        throw 'Docker Desktop is not installed.'
    }

    Start-Process -FilePath $dockerDesktopPath -WindowStyle Hidden
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        Start-Sleep -Seconds 2
        if (Test-DockerReady) {
            $ready = $true
            break
        }
    }

    if (-not $ready) {
        throw 'Docker Desktop did not become ready within two minutes.'
    }
}

$composeArgs = @('compose', '--env-file', $envPath, '-f', $composePath, 'up', '-d')
if ($Build) {
    $composeArgs += '--build'
}

& docker @composeArgs
if ($LASTEXITCODE -ne 0) {
    throw 'The local application could not be started.'
}

$healthy = $false
for ($attempt = 0; $attempt -lt 90; $attempt++) {
    Start-Sleep -Seconds 2
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost:8080/up' -TimeoutSec 3
        if ($response.StatusCode -eq 200) {
            $healthy = $true
            break
        }
    } catch {
    }
}

if (-not $healthy) {
    throw 'The app started but did not pass its health check.'
}

if (-not $NoBrowser) {
    Start-Process 'http://localhost:8080/admin'
}
