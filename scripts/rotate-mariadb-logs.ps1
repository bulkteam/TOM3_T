# MariaDB Log-Rotation Script
# Rotiert Logs ab 50MB, behält max. 5 Archive
# 
# Verwendung:
#   .\rotate-mariadb-logs.ps1
#   .\rotate-mariadb-logs.ps1 -MaxSizeMB 100 -KeepArchives 10
#   .\rotate-mariadb-logs.ps1 -LogsPath "C:\dev\mariadb-docker\logs"

param(
    [string]$LogsPath = "C:\dev\mariadb-docker\logs",
    [int]$MaxSizeMB = 50,
    [int]$KeepArchives = 5
)

$ErrorActionPreference = "Continue"

Write-Host "=== MariaDB Log-Rotation ===" -ForegroundColor Cyan
Write-Host "Logs-Pfad: $LogsPath" -ForegroundColor Gray
Write-Host "Max. Größe: $MaxSizeMB MB" -ForegroundColor Gray
Write-Host "Archive behalten: $KeepArchives" -ForegroundColor Gray
Write-Host ""

if (-not (Test-Path $LogsPath)) {
    Write-Host "❌ Logs-Pfad nicht gefunden: $LogsPath" -ForegroundColor Red
    exit 1
}

$logFiles = @(
    "mariadb-error.log",
    "mariadb-slow.log"
)

foreach ($logFile in $logFiles) {
    $logPath = Join-Path $LogsPath $logFile
    
    if (-not (Test-Path $logPath)) {
        Write-Host "⏭️  $logFile nicht gefunden, überspringe..." -ForegroundColor Yellow
        continue
    }
    
    $fileInfo = Get-Item $logPath
    $sizeMB = [math]::Round($fileInfo.Length / 1MB, 2)
    
    Write-Host "📄 $logFile: $sizeMB MB" -ForegroundColor Gray
    
    if ($sizeMB -ge $MaxSizeMB) {
        Write-Host "  → Rotiere (≥ $MaxSizeMB MB)..." -ForegroundColor Yellow
        
        # Erstelle Archiv mit Zeitstempel
        $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
        $archiveName = "$logFile.$timestamp"
        $archivePath = Join-Path $LogsPath $archiveName
        
        try {
            # Kopiere aktuelle Log-Datei als Archiv
            Copy-Item $logPath $archivePath -Force
            Write-Host "  ✓ Archiv erstellt: $archiveName" -ForegroundColor Green
            
            # Leere aktuelle Log-Datei
            Clear-Content $logPath -Force
            Write-Host "  ✓ Log-Datei geleert" -ForegroundColor Green
            
            # Lösche alte Archive (behalte nur die neuesten)
            $archives = Get-ChildItem -Path $LogsPath -Filter "$logFile.*" | 
                        Sort-Object LastWriteTime -Descending | 
                        Select-Object -Skip $KeepArchives
            
            foreach ($oldArchive in $archives) {
                Remove-Item $oldArchive.FullName -Force
                Write-Host "  🗑️  Altes Archiv gelöscht: $($oldArchive.Name)" -ForegroundColor Gray
            }
            
        } catch {
            Write-Host "  ❌ Fehler beim Rotieren: $_" -ForegroundColor Red
        }
    } else {
        Write-Host "  ✓ Größe OK" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "✅ Log-Rotation abgeschlossen" -ForegroundColor Green


