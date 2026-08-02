<#
.SYNOPSIS
    Download every GitHub Release zip into the local "Plugin Zip files" folder.

.DESCRIPTION
    Releases are built by CI, so the installable zip lives on GitHub and never
    touches your disk. This pulls them all down so the local archive stays a
    complete history of shipped versions, matching the zips built locally
    before release automation existed.

    Already-present files are skipped, so re-running is cheap and safe. The
    folder is gitignored; these are build artifacts, not source.

.PARAMETER Destination
    Where to put the zips. Defaults to "Plugin Zip files" at the repo root.

.PARAMETER Force
    Re-download even when a file of the same name already exists.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\sync-release-zips.ps1
    powershell -ExecutionPolicy Bypass -File tools\sync-release-zips.ps1 -Force
#>
[CmdletBinding()]
param(
    [string] $Destination,
    [switch] $Force
)

$ErrorActionPreference = 'Stop'

$repoRoot = (git rev-parse --show-toplevel)
if (-not $repoRoot) { throw 'Not inside a git repository.' }

if (-not $Destination) {
    $Destination = Join-Path $repoRoot 'Plugin Zip files'
}
New-Item -ItemType Directory -Force -Path $Destination | Out-Null

# gh may not be on PATH in an already-open shell even when it is installed.
$gh = (Get-Command gh -ErrorAction SilentlyContinue).Source
if (-not $gh -and (Test-Path 'C:\Program Files\GitHub CLI\gh.exe')) {
    $gh = 'C:\Program Files\GitHub CLI\gh.exe'
}
if (-not $gh) { throw 'GitHub CLI (gh) not found. Install it, or add it to PATH.' }

$tagsRaw = & $gh release list --limit 200 --json tagName 2>&1
if ($LASTEXITCODE -ne 0) { throw "gh release list failed: $tagsRaw" }
$tags = ($tagsRaw | ConvertFrom-Json) | ForEach-Object { $_.tagName }

if (-not $tags) {
    Write-Host 'No releases found.'
    return
}

$fetched = 0
$skipped = 0

foreach ($tag in $tags) {
    $assetsRaw = & $gh release view $tag --json assets 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "Could not read assets for ${tag}: $assetsRaw"
        continue
    }
    $assets = ($assetsRaw | ConvertFrom-Json).assets | Where-Object { $_.name -like '*.zip' }

    foreach ($asset in $assets) {
        $target = Join-Path $Destination $asset.name
        if ((Test-Path $target) -and -not $Force) {
            $skipped++
            continue
        }
        & $gh release download $tag --pattern $asset.name --dir $Destination --clobber
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "Download failed for $($asset.name)"
            continue
        }
        Write-Host ("Fetched {0}" -f $asset.name)
        $fetched++
    }
}

$total = (Get-ChildItem $Destination -Filter *.zip | Measure-Object).Count
Write-Host ""
Write-Host ("Fetched {0}, already present {1}. {2} zips now in:" -f $fetched, $skipped, $total)
Write-Host "  $Destination"
