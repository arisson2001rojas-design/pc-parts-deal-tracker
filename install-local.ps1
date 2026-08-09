$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$envPath = Join-Path $projectPath '.env.local'
$loginPath = Join-Path $projectPath '.local-login.txt'

function New-RandomBytes([int]$Bytes) {
    $buffer = New-Object byte[] $Bytes
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($buffer)
    } finally {
        $generator.Dispose()
    }
    return $buffer
}

function New-RandomValue([int]$Bytes = 24) {
    $buffer = New-RandomBytes $Bytes
    return [Convert]::ToBase64String($buffer).Replace('+', 'A').Replace('/', 'B').TrimEnd('=')
}

if (-not (Test-Path -LiteralPath $envPath)) {
    $appKeyBytes = New-RandomBytes 32
    $appKey = 'base64:' + [Convert]::ToBase64String($appKeyBytes)
    $adminPassword = New-RandomValue 18
    $dbPassword = New-RandomValue 24
    $rootPassword = New-RandomValue 24

    $environment = @(
        'COMPOSE_PROJECT_NAME=pc-deal-hunter'
        'APP_PORT=8080'
        "APP_KEY=$appKey"
        'APP_USER_NAME=PC Deal Hunter'
        'APP_USER_EMAIL=admin@localhost'
        "APP_USER_PASSWORD=$adminPassword"
        "DB_PASSWORD=$dbPassword"
        "DB_ROOT_PASSWORD=$rootPassword"
        'DEAL_HUNTER_REFRESH_HOURS=6'
        'BEST_BUY_API_KEY='
    )
    [IO.File]::WriteAllLines($envPath, $environment)
    [IO.File]::WriteAllLines($loginPath, @(
        'PC Deal Hunter'
        'URL: http://localhost:8080/admin'
        'Email: admin@localhost'
        "Password: $adminPassword"
    ))
} else {
    $environment = [IO.File]::ReadAllLines($envPath) | Where-Object {
        $_ -notmatch '^DEAL_HUNTER_(FREIGHT_PER_LB|IMPORT_PERCENT)='
    }
    [IO.File]::WriteAllLines($envPath, $environment)
}

& (Join-Path $projectPath 'start-local.ps1') -Build -NoBrowser
if ($LASTEXITCODE -ne 0) {
    throw 'The local application failed to start.'
}

$compose = @('compose', '--env-file', $envPath, '-f', (Join-Path $projectPath 'compose.local.yml'))
& docker @compose exec -T app php artisan pc-parts:sync-catalog
if ($LASTEXITCODE -ne 0) {
    throw 'The PC component catalog could not be imported.'
}
& docker @compose exec -T app php artisan deal-hunter:setup --user=admin@localhost
if ($LASTEXITCODE -ne 0) {
    throw 'The starter deal searches could not be created.'
}
& docker @compose exec -T app php artisan deal-hunter:refresh --user=admin@localhost
if ($LASTEXITCODE -ne 0) {
    throw 'The initial deal searches could not be queued.'
}

$desktopPath = [Environment]::GetFolderPath('Desktop')
$shortcutPath = Join-Path $desktopPath 'PC Deal Hunter.lnk'
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = 'powershell.exe'
$shortcut.Arguments = '-NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $projectPath 'start-local.ps1') + '"'
$shortcut.WorkingDirectory = $projectPath
$shortcut.Description = 'Start and open PC Deal Hunter'
$shortcut.Save()

Start-Process 'http://localhost:8080/admin'
