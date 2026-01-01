<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Service\BaseEntityService;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Document\FileTypeValidator;
use TOM\Infrastructure\Document\ClamAvService;

/**
 * DocumentService
 * 
 * Verwaltet Dokumente (Metadaten) und Attachments (Verknüpfungen zu Entitäten).
 */
class DocumentService extends BaseEntityService
{
    private BlobService $blobService;
    private FileTypeValidator $fileTypeValidator;
    private ?ClamAvService $clamAvService = null;
    
    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        $this->blobService = new BlobService($db);
        $this->fileTypeValidator = new FileTypeValidator();
    }
    
    /**
     * Gibt ClamAvService zurück (lazy loading)
     */
    private function getClamAvService(): ?ClamAvService
    {
        if ($this->clamAvService === null) {
            try {
                $this->clamAvService = new ClamAvService();
                // Prüfe, ob ClamAV verfügbar ist
                if (!$this->clamAvService->isAvailable()) {
                    // ClamAV nicht verfügbar - Service wird nicht verwendet
                    $this->clamAvService = null;
                }
            } catch (\Exception $e) {
                // ClamAV nicht verfügbar - ignorieren für MVP
                error_log("ClamAV nicht verfügbar: " . $e->getMessage());
                $this->clamAvService = null;
            }
        }
        return $this->clamAvService;
    }
    
    /**
     * Upload und Attachment in einem Schritt
     * 
     * Optimiert: Transaction-basiert, Race-Condition-sicher
     * Erstellt automatisch document_group für neue Dokumente
     * 
     * @param array $fileData $_FILES['file'] oder ['tmp_name', 'name', 'size', 'type']
     * @param string $entityType 'org', 'person', 'case', etc.
     * @param string $entityUuid UUID der Entität
     * @param array $metadata title, tags[], classification, role, description, version_group_uuid, created_by_user_id
     * @return array document_uuid, attachment_uuid, version_group_uuid, status, scan_status
     */
    public function uploadAndAttach(array $fileData, string $entityType, string $entityUuid, array $metadata = []): array
    {
        // Validierung
        if (empty($fileData['tmp_name']) || !file_exists($fileData['tmp_name'])) {
            throw new \InvalidArgumentException('Datei nicht gefunden');
        }
        
        $originalFilename = $fileData['name'] ?? 'unknown';
        $tempPath = $fileData['tmp_name'];
        
        // Filetype-Validierung (vor dem Kopieren)
        $validation = $this->fileTypeValidator->validate($tempPath, $originalFilename);
        
        // Transaction für atomare Operationen
        $this->db->beginTransaction();
        
        try {
            // Blob erstellen (mit Dedup + Streaming Hash)
            // createBlobFromFile kopiert die Datei intern und berechnet Hash währenddessen
            $blobResult = $this->blobService->createBlobFromFile($tempPath, [
                'mime_detected' => $validation['mime'],
                'file_extension' => $validation['extension'],
                'original_filename' => $originalFilename,
                'created_by_user_id' => $metadata['created_by_user_id'] ?? null
            ]);
            
            // Document erstellen (erstellt automatisch document_group wenn nicht angegeben)
            $document = $this->createDocument([
                'current_blob_uuid' => $blobResult['blob_uuid'],
                'title' => $metadata['title'] ?? $originalFilename,
                'classification' => $metadata['classification'] ?? 'other',
                'tags' => $metadata['tags'] ?? [],
                'version_group_uuid' => $metadata['version_group_uuid'] ?? null, // Wird in createDocument behandelt
                'source_type' => 'upload',
                'created_by_user_id' => $metadata['created_by_user_id'] ?? null,
                'entity_type' => $entityType, // Für Audit-Trail
                'entity_uuid' => $entityUuid  // Für Audit-Trail
            ]);
            
            // Attachment erstellen
            $attachment = $this->attachDocument(
                $document['document_uuid'],
                $entityType,
                $entityUuid,
                [
                    'role' => $metadata['role'] ?? null,
                    'description' => $metadata['description'] ?? null,
                    'created_by_user_id' => $metadata['created_by_user_id'] ?? null
                ]
            );
            
            // Transaction commit
            $this->db->commit();
            
            // Jobs enqueuen (async processing)
            $this->enqueueScan($blobResult['blob_uuid']);
            // $this->enqueueExtraction($document['document_uuid']); // Später
            
            return [
                'document_uuid' => $document['document_uuid'],
                'attachment_uuid' => $attachment['attachment_uuid'],
                'version_group_uuid' => $document['version_group_uuid'],
                'version_number' => $document['version_number'],
                'status' => 'processing',
                'scan_status' => 'pending',
                'extraction_status' => 'pending',
                'is_new_blob' => $blobResult['is_new']
            ];
            
        } catch (\Exception $e) {
            // Rollback bei Fehler
            $this->db->rollBack();
            
            // Cleanup: temp-Datei wird von BlobService bereits gelöscht
            // (außer bei sehr frühen Fehlern)
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            
            throw $e;
        }
    }
    
    /**
     * Document erstellen
     * 
     * @param array $data current_blob_uuid, title, classification, tags, version_group_uuid, etc.
     * @return array document_uuid, ...
     */
    public function createDocument(array $data): array
    {
        $documentUuid = UuidHelper::generate($this->db);
        
        // Version-Gruppe: Wenn nicht angegeben, neue Gruppe erstellen
        $versionGroupUuid = $data['version_group_uuid'] ?? null;
        if (!$versionGroupUuid) {
            $versionGroupUuid = $this->createDocumentGroup($data['title'] ?? 'Unbenannt', $data['created_by_user_id'] ?? null);
        }
        
        $versionNumber = $data['version_number'] ?? 1;
        $supersedesUuid = $data['supersedes_document_uuid'] ?? null;
        
        $stmt = $this->db->prepare("
            INSERT INTO documents (
                document_uuid, tenant_id, current_blob_uuid, title,
                classification, version_group_uuid, version_number, supersedes_document_uuid,
                is_current_version, source_type, source_metadata, tags, notes, status,
                extraction_status, created_by_user_id
            ) VALUES (
                :document_uuid, :tenant_id, :current_blob_uuid, :title,
                :classification, :version_group_uuid, :version_number, :supersedes_document_uuid,
                :is_current_version, :source_type, :source_metadata, :tags, :notes, :status,
                :extraction_status, :created_by_user_id
            )
        ");
        
        $tagsJson = !empty($data['tags']) ? json_encode($data['tags']) : null;
        $sourceMetadataJson = !empty($data['source_metadata']) ? json_encode($data['source_metadata']) : null;
        
        $stmt->execute([
            'document_uuid' => $documentUuid,
            'tenant_id' => 1, // TODO: Multi-Tenancy
            'current_blob_uuid' => $data['current_blob_uuid'],
            'title' => $data['title'] ?? 'Unbenannt',
            'classification' => $data['classification'] ?? 'other',
            'version_group_uuid' => $versionGroupUuid,
            'version_number' => $versionNumber,
            'supersedes_document_uuid' => $supersedesUuid,
            'is_current_version' => $data['is_current_version'] ?? true,
            'source_type' => $data['source_type'] ?? 'upload',
            'source_metadata' => $sourceMetadataJson,
            'tags' => $tagsJson,
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
            'extraction_status' => 'pending',
            'created_by_user_id' => $data['created_by_user_id'] ?? null
        ]);
        
        // Wenn aktuelle Version: document_groups.current_document_uuid aktualisieren
        if ($data['is_current_version'] ?? true) {
            $this->updateDocumentGroupCurrent($versionGroupUuid, $documentUuid, $data['title'] ?? 'Unbenannt');
        }
        
        // Audit-Trail
        $document = $this->getDocument($documentUuid);
        if ($document) {
            $userId = isset($data['created_by_user_id']) ? (string)$data['created_by_user_id'] : null;
            // Füge Entity-Informationen hinzu, falls verfügbar (werden in metadata gespeichert)
            $documentForAudit = $document;
            if (isset($data['entity_type'])) {
                $documentForAudit['entity_type'] = $data['entity_type'];
            }
            if (isset($data['entity_uuid'])) {
                $documentForAudit['entity_uuid'] = $data['entity_uuid'];
            }
            $this->logCreateAuditTrail('document', $documentUuid, $userId, $documentForAudit, null);
            $this->publishEntityEvent('document', $documentUuid, 'DocumentCreated', $document);
        }
        
        return $document ?: [];
    }
    
    /**
     * Erstellt neue Version eines Dokuments (Race-Condition-sicher)
     * 
     * @param string $versionGroupUuid UUID der Version-Gruppe
     * @param array $fileData $_FILES['file']
     * @param array $metadata title, classification, etc.
     * @param bool $supersede Ob alte Version als "nicht aktuell" markiert werden soll
     * @return array document_uuid, version_number, ...
     */
    public function createVersion(string $versionGroupUuid, array $fileData, array $metadata = [], bool $supersede = true): array
    {
        // Transaction für atomare Version-Erstellung
        $this->db->beginTransaction();
        
        try {
            // 1) Version-Gruppe locken (FOR UPDATE)
            $stmt = $this->db->prepare("
                SELECT current_document_uuid, title
                FROM document_groups
                WHERE group_uuid = :group_uuid AND tenant_id = :tenant_id
                FOR UPDATE
            ");
            $stmt->execute([
                'group_uuid' => $versionGroupUuid,
                'tenant_id' => 1
            ]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$group) {
                throw new \InvalidArgumentException("Version-Gruppe nicht gefunden: {$versionGroupUuid}");
            }
            
            $currentDocumentUuid = $group['current_document_uuid'];
            
            // 2) Nächste Versionsnummer bestimmen (FOR UPDATE)
            $stmt = $this->db->prepare("
                SELECT COALESCE(MAX(version_number), 0) AS max_version
                FROM documents
                WHERE version_group_uuid = :group_uuid AND tenant_id = :tenant_id
                FOR UPDATE
            ");
            $stmt->execute([
                'group_uuid' => $versionGroupUuid,
                'tenant_id' => 1
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $nextVersion = (int)($result['max_version'] ?? 0) + 1;
            
            // 3) Alte Version als "nicht aktuell" markieren (wenn supersede)
            if ($supersede && $currentDocumentUuid) {
                $stmt = $this->db->prepare("
                    UPDATE documents
                    SET is_current_version = FALSE
                    WHERE document_uuid = :uuid
                ");
                $stmt->execute(['uuid' => $currentDocumentUuid]);
            }
            
            // 4) Filetype-Validierung
            $originalFilename = $fileData['name'] ?? 'unknown';
            $validation = $this->fileTypeValidator->validate($fileData['tmp_name'], $originalFilename);
            
            // 5) Blob erstellen (mit Dedup)
            $blobResult = $this->blobService->createBlobFromFile($fileData['tmp_name'], [
                'mime_detected' => $validation['mime'],
                'file_extension' => $validation['extension'],
                'original_filename' => $originalFilename,
                'created_by_user_id' => $metadata['created_by_user_id'] ?? null
            ]);
            
            // 6) Neue Version erstellen
            $document = $this->createDocument([
                'current_blob_uuid' => $blobResult['blob_uuid'],
                'title' => $metadata['title'] ?? $group['title'] ?? $originalFilename,
                'classification' => $metadata['classification'] ?? 'other',
                'tags' => $metadata['tags'] ?? [],
                'version_group_uuid' => $versionGroupUuid,
                'version_number' => $nextVersion,
                'supersedes_document_uuid' => $currentDocumentUuid,
                'is_current_version' => true,
                'source_type' => 'upload',
                'created_by_user_id' => $metadata['created_by_user_id'] ?? null
            ]);
            
            $this->db->commit();
            
            return [
                'document_uuid' => $document['document_uuid'],
                'version_number' => $nextVersion,
                'version_group_uuid' => $versionGroupUuid,
                'status' => 'processing',
                'scan_status' => 'pending'
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Erstellt neue Document-Gruppe
     * 
     * @param string $title Titel der Gruppe
     * @param int|null $userId
     * @return string group_uuid
     */
    private function createDocumentGroup(string $title, ?int $userId): string
    {
        $groupUuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO document_groups (
                group_uuid, tenant_id, title, created_by_user_id
            ) VALUES (
                :group_uuid, :tenant_id, :title, :created_by_user_id
            )
        ");
        
        $stmt->execute([
            'group_uuid' => $groupUuid,
            'tenant_id' => 1,
            'title' => $title,
            'created_by_user_id' => $userId
        ]);
        
        return $groupUuid;
    }
    
    /**
     * Aktualisiert current_document_uuid in document_groups
     * 
     * @param string $groupUuid
     * @param string $documentUuid
     * @param string $title
     */
    private function updateDocumentGroupCurrent(string $groupUuid, string $documentUuid, string $title): void
    {
        $stmt = $this->db->prepare("
            UPDATE document_groups
            SET current_document_uuid = :document_uuid,
                title = :title,
                updated_at = NOW()
            WHERE group_uuid = :group_uuid
        ");
        
        $stmt->execute([
            'group_uuid' => $groupUuid,
            'document_uuid' => $documentUuid,
            'title' => $title
        ]);
    }
    
    /**
     * Ruft alle Versionen einer Gruppe ab
     * 
     * @param string $versionGroupUuid
     * @return array Liste aller Versionen
     */
    public function getDocumentVersions(string $versionGroupUuid): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   b.sha256, b.size_bytes, b.mime_detected, b.scan_status,
                   b.file_extension, b.original_filename
            FROM documents d
            JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
            WHERE d.version_group_uuid = :group_uuid
              AND d.status != 'deleted'
            ORDER BY d.version_number DESC
        ");
        
        $stmt->execute(['group_uuid' => $versionGroupUuid]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // JSON-Felder dekodieren
        foreach ($versions as &$version) {
            if ($version['tags']) {
                $version['tags'] = json_decode($version['tags'], true) ?: [];
            }
            if ($version['source_metadata']) {
                $version['source_metadata'] = json_decode($version['source_metadata'], true) ?: [];
            }
            if ($version['extraction_meta']) {
                $version['extraction_meta'] = json_decode($version['extraction_meta'], true) ?: [];
            }
        }
        
        return $versions;
    }
    
    /**
     * Ruft Document-Gruppe ab
     * 
     * @param string $groupUuid
     * @return array|null
     */
    public function getDocumentGroup(string $groupUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT dg.*,
                   d.document_uuid as current_document_uuid,
                   d.title as current_title,
                   d.version_number as current_version_number
            FROM document_groups dg
            LEFT JOIN documents d ON dg.current_document_uuid = d.document_uuid
            WHERE dg.group_uuid = :uuid
        ");
        
        $stmt->execute(['uuid' => $groupUuid]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($group) {
            // Versions-Liste hinzufügen
            $group['versions'] = $this->getDocumentVersions($groupUuid);
        }
        
        return $group ?: null;
    }
    
    /**
     * Document abrufen
     * 
     * @param string $documentUuid
     * @return array|null
     */
    public function getDocument(string $documentUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   b.sha256, b.size_bytes, b.mime_detected, b.scan_status, b.scan_at,
                   b.file_extension, b.original_filename
            FROM documents d
            JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
            WHERE d.document_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $documentUuid]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document && $document['tags']) {
            $document['tags'] = json_decode($document['tags'], true) ?: [];
        }
        if ($document && $document['source_metadata']) {
            $document['source_metadata'] = json_decode($document['source_metadata'], true) ?: [];
        }
        if ($document && $document['extraction_meta']) {
            $document['extraction_meta'] = json_decode($document['extraction_meta'], true) ?: [];
        }
        
        return $document ?: null;
    }
    
    /**
     * Dokumente einer Entität abrufen
     * 
     * @param string $entityType
     * @param string $entityUuid
     * @param bool $activeOnly
     * @return array
     */
    public function getEntityDocuments(string $entityType, string $entityUuid, bool $activeOnly = true): array
    {
        $sql = "
            SELECT d.*,
                   b.sha256, b.size_bytes, b.mime_detected, b.scan_status, b.scan_at,
                   b.file_extension, b.original_filename,
                   da.attachment_uuid, da.role, da.description, da.created_at as attached_at
            FROM document_attachments da
            JOIN documents d ON da.document_uuid = d.document_uuid
            JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
            WHERE da.entity_type = :entity_type
              AND da.entity_uuid = :entity_uuid
        ";
        
        if ($activeOnly) {
            $sql .= " AND d.status = 'active'";
        }
        
        $sql .= " ORDER BY da.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid
        ]);
        
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // JSON-Felder dekodieren
        foreach ($documents as &$doc) {
            if ($doc['tags']) {
                $doc['tags'] = json_decode($doc['tags'], true) ?: [];
            }
            if ($doc['source_metadata']) {
                $doc['source_metadata'] = json_decode($doc['source_metadata'], true) ?: [];
            }
            if ($doc['extraction_meta']) {
                $doc['extraction_meta'] = json_decode($doc['extraction_meta'], true) ?: [];
            }
        }
        
        return $documents;
    }
    
    /**
     * Alle Attachments eines Dokuments abrufen (zeigt wo das Dokument hängt)
     * 
     * @param string $documentUuid
     * @return array
     */
    public function getDocumentAttachments(string $documentUuid): array
    {
        $sql = "
            SELECT da.attachment_uuid, da.entity_type, da.entity_uuid, 
                   da.role, da.description, da.created_at as attached_at,
                   da.created_by_user_id
            FROM document_attachments da
            WHERE da.document_uuid = :document_uuid
            ORDER BY da.created_at DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['document_uuid' => $documentUuid]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Attachment erstellen (verknüpft Document mit Entität)
     * 
     * @param string $documentUuid
     * @param string $entityType
     * @param string $entityUuid
     * @param array $metadata role, description, created_by_user_id
     * @return array attachment_uuid
     */
    public function attachDocument(string $documentUuid, string $entityType, string $entityUuid, array $metadata = []): array
    {
        // Prüfe ob Document existiert
        $document = $this->getDocument($documentUuid);
        if (!$document) {
            throw new \InvalidArgumentException("Document nicht gefunden: {$documentUuid}");
        }
        
        // Prüfe ob Attachment bereits existiert
        $stmt = $this->db->prepare("
            SELECT attachment_uuid 
            FROM document_attachments 
            WHERE document_uuid = :document_uuid 
              AND entity_type = :entity_type 
              AND entity_uuid = :entity_uuid
        ");
        $stmt->execute([
            'document_uuid' => $documentUuid,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid
        ]);
        
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException("Document ist bereits mit dieser Entität verknüpft");
        }
        
        // Attachment erstellen
        $attachmentUuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO document_attachments (
                attachment_uuid, tenant_id, document_uuid,
                entity_type, entity_uuid, role, description,
                created_by_user_id
            ) VALUES (
                :attachment_uuid, :tenant_id, :document_uuid,
                :entity_type, :entity_uuid, :role, :description,
                :created_by_user_id
            )
        ");
        
        $stmt->execute([
            'attachment_uuid' => $attachmentUuid,
            'tenant_id' => 1, // TODO: Multi-Tenancy
            'document_uuid' => $documentUuid,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'role' => $metadata['role'] ?? null,
            'description' => $metadata['description'] ?? null,
            'created_by_user_id' => $metadata['created_by_user_id'] ?? null
        ]);
        
        // Audit-Trail
        $userId = isset($metadata['created_by_user_id']) ? (string)$metadata['created_by_user_id'] : null;
        $this->logAuditAction('document', $documentUuid, 'attach', [
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'attachment_uuid' => $attachmentUuid
        ], $userId);
        
        return [
            'attachment_uuid' => $attachmentUuid,
            'document_uuid' => $documentUuid,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid
        ];
    }
    
    /**
     * Attachment entfernen
     * 
     * @param string $attachmentUuid
     * @return bool
     */
    public function detachDocument(string $attachmentUuid): bool
    {
        $stmt = $this->db->prepare("
            SELECT * FROM document_attachments WHERE attachment_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $attachmentUuid]);
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$attachment) {
            return false;
        }
        
        // Audit-Trail vor Löschung
        $this->logAuditAction('document', $attachment['document_uuid'], 'detach', [
            'entity_type' => $attachment['entity_type'],
            'entity_uuid' => $attachment['entity_uuid'],
            'attachment_uuid' => $attachmentUuid
        ], null);
        
        // Löschen
        $stmt = $this->db->prepare("DELETE FROM document_attachments WHERE attachment_uuid = :uuid");
        $stmt->execute(['uuid' => $attachmentUuid]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Document löschen (soft delete)
     * 
     * @param string $documentUuid
     * @return bool
     */
    public function deleteDocument(string $documentUuid): bool
    {
        $document = $this->getDocument($documentUuid);
        if (!$document) {
            return false;
        }
        
        // Soft Delete
        $stmt = $this->db->prepare("
            UPDATE documents 
            SET status = 'deleted' 
            WHERE document_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $documentUuid]);
        
        // Audit-Trail
        $this->logAuditAction('document', $documentUuid, 'delete', null, null);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Download-URL generieren (nur wenn scan_status = clean)
     * 
     * @param string $documentUuid
     * @return string|null Download-URL oder null wenn blockiert
     */
    public function getDownloadUrl(string $documentUuid): ?string
    {
        $document = $this->getDocument($documentUuid);
        if (!$document) {
            return null;
        }
        
        // Nur wenn clean
        if ($document['scan_status'] !== 'clean') {
            return null;
        }
        
        // Generiere temporäre Download-URL (z.B. über API-Endpunkt)
        return "/api/documents/{$documentUuid}/download";
    }
    
    /**
     * Volltext-Suche in Dokumenten (MariaDB FULLTEXT)
     * 
     * @param string $query Suchbegriff
     * @param array $filters entity_type, entity_uuid, classification, tags[], limit
     * @return array Dokumente mit Relevanz-Score
     */
    public function searchDocuments(string $query, array $filters = []): array
    {
        // Wenn Query leer oder '*', verwende searchDocumentsInTitle stattdessen
        if (empty($query) || $query === '*') {
            return $this->searchDocumentsInTitle($query, $filters);
        }
        
        $tenantId = 1; // TODO: Multi-Tenancy
        $limit = (int)($filters['limit'] ?? 50);
        
        // Normalisiere Query (trim)
        $query = trim($query);
        $queryLower = mb_strtolower($query);
        
        // Für kurze Queries (<= 3 Zeichen) verwende LIKE (FULLTEXT hat minimale Wortlänge)
        // Für längere Queries versuche zuerst FULLTEXT, dann LIKE als Fallback
        $useLikeOnly = mb_strlen($query) <= 3;
        
        if ($useLikeOnly) {
            // LIKE-basierte Suche (case-insensitive)
            $sql = "
                SELECT d.*,
                       b.sha256, b.size_bytes, b.mime_detected, b.scan_status,
                       b.file_extension, b.original_filename,
                       (CASE 
                           WHEN LOWER(d.title) LIKE :query_like THEN 2.0
                           WHEN LOWER(COALESCE(d.extracted_text, '')) LIKE :query_like THEN 1.0
                           ELSE 0.5
                       END) AS relevance_score
                FROM documents d
                JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
                WHERE d.tenant_id = :tenant_id
                  AND (
                    LOWER(d.title) LIKE :query_like
                    OR LOWER(COALESCE(d.extracted_text, '')) LIKE :query_like
                  )
            ";
            
            $params = [
                'tenant_id' => $tenantId,
                'query_like' => '%' . $queryLower . '%'
            ];
        } else {
            // FULLTEXT-basierte Suche (case-insensitive über Collation utf8mb4_unicode_ci)
            // Kombiniere mit LIKE für bessere Trefferquote
            $sql = "
                SELECT d.*,
                       b.sha256, b.size_bytes, b.mime_detected, b.scan_status,
                       b.file_extension, b.original_filename,
                       GREATEST(
                           COALESCE(
                               CASE WHEN d.extracted_text IS NOT NULL AND d.extracted_text != '' 
                               THEN MATCH(d.extracted_text) AGAINST(:query IN NATURAL LANGUAGE MODE) 
                               ELSE 0 END, 0) * 1.0,
                           CASE WHEN LOWER(COALESCE(d.extracted_text, '')) LIKE :query_like THEN 0.8 ELSE 0 END
                       ) + 
                       GREATEST(
                           COALESCE(MATCH(d.title) AGAINST(:query IN NATURAL LANGUAGE MODE), 0) * 1.5,
                           CASE WHEN LOWER(d.title) LIKE :query_like THEN 1.2 ELSE 0 END
                       ) AS relevance_score
                FROM documents d
                JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
                WHERE d.tenant_id = :tenant_id
                  AND (
                    (d.extracted_text IS NOT NULL AND d.extracted_text != '' AND MATCH(d.extracted_text) AGAINST(:query IN NATURAL LANGUAGE MODE))
                    OR MATCH(d.title) AGAINST(:query IN NATURAL LANGUAGE MODE)
                    OR LOWER(d.title) LIKE :query_like
                    OR LOWER(COALESCE(d.extracted_text, '')) LIKE :query_like
                  )
            ";
            
            $params = [
                'tenant_id' => $tenantId,
                'query' => $query,
                'query_like' => '%' . $queryLower . '%'
            ];
        }
        
        // Filter: Status (default: active)
        if (!empty($filters['status'])) {
            $sql .= " AND d.status = :status";
            $params['status'] = $filters['status'];
        } else {
            $sql .= " AND d.status = 'active'";
        }
        
        // Filter: Scan-Status (default: clean)
        if (!empty($filters['scan_status'])) {
            $sql .= " AND b.scan_status = :scan_status";
            $params['scan_status'] = $filters['scan_status'];
        } else {
            $sql .= " AND b.scan_status = 'clean'";
        }
        
        // Filter: Extraction-Status (optional, standardmäßig auch pending erlauben)
        if (isset($filters['extraction_status'])) {
            $sql .= " AND d.extraction_status = :extraction_status";
            $params['extraction_status'] = $filters['extraction_status'];
        }
        
        // Filter: Zeitraum (created_at)
        if (!empty($filters['date_from'])) {
            $sql .= " AND d.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND d.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        // Filter: Quelle (source_type)
        if (!empty($filters['source_type'])) {
            $sql .= " AND d.source_type = :source_type";
            $params['source_type'] = $filters['source_type'];
        }
        
        // Filter: Entity-Type/ID
        if (!empty($filters['entity_type']) && !empty($filters['entity_uuid'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM document_attachments da
                WHERE da.document_uuid = d.document_uuid
                  AND da.entity_type = :entity_type
                  AND da.entity_uuid = :entity_uuid
            )";
            $params['entity_type'] = $filters['entity_type'];
            $params['entity_uuid'] = $filters['entity_uuid'];
        }
        
        // Filter: Rolle (role aus attachments)
        if (!empty($filters['role'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM document_attachments da
                WHERE da.document_uuid = d.document_uuid
                  AND da.role = :role
            )";
            $params['role'] = $filters['role'];
        }
        
        // Filter: Nur ohne Zuordnung (Waisen)
        if (!empty($filters['orphaned_only']) && $filters['orphaned_only'] === true) {
            $sql .= " AND NOT EXISTS (
                SELECT 1 FROM document_attachments da
                WHERE da.document_uuid = d.document_uuid
            )";
        }
        
        // Filter: Klassifikation
        if (!empty($filters['classification'])) {
            $sql .= " AND d.classification = :classification";
            $params['classification'] = $filters['classification'];
        }
        
        // Filter: Tags (JSON)
        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            foreach ($filters['tags'] as $i => $tag) {
                $paramName = 'tag_' . $i;
                $sql .= " AND JSON_CONTAINS(d.tags, :{$paramName})";
                $params[$paramName] = json_encode($tag);
            }
        }
        
        // Sortierung nach Relevanz
        $sql .= " ORDER BY relevance_score DESC, d.created_at DESC LIMIT :limit";
        $params['limit'] = $limit;
        
        $stmt = $this->db->prepare($sql);
        
        // Parameter binden (limit als int)
        foreach ($params as $key => $value) {
            if ($key === 'limit') {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // JSON-Felder dekodieren
        foreach ($documents as &$doc) {
            if ($doc['tags']) {
                $doc['tags'] = json_decode($doc['tags'], true) ?: [];
            }
            if ($doc['source_metadata']) {
                $doc['source_metadata'] = json_decode($doc['source_metadata'], true) ?: [];
            }
            if ($doc['extraction_meta']) {
                $doc['extraction_meta'] = json_decode($doc['extraction_meta'], true) ?: [];
            }
            // Relevanz-Score als float
            $doc['relevance_score'] = (float)($doc['relevance_score'] ?? 0);
        }
        
        return $documents;
    }
    
    /**
     * Suche auch im Titel (falls extracted_text leer) oder für leere Queries
     * 
     * @param string $query
     * @param array $filters
     * @return array
     */
    public function searchDocumentsInTitle(string $query, array $filters = []): array
    {
        $tenantId = 1;
        $limit = (int)($filters['limit'] ?? 50);
        
        // Wenn Query leer oder '*', zeige alle Dokumente (nur mit Filtern)
        $useFulltext = !empty($query) && $query !== '*';
        $queryLower = $useFulltext ? mb_strtolower($query) : '';
        
        if ($useFulltext) {
            // Für kurze Queries (<= 3 Zeichen) verwende LIKE
            $useLikeOnly = mb_strlen($query) <= 3;
            
            if ($useLikeOnly) {
                $sql = "
                    SELECT d.*,
                           b.sha256, b.size_bytes, b.mime_detected, b.scan_status,
                           b.file_extension, b.original_filename,
                           (CASE 
                               WHEN LOWER(d.title) LIKE :query_like THEN 2.0
                               ELSE 1.0
                           END) AS relevance_score
                    FROM documents d
                    JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
                    WHERE d.tenant_id = :tenant_id
                      AND LOWER(d.title) LIKE :query_like
                ";
                
                $params = [
                    'tenant_id' => $tenantId,
                    'query_like' => '%' . $queryLower . '%'
                ];
            } else {
                // FULLTEXT + LIKE Fallback
                $sql = "
                    SELECT d.*,
                           b.sha256, b.size_bytes, b.mime_detected, b.scan_status,
                           b.file_extension, b.original_filename,
                           GREATEST(
                               COALESCE(MATCH(d.title) AGAINST(:query IN NATURAL LANGUAGE MODE), 0) * 1.5,
                               CASE WHEN LOWER(d.title) LIKE :query_like THEN 1.2 ELSE 0 END
                           ) AS relevance_score
                    FROM documents d
                    JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
                    WHERE d.tenant_id = :tenant_id
                      AND (
                        MATCH(d.title) AGAINST(:query IN NATURAL LANGUAGE MODE)
                        OR LOWER(d.title) LIKE :query_like
                      )
                ";
                
                $params = [
                    'tenant_id' => $tenantId,
                    'query' => $query,
                    'query_like' => '%' . $queryLower . '%'
                ];
            }
        } else {
            $sql = "
                SELECT d.*,
                       b.sha256, b.size_bytes, b.mime_detected, b.scan_status,
                       b.file_extension, b.original_filename,
                       1.0 AS relevance_score
                FROM documents d
                JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
                WHERE d.tenant_id = :tenant_id
            ";
            
            $params = [
                'tenant_id' => $tenantId
            ];
        }
        
        // Filter: Status (default: active)
        if (!empty($filters['status'])) {
            $sql .= " AND d.status = :status";
            $params['status'] = $filters['status'];
        } else {
            $sql .= " AND d.status = 'active'";
        }
        
        // Filter: Scan-Status (default: clean)
        if (!empty($filters['scan_status'])) {
            $sql .= " AND b.scan_status = :scan_status";
            $params['scan_status'] = $filters['scan_status'];
        } else {
            $sql .= " AND b.scan_status = 'clean'";
        }
        
        // Filter: Zeitraum
        if (!empty($filters['date_from'])) {
            $sql .= " AND d.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND d.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        // Filter: Quelle
        if (!empty($filters['source_type'])) {
            $sql .= " AND d.source_type = :source_type";
            $params['source_type'] = $filters['source_type'];
        }
        
        // Filter: Entity-Type/ID
        if (!empty($filters['entity_type']) && !empty($filters['entity_uuid'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM document_attachments da
                WHERE da.document_uuid = d.document_uuid
                  AND da.entity_type = :entity_type
                  AND da.entity_uuid = :entity_uuid
            )";
            $params['entity_type'] = $filters['entity_type'];
            $params['entity_uuid'] = $filters['entity_uuid'];
        }
        
        // Filter: Rolle
        if (!empty($filters['role'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM document_attachments da
                WHERE da.document_uuid = d.document_uuid
                  AND da.role = :role
            )";
            $params['role'] = $filters['role'];
        }
        
        // Filter: Nur ohne Zuordnung
        if (!empty($filters['orphaned_only']) && $filters['orphaned_only'] === true) {
            $sql .= " AND NOT EXISTS (
                SELECT 1 FROM document_attachments da
                WHERE da.document_uuid = d.document_uuid
            )";
        }
        
        // Filter: Klassifikation
        if (!empty($filters['classification'])) {
            $sql .= " AND d.classification = :classification";
            $params['classification'] = $filters['classification'];
        }
        
        // Filter: Tags
        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            foreach ($filters['tags'] as $i => $tag) {
                $paramName = 'tag_' . $i;
                $sql .= " AND JSON_CONTAINS(d.tags, :{$paramName})";
                $params[$paramName] = json_encode($tag);
            }
        }
        
        $sql .= " ORDER BY relevance_score DESC, d.created_at DESC LIMIT :limit";
        $params['limit'] = $limit;
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'limit') {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($documents as &$doc) {
            if ($doc['tags']) {
                $doc['tags'] = json_decode($doc['tags'], true) ?: [];
            }
            if ($doc['source_metadata']) {
                $doc['source_metadata'] = json_decode($doc['source_metadata'], true) ?: [];
            }
            if ($doc['extraction_meta']) {
                $doc['extraction_meta'] = json_decode($doc['extraction_meta'], true) ?: [];
            }
            $doc['relevance_score'] = (float)($doc['relevance_score'] ?? 0);
        }
        
        return $documents;
    }
    
    /**
     * Audit-Action loggen
     * 
     * @param string $entityType
     * @param string $entityUuid
     * @param string $action
     * @param array|null $metadata
     * @param string|null $userId
     */
    /**
     * Enqueued einen Scan-Job für einen Blob
     * 
     * @param string $blobUuid
     */
    private function enqueueScan(string $blobUuid): void
    {
        try {
            $eventUuid = UuidHelper::generate($this->db);
            
            $stmt = $this->db->prepare("
                INSERT INTO outbox_event (
                    event_uuid, aggregate_type, aggregate_uuid, event_type, payload
                ) VALUES (
                    :event_uuid, 'blob', :blob_uuid, 'BlobScanRequested', :payload
                )
            ");
            
            $stmt->execute([
                'event_uuid' => $eventUuid,
                'blob_uuid' => $blobUuid,
                'payload' => json_encode([
                    'blob_uuid' => $blobUuid,
                    'job_type' => 'scan_blob',
                    'created_at' => date('Y-m-d H:i:s')
                ])
            ]);
        } catch (\Exception $e) {
            // Fehler beim Enqueuen nicht kritisch - loggen und weitermachen
            error_log("Fehler beim Enqueuen von Scan-Job: " . $e->getMessage());
        }
    }
    
    private function logAuditAction(string $entityType, string $entityUuid, string $action, ?array $metadata, ?string $userId): void
    {
        try {
            // Verwende die vereinheitlichte Struktur (wie org_audit_trail, person_audit_trail)
            $stmt = $this->db->prepare("
                INSERT INTO document_audit_trail (
                    document_uuid, user_id, action, change_type, metadata
                ) VALUES (
                    :document_uuid, :user_id, :action, :change_type, :metadata
                )
            ");
            
            // Map action zu change_type für spezielle Aktionen
            $changeType = $action; // attach, detach, delete, etc.
            $dbAction = in_array($action, ['attach', 'detach', 'block', 'unblock', 'version_create', 'download', 'preview']) 
                ? 'update' 
                : ($action === 'delete' ? 'delete' : 'update');
            
            $stmt->execute([
                'document_uuid' => $entityUuid,
                'user_id' => $userId ?? '',
                'action' => $dbAction,
                'change_type' => $changeType,
                'metadata' => $metadata ? json_encode($metadata) : null
            ]);
        } catch (\Exception $e) {
            // Audit-Fehler sollten nicht den Haupt-Flow blockieren
            error_log("Document audit trail error: " . $e->getMessage());
        }
    }
}
