<?php
/**
 * TOM3 - Import API
 * Nutzt zentralisierten DocumentService für Upload
 */

declare(strict_types=1);

require_once __DIR__ . '/base-api-handler.php';
require_once __DIR__ . '/api-security.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\Import\OrgImportService;
use TOM\Service\Import\ImportStagingService;
use TOM\Service\Import\IndustryDecisionService;
use TOM\Service\Import\ImportCommitService;
use TOM\Service\Import\ImportTemplateService;
use TOM\Service\Import\ImportReviewService;
use TOM\Service\DocumentService;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Activity\ActivityLogService;

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Auth prüfen für alle Import-Endpoints (alle benötigen Auth)
    $currentUser = requireAuth();
    $userId = (string)$currentUser['user_id'];
    
    // userId bleibt als int (wird von DocumentService als ?int erwartet)
    // Nur für Import-Services wird es zu String konvertiert
    $userIdInt = is_int($currentUser['user_id']) ? $currentUser['user_id'] : (int)$currentUser['user_id'];
    
    // CSRF prüfen für state-changing Requests
    validateCsrfToken($method);
    
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
    $subAction = $parts[2] ?? null; // Für verschachtelte Pfade wie staging/{uuid}/industry-decision
    
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
                if ($id && $subAction === 'industry-decision') {
                    // POST /api/import/staging/{staging_uuid}/industry-decision
                    $stagingService = new ImportStagingService();
                    $decisionService = new IndustryDecisionService();
                    handleIndustryDecision($decisionService, $id, $userId);
                } elseif ($id && $subAction === 'disposition') {
                    // POST /api/import/staging/{staging_uuid}/disposition
                    $reviewService = new ImportReviewService();
                    handleSetDisposition($reviewService, $id, $userId);
                } elseif ($id && $subAction === 'corrections') {
                    // POST /api/import/staging/{staging_uuid}/corrections
                    $stagingService = new ImportStagingService();
                    handleSaveCorrections($stagingService, $id, $userId);
                } elseif ($id) {
                    // POST /api/import/staging/{batch_uuid}
                    // Importiert in Staging (nutzt neuen ImportStagingService)
                    $stagingService = new ImportStagingService();
                    handleImportToStaging($stagingService, $id, $userId);
                } else {
                    jsonError('Invalid endpoint', 400);
                }
            } elseif ($action === 'batch' && $id && $subAction === 'commit') {
                // POST /api/import/batch/{batch_uuid}/commit
                $commitService = new \TOM\Service\Import\ImportCommitService();
                handleCommitBatch($commitService, $id, $userId);
            } elseif ($action === 'staging' && $id && $subAction === 'disposition') {
                // POST /api/import/staging/{staging_uuid}/disposition
                $reviewService = new \TOM\Service\Import\ImportReviewService();
                handleSetDisposition($reviewService, $id, $userId);
            } else {
                jsonError('Invalid endpoint', 400);
            }
            break;
            
        case 'DELETE':
            if ($action === 'batch' && $id) {
                // DELETE /api/import/batch/{batch_uuid}
                $batchService = new \TOM\Service\Import\ImportBatchService();
                handleDeleteBatch($batchService, $id, $userId);
            } else {
                jsonError('Invalid endpoint', 400);
            }
            break;
            
        case 'GET':
            if ($action === 'batches' && !$id) {
                // GET /api/import/batches
                $batchService = new \TOM\Service\Import\ImportBatchService();
                handleListBatches($batchService, $userId);
            } elseif ($action === 'batch' && $id) {
                if ($subAction === 'staging-rows') {
                    // GET /api/import/batch/{batch_uuid}/staging-rows
                    $stagingService = new ImportStagingService();
                    handleGetBatchStagingRows($stagingService, $id);
                } elseif ($subAction === 'stats') {
                    // GET /api/import/batch/{batch_uuid}/stats
                    $batchService = new \TOM\Service\Import\ImportBatchService();
                    handleGetBatchStats($batchService, $id);
                } else {
                    // GET /api/import/batch/{batch_uuid}
                    handleGetBatch($importService, $id);
                }
            } elseif ($action === 'staging' && $id) {
                // GET /api/import/staging/{staging_uuid}
                // Holt einzelne Staging-Row (nicht Batch)
                $stagingService = new ImportStagingService();
                handleGetStagingRow($stagingService, $id);
            } elseif ($action === 'templates') {
                // GET /api/import/templates
                $templateService = new ImportTemplateService();
                handleListTemplates($templateService, $id); // $id = import_type (optional)
            } elseif ($action === 'template' && $id) {
                // GET /api/import/template/{template_uuid}
                $templateService = new ImportTemplateService();
                handleGetTemplate($templateService, $id);
            } else {
                jsonError('Invalid endpoint', 400);
            }
            break;
            
        default:
            jsonError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    handleApiException($e, 'Import API Error');
}

/**
 * Upload: Nutzt zentralisierten DocumentService
 */
function handleImportUpload($documentService, $importService, $blobService, $userId) {
    if (empty($_FILES['file'])) {
        jsonError('No file uploaded', 400);
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
        
        // 4. Analysiere Excel (mit Template-Matching)
        $importType = 'ORG_ONLY'; // TODO: Aus Request oder Config
        $analysis = $importService->analyzeExcel($filePath, $importType);
        
        // 5. Speichere Template-Matching-Ergebnisse in Batch
        if (!empty($analysis['template_match'])) {
            $templateMatch = $analysis['template_match'];
            $db = \TOM\Infrastructure\Database\DatabaseConnection::getInstance();
            $stmt = $db->prepare("
                UPDATE org_import_batch
                SET detected_header_row = :header_row,
                    detected_template_uuid = :template_uuid,
                    detected_template_score = :score
                WHERE batch_uuid = :batch_uuid
            ");
            $stmt->execute([
                'batch_uuid' => $batchUuid,
                'header_row' => $analysis['header_row'] ?? null,
                'template_uuid' => $templateMatch['template']['template_uuid'] ?? null,
                'score' => $templateMatch['score'] ?? null
            ]);
        }
        
        // 6. Activity-Log: Datei hochgeladen
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
        handleApiException($e, 'Upload failed');
    }
}

/**
 * Analysiert Excel-Datei
 */
function handleAnalyze($importService, $documentUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Unterstütze sowohl document_uuid als auch batch_uuid
    $batchUuid = $data['batch_uuid'] ?? null;
    
    if ($batchUuid) {
        // Hole Dokument für Batch
        $db = \TOM\Infrastructure\Database\DatabaseConnection::getInstance();
        $stmt = $db->prepare("
            SELECT d.document_uuid, d.current_blob_uuid as blob_uuid
            FROM document_attachments da
            JOIN documents d ON da.document_uuid = d.document_uuid
            WHERE da.entity_type = 'import_batch'
            AND da.entity_uuid = :batch_uuid
            ORDER BY da.created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['batch_uuid' => $batchUuid]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doc || !$doc['blob_uuid']) {
            jsonError('Dokument für Batch nicht gefunden', 404);
            return;
        }
        
        $blobService = new BlobService();
        $filePath = $blobService->getBlobFilePath($doc['blob_uuid']);
        
        if (!$filePath || !file_exists($filePath)) {
            jsonError('Datei nicht gefunden', 404);
            return;
        }
        
        try {
            $importType = 'ORG_ONLY'; // TODO: Aus Batch oder Config
            $analysis = $importService->analyzeExcel($filePath, $importType);
            jsonResponse(['analysis' => $analysis]);
        } catch (Exception $e) {
            handleApiException($e, 'Analysis failed');
        }
        return;
    }
    
    // Fallback: Alte Methode mit file_path
    $filePath = $data['file_path'] ?? null;
    
    if (!$filePath || !file_exists($filePath)) {
        jsonError('File not found', 404);
        return;
    }
    
    try {
        $analysis = $importService->analyzeExcel($filePath);
        jsonResponse($analysis);
    } catch (Exception $e) {
        handleApiException($e, 'Analysis failed');
    }
}

/**
 * Speichert Mapping-Konfiguration
 */
function handleSaveMapping($importService, $batchUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['mapping_config'])) {
        jsonError('mapping_config required', 400);
        return;
    }
    
    try {
        $importService->saveMapping($batchUuid, $data['mapping_config'], (string)$userId);
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to save mapping');
    }
}

/**
 * Importiert in Staging (nutzt neuen ImportStagingService)
 */
function handleImportToStaging($stagingService, $batchUuid, $userId) {
    try {
        // Hole Batch-Details
        $importService = new OrgImportService();
        $batch = $importService->getBatch($batchUuid);
        
        if (!$batch) {
            jsonError('Batch not found', 404);
            return;
        }
        
        // Hole file_path aus DocumentService/BlobService
        // Suche nach Document für diesen Batch
        $db = \TOM\Infrastructure\Database\DatabaseConnection::getInstance();
        $stmt = $db->prepare("
            SELECT d.current_blob_uuid as blob_uuid
            FROM document_attachments da
            JOIN documents d ON da.document_uuid = d.document_uuid
            WHERE da.entity_type = 'import_batch'
            AND da.entity_uuid = :batch_uuid
            ORDER BY da.created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['batch_uuid' => $batchUuid]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doc || !$doc['blob_uuid']) {
            jsonError('File not found for batch', 404);
            return;
        }
        
        // Hole Datei-Pfad über BlobService
        $blobService = new BlobService();
        $filePath = $blobService->getBlobFilePath($doc['blob_uuid']);
        
        if (!$filePath || !file_exists($filePath)) {
            jsonError('File not found on disk', 404);
            return;
        }
        
        // Importiere in Staging
        $stats = $stagingService->stageBatch($batchUuid, $filePath);
        
        // Prüfe, ob Daten importiert wurden
        if (($stats['imported'] ?? 0) === 0 && ($stats['total_rows'] ?? 0) > 0) {
            // Alle Zeilen hatten Fehler
            jsonResponse([
                'error' => 'Import failed',
                'message' => 'Keine Zeilen konnten importiert werden. Bitte prüfen Sie die Fehler.',
                'stats' => $stats
            ], 400);
            return;
        }
        
        // Activity-Log
        $activityLogService = new ActivityLogService();
        $activityLogService->logActivity(
            (string)$userId,
            'import',
            'import_batch',
            $batchUuid,
            [
                'action' => 'staging_import',
                'rows_total' => $stats['total_rows'] ?? 0,
                'rows_imported' => $stats['imported'] ?? 0,
                'rows_errors' => $stats['errors'] ?? 0,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        // Prüfe, ob Daten importiert wurden
        if (($stats['imported'] ?? 0) === 0) {
            // Keine Zeilen importiert - zeige Fehlerdetails
            $errorMessage = 'Keine Zeilen konnten importiert werden.';
            if (!empty($stats['errors_detail'])) {
                $firstError = $stats['errors_detail'][0];
                $errorMessage .= ' Erster Fehler (Zeile ' . ($firstError['row'] ?? '?') . '): ' . ($firstError['error'] ?? 'Unbekannt');
            }
            
            jsonResponse([
                'error' => 'Import failed',
                'message' => $errorMessage,
                'stats' => $stats
            ], 400);
            return;
        }
        
        jsonResponse([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        handleApiException($e, 'Import failed');
    }
}

/**
 * Listet alle Batches (GET /api/import/batches)
 */
function handleListBatches($batchService, $userId) {
    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $batches = $batchService->listBatches($userId ? (string)$userId : null, $limit);
        
        jsonResponse([
            'batches' => $batches,
            'count' => count($batches)
        ]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to list batches');
    }
}

/**
 * Holt Batch-Statistiken (GET /api/import/batch/{batch_uuid}/stats)
 */
function handleGetBatchStats($batchService, $batchUuid) {
    try {
        $batch = $batchService->getBatchWithStats($batchUuid);
        if (!$batch) {
            jsonError('Batch not found', 404);
            return;
        }
        
        jsonResponse($batch);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to get batch stats');
    }
}

/**
 * Holt Batch-Details
 */
function handleGetBatch($importService, $batchUuid) {
    try {
        $batch = $importService->getBatch($batchUuid);
        if (!$batch) {
            jsonError('Batch not found', 404);
            return;
        }
        
        jsonResponse($batch);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to get batch');
    }
}

/**
 * Holt einzelne Staging-Row (GET /api/import/staging/{staging_uuid})
 */
function handleGetStagingRow($stagingService, $stagingUuid) {
    try {
        $row = $stagingService->getStagingRow($stagingUuid);
        
        if (!$row) {
            jsonError('Staging row not found', 404);
            return;
        }
        
        // Merge mapped_data mit corrections_json für effective_data
        $mappedData = json_decode($row['mapped_data'], true);
        $corrections = isset($row['corrections_json']) && $row['corrections_json'] ? json_decode($row['corrections_json'], true) : null;
        
        // Merge corrections in mapped_data (effective_data)
        // Verwende die gleiche Logik wie ImportCommitService::mergeRecursive
        $effectiveData = $mappedData;
        if ($corrections) {
            $effectiveData = mergeRecursiveOverwrite($mappedData, $corrections);
        }
        
        jsonResponse([
            'staging_uuid' => $row['staging_uuid'],
            'batch_uuid' => $row['import_batch_uuid'],
            'row_number' => $row['row_number'],
            'raw_data' => json_decode($row['raw_data'], true),
            'mapped_data' => $mappedData,
            'corrections' => $corrections,
            'effective_data' => $effectiveData, // mapped_data + corrections merged
            'industry_resolution' => json_decode($row['industry_resolution'] ?? '{}', true),
            'validation_status' => $row['validation_status'],
            'validation_errors' => json_decode($row['validation_errors'] ?? '[]', true),
            'disposition' => $row['disposition'] ?? 'pending', // Korrekte Feldname
            'review_status' => $row['disposition'] ?? 'pending', // Für Rückwärtskompatibilität
            'review_notes' => $row['review_notes'] ?? null,
            'duplicate_status' => $row['duplicate_status'] ?? 'unknown',
            'duplicate_summary' => isset($row['duplicate_summary']) ? json_decode($row['duplicate_summary'] ?? 'null', true) : null,
            'import_status' => $row['import_status']
        ]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to get staging row');
    }
}

/**
 * Holt alle Staging-Rows für einen Batch (GET /api/import/batch/{batch_uuid}/staging-rows)
 */
function handleGetBatchStagingRows($stagingService, $batchUuid) {
    try {
        $reviewStatus = $_GET['review_status'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : null;
        
        $rows = $stagingService->getStagingRowsForBatch($batchUuid, $reviewStatus, $limit, $offset);
        
        jsonResponse([
            'rows' => $rows,
            'count' => count($rows)
        ]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to get staging rows');
    }
}

/**
 * Verarbeitet Industry-Entscheidung (POST /api/import/staging/{staging_uuid}/industry-decision)
 */
function handleIndustryDecision($decisionService, $stagingUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data)) {
        jsonResponse(['error' => 'Request body required'], 400);
        return;
    }
    
    try {
        $result = $decisionService->applyDecision($stagingUuid, $data, (string)$userId);
        jsonResponse($result);
    } catch (\RuntimeException $e) {
        $errorCode = $e->getMessage();
        
        if ($errorCode === 'INCONSISTENT_PARENT') {
            jsonResponse([
                'error' => 'INCONSISTENT_PARENT',
                'message' => 'Die gewählte Branche (Level 2) gehört nicht zum gewählten Branchenbereich (Level 1).'
            ], 409);
        } elseif ($errorCode === 'L3_CREATE_REQUIRES_CONFIRMED_L2') {
            jsonResponse([
                'error' => 'L3_CREATE_REQUIRES_CONFIRMED_L2',
                'message' => 'Level 3 kann nur erstellt werden, wenn Level 2 bestätigt wurde.'
            ], 400);
        } elseif ($errorCode === 'L3_NAME_REQUIRED') {
            jsonResponse([
                'error' => 'L3_NAME_REQUIRED',
                'message' => 'Name für neue Level 3 Branche ist erforderlich.'
            ], 400);
        } elseif ($errorCode === 'L3_UUID_REQUIRED') {
            jsonResponse([
                'error' => 'L3_UUID_REQUIRED',
                'message' => 'UUID für bestehende Level 3 Branche ist erforderlich.'
            ], 400);
        } else {
            handleApiException($e, 'Industry decision failed');
        }
    } catch (Exception $e) {
        handleApiException($e, 'Decision failed');
    }
}

/**
 * Listet Templates
 */
function handleListTemplates($templateService, $importType) {
    try {
        $templates = $templateService->listTemplates($importType, true);
        jsonResponse(['templates' => $templates]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to list templates');
    }
}

/**
 * Holt Template
 */
function handleGetTemplate($templateService, $templateUuid) {
    try {
        $template = $templateService->getTemplate($templateUuid);
        if (!$template) {
            jsonResponse(['error' => 'Template not found'], 404);
            return;
        }
        jsonResponse(['template' => $template]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to get template');
    }
}

/**
 * Erstellt Template
 */
function handleCreateTemplate($templateService, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name']) || empty($data['mapping_config'])) {
        jsonResponse(['error' => 'name and mapping_config required'], 400);
        return;
    }
    
    try {
        $templateUuid = $templateService->createTemplate(
            $data['name'],
            $data['import_type'] ?? 'ORG_ONLY',
            $data['mapping_config'],
            $userId,
            $data['is_default'] ?? false
        );
        jsonResponse(['template_uuid' => $templateUuid, 'success' => true]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to create template');
    }
}

/**
 * Aktualisiert Template
 */
function handleUpdateTemplate($templateService, $templateUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $templateService->updateTemplate(
            $templateUuid,
            $data['name'] ?? null,
            $data['mapping_config'] ?? null,
            $userId,
            $data['is_default'] ?? null
        );
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        handleApiException($e, 'Failed to update template');
    }
}

/**
 * Löscht einen Batch (DELETE /api/import/batch/{batch_uuid})
 */
function handleDeleteBatch($batchService, $batchUuid, $userId) {
    try {
        $batchService->deleteBatch($batchUuid, (string)$userId);
        jsonResponse(['success' => true, 'message' => 'Batch erfolgreich gelöscht']);
    } catch (\RuntimeException $e) {
        jsonResponse([
            'error' => 'Batch konnte nicht gelöscht werden',
            'message' => $e->getMessage()
        ], 400);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Fehler beim Löschen',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Setzt Disposition einer Staging-Row (POST /api/import/staging/{staging_uuid}/disposition)
 */
function handleSetDisposition($reviewService, $stagingUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['disposition'])) {
        jsonResponse(['error' => 'disposition required'], 400);
        return;
    }
    
    $disposition = $data['disposition'];
    $notes = $data['notes'] ?? null;
    
    try {
        $reviewService->setDisposition($stagingUuid, $disposition, (string)$userId, $notes);
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Failed to set disposition',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Merge-Funktion: Überschreibt Werte statt sie zu verdoppeln (wie array_merge_recursive)
 */
function mergeRecursiveOverwrite(array $base, array $patch): array
{
    foreach ($patch as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = mergeRecursiveOverwrite($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

/**
 * Speichert Korrekturen für eine Staging-Row (POST /api/import/staging/{staging_uuid}/corrections)
 */
function handleSaveCorrections($stagingService, $stagingUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['corrections'])) {
        jsonResponse(['error' => 'corrections required'], 400);
        return;
    }
    
    $corrections = $data['corrections'];
    
    try {
        // Prüfe, ob Row existiert
        $row = $stagingService->getStagingRow($stagingUuid);
        if (!$row) {
            jsonResponse(['error' => 'Staging row not found'], 404);
            return;
        }
        
        // Prüfe, ob bereits importiert
        if ($row['import_status'] === 'imported') {
            jsonResponse(['error' => 'Row wurde bereits importiert und kann nicht mehr geändert werden'], 400);
            return;
        }
        
        // Speichere Korrekturen in corrections_json
        $db = \TOM\Infrastructure\Database\DatabaseConnection::getInstance();
        $stmt = $db->prepare("
            UPDATE org_import_staging
            SET corrections_json = :corrections
            WHERE staging_uuid = :staging_uuid
        ");
        
        $stmt->execute([
            'staging_uuid' => $stagingUuid,
            'corrections' => json_encode($corrections, JSON_UNESCAPED_UNICODE)
        ]);
        
        // Activity-Log
        $activityLogService = new \TOM\Infrastructure\Activity\ActivityLogService($db);
        $activityLogService->logActivity(
            (string)$userId,
            'import',
            'import_staging',
            $stagingUuid,
            [
                'action' => 'corrections_saved',
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        jsonResponse(['success' => true, 'message' => 'Korrekturen gespeichert']);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Failed to save corrections',
            'message' => $e->getMessage()
        ], 500);
    }
}

/**
 * Committet Batch (POST /api/import/batch/{batch_uuid}/commit)
 */
function handleCommitBatch($commitService, $batchUuid, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mode = $data['mode'] ?? 'APPROVED_ONLY';
    $startWorkflows = $data['start_workflows'] ?? true;
    $dryRun = $data['dry_run'] ?? false;
    
    if ($dryRun) {
        // TODO: Validierung ohne Commit
        jsonResponse([
            'error' => 'Dry-run not implemented yet',
            'message' => 'Dry-run wird später implementiert'
        ], 501);
        return;
    }
    
    try {
        $result = $commitService->commitBatch($batchUuid, (string)$userId, $startWorkflows, $mode);
        
        jsonResponse([
            'batch_uuid' => $batchUuid,
            'result' => $result,
            'stats' => $result // Für Kompatibilität
        ]);
    } catch (Exception $e) {
        jsonResponse([
            'error' => 'Commit failed',
            'message' => $e->getMessage()
        ], 500);
    }
}

