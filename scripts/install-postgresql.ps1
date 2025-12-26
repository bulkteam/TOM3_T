# TOM3 - PostgreSQL Installation & Service Setup
# Installiert PostgreSQL und richtet es als Windows-Service ein

param(
    [string]$PostgreSQLVersion = "16",
    [string]$InstallPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion",
    [string]$DataPath = "$InstallPath\data",
    [string]$Password = "tom3_postgres",
    [string]$Port = "5432"
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - PostgreSQL Installation" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Prüfe Administrator-Rechte
if (-NOT ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")) {
    Write-Host "❌ Dieses Script muss als Administrator ausgeführt werden!" -ForegroundColor Red
    Write-Host "Rechtsklick auf PowerShell -> 'Als Administrator ausführen'" -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host "✓ Script läuft als Administrator" -ForegroundColor Green
Write-Host ""

# Prüfe ob PostgreSQL bereits installiert ist
$pgBinPath = "$InstallPath\bin"
if (Test-Path "$pgBinPath\pg_ctl.exe") {
    Write-Host "✓ PostgreSQL $PostgreSQLVersion bereits installiert in: $InstallPath" -ForegroundColor Green
    Write-Host ""
} else {
    Write-Host "⚠ PostgreSQL $PostgreSQLVersion nicht gefunden" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Optionen:" -ForegroundColor Cyan
    Write-Host "1. Manuelle Installation (empfohlen):" -ForegroundColor White
    Write-Host "   → Download: https://www.postgresql.org/download/windows/" -ForegroundColor Gray
    Write-Host "   → Installer ausführen" -ForegroundColor Gray
    Write-Host ""
    Write-Host "2. Silent Installation (erfordert Installer-Datei):" -ForegroundColor White
    Write-Host "   → Lade PostgreSQL-Installer herunter" -ForegroundColor Gray
    Write-Host "   → Führe aus: .\install-postgresql.ps1 -InstallerPath 'C:\path\to\postgresql-installer.exe'" -ForegroundColor Gray
    Write-Host ""
    
    $continue = Read-Host "PostgreSQL bereits installiert? (j/n)"
    if ($continue -ne "j") {
        Write-Host "Bitte installiere PostgreSQL manuell und führe dieses Script erneut aus." -ForegroundColor Yellow
        exit 1
    }
}

# Prüfe ob Datenverzeichnis existiert
if (-not (Test-Path $DataPath)) {
    Write-Host "⚠ Datenverzeichnis nicht gefunden: $DataPath" -ForegroundColor Yellow
    Write-Host "  → Initialisiere Datenverzeichnis..." -ForegroundColor Yellow
    
    try {
        New-Item -ItemType Directory -Path $DataPath -Force | Out-Null
        & "$pgBinPath\initdb.exe" -D "$DataPath" -U postgres -A trust -E UTF8 --locale=German_Germany.1252
        Write-Host "✓ Datenverzeichnis initialisiert" -ForegroundColor Green
    } catch {
        Write-Host "❌ Fehler beim Initialisieren: $_" -ForegroundColor Red
        exit 1
    }
}

# Prüfe ob Service bereits registriert ist
$serviceName = "postgresql-x64-$PostgreSQLVersion"
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue

if ($service) {
    Write-Host "✓ Service bereits registriert: $serviceName" -ForegroundColor Green
    
    if ($service.Status -eq 'Running') {
        Write-Host "✓ Service läuft bereits" -ForegroundColor Green
    } else {
        Write-Host "→ Starte Service..." -ForegroundColor Yellow
        Start-Service -Name $serviceName
        Start-Sleep -Seconds 3
        Write-Host "✓ Service gestartet" -ForegroundColor Green
    }
} else {
    Write-Host "→ Registriere PostgreSQL als Windows-Service..." -ForegroundColor Yellow
    
    try {
        # Service registrieren
        & "$pgBinPath\pg_ctl.exe" register -N $serviceName -D "$DataPath" -w
        
        # Service starten
        Start-Service -Name $serviceName
        Start-Sleep -Seconds 3
        
        Write-Host "✓ Service registriert und gestartet: $serviceName" -ForegroundColor Green
    } catch {
        Write-Host "❌ Fehler beim Registrieren des Services: $_" -ForegroundColor Red
        Write-Host "Versuche manuelle Registrierung..." -ForegroundColor Yellow
        
        # Alternative: Manuelle Registrierung
        $serviceArgs = @(
            "register",
            "-N", $serviceName,
            "-D", "`"$DataPath`"",
            "-w"
        )
        & "$pgBinPath\pg_ctl.exe" $serviceArgs
        
        if ($LASTEXITCODE -eq 0) {
            Start-Service -Name $serviceName
            Write-Host "✓ Service manuell registriert und gestartet" -ForegroundColor Green
        } else {
            Write-Host "❌ Service-Registrierung fehlgeschlagen" -ForegroundColor Red
            exit 1
        }
    }
}

# Prüfe Service-Status
Start-Sleep -Seconds 2
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($service -and $service.Status -eq 'Running') {
    Write-Host ""
    Write-Host "✓ PostgreSQL-Service läuft erfolgreich!" -ForegroundColor Green
} else {
    Write-Host ""
    Write-Host "⚠ Service-Status unklar. Prüfe manuell:" -ForegroundColor Yellow
    Write-Host "   Get-Service -Name '$serviceName'" -ForegroundColor Gray
}

# Setze automatischen Start
Write-Host ""
Write-Host "→ Setze automatischen Start..." -ForegroundColor Yellow
Set-Service -Name $serviceName -StartupType Automatic
Write-Host "✓ Automatischer Start aktiviert" -ForegroundColor Green

# Füge PostgreSQL zu PATH hinzu (falls nicht vorhanden)
Write-Host ""
Write-Host "→ Prüfe PATH..." -ForegroundColor Yellow
$currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
if ($currentPath -notlike "*$pgBinPath*") {
    Write-Host "→ Füge PostgreSQL zu PATH hinzu..." -ForegroundColor Yellow
    [Environment]::SetEnvironmentVariable("Path", "$currentPath;$pgBinPath", "Machine")
    Write-Host "✓ PATH aktualisiert (neue Shell erforderlich)" -ForegroundColor Green
} else {
    Write-Host "✓ PostgreSQL bereits im PATH" -ForegroundColor Green
}

# Teste Verbindung
Write-Host ""
Write-Host "→ Teste PostgreSQL-Verbindung..." -ForegroundColor Yellow
$env:PGPASSWORD = $Password
try {
    $result = & "$pgBinPath\psql.exe" -U postgres -h localhost -p $Port -c "SELECT version();" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ PostgreSQL-Verbindung erfolgreich!" -ForegroundColor Green
        Write-Host "  $($result[0])" -ForegroundColor Gray
    } else {
        Write-Host "⚠ Verbindungstest fehlgeschlagen" -ForegroundColor Yellow
        Write-Host "  Prüfe Passwort und Service-Status" -ForegroundColor Yellow
    }
} catch {
    Write-Host "⚠ Verbindungstest fehlgeschlagen: $_" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Nächste Schritte" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Erstelle Datenbank:" -ForegroundColor Yellow
Write-Host "   psql -U postgres" -ForegroundColor White
Write-Host "   CREATE DATABASE tom3;" -ForegroundColor Gray
Write-Host "   CREATE USER tom3_user WITH PASSWORD 'tom3_password';" -ForegroundColor Gray
Write-Host "   GRANT ALL PRIVILEGES ON DATABASE tom3 TO tom3_user;" -ForegroundColor Gray
Write-Host ""
Write-Host "2. Führe TOM3-Setup aus:" -ForegroundColor Yellow
Write-Host "   php scripts\setup-database.php" -ForegroundColor White
Write-Host ""


