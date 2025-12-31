# MySQL Diagnose und Reparatur Script
# Analysiert MySQL-Logs und behebt häufige Startprobleme

$ErrorActionPreference = "Continue"
$mysqlDataDir = "C:\xampp\mysql\data"
$mysqlBinDir = "C:\xampp\mysql\bin"
$mysqlConfigFile = "C:\xampp\mysql\bin\my.ini"
$errorLog = Join-Path $mysqlDataDir "mysql_error.log"

Write-Host "=== MySQL Diagnose und Reparatur ===" -ForegroundColor Cyan
Write-Host ""

# 1. Prüfe ob MySQL läuft
$mysqlRunning = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if ($mysqlRunning) {
    Write-Host "MySQL läuft bereits. Stoppe MySQL für Diagnose..." -ForegroundColor Yellow
    Stop-Process -Name mysqld -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 3
}

# 2. Analysiere Error Log
$issues = @()
$fixes = @()

if (Test-Path $errorLog) {
    Write-Host "Analysiere MySQL Error Log..." -ForegroundColor Cyan
    $logContent = Get-Content $errorLog -Tail 100 -ErrorAction SilentlyContinue
    
    # Prüfe auf häufige Fehler
    $ariaErrors = $logContent | Select-String -Pattern "Aria recovery failed|aria_chk|aria_log_control" -CaseSensitive:$false
    $portErrors = $logContent | Select-String -Pattern "port.*already in use|bind.*address|Can't connect" -CaseSensitive:$false
    $configErrors = $logContent | Select-String -Pattern "unknown variable|unknown option|bad variable" -CaseSensitive:$false
    $permissionErrors = $logContent | Select-String -Pattern "permission denied|access denied|cannot open" -CaseSensitive:$false
    $corruptErrors = $logContent | Select-String -Pattern "corrupt|damaged|inconsistent" -CaseSensitive:$false
    
    if ($ariaErrors) {
        $issues += "Aria-Log-Fehler erkannt"
        $fixes += "Lösche aria_log Dateien und aria_log_control"
    }
    
    if ($portErrors) {
        $issues += "Port-Konflikt erkannt"
        $fixes += "Prüfe ob Port 3306 blockiert ist"
    }
    
    if ($configErrors) {
        $issues += "Konfigurationsfehler erkannt"
        $fixes += "Prüfe my.ini auf Fehler"
    }
    
    if ($permissionErrors) {
        $issues += "Berechtigungsfehler erkannt"
        $fixes += "Prüfe Dateiberechtigungen"
    }
    
    if ($corruptErrors) {
        $issues += "Korrupte Dateien erkannt"
        $fixes += "Repariere Datenbank-Dateien"
    }
    
    # Zeige letzte Fehler
    Write-Host ""
    Write-Host "Letzte Fehler aus Log:" -ForegroundColor Yellow
    $logContent | Select-Object -Last 10 | ForEach-Object {
        Write-Host "  $_" -ForegroundColor Gray
    }
} else {
    Write-Host "MySQL Error Log nicht gefunden: $errorLog" -ForegroundColor Yellow
}

# 3. Prüfe Port 3306
Write-Host ""
Write-Host "Prüfe Port 3306..." -ForegroundColor Cyan
$portInUse = $false
try {
    $listener = Get-NetTCPConnection -LocalPort 3306 -ErrorAction SilentlyContinue
    if ($listener) {
        $portInUse = $true
        $process = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue
        Write-Host "  ⚠ Port 3306 wird verwendet von: $($process.Name) (PID: $($process.Id))" -ForegroundColor Red
        $issues += "Port 3306 blockiert"
        $fixes += "Beende Prozess oder ändere MySQL-Port"
    } else {
        Write-Host "  ✓ Port 3306 ist frei" -ForegroundColor Green
    }
} catch {
    Write-Host "  ✓ Port 3306 ist frei" -ForegroundColor Green
}

# 4. Prüfe Aria-Log-Dateien
Write-Host ""
Write-Host "Prüfe Aria-Log-Dateien..." -ForegroundColor Cyan
$ariaLogFiles = Get-ChildItem -Path $mysqlDataDir -Filter "aria_log.*" -ErrorAction SilentlyContinue
$ariaLogControl = Join-Path $mysqlDataDir "aria_log_control"

if ($ariaLogFiles) {
    Write-Host "  Gefunden: $($ariaLogFiles.Count) aria_log Dateien" -ForegroundColor Yellow
    $issues += "Aria-Log-Dateien vorhanden (können Probleme verursachen)"
}

if (Test-Path $ariaLogControl) {
    try {
        $content = Get-Content $ariaLogControl -ErrorAction Stop
        if ($null -eq $content -or $content.Length -eq 0) {
            Write-Host "  ⚠ aria_log_control ist leer/korrupt" -ForegroundColor Red
            $issues += "aria_log_control korrupt"
            $fixes += "Lösche korrupte aria_log_control"
        } else {
            Write-Host "  ✓ aria_log_control ist OK" -ForegroundColor Green
        }
    } catch {
        Write-Host "  ⚠ aria_log_control kann nicht gelesen werden" -ForegroundColor Red
        $issues += "aria_log_control nicht lesbar"
        $fixes += "Lösche aria_log_control"
    }
} else {
    Write-Host "  ✓ aria_log_control nicht vorhanden (OK)" -ForegroundColor Green
}

# 5. Prüfe my.ini
Write-Host ""
Write-Host "Prüfe MySQL-Konfiguration..." -ForegroundColor Cyan
if (Test-Path $mysqlConfigFile) {
    try {
        $configContent = Get-Content $mysqlConfigFile -ErrorAction Stop
        $hasDatadir = $configContent | Select-String -Pattern "^datadir\s*=" -Quiet
        $hasPort = $configContent | Select-String -Pattern "^port\s*=" -Quiet
        
        if (-not $hasDatadir) {
            Write-Host "  ⚠ datadir nicht in my.ini gefunden" -ForegroundColor Yellow
        } else {
            Write-Host "  ✓ datadir konfiguriert" -ForegroundColor Green
        }
        
        if (-not $hasPort) {
            Write-Host "  ⚠ port nicht in my.ini gefunden" -ForegroundColor Yellow
        } else {
            Write-Host "  ✓ port konfiguriert" -ForegroundColor Green
        }
    } catch {
        Write-Host "  ⚠ my.ini kann nicht gelesen werden" -ForegroundColor Red
        $issues += "my.ini nicht lesbar"
    }
} else {
    Write-Host "  ⚠ my.ini nicht gefunden: $mysqlConfigFile" -ForegroundColor Red
    $issues += "my.ini nicht gefunden"
}

# 6. Prüfe Datenverzeichnis
Write-Host ""
Write-Host "Prüfe Datenverzeichnis..." -ForegroundColor Cyan
if (Test-Path $mysqlDataDir) {
    $dirInfo = Get-Item $mysqlDataDir
    if (-not ($dirInfo.Attributes -band [System.IO.FileAttributes]::Directory)) {
        Write-Host "  ⚠ Datenverzeichnis ist keine Verzeichnis" -ForegroundColor Red
        $issues += "Datenverzeichnis ungültig"
    } else {
        Write-Host "  ✓ Datenverzeichnis existiert: $mysqlDataDir" -ForegroundColor Green
        
        # Prüfe Berechtigungen
        try {
            $testFile = Join-Path $mysqlDataDir "test_write.tmp"
            "test" | Out-File -FilePath $testFile -ErrorAction Stop
            Remove-Item $testFile -ErrorAction Stop
            Write-Host "  ✓ Schreibrechte OK" -ForegroundColor Green
        } catch {
            Write-Host "  ⚠ Keine Schreibrechte im Datenverzeichnis" -ForegroundColor Red
            $issues += "Keine Schreibrechte"
            $fixes += "Prüfe Dateiberechtigungen für $mysqlDataDir"
        }
    }
} else {
    Write-Host "  ⚠ Datenverzeichnis nicht gefunden: $mysqlDataDir" -ForegroundColor Red
    $issues += "Datenverzeichnis nicht gefunden"
}

# 7. Zusammenfassung
Write-Host ""
Write-Host "=== Zusammenfassung ===" -ForegroundColor Cyan
Write-Host ""

if ($issues.Count -eq 0) {
    Write-Host "✓ Keine offensichtlichen Probleme gefunden" -ForegroundColor Green
    Write-Host ""
    Write-Host "Versuche MySQL zu starten..." -ForegroundColor Yellow
} else {
    Write-Host "Gefundene Probleme:" -ForegroundColor Yellow
    foreach ($issue in $issues) {
        Write-Host "  • $issue" -ForegroundColor Red
    }
    Write-Host ""
    Write-Host "Empfohlene Reparaturen:" -ForegroundColor Yellow
    foreach ($fix in $fixes) {
        Write-Host "  • $fix" -ForegroundColor Cyan
    }
    Write-Host ""
    
    # Frage ob repariert werden soll
    $response = Read-Host "Soll ich die Reparaturen jetzt durchführen? (J/N)"
    if ($response -eq "J" -or $response -eq "j" -or $response -eq "Y" -or $response -eq "y") {
        Write-Host ""
        Write-Host "Starte Reparatur..." -ForegroundColor Cyan
        
        # Reparatur 1: Aria-Log-Dateien löschen
        if ($ariaLogFiles) {
            Write-Host "  Lösche aria_log Dateien..." -ForegroundColor Yellow
            $ariaLogFiles | Remove-Item -Force -ErrorAction SilentlyContinue
            Write-Host "    ✓ Gelöscht" -ForegroundColor Green
        }
        
        # Reparatur 2: aria_log_control löschen wenn korrupt
        if (Test-Path $ariaLogControl) {
            try {
                $content = Get-Content $ariaLogControl -ErrorAction Stop
                if ($null -eq $content -or $content.Length -eq 0) {
                    Write-Host "  Lösche korrupte aria_log_control..." -ForegroundColor Yellow
                    Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
                    Write-Host "    ✓ Gelöscht" -ForegroundColor Green
                }
            } catch {
                Write-Host "  Lösche nicht lesbare aria_log_control..." -ForegroundColor Yellow
                Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
                Write-Host "    ✓ Gelöscht" -ForegroundColor Green
            }
        }
        
        Write-Host ""
        Write-Host "✓ Reparatur abgeschlossen" -ForegroundColor Green
    }
}

# 8. Versuche MySQL zu starten
Write-Host ""
Write-Host "=== MySQL Start-Versuch ===" -ForegroundColor Cyan
Write-Host ""

$mysqlStartScript = "C:\xampp\mysql_start.bat"
if (Test-Path $mysqlStartScript) {
    Write-Host "Starte MySQL über mysql_start.bat..." -ForegroundColor Yellow
    try {
        $process = Start-Process -FilePath $mysqlStartScript -WindowStyle Hidden -PassThru -ErrorAction Stop
        Start-Sleep -Seconds 5
        
        # Prüfe ob MySQL läuft
        $mysqlRunning = Get-Process -Name mysqld -ErrorAction SilentlyContinue
        if ($mysqlRunning) {
            Write-Host "✓ MySQL-Prozess läuft" -ForegroundColor Green
            
            # Warte bis Port antwortet
            Write-Host "Warte auf MySQL-Port..." -ForegroundColor Yellow
            $waited = 0
            $maxWait = 15
            while ($waited -lt $maxWait) {
                Start-Sleep -Seconds 1
                $waited++
                
                try {
                    $tcpClient = New-Object System.Net.Sockets.TcpClient
                    $tcpClient.ReceiveTimeout = 1000
                    $tcpClient.SendTimeout = 1000
                    $result = $tcpClient.BeginConnect("localhost", 3306, $null, $null)
                    $wait = $result.AsyncWaitHandle.WaitOne(1000, $false)
                    if ($wait) {
                        $tcpClient.EndConnect($result)
                        $tcpClient.Close()
                        Write-Host "✓ MySQL ist bereit und antwortet auf Port 3306" -ForegroundColor Green
                        exit 0
                    } else {
                        $tcpClient.Close()
                    }
                } catch {
                    # Port antwortet noch nicht
                }
            }
            
            Write-Host "⚠ MySQL-Prozess läuft, aber Port 3306 antwortet noch nicht" -ForegroundColor Yellow
            Write-Host "  Prüfe MySQL-Logs für Details: $errorLog" -ForegroundColor Yellow
        } else {
            Write-Host "✗ MySQL konnte nicht gestartet werden" -ForegroundColor Red
            Write-Host "  Prüfe MySQL-Logs: $errorLog" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "✗ Fehler beim Starten: $_" -ForegroundColor Red
    }
} else {
    Write-Host "✗ mysql_start.bat nicht gefunden: $mysqlStartScript" -ForegroundColor Red
    Write-Host "  Bitte starte MySQL manuell über XAMPP Control Panel" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Fertig ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Nächste Schritte:" -ForegroundColor Yellow
Write-Host "  1. Prüfe MySQL-Logs: $errorLog" -ForegroundColor White
Write-Host "  2. Prüfe XAMPP Control Panel" -ForegroundColor White
Write-Host "  3. Bei Port-Konflikten: Beende andere MySQL-Instanzen" -ForegroundColor White
Write-Host "  4. Bei Berechtigungsfehlern: Führe als Administrator aus" -ForegroundColor White
