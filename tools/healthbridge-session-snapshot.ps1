#requires -Version 5.1
<#
.SYNOPSIS
  Creates a read-only HealthBridge session snapshot for ChatGPT handoff.
.DESCRIPTION
  Captures Git state, current roadmap priorities, active handoff, selected
  open-finding candidates, core-document metadata and recent QA folder names.
  Writes latest.md and latest-changed-paths.txt outside the application tree.
  Does not inspect .env files, connect to databases, run tests, modify source,
  stage/commit, or access hosted resources.
.EXAMPLE
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\healthbridge-session-snapshot.ps1
.EXAMPLE
  .\tools\healthbridge-session-snapshot.ps1 -Archive
#>
[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$OutputRoot = '',
    [int]$RecentQaFolders = 10,
    [switch]$Archive
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) {
        throw 'Cannot resolve script location. Pass -ProjectRoot explicitly.'
    }
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $scriptPath)
}
$ProjectRoot = [System.IO.Path]::GetFullPath($ProjectRoot)
if (-not (Test-Path -LiteralPath $ProjectRoot -PathType Container)) {
    throw "Project root does not exist: $ProjectRoot"
}

$backupBase = Split-Path -Parent (Split-Path -Parent $ProjectRoot)
$qaRoot = Join-Path $backupBase 'migration-backups\healthbridge'
if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = Join-Path $qaRoot 'session-snapshots'
}
$OutputRoot = [System.IO.Path]::GetFullPath($OutputRoot)
New-Item -ItemType Directory -Force -Path $OutputRoot | Out-Null

$timestamp = Get-Date
$stamp = $timestamp.ToString('yyyy-MM-dd_HHmmss')
$handoffPath = Join-Path $ProjectRoot 'HEALTHBRIDGE_REPAIR_PLAN_AND_HANDOFF.md'
$roadmapPath = Join-Path $ProjectRoot 'docs\HEALTHBRIDGE_ENGINEERING_ROADMAP.md'
$findingsPath = Join-Path $ProjectRoot 'docs\HEALTHBRIDGE_FINDINGS_REGISTER.md'

function Invoke-GitText {
    param([string[]]$GitArgs)
    $result = & git -C $ProjectRoot @GitArgs 2>&1
    if ($LASTEXITCODE -ne 0) { return '' }
    return (($result | ForEach-Object { [string]$_ }) -join [Environment]::NewLine)
}

# Read-only Git snapshot. Preserve leading spaces in porcelain status output.
$branch = (Invoke-GitText @('branch', '--show-current')).Trim()
$head = (Invoke-GitText @('rev-parse', 'HEAD')).Trim()
$headShort = (Invoke-GitText @('rev-parse', '--short', 'HEAD')).Trim()
$commitDate = (Invoke-GitText @('show', '-s', '--format=%cI', 'HEAD')).Trim()
$statusRaw = Invoke-GitText @('status', '--porcelain=v1', '--untracked-files=all')
$statusLines = @()
if ($statusRaw) {
    $statusLines = @($statusRaw -split '\r?\n' | Where-Object { $_.Length -gt 0 })
}

$untracked = 0
$staged = 0
$unstaged = 0
$deleted = 0
$renamed = 0
$changedPaths = @()
foreach ($line in $statusLines) {
    $code = $line.Substring(0, [Math]::Min(2, $line.Length))
    $path = if ($line.Length -gt 3) { $line.Substring(3) } else { $line }
    if ($code -eq '??') {
        $untracked++
    } else {
        if ($code[0] -ne ' ') { $staged++ }
        if ($code[1] -ne ' ') { $unstaged++ }
        if ($code.Contains('D')) { $deleted++ }
        if ($code.Contains('R')) { $renamed++ }
    }
    $changedPaths += [pscustomobject]@{ Status = $code; Path = $path }
}

# Only inspect a fixed allowlist of documentation files. Never scan .env or secrets.
$docPaths = @(
    'HEALTHBRIDGE_REPAIR_PLAN_AND_HANDOFF.md',
    'docs/HEALTHBRIDGE_SESSION_PROTOCOL.md',
    'docs/HEALTHBRIDGE_QA_EVIDENCE_INDEX.md',
    'docs/HEALTHBRIDGE_ENGINEERING_ROADMAP.md',
    'docs/HEALTHBRIDGE_FINDINGS_REGISTER.md',
    'docs/HEALTHBRIDGE_ENDPOINT_INVENTORY.md',
    'docs/HEALTHBRIDGE_MODULE_MAP.md',
    'docs/HEALTHBRIDGE_REPOSITORY_AUDIT.md',
    'docs/HEALTHBRIDGE_PORTFOLIO_REFACTOR_SCOPE.md',
    'docs/archive/HEALTHBRIDGE_DETAILED_HANDOFF_2026-11-09.md'
)
$documents = @()
foreach ($relative in $docPaths) {
    $full = Join-Path $ProjectRoot $relative.Replace('/', '\')
    if (Test-Path -LiteralPath $full -PathType Leaf) {
        $item = Get-Item -LiteralPath $full
        $documentLines = @(Get-Content -LiteralPath $full)
        $documents += [pscustomobject]@{
            Path = $relative
            Exists = $true
            Lines = $documentLines.Count
            Bytes = $item.Length
            LastWrite = $item.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
        }
    } else {
        $documents += [pscustomobject]@{
            Path = $relative; Exists = $false; Lines = 0; Bytes = 0; LastWrite = ''
        }
    }
}

# Extract only the current dashboard table, not older phase tables later in the roadmap.
$roadmapLines = @()
$dashboardLine = ''
$phaseRows = @()
$priorityLines = @()
if (Test-Path -LiteralPath $roadmapPath) {
    $roadmapLines = @(Get-Content -LiteralPath $roadmapPath)
    $insideDashboard = $false
    $insidePriorities = $false

    foreach ($line in $roadmapLines) {
        if (-not $insideDashboard -and $line -match '^## Current project status dashboard') {
            $insideDashboard = $true
            continue
        }
        if ($insideDashboard -and $line -match '^### Latest completed work') {
            $insideDashboard = $false
        }
        if ($insideDashboard) {
            if ($line -match '^\*\*Overall progress estimate:') { $dashboardLine = $line.Trim() }
            if ($line -match '^\|\s*[1-6]\.\s') { $phaseRows += $line.Trim() }
        }

        if ($line.Trim() -eq '### Current next priorities') {
            $insidePriorities = $true
            continue
        }
        if ($insidePriorities -and $line -match '^#{1,6}\s+') {
            $insidePriorities = $false
        }
        if ($insidePriorities -and $priorityLines.Count -lt 18) {
            $priorityLines += $line
        }
    }
}

# The handoff's next action and latest-completed summary are short, current excerpts.
$nextAction = ''
$handoffExcerpt = @()
if (Test-Path -LiteralPath $handoffPath) {
    $handoffLines = @(Get-Content -LiteralPath $handoffPath)
    foreach ($line in $handoffLines) {
        if ($line -match '^\*\*Current next action:\*\*') {
            $nextAction = $line.Trim()
            break
        }
    }

    $insideCompleted = $false
    foreach ($line in $handoffLines) {
        if (-not $insideCompleted -and $line -match '^## Latest completed work') {
            $insideCompleted = $true
            continue
        }
        if ($insideCompleted -and $line -match '^## Remaining work') { break }
        if ($insideCompleted -and $handoffExcerpt.Count -lt 20) {
            $handoffExcerpt += $line
        }
    }
}

# Keyword scan provides a prioritized navigation shortlist, not authoritative issue status.
$findingCount = 0
$openCandidates = @()
if (Test-Path -LiteralPath $findingsPath) {
    $findingLines = @(Get-Content -LiteralPath $findingsPath)
    foreach ($line in $findingLines) {
        if ($line -match '^\|\s*(HB-[A-Z]+-\d+)\s*\|') {
            $findingCount++
            $findingId = $Matches[1]
            $cells = $line -split '\|'
            if ($cells.Count -ge 7) {
                $priority = $cells[2].Trim()
                $area = $cells[3].Trim()
                $statusCell = $cells[$cells.Count - 2].Trim()
                if ($statusCell -match '(?i)\b(open|pending|remain|unresolved|not yet|in progress|partially mitigated|still require|still needs)\b') {
                    $openCandidates += [pscustomobject]@{
                        Id = $findingId
                        Priority = $priority
                        Area = $area
                        StatusHint = if ($statusCell.Length -gt 250) {
                            $statusCell.Substring(0, 247) + '...'
                        } else { $statusCell }
                    }
                }
            }
        }
    }
}
$priorityOrderedCandidates = @()
foreach ($priorityLabel in @('P0*', 'P1', 'P2', 'P3')) {
    $priorityOrderedCandidates += @($openCandidates | Where-Object { $_.Priority -eq $priorityLabel })
}
$openCandidates = @($priorityOrderedCandidates | Select-Object -First 25)

# QA folder names/timestamps only. Do not read or execute QA artifacts here.
$qaFolders = @()
if (Test-Path -LiteralPath $qaRoot -PathType Container) {
    $qaFolders = @(Get-ChildItem -LiteralPath $qaRoot -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -ne '__pycache__' } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First ([Math]::Max(1, $RecentQaFolders)) |
        ForEach-Object {
            [pscustomobject]@{
                Name = $_.Name
                LastWrite = $_.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
            }
        })
}

# Render bounded Markdown for the next chat.
$md = New-Object System.Collections.Generic.List[string]
$md.Add('# HealthBridge - Automatic Session Snapshot')
$md.Add('')
$md.Add(('Generated: {0}' -f $timestamp.ToString('yyyy-MM-dd HH:mm:ss zzz')))
$md.Add(('Project: {0}' -f $ProjectRoot))
$md.Add(('QA root: {0}' -f $qaRoot))
$md.Add('')
$md.Add('Machine-generated, read-only snapshot. Revalidate live source before acting; this report does not run tests.')
$md.Add('')
$md.Add('## Git state')
$md.Add('')
$md.Add(('Branch: {0}' -f $(if ($branch) { $branch } else { 'UNKNOWN' })))
$md.Add(('HEAD: {0}' -f $(if ($head) { $head } else { 'UNKNOWN' })))
$md.Add(('Commit date: {0}' -f $(if ($commitDate) { $commitDate } else { 'UNKNOWN' })))
$md.Add(('Working-tree entries: {0} (untracked {1}, staged {2}, unstaged {3}, deleted {4}, renamed {5})' -f $statusLines.Count, $untracked, $staged, $unstaged, $deleted, $renamed))
$md.Add('')
$md.Add('### Changed paths (first 35; complete list is in latest-changed-paths.txt)')
$md.Add('')
if ($changedPaths.Count -eq 0) {
    $md.Add('Clean working tree.')
} else {
    foreach ($entry in ($changedPaths | Select-Object -First 35)) {
        $md.Add(('[{0}] {1}' -f $entry.Status, $entry.Path))
    }
    if ($changedPaths.Count -gt 35) {
        $md.Add(('... {0} more paths are in latest-changed-paths.txt.' -f ($changedPaths.Count - 35)))
    }
}

$md.Add('')
$md.Add('## Current roadmap')
$md.Add('')
if ($dashboardLine) { $md.Add($dashboardLine) }
foreach ($row in $phaseRows) { $md.Add($row) }
$md.Add('')
$md.Add('### Current next priorities')
$md.Add('')
foreach ($line in $priorityLines) {
    if (-not [string]::IsNullOrWhiteSpace($line)) { $md.Add($line) }
}

$md.Add('')
$md.Add('## Active handoff')
$md.Add('')
if ($nextAction) { $md.Add($nextAction) } else { $md.Add('No explicit next-action line found. Inspect the active handoff.') }
foreach ($line in $handoffExcerpt) { $md.Add($line) }

$md.Add('')
$md.Add('## Findings register search aid')
$md.Add('')
$md.Add(('Finding rows detected: {0}' -f $findingCount))
$md.Add(('Open/review keyword candidates: {0}. Search aid only; verify each full finding row before selecting work.' -f $openCandidates.Count))
foreach ($candidate in $openCandidates) {
    $md.Add(('[{0}] {1} ({2}) - {3}' -f $candidate.Priority, $candidate.Id, $candidate.Area, $candidate.StatusHint))
}

$md.Add('')
$md.Add('## Core documentation health')
$md.Add('')
$md.Add('| Document | Exists | Lines | Bytes | Last modified |')
$md.Add('|---|---:|---:|---:|---|')
foreach ($doc in $documents) {
    $md.Add(('| {0} | {1} | {2} | {3} | {4} |' -f $doc.Path, $doc.Exists, $doc.Lines, $doc.Bytes, $doc.LastWrite))
}

$md.Add('')
$md.Add('## Recently modified QA folders')
$md.Add('')
$md.Add('| Folder | Last modified |')
$md.Add('|---|---|')
foreach ($folder in $qaFolders) {
    $md.Add(('| {0} | {1} |' -f $folder.Name, $folder.LastWrite))
}

$md.Add('')
$md.Add('## New-chat instruction')
$md.Add('')
$md.Add(('Continue HealthBridge from {0}. Read the active handoff and docs/HEALTHBRIDGE_SESSION_PROTOCOL.md, then use this snapshot at {1}. Revalidate live branch, HEAD and working-tree status. Check the canonical findings register and existing QA evidence before repeating work. Preserve all uncommitted changes. Do not inspect .env or touch hosted resources.' -f $ProjectRoot, (Join-Path $OutputRoot 'latest.md')))

$latestMd = Join-Path $OutputRoot 'latest.md'
$latestPaths = Join-Path $OutputRoot 'latest-changed-paths.txt'
$mdText = $md -join [Environment]::NewLine
$pathText = @($changedPaths | ForEach-Object { '{0} {1}' -f $_.Status, $_.Path }) -join [Environment]::NewLine

Set-Content -LiteralPath $latestMd -Value $mdText -Encoding UTF8
Set-Content -LiteralPath $latestPaths -Value $pathText -Encoding UTF8

if ($Archive) {
    $archiveMd = Join-Path $OutputRoot ("HealthBridge_Session_Snapshot_{0}.md" -f $stamp)
    $archivePaths = Join-Path $OutputRoot ("HealthBridge_Session_Snapshot_{0}_changed-paths.txt" -f $stamp)
    Copy-Item -LiteralPath $latestMd -Destination $archiveMd
    Copy-Item -LiteralPath $latestPaths -Destination $archivePaths
}

Write-Output 'HEALTHBRIDGE_SESSION_SNAPSHOT=SUCCESS'
Write-Output ("GENERATED_AT={0}" -f $timestamp.ToString('yyyy-MM-dd HH:mm:ss zzz'))
Write-Output ("BRANCH={0}" -f $branch)
Write-Output ("HEAD={0}" -f $headShort)
Write-Output ("WORKTREE_ENTRIES={0}" -f $statusLines.Count)
Write-Output ("FINDING_ROWS={0}" -f $findingCount)
Write-Output ("RECENT_QA_FOLDERS={0}" -f $qaFolders.Count)
Write-Output ("MARKDOWN={0}" -f $latestMd)
Write-Output ("CHANGED_PATHS={0}" -f $latestPaths)
if ($Archive) { Write-Output ("ARCHIVE_STAMP={0}" -f $stamp) }
