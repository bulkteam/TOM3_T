-- TOM3 - Simplify VAT Registration
-- USt-IDs werden direkt an Organisationen gebunden, nicht an Adressen
-- Adressen sind nur optionaler Kontext

-- ============================================================================
-- ÄNDERUNG: address_uuid optional machen
-- ============================================================================
ALTER TABLE org_vat_registration 
MODIFY COLUMN address_uuid CHAR(36) NULL COMMENT 'Optional: Verknüpfung zu Adresse für Kontext (nicht zwingend)';

-- Entferne NOT NULL Constraint vom Foreign Key (wird durch MODIFY COLUMN bereits erledigt)
-- Der Foreign Key bleibt bestehen, aber erlaubt jetzt NULL

-- ============================================================================
-- OPTIONAL: location_type hinzufügen für Kontext
-- ============================================================================
ALTER TABLE org_vat_registration 
ADD COLUMN location_type VARCHAR(50) COMMENT 'Optional: HQ | Branch | Subsidiary | SalesOffice | etc. (für Kontext)';

-- ============================================================================
-- HINWEIS
-- ============================================================================
-- Die USt-ID gehört zur Organisation, nicht zur Adresse!
-- Adressen sind nur optionaler Kontext.
-- 
-- Beispiel:
-- - Müller GmbH → DE123456789 (Primär, Hauptsitz)
-- - Müller GmbH → ATU98765432 (Niederlassung Wien)
-- - Müller GmbH → FRXX123456789 (Niederlassung Paris)
--
-- Später können USt-IDs auch an org_relation gebunden werden,
-- wenn komplexe Organisationsstrukturen (Holding, Tochter) vorhanden sind.



