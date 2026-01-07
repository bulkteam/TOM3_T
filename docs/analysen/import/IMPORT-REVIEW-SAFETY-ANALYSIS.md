# Import Review - Sicherheitsanalyse

## Frage
Kann es zu Fehlern kommen, wenn auf der Review-Seite nur ein Datensatz geändert wird (z.B. Name oder Status auf SKIP und wieder zurück), können Änderungen bereits einen Eintrag erzeugen oder beim Import stören?

## Analyse

### 1. Korrekturen speichern (`saveCorrections`)

**Was passiert:**
- Aktualisiert nur `corrections_json` Feld in `org_import_staging`
- Prüft ob Row bereits importiert ist (`import_status === 'imported'`)
- Erstellt KEINE Organisationen oder Cases

**Sicherheit:**
- ✅ Keine ungewollten Einträge werden erstellt
- ✅ Änderungen werden nur in Staging-Tabelle gespeichert
- ✅ Beim Import werden `corrections_json` mit `mapped_data` gemerged zu `effective_data`

**Potenzielle Probleme:**
- ⚠️ Race Condition: Wenn während des Imports Korrekturen gespeichert werden, könnte die alte Version importiert werden
- ✅ Abgemildert durch: Import liest `corrections_json` direkt vor dem Import

### 2. Disposition ändern (`setDisposition`)

**Was passiert:**
- Aktualisiert nur `disposition` Feld in `org_import_staging`
- Prüft ob Row bereits importiert ist (`import_status === 'imported'`)
- Erstellt KEINE Organisationen oder Cases

**Sicherheit:**
- ✅ Keine ungewollten Einträge werden erstellt
- ✅ Änderungen werden nur in Staging-Tabelle gespeichert

**Potenzielle Probleme:**
- ⚠️ **KRITISCH**: Race Condition beim Import:
  - `commitBatch()` lädt alle approved Rows in eine Liste (Zeile 67)
  - Dann verarbeitet es sie in einer Schleife (Zeile 81)
  - Zwischen Laden und Verarbeiten kann `disposition` auf `skip` geändert werden
  - Row wird trotzdem importiert, weil sie bereits in der Liste ist
  - `commitRow()` prüft nur `import_status`, aber nicht ob `disposition` noch `approved` ist

### 3. Import-Prozess (`commitBatch`)

**Aktueller Ablauf:**
1. Lade alle approved Rows (`listApprovedRows()`)
2. Verarbeite jede Row in Schleife
3. `commitRow()` prüft nur `import_status`, nicht `disposition`

**Problem:**
- Wenn zwischen Schritt 1 und 2 eine Row auf `skip` gesetzt wird, wird sie trotzdem importiert

## Empfohlene Lösung

### 1. Zusätzliche Prüfung in `commitRow()`

```php
private function commitRow(array $row, string $userId, bool $startWorkflows, array &$stats): array
{
    // Prüfe, ob bereits importiert
    if ($row['import_status'] === 'imported') {
        return ['status' => 'skipped', 'reason' => 'ALREADY_IMPORTED'];
    }
    
    // NEU: Prüfe, ob disposition noch approved ist (verhindert Race Condition)
    if ($row['disposition'] !== 'approved') {
        return [
            'status' => 'skipped',
            'reason' => 'DISPOSITION_NOT_APPROVED',
            'current_disposition' => $row['disposition']
        ];
    }
    
    // ... restlicher Code
}
```

### 2. Optional: Row-Locking beim Import

```php
private function listApprovedRows(string $batchUuid): array
{
    $stmt = $this->db->prepare("
        SELECT ...
        FROM org_import_staging
        WHERE import_batch_uuid = :batch_uuid
          AND disposition = 'approved'
          AND import_status != 'imported'
        ORDER BY row_number
        FOR UPDATE  -- Lock Rows während Import
    ");
    // ...
}
```

**Nachteil:** Könnte zu Deadlocks führen, wenn gleichzeitig Änderungen gemacht werden.

## Fazit

### Aktuelle Sicherheit:
- ✅ Korrekturen und Disposition-Änderungen erzeugen KEINE ungewollten Einträge
- ✅ Änderungen werden korrekt beim Import berücksichtigt (corrections_json wird gemerged)
- ⚠️ **Race Condition möglich**: Disposition-Änderung während Import kann ignoriert werden

### Empfehlung:
1. ✅ Zusätzliche Prüfung in `commitRow()` hinzufügen (einfach, sicher)
2. ⚠️ Row-Locking optional (könnte zu Problemen führen)


