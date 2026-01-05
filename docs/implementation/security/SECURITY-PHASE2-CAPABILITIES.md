# Security Phase 2.1 - Capability-System

## Übersicht

Das Capability-System ermöglicht granularere Berechtigungsprüfungen als das bisherige Role-System.

### Vorher (Role-basiert)
```php
// Zu "literal" - funktioniert nicht für Hierarchien
if ($userRole === 'manager') {
    // Manager kann schreiben
}
if ($userRole === 'user') {
    // User kann auch schreiben, aber muss separat geprüft werden
}
```

### Nachher (Capability-basiert)
```php
// Hierarchisch - admin > manager > user > readonly
if ($permissionService->userHasCapability($userId, 'org.write')) {
    // Manager, User und Admin können schreiben
}
```

---

## Capabilities

### Organisationen
- `org.read` - Organisationen lesen (admin, manager, user, readonly)
- `org.write` - Organisationen erstellen/bearbeiten (admin, manager, user)
- `org.delete` - Organisationen löschen (admin, manager)
- `org.archive` - Organisationen archivieren (admin, manager)
- `org.export` - Organisationen exportieren (admin, manager, user)

### Personen
- `person.read` - Personen lesen (admin, manager, user, readonly)
- `person.write` - Personen erstellen/bearbeiten (admin, manager, user)
- `person.delete` - Personen löschen (admin, manager)

### Import
- `import.upload` - Dateien hochladen (admin, manager, user)
- `import.review` - Import prüfen (admin, manager, user)
- `import.commit` - Import committen (admin, manager)
- `import.delete` - Import löschen (admin, manager)

### Dokumente
- `document.read` - Dokumente lesen (admin, manager, user, readonly)
- `document.upload` - Dokumente hochladen (admin, manager, user)
- `document.delete` - Dokumente löschen (admin, manager)

### Cases/Vorgänge
- `case.read` - Vorgänge lesen (admin, manager, user, readonly)
- `case.write` - Vorgänge erstellen/bearbeiten (admin, manager, user)
- `case.delete` - Vorgänge löschen (admin, manager)

### Projekte
- `project.read` - Projekte lesen (admin, manager, user, readonly)
- `project.write` - Projekte erstellen/bearbeiten (admin, manager, user)
- `project.delete` - Projekte löschen (admin, manager)

### Admin-Funktionen
- `admin.manage_users` - Benutzer verwalten (admin)
- `admin.manage_roles` - Rollen verwalten (admin)
- `admin.view_monitoring` - Monitoring anzeigen (admin, manager)
- `admin.export_data` - Daten exportieren (admin, manager)

---

## Verwendung

### In API-Endpoints

**Vorher:**
```php
$user = requireAuth();
$userRole = $user['roles'][0] ?? null;
if ($userRole !== 'admin' && $userRole !== 'manager') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
```

**Nachher:**
```php
// Einfach und klar
requireCapability('org.write');
```

**Oder mit mehreren Capabilities:**
```php
// Mindestens eine Capability erforderlich
requireAnyCapability(['org.write', 'org.delete']);
```

### In Services

**Vorher:**
```php
$userRole = $permissionService->getUserPermissionRole($userId);
if ($userRole !== 'admin' && $userRole !== 'manager') {
    throw new \RuntimeException('Insufficient permissions');
}
```

**Nachher:**
```php
if (!$permissionService->userHasCapability($userId, 'org.write')) {
    throw new \RuntimeException('Insufficient permissions');
}
```

### Alle Capabilities eines Users abrufen

```php
$capabilities = $permissionService->getUserCapabilities($userId);
// ['org.read', 'org.write', 'person.read', 'person.write', ...]
```

---

## Migration

### Schrittweise Migration

Die alte `userHasPermission()` Methode bleibt für Backward Compatibility erhalten (als `@deprecated` markiert).

**Empfehlung:**
1. Neue Endpoints verwenden `requireCapability()`
2. Bestehende Endpoints können schrittweise migriert werden
3. Alte `requireRole()` Funktion bleibt für einfache Fälle erhalten

### Beispiel-Migration

**orgs.php - POST /api/orgs:**
```php
// Vorher
$user = requireAuth();
$userRole = $user['roles'][0] ?? null;
if (!in_array($userRole, ['admin', 'manager', 'user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Nachher
requireCapability('org.write');
```

**orgs.php - DELETE /api/orgs/{uuid}:**
```php
// Vorher
requireRole('admin'); // Oder 'manager'?

// Nachher
requireCapability('org.delete'); // Klar: admin und manager
```

---

## Vorteile

1. **Granularität:** Feine Kontrolle über Berechtigungen
2. **Hierarchie:** Automatische Hierarchie-Prüfung (admin hat alle)
3. **Wartbarkeit:** Capabilities zentral definiert
4. **Erweiterbarkeit:** Neue Capabilities einfach hinzufügen
5. **Klarheit:** Code ist selbsterklärend (`requireCapability('org.write')`)

---

## Nächste Schritte

1. ✅ Capability-System implementiert
2. ✅ `requireCapability()` Funktion erstellt
3. ⏳ API-Endpoints schrittweise migrieren (optional)
4. ⏳ Frontend: Capability-basierte UI-Anzeige (optional)

