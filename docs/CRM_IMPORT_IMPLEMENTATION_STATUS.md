# CRM Import - Implementierungsstatus

## ✅ Erstellt

### Migrationen
- ✅ `041_crm_import_batch_mysql.sql` - Import Batch (First-Class Entity)
- ✅ `042_crm_import_staging_mysql.sql` - Org Import Staging
- ✅ `043_crm_import_duplicates_mysql.sql` - Duplicate Candidates
- ✅ `044_crm_import_persons_mysql.sql` - Person & Employment Staging
- ✅ `045_crm_validation_rules_mysql.sql` - Versionierte Validierungsregeln

### Services (Prototyp)
- ✅ `OrgImportService` - Haupt-Service für Import-Prozess
  - Batch-Erstellung
  - Excel-Analyse
  - Mapping-Konfiguration
  - Staging-Import
  - Fingerprint-Generierung
  
- ✅ `ImportMappingService` - Mapping-Konfiguration
  - Automatischer Mapping-Vorschlag
  - Zeilen-Lesen basierend auf Mapping
  - Transformationen
  
- ✅ `ImportValidationService` - Validierung
  - Versionierte Validierungsregeln
  - Format-Validierung
  - Geodaten-Validierung
  - Telefon-Validierung
  
- ✅ `ImportDedupeService` - Duplikat-Erkennung
  - Duplikat-Suche gegen bestehende DB
  - Match-Score-Berechnung
  - Duplikat-Kandidaten speichern

## 🔄 Nächste Schritte

### 1. Migrationen ausführen
```bash
# Migrationen in Reihenfolge ausführen
php scripts/run-migration-041.php
php scripts/run-migration-042.php
php scripts/run-migration-043.php
php scripts/run-migration-044.php
php scripts/run-migration-045.php
```

### 2. Fehlende Services implementieren
- [ ] `ImportReviewService` - Review & Freigabe
- [ ] `ImportProductionService` - Finaler Import in Produktion
- [ ] `ImportCorrectionService` - Korrekturen (Patch-System)

### 3. API-Endpoints
- [ ] `POST /api/import/upload` - Datei hochladen
- [ ] `POST /api/import/analyze` - Excel analysieren
- [ ] `POST /api/import/mapping` - Mapping speichern
- [ ] `POST /api/import/staging` - In Staging importieren
- [ ] `GET /api/import/batch/{id}` - Batch-Details
- [ ] `GET /api/import/staging/{batchId}` - Staging-Daten
- [ ] `POST /api/import/review` - Review-Entscheidung
- [ ] `POST /api/import/approve` - Freigeben
- [ ] `POST /api/import/production` - Finaler Import

### 4. UI-Komponenten
- [ ] Import-Wizard (3 Schritte)
- [ ] Mapping-Konfigurator
- [ ] Staging-Vorschau
- [ ] Review-Interface
- [ ] Diff-Ansicht

## 📝 Notizen

- Alle Services sind als Prototypen implementiert
- Fingerprint-System ist implementiert
- Validierung mit versionierten Regeln
- Duplikat-Erkennung gegen bestehende DB
- Mapping-Vorschlag basierend auf String-Ähnlichkeit

## ⚠️ Offene Punkte

1. **Raw Data:** Aktuell wird `mapped_data` auch als `raw_data` gespeichert. Sollte Original Excel-Zeile sein.
2. **Header-Erkennung:** Einfache Heuristik, könnte verbessert werden.
3. **Mapping-Templates:** Noch nicht implementiert (Speichern/Laden).
4. **Telefon-Vorwahl-Validierung:** TODO in `ImportValidationService`.
5. **Production Import:** Noch nicht implementiert (zeilenweise Transaktionen).

