# Button-Farb-Konzept - TOM3

## Farbzuordnung

### ✅ Speichern / Erstellen / Anlegen
**Farbe**: Grün (`btn-success`)
**Verwendung**: Alle Submit-Buttons für:
- "Person erstellen"
- "Person speichern"
- "Organisation anlegen"
- "Speichern" (in allen Formularen)
- "Änderungen speichern"

**Beispiele**:
```html
<button type="submit" class="btn btn-success">Person erstellen</button>
<button type="submit" class="btn btn-success">Änderungen speichern</button>
<button type="submit" class="btn btn-success">Organisation anlegen</button>
```

### 🔵 Primäre Aktionen (Nicht-Submit)
**Farbe**: Blau (`btn-primary`)
**Verwendung**: 
- "Neue Person" (Öffnet Modal)
- "Neue Organisation" (Öffnet Modal)
- "Filter anwenden"
- "Bearbeiten" (Wechselt in Edit-Modus)
- "Anmelden" (Login)

**Beispiele**:
```html
<button class="btn btn-primary" id="btn-create-person">+ Neue Person</button>
<button class="btn btn-primary" id="btn-edit-person">Bearbeiten</button>
<button class="btn btn-primary" id="btn-apply-filters">Filter anwenden</button>
```

### ⚪ Sekundäre Aktionen
**Farbe**: Grau (`btn-secondary`)
**Verwendung**:
- "Abbrechen"
- "Zurücksetzen"
- "Schließen"

**Beispiele**:
```html
<button type="button" class="btn btn-secondary" id="btn-cancel">Abbrechen</button>
<button class="btn btn-secondary" id="btn-reset-filters">Filter zurücksetzen</button>
```

### 🔴 Gefährliche Aktionen
**Farbe**: Rot (`btn-danger`)
**Verwendung**:
- "Löschen"
- "Archivieren"
- "Entfernen"

**Beispiele**:
```html
<button class="btn btn-danger" onclick="deleteItem()">Löschen</button>
```

### 🟡 Warnungen
**Farbe**: Orange (`btn-warning`)
**Verwendung**:
- Warnungen
- Bestätigungen bei kritischen Aktionen

## Regel

**Wichtig**: 
- **Alle Submit-Buttons** (type="submit") sollten **grün** (`btn-success`) sein
- **Alle "Bearbeiten"-Buttons** (öffnen Edit-Modus) sollten **blau** (`btn-primary`) sein
- **Alle "Abbrechen"-Buttons** sollten **grau** (`btn-secondary`) sein

## Aktueller Status

✅ **Korrekt**:
- `index.html`: Alle Submit-Buttons verwenden `btn-success`
- `person-forms.js`: Submit-Buttons verwenden jetzt `btn-success` (nach Refactoring)

❌ **Zu prüfen**:
- `login.php`: Login-Button verwendet `btn-primary` (könnte auch `btn-success` sein, da es ein Submit ist)


