<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Activity;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * ActivityLogService
 * Zentrale Service-Klasse für Activity-Logging
 * 
 * Loggt alle wichtigen User-Aktionen (Login, Export, Upload, Entity-Änderungen, etc.)
 * Verknüpft mit entity-spezifischen Audit-Trails für Details
 */
class ActivityLogService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Loggt eine User-Aktion
     * 
     * @param string $userId User-ID
     * @param string $actionType login, logout, export, upload, download, entity_change, assignment, system_action
     * @param string|null $entityType org, person, project, case, system, etc.
     * @param string|null $entityUuid UUID der betroffenen Entität
     * @param array|null $details Zusätzliche Informationen
     * @param int|null $auditTrailId Verknüpfung zu entity-spezifischem Audit-Trail
     * @param string|null $auditTrailTable Tabellenname des Audit-Trails
     * @return int Activity-ID des erstellten Eintrags
     */
    public function logActivity(
        string $userId,
        string $actionType,
        ?string $entityType = null,
        ?string $entityUuid = null,
        ?array $details = null,
        ?int $auditTrailId = null,
        ?string $auditTrailTable = null
    ): int {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $this->db->prepare("
            INSERT INTO activity_log (
                user_id, action_type, entity_type, entity_uuid,
                audit_trail_id, audit_trail_table, details,
                ip_address, user_agent
            ) VALUES (
                :user_id, :action_type, :entity_type, :entity_uuid,
                :audit_trail_id, :audit_trail_table, :details,
                :ip_address, :user_agent
            )
        ");
        
        $detailsJson = $details ? json_encode($details) : null;
        
        $stmt->execute([
            'user_id' => $userId,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'audit_trail_id' => $auditTrailId,
            'audit_trail_table' => $auditTrailTable,
            'details' => $detailsJson,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Loggt eine Entity-Änderung (verknüpft mit Audit-Trail)
     * 
     * @param string $userId User-ID
     * @param string $entityType org, person, project, etc.
     * @param string $entityUuid UUID der Entität
     * @param int $auditTrailId ID im entity-spezifischen Audit-Trail
     * @param string $auditTrailTable Tabellenname (org_audit_trail, person_audit_trail, etc.)
     * @param array|null $summary Zusammenfassung der Änderungen
     * @return int Activity-ID
     */
    public function logEntityChange(
        string $userId,
        string $entityType,
        string $entityUuid,
        int $auditTrailId,
        string $auditTrailTable,
        ?array $summary = null
    ): int {
        return $this->logActivity(
            $userId,
            'entity_change',
            $entityType,
            $entityUuid,
            $summary,
            $auditTrailId,
            $auditTrailTable
        );
    }
    
    /**
     * Loggt einen Login
     * 
     * @param string $userId User-ID
     * @return int Activity-ID
     */
    public function logLogin(string $userId): int
    {
        return $this->logActivity(
            $userId,
            'login',
            'system',
            null,
            ['timestamp' => date('Y-m-d H:i:s')]
        );
    }
    
    /**
     * Loggt einen Logout
     * 
     * @param string $userId User-ID
     * @return int Activity-ID
     */
    public function logLogout(string $userId): int
    {
        return $this->logActivity(
            $userId,
            'logout',
            'system',
            null,
            ['timestamp' => date('Y-m-d H:i:s')]
        );
    }
    
    /**
     * Loggt einen Export
     * 
     * @param string $userId User-ID
     * @param string|null $entityType Typ der exportierten Entität
     * @param string|null $exportType csv, pdf, excel, etc.
     * @param array|null $filters Angewendete Filter
     * @return int Activity-ID
     */
    public function logExport(
        string $userId,
        ?string $entityType = null,
        ?string $exportType = null,
        ?array $filters = null
    ): int {
        return $this->logActivity(
            $userId,
            'export',
            $entityType,
            null,
            [
                'export_type' => $exportType,
                'filters' => $filters,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
    
    /**
     * Loggt einen Datei-Upload
     * 
     * @param string $userId User-ID
     * @param string $fileName Dateiname
     * @param int $fileSize Dateigröße in Bytes
     * @param string|null $entityType Typ der zugehörigen Entität
     * @param string|null $entityUuid UUID der zugehörigen Entität
     * @return int Activity-ID
     */
    public function logUpload(
        string $userId,
        string $fileName,
        int $fileSize,
        ?string $entityType = null,
        ?string $entityUuid = null
    ): int {
        return $this->logActivity(
            $userId,
            'upload',
            $entityType,
            $entityUuid,
            [
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
    
    /**
     * Loggt einen Datei-Download
     * 
     * @param string $userId User-ID
     * @param string $fileName Dateiname
     * @param string|null $entityType Typ der zugehörigen Entität
     * @param string|null $entityUuid UUID der zugehörigen Entität
     * @return int Activity-ID
     */
    public function logDownload(
        string $userId,
        string $fileName,
        ?string $entityType = null,
        ?string $entityUuid = null
    ): int {
        return $this->logActivity(
            $userId,
            'download',
            $entityType,
            $entityUuid,
            [
                'file_name' => $fileName,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
    
    /**
     * Loggt eine Zuweisung (z.B. Account Owner)
     * 
     * @param string $userId User-ID (der zuweist)
     * @param string $entityType Typ der Entität
     * @param string $entityUuid UUID der Entität
     * @param string $assignmentType Typ der Zuweisung (account_owner, etc.)
     * @param string|null $assignedToUserId User-ID des zugewiesenen Users
     * @return int Activity-ID
     */
    public function logAssignment(
        string $userId,
        string $entityType,
        string $entityUuid,
        string $assignmentType,
        ?string $assignedToUserId = null
    ): int {
        return $this->logActivity(
            $userId,
            'assignment',
            $entityType,
            $entityUuid,
            [
                'assignment_type' => $assignmentType,
                'assigned_to_user_id' => $assignedToUserId,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
    
    /**
     * Holt Activity-Log für einen User
     * 
     * @param string $userId User-ID
     * @param int $limit Anzahl der Einträge
     * @param int $offset Offset für Pagination
     * @return array Activity-Einträge
     */
    public function getUserActivities(string $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.*,
                u.name as user_name
            FROM activity_log a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.user_id = :user_id
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON-Details
        foreach ($activities as &$activity) {
            if ($activity['details']) {
                $activity['details'] = json_decode($activity['details'], true);
            }
        }
        
        return $activities;
    }
    
    /**
     * Holt Activity-Log für eine Entität
     * 
     * @param string $entityType Typ der Entität
     * @param string $entityUuid UUID der Entität
     * @param int $limit Anzahl der Einträge
     * @return array Activity-Einträge
     */
    public function getEntityActivities(string $entityType, string $entityUuid, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.*,
                u.name as user_name
            FROM activity_log a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.entity_type = :entity_type AND a.entity_uuid = :entity_uuid
            ORDER BY a.created_at DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':entity_type', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':entity_uuid', $entityUuid, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON-Details
        foreach ($activities as &$activity) {
            if ($activity['details']) {
                $activity['details'] = json_decode($activity['details'], true);
            }
        }
        
        return $activities;
    }
    
    /**
     * Holt alle Activities mit Filtern
     * 
     * @param array $filters Filter (user_id, action_type, entity_type, date_from, date_to)
     * @param int $limit Anzahl der Einträge
     * @param int $offset Offset für Pagination
     * @return array Activity-Einträge
     */
    public function getActivities(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $sql = "
            SELECT 
                a.*,
                u.name as user_name
            FROM activity_log a
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
        
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON-Details
        foreach ($activities as &$activity) {
            if ($activity['details']) {
                $activity['details'] = json_decode($activity['details'], true);
            }
        }
        
        return $activities;
    }
    
    /**
     * Holt die Anzahl der Activities mit Filtern (für Pagination)
     * 
     * @param array $filters Filter (user_id, action_type, entity_type, date_from, date_to)
     * @return int Anzahl der Einträge
     */
    public function countActivities(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM activity_log WHERE 1=1";
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
}
