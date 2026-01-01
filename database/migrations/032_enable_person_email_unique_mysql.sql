-- TOM3 - Migration 032: E-Mail UNIQUE Constraint für Personen aktivieren
-- Aktiviert den UNIQUE Constraint auf person.email
-- WICHTIG: Vorher müssen eventuelle Duplikate entfernt werden!

-- ============================================================================
-- Schritt 1: Prüfe auf bestehende E-Mail-Duplikate
-- ============================================================================
-- Diese Query zeigt alle E-Mail-Duplikate (falls vorhanden):
-- SELECT email, COUNT(*) as count 
-- FROM person 
-- WHERE email IS NOT NULL AND email != '' 
-- GROUP BY email 
-- HAVING count > 1;

-- ============================================================================
-- Schritt 2: E-Mail UNIQUE Constraint hinzufügen
-- ============================================================================
-- Hinweis: Falls Duplikate vorhanden sind, muss dieser Schritt manuell nach Bereinigung ausgeführt werden
ALTER TABLE person 
ADD UNIQUE KEY uq_person_email (email);

-- ============================================================================
-- Schritt 3: Index auf E-Mail bleibt erhalten (wird automatisch durch UNIQUE KEY erstellt)
-- ============================================================================
