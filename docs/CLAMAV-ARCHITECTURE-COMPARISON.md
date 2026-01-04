# ClamAV Architektur-Vergleich

## Unterschiede zwischen Text-Vorschlag und unserer Implementierung

### ✅ Was bereits korrekt ist

**1. ClamAV als eigener Container**
- ✅ Implementiert: `tom3-clamav` Container
- ✅ Signaturen persistent: `clamav_db` Volume
- ✅ Read-only Zugriff auf Uploads: `:ro` Mount

**2. Ports vs. expose**
- **Text schlägt vor:** `expose: "3310"` (nur Container-zu-Container)
- **Unsere Lösung:** `ports: "3310:3310"` (Host-Zugriff)
- **Warum:** PHP-App läuft auf XAMPP (Host), nicht in Docker
- ✅ **Unser Ansatz ist korrekt!**

**3. Storage-Architektur**
- **Text schlägt vor:** Docker Volume `uploads:/scandir`
- **Unsere Lösung:** Host-Mount `C:/xampp/htdocs/TOM3/storage:/scans:ro`
- **Warum:** PHP-App schreibt auf Host-Filesystem
- ✅ **Unser Ansatz ist korrekt!**

### ⚠️ Was noch nicht implementiert ist (optional)

**1. Quarantäne-Konzept**

**Text-Vorschlag:**
```
Upload → Quarantäne → Scan → Clean/Infected
```

**Aktuelle Implementierung:**
```
Upload → Storage → Scan (async) → Status-Update
```

**Unterschied:**
- Text: Dateien werden zunächst in Quarantäne abgelegt
- Unsere Lösung: Dateien werden direkt in Storage abgelegt, Scan läuft asynchron

**Ist Quarantäne nötig?**
- **Für MVP:** Nein - asynchroner Scan reicht
- **Für Production:** Optional - erhöht Sicherheit, aber komplexer

**Vorteile Quarantäne:**
- Dateien sind erst nach Scan verfügbar
- Kein Zugriff auf potenziell infizierte Dateien
- Klare Trennung: Quarantäne → Clean → Infected

**Nachteile:**
- Zusätzliche Komplexität (Verschieben von Dateien)
- User muss warten, bis Scan abgeschlossen ist
- Mehr I/O-Operationen

**Empfehlung:** Für MVP nicht nötig. Später optional implementieren.

**2. Automatisches Scannen**

**Text sagt:** "Mit dem reinen Compose-Setup wird noch nichts automatisch gescannt"

**Unsere Lösung:** ✅ **Automatisches Scannen implementiert!**
- Worker-Script (`scan-blob-worker.php`)
- Task Scheduler (läuft alle 5 Minuten)
- Jobs werden automatisch verarbeitet

**Text-Vorschlag war unvollständig** - wir haben das bereits gelöst!

### 📋 Zusammenfassung

| Aspekt | Text-Vorschlag | Unsere Lösung | Status |
|--------|----------------|---------------|--------|
| ClamAV Container | ✅ | ✅ | ✅ Identisch |
| Signaturen Volume | ✅ | ✅ | ✅ Identisch |
| Uploads Volume | Docker Volume | Host-Mount | ✅ **Unser Ansatz besser** (PHP auf Host) |
| Ports | `expose` | `ports` | ✅ **Unser Ansatz besser** (Host-Zugriff) |
| Quarantäne | ✅ Vorgeschlagen | ❌ Nicht implementiert | ⚠️ Optional für später |
| Automatisches Scannen | ❌ Nicht erwähnt | ✅ Implementiert | ✅ **Wir haben mehr!** |

### 🎯 Fazit

**Was relevant ist:**
- ✅ **Nichts kritisches** - unsere Implementierung ist vollständiger
- ⚠️ **Quarantäne** - optional für später, nicht kritisch für MVP

**Was nicht relevant ist:**
- ❌ Docker Volume für Uploads (nicht nötig, da PHP auf Host)
- ❌ `expose` statt `ports` (unser Ansatz ist korrekt)
- ❌ "Kein automatisches Scannen" (wir haben es implementiert)

### 💡 Empfehlung

**Aktuell:** Alles korrekt implementiert für MVP ✅

**Optional später:**
- Quarantäne-Logik (wenn höhere Sicherheit gewünscht)
- Sofort-Scan für kleine Dateien (< 5MB)
- Admin-Benachrichtigung bei Infected


