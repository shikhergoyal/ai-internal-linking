<#
.SYNOPSIS
    Builds the installable plugin zip locally, the same way the release
    workflow does.

.DESCRIPTION
    Uses `git archive` on the ai-internal-linking subtree. This matters:
    Windows' Compress-Archive can write BACKSLASH path separators, which
    produces a zip WordPress cannot install. git archive always writes
    forward slashes, and only includes committed files.

    The version is read from the plugin header, so the zip is always named
    after what it actually contains.

.PARAMETER Ref
    Git ref to build from. Defaults to HEAD.

.PARAMETER AlsoDownloads
    Also refresh %USERPROFILE%\Downloads\ai-internal-linking.zip.

.EXAMPLE
    pwsh tools/build-zip.ps1
    pwsh tools/build-zip.ps1 -Ref v0.12.1 -AlsoDownloads
#>
[CmdletBinding()]
param(
    [string] $Ref = 'HEAD',
    [switch] $AlsoDownloads
)

$ErrorActionPreference = 'Stop'

# Always operate from the repository root, whatever the caller's cwd is.
$repoRoot = (git rev-parse --show-toplevel)
if (-not $repoRoot) { throw 'Not inside a git repository.' }
Set-Location $repoRoot

# git archive packages committed content only, so uncommitted edits are
# silently excluded. Say so rather than shipping a surprising zip.
$dirty = git status --porcelain -- ai-internal-linking
if ($dirty) {
    Write-Warning 'Uncommitted changes under ai-internal-linking/ will NOT be in the zip (git archive packages committed content only):'
    $dirty | ForEach-Object { Write-Warning "  $_" }
}

$header = git show "${Ref}:ai-internal-linking/ai-internal-linking.php" |
          Select-String -Pattern '^\s*\*\s*Version:\s*([0-9][^\s]*)' |
          Select-Object -First 1
if (-not $header) { throw "Could not read the Version header from ${Ref}:ai-internal-linking/ai-internal-linking.php" }
$version = $header.Matches[0].Groups[1].Value

$stable = git show "${Ref}:ai-internal-linking/readme.txt" |
          Select-String -Pattern '^Stable tag:\s*([0-9][^\s]*)' |
          Select-Object -First 1
if ($stable -and $stable.Matches[0].Groups[1].Value -ne $version) {
    Write-Warning "readme.txt Stable tag ($($stable.Matches[0].Groups[1].Value)) does not match the plugin header ($version). The release workflow treats this as an error."
}

$outDir = Join-Path $repoRoot 'Plugin Zip files'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$outZip = Join-Path $outDir "ai-internal-linking-v$version.zip"

git archive --format=zip --prefix=ai-internal-linking/ -o "$outZip" "${Ref}:ai-internal-linking"
if ($LASTEXITCODE -ne 0) { throw 'git archive failed.' }

# Verify the package before anyone tries to install it.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($outZip)
try {
    $names = $zip.Entries | ForEach-Object { $_.FullName }
    if ($names | Where-Object { $_ -like '*\*' }) {
        throw "Zip contains backslash paths and would be unusable: $outZip"
    }
    # @() matters: with a single root, Sort-Object returns a bare string and
    # $roots[0] would index its first character instead of the folder name.
    $roots = @($names | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique)
    if ($roots.Count -ne 1 -or $roots[0] -ne 'ai-internal-linking') {
        throw "Zip must contain exactly one top level folder 'ai-internal-linking', found: $($roots -join ', ')"
    }
    $entry = $zip.Entries | Where-Object { $_.FullName -eq 'ai-internal-linking/ai-internal-linking.php' }
    if (-not $entry) { throw 'Zip is missing the main plugin file.' }
    $reader = New-Object System.IO.StreamReader($entry.Open())
    try { $embedded = $reader.ReadToEnd() } finally { $reader.Dispose() }
    if ($embedded -notmatch "AILINKING_VERSION',\s*'$([regex]::Escape($version))'") {
        throw "Embedded AILINKING_VERSION does not match $version."
    }
    Write-Host "OK  $outZip"
    Write-Host "    version $version, $($names.Count) entries, forward slash paths only"
}
finally { $zip.Dispose() }

if ($AlsoDownloads) {
    $dl = Join-Path $env:USERPROFILE 'Downloads\ai-internal-linking.zip'
    Copy-Item $outZip $dl -Force
    Write-Host "OK  $dl"
}
