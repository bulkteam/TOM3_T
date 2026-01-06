<?php
/**
 * TOM3 - Documents API
 * 
 * Endpunkte für Dokumenten-Upload und -Verwaltung
 */

declare(strict_types=1);

require_once __DIR__ . '/base-api-handler.php';
require_once __DIR__ . '/api-security.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\DocumentService;
use TOM\Infrastructure\Auth\AuthHelper;

/**
 * Sanitizes filename for Content-Disposition header (prevents header injection)
 * 
 * @param string $filename Original filename
 * @return array ['filename' => string, 'filenameStar' => string] Safe filenames for HTTP headers
 */
function sanitizeFilenameForHeader(string $filename): array
{
    // Entferne gefährliche Zeichen (CR, LF, Tabs, etc.)
    $filename = preg_replace('/[\r\n\t\x00-\x1F\x7F]/', '', $filename);
    
    // Entferne oder ersetze problematische Zeichen
    // Erlaube nur: Buchstaben, Zahlen, Leerzeichen, Punkt, Bindestrich, Unterstrich, Klammern
    $filename = preg_replace('/[^a-zA-Z0-9\s\.\-_()]/', '_', $filename);
    
    // Entferne führende/abschließende Punkte und Leerzeichen
    $filename = trim($filename, '. ');
    
    // Begrenze Länge (RFC 5987 empfiehlt max 255 Zeichen)
    if (strlen($filename) > 200) {
        $filename = substr($filename, 0, 200);
    }
    
    // Fallback wenn leer
    if (empty($filename)) {
        $filename = 'document';
    }
    
    // RFC 5987 filename* für Unicode-Unterstützung (besser als einfaches filename="...")
    // URL-encode für filename* (RFC 5987 Format)
    $filenameStar = rawurlencode($filename);
    
    // Escaping für Quotes (Content-Disposition filename="..." als Fallback)
    $filenameQuoted = addslashes($filename);
    
    return [
        'filename' => $filenameQuoted,
        'filenameStar' => $filenameStar
    ];
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Auth prüfen für geschützte Endpoints
    // GET-Endpoints sind öffentlich, POST/PUT/DELETE benötigen Auth
    $currentUser = null;
    $userId = null;
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
        $currentUser = requireAuth();
        $userId = $currentUser['user_id'] ?? null;
        // CSRF prüfen für state-changing Requests
        validateCsrfToken($method);
    } else {
        // Für GET: Optional Auth (für user_id bei track-access)
        $currentUser = AuthHelper::getCurrentUser();
        $userId = $currentUser['user_id'] ?? null;
    }
    
    // Router übergibt bereits geparste Parameter
    $documentId = $id ?? null;
    $action = $action ?? null;
    
    // Für komplexere Pfade müssen wir nochmal parsen
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = preg_replace('#^/tom3/public#i', '', $path);
    $path = preg_replace('#^/api/?|^api/?#', '', $path);
    $path = trim($path, '/');
    $parts = explode('/', $path);
    
    // Filtere 'documents' heraus
    $parts = array_filter($parts, function($p) { return $p !== 'documents'; });
    $parts = array_values($parts);
    
    $subAction = $parts[1] ?? null;
    $subId = $parts[2] ?? null;
    
    $documentService = new DocumentService();
    
    switch ($method) {
        case 'POST':
            if ($parts[0] === 'upload') {
                // POST /api/documents/upload
                handleUpload($documentService, $userId);
            } elseif ($parts[0] === 'groups' && isset($parts[1]) && $parts[1] === 'upload-version') {
                // POST /api/documents/groups/{group_uuid}/upload-version
                $groupUuid = $parts[2] ?? null;
                handleUploadVersion($documentService, $groupUuid, $userId);
            } elseif ($documentId && $action === 'attach') {
                // POST /api/documents/{uuid}/attach
                handleAttach($documentService, $documentId, $userId);
            } else {
                jsonResponse(['error' => 'Invalid endpoint'], 400);
            }
            break;
            
        case 'GET':
            if ($parts[0] === 'search') {
                // GET /api/documents/search?q=...&entity_type=...&entity_uuid=...
                handleSearchDocuments($documentService);
            } elseif ($parts[0] === 'groups' && isset($parts[1])) {
                // GET /api/documents/groups/{group_uuid}
                handleGetDocumentGroup($documentService, $parts[1]);
            } elseif ($parts[0] === 'entity' && isset($parts[1]) && isset($parts[2])) {
                // GET /api/documents/entity/{entity_type}/{entity_uuid}
                handleGetEntityDocuments($documentService, $parts[1], $parts[2]);
            } elseif ($documentId && $action === 'download') {
                // GET /api/documents/{uuid}/download
                handleDownload($documentService, $documentId);
            } elseif ($documentId && $action === 'view') {
                // GET /api/documents/{uuid}/view (Preview - für PDFs und Bilder)
                handleView($documentService, $documentId);
            } elseif ($documentId) {
                // GET /api/documents/{uuid}
                handleGetDocument($documentService, $documentId);
            } else {
                jsonResponse(['error' => 'Invalid endpoint'], 400);
            }
            break;
            
        case 'DELETE':
            if ($parts[0] === 'attachments' && isset($parts[1])) {
                // DELETE /api/documents/attachments/{attachment_uuid}
                handleDetach($documentService, $parts[1]);
            } elseif ($documentId) {
                // DELETE /api/documents/{uuid}
                handleDeleteDocument($documentService, $documentId, $userId);
            } else {
                jsonResponse(['error' => 'Invalid endpoint'], 400);
            }
            break;
            
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    handleApiException($e);
}

/**
 * Upload-Handler
 */
function handleUpload(DocumentService $service, ?int $userId): void
{
    if (empty($_FILES['file'])) {
        jsonResponse(['error' => 'No file uploaded'], 400);
        return;
    }
    
    $file = $_FILES['file'];
    
    // Validierung
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error';
        jsonResponse(['error' => $errorMsg], 400);
        return;
    }
    
    // Max. Dateigröße (50MB)
    $maxSize = 50 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        jsonResponse(['error' => 'File too large (max 50MB)'], 400);
        return;
    }
    
    // Metadata aus POST
    $metadata = [
        'title' => $_POST['title'] ?? $file['name'],
        'classification' => $_POST['classification'] ?? 'other',
        'tags' => !empty($_POST['tags']) ? json_decode($_POST['tags'], true) : [],
        'entity_type' => $_POST['entity_type'] ?? null,
        'entity_uuid' => $_POST['entity_uuid'] ?? null,
        'role' => $_POST['role'] ?? null,
        'description' => $_POST['description'] ?? null,
        'created_by_user_id' => $userId
    ];
    
    // Validierung: entity_type und entity_uuid müssen gesetzt sein
    if (empty($metadata['entity_type']) || empty($metadata['entity_uuid'])) {
        jsonResponse(['error' => 'entity_type and entity_uuid are required'], 400);
        return;
    }
    
    try {
        $result = $service->uploadAndAttach(
            $file,
            $metadata['entity_type'],
            $metadata['entity_uuid'],
            $metadata
        );
        
        jsonResponse($result, 201);
    } catch (InvalidArgumentException $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    } catch (Exception $e) {
        handleApiException($e, 'Upload failed');
    }
}

/**
 * Attachment erstellen
 */
function handleAttach(DocumentService $service, string $documentUuid, ?int $userId): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['entity_type']) || empty($data['entity_uuid'])) {
        jsonResponse(['error' => 'entity_type and entity_uuid are required'], 400);
        return;
    }
    
    try {
        $result = $service->attachDocument(
            $documentUuid,
            $data['entity_type'],
            $data['entity_uuid'],
            [
                'role' => $data['role'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by_user_id' => $userId
            ]
        );
        
        jsonResponse($result, 201);
    } catch (InvalidArgumentException $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    } catch (Exception $e) {
        handleApiException($e, 'Attach failed');
    }
}

/**
 * Document abrufen
 */
function handleGetDocument(DocumentService $service, string $documentUuid): void
{
    try {
        $document = $service->getDocument($documentUuid);
        
        if (!$document) {
            jsonResponse(['error' => 'Document not found'], 404);
            return;
        }
        
        jsonResponse($document);
    } catch (Exception $e) {
        handleApiException($e, 'Get document failed');
    }
}

/**
 * Dokumente einer Entität abrufen
 */
function handleGetEntityDocuments(DocumentService $service, ?string $entityType, ?string $entityUuid): void
{
    if (!$entityType || !$entityUuid) {
        jsonResponse(['error' => 'entity_type and entity_uuid are required'], 400);
        return;
    }
    
    try {
        $documents = $service->getEntityDocuments($entityType, $entityUuid, true);
        jsonResponse($documents);
    } catch (Exception $e) {
        handleApiException($e, 'Get entity documents failed');
    }
}

/**
 * Download-Handler
 */
function handleDownload(DocumentService $service, string $documentUuid): void
{
    try {
        // Berechtigungsprüfung: Prüfe ob User Zugriff auf das Dokument hat
        // (über Attachments zu Entitäten, auf die der User Zugriff hat)
        require_once __DIR__ . '/api-security.php';
        $currentUser = AuthHelper::getCurrentUser();
        
        // Prüfe ob Dokument existiert
        $document = $service->getDocument($documentUuid);
        if (!$document) {
            http_response_code(404);
            echo 'Document not found';
            return;
        }
        
        // Berechtigungsprüfung: Dokument muss an mindestens eine Entität angehängt sein
        // (später: zusätzlich prüfen ob User Zugriff auf diese Entität hat)
        $attachments = $service->getDocumentAttachments($documentUuid);
        if (empty($attachments)) {
            // Dokument ohne Attachments: Nur Admins dürfen zugreifen
            if (!$currentUser || !in_array('admin', $currentUser['roles'] ?? [])) {
                http_response_code(403);
                echo 'Access denied';
                return;
            }
        }
        // TODO: Später erweitern: Prüfe ob User Zugriff auf mindestens eine der Entitäten hat
        
        // Nur wenn scan_status = clean
        if ($document['scan_status'] !== 'clean') {
            http_response_code(403);
            echo 'Document is not available for download (scan status: ' . $document['scan_status'] . ')';
            return;
        }
        
        // Blob-Dateipfad abrufen
        $blobService = new \TOM\Service\Document\BlobService();
        $filePath = $blobService->getBlobFilePath($document['current_blob_uuid']);
        
        if (!$filePath || !file_exists($filePath)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }
        
        // Download-Header
        $filename = $document['title'] ?? $document['original_filename'] ?? 'document';
        $extension = $document['file_extension'] ?? '';
        if ($extension) {
            $filename .= '.' . $extension;
        }
        
        // Sicherer Filename für Content-Disposition (verhindert Header-Injection)
        $safeFilenames = sanitizeFilenameForHeader($filename);
        
        header('Content-Type: ' . ($document['mime_detected'] ?? 'application/octet-stream'));
        // RFC 5987 filename* für Unicode-Unterstützung, filename="..." als Fallback
        header('Content-Disposition: attachment; filename="' . $safeFilenames['filename'] . '"; filename*=UTF-8\'\'' . $safeFilenames['filenameStar']);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        
        // Datei ausgeben
        readfile($filePath);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        // Sicher: Keine internen Fehlerdetails an Client
        $isDev = \TOM\Infrastructure\Security\SecurityHelper::isDevMode();
        echo 'Download failed' . ($isDev ? ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : '');
    }
}

/**
 * View-Handler (Preview für PDFs und Bilder)
 */
function handleView(DocumentService $service, string $documentUuid): void
{
    try {
        // Berechtigungsprüfung: Prüfe ob User Zugriff auf das Dokument hat
        require_once __DIR__ . '/api-security.php';
        $currentUser = AuthHelper::getCurrentUser();
        
        // Prüfe ob Dokument existiert
        $document = $service->getDocument($documentUuid);
        if (!$document) {
            http_response_code(404);
            echo 'Document not found';
            return;
        }
        
        // Berechtigungsprüfung: Dokument muss an mindestens eine Entität angehängt sein
        // (später: zusätzlich prüfen ob User Zugriff auf diese Entität hat)
        $attachments = $service->getDocumentAttachments($documentUuid);
        if (empty($attachments)) {
            // Dokument ohne Attachments: Nur Admins dürfen zugreifen
            if (!$currentUser || !in_array('admin', $currentUser['roles'] ?? [])) {
                http_response_code(403);
                echo 'Access denied';
                return;
            }
        }
        // TODO: Später erweitern: Prüfe ob User Zugriff auf mindestens eine der Entitäten hat
        
        // Nur wenn scan_status = clean
        if ($document['scan_status'] !== 'clean') {
            http_response_code(403);
            echo 'Document is not available for viewing (scan status: ' . $document['scan_status'] . ')';
            return;
        }
        
        // Nur für PDFs und Bilder
        $mimeType = $document['mime_detected'] ?? '';
        if ($mimeType !== 'application/pdf' && !str_starts_with($mimeType, 'image/')) {
            // Für andere Dateitypen: Redirect zu Download
            header('Location: /tom3/public/api/documents/' . $documentUuid . '/download');
            exit;
        }
        
        // Blob-Dateipfad abrufen
        $blobService = new \TOM\Service\Document\BlobService();
        $filePath = $blobService->getBlobFilePath($document['current_blob_uuid']);
        
        if (!$filePath || !file_exists($filePath)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }
        
        // View-Header (inline statt attachment)
        $safeFilenames = sanitizeFilenameForHeader($document['title'] ?? 'document');
        header('Content-Type: ' . $mimeType);
        // RFC 5987 filename* für Unicode-Unterstützung, filename="..." als Fallback
        header('Content-Disposition: inline; filename="' . $safeFilenames['filename'] . '"; filename*=UTF-8\'\'' . $safeFilenames['filenameStar']);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        
        // Content-Security-Policy für PDFs (verhindert XSS durch bösartige PDFs)
        if ($mimeType === 'application/pdf') {
            header('Content-Security-Policy: sandbox allow-same-origin allow-scripts');
        }
        
        // Datei ausgeben
        readfile($filePath);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        // Sicher: Keine internen Fehlerdetails an Client
        $isDev = \TOM\Infrastructure\Security\SecurityHelper::isDevMode();
        echo 'View failed' . ($isDev ? ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : '');
    }
}

/**
 * Attachment entfernen
 */
function handleDetach(DocumentService $service, ?string $attachmentUuid): void
{
    if (!$attachmentUuid) {
        jsonResponse(['error' => 'attachment_uuid is required'], 400);
        return;
    }
    
    try {
        $success = $service->detachDocument($attachmentUuid);
        
        if (!$success) {
            jsonResponse(['error' => 'Attachment not found'], 404);
            return;
        }
        
        jsonResponse(['success' => true, 'message' => 'Attachment removed']);
    } catch (Exception $e) {
        handleApiException($e, 'Detach failed');
    }
}

/**
 * Document löschen
 */
function handleDeleteDocument(DocumentService $service, string $documentUuid, ?int $userId): void
{
    try {
        $success = $service->deleteDocument($documentUuid);
        
        if (!$success) {
            jsonResponse(['error' => 'Document not found'], 404);
            return;
        }
        
        jsonResponse(['success' => true, 'message' => 'Document deleted']);
    } catch (Exception $e) {
        handleApiException($e, 'Delete failed');
    }
}

/**
 * Dokumenten-Suche (FULLTEXT)
 */
function handleSearchDocuments(DocumentService $service): void
{
    $query = $_GET['q'] ?? '';
    
    // Query ist optional - wenn leer, werden alle Dokumente zurückgegeben (mit Filtern)
    $filters = [];
    
    // Suchfilter
    if (!empty($_GET['entity_type'])) {
        $filters['entity_type'] = $_GET['entity_type'];
    }
    if (!empty($_GET['entity_uuid'])) {
        $filters['entity_uuid'] = $_GET['entity_uuid'];
    }
    if (!empty($_GET['classification'])) {
        $filters['classification'] = $_GET['classification'];
    }
    if (!empty($_GET['tags'])) {
        $filters['tags'] = is_array($_GET['tags']) ? $_GET['tags'] : explode(',', $_GET['tags']);
    }
    if (!empty($_GET['status'])) {
        $filters['status'] = $_GET['status'];
    }
    if (!empty($_GET['scan_status'])) {
        $filters['scan_status'] = $_GET['scan_status'];
    }
    if (!empty($_GET['source_type'])) {
        $filters['source_type'] = $_GET['source_type'];
    }
    if (!empty($_GET['role'])) {
        $filters['role'] = $_GET['role'];
    }
    if (!empty($_GET['date_from'])) {
        $filters['date_from'] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $filters['date_to'] = $_GET['date_to'];
    }
    if (isset($_GET['orphaned_only']) && $_GET['orphaned_only'] === '1') {
        $filters['orphaned_only'] = true;
    }
    if (!empty($_GET['limit'])) {
        $filters['limit'] = (int)$_GET['limit'];
    }
    if (!empty($_GET['offset'])) {
        $filters['offset'] = (int)$_GET['offset'];
    }
    
    try {
        $results = [];
        
        // Wenn Query leer, verwende searchDocumentsInTitle (unterstützt alle Filter ohne FULLTEXT)
        if (empty($query)) {
            $results = $service->searchDocumentsInTitle('*', $filters);
        } else {
            // Suche in extracted_text UND Titel (bereits kombiniert in searchDocuments)
            $results = $service->searchDocuments($query, $filters);
            
            // Falls keine Ergebnisse, auch nur in Titel suchen
            if (empty($results)) {
                $results = $service->searchDocumentsInTitle($query, $filters);
            }
        }
        
        // Für jedes Dokument die Attachments laden (zeigt wo es hängt)
        foreach ($results as &$doc) {
            $attachments = $service->getDocumentAttachments($doc['document_uuid']);
            $doc['attachments'] = $attachments;
        }
        
        jsonResponse($results);
    } catch (Exception $e) {
        handleApiException($e, 'Search failed');
    }
}

/**
 * Document-Gruppe abrufen (mit allen Versionen)
 */
function handleGetDocumentGroup(DocumentService $service, string $groupUuid): void
{
    try {
        $group = $service->getDocumentGroup($groupUuid);
        
        if (!$group) {
            jsonResponse(['error' => 'Document group not found'], 404);
            return;
        }
        
        jsonResponse($group);
    } catch (Exception $e) {
        handleApiException($e, 'Get document group failed');
    }
}

/**
 * Neue Version hochladen
 */
function handleUploadVersion(DocumentService $service, ?string $groupUuid, ?int $userId): void
{
    if (!$groupUuid) {
        jsonResponse(['error' => 'group_uuid is required'], 400);
        return;
    }
    
    if (empty($_FILES['file'])) {
        jsonResponse(['error' => 'No file uploaded'], 400);
        return;
    }
    
    $file = $_FILES['file'];
    
    // Validierung
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Upload failed'], 400);
        return;
    }
    
    // Max. Dateigröße (50MB)
    $maxSize = 50 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        jsonResponse(['error' => 'File too large (max 50MB)'], 400);
        return;
    }
    
    // Metadata aus POST
    $metadata = [
        'title' => $_POST['title'] ?? null,
        'classification' => $_POST['classification'] ?? 'other',
        'tags' => !empty($_POST['tags']) ? json_decode($_POST['tags'], true) : [],
        'created_by_user_id' => $userId
    ];
    
    $supersede = !isset($_POST['supersede']) || $_POST['supersede'] !== 'false';
    
    try {
        $result = $service->createVersion(
            $groupUuid,
            $file,
            $metadata,
            $supersede
        );
        
        jsonResponse($result, 201);
    } catch (InvalidArgumentException $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    } catch (Exception $e) {
        handleApiException($e, 'Version upload failed');
    }
}


