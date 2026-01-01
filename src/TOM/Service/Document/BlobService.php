<?php
declare(strict_types=1);

namespace TOM\Service\Document;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

/**
 * BlobService
 * 
 * Verwaltet Datei-Inhalte (Blobs) mit Deduplication über SHA-256 Hash.
 */
class BlobService
{
    private PDO $db;
    private string $storageBasePath;
    private int $tenantId;
    
    public function __construct(?PDO $db = null, ?string $storageBasePath = null, ?int $tenantId = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->storageBasePath = $storageBasePath ?? __DIR__ . '/../../../../storage';
        $this->tenantId = $tenantId ?? 1; // Für Multi-Tenancy später
        
        // Erstelle Storage-Verzeichnisse falls nicht vorhanden
        $this->ensureStorageDirectories();
    }
    
    /**
     * Erstellt Blob aus Datei (mit Dedup-Check)
     * 
     * Optimiert: Streaming Hash-Berechnung während Kopieren (kein RAM-Bloat)
     * Race-Condition-Handling: Unique Constraint schützt vor Duplikaten
     * 
     * @param string $filePath Temporärer Pfad zur Datei
     * @param array $metadata original_filename, mime_detected, file_extension, created_by_user_id
     * @return array ['blob_uuid' => string, 'is_new' => bool]
     */
    public function createBlobFromFile(string $filePath, array $metadata = []): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        // 1) Streaming Hash-Berechnung während Kopieren (optimiert für große Dateien)
        $tempLocal = $this->getTempPath();
        $this->ensureDir(dirname($tempLocal));
        
        $hashCtx = hash_init('sha256');
        $sizeBytes = 0;
        
        $in = fopen($filePath, 'rb');
        if (!$in) {
            throw new \RuntimeException("Konnte Upload-Temp nicht öffnen: {$filePath}");
        }
        
        $out = fopen($tempLocal, 'wb');
        if (!$out) {
            fclose($in);
            throw new \RuntimeException("Konnte Server-Temp nicht erstellen: {$tempLocal}");
        }
        
        // Stream kopieren + Hash berechnen (1MB Chunks)
        while (!feof($in)) {
            $buf = fread($in, 1024 * 1024);
            if ($buf === false) break;
            $sizeBytes += strlen($buf);
            hash_update($hashCtx, $buf);
            fwrite($out, $buf);
        }
        fclose($in);
        fclose($out);
        
        $sha256 = hash_final($hashCtx);
        
        // 2) Dedup-Check (vor dem Verschieben)
        $existingBlob = $this->findBlobByHash($sha256, $sizeBytes);
        
        if ($existingBlob) {
            // Blob existiert bereits - temp-Dateien löschen
            @unlink($tempLocal);
            @unlink($filePath);
            return [
                'blob_uuid' => $existingBlob,
                'is_new' => false
            ];
        }
        
        // 3) Neuer Blob - in Storage verschieben
        $storagePath = $this->generateStoragePath($sha256);
        $storageDir = dirname($storagePath);
        $this->ensureDir($storageDir);
        
        // Atomar verschieben (rename ist atomar auf gleichem Filesystem)
        if (!@rename($tempLocal, $storagePath)) {
            // Fallback: copy + unlink
            if (!@copy($tempLocal, $storagePath)) {
                @unlink($tempLocal);
                throw new \RuntimeException("Konnte Blob nicht nach Storage verschieben: {$storagePath}");
            }
            @unlink($tempLocal);
        }
        
        // Original-Upload-Temp löschen (falls noch vorhanden)
        if ($filePath !== $tempLocal) {
            @unlink($filePath);
        }
        
        // 4) Blob in DB anlegen (mit Race-Condition-Handling)
        $blobUuid = UuidHelper::generate($this->db);
        $storageKey = $this->getStorageKey($sha256);
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO blobs (
                    blob_uuid, tenant_id, sha256, size_bytes,
                    mime_detected, storage_key, file_extension, original_filename,
                    created_by_user_id, scan_status
                ) VALUES (
                    :blob_uuid, :tenant_id, :sha256, :size_bytes,
                    :mime_detected, :storage_key, :file_extension, :original_filename,
                    :created_by_user_id, :scan_status
                )
            ");
            
                $stmt->execute([
                    'blob_uuid' => $blobUuid,
                    'tenant_id' => $this->tenantId,
                    'sha256' => $sha256,
                    'size_bytes' => $sizeBytes,
                    'mime_detected' => $metadata['mime_detected'] ?? null,
                    'storage_key' => $storageKey,
                    'file_extension' => $metadata['file_extension'] ?? null,
                    'original_filename' => $metadata['original_filename'] ?? null,
                    'created_by_user_id' => $metadata['created_by_user_id'] ?? null,
                    // Scan-Status: 'pending' - wird vom Worker auf 'clean' oder 'infected' gesetzt
                    'scan_status' => 'pending'
                ]);
            
            return [
                'blob_uuid' => $blobUuid,
                'is_new' => true
            ];
            
        } catch (\PDOException $e) {
            // Race-Condition: Parallel-Upload erkannt (Unique Constraint)
            if ($this->isDuplicateKey($e)) {
                // Bestehenden Blob finden und verwenden
                $existingBlob = $this->findBlobByHash($sha256, $sizeBytes);
                
                if ($existingBlob) {
                    // Storage-Datei löschen (wurde bereits von anderem Upload erstellt)
                    @unlink($storagePath);
                    
                    return [
                        'blob_uuid' => $existingBlob,
                        'is_new' => false
                    ];
                }
            }
            
            // Anderer Fehler: Storage-Datei löschen
            @unlink($storagePath);
            throw $e;
        }
    }
    
    /**
     * Prüft ob Exception ein Duplicate-Key-Fehler ist
     */
    private function isDuplicateKey(\PDOException $e): bool
    {
        // MariaDB/MySQL: SQLSTATE 23000, Error Code 1062
        return ($e->getCode() === '23000') && str_contains($e->getMessage(), '1062');
    }
    
    /**
     * Generiert temporären Pfad für Upload
     */
    private function getTempPath(): string
    {
        $tempDir = $this->storageBasePath . '/tmp';
        $this->ensureDir($tempDir);
        return $tempDir . '/' . bin2hex(random_bytes(16)) . '.upload';
    }
    
    /**
     * Findet Blob über Hash (Dedup-Check)
     * 
     * @param string $sha256 SHA-256 Hash (hex)
     * @param int $sizeBytes Dateigröße
     * @return string|null blob_uuid wenn existiert
     */
    public function findBlobByHash(string $sha256, int $sizeBytes): ?string
    {
        $stmt = $this->db->prepare("
            SELECT blob_uuid 
            FROM blobs 
            WHERE tenant_id = :tenant_id 
              AND sha256 = :sha256 
              AND size_bytes = :size_bytes
            LIMIT 1
        ");
        
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['blob_uuid'] : null;
    }
    
    /**
     * Blob abrufen
     * 
     * @param string $blobUuid
     * @return array|null
     */
    public function getBlob(string $blobUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM blobs WHERE blob_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $blobUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Blob-Dateipfad abrufen
     * 
     * @param string $blobUuid
     * @return string|null Dateipfad oder null
     */
    public function getBlobFilePath(string $blobUuid): ?string
    {
        $blob = $this->getBlob($blobUuid);
        if (!$blob) {
            return null;
        }
        
        $storageKey = $blob['storage_key'];
        $filePath = $this->storageBasePath . '/' . $storageKey;
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        return $filePath;
    }
    
    /**
     * Blob-Referenzzählung (wie viele Documents verwenden diesen Blob)
     * 
     * @param string $blobUuid
     * @return int
     */
    public function getBlobReferenceCount(string $blobUuid): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM documents 
            WHERE current_blob_uuid = :blob_uuid 
              AND status != 'deleted'
        ");
        $stmt->execute(['blob_uuid' => $blobUuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0);
    }
    
    /**
     * Generiert Storage-Pfad basierend auf Hash
     * 
     * @param string $sha256
     * @return string Vollständiger Pfad
     */
    private function generateStoragePath(string $sha256): string
    {
        $subDir1 = substr($sha256, 0, 2);
        $subDir2 = substr($sha256, 2, 2);
        $filename = $sha256;
        
        return $this->storageBasePath . '/' . $this->tenantId . '/' . $subDir1 . '/' . $subDir2 . '/' . $filename;
    }
    
    /**
     * Generiert Storage-Key (relativ zu storage/)
     * 
     * @param string $sha256
     * @return string
     */
    private function getStorageKey(string $sha256): string
    {
        $subDir1 = substr($sha256, 0, 2);
        $subDir2 = substr($sha256, 2, 2);
        $filename = $sha256;
        
        return $this->tenantId . '/' . $subDir1 . '/' . $subDir2 . '/' . $filename;
    }
    
    /**
     * Erstellt Storage-Verzeichnisse falls nicht vorhanden
     */
    private function ensureStorageDirectories(): void
    {
        $dirs = [
            $this->storageBasePath . '/tmp',
            $this->storageBasePath . '/' . $this->tenantId,
            $this->storageBasePath . '/quarantine'
        ];
        
        foreach ($dirs as $dir) {
            $this->ensureDir($dir);
        }
    }
    
    /**
     * Erstellt Verzeichnis falls nicht vorhanden
     * 
     * @param string $dir Verzeichnispfad
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
