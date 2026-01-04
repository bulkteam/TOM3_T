-- ============================================================================
-- TOM3 Migration 023: Alte Projekt-Tabellen löschen
-- ============================================================================
-- Löscht die alten Tabellen project_partner und project_stakeholder,
-- da wir vollständig auf die neuen Tabellen (project_party, project_person)
-- umgestellt haben.
-- 
-- WICHTIG: Diese Migration sollte nur ausgeführt werden, wenn:
-- 1. Migration 021 erfolgreich war (neue Tabellen existieren)
-- 2. Migration 022 ausgeführt wurde (Daten wurden migriert)
-- 3. Alle Code-Stellen auf die neuen Tabellen umgestellt sind
-- 
-- Da wir in der Implementierung sind und nur Testdaten haben,
-- können wir die alten Tabellen direkt löschen.
-- ============================================================================

-- ============================================================================
-- Schritt 1: Foreign Key Constraints entfernen (falls vorhanden)
-- ============================================================================
-- MySQL/MariaDB erfordert, dass Foreign Keys vor dem Löschen der Tabelle entfernt werden.
-- Da die Tabellen möglicherweise keine benannten Constraints haben, versuchen wir es
-- mit DROP TABLE CASCADE (falls unterstützt) oder manuell.

-- ============================================================================
-- Schritt 2: Alte Tabellen löschen
-- ============================================================================

-- Projektpartner (alte Tabelle)
DROP TABLE IF EXISTS project_partner;

-- Projekt-Stakeholder (alte Tabelle)
DROP TABLE IF EXISTS project_stakeholder;

-- ============================================================================
-- Schritt 3: Optional - Prüfung
-- ============================================================================
-- Nach Ausführung dieser Migration sollten folgende Tabellen existieren:
-- - project_party (neu)
-- - project_person (neu)
-- 
-- Und folgende Tabellen sollten NICHT mehr existieren:
-- - project_partner (alt, gelöscht)
-- - project_stakeholder (alt, gelöscht)


