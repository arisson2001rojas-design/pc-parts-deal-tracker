$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
docker compose --env-file (Join-Path $projectPath '.env.local') -f (Join-Path $projectPath 'compose.local.yml') down
