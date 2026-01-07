-- TOM3 - Monitoring Metrics
-- Speichert Metriken für Monitoring und Analyse

CREATE TABLE IF NOT EXISTS monitoring_metrics (
    metric_uuid CHAR(36) PRIMARY KEY,
    metric_type VARCHAR(50) NOT NULL COMMENT 'scan_pending_fix, worker_error, etc.',
    metric_name VARCHAR(100) NOT NULL COMMENT 'Eindeutiger Name der Metrik',
    metric_value INT NOT NULL DEFAULT 0 COMMENT 'Wert der Metrik',
    metric_data JSON COMMENT 'Zusätzliche Daten (z.B. Details, Kontext)',
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fixed_at DATETIME COMMENT 'Wann wurde das Problem behoben',
    fixed_count INT DEFAULT 0 COMMENT 'Wie oft wurde das Problem behoben',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_metric_type (metric_type),
    INDEX idx_metric_name (metric_name),
    INDEX idx_occurred_at (occurred_at),
    INDEX idx_fixed_at (fixed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eindeutiger Index für Metrik-Namen (jede Metrik sollte nur einmal existieren)
CREATE UNIQUE INDEX unique_metric_name ON monitoring_metrics(metric_name);

