# USt-ID Verwaltung - Konzept V2 (Überarbeitet)

## Problemstellung (Überarbeitet)

Eine Organisation kann mehrere USt-IDs haben:
- Hauptsitz in DE → DE123456789
- Niederlassung in AT → ATU98765432
- Niederlassung in FR → FRXX123456789

**Wichtig:** Die USt-ID gehört zur **Organisation**, nicht zur Adresse!

## Überarbeitetes Datenmodell

### 1. Vereinfachte Tabelle `org_vat_registration`

**Entfernt:**
- `address_uuid` (nicht mehr nötig)
- Adressen sind nur Kontext, nicht die steuerliche Entität

**Beibehalten:**
- `org_uuid` (direkt an Organisation)
- `vat_id` (die USt-ID)
- `country_code` (Land der USt-ID)
- `valid_from` / `valid_to` (Zeitbezug)
- `is_primary_for_country` (Primäre USt-ID pro Land)
- `notes` (für Hinweise wie "Ausland", "Primär", etc.)

**Optional hinzufügen:**
- `location_type` VARCHAR(50) - "HQ", "Branch", "Subsidiary" (für Kontext)
- `address_uuid` CHAR(36) NULL - Optional: Verknüpfung zu Adresse für Kontext (aber nicht zwingend)

### 2. Erweiterung für Organisationsstrukturen

Wenn `org_relation` vorhanden ist (Holding, Niederlassung, Tochter):
- USt-ID kann optional an `relation_uuid` gebunden werden
- Dann gehört die USt-ID zur Relation (z.B. "Niederlassung Wien")
- Aber auch hier: Die Relation ist die steuerliche Einheit, nicht die Adresse

## Logik

### Rechnungsstellung

1. **Rechnungsempfänger = Organisation**
   - User wählt Organisation
   - System sucht USt-ID für diese Organisation

2. **USt-ID-Suche:**
   ```sql
   SELECT vat_id, country_code
   FROM org_vat_registration
   WHERE org_uuid = :org_uuid
     AND (valid_to IS NULL OR valid_to >= CURDATE())
   ORDER BY is_primary_for_country DESC, valid_from DESC
   LIMIT 1;
   ```

3. **Optional: Filter nach Land**
   - Wenn Rechnungsadresse in AT → Suche AT-USt-ID
   - Wenn Rechnungsadresse in DE → Suche DE-USt-ID

### UI-Logik

**Organisation - Überblick:**
```
Organisation: Müller Maschinenbau GmbH

USt-Registrierungen:
• DE123456789 (DE, Primär, Hauptsitz)
• ATU98765432 (AT, Niederlassung Wien)
• FRXX123456789 (FR, Niederlassung Paris)
```

**Keine Adressen-Auswahl nötig!**
- USt-IDs werden direkt der Organisation zugeordnet
- Adressen sind nur Kontext (optional anzeigbar)

## Migration

1. Entferne `address_uuid` NOT NULL Constraint
2. Mache `address_uuid` optional (NULL erlaubt)
3. Optional: Füge `location_type` hinzu
4. Optional: Füge `relation_uuid` hinzu (für Organisationsstrukturen)

## Vorteile

✅ **Einfacher:** Keine Adressen-Auswahl nötig
✅ **Klarer:** USt-ID gehört zur Organisation
✅ **Flexibler:** Adressen sind optionaler Kontext
✅ **Erweiterbar:** Später für Organisationsstrukturen nutzbar


