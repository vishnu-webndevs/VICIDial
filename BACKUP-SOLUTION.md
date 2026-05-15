# Project Backup Solution (Dependency Folders Excluded)

## Overview

This project includes `backup-project.ps1`, a robust PowerShell backup script that:

- Creates a timestamped compressed archive (`.zip`)
- Preserves original project directory structure for included content
- Excludes dependency folders that can be regenerated (`node_modules`, `.next`, `vendor`, `Dependancy`)
- Includes hidden files
- Skips symbolic links/reparse points safely
- Logs exclusions and permission issues in detail
- Verifies archive integrity after creation

## What Is Included

- Source code files
- Configuration files
- Documentation
- Static assets
- Any non-excluded folders and files under the selected source root

## What Is Excluded

Excluded by folder name at any depth:

- `node_modules`
- `.next`
- `vendor`
- `Dependancy`

Symbolic links and reparse points are also skipped and logged to avoid recursion and unsafe traversal.

## Usage

From project root:

```powershell
powershell -ExecutionPolicy Bypass -File .\backup-project.ps1
```

Custom source/output:

```powershell
powershell -ExecutionPolicy Bypass -File .\backup-project.ps1 `
  -SourcePath "C:\xampp\htdocs\vicidial" `
  -OutputDirectory "C:\xampp\htdocs\backups"
```

Custom exclusion list:

```powershell
powershell -ExecutionPolicy Bypass -File .\backup-project.ps1 `
  -ExcludeDirectoryNames @("node_modules", ".next", "vendor", "Dependancy", "bower_components")
```

Strict mode (fails if files are skipped or verification reports issues):

```powershell
powershell -ExecutionPolicy Bypass -File .\backup-project.ps1 -FailOnSkippedFiles
```

## Outputs

For each run, the script generates files in `OutputDirectory`:

- `<project>_<timestamp>.zip`
- `<project>_<timestamp>.log`
- `<project>_<timestamp>.summary.json`
- `<project>_<timestamp>.excluded.json`
- `<project>_<timestamp>.permission-issues.json`
- `<project>_<timestamp>.symlinks-skipped.json`

Timestamp format: `yyyyMMdd_HHmmss`

## Archive Structure

The ZIP contains:

- The original project tree (relative paths preserved)
- `_backup/manifest.json` (embedded metadata)
- `_backup/README.txt` (embedded structure and exclusion notes)

## Integrity Verification

After writing the archive, the script validates:

- Every expected entry exists in the ZIP
- Uncompressed entry size matches the source file size
- Entry stream can be opened/read

If verification fails, the script throws an error and details are written to `summary.json`.

## Edge Case Handling

- Hidden files: included via `Get-ChildItem -Force`
- Symbolic links/junctions: skipped and logged
- Permission/read issues: captured, logged, and summarized
- Output folder inside source tree: automatically excluded to prevent recursive self-backup

