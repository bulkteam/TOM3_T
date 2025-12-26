-- TOM3 - Org Communication Channels
-- Erweitert die Org-Struktur um Kommunikationskanäle (Email, Telefon, Fax, etc.)

-- ============================================================================
-- ORG COMMUNICATION CHANNELS (Email, Telefon, Fax, etc.)
-- ============================================================================
CREATE TABLE org_communication_channel (
    channel_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    channel_type VARCHAR(50) NOT NULL COMMENT 'email | phone_main | fax | other',
    -- Für Telefon/Fax: Vorwahl und Nummer getrennt
    country_code VARCHAR(10) COMMENT 'Ländervorwahl (z.B. +49, 0049)',
    area_code VARCHAR(20) COMMENT 'Ortsvorwahl (z.B. 030, 040)',
    number VARCHAR(50) COMMENT 'Hauptnummer (ohne Vorwahlen)',
    extension VARCHAR(20) COMMENT 'Durchwahl',
    -- Für Email
    email_address VARCHAR(255) COMMENT 'E-Mail-Adresse (nur bei channel_type=email)',
    -- Metadaten
    label VARCHAR(100) COMMENT 'Bezeichnung (z.B. "Zentrale", "Support-Hotline", "Geschäftsführung")',
    is_primary TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hauptkanal für diesen Typ',
    is_public TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Öffentlich verfügbar (z.B. auf Website)',
    notes TEXT COMMENT 'Zusätzliche Hinweise (z.B. "Mo-Fr 9-17 Uhr", "Nur für Notfälle")',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_channel_org ON org_communication_channel(org_uuid);
CREATE INDEX idx_org_channel_type ON org_communication_channel(channel_type);
CREATE INDEX idx_org_channel_primary ON org_communication_channel(org_uuid, channel_type, is_primary);
CREATE INDEX idx_org_channel_email ON org_communication_channel(email_address);

-- ============================================================================
-- HINWEISE ZUR STRUKTUR:
-- ============================================================================
-- 
-- 1. VORWAHLEN:
--    - country_code: Ländervorwahl (z.B. "+49" oder "0049")
--    - area_code: Ortsvorwahl (z.B. "030" für Berlin, "040" für Hamburg)
--    - number: Hauptnummer ohne Vorwahlen
--    - extension: Durchwahl (optional)
--    
--    Beispiel: +49 30 12345678 Durchwahl 123
--    -> country_code: "+49"
--    -> area_code: "030"
--    -> number: "12345678"
--    -> extension: "123"
--
-- 2. WARUM NICHT IM DIALERFORMAT?
--    - Dialerformat ist softwareabhängig (z.B. "tel:+493012345678" für HTML5)
--    - Kann bei Bedarf aus den Einzelteilen generiert werden
--    - Ermöglicht flexible Darstellung (mit/ohne Klammern, Leerzeichen, etc.)
--
-- 3. CHANNEL TYPES:
--    - email: E-Mail-Adresse (email_address wird verwendet)
--    - phone_main: Hauptnummer/Zentrale
--    - fax: Faxnummer
--    - other: Sonstige (z.B. Skype, Teams, etc.)
--
-- 4. IS_PRIMARY:
--    - Pro channel_type kann es einen "primary" Kanal geben
--    - z.B. eine primäre E-Mail und eine primäre Telefonnummer
--    - Wird für Quick-Actions verwendet (z.B. "Anrufen", "E-Mail senden")
--
-- 5. IS_PUBLIC:
--    - Markiert, ob der Kanal öffentlich verfügbar ist
--    - Nützlich für Exporte oder öffentliche Darstellungen

