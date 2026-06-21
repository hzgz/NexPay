$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot

$targets = @(
    @{ Name = 'home'; Source = Join-Path $projectRoot 'frontend\home'; Dest = Join-Path $projectRoot 'backend\public'; Clear = @('index.html', 'assets', 'brand', 'payment-icons', 'icons', 'doc', 'home', 'favicon.ico', 'favicon.svg', 'icons.svg') },
    @{ Name = 'admin'; Source = Join-Path $projectRoot 'frontend\admin'; Dest = Join-Path $projectRoot 'backend\public\admin'; Clear = @() },
    @{ Name = 'user'; Source = Join-Path $projectRoot 'frontend\user'; Dest = Join-Path $projectRoot 'backend\public\user'; Clear = @() }
)

foreach ($target in $targets) {
    Write-Host "Building $($target.Name)..."
    Push-Location $target.Source
    try {
        cmd /c npm run build
        if ($LASTEXITCODE -ne 0) {
            throw "Build failed for $($target.Name) with exit code $LASTEXITCODE."
        }
    }
    finally {
        Pop-Location
    }

    $distPath = Join-Path $target.Source 'dist'
    if (-not (Test-Path $distPath)) {
        throw "Build output not found for $($target.Name)."
    }

    if ($target.Name -eq 'home') {
        foreach ($item in $target.Clear) {
            $full = Join-Path $target.Dest $item
            if (Test-Path $full) {
                Remove-Item -LiteralPath $full -Recurse -Force
            }
        }
    } else {
        if (Test-Path $target.Dest) {
            Remove-Item -LiteralPath $target.Dest -Recurse -Force
        }

        New-Item -ItemType Directory -Path $target.Dest | Out-Null
    }

    Copy-Item -Path (Join-Path $target.Source 'dist\*') -Destination $target.Dest -Recurse -Force

    $distIndex = Join-Path $target.Source 'dist\index.html'
    $destIndex = Join-Path $target.Dest 'index.html'
    if (Test-Path $distIndex) {
        Copy-Item -LiteralPath $distIndex -Destination $destIndex -Force
    }

    if (Test-Path $distPath) {
        Remove-Item -LiteralPath $distPath -Recurse -Force
    }
}

Write-Host 'Release build copied to backend/public successfully.'
