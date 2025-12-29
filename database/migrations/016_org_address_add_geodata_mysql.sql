-- TOM3 - Add geodata fields to org_address for map display
-- Fügt Latitude und Longitude Felder zur Adress-Tabelle hinzu

ALTER TABLE org_address 
ADD COLUMN latitude DECIMAL(10, 8) NULL COMMENT 'Breitengrad (Latitude) für Kartenanzeige' AFTER country,
ADD COLUMN longitude DECIMAL(11, 8) NULL COMMENT 'Längengrad (Longitude) für Kartenanzeige' AFTER latitude;

CREATE INDEX idx_org_address_geodata ON org_address(latitude, longitude);


