# Build Coolify dev images using buildx (avoids Docker Desktop overlay/containerd issues).
# Run from repo root: .\scripts\build-dev-with-buildx.ps1
# Then: docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

# Use buildx with docker-container driver so builds run in a container (avoids host overlay corruption)
$builder = "coolifybuilder"
docker buildx inspect $builder 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Creating buildx builder '$builder'..."
    docker buildx create --name $builder --use
}

$build = {
    param($file, $tag)
    Write-Host "Building $tag..."
    docker buildx build -f $file -t $tag --build-arg USER_ID=1000 --build-arg GROUP_ID=1000 --load --progress=plain .
}

& $build "docker/development/Dockerfile" "coolify:dev"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $build "docker/coolify-realtime/Dockerfile" "coolify-realtime:dev"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $build "docker/testing-host/Dockerfile" "coolify-testing-host:dev"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "All images built. Start with: docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d"
