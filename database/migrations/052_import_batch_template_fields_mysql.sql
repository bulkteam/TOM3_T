-- TOM3 - Import Batch Template-Felder
-- Fügt Felder für Template-Matching-Ergebnisse hinzu

ALTER TABLE org_import_batch
ADD COLUMN detected_header_row INT NULL 
    COMMENT 'Automatisch erkannte Header-Zeile',
ADD COLUMN detected_template_uuid CHAR(36) NULL 
    COMMENT 'UUID des automatisch erkannten Templates',
ADD COLUMN detected_template_score DECIMAL(3,2) NULL 
    COMMENT 'Fit-Score des Template-Matchings (0.00-1.00)';

CREATE INDEX idx_batch_template ON org_import_batch(detected_template_uuid);


