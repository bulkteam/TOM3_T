# TOM3 - Dateigrößen-Check
# Prüft alle Module auf Überschreitung der Größenlimits

param(
    [switch]$WarnOnly = $false
)

$ErrorActionPreference = "Continue"

# Limits definieren
$limits = @{
    'public/js/modules/*.js' = @{
        Max = 400
        Warn = 300
        Type = "JavaScript Module"
    }
    'src/TOM/Service/*.php' = @{
        Max = 500
        Warn = 400
        Type = "PHP Service"
    }
    'public/api/*.php' = @{
        Max = 200
        Warn = 150
        Type = "PHP API Endpoint"
    }
    'src/TOM/Infrastructure/**/*.php' = @{
        Max = 300
        Warn = 250
        Type = "PHP Infrastructure"
    }
}

$warnings = @()
$errors = @()

Write-Host "`n=== Dateigrößen-Check ===" -ForegroundColor Cyan
Write-Host ""

foreach ($pattern in $limits.Keys) {
    $config = $limits[$pattern]
    $maxLines = $config.Max
    $warnLines = $config.Warn
    $type = $config.Type
    
    Write-Host "Prüfe $type..." -ForegroundColor Yellow
    
    $files = Get-ChildItem -Path $pattern -ErrorAction SilentlyContinue
    
    if (-not $files) {
        Write-Host "  Keine Dateien gefunden für: $pattern" -ForegroundColor Gray
        continue
    }
    
    foreach ($file in $files) {
        $lines = (Get-Content $file.FullName -ErrorAction SilentlyContinue | Measure-Object -Line).Lines
        
        if ($lines -gt $maxLines) {
            $errorObj = [PSCustomObject]@{
                File = $file.Name
                Path = $file.FullName.Replace((Get-Location).Path + '\', '')
                Lines = $lines
                Limit = $maxLines
                Type = $type
            }
            $errors += $errorObj
            Write-Host "  ❌ $($file.Name): $lines Zeilen (Limit: $maxLines)" -ForegroundColor Red
        }
        elseif ($lines -gt $warnLines) {
            $warning = [PSCustomObject]@{
                File = $file.Name
                Path = $file.FullName.Replace((Get-Location).Path + '\', '')
                Lines = $lines
                Warn = $warnLines
                Type = $type
            }
            $warnings += $warning
            Write-Host "  ⚠️  $($file.Name): $lines Zeilen (Warnung bei: $warnLines)" -ForegroundColor Yellow
        }
        else {
            Write-Host "  ✅ $($file.Name): $lines Zeilen" -ForegroundColor Green
        }
    }
}

Write-Host ""

# Zusammenfassung
if ($errors.Count -gt 0) {
    Write-Host "=== FEHLER: Dateien überschreiten Limit ===" -ForegroundColor Red
    foreach ($err in $errors) {
        Write-Host "  ❌ $($err.Path): $($err.Lines) Zeilen (Limit: $($err.Limit))" -ForegroundColor Red
    }
    Write-Host ""
}

if ($warnings.Count -gt 0) {
    Write-Host "=== WARNUNGEN: Dateien nähern sich Limit ===" -ForegroundColor Yellow
    foreach ($warning in $warnings) {
        Write-Host "  ⚠️  $($warning.Path): $($warning.Lines) Zeilen (Warnung bei: $($warning.Warn))" -ForegroundColor Yellow
    }
    Write-Host ""
}

# Exit-Code
if ($errors.Count -gt 0 -and -not $WarnOnly) {
    Write-Host "❌ Check fehlgeschlagen: $($errors.Count) Datei(en) überschreiten das Limit!" -ForegroundColor Red
    exit 1
}
elseif ($warnings.Count -gt 0) {
    Write-Host "⚠️  Check mit Warnungen: $($warnings.Count) Datei(en) nähern sich dem Limit." -ForegroundColor Yellow
    exit 0
}
else {
    Write-Host "✅ Alle Dateien sind innerhalb der Limits!" -ForegroundColor Green
    exit 0
}

