# Verbesserte MySQL-Konfiguration für Stabilität

## Problem

Die aktuelle `my.ini` in `C:\xampp\mysql\data\my.ini` ist sehr minimal und kann zu Stabilitätsproblemen führen.

## Empfohlene Verbesserungen

### 1. Aria-Storage-Engine optimieren

Füge folgende Zeilen zur `[mysqld]` Sektion hinzu:

```ini
# Aria Storage Engine - Verbesserte Stabilität
aria-pagecache-buffer-size=128M
aria-log-file-size=64M
aria-checkpoint-interval=30
aria-force-start-after-recovery-failures=1
```

### 2. InnoDB optimieren

```ini
# InnoDB - Bessere Performance und Stabilität
innodb_buffer_pool_size=256M
innodb_log_file_size=64M
innodb_log_buffer_size=16M
innodb_flush_log_at_trx_commit=2
innodb_flush_method=O_DIRECT
```

### 3. Allgemeine Stabilität

```ini
# Allgemeine Stabilität
max_connections=100
wait_timeout=28800
interactive_timeout=28800
table_open_cache=2000
thread_cache_size=50
```

### 4. Logging für besseres Debugging

```ini
# Logging
log_error=mysql_error.log
slow_query_log=1
slow_query_log_file=slow_query.log
long_query_time=2
```

## Vollständige verbesserte my.ini

Kopiere diese Datei nach `C:\xampp\mysql\data\my.ini` (BACKUP der alten Datei erstellen!):

```ini
[mysqld]
datadir=C:/xampp/mysql/data
port=3306
socket=C:/xampp/mysql/mysql.sock
basedir=C:/xampp/mysql
tmpdir=C:/xampp/tmp
pid_file=mysql.pid

# Character Set
character-set-server=utf8mb4
collation-server=utf8mb4_general_ci

# Aria Storage Engine - Verbesserte Stabilität
aria-pagecache-buffer-size=128M
aria-log-file-size=64M
aria-checkpoint-interval=30
aria-force-start-after-recovery-failures=1

# InnoDB - Bessere Performance
innodb_data_home_dir=C:/xampp/mysql/data
innodb_data_file_path=ibdata1:10M:autoextend
innodb_log_group_home_dir=C:/xampp/mysql/data
innodb_buffer_pool_size=256M
innodb_log_file_size=64M
innodb_log_buffer_size=16M
innodb_flush_log_at_trx_commit=2
innodb_flush_method=O_DIRECT
innodb_lock_wait_timeout=50

# Allgemeine Einstellungen
max_connections=100
max_allowed_packet=16M
wait_timeout=28800
interactive_timeout=28800
table_open_cache=2000
thread_cache_size=50
key_buffer_size=32M
sort_buffer_size=2M
read_buffer_size=2M
read_rnd_buffer_size=4M
myisam_sort_buffer_size=64M

# Logging
log_error=mysql_error.log
slow_query_log=1
slow_query_log_file=slow_query.log
long_query_time=2

# SQL Mode
sql_mode=NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION
log_bin_trust_function_creators=1

[client]
port=3306
socket=C:/xampp/mysql/mysql.sock
default-character-set=utf8mb4
```

## Wichtige Hinweise

1. **BACKUP erstellen** vor Änderungen!
2. **MySQL stoppen** vor dem Bearbeiten der my.ini
3. Nach Änderungen **MySQL neu starten**
4. Bei Problemen: Alte my.ini wiederherstellen

## Automatisches Recovery einrichten

1. Kopiere `scripts/mysql-auto-recovery.bat` nach `C:\xampp\`
2. Erstelle einen Windows Scheduled Task, der das Skript vor dem MySQL-Start ausführt
3. Oder füge es zu XAMPP Startup hinzu

