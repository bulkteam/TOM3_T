# MySQL Fix: Aria Recovery und Plugin Table
# Behebt die spezifischen Fehler: Aria recovery failed und mysql.plugin table

$ErrorActionPreference = "Continue"
$mysqlDataDir = "C:\xampp\mysql\data"
$mysqlBinDir = "C:\xampp\mysql\bin"

Write-Host "=== MySQL Aria & Plugin Table Reparatur ===" -ForegroundColor Cyan
Write-Host ""

# 1. Stoppe MySQL falls es läuft
$mysqlRunning = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if ($mysqlRunning) {
    Write-Host "Stoppe MySQL..." -ForegroundColor Yellow
    Stop-Process -Name mysqld -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 3
    Write-Host "  ✓ MySQL gestoppt" -ForegroundColor Green
}

# 2. Lösche alle aria_log Dateien
Write-Host ""
Write-Host "Lösche aria_log Dateien..." -ForegroundColor Yellow
$ariaLogFiles = Get-ChildItem -Path $mysqlDataDir -Filter "aria_log.*" -ErrorAction SilentlyContinue
if ($ariaLogFiles) {
    $count = ($ariaLogFiles | Measure-Object).Count
    $ariaLogFiles | Remove-Item -Force -ErrorAction SilentlyContinue
    Write-Host "  ✓ $count aria_log Dateien gelöscht" -ForegroundColor Green
} else {
    Write-Host "  ✓ Keine aria_log Dateien gefunden" -ForegroundColor Green
}

# 3. Lösche aria_log_control
Write-Host ""
Write-Host "Lösche aria_log_control..." -ForegroundColor Yellow
$ariaLogControl = Join-Path $mysqlDataDir "aria_log_control"
if (Test-Path $ariaLogControl) {
    Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
    Write-Host "  ✓ aria_log_control gelöscht" -ForegroundColor Green
} else {
    Write-Host "  ✓ aria_log_control nicht vorhanden" -ForegroundColor Green
}

# 4. Repariere mysql.plugin Tabelle (falls vorhanden)
Write-Host ""
Write-Host "Prüfe mysql.plugin Tabelle..." -ForegroundColor Yellow
$pluginTableFile = Join-Path $mysqlDataDir "mysql\plugin.MAD"
$pluginTableFile2 = Join-Path $mysqlDataDir "mysql\plugin.MAI"
$pluginTableFile3 = Join-Path $mysqlDataDir "mysql\plugin.frm"

if (Test-Path $pluginTableFile -or Test-Path $pluginTableFile2 -or Test-Path $pluginTableFile3) {
    Write-Host "  mysql.plugin Tabelle gefunden" -ForegroundColor Yellow
    
    # Versuche mit aria_chk zu reparieren
    $ariaChkPath = Join-Path $mysqlBinDir "aria_chk.exe"
    if (Test-Path $ariaChkPath) {
        Write-Host "  Versuche mysql.plugin mit aria_chk zu reparieren..." -ForegroundColor Yellow
        $pluginTableBase = Join-Path $mysqlDataDir "mysql\plugin"
        
        try {
            $result = & $ariaChkPath -r "$pluginTableBase" 2>&1
            if ($LASTEXITCODE -eq 0) {
                Write-Host "    ✓ mysql.plugin repariert" -ForegroundColor Green
            } else {
                Write-Host "    ⚠ aria_chk konnte mysql.plugin nicht reparieren" -ForegroundColor Yellow
                Write-Host "    Versuche alternative Methode..." -ForegroundColor Yellow
                
                # Alternative: Lösche mysql.plugin Tabelle (wird beim nächsten Start neu erstellt)
                if (Test-Path $pluginTableFile) {
                    Remove-Item $pluginTableFile -Force -ErrorAction SilentlyContinue
                }
                if (Test-Path $pluginTableFile2) {
                    Remove-Item $pluginTableFile2 -Force -ErrorAction SilentlyContinue
                }
                if (Test-Path $pluginTableFile3) {
                    Remove-Item $pluginTableFile3 -Force -ErrorAction SilentlyContinue
                }
                Write-Host "    ✓ mysql.plugin gelöscht (wird beim Start neu erstellt)" -ForegroundColor Green
            }
        } catch {
            Write-Host "    ⚠ Fehler bei aria_chk: $_" -ForegroundColor Yellow
            Write-Host "    Lösche mysql.plugin Tabelle..." -ForegroundColor Yellow
            if (Test-Path $pluginTableFile) {
                Remove-Item $pluginTableFile -Force -ErrorAction SilentlyContinue
            }
            if (Test-Path $pluginTableFile2) {
                Remove-Item $pluginTableFile2 -Force -ErrorAction SilentlyContinue
            }
            if (Test-Path $pluginTableFile3) {
                Remove-Item $pluginTableFile3 -Force -ErrorAction SilentlyContinue
            }
            Write-Host "    ✓ mysql.plugin gelöscht (wird beim Start neu erstellt)" -ForegroundColor Green
        }
    } else {
        Write-Host "  ⚠ aria_chk.exe nicht gefunden" -ForegroundColor Yellow
        Write-Host "  Lösche mysql.plugin Tabelle..." -ForegroundColor Yellow
        if (Test-Path $pluginTableFile) {
            Remove-Item $pluginTableFile -Force -ErrorAction SilentlyContinue
        }
        if (Test-Path $pluginTableFile2) {
            Remove-Item $pluginTableFile2 -Force -ErrorAction SilentlyContinue
        }
        if (Test-Path $pluginTableFile3) {
            Remove-Item $pluginTableFile3 -Force -ErrorAction SilentlyContinue
        }
        Write-Host "    ✓ mysql.plugin gelöscht (wird beim Start neu erstellt)" -ForegroundColor Green
    }
} else {
    Write-Host "  ✓ mysql.plugin Tabelle nicht gefunden (wird beim Start erstellt)" -ForegroundColor Green
}

# 5. Prüfe auf weitere Aria-Tabellen und repariere sie
Write-Host ""
Write-Host "Prüfe auf weitere Aria-Tabellen..." -ForegroundColor Yellow
$ariaChkPath = Join-Path $mysqlBinDir "aria_chk.exe"
if (Test-Path $ariaChkPath) {
    $ariaTables = Get-ChildItem -Path $mysqlDataDir -Recurse -Filter "*.MAD" -ErrorAction SilentlyContinue
    if ($ariaTables) {
        Write-Host "  Gefunden: $($ariaTables.Count) Aria-Tabellen" -ForegroundColor Yellow
        $repaired = 0
        $failed = 0
        
        foreach ($table in $ariaTables) {
            $tableBase = $table.FullName -replace '\.MAD$', ''
            try {
                $result = & $ariaChkPath -r "$tableBase" 2>&1 | Out-Null
                if ($LASTEXITCODE -eq 0) {
                    $repaired++
                } else {
                    $failed++
                }
            } catch {
                $failed++
            }
        }
        
        if ($repaired -gt 0) {
            Write-Host "    ✓ $repaired Tabellen repariert" -ForegroundColor Green
        }
        if ($failed -gt 0) {
            Write-Host "    ⚠ $failed Tabellen konnten nicht repariert werden" -ForegroundColor Yellow
        }
    } else {
        Write-Host "  ✓ Keine Aria-Tabellen gefunden" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "=== Reparatur abgeschlossen ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Nächste Schritte:" -ForegroundColor Yellow
Write-Host "  1. Starte MySQL über XAMPP Control Panel" -ForegroundColor White
Write-Host "  2. Oder führe aus: scripts\ensure-mysql-running.bat" -ForegroundColor White
Write-Host ""
Write-Host "MySQL sollte jetzt starten können!" -ForegroundColor Green


