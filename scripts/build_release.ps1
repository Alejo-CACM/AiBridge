<#
Builds dist/aibridge-<version>.zip and dist/manifest.json from the current
working tree. Run from the module root (or anywhere - it resolves its own path).

Zip entries are forced to forward slashes because PrestaShop's ZipArchive
extraction on Linux hosts does not treat backslashes as path separators
(Compress-Archive on Windows produces backslash-separated entries, which
silently fail to extract into subfolders).
#>

$ErrorActionPreference = 'Stop'

$moduleRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$repoRoot = Resolve-Path (Join-Path $moduleRoot '..\..')

# Read version from aibridge.php
$aibridgePhp = Get-Content (Join-Path $moduleRoot 'aibridge.php') -Raw
if ($aibridgePhp -notmatch "\`$this->version\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+)'") {
    throw "Could not read version from aibridge.php"
}
$version = $Matches[1]

$distDir = Join-Path $moduleRoot 'dist'
New-Item -ItemType Directory -Force -Path $distDir | Out-Null

$zipName = "aibridge-$version.zip"
$zipPath = Join-Path $distDir $zipName
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Files/dirs to exclude from the package (relative to module root)
$excludeDirs = @('.git', 'dist', 'graphify-out', 'scripts')
$excludeFiles = @('HANDOFF.md', '.gitignore')

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)

Get-ChildItem -Path $moduleRoot -Recurse -File | ForEach-Object {
    $relPath = $_.FullName.Substring($moduleRoot.Path.Length + 1)
    $topDir = ($relPath -split '[\\/]')[0]
    if ($excludeDirs -contains $topDir) { return }
    if ($excludeFiles -contains $relPath) { return }

    # Entries live under an "aibridge/" root inside the zip, matching how
    # PrestaShop expects module packages to be laid out.
    $entryName = 'aibridge/' + ($relPath -replace '\\', '/')
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entryName) | Out-Null
}

$zip.Dispose()

$sha256 = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash.ToLower()

$manifest = [ordered]@{
    version       = $version
    download_url  = "https://raw.githubusercontent.com/Alejo-CACM/AiBridge/main/dist/$zipName"
    sha256        = $sha256
    changelog     = "Release $version"
    min_ps_version = "8.0.0"
}

$manifestPath = Join-Path $distDir 'manifest.json'
$json = $manifest | ConvertTo-Json -Depth 4
[System.IO.File]::WriteAllText($manifestPath, $json, [System.Text.UTF8Encoding]::new($false))

Write-Host "Built $zipPath"
Write-Host "sha256: $sha256"
Write-Host "manifest.json written to $manifestPath"
