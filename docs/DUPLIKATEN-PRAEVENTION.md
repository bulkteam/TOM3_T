# Duplikaten-Prävention - TOM3

## Aktueller Status

### ✅ Personen (Person)

**Datenbank-Constraints:**
- ❌ **UNIQUE Constraint auf `email` ist auskommentiert** (`024_extend_person_table_mysql.sql`, Zeile 81)
- ⚠️ **Hinweis in Migration**: "Falls bereits Daten vorhanden sind, müssen Duplikate zuerst entfernt werden"

**Service-Layer:**
- ❌ **Keine explizite Duplikaten-Prüfung** in `PersonService::createPerson()`
- ⚠️ Wenn der UNIQUE Constraint aktiv wäre, würde die Datenbank einen Fehler werfen, aber es gibt keine benutzerfreundliche Fehlermeldung

**Aktuelles Verhalten:**
- Wenn eine Person mit einer bereits existierenden E-Mail erstellt wird, wird ein Datenbankfehler geworfen (falls Constraint aktiv)
- Keine Prüfung auf Duplikate basierend auf Name (first_name + last_name)

### ❌ Organisationen (Org)

**Datenbank-Constraints:**
- ❌ **KEIN UNIQUE Constraint auf `name`**
- ❌ **KEIN UNIQUE Constraint auf andere Felder**

**Service-Layer:**
- ❌ **Keine explizite Duplikaten-Prüfung** in `OrgService::createOrg()`

**Aktuelles Verhalten:**
- ⚠️ **Es können mehrere Organisationen mit demselben Namen erstellt werden**
- Keine automatische Duplikaten-Prävention

### ✅ Vergleich: UserService (gutes Beispiel)

**Datenbank-Constraints:**
- ✅ UNIQUE Constraint auf `email` in `users` Tabelle

**Service-Layer:**
- ✅ **Explizite Duplikaten-Prüfung** in `UserService::createUser()`:
```php
// Prüfe ob Email bereits existiert
$stmt = $this->db->prepare("SELECT user_id FROM users WHERE email = :email");
$stmt->execute(['email' => $data['email']]);
if ($stmt->fetch()) {
    throw new \InvalidArgumentException('Ein User mit dieser Email existiert bereits');
}
```

## Empfehlungen

### 1. Personen (Person)

#### Option A: UNIQUE Constraint aktivieren + Service-Prüfung
```sql
-- Migration: Duplikate zuerst entfernen, dann Constraint aktivieren
ALTER TABLE person ADD UNIQUE KEY uq_person_email (email);
```

```php
// In PersonService::createPerson()
if (!empty($data['email'])) {
    $stmt = $this->db->prepare("SELECT person_uuid FROM person WHERE email = :email");
    $stmt->execute(['email' => $data['email']]);
    if ($stmt->fetch()) {
        throw new \InvalidArgumentException('Eine Person mit dieser E-Mail existiert bereits');
    }
}
```

#### Option B: Fuzzy-Matching auf Name
```php
// Prüfe auf ähnliche Namen (fuzzy matching)
if (!empty($data['first_name']) && !empty($data['last_name'])) {
    $stmt = $this->db->prepare("
        SELECT person_uuid, first_name, last_name 
        FROM person 
        WHERE LOWER(first_name) = LOWER(:first_name) 
        AND LOWER(last_name) = LOWER(:last_name)
        AND is_active = 1
    ");
    $stmt->execute([
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name']
    ]);
    $existing = $stmt->fetch();
    if ($existing) {
        throw new \InvalidArgumentException(
            "Eine Person mit dem Namen '{$existing['first_name']} {$existing['last_name']}' existiert bereits"
        );
    }
}
```

### 2. Organisationen (Org)

#### Option A: UNIQUE Constraint auf Name + Org-Kind
```sql
-- Verhindert Duplikate bei gleichem Namen UND gleichem Typ
ALTER TABLE org ADD UNIQUE KEY uq_org_name_kind (name(255), org_kind);
```

**Problem**: Zwei Organisationen mit demselben Namen, aber unterschiedlichem `org_kind` (z.B. "ACME" als Kunde und "ACME" als Lieferant) wären erlaubt.

#### Option B: Service-Prüfung mit Warnung
```php
// In OrgService::createOrg()
$stmt = $this->db->prepare("
    SELECT org_uuid, name, org_kind 
    FROM org 
    WHERE LOWER(name) = LOWER(:name)
    AND is_active = 1
");
$stmt->execute(['name' => $data['name']]);
$existing = $stmt->fetchAll();
if (!empty($existing)) {
    // Warnung, aber nicht blockieren (könnte Absicht sein)
    // Oder: Exception werfen, wenn exakt gleicher Name UND gleicher org_kind
    foreach ($existing as $org) {
        if ($org['org_kind'] === $data['org_kind']) {
            throw new \InvalidArgumentException(
                "Eine Organisation mit dem Namen '{$data['name']}' und Typ '{$data['org_kind']}' existiert bereits"
            );
        }
    }
}
```

#### Option C: Fuzzy-Matching mit Vorschlag
```php
// Prüfe auf ähnliche Namen (Levenshtein-Distanz oder LIKE)
$stmt = $this->db->prepare("
    SELECT org_uuid, name, org_kind 
    FROM org 
    WHERE LOWER(name) LIKE LOWER(:name_pattern)
    AND is_active = 1
");
$stmt->execute(['name_pattern' => '%' . $data['name'] . '%']);
$similar = $stmt->fetchAll();
if (!empty($similar)) {
    // Rückgabe als Warnung, nicht als Fehler
    // Frontend kann dann fragen: "Möchten Sie wirklich eine neue Organisation erstellen?"
}
```

## Implementierungsvorschlag

### Priorität 1: Personen - E-Mail-Duplikate verhindern
1. ✅ UNIQUE Constraint auf `email` aktivieren (nach Duplikat-Bereinigung)
2. ✅ Service-Prüfung in `PersonService::createPerson()` hinzufügen
3. ✅ Benutzerfreundliche Fehlermeldung in API zurückgeben

### Priorität 2: Personen - Name-Duplikate prüfen (optional)
1. ⚠️ Fuzzy-Matching auf Name (als Warnung, nicht als Blockierung)
2. ⚠️ Frontend kann dann fragen: "Person existiert bereits - wirklich neu erstellen?"

### Priorität 3: Organisationen - Name-Duplikate prüfen
1. ⚠️ Service-Prüfung mit Warnung (nicht blockierend, da gleicher Name bei unterschiedlichen `org_kind` erlaubt sein könnte)
2. ⚠️ Oder: UNIQUE Constraint auf `(name, org_kind)` wenn Duplikate absolut verhindert werden sollen

## Frontend-Integration

### Vorschlag: Duplikaten-Prüfung während Eingabe
```javascript
// In person-forms.js oder org-forms.js
async checkDuplicate() {
    const email = this.form.querySelector('#email').value;
    if (email) {
        const existing = await window.API.searchPersons(email);
        if (existing.length > 0) {
            // Zeige Warnung: "Person mit dieser E-Mail existiert bereits"
        }
    }
}
```

## Zusammenfassung

**Aktuell:**
- ❌ Keine explizite Duplikaten-Prävention bei Personen oder Organisationen
- ⚠️ Nur Datenbank-Constraints würden greifen (und diese sind teilweise auskommentiert)

**Empfohlen:**
- ✅ Personen: E-Mail-UNIQUE Constraint aktivieren + Service-Prüfung
- ⚠️ Personen: Optional Name-Prüfung (als Warnung)
- ⚠️ Organisationen: Service-Prüfung mit Warnung (nicht blockierend)
