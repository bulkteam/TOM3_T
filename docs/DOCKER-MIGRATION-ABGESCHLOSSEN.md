# TOM3 - Docker Migration abgeschlossen ✅

## Status: Vollständig auf Docker MariaDB umgestellt

**Datum:** 2026-01-01  
**Status:** ✅ Migration abgeschlossen

## Verifizierung

### ✅ Konfiguration

**ENV-Variablen (gelesen über `config/database.php`):**
- Host: `127.0.0.1` ✅ (ENV: `MYSQL_HOST`)
- Port: `3307` ✅ (ENV: `MYSQL_PORT`)
- User: `tomcat` ✅ (ENV: `MYSQL_USER`)
- Passwort: Über ENV gesetzt ✅ (ENV: `MYSQL_PASSWORD`)

**WICHTIG:** Secrets werden nicht mehr in `config/database.php` gespeichert, sondern über ENV-Variablen gesetzt. Siehe [SECURITY-IMPROVEMENTS.md](SECURITY-IMPROVEMENTS.md).

**`src/TOM/Infrastructure/Database/DatabaseConnection.php`:**
- Fallback-Port: `3307` ✅ (geändert von 3306)
- Fallback-Host: `127.0.0.1` ✅ (geändert von localhost)

### ✅ Keine XAMPP MySQL Abhängigkeiten

**Geprüft:**
- ❌ Keine hardcodierten Verbindungen auf Port 3306
- ❌ Keine hardcodierten Verbindungen auf `localhost:3306`
- ✅ Alle Verbindungen verwenden `config/database.php`
- ✅ Alle Verbindungen verwenden Port 3307 (Docker)

### ✅ Docker Setup

**Container:**
- `mariadb104` (MariaDB 10.4.32) auf Port 3307 ✅
- `phpmyadmin` (phpMyAdmin) auf Port 8081 ✅

**Volumes:**
- `mariadb_data` (persistente Daten) ✅
- `./conf.d` (Config-Mount) ✅
- `./logs` (Logs-Mount) ✅

### ✅ Datenbank

**Status:**
- Datenbank `tom` existiert ✅
- 49 Tabellen erstellt ✅
- Test-User erstellt ✅
- Migrationen ausgeführt ✅

## Betriebsanleitung

### MariaDB starten

```powershell
cd C:\dev\mariadb-docker
docker compose up -d
```

### MariaDB stoppen

```powershell
cd C:\dev\mariadb-docker
docker compose stop
```

### Status prüfen

```powershell
cd C:\dev\mariadb-docker
docker compose ps
```

**Siehe auch:** [DOCKER-MARIADB-BETRIEB.md](DOCKER-MARIADB-BETRIEB.md)

## XAMPP MySQL

### ❌ Wird NICHT mehr benötigt

**Wichtig:**
- XAMPP MySQL sollte **NICHT** gestartet werden
- TOM3 verwendet **nur** Docker MariaDB auf Port 3307
- XAMPP MySQL würde auf Port 3306 laufen (nicht verwendet)

**XAMPP Control Panel:**
- ❌ MySQL **NICHT** starten
- ✅ Nur Apache starten (für PHP)

### XAMPP MySQL deaktivieren (optional)

Falls XAMPP MySQL als Windows-Service installiert ist:

```powershell
# Service deaktivieren
Set-Service -Name "MySQL" -StartupType Disabled -ErrorAction SilentlyContinue
```

Oder über Services.msc:
1. Windows-Taste + R → `services.msc`
2. Suche nach "MySQL" oder "XAMPP MySQL"
3. Rechtsklick → Eigenschaften
4. Starttyp: **Deaktiviert**

## Dokumentation

**Hauptdokumentation:**
- [DOCKER-MARIADB-SETUP.md](DOCKER-MARIADB-SETUP.md) - Vollständiges Setup
- [DOCKER-MARIADB-BETRIEB.md](DOCKER-MARIADB-BETRIEB.md) - Betriebsanleitung (Start/Stop)

**Weitere Dokumentation:**
- [SETUP.md](SETUP.md) - Allgemeine Setup-Anleitung (Docker als Option A)
- [INSTALLATION-WINDOWS.md](INSTALLATION-WINDOWS.md) - Windows-Installation (Docker als Option A)
- [QUICK-START-TEST.md](QUICK-START-TEST.md) - Quick Start (Docker als empfohlen)

## Schnellreferenz

### Start/Stop Befehle

| Aktion | Befehl |
|--------|--------|
| **Start** | `cd C:\dev\mariadb-docker && docker compose up -d` |
| **Stop** | `cd C:\dev\mariadb-docker && docker compose stop` |
| **Status** | `cd C:\dev\mariadb-docker && docker compose ps` |
| **Logs** | `docker logs mariadb104` |
| **Restart** | `cd C:\dev\mariadb-docker && docker compose restart mariadb` |

### Verbindungsdaten

| Parameter | Wert |
|-----------|------|
| **Host** | `127.0.0.1` |
| **Port** | `3307` |
| **Datenbank** | `tom` |
| **User** | `tomcat` |
| **Passwort** | Über ENV-Variable `MYSQL_PASSWORD` |
| **phpMyAdmin** | http://127.0.0.1:8081 |

## Checkliste für neuen Entwickler

✅ Docker Desktop installiert  
✅ Docker MariaDB Setup durchgeführt (siehe [DOCKER-MARIADB-SETUP.md](DOCKER-MARIADB-SETUP.md))  
✅ ENV-Variablen gesetzt (MYSQL_HOST, MYSQL_PORT, etc.)  
✅ MariaDB Container läuft (`docker compose ps`)  
✅ Verbindungstest erfolgreich (`php test-docker-db.php`)  
✅ XAMPP MySQL ist **NICHT** gestartet  

---

*Migration abgeschlossen - TOM3 läuft vollständig auf Docker MariaDB*


