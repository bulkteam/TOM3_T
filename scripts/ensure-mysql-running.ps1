# MySQL Ensure Running Script
# Prüft ob MySQL läuft, führt Recovery durch wenn nötig, startet MySQL und wartet bis es bereit ist
# Kann von index.php oder anderen Scripts aufgerufen werden

param(
    [int]$MaxWaitSeconds = 30,  # Maximale Wartezeit bis MySQL bereit ist
    [switch]$Silent = $false     # Keine Ausgabe (für automatische Aufrufe)
)

$ErrorActionPreference = "Continue"
$mysqlDataDir = "C:\xampp\mysql\data"
$mysqlBinDir = "C:\xampp\mysql\bin"
$mysqlStartScript = "C:\xampp\mysql_start.bat"
$mysqlStopScript = "C:\xampp\mysql_stop.bat"
$xamppControl = "C:\xampp\xampp-control.exe"

function Write-Info {
    param([string]$Message)
    if (-not $Silent) {
        Write-Host $Message -ForegroundColor Cyan
    }
}

function Write-Error-Info {
    param([string]$Message)
    if (-not $Silent) {
        Write-Host $Message -ForegroundColor Red
    }
}

function Write-Success {
    param([string]$Message)
    if (-not $Silent) {
        Write-Host $Message -ForegroundColor Green
    }
}

function Test-MySQLPort {
    # Prüft ob MySQL auf Port 3306 antwortet
    try {
        $tcpClient = New-Object System.Net.Sockets.TcpClient
        $tcpClient.ReceiveTimeout = 2000
        $tcpClient.SendTimeout = 2000
        $result = $tcpClient.BeginConnect("localhost", 3306, $null, $null)
        $wait = $result.AsyncWaitHandle.WaitOne(2000, $false)
        if ($wait) {
            $tcpClient.EndConnect($result)
            $tcpClient.Close()
            return $true
        } else {
            $tcpClient.Close()
            return $false
        }
    } catch {
        return $false
    }
}

function Test-MySQLProcess {
    # Prüft ob mysqld.exe läuft
    $process = Get-Process -Name mysqld -ErrorAction SilentlyContinue
    return ($null -ne $process)
}

function Start-MySQL {
    # Versucht MySQL zu starten
    Write-Info "Starte MySQL..."
    
    # Prüfe ob XAMPP Control Panel verfügbar ist
    if (Test-Path $xamppControl) {
        # Versuche über XAMPP Control Panel zu starten (nicht direkt möglich, aber wir können den Service starten)
        Write-Info "  Verwende XAMPP MySQL Service..."
    }
    
    # Versuche über mysql_start.bat zu starten
    if (Test-Path $mysqlStartScript) {
        try {
            $process = Start-Process -FilePath $mysqlStartScript -WindowStyle Hidden -PassThru -ErrorAction Stop
            Start-Sleep -Seconds 2
            return $true
        } catch {
            Write-Error-Info "  Fehler beim Starten über mysql_start.bat"
        }
    }
    
    # Fallback: Versuche mysqld direkt zu starten
    $mysqldPath = Join-Path $mysqlBinDir "mysqld.exe"
    if (Test-Path $mysqldPath) {
        try {
            $process = Start-Process -FilePath $mysqldPath -ArgumentList "--defaults-file=$mysqlDataDir\my.ini" -WindowStyle Hidden -PassThru -ErrorAction Stop
            Start-Sleep -Seconds 2
            return $true
        } catch {
            Write-Error-Info "  Fehler beim direkten Start von mysqld"
        }
    }
    
    return $false
}

function Invoke-MySQLRecovery {
    # Führt MySQL Recovery durch (Aria-Log-Bereinigung)
    Write-Info "Führe MySQL Recovery durch..."
    
    # Prüfe ob MySQL läuft und stoppe es
    if (Test-MySQLProcess) {
        Write-Info "  Stoppe MySQL für Recovery..."
        Stop-Process -Name mysqld -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }
    
    # Lösche aria_log Dateien
    $ariaLogFiles = Get-ChildItem -Path $mysqlDataDir -Filter "aria_log.*" -ErrorAction SilentlyContinue
    if ($ariaLogFiles) {
        Write-Info "  Lösche aria_log Dateien..."
        $ariaLogFiles | Remove-Item -Force -ErrorAction SilentlyContinue
    }
    
    # Lösche aria_log_control wenn korrupt
    $ariaLogControl = Join-Path $mysqlDataDir "aria_log_control"
    if (Test-Path $ariaLogControl) {
        try {
            $content = Get-Content $ariaLogControl -ErrorAction Stop
            if ($null -eq $content -or $content.Length -eq 0) {
                Write-Info "  Lösche korrupte aria_log_control..."
                Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
            }
        } catch {
            Write-Info "  Lösche nicht lesbare aria_log_control..."
            Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
        }
    }
    
    Write-Success "  Recovery abgeschlossen"
}

# === Hauptlogik ===

Write-Info "=== MySQL Status-Prüfung ==="

# 1. Prüfe ob MySQL bereits läuft und bereit ist
if (Test-MySQLProcess) {
    if (Test-MySQLPort) {
        Write-Success "✓ MySQL läuft und ist bereit"
        exit 0
    } else {
        Write-Info "MySQL-Prozess läuft, aber Port 3306 antwortet nicht. Warte..."
        # Warte bis zu 10 Sekunden
        $waited = 0
        while ($waited -lt 10) {
            Start-Sleep -Seconds 1
            $waited++
            if (Test-MySQLPort) {
                Write-Success "✓ MySQL ist jetzt bereit"
                exit 0
            }
        }
        Write-Error-Info "MySQL-Prozess läuft, aber antwortet nicht. Stoppe und starte neu..."
        Stop-Process -Name mysqld -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }
}

# 2. Prüfe auf Aria-Fehler und führe Recovery durch
$errorLog = Join-Path $mysqlDataDir "mysql_error.log"
$needsRecovery = $false

if (Test-Path $errorLog) {
    $lastError = Get-Content $errorLog -Tail 30 -ErrorAction SilentlyContinue | Select-String -Pattern "Aria recovery failed|aria_chk|corrupt" -Quiet
    if ($lastError) {
        $needsRecovery = $true
        Write-Info "Aria-Fehler in Log erkannt"
    }
}

# Prüfe aria_log_control
$ariaLogControl = Join-Path $mysqlDataDir "aria_log_control"
if (Test-Path $ariaLogControl) {
    try {
        $content = Get-Content $ariaLogControl -ErrorAction Stop
        if ($null -eq $content -or $content.Length -eq 0) {
            $needsRecovery = $true
        }
    } catch {
        $needsRecovery = $true
    }
}

if ($needsRecovery) {
    Invoke-MySQLRecovery
}

# 3. Starte MySQL
if (-not (Start-MySQL)) {
    Write-Error-Info "✗ Fehler: MySQL konnte nicht gestartet werden"
    Write-Error-Info "  Bitte starte MySQL manuell über XAMPP Control Panel"
    exit 1
}

# 4. Warte bis MySQL bereit ist
Write-Info "Warte auf MySQL (max. $MaxWaitSeconds Sekunden)..."
$waited = 0
$checkInterval = 1

while ($waited -lt $MaxWaitSeconds) {
    Start-Sleep -Seconds $checkInterval
    $waited += $checkInterval
    
    if (Test-MySQLPort) {
        # Zusätzliche Prüfung: Versuche eine echte Verbindung
        try {
            $testConnection = New-Object System.Data.SqlClient.SqlConnection
            $testConnection.ConnectionString = "Server=localhost;Port=3306;Database=mysql;Uid=root;Pwd=;Connection Timeout=2;"
            $testConnection.Open()
            $testConnection.Close()
            Write-Success "✓ MySQL läuft und ist bereit ($waited Sekunden)"
            exit 0
        } catch {
            # Port antwortet, aber Verbindung schlägt fehl - warte weiter
            if ($waited % 5 -eq 0) {
                Write-Info "  MySQL startet noch... ($waited/$MaxWaitSeconds Sekunden)"
            }
        }
    } else {
        if ($waited % 5 -eq 0) {
            Write-Info "  Warte auf MySQL... ($waited/$MaxWaitSeconds Sekunden)"
        }
    }
}

# Timeout erreicht
Write-Error-Info "✗ Fehler: MySQL ist nach $MaxWaitSeconds Sekunden nicht bereit"
Write-Error-Info "  Bitte prüfe:"
Write-Error-Info "  1. MySQL-Logs: $errorLog"
Write-Error-Info "  2. Port 3306 ist nicht blockiert"
Write-Error-Info "  3. MySQL-Konfiguration ist korrekt"
exit 1


