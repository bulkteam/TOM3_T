<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;
use TOM\Infrastructure\Activity\ActivityLogService;

/**
 * Basis-Service-Klasse für Entity-Services
 * Eliminiert Code-Duplikation zwischen OrgService und PersonService
 * 
 * Pragmatischer Ansatz: Nur wirklich gemeinsame Patterns werden hier zentralisiert.
 * Spezifische Logik bleibt in den abgeleiteten Services.
 */
abstract class BaseEntityService
{
    protected PDO $db;
    protected EventPublisher $eventPublisher;
    protected AuditTrailService $auditTrailService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
        
        // ActivityLogService für Verknüpfung mit Audit-Trail
        $activityLogService = new ActivityLogService($this->db);
        $this->auditTrailService = new AuditTrailService($this->db, $activityLogService);
    }
    
    /**
     * Protokolliert Audit-Trail für Create-Operation
     * Wird von abgeleiteten Services nach dem Erstellen aufgerufen
     */
    protected function logCreateAuditTrail(string $entityType, string $uuid, ?string $userId, array $entity, ?callable $fieldResolver = null): void
    {
        $this->auditTrailService->logAuditTrail(
            $entityType,
            $uuid,
            $userId,
            'create',
            null,
            $entity,
            null, // allowedFields - bei create werden alle Felder protokolliert
            null,
            $fieldResolver
        );
    }
    
    /**
     * Protokolliert Audit-Trail für Update-Operation
     * Wird von abgeleiteten Services nach dem Aktualisieren aufgerufen
     */
    protected function logUpdateAuditTrail(string $entityType, string $uuid, ?string $userId, array $oldData, array $newData, ?callable $fieldResolver = null): void
    {
        $this->auditTrailService->logAuditTrail(
            $entityType,
            $uuid,
            $userId,
            'update',
            $oldData,
            $newData,
            null, // allowedFields - alle Felder
            null, // changedFields - wird automatisch ermittelt
            $fieldResolver
        );
    }
    
    /**
     * Publiziert ein Event
     * Wird von abgeleiteten Services verwendet
     */
    protected function publishEntityEvent(string $entityType, string $uuid, string $eventType, array $data): void
    {
        $this->eventPublisher->publish($entityType, $uuid, $eventType, $data);
    }
    
    /**
     * Holt die aktuelle User-ID
     * 
     * @param bool $allowFallback Erlaubt 'default_user' Fallback nur in Dev-Mode
     * @return string User-ID
     * @throws \RuntimeException Wenn kein User eingeloggt und Fallback nicht erlaubt
     */
    protected function getCurrentUserId(bool $allowFallback = false): string
    {
        try {
            return \TOM\Infrastructure\Auth\AuthHelper::getCurrentUserId($allowFallback);
        } catch (\RuntimeException $e) {
            // In Dev-Mode: Fallback erlauben
            if ($allowFallback) {
                $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
                if (in_array($appEnv, ['local', 'dev', 'development'])) {
                    return 'default_user';
                }
            }
            throw $e;
        }
    }
}


