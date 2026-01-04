-- TOM3 - Add additional field to org_address for address supplements (e.g., "Gebäude 5")
-- Erweitert die Adress-Tabelle um ein Feld für Adresszusätze

ALTER TABLE org_address 
ADD COLUMN address_additional VARCHAR(255) NULL COMMENT 'Adresszusatz (z.B. "Gebäude 5", "Eingang B", "3. Stock")' AFTER street;





