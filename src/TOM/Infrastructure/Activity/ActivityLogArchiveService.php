<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Activity;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * ActivityLogArchiveService
 * 
 * Verwaltet Archivierung von alten Activity-Log-Einträgen
 * 
 * Strategie:
 * - Daten älter als X Monate werden in Archiv-Tabelle verschoben
 * - Nur aktuelle Daten bleiben in Haupttabelle
 * - Archiv-Daten können bei Bedarf geladen werden
 */
class ActivityLogArchiveService
{
    private PDO $db;
    private int $retentionMonths;
    
    public function __construct(?PDO $db = null, int $retentionMonths = 24)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->retentionMonths = $retentionMonths;
    }
    
    /**
     * Erstellt die Archiv-Tabelle (falls nicht vorhanden)
     */
    public function createArchiveTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS activity_log_archive (
                activity_id BIGINT PRIMARY KEY,
                user_id VARCHAR(100) NOT NULL,
                action_type VARCHAR(50) NOT NULL,
                entity_type VARCHAR(50),
                entity_uuid CHAR(36),
                audit_trail_id INT,
                audit_trail_table VARCHAR(50),
                details JSON,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at DATETIME NOT NULL,
                archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_archive_user_date (user_id, created_at DESC),
                INDEX idx_archive_entity (entity_type, entity_uuid),
                INDEX idx_archive_action (action_type, created_at DESC),
                INDEX idx_archive_created (created_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Archivierte Activity-Log-Einträge'
        ");
    }
    
    /**
     * Archiviert alte Einträge
     * 
     * @param int|null $months Anzahl Monate (null = verwendet retentionMonths)
     * @return int Anzahl archivierter Einträge
     */
    public function archiveOldEntries(?int $months = null): int
    {
        $months = $months ?? $this->retentionMonths;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$months} months"));
        
        // Erstelle Archiv-Tabelle falls nicht vorhanden
        $this->createArchiveTable();
        
        // Verschiebe alte Einträge in Archiv
        $stmt = $this->db->prepare("
            INSERT INTO activity_log_archive 
            SELECT 
                activity_id,
                user_id,
                action_type,
                entity_type,
                entity_uuid,
                audit_trail_id,
                audit_trail_table,
                audit_trail_id,
                details,
                ip_address,
                user_agent,
                created_at,
                NOW() as archived_at
            FROM activity_log
            WHERE created_at < :cutoff_date
        ");
        
        $stmt->execute(['cutoff_date' => $cutoffDate]);
        $archivedCount = $stmt->rowCount();
        
        // Lösche archivierte Einträge aus Haupttabelle
        $deleteStmt = $this->db->prepare("
            DELETE FROM activity_log
            WHERE created_at < :cutoff_date
        ");
        $deleteStmt->execute(['cutoff_date' => $cutoffDate]);
        
        return $archivedCount;
    }
    
    /**
     * Holt archivierte Einträge
     * 
     * @param array $filters Filter (user_id, action_type, entity_type, date_from, date_to)
     * @param int $limit Anzahl der Einträge
     * @param int $offset Offset für Pagination
     * @return array Archivierte Einträge
     */
    public function getArchivedEntries(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $sql = "
            SELECT 
                a.*,
                u.name as user_name
            FROM activity_log_archive a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND a.user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }
        
        if (!empty($filters['action_type'])) {
            $sql .= " AND a.action_type = :action_type";
            $params['action_type'] = $filters['action_type'];
        }
        
        if (!empty($filters['entity_type'])) {
            $sql .= " AND a.entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND a.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND a.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON-Details
        foreach ($entries as &$entry) {
            if ($entry['details']) {
                $entry['details'] = json_decode($entry['details'], true);
            }
        }
        
        return $entries;
    }
    
    /**
     * Zählt archivierte Einträge
     * 
     * @param array $filters Filter
     * @return int Anzahl der Einträge
     */
    public function countArchivedEntries(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM activity_log_archive WHERE 1=1";
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }
        
        if (!empty($filters['action_type'])) {
            $sql .= " AND action_type = :action_type";
            $params['action_type'] = $filters['action_type'];
        }
        
        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['total'] ?? 0);
    }
    
    /**
     * Löscht archivierte Einträge älter als X Jahre
     * 
     * @param int $years Anzahl Jahre
     * @return int Anzahl gelöschter Einträge
     */
    public function deleteOldArchivedEntries(int $years = 7): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$years} years"));
        
        $stmt = $this->db->prepare("
            DELETE FROM activity_log_archive
            WHERE created_at < :cutoff_date
        ");
        
        $stmt->execute(['cutoff_date' => $cutoffDate]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Gibt Statistiken zurück
     * 
     * @return array Statistiken
     */
    public function getStatistics(): array
    {
        // Aktive Einträge
        $activeStmt = $this->db->query("SELECT COUNT(*) as count FROM activity_log");
        $activeCount = (int)$activeStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Archivierte Einträge
        $archiveStmt = $this->db->query("SELECT COUNT(*) as count FROM activity_log_archive");
        $archiveCount = (int)$archiveStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Ältester aktiver Eintrag
        $oldestStmt = $this->db->query("SELECT MIN(created_at) as oldest FROM activity_log");
        $oldest = $oldestStmt->fetch(PDO::FETCH_ASSOC)['oldest'];
        
        // Neuester archivierter Eintrag
        $newestArchiveStmt = $this->db->query("SELECT MAX(created_at) as newest FROM activity_log_archive");
        $newestArchive = $newestArchiveStmt->fetch(PDO::FETCH_ASSOC)['newest'];
        
        return [
            'active_count' => $activeCount,
            'archive_count' => $archiveCount,
            'oldest_active' => $oldest,
            'newest_archived' => $newestArchive,
            'retention_months' => $this->retentionMonths
        ];
    }
}


