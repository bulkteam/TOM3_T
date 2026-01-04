# Security Phase 2.3 - Transaktionen bei Multi-Step Writes

## Übersicht

Transaktionen stellen sicher, dass Multi-Step-Operationen atomar sind. Bei Fehlern wird alles zurückgerollt, verhindert Inkonsistenzen.

### Problem
- Entities wie Org ändern mehrere Tabellen
- Keine Transaktionen → Inkonsistenz möglich
- Audit/Activity-Logs werden auch bei Fehlern geschrieben

### Lösung
- Service-Layer Methoden mit Transaktionen
- Audit/Activity nach Commit
- Rollback bei Fehlern

---

## TransactionHelper

### executeInTransaction()
```php
use TOM\Infrastructure\Database\TransactionHelper;

$result = TransactionHelper::executeInTransaction($db, function($db) {
    // Alle Datenbank-Operationen hier
    $stmt = $db->prepare("INSERT INTO org (...) VALUES (...)");
    $stmt->execute([...]);
    
    // Weitere Operationen...
    
    return $result; // Wird zurückgegeben
});
// Transaktion wird automatisch committed oder gerollt
```

**Features:**
- Automatisches Commit bei Erfolg
- Automatisches Rollback bei Fehler
- Unterstützt verschachtelte Transaktionen (prüft ob bereits in Transaktion)
- Wirft Exception weiter (für Fehlerbehandlung)

### executeMultipleInTransaction()
```php
$results = TransactionHelper::executeMultipleInTransaction($db, [
    function($db) { return operation1($db); },
    function($db) { return operation2($db); },
    function($db) { return operation3($db); }
]);
// Alle Operationen in einer Transaktion
```

---

## Implementierte Services

### OrgCrudService

**createOrg()**
- ✅ Transaktion um INSERT
- ✅ Audit-Trail nach Commit
- ✅ Event-Publishing nach Commit

**updateOrg()**
- ✅ Transaktion um UPDATE
- ✅ Audit-Trail nach Commit
- ✅ Event-Publishing nach Commit

### PersonService

**createPerson()**
- ✅ Transaktion um INSERT
- ✅ Audit-Trail nach Commit
- ✅ Event-Publishing nach Commit

**updatePerson()**
- ✅ Transaktion um UPDATE
- ✅ Audit-Trail nach Commit
- ✅ Event-Publishing nach Commit

### OrgArchiveService

**archiveOrg()**
- ✅ Transaktion um UPDATE
- ✅ Audit-Trail nach Commit
- ✅ Event-Publishing nach Commit

**unarchiveOrg()**
- ✅ Transaktion um UPDATE
- ✅ Audit-Trail nach Commit
- ✅ Event-Publishing nach Commit

### ImportCommitService

**commitRow()**
- ✅ Bereits Transaktion pro Row (vorhanden)
- ✅ Mehrere Tabellen: org, org_address, org_communication_channel, org_vat_registration

### DocumentService

**uploadAndAttach()**
- ✅ Bereits Transaktion (vorhanden)
- ✅ Mehrere Tabellen: blobs, documents, document_attachments

---

## Verwendung

### Einfache Transaktion
```php
use TOM\Infrastructure\Database\TransactionHelper;

$result = TransactionHelper::executeInTransaction($this->db, function($db) use ($data) {
    $stmt = $db->prepare("INSERT INTO org (...) VALUES (...)");
    $stmt->execute([...]);
    
    // Weitere Operationen...
    
    return $this->getOrg($uuid);
});
```

### Mit Fehlerbehandlung
```php
try {
    $result = TransactionHelper::executeInTransaction($this->db, function($db) use ($data) {
        // Operationen...
    });
    
    // Nach Commit: Audit-Trail, Events, etc.
    $this->logCreateAuditTrail(...);
    $this->publishEntityEvent(...);
    
    return $result;
} catch (\Exception $e) {
    // Transaktion wurde bereits zurückgerollt
    throw $e;
}
```

### Verschachtelte Transaktionen
```php
// Äußere Transaktion
TransactionHelper::executeInTransaction($this->db, function($db) {
    // Innere Transaktion (wird erkannt, keine neue Transaktion)
    TransactionHelper::executeInTransaction($db, function($db) {
        // Operationen...
    });
});
```

---

## Best Practices

### 1. Audit-Trail nach Commit
```php
// ✅ RICHTIG: Audit-Trail nach Commit
$org = TransactionHelper::executeInTransaction($this->db, function($db) {
    // INSERT/UPDATE...
    return $this->getOrg($uuid);
});

// Nach Commit
$this->logCreateAuditTrail(...);
```

```php
// ❌ FALSCH: Audit-Trail in Transaktion
TransactionHelper::executeInTransaction($this->db, function($db) {
    // INSERT/UPDATE...
    $this->logCreateAuditTrail(...); // Wird bei Rollback verloren
});
```

### 2. Event-Publishing nach Commit
```php
// ✅ RICHTIG: Events nach Commit
$org = TransactionHelper::executeInTransaction($this->db, function($db) {
    // INSERT/UPDATE...
    return $this->getOrg($uuid);
});

// Nach Commit
$this->publishEntityEvent(...);
```

### 3. Fehlerbehandlung
```php
try {
    $result = TransactionHelper::executeInTransaction($this->db, function($db) {
        // Operationen...
    });
} catch (\PDOException $e) {
    // Transaktion wurde bereits zurückgerollt
    // Logge Fehler, aber wirf Exception weiter
    error_log("Database error: " . $e->getMessage());
    throw $e;
}
```

---

## Vorteile

1. **Atomarität:** Alle Operationen oder keine
2. **Konsistenz:** Keine Inkonsistenzen bei Fehlern
3. **Isolation:** Andere Transaktionen sehen keine unvollständigen Änderungen
4. **Durabilität:** Committed Änderungen sind dauerhaft

---

## Nächste Schritte

1. ✅ TransactionHelper erstellt
2. ✅ OrgCrudService angepasst
3. ✅ PersonService angepasst
4. ✅ OrgArchiveService angepasst
5. ⏳ Weitere Services prüfen (optional)
   - OrgVatService::updateVatRegistration() (wenn is_primary_for_country mehrere Tabellen betrifft)
   - OrgCommunicationService (wenn mehrere Kanäle gleichzeitig erstellt werden)
   - OrgRelationService (wenn mehrere Relationen gleichzeitig erstellt werden)

