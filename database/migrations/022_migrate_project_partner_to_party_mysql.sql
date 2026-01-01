-- ============================================================================
-- TOM3 Migration 022: Migration von project_partner → project_party
--                    und project_stakeholder → project_person
-- ============================================================================
-- Migriert bestehende Daten von den alten Tabellen zu den neuen Tabellen.
-- 
-- WICHTIG: Diese Migration sollte nur ausgeführt werden, wenn:
-- 1. Migration 021 erfolgreich war
-- 2. Die neuen Tabellen existieren
-- 3. Du sicher bist, dass die Daten korrekt migriert werden sollen
-- 
-- Die alten Tabellen (project_partner, project_stakeholder) bleiben erhalten
-- für Backward Compatibility. Sie können später entfernt werden, wenn alle
-- Code-Stellen auf die neuen Tabellen umgestellt sind.
-- ============================================================================

-- ============================================================================
-- Schritt 1: Migriere project_partner → project_party
-- ============================================================================
-- Mapping der relation-Werte zu party_role ENUM:
-- - 'delivers' → 'supplier'
-- - 'advises' → 'consultant'
-- - 'participates' → 'partner'
-- 
-- Falls andere Werte vorhanden sind, werden sie als 'partner' behandelt.

INSERT INTO project_party (
    party_uuid,
    project_uuid,
    org_uuid,
    party_role,
    notes,
    created_at
)
SELECT 
    UUID() as party_uuid,
    project_uuid,
    org_uuid,
    CASE 
        WHEN relation = 'delivers' THEN 'supplier'
        WHEN relation = 'advises' THEN 'consultant'
        WHEN relation = 'participates' THEN 'partner'
        ELSE 'partner'  -- Fallback für unbekannte Werte
    END as party_role,
    CONCAT_WS('\n', 
        IF(scope IS NOT NULL, CONCAT('Scope: ', scope), NULL),
        IF(contract_ref IS NOT NULL, CONCAT('Contract: ', contract_ref), NULL)
    ) as notes,
    NOW() as created_at
FROM project_partner
WHERE NOT EXISTS (
    -- Verhindere Duplikate (falls Migration mehrfach ausgeführt wird)
    SELECT 1 FROM project_party pp
    WHERE pp.project_uuid = project_partner.project_uuid
      AND pp.org_uuid = project_partner.org_uuid
      AND pp.party_role = CASE 
          WHEN project_partner.relation = 'delivers' THEN 'supplier'
          WHEN project_partner.relation = 'advises' THEN 'consultant'
          WHEN project_partner.relation = 'participates' THEN 'partner'
          ELSE 'partner'
      END
);

-- ============================================================================
-- Schritt 2: Migriere project_stakeholder → project_person
-- ============================================================================
-- WICHTIG: Hier müssen wir die project_party_uuid zuordnen!
-- 
-- Strategie:
-- 1. Finde die Person über person_affiliation (welche Firma?)
-- 2. Finde die passende project_party (gleiche Firma, passende Rolle)
-- 3. Falls keine passende project_party gefunden: project_party_uuid = NULL
--    (Person kann trotzdem am Projekt beteiligt sein, z.B. als Owner-Firma)

INSERT INTO project_person (
    project_person_uuid,
    project_uuid,
    person_uuid,
    project_party_uuid,
    project_role,
    start_date,
    end_date,
    created_at
)
SELECT 
    UUID() as project_person_uuid,
    ps.project_uuid,
    ps.person_uuid,
    -- Finde passende project_party über person_affiliation
    (
        SELECT pp.party_uuid
        FROM person_affiliation pa
        JOIN project_party pp ON pp.org_uuid = pa.org_uuid 
                              AND pp.project_uuid = ps.project_uuid
        WHERE pa.person_uuid = ps.person_uuid
          AND (pa.until_date IS NULL OR pa.until_date >= CURDATE())
        ORDER BY 
            -- Bevorzuge aktive Affiliations
            CASE WHEN pa.until_date IS NULL THEN 0 ELSE 1 END,
            -- Bevorzuge primary party
            pp.is_primary DESC
        LIMIT 1
    ) as project_party_uuid,
    -- Mapping der role-Werte zu project_role ENUM
    CASE 
        WHEN ps.role IN ('Decider', 'decision_maker') THEN 'decision_maker'
        WHEN ps.role IN ('Influencer', 'champion') THEN 'champion'
        WHEN ps.role IN ('advisor', 'Advisor', 'Consultant') THEN 'consultant'
        WHEN ps.role IN ('contact_person', 'Contact', 'Account Contact') THEN 'account_contact'
        WHEN ps.role IN ('delivery_contact', 'Delivery Contact') THEN 'delivery_contact'
        WHEN ps.role IN ('auditor', 'Auditor') THEN 'auditor'
        WHEN ps.role IN ('blocker', 'Blocker') THEN 'blocker'
        ELSE 'stakeholder'  -- Fallback
    END as project_role,
    ps.since_date as start_date,
    ps.until_date as end_date,
    NOW() as created_at
FROM project_stakeholder ps
WHERE NOT EXISTS (
    -- Verhindere Duplikate (falls Migration mehrfach ausgeführt wird)
    SELECT 1 FROM project_person pp
    WHERE pp.project_uuid = ps.project_uuid
      AND pp.person_uuid = ps.person_uuid
      AND pp.project_role = CASE 
          WHEN ps.role IN ('Decider', 'decision_maker') THEN 'decision_maker'
          WHEN ps.role IN ('Influencer', 'champion') THEN 'champion'
          WHEN ps.role IN ('advisor', 'Advisor', 'Consultant') THEN 'consultant'
          WHEN ps.role IN ('contact_person', 'Contact', 'Account Contact') THEN 'account_contact'
          WHEN ps.role IN ('delivery_contact', 'Delivery Contact') THEN 'delivery_contact'
          WHEN ps.role IN ('auditor', 'Auditor') THEN 'auditor'
          WHEN ps.role IN ('blocker', 'Blocker') THEN 'blocker'
          ELSE 'stakeholder'
      END
      AND COALESCE(pp.start_date, '1900-01-01') = COALESCE(ps.since_date, '1900-01-01')
);

-- ============================================================================
-- Schritt 3: Optional - Statistiken ausgeben
-- ============================================================================
-- Diese Queries können manuell ausgeführt werden, um die Migration zu prüfen:

-- Anzahl migrierte Projektparteien:
-- SELECT COUNT(*) as migrated_parties FROM project_party;

-- Anzahl migrierte Projektpersonen:
-- SELECT COUNT(*) as migrated_persons FROM project_person;

-- Projektpersonen ohne project_party_uuid (sollten selten sein):
-- SELECT COUNT(*) as persons_without_party 
-- FROM project_person 
-- WHERE project_party_uuid IS NULL;

-- Vergleich: Alte vs. Neue Tabellen
-- SELECT 
--     (SELECT COUNT(*) FROM project_partner) as old_partners,
--     (SELECT COUNT(*) FROM project_party) as new_parties,
--     (SELECT COUNT(*) FROM project_stakeholder) as old_stakeholders,
--     (SELECT COUNT(*) FROM project_person) as new_persons;
