# TOM3 - Neo4j Installation & Service Setup
# Installiert Neo4j Community Edition und richtet es als Service ein

param(
    [string]$Neo4jVersion = "5.15.0",
    [string]$InstallPath = "C:\neo4j",
    [string]$DataPath = "$InstallPath\data",
    [string]$LogsPath = "$InstallPath\logs",
    [string]$Password = "tom3_neo4j",
    [string]$Port = "7687",
    [string]$HttpPort = "7474"
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - Neo4j Installation" -ForegroundColor Cyan
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

# Prüfe ob Neo4j bereits installiert ist
if (Test-Path "$InstallPath\bin\neo4j.bat") {
    Write-Host "✓ Neo4j bereits installiert in: $InstallPath" -ForegroundColor Green
    Write-Host ""
} else {
    Write-Host "⚠ Neo4j nicht gefunden in: $InstallPath" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Optionen:" -ForegroundColor Cyan
    Write-Host "1. Neo4j Desktop (empfohlen für Entwicklung):" -ForegroundColor White
    Write-Host "   → Download: https://neo4j.com/download/" -ForegroundColor Gray
    Write-Host "   → Installer ausführen" -ForegroundColor Gray
    Write-Host ""
    Write-Host "2. Community Edition ZIP (für Service):" -ForegroundColor White
    Write-Host "   → Download: https://neo4j.com/download-center/#community" -ForegroundColor Gray
    Write-Host "   → Entpacke nach: $InstallPath" -ForegroundColor Gray
    Write-Host ""
    
    $continue = Read-Host "Neo4j bereits installiert oder ZIP entpackt? (j/n)"
    if ($continue -ne "j") {
        Write-Host ""
        Write-Host "Bitte installiere Neo4j:" -ForegroundColor Yellow
        Write-Host "1. Lade Neo4j Community Edition herunter" -ForegroundColor White
        Write-Host "2. Entpacke nach: $InstallPath" -ForegroundColor White
        Write-Host "3. Führe dieses Script erneut aus" -ForegroundColor White
        Write-Host ""
        Write-Host "Download: https://neo4j.com/download-center/#community" -ForegroundColor Cyan
        exit 1
    }
}

# Erstelle Verzeichnisse
Write-Host "→ Erstelle Verzeichnisse..." -ForegroundColor Yellow
New-Item -ItemType Directory -Path $DataPath -Force | Out-Null
New-Item -ItemType Directory -Path $LogsPath -Force | Out-Null
Write-Host "✓ Verzeichnisse erstellt" -ForegroundColor Green

# Konfiguriere Neo4j
Write-Host ""
Write-Host "→ Konfiguriere Neo4j..." -ForegroundColor Yellow

$confPath = "$InstallPath\conf\neo4j.conf"
if (Test-Path $confPath) {
    # Backup erstellen
    Copy-Item $confPath "$confPath.backup" -Force
    
    # Konfiguration anpassen
    $confContent = Get-Content $confPath -Raw
    
    # Datenverzeichnis
    $confContent = $confContent -replace "#dbms\.directories\.data=.*", "dbms.directories.data=$DataPath"
    $confContent = $confContent -replace "dbms\.directories\.data=.*", "dbms.directories.data=$DataPath"
    
    # Log-Verzeichnis
    $confContent = $confContent -replace "#dbms\.directories\.logs=.*", "dbms.directories.logs=$LogsPath"
    $confContent = $confContent -replace "dbms\.directories\.logs=.*", "dbms.directories.logs=$LogsPath"
    
    # Ports
    $confContent = $confContent -replace "#dbms\.connector\.bolt\.listen_address=:7687", "dbms.connector.bolt.listen_address=:$Port"
    $confContent = $confContent -replace "dbms\.connector\.bolt\.listen_address=.*", "dbms.connector.bolt.listen_address=:$Port"
    
    $confContent = $confContent -replace "#dbms\.connector\.http\.listen_address=:7474", "dbms.connector.http.listen_address=:$HttpPort"
    $confContent = $confContent -replace "dbms\.connector\.http\.listen_address=.*", "dbms.connector.http.listen_address=:$HttpPort"
    
    # Speichere Konfiguration
    Set-Content -Path $confPath -Value $confContent -NoNewline
    Write-Host "✓ Konfiguration angepasst" -ForegroundColor Green
} else {
    Write-Host "⚠ Konfigurationsdatei nicht gefunden: $confPath" -ForegroundColor Yellow
    Write-Host "  Erstelle Standard-Konfiguration..." -ForegroundColor Yellow
    
    $confContent = @"
# TOM3 Neo4j Configuration
dbms.directories.data=$DataPath
dbms.directories.logs=$LogsPath
dbms.connector.bolt.listen_address=:$Port
dbms.connector.http.listen_address=:$HttpPort
dbms.security.auth_enabled=true
"@
    
    New-Item -ItemType Directory -Path "$InstallPath\conf" -Force | Out-Null
    Set-Content -Path $confPath -Value $confContent
    Write-Host "✓ Standard-Konfiguration erstellt" -ForegroundColor Green
}

# Setze Initial-Passwort (falls neu)
Write-Host ""
Write-Host "→ Setze Initial-Passwort..." -ForegroundColor Yellow
$authPath = "$DataPath\dbms\auth"
if (-not (Test-Path "$authPath\neo4j")) {
    Write-Host "  Initial-Passwort wird beim ersten Start gesetzt" -ForegroundColor Gray
    Write-Host "  Standard: neo4j / neo4j (muss beim ersten Login geändert werden)" -ForegroundColor Gray
}

# Prüfe ob Service bereits registriert ist
$serviceName = "Neo4j-TOM3"
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue

if ($service) {
    Write-Host ""
    Write-Host "✓ Service bereits registriert: $serviceName" -ForegroundColor Green
    
    if ($service.Status -eq 'Running') {
        Write-Host "✓ Service läuft bereits" -ForegroundColor Green
    } else {
        Write-Host "→ Starte Service..." -ForegroundColor Yellow
        Start-Service -Name $serviceName
        Start-Sleep -Seconds 5
        Write-Host "✓ Service gestartet" -ForegroundColor Green
    }
} else {
    Write-Host ""
    Write-Host "→ Registriere Neo4j als Windows-Service..." -ForegroundColor Yellow
    
    # Prüfe ob neo4j.ps1 vorhanden (für Service-Installation)
    $neo4jScript = "$InstallPath\bin\neo4j.ps1"
    if (Test-Path $neo4jScript) {
        try {
            # Service installieren
            & $neo4jScript install-service -Name $serviceName -DisplayName "Neo4j TOM3" -Description "Neo4j Graph Database for TOM3"
            
            if ($LASTEXITCODE -eq 0) {
                Start-Service -Name $serviceName
                Start-Sleep -Seconds 5
                Write-Host "✓ Service registriert und gestartet: $serviceName" -ForegroundColor Green
            } else {
                Write-Host "⚠ Service-Installation fehlgeschlagen, versuche manuellen Start..." -ForegroundColor Yellow
            }
        } catch {
            Write-Host "⚠ Service-Installation fehlgeschlagen: $_" -ForegroundColor Yellow
        }
    } else {
        Write-Host "⚠ neo4j.ps1 nicht gefunden, Service muss manuell eingerichtet werden" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "Alternative: Starte Neo4j manuell:" -ForegroundColor Cyan
        Write-Host "  cd $InstallPath\bin" -ForegroundColor White
        Write-Host "  .\neo4j.bat console" -ForegroundColor White
        Write-Host ""
    }
}

# Prüfe Service-Status
if ($service) {
    Start-Sleep -Seconds 2
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if ($service -and $service.Status -eq 'Running') {
        Write-Host ""
        Write-Host "✓ Neo4j-Service läuft erfolgreich!" -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "⚠ Service-Status unklar" -ForegroundColor Yellow
    }
}

# Setze automatischen Start
if ($service) {
    Write-Host ""
    Write-Host "→ Setze automatischen Start..." -ForegroundColor Yellow
    Set-Service -Name $serviceName -StartupType Automatic
    Write-Host "✓ Automatischer Start aktiviert" -ForegroundColor Green
}

# Teste Verbindung
Write-Host ""
Write-Host "→ Teste Neo4j-Verbindung..." -ForegroundColor Yellow
Start-Sleep -Seconds 3

try {
    $response = Invoke-WebRequest -Uri "http://localhost:$HttpPort" -TimeoutSec 5 -ErrorAction Stop
    Write-Host "✓ Neo4j HTTP-Schnittstelle erreichbar (Port $HttpPort)" -ForegroundColor Green
    Write-Host "  → Öffne Browser: http://localhost:$HttpPort" -ForegroundColor Gray
} catch {
    Write-Host "⚠ HTTP-Verbindung fehlgeschlagen (Service startet möglicherweise noch)" -ForegroundColor Yellow
    Write-Host "  → Prüfe manuell: http://localhost:$HttpPort" -ForegroundColor Gray
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Nächste Schritte" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Öffne Neo4j Browser:" -ForegroundColor Yellow
Write-Host "   http://localhost:$HttpPort" -ForegroundColor White
Write-Host ""
Write-Host "2. Login (erste Anmeldung):" -ForegroundColor Yellow
Write-Host "   Username: neo4j" -ForegroundColor White
Write-Host "   Password: neo4j (muss geändert werden)" -ForegroundColor White
Write-Host ""
Write-Host "3. Erstelle Constraints:" -ForegroundColor Yellow
Write-Host "   Führe database\neo4j\constraints.cypher aus" -ForegroundColor White
Write-Host ""
Write-Host "4. Erstelle Indexes:" -ForegroundColor Yellow
Write-Host "   Führe database\neo4j\indexes.cypher aus" -ForegroundColor White
Write-Host ""


