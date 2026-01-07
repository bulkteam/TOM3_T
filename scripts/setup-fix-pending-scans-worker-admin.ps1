# TOM3 - Fix Pending Scans Worker Setup (Admin-Version)
# Dieses Script muss als Administrator ausgeführt werden
# 
# Verwendung:
#   Rechtsklick auf PowerShell -> "Als Administrator ausführen"
#   Dann: .\scripts\setup-fix-pending-scans-worker-admin.ps1

$ErrorActionPreference = "Stop"

# Prüfe, ob Script als Administrator ausgeführt wird
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "FEHLER: Dieses Script muss als Administrator ausgeführt werden!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Bitte:" -ForegroundColor Yellow
    Write-Host "  1. Rechtsklick auf PowerShell" -ForegroundColor Yellow
    Write-Host "  2. 'Als Administrator ausführen' wählen" -ForegroundColor Yellow
    Write-Host "  3. Dann dieses Script erneut ausführen" -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

# Führe das normale Setup-Script aus
$scriptPath = Join-Path $PSScriptRoot "setup-fix-pending-scans-worker.ps1"
& $scriptPath

