param(
    [string]$SourcePath = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path,
    [string]$OutputDirectory = "",
    [string]$BackupName = "",
    [switch]$FailOnWarnings
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.Security

function Write-Step {
    param([string]$Message)
    $ts = [DateTime]::UtcNow.ToString("o")
    Write-Host "[$ts] [backup] $Message"
}

function Get-RelativePath {
    param(
        [string]$BasePath,
        [string]$TargetPath
    )

    $baseUri = [Uri]("$($BasePath.TrimEnd('\'))\")
    $targetUri = [Uri]$TargetPath
    $relative = $baseUri.MakeRelativeUri($targetUri).ToString()
    return [Uri]::UnescapeDataString($relative).Replace("/", "\")
}

function Convert-ToZipPath {
    param([string]$RelativePath)
    return $RelativePath.Replace("\", "/")
}

function Convert-StreamToSha256Hex {
    param([System.IO.Stream]$InputStream)

    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $hashBytes = $sha.ComputeHash($InputStream)
        return ([BitConverter]::ToString($hashBytes)).Replace("-", "").ToLowerInvariant()
    }
    finally {
        $sha.Dispose()
    }
}

function Get-FileSha256Hex {
    param([string]$FilePath)

    $stream = [System.IO.File]::OpenRead($FilePath)
    try {
        return Convert-StreamToSha256Hex -InputStream $stream
    }
    finally {
        $stream.Dispose()
    }
}

function Safe-GetAclSddl {
    param([string]$Path)

    try {
        $acl = Get-Acl -LiteralPath $Path -ErrorAction Stop
        return $acl.GetSecurityDescriptorSddlForm([System.Security.AccessControl.AccessControlSections]::All)
    }
    catch {
        return $null
    }
}

function Test-MatchesAnyPattern {
    param(
        [string]$Value,
        [string[]]$Patterns
    )

    foreach ($pattern in $Patterns) {
        if ($Value -like $pattern) {
            return $true
        }
    }

    return $false
}

$resolvedSource = (Resolve-Path -LiteralPath $SourcePath).Path.TrimEnd('\')
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $resolvedSource "backups"
}
$resolvedOutput = [System.IO.Path]::GetFullPath($OutputDirectory)
[void](New-Item -ItemType Directory -Path $resolvedOutput -Force)

$excludedDirectoryNames = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
@(
    "node_modules", "vendor", ".next", "dist", "build", "out", ".nuxt",
    ".cache", ".temp", "tmp", "temp", ".turbo", ".parcel-cache",
    ".git", ".svn", ".hg", ".idea", ".vs", ".vscode",
    "coverage", ".nyc_output", ".pytest_cache", "__pycache__",
    ".mypy_cache", ".ruff_cache", ".sass-cache", ".pnpm-store",
    "backups"
) | ForEach-Object { [void]$excludedDirectoryNames.Add($_) }

$excludedFilePatterns = @(
    "*.log", "*.tmp", "*.temp", "*.cache",
    "npm-debug.log*", "yarn-error.log*", "pnpm-debug.log*",
    ".DS_Store", "Thumbs.db",
    ".env.local", ".env.*.local",
    "*.tsbuildinfo"
)

$excludedRelativePathPatterns = @(
    "*/storage/logs/*",
    "*/bootstrap/cache/*",
    "*/.next/*",
    "*/dist/*",
    "*/build/*"
)

if ($resolvedOutput.StartsWith($resolvedSource, [System.StringComparison]::OrdinalIgnoreCase)) {
    $outputLeaf = Split-Path -Path $resolvedOutput -Leaf
    [void]$excludedDirectoryNames.Add($outputLeaf)
}

$projectName = Split-Path -Path $resolvedSource -Leaf
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$runId = if ([string]::IsNullOrWhiteSpace($BackupName)) { "$projectName`_$timestamp" } else { $BackupName }

$zipPath = Join-Path $resolvedOutput "$runId.zip"
$summaryPath = Join-Path $resolvedOutput "$runId.summary.json"
$excludedPath = Join-Path $resolvedOutput "$runId.excluded.json"
$permissionsPath = Join-Path $resolvedOutput "$runId.permissions.json"
$validationPath = Join-Path $resolvedOutput "$runId.validation.json"

Write-Step "Source: $resolvedSource"
Write-Step "Archive: $zipPath"
Write-Step "Output dir: $resolvedOutput"

$excludedItems = [System.Collections.Generic.List[object]]::new()
$permissionMetadata = [System.Collections.Generic.List[object]]::new()
$includedFiles = [System.Collections.Generic.List[object]]::new()
$warnings = [System.Collections.Generic.List[string]]::new()
$expectedEntries = @{}

$zip = $null
$directories = [System.Collections.Generic.Stack[string]]::new()
$directories.Push($resolvedSource)

try {
    $zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)

    while ($directories.Count -gt 0) {
        $currentDir = $directories.Pop()
        $children = Get-ChildItem -LiteralPath $currentDir -Force

        foreach ($item in $children) {
            $relativePath = Get-RelativePath -BasePath $resolvedSource -TargetPath $item.FullName
            $relativePathSlash = $relativePath.Replace("\", "/")
            $isReparsePoint = (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0)

            if ($isReparsePoint) {
                $excludedItems.Add([PSCustomObject]@{
                    type = if ($item.PSIsContainer) { "directory" } else { "file" }
                    relativePath = $relativePath
                    reason = "reparse-point/symlink excluded"
                }) | Out-Null
                continue
            }

            if ($item.PSIsContainer) {
                if ($excludedDirectoryNames.Contains($item.Name)) {
                    $excludedItems.Add([PSCustomObject]@{
                        type = "directory"
                        relativePath = $relativePath
                        reason = "excluded directory name"
                    }) | Out-Null
                    continue
                }

                if (Test-MatchesAnyPattern -Value $relativePathSlash -Patterns $excludedRelativePathPatterns) {
                    $excludedItems.Add([PSCustomObject]@{
                        type = "directory"
                        relativePath = $relativePath
                        reason = "excluded directory path pattern"
                    }) | Out-Null
                    continue
                }

                $directories.Push($item.FullName)
                continue
            }

            $excludeByFileName = Test-MatchesAnyPattern -Value $item.Name -Patterns $excludedFilePatterns
            $excludeByRelative = Test-MatchesAnyPattern -Value $relativePathSlash -Patterns $excludedRelativePathPatterns

            if ($excludeByFileName -or $excludeByRelative) {
                $excludedItems.Add([PSCustomObject]@{
                    type = "file"
                    relativePath = $relativePath
                    reason = if ($excludeByFileName) { "excluded filename pattern" } else { "excluded path pattern" }
                }) | Out-Null
                continue
            }

            $zipEntryPath = Convert-ToZipPath -RelativePath $relativePath
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip,
                $item.FullName,
                $zipEntryPath,
                [System.IO.Compression.CompressionLevel]::Optimal
            )

            $sha256 = $null
            try {
                $sha256 = Get-FileSha256Hex -FilePath $item.FullName
            }
            catch {
                $warnings.Add("Failed to hash source file: $relativePath - $($_.Exception.Message)") | Out-Null
            }

            $aclSddl = Safe-GetAclSddl -Path $item.FullName
            if ($null -eq $aclSddl) {
                $warnings.Add("Failed to capture ACL metadata: $relativePath") | Out-Null
            }

            $fileInfo = [System.IO.FileInfo]::new($item.FullName)
            $metadata = [PSCustomObject]@{
                relativePath = $relativePath
                zipEntry = $zipEntryPath
                sizeBytes = $fileInfo.Length
                lastWriteTimeUtc = $fileInfo.LastWriteTimeUtc.ToString("o")
                attributes = $fileInfo.Attributes.ToString()
                aclSddl = $aclSddl
                sha256 = $sha256
            }

            $includedFiles.Add($metadata) | Out-Null
            $permissionMetadata.Add([PSCustomObject]@{
                relativePath = $relativePath
                attributes = $fileInfo.Attributes.ToString()
                aclSddl = $aclSddl
            }) | Out-Null

            $expectedEntries[$zipEntryPath] = $metadata
        }
    }

    $manifestObject = [PSCustomObject]@{
        runId = $runId
        backupCreatedUtc = [DateTime]::UtcNow.ToString("o")
        sourcePath = $resolvedSource
        excludedDirectoryNames = @($excludedDirectoryNames | Sort-Object)
        excludedFilePatterns = $excludedFilePatterns
        excludedRelativePathPatterns = $excludedRelativePathPatterns
        includedFileCount = $includedFiles.Count
        excludedItemCount = $excludedItems.Count
        notes = @(
            "Archive preserves original directory structure.",
            "Timestamps are preserved at zip entry level.",
            "Permissions are captured as metadata in _backup/permissions.json for restoration workflows."
        )
    }

    $manifestEntry = $zip.CreateEntry("_backup/manifest.json")
    $manifestWriter = [System.IO.StreamWriter]::new($manifestEntry.Open())
    $manifestWriter.Write(($manifestObject | ConvertTo-Json -Depth 8))
    $manifestWriter.Dispose()

    $permissionsEntry = $zip.CreateEntry("_backup/permissions.json")
    $permissionsWriter = [System.IO.StreamWriter]::new($permissionsEntry.Open())
    $permissionsWriter.Write(($permissionMetadata | ConvertTo-Json -Depth 8))
    $permissionsWriter.Dispose()
}
finally {
    if ($null -ne $zip) {
        $zip.Dispose()
    }
}

Write-Step "Created archive, starting integrity validation."

$missingEntries = [System.Collections.Generic.List[string]]::new()
$sizeMismatches = [System.Collections.Generic.List[string]]::new()
$hashMismatches = [System.Collections.Generic.List[string]]::new()
$timestampMismatches = [System.Collections.Generic.List[string]]::new()
$unreadableEntries = [System.Collections.Generic.List[string]]::new()

$zipRead = $null
try {
    $zipRead = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    foreach ($entryName in $expectedEntries.Keys) {
        $expected = $expectedEntries[$entryName]
        $entry = $zipRead.GetEntry($entryName)

        if ($null -eq $entry) {
            $missingEntries.Add($entryName) | Out-Null
            continue
        }

        if ($entry.Length -ne [int64]$expected.sizeBytes) {
            $sizeMismatches.Add($entryName) | Out-Null
        }

        try {
            $stream = $entry.Open()
            $entryHash = Convert-StreamToSha256Hex -InputStream $stream
            $stream.Dispose()

            if (-not [string]::IsNullOrWhiteSpace($expected.sha256) -and $entryHash -ne $expected.sha256) {
                $hashMismatches.Add($entryName) | Out-Null
            }
        }
        catch {
            $unreadableEntries.Add($entryName) | Out-Null
        }

        $expectedUtc = [DateTime]::Parse($expected.lastWriteTimeUtc).ToUniversalTime()
        $entryUtc = $entry.LastWriteTime.UtcDateTime
        if ([Math]::Abs(($expectedUtc - $entryUtc).TotalSeconds) -gt 2) {
            $timestampMismatches.Add($entryName) | Out-Null
        }
    }
}
finally {
    if ($null -ne $zipRead) {
        $zipRead.Dispose()
    }
}

$verificationPassed = (
    $missingEntries.Count -eq 0 -and
    $sizeMismatches.Count -eq 0 -and
    $hashMismatches.Count -eq 0 -and
    $timestampMismatches.Count -eq 0 -and
    $unreadableEntries.Count -eq 0
)

$archiveSizeBytes = ([System.IO.FileInfo]::new($zipPath)).Length
$totalSourceBytes = ($includedFiles | Measure-Object -Property sizeBytes -Sum).Sum
if ($null -eq $totalSourceBytes) { $totalSourceBytes = 0 }

$summary = [PSCustomObject]@{
    runId = $runId
    sourcePath = $resolvedSource
    archivePath = $zipPath
    archiveSizeBytes = [int64]$archiveSizeBytes
    includedFileCount = $includedFiles.Count
    excludedItemCount = $excludedItems.Count
    totalIncludedSourceBytes = [int64]$totalSourceBytes
    compressionRatio = if ($totalSourceBytes -gt 0) { [Math]::Round(($archiveSizeBytes / $totalSourceBytes), 4) } else { 0 }
    validation = [PSCustomObject]@{
        passed = $verificationPassed
        missingEntries = @($missingEntries)
        sizeMismatches = @($sizeMismatches)
        hashMismatches = @($hashMismatches)
        timestampMismatches = @($timestampMismatches)
        unreadableEntries = @($unreadableEntries)
    }
    excludedBreakdown = [PSCustomObject]@{
        directories = ($excludedItems | Where-Object { $_.type -eq "directory" }).Count
        files = ($excludedItems | Where-Object { $_.type -eq "file" }).Count
    }
    warnings = @($warnings)
}

$summary | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $summaryPath -Encoding UTF8
$excludedItems | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $excludedPath -Encoding UTF8
$permissionMetadata | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $permissionsPath -Encoding UTF8

$validationReport = [PSCustomObject]@{
    archivePath = $zipPath
    verifiedAtUtc = [DateTime]::UtcNow.ToString("o")
    passed = $verificationPassed
    checks = @(
        "entry existence",
        "entry size",
        "entry hash (SHA-256)",
        "entry timestamp preservation (<=2 seconds tolerance)",
        "entry readability"
    )
    result = $summary.validation
}
$validationReport | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $validationPath -Encoding UTF8

Write-Step "Backup size (bytes): $archiveSizeBytes"
Write-Step "Included files: $($includedFiles.Count)"
Write-Step "Excluded items: $($excludedItems.Count)"
Write-Step "Validation passed: $verificationPassed"
Write-Step "Summary report: $summaryPath"
Write-Step "Excluded items report: $excludedPath"
Write-Step "Permissions metadata report: $permissionsPath"
Write-Step "Validation report: $validationPath"

if (-not $verificationPassed) {
    throw "Backup integrity validation failed. See $validationPath"
}

if ($FailOnWarnings -and $warnings.Count -gt 0) {
    throw "Backup completed with warnings. See $summaryPath"
}
