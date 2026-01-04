-- TOM3 - Migration 033: Duplikaten-Prüfung Ergebnisse-Tabelle
-- Speichert die Ergebnisse der täglichen Duplikaten-Prüfung

CREATE TABLE IF NOT EXISTS duplicate_check_results (
    check_id INT AUTO_INCREMENT PRIMARY KEY,
    check_date DATETIME NOT NULL,
    org_duplicates INT NOT NULL DEFAULT 0 COMMENT 'Anzahl gefundener Org-Duplikat-Paare',
    person_duplicates INT NOT NULL DEFAULT 0 COMMENT 'Anzahl gefundener Person-Duplikat-Paare',
    total_pairs INT NOT NULL DEFAULT 0 COMMENT 'Gesamtanzahl Duplikat-Paare',
    results_json JSON COMMENT 'Vollständige Ergebnisse als JSON (org_duplicates, person_duplicates Arrays)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_check_date (check_date),
    INDEX idx_total_pairs (total_pairs)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE duplicate_check_results COMMENT = 'Ergebnisse der täglichen Duplikaten-Prüfung';


