<?php
/**
 * TOM3 - Import API
 * Nutzt zentralisierten DocumentService für Upload
 */

declare(strict_types=1);

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\Import\OrgImportService;
use TOM\Service\DocumentService;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Auth\AuthHelper;
use TOM\Infrastructure\Activity\ActivityLogService;

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $currentUser = AuthHelper::getCurrentUser();
    $userId = $currentUser['user_id'] ?? null;
    
    if (!$userId) {
        jsonResponse(['error' => 'Unauthorized'], 401);
        exit;
    }
    
    // userId bleibt als int (wird von DocumentService als ?int erwartet)
    // Nur für Import-Services wird es zu String konvertiert
    $userIdInt = is_int($userId) ? $userId : (int)$userId;
    
    // Parse URL
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = preg_replace('#^/tom3/public#i', '', $path);
    $path = preg_replace('#^/api/?|^api/?#', '', $path);
    $path = trim($path, '/');
    $parts = explode('/', $path);
    
    // Filtere 'import' heraus
    $parts = array_filter($parts, function($p) { return $p !== 'import'; });
    $parts = array_values($parts);
    
    $action = $parts[0] ?? null;
    $id = $parts[1] ?? null;
    
    $importService = new OrgImportService();
    $documentService = new DocumentService();
    $blobService = new BlobService();
    
    switch ($method) {
        case 'POST':
            if ($action === 'upload') {
                // POST /api/import/upload
                // Nutzt zentralisierten DocumentService
                handleImportUpload($documentService, $importService, $blobService, $userId);
            } elseif ($action === 'analyze') {
                // POST /api/import/analyze
                // Analysiert bereits hochgeladene Datei (via document_uuid)
                handleAnalyze($importService, $id, $userId);
            } elseif ($action === 'mapping') {
                // POST /api/import/mapping
                // Speichert Mapping-Konfiguration
                handleSaveMapping($importService, $id, $userId);
            } elseif ($action === 'staging') {
                // POST /api/import/staging
                // Importiert in Staging
                handleImportToStaging($importService, $id, $userId);
            } else {
                jsonResponse(['error' => 'Invalid endpoint'], 400);
            }
            break;
            
        case 'GET':
            if ($action === 'batch' && $id) {
                // GET /api/import/batch/{batch_uuid}
                handleGetBatch($importService, $id);
            } elseif ($action === 'staging' && $id) {
                // GET /api/import/staging/{batch_uuid}
                handleGetStaging($importService, $id);
            } else {
                jsonResponse(['error' => 'Invalid endpoint'], 400);
            }
            break;
            
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
    
} catch (Exception $e) {
    error_log("Import API Error: " . $e->getMessage());
    jsonResponse([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ], 500);
}

/**
 * Upload: Nutzt zentralisierten DocumentService
 */
function handleImportUpload($documentService, $importService, $blobService, $userId) {
    if (empty($_FILES['file'])) {
        jsonResponse(['error' => 'No file uploaded'], 400);
        return;
    }
    
    // Konvertiere userId zu int für DocumentService
    $userIdInt = is_int($userId) ? $userId : (int)$userId;
    
    $fileData = $_FILES['file'];
    
    // 1. Upload über zentralisierten DocumentService
    // Bestimme source_type aus Datei-Erweiterung
    $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
    $sourceType = match($extension) {
        'xlsx', 'xls' => 'excel',
        'csv' => 'csv',
        default => 'excel'
    };
    
    // Erstelle Batch zuerst (ohne file_path, wird nach Upload gesetzt)
    // Import-Service benötigt String für userId
    $batchUuid = $importService->createBatch(
        $sourceType,
        $fileData['name'],
        null, // file_path wird nach Upload gesetzt
        (string)$userIdInt
    );
    
    try {
        // Upload über DocumentService
        // Nutze 'import_batch' als entity_type (nach Migration 046)
        $entityType = 'import_batch';
        $entityUuid = $batchUuid; // Verwende batch_uuid als entity_uuid
        
        $uploadResult = $documentService->uploadAndAttach(
            $fileData,
            $entityType,
            $entityUuid,
            [
                'title' => $fileData['name'],
                'classification' => 'other', // ENUM: invoice, quote, contract, email_attachment, other
                'created_by_user_id' => $userIdInt
            ]
        );
        
        // 2. Hole Blob-Pfad über BlobService
        $filePath = $blobService->getBlobFilePath($uploadResult['blob_uuid']);
        
        if (!$filePath || !file_exists($filePath)) {
            throw new \RuntimeException('Blob-Datei nicht gefunden');
        }
        
        // 3. Aktualisiere Batch mit file_hash
        $importService->updateBatchFileHash($batchUuid, $filePath);
        
        // 4. Analysiere Excel
        $analysis = $importService->analyzeExcel($filePath);
        
        // 5. Activity-Log: Datei hochgeladen
        $activityLogService = new ActivityLogService();
        $activityLogService->logActivity(
            (string)$userIdInt,
            'import',
            'import_batch',
            $batchUuid,
            [
                'action' => 'file_uploaded',
                'filename' => $fileData['name'],
                'file_size' => $fileData['size'],
                'source_type' => $sourceType,
                'columns_found' => count($analysis['columns'] ?? []),
                'mapping_suggestions' => count($analysis['mapping_suggestion']['by_field'] ?? []),
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        jsonResponse([
            'batch_uuid' => $batchUuid,
            'document_uuid' => $uploadResult['document_uuid'],
            'blob_uuid' => $uploadResult['blob_uuid'],
            'analysis' => $analysis
        ]);
        
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Upload failed',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Analysiert Excel-Datei
 */
function handleAnalyze($importService, $documentUuid, $userId) {
    // TODO: Hole Datei-Pfad aus DocumentService
    // Für jetzt: Datei-Pfad muss übergeben werden
    $data = json_decode(file_get_contents('php://input'), true);
    $filePath = $data['file_path'] ?? null;
    
    if (!$filePath || !file_exists($filePath)) {
        jsonResponse(['error' => 'File not found'], 404);
        return;
    }
    
    try {
        $analysis = $importService->analyzeExcel($filePath);
        jsonResponse($analysis);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Analysis failed',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Speichert Mapping-Konfiguration
 */
function handleSaveMapping($importService, $batchUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['mapping_config'])) {
        jsonResponse(['error' => 'mapping_config required'], 400);
        return;
    }
    
    try {
        $importService->saveMapping($batchUuid, $data['mapping_config'], (string)$userId);
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Failed to save mapping',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Importiert in Staging
 */
function handleImportToStaging($importService, $batchUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    $filePath = $data['file_path'] ?? null;
    
    if (!$filePath || !file_exists($filePath)) {
        jsonResponse(['error' => 'File not found'], 404);
        return;
    }
    
    try {
        $stats = $importService->importToStaging($batchUuid, $filePath);
        jsonResponse([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Import failed',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Holt Batch-Details
 */
function handleGetBatch($importService, $batchUuid) {
    try {
        $batch = $importService->getBatch($batchUuid);
        if (!$batch) {
            jsonResponse(['error' => 'Batch not found'], 404);
            return;
        }
        
        jsonResponse($batch);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Failed to get batch',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Holt Staging-Daten
 */
function handleGetStaging($importService, $batchUuid) {
    // TODO: Implementierung
    jsonResponse(['error' => 'Not implemented'], 501);
}
