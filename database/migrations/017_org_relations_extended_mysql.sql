-- TOM3 - Extended Org Relations
-- Erweitert org_relation um zusätzliche Metadaten und Relationstypen

-- ============================================================================
-- ERWEITERE ORG_RELATION TABELLE
-- ============================================================================

-- Neue Felder für erweiterte Metadaten
ALTER TABLE org_relation 
ADD COLUMN has_voting_rights TINYINT(1) DEFAULT 0 COMMENT 'Stimmberechtigt bei Beteiligungen',
ADD COLUMN is_direct TINYINT(1) DEFAULT 1 COMMENT 'Direkt oder indirekt (über Zwischengesellschaft)',
ADD COLUMN source TEXT COMMENT 'Quelle/Beleg für die Relation (Link, Datei, etc.)',
ADD COLUMN confidence VARCHAR(20) DEFAULT 'high' COMMENT 'Vertrauenswürdigkeit: high | medium | low',
ADD COLUMN tags VARCHAR(255) COMMENT 'Tags (kommagetrennt, z.B. "Konzernstruktur,Go-to-Market")',
ADD COLUMN is_current TINYINT(1) DEFAULT 1 COMMENT 'Aktuell gültig (false = historisch)';

-- Erweitere relation_type Kommentar mit allen verfügbaren Typen
-- Die Typen werden in der Anwendung verwaltet, hier nur Dokumentation:
-- Konzern & Struktur:
--   parent_of / subsidiary_of (Mutter/Tochter)
--   sister_company (Schwestergesellschaft)
--   holding_of / operating_company_of (Holding/operative Gesellschaft)
--   branch_of / location_of (Niederlassung/Filiale)
--   division_of (Zweigstelle/Standort)
-- Ownership:
--   owns_stake_in (Beteiligung)
--   joint_venture_with (Joint Venture)
--   ubo_of (Ultimate Beneficial Owner)
-- Transaktionen:
--   acquired_from (Übernommen von)
--   merged_with (Fusioniert mit)
--   spun_off_from (Abgespalten von)
--   legal_successor_of (Rechtsnachfolger von)
--   in_liquidation_by (In Liquidation durch)
-- Geschäftlich:
--   customer_of / supplier_of (Kunde/Lieferant)
--   distributor_of / reseller_of (Distributor/Reseller)
--   partner_of (Partner)
--   service_provider_of (Service Provider)
--   logistics_partner_of (Logistikpartner)
--   franchise_giver_of / franchise_taker_of (Franchise)
--   contract_partner_of (Vertragspartner)
--   framework_contract_for (Rahmenvertrag für)
--   implementation_partner_for (Implementierungspartner)
-- Compliance:
--   guarantor_for (Bürge/Garant für)

CREATE INDEX idx_org_relation_current ON org_relation(is_current);
CREATE INDEX idx_org_relation_confidence ON org_relation(confidence);





