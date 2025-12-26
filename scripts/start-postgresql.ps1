# TOM3 - PostgreSQL Service Start
# Startet PostgreSQL-Service (ohne Admin-Rechte, falls Service bereits existiert)

param(
    [string]$PostgreSQLVersion = "17"
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - PostgreSQL Service Start" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Suche PostgreSQL-Service
$services = Get-Service -Name "*postgres*" -ErrorAction SilentlyContinue

if ($services) {
    foreach ($service in $services) {
        Write-Host "Gefundener Service: $($service.Name) - Status: $($service.Status)" -ForegroundColor Yellow
        
        if ($service.Status -eq 'Running') {
            Write-Host "✓ Service läuft bereits: $($service.Name)" -ForegroundColor Green
        } else {
            Write-Host "→ Starte Service: $($service.Name)..." -ForegroundColor Yellow
            try {
                Start-Service -Name $service.Name
                Start-Sleep -Seconds 3
                
                $service = Get-Service -Name $service.Name
                if ($service.Status -eq 'Running') {
                    Write-Host "✓ Service erfolgreich gestartet!" -ForegroundColor Green
                } else {
                    Write-Host "⚠ Service-Status: $($service.Status)" -ForegroundColor Yellow
                }
            } catch {
                Write-Host "❌ Fehler beim Starten: $_" -ForegroundColor Red
                Write-Host "  → Versuche manuellen Start..." -ForegroundColor Yellow
                
                # Versuche manuellen Start über pg_ctl
                $pgBinPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion\bin"
                if (Test-Path "$pgBinPath\pg_ctl.exe") {
                    $dataPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion\data"
                    if (Test-Path $dataPath) {
                        Write-Host "  → Starte PostgreSQL manuell..." -ForegroundColor Yellow
                        & "$pgBinPath\pg_ctl.exe" start -D "$dataPath" -w
                        
                        if ($LASTEXITCODE -eq 0) {
                            Write-Host "✓ PostgreSQL manuell gestartet" -ForegroundColor Green
                        } else {
                            Write-Host "❌ Manueller Start fehlgeschlagen" -ForegroundColor Red
                            Write-Host "  → Bitte als Administrator ausführen: .\scripts\install-postgresql.ps1" -ForegroundColor Yellow
                        }
                    }
                }
            }
        }
    }
} else {
    Write-Host "⚠ Kein PostgreSQL-Service gefunden" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Optionen:" -ForegroundColor Cyan
    Write-Host "1. Service registrieren (erfordert Admin):" -ForegroundColor White
    Write-Host "   .\scripts\install-postgresql.ps1" -ForegroundColor Gray
    Write-Host ""
    Write-Host "2. Manueller Start:" -ForegroundColor White
    $pgBinPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion\bin"
    if (Test-Path "$pgBinPath\pg_ctl.exe") {
        $dataPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion\data"
        Write-Host "   cd `"$pgBinPath`"" -ForegroundColor Gray
        Write-Host "   .\pg_ctl.exe start -D `"$dataPath`"" -ForegroundColor Gray
    } else {
        Write-Host "   PostgreSQL nicht gefunden in: $pgBinPath" -ForegroundColor Gray
    }
}

# Teste Verbindung
Write-Host ""
Write-Host "→ Teste PostgreSQL-Verbindung..." -ForegroundColor Yellow

$pgBinPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion\bin"
if (Test-Path "$pgBinPath\psql.exe") {
    try {
        $result = & "$pgBinPath\psql.exe" -U postgres -h localhost -c "SELECT version();" 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Host "✓ PostgreSQL-Verbindung erfolgreich!" -ForegroundColor Green
            $result | Select-Object -First 1 | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
        } else {
            Write-Host "⚠ Verbindung fehlgeschlagen" -ForegroundColor Yellow
            Write-Host "  Prüfe Service-Status und Passwort" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "⚠ Verbindungstest fehlgeschlagen: $_" -ForegroundColor Yellow
    }
} else {
    Write-Host "⚠ psql.exe nicht gefunden" -ForegroundColor Yellow
}

Write-Host ""


