<#
.SYNOPSIS
  XAMPP MariaDB Health Check (Windows / XAMPP MariaDB 10.4.x)

.DESCRIPTION
  - Checks that MariaDB responds (mysqladmin ping)
  - Checks port usage (default 3306)
  - Scans mysql_error.log for known fatal patterns (Aria / mysql.plugin / abort)
  - Validates my.ini contains recommended stability settings
  - Optional: runs mysqlcheck (mysql DB / all DBs) and mysql_upgrade

.PARAMETER XamppRoot
  Root folder of XAMPP (default: C:\xampp)

.PARAMETER User
  DB user (default: root)

.PARAMETER PromptForPassword
  If set, will ask for a password and pass it to mysql tools.
  (If not set, script runs without -p; fine for typical XAMPP root-without-password.)

.PARAMETER Port
  Port to check (default 3306)

.PARAMETER TailErrorLogLines
  How many last lines of mysql_error.log to scan (default 300)

.PARAMETER DoRepairMysqlSystem
  Runs: mysqlcheck --repair --verbose mysql

.PARAMETER DoCheckAllDatabases
  Runs: mysqlcheck --check --all-databases

.PARAMETER DoAutoRepairAllDatabases
  Runs: mysqlcheck --auto-repair --all-databases
  (Use with care.)

.PARAMETER DoUpgrade
  Runs: mysql_upgrade

.EXAMPLE
  .\mysql-health-check.ps1

.EXAMPLE
  .\mysql-health-check.ps1 -DoRepairMysqlSystem -DoUpgrade -DoCheckAllDatabases

.EXAMPLE
  .\mysql-health-check.ps1 -PromptForPassword
#>

[CmdletBinding()]
param(
  [string]$XamppRoot = "C:\xampp",
  [string]$User = "root",
  [switch]$PromptForPassword,
  [int]$Port = 3306,
  [int]$TailErrorLogLines = 300,
  [switch]$DoRepairMysqlSystem,
  [switch]$DoCheckAllDatabases,
  [switch]$DoAutoRepairAllDatabases,
  [switch]$DoUpgrade
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Info($msg)  { Write-Host "[INFO]  $msg" -ForegroundColor Cyan }
function Write-Warn($msg)  { Write-Host "[WARN]  $msg" -ForegroundColor Yellow }
function Write-Err ($msg)  { Write-Host "[ERROR] $msg" -ForegroundColor Red }
function Write-Ok  ($msg)  { Write-Host "[OK]    $msg" -ForegroundColor Green }

function Get-PlainPassword {
  param([switch]$Prompt)
  if (-not $Prompt) { return $null }
  $sec = Read-Host "Enter password for MySQL user '$User'" -AsSecureString
  $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
  try { return [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
  finally { [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

function Invoke-Tool {
  param(
    [Parameter(Mandatory)][string]$Exe,
    [Parameter(Mandatory)][string[]]$Args
  )
  $psi = New-Object System.Diagnostics.ProcessStartInfo
  $psi.FileName = $Exe
  $psi.Arguments = ($Args -join " ")
  $psi.RedirectStandardOutput = $true
  $psi.RedirectStandardError  = $true
  $psi.UseShellExecute = $false
  $psi.CreateNoWindow  = $true
  $p = New-Object System.Diagnostics.Process
  $p.StartInfo = $psi
  [void]$p.Start()
  $stdout = $p.StandardOutput.ReadToEnd()
  $stderr = $p.StandardError.ReadToEnd()
  $p.WaitForExit()
  return [pscustomobject]@{
    ExitCode = $p.ExitCode
    StdOut   = $stdout.Trim()
    StdErr   = $stderr.Trim()
    Command  = "$Exe $($psi.Arguments)"
  }
}

function Get-LastLines {
  param([string]$Path, [int]$Lines = 200)
  if (-not (Test-Path $Path)) { return @() }
  return Get-Content -Path $Path -Tail $Lines -ErrorAction SilentlyContinue
}

function Test-PortUsage {
  param([int]$Port)
  Write-Info "Checking port $Port usage..."

  # Try Get-NetTCPConnection first (newer Windows)
  if (Get-Command Get-NetTCPConnection -ErrorAction SilentlyContinue) {
    $conns = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    if (-not $conns) {
      Write-Ok "Port $Port is not in LISTEN state (no listener found)."
      return
    }
    foreach ($c in $conns) {
      $procId = $c.OwningProcess
      $p = Get-Process -Id $procId -ErrorAction SilentlyContinue
      $pname = if ($p) { $p.ProcessName } else { "PID $procId" }
      Write-Warn "Port $Port is LISTENING by: $pname (PID $procId)"
    }
    return
  }

  # Fallback: netstat
  $netstat = & netstat -ano | Select-String ":$Port\s" | Select-String "LISTENING"
  if (-not $netstat) {
    Write-Ok "Port $Port is not in LISTEN state (netstat found no LISTENING)."
    return
  }
  Write-Warn "Port $Port has LISTENING entries (netstat). Output:"
  $netstat | ForEach-Object { Write-Host "  $_" }
}

# ---------- Resolve Paths ----------
$mysqlBin  = Join-Path $XamppRoot "mysql\bin"
$mysqlData = Join-Path $XamppRoot "mysql\data"
$mysqlTmp  = Join-Path $XamppRoot "tmp"
$myIni     = Join-Path $XamppRoot "mysql\bin\my.ini"

$mysqladmin = Join-Path $mysqlBin "mysqladmin.exe"
$mysqlcheck = Join-Path $mysqlBin "mysqlcheck.exe"
$mysqlupg   = Join-Path $mysqlBin "mysql_upgrade.exe"

$errorLog1  = Join-Path $mysqlData "mysql_error.log"
$errorLog2  = Join-Path $mysqlData "mysql_error.log.err"  # just in case (some bundles differ)

Write-Host "=== XAMPP MariaDB Health Check ===" -ForegroundColor Cyan
Write-Host ""
Write-Info "XAMPP root: $XamppRoot"
Write-Info "MySQL bin : $mysqlBin"
Write-Info "MySQL data: $mysqlData"

foreach ($f in @($mysqladmin, $mysqlcheck, $mysqlupg)) {
  if (-not (Test-Path $f)) { Write-Err "Missing tool: $f"; throw "Tool not found." }
}
if (-not (Test-Path $mysqlData)) { Write-Err "Missing data dir: $mysqlData"; throw "Data dir not found." }

# ---------- Password handling ----------
$plainPw = Get-PlainPassword -Prompt:$PromptForPassword
$authArgs = @("-u", $User)
if ($plainPw) {
  # mysql tools accept -p<pass> (no space). Note: visible in process list while running.
  $authArgs += ("-p$plainPw")
}

Write-Host ""
Write-Host "=== 1) Connectivity Check ===" -ForegroundColor Yellow
$ping = Invoke-Tool -Exe $mysqladmin -Args (@("--connect-timeout=3") + $authArgs + @("ping"))
if ($ping.ExitCode -eq 0 -and $ping.StdOut -match "alive") {
  Write-Ok "MariaDB responds: $($ping.StdOut)"
} else {
  Write-Err "MariaDB ping failed."
  Write-Host "  Cmd: $($ping.Command)"
  if ($ping.StdOut) { Write-Host "  Out: $($ping.StdOut)" }
  if ($ping.StdErr) { Write-Host "  Err: $($ping.StdErr)" }
}

Write-Host ""
Write-Host "=== 2) Port Check ===" -ForegroundColor Yellow
Test-PortUsage -Port $Port

Write-Host ""
Write-Host "=== 3) Error Log Scan (last $TailErrorLogLines lines) ===" -ForegroundColor Yellow
$logPath = if (Test-Path $errorLog1) { $errorLog1 } elseif (Test-Path $errorLog2) { $errorLog2 } else { $null }
if (-not $logPath) {
  Write-Warn "No mysql_error.log found under $mysqlData"
} else {
  Write-Info "Using log: $logPath"
  $lines = Get-LastLines -Path $logPath -Lines $TailErrorLogLines

  $patterns = @(
    "Aria recovery failed",
    "Cannot find checkpoint record",
    "Could not open mysql\.plugin table",
    "Failed to initialize plugins",
    "Aborting",
    "InnoDB: Starting crash recovery"
  )

  $hits = @{}
  foreach ($p in $patterns) { $hits[$p] = 0 }

  foreach ($l in $lines) {
    foreach ($p in $patterns) {
      if ($l -match $p) { $hits[$p]++ }
    }
  }

  $anyBad = $false
  foreach ($kv in $hits.GetEnumerator() | Sort-Object Name) {
    if ($kv.Value -gt 0) { $anyBad = $true; Write-Warn "$($kv.Name): $($kv.Value) hit(s)" }
    else { Write-Ok "$($kv.Name): 0" }
  }

  if ($anyBad) {
    Write-Warn "Found suspicious patterns. Consider: aria-log cleanup / mysqlcheck / mysql_upgrade (depending on which patterns appear)."
    Write-Info "  Run: scripts\mysql-fix-aria-logs.bat"
    Write-Info "  Or:  scripts\mysql-fix-aria-and-plugin.bat"
  } else {
    Write-Ok "No critical patterns found in recent log tail."
  }
}

Write-Host ""
Write-Host "=== 4) my.ini Sanity Check ===" -ForegroundColor Yellow
if (-not (Test-Path $myIni)) {
  Write-Warn "my.ini not found at: $myIni"
} else {
  $ini = Get-Content -Path $myIni -ErrorAction SilentlyContinue

  function Has-IniLine($regex) {
    return ($ini | Where-Object { $_ -match $regex } | Measure-Object).Count -gt 0
  }

  $recommendations = New-Object System.Collections.Generic.List[string]

  if (Has-IniLine '^\s*key_buffer\s*=') {
    $recommendations.Add("Replace 'key_buffer=…' with 'key_buffer_size=…' (avoid ambiguous prefix).")
  } elseif (-not (Has-IniLine '^\s*key_buffer_size\s*=')) {
    $recommendations.Add("Consider setting 'key_buffer_size' (if you use MyISAM at all).")
  } else {
    Write-Ok "key_buffer_size present."
  }

  # HINWEIS: internal_tmp_disk_storage_engine wird in MariaDB 10.4 nicht unterstützt
  if (Has-IniLine '^\s*internal_tmp_disk_storage_engine\s*=\s*InnoDB') {
    Write-Warn "internal_tmp_disk_storage_engine=InnoDB is present, but NOT supported in MariaDB 10.4.32!"
    Write-Warn "  This will cause 'unknown variable' error. Remove this line."
  } else {
    Write-Info "internal_tmp_disk_storage_engine not set (correct for MariaDB 10.4)."
  }

  if (-not (Has-IniLine '^\s*aria_recover_options\s*=')) {
    $recommendations.Add("Add: aria_recover_options=BACKUP,QUICK")
  } else {
    Write-Ok "aria_recover_options present."
  }

  if (-not (Has-IniLine '^\s*myisam_recover_options\s*=')) {
    $recommendations.Add("Add: myisam_recover_options=BACKUP,FORCE")
  } else {
    Write-Ok "myisam_recover_options present."
  }

  if (-not (Has-IniLine '^\s*aria_sort_buffer_size\s*=')) {
    $recommendations.Add("Add: aria_sort_buffer_size=1M (avoids repair/index-sort failures).")
  } else {
    Write-Ok "aria_sort_buffer_size present."
  }

  if (-not (Has-IniLine '^\s*max_allowed_packet\s*=')) {
    $recommendations.Add("Add: max_allowed_packet=16M (common PHP baseline).")
  } elseif (Has-IniLine '^\s*max_allowed_packet\s*=\s*1M') {
    $recommendations.Add("Consider increasing max_allowed_packet from 1M to 16M (for larger inserts/blobs).")
  } else {
    Write-Ok "max_allowed_packet present."
  }

  if ($recommendations.Count -eq 0) {
    Write-Ok "my.ini looks good for the core stability settings."
  } else {
    Write-Warn "Recommended my.ini adjustments:"
    $recommendations | ForEach-Object { Write-Host "  - $_" }
    Write-Info "See: docs/MYSQL-CONFIG-CHANGES.md for details"
  }

  Write-Host ""
  Write-Info "AV/Defender exclusions to verify on target system:"
  Write-Host "  - Folder: $mysqlData"
  Write-Host "  - Folder: $mysqlTmp"
  Write-Host "  - Process: $mysqlBin\mysqld.exe"
}

Write-Host ""
Write-Host "=== 5) mysqlcheck / mysql_upgrade (optional actions) ===" -ForegroundColor Yellow

if ($DoRepairMysqlSystem) {
  Write-Info "Running: mysqlcheck --repair --verbose mysql"
  $r = Invoke-Tool -Exe $mysqlcheck -Args ($authArgs + @("--repair", "--verbose", "mysql"))
  Write-Host $r.StdOut
  if ($r.ExitCode -eq 0) { Write-Ok "mysql system DB repair completed (exit 0)." }
  else {
    Write-Warn "mysqlcheck repair exit code: $($r.ExitCode)"
    if ($r.StdErr) { Write-Host $r.StdErr }
  }
} else {
  Write-Info "Skipped mysql system DB repair (use -DoRepairMysqlSystem to run)."
}

if ($DoCheckAllDatabases) {
  Write-Info "Running: mysqlcheck --check --all-databases"
  $c = Invoke-Tool -Exe $mysqlcheck -Args ($authArgs + @("--check", "--all-databases"))
  Write-Host $c.StdOut
  if ($c.ExitCode -eq 0) { Write-Ok "All-databases CHECK completed (exit 0)." }
  else {
    Write-Warn "mysqlcheck --check exit code: $($c.ExitCode)"
    if ($c.StdErr) { Write-Host $c.StdErr }
  }
} else {
  Write-Info "Skipped all-databases check (use -DoCheckAllDatabases)."
}

if ($DoAutoRepairAllDatabases) {
  Write-Warn "Running AUTO-REPAIR for ALL databases (use with care)."
  $ar = Invoke-Tool -Exe $mysqlcheck -Args ($authArgs + @("--auto-repair", "--all-databases"))
  Write-Host $ar.StdOut
  if ($ar.ExitCode -eq 0) { Write-Ok "All-databases AUTO-REPAIR completed (exit 0)." }
  else {
    Write-Warn "mysqlcheck --auto-repair exit code: $($ar.ExitCode)"
    if ($ar.StdErr) { Write-Host $ar.StdErr }
  }
} else {
  Write-Info "Skipped all-databases auto-repair (use -DoAutoRepairAllDatabases)."
}

if ($DoUpgrade) {
  Write-Info "Running: mysql_upgrade"
  $u = Invoke-Tool -Exe $mysqlupg -Args ($authArgs)
  Write-Host $u.StdOut
  if ($u.ExitCode -eq 0) { Write-Ok "mysql_upgrade completed (exit 0)." }
  else {
    Write-Warn "mysql_upgrade exit code: $($u.ExitCode)"
    if ($u.StdErr) { Write-Host $u.StdErr }
  }
} else {
  Write-Info "Skipped mysql_upgrade (use -DoUpgrade)."
}

Write-Host ""
Write-Host "=== Summary ===" -ForegroundColor Cyan
Write-Info "If you see recurring 'InnoDB crash recovery' or any Aria checkpoint errors after normal stop/start:"
Write-Host "  - Stop MySQL cleanly via:  cd $mysqlBin ; .\mysqladmin.exe -u $User shutdown"
Write-Host "  - Avoid killing mysqld.exe via Task Manager / forced stop"
Write-Host "  - Verify AV exclusions and disk health"
Write-Host ""
Write-Info "Available repair scripts:"
Write-Host "  - scripts\mysql-fix-aria-logs.bat"
Write-Host "  - scripts\mysql-fix-aria-and-plugin.bat"
Write-Host "  - scripts\mysql-repair-tables.bat"
Write-Host "  - scripts\mysql-diagnose-and-fix.bat"
Write-Host ""
Write-Ok "Health check completed."
