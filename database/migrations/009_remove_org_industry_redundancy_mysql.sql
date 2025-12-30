-- TOM3 - Remove org_industry Redundancy
-- Entfernt die redundante org_industry Tabelle, da wir jetzt direkte Felder verwenden
-- (org.industry_main_uuid und org.industry_sub_uuid)

-- ============================================================================
-- HINWEIS: Diese Migration entfernt die org_industry Tabelle
-- ============================================================================
-- Die org_industry Tabelle wurde durch direkte Felder in der org Tabelle ersetzt:
-- - org.industry_main_uuid (Hauptklasse)
-- - org.industry_sub_uuid (Unterklasse)
--
-- Dies eliminiert Redundanzen und vereinfacht das Datenmodell.
-- Eine Organisation hat jetzt genau eine Hauptklasse und optional eine Unterklasse.

-- ============================================================================
-- ENTFERNEN DER REDUNDANTEN TABELLE
-- ============================================================================
DROP TABLE IF EXISTS org_industry;



