param(
    [Parameter(Mandatory = $false)]
    [string]$SourcePath = (Get-Location).Path,

    [Parameter(Mandatory = $false)]
    [string]$OutputDirectory = "",

    [Parameter(Mandatory = $false)]
    [string[]]$ExcludeDirectoryNames = @("node_modules", ".next", "vendor", "Dependancy"),

    [Parameter(Mandatory = $false)]
    [switch]$FailOnSkippedFiles
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function New-CaseInsensitiveSet {
    param([string[]]$Values)

    $set = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($value in $Values) {
        if (-not [string]::IsNullOrWhiteSpace($value)) {
            [void]$set.Add($value.Trim())
        }
    }
    return ,$set
}

function Add-LogEntry {
    param(
        [Parameter(Mandatory = $true)][string]$Level,
        [Parameter(Mandatory = $true)][string]$Message
    )

    $timestamp = [DateTime]::UtcNow.ToString("o")
    $line = "[{0}] [{1}] {2}" -f $timestamp, $Level.ToUpperInvariant(), $Message
    $script:LogLines.Add($line) | Out-Null
    Write-Host $line
}

function Get-RelativePath {
    param(
        [Parameter(Mandatory = $true)][string]$BasePath,
        [Parameter(Mandatory = $true)][string]$TargetPath
    )

    $baseUri = [Uri]("$($BasePath.TrimEnd('\'))\")
    $targetUri = [Uri]$TargetPath
    $relative = $baseUri.MakeRelativeUri($targetUri).ToString()
    return [Uri]::UnescapeDataString($relative).Replace("/", "\")
}

function Convert-ToZipPath {
    param([Parameter(Mandatory = $true)][string]$Path)
    return $Path.Replace("\", "/")
}

function Test-IsDescendantPath {
    param(
        [Parameter(Mandatory = $true)][string]$ParentPath,
        [Parameter(Mandatory = $true)][string]$CandidatePath
    )

    $normalizedParent = $ParentPath.TrimEnd('\') + '\'
    $normalizedCandidate = $CandidatePath.TrimEnd('\') + '\'
    return $normalizedCandidate.StartsWith($normalizedParent, [System.StringComparison]::OrdinalIgnoreCase)
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$resolvedSource = (Resolve-Path -LiteralPath $SourcePath).Path.TrimEnd('\')
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $sourceParent = Split-Path -Path $resolvedSource -Parent
    $OutputDirectory = Join-Path -Path $sourceParent -ChildPath "backups"
}

$resolvedOutput = [System.IO.Path]::GetFullPath($OutputDirectory)
[void](New-Item -ItemType Directory -Path $resolvedOutput -Force)

$projectName = Split-Path -Path $resolvedSource -Leaf
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$runId = "{0}_{1}" -f $projectName, $timestamp
$zipPath = Join-Path -Path $resolvedOutput -ChildPath ("{0}.zip" -f $runId)
$logPath = Join-Path -Path $resolvedOutput -ChildPath ("{0}.log" -f $runId)
$summaryPath = Join-Path -Path $resolvedOutput -ChildPath ("{0}.summary.json" -f $runId)
$exclusionPath = Join-Path -Path $resolvedOutput -ChildPath ("{0}.excluded.json" -f $runId)
$permissionPath = Join-Path -Path $resolvedOutput -ChildPath ("{0}.permission-issues.json" -f $runId)
$symlinkPath = Join-Path -Path $resolvedOutput -ChildPath ("{0}.symlinks-skipped.json" -f $runId)

$script:LogLines = [System.Collections.Generic.List[string]]::new()
$excludeSet = New-CaseInsensitiveSet -Values $ExcludeDirectoryNames

if (Test-IsDescendantPath -ParentPath $resolvedSource -CandidatePath $resolvedOutput) {
    $excludeSet.Add((Split-Path -Path $resolvedOutput -Leaf)) | Out-Null
    Add-LogEntry -Level "warn" -Message ("Output directory is inside source. Auto-excluding directory name '{0}' to prevent recursive backup." -f (Split-Path -Path $resolvedOutput -Leaf))
}

$excludedDirectories = [System.Collections.Generic.List[object]]::new()
$permissionIssues = [System.Collections.Generic.List[object]]::new()
$skippedSymlinks = [System.Collections.Generic.List[object]]::new()
$archivedFiles = [System.Collections.Generic.List[object]]::new()
$createdDirEntries = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)

Add-LogEntry -Level "info" -Message ("Backup started for '{0}'." -f $resolvedSource)
Add-LogEntry -Level "info" -Message ("Archive path: {0}" -f $zipPath)
Add-LogEntry -Level "info" -Message ("Excluded dependency directories: {0}" -f (($excludeSet | Sort-Object) -join ", "))

$zipArchive = $null
$expectedEntries = @{}
$directoryStack = [System.Collections.Generic.Stack[string]]::new()
$directoryStack.Push($resolvedSource)

try {
    $zipArchive = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)

    while ($directoryStack.Count -gt 0) {
        $currentDir = $directoryStack.Pop()
        $children = @()

        try {
            $children = Get-ChildItem -LiteralPath $currentDir -Force -ErrorAction Stop
        }
        catch {
            $permissionIssues.Add([PSCustomObject]@{
                itemPath = $currentDir
                reason = $_.Exception.Message
            }) | Out-Null
            Add-LogEntry -Level "warn" -Message ("Permission issue while listing directory: {0}" -f $currentDir)
            continue
        }

        foreach ($item in $children) {
            $itemPath = $item.FullName
            $relativePath = Get-RelativePath -BasePath $resolvedSource -TargetPath $itemPath

            $isReparsePoint = (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0)
            if ($isReparsePoint) {
                $skippedSymlinks.Add([PSCustomObject]@{
                    itemPath = $itemPath
                    relativePath = $relativePath
                    type = $(if ($item.PSIsContainer) { "directory" } else { "file" })
                }) | Out-Null
                Add-LogEntry -Level "warn" -Message ("Skipped symbolic link: {0}" -f $itemPath)
                continue
            }

            if ($item.PSIsContainer) {
                if ($excludeSet.Contains($item.Name)) {
                    $excludedDirectories.Add([PSCustomObject]@{
                        directoryPath = $itemPath
                        relativePath = $relativePath
                        reason = "Excluded dependency directory name"
                    }) | Out-Null
                    Add-LogEntry -Level "info" -Message ("Excluded directory: {0}" -f $itemPath)
                    continue
                }

                $zipDirectoryEntry = (Convert-ToZipPath -Path $relativePath).TrimEnd("/") + "/"
                if (-not [string]::IsNullOrWhiteSpace($zipDirectoryEntry) -and $createdDirEntries.Add($zipDirectoryEntry)) {
                    [void]$zipArchive.CreateEntry($zipDirectoryEntry)
                }

                $directoryStack.Push($itemPath)
                continue
            }

            $zipEntryPath = Convert-ToZipPath -Path $relativePath
            try {
                [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $zipArchive,
                    $itemPath,
                    $zipEntryPath,
                    [System.IO.Compression.CompressionLevel]::Optimal
                )

                $fileInfo = [System.IO.FileInfo]::new($itemPath)
                $expectedEntries[$zipEntryPath] = $fileInfo.Length
                $archivedFiles.Add([PSCustomObject]@{
                    filePath = $itemPath
                    relativePath = $relativePath
                    zipEntry = $zipEntryPath
                    sizeBytes = $fileInfo.Length
                }) | Out-Null
            }
            catch {
                $permissionIssues.Add([PSCustomObject]@{
                    itemPath = $itemPath
                    reason = $_.Exception.Message
                }) | Out-Null
                Add-LogEntry -Level "warn" -Message ("Skipped file due to read/compression issue: {0}" -f $itemPath)
            }
        }
    }

    # Add backup metadata inside the archive for clear structure documentation.
    $manifest = [PSCustomObject]@{
        backupCreatedUtc = [DateTime]::UtcNow.ToString("o")
        sourcePath = $resolvedSource
        archiveFile = $zipPath
        exclusionRules = [string[]]($excludeSet | Sort-Object)
        includedFileCount = $archivedFiles.Count
        excludedDirectoryCount = $excludedDirectories.Count
        skippedSymlinkCount = $skippedSymlinks.Count
        permissionIssueCount = $permissionIssues.Count
        notes = @(
            "Dependency directories are excluded by name anywhere in the tree.",
            "Hidden files are included.",
            "Symbolic links/reparse points are skipped to prevent recursion and unsafe traversal."
        )
    }

    $manifestJson = $manifest | ConvertTo-Json -Depth 5
    $structureDoc = @(
        "Backup Structure Overview"
        "========================="
        ""
        "Root of archive:"
        "- Original project tree with relative paths preserved."
        "- _backup/manifest.json: machine-readable metadata for this backup."
        "- _backup/README.txt: human-readable explanation of included/excluded content."
        ""
        "Exclusion behavior:"
        ("- Excluded folder names: {0}" -f (($excludeSet | Sort-Object) -join ", "))
        "- Exclusions are applied recursively at any depth."
        "- Hidden files are retained."
        "- Reparse points (symbolic links/junctions) are skipped."
    ) -join [Environment]::NewLine

    $internalReadme = @(
        "This backup contains source code, configuration, docs, and static assets."
        "Dependency directories are excluded because they can be regenerated with package managers."
        ""
        "Use the external *.summary.json, *.excluded.json, *.symlinks-skipped.json and *.permission-issues.json files"
        "next to this archive for detailed run logs."
    ) -join [Environment]::NewLine

    $manifestEntry = $zipArchive.CreateEntry("_backup/manifest.json")
    $manifestWriter = [System.IO.StreamWriter]::new($manifestEntry.Open())
    $manifestWriter.Write($manifestJson)
    $manifestWriter.Dispose()

    $structureEntry = $zipArchive.CreateEntry("_backup/README.txt")
    $structureWriter = [System.IO.StreamWriter]::new($structureEntry.Open())
    $structureWriter.Write($structureDoc + [Environment]::NewLine + [Environment]::NewLine + $internalReadme)
    $structureWriter.Dispose()
}
finally {
    if ($null -ne $zipArchive) {
        $zipArchive.Dispose()
    }
}

Add-LogEntry -Level "info" -Message "Running integrity verification against archive entries."
$missingEntries = [System.Collections.Generic.List[string]]::new()
$sizeMismatchEntries = [System.Collections.Generic.List[string]]::new()
$archiveOpenErrors = [System.Collections.Generic.List[string]]::new()

$zipRead = $null
try {
    $zipRead = [System.IO.Compression.ZipFile]::OpenRead($zipPath)

    foreach ($expectedEntryName in $expectedEntries.Keys) {
        $entry = $zipRead.GetEntry($expectedEntryName)
        if ($null -eq $entry) {
            $missingEntries.Add($expectedEntryName) | Out-Null
            continue
        }

        $expectedLength = [int64]$expectedEntries[$expectedEntryName]
        if ($entry.Length -ne $expectedLength) {
            $sizeMismatchEntries.Add($expectedEntryName) | Out-Null
        }

        try {
            $stream = $entry.Open()
            $null = $stream.ReadByte()
            $stream.Dispose()
        }
        catch {
            $archiveOpenErrors.Add($expectedEntryName) | Out-Null
        }
    }
}
finally {
    if ($null -ne $zipRead) {
        $zipRead.Dispose()
    }
}

$verificationPassed = ($missingEntries.Count -eq 0 -and $sizeMismatchEntries.Count -eq 0 -and $archiveOpenErrors.Count -eq 0)

$summary = [PSCustomObject]@{
    runId = $runId
    sourcePath = $resolvedSource
    outputDirectory = $resolvedOutput
    archivePath = $zipPath
    logPath = $logPath
    backupCreatedUtc = [DateTime]::UtcNow.ToString("o")
    includedFileCount = $archivedFiles.Count
    excludedDirectoryCount = $excludedDirectories.Count
    skippedSymlinkCount = $skippedSymlinks.Count
    permissionIssueCount = $permissionIssues.Count
    verification = [PSCustomObject]@{
        passed = $verificationPassed
        missingEntries = @($missingEntries)
        sizeMismatches = @($sizeMismatchEntries)
        unreadableEntries = @($archiveOpenErrors)
    }
}

$summary | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $summaryPath -Encoding UTF8
$excludedDirectories | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $exclusionPath -Encoding UTF8
$permissionIssues | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $permissionPath -Encoding UTF8
$skippedSymlinks | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $symlinkPath -Encoding UTF8
$script:LogLines | Set-Content -LiteralPath $logPath -Encoding UTF8

Add-LogEntry -Level "info" -Message ("Backup completed. Included files: {0}" -f $archivedFiles.Count)
Add-LogEntry -Level "info" -Message ("Excluded dependency directories: {0}" -f $excludedDirectories.Count)
Add-LogEntry -Level "info" -Message ("Skipped symbolic links: {0}" -f $skippedSymlinks.Count)
Add-LogEntry -Level "info" -Message ("Permission issues: {0}" -f $permissionIssues.Count)
Add-LogEntry -Level "info" -Message ("Verification passed: {0}" -f $verificationPassed)

if ($FailOnSkippedFiles -and ($permissionIssues.Count -gt 0 -or $archiveOpenErrors.Count -gt 0 -or $missingEntries.Count -gt 0 -or $sizeMismatchEntries.Count -gt 0)) {
    throw "Backup completed with skipped/problematic files and -FailOnSkippedFiles was requested."
}

if (-not $verificationPassed) {
    throw "Backup integrity verification failed. See summary JSON for details."
}

Write-Output ("Backup archive: {0}" -f $zipPath)
Write-Output ("Summary report: {0}" -f $summaryPath)
Write-Output ("Excluded report: {0}" -f $exclusionPath)
Write-Output ("Permission report: {0}" -f $permissionPath)
Write-Output ("Symlink report: {0}" -f $symlinkPath)
Write-Output ("Log file: {0}" -f $logPath)
