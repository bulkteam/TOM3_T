<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

/**
 * FileTypeValidator
 * 
 * Validiert Dateitypen über Magic Bytes (nicht Extension)
 * und prüft auf blockierte Dateitypen.
 */
class FileTypeValidator
{
    private const ALLOWED_MIMES = [
        // Dokumente
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
        
        // Bilder
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/tiff',
        
        // Text
        'text/plain',
        'text/csv',
        'text/html',
        
        // Archive (optional)
        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
    ];
    
    private const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar',
        'sh', 'ps1', 'msi', 'dll', 'sys', 'drv'
    ];
    
    private const BLOCKED_MACRO_EXTENSIONS = [
        'docm', 'xlsm', 'pptm', 'dotm', 'xltm', 'potm'
    ];
    
    /**
     * Validiert Datei
     * 
     * @param string $filePath Pfad zur Datei
     * @param string $originalFilename Original-Dateiname
     * @return array ['mime' => string, 'extension' => string]
     * @throws \InvalidArgumentException Wenn Dateityp nicht erlaubt
     */
    public function validate(string $filePath, string $originalFilename): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        // Magic Bytes prüfen (MIME-Type)
        $mime = $this->detectMimeType($filePath);
        
        // Extension prüfen
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        
        // Blockierte Extensions
        if (in_array($ext, self::BLOCKED_EXTENSIONS)) {
            throw new \InvalidArgumentException("Dateityp nicht erlaubt: .{$ext}");
        }
        
        // Office-Dateien mit Makros blocken
        if (in_array($ext, self::BLOCKED_MACRO_EXTENSIONS)) {
            throw new \InvalidArgumentException("Office-Dateien mit Makros sind nicht erlaubt: .{$ext}");
        }
        
        // MIME-Type prüfen
        // Fallback: Wenn MIME-Type nicht erkannt (application/octet-stream), aber Extension erlaubt ist, verwende Extension-basierte MIME-Type-Erkennung
        if (!in_array($mime, self::ALLOWED_MIMES)) {
            if ($mime === 'application/octet-stream' && $ext) {
                // Versuche MIME-Type aus Extension zu bestimmen
                $mimeFromExt = $this->getMimeTypeFromExtension($ext);
                if ($mimeFromExt && in_array($mimeFromExt, self::ALLOWED_MIMES)) {
                    $mime = $mimeFromExt;
                } else {
                    throw new \InvalidArgumentException("MIME-Type nicht erlaubt: {$mime} (Extension: .{$ext})");
                }
            } else {
                throw new \InvalidArgumentException("MIME-Type nicht erlaubt: {$mime}");
            }
        }
        
        // Zusätzliche Prüfung: Extension sollte zu MIME passen (warnend, nicht blockierend)
        // z.B. .pdf sollte application/pdf sein
        
        return [
            'mime' => $mime,
            'extension' => $ext,
            'original_filename' => $originalFilename
        ];
    }
    
    /**
     * Erkennt MIME-Type über Magic Bytes
     * 
     * @param string $filePath
     * @return string MIME-Type
     */
    private function detectMimeType(string $filePath): string
    {
        if (!function_exists('finfo_open')) {
            throw new \RuntimeException('fileinfo Extension nicht verfügbar');
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            throw new \RuntimeException('fileinfo konnte nicht initialisiert werden');
        }
        
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        if (!$mime) {
            throw new \RuntimeException('MIME-Type konnte nicht erkannt werden');
        }
        
        return $mime;
    }
    
    /**
     * Prüft ob Datei ein Bild ist
     * 
     * @param string $mime
     * @return bool
     */
    public function isImage(string $mime): bool
    {
        return strpos($mime, 'image/') === 0;
    }
    
    /**
     * Prüft ob Datei ein PDF ist
     * 
     * @param string $mime
     * @return bool
     */
    public function isPdf(string $mime): bool
    {
        return $mime === 'application/pdf';
    }
    
    /**
     * Prüft ob Datei ein Office-Dokument ist
     * 
     * @param string $mime
     * @return bool
     */
    public function isOfficeDocument(string $mime): bool
    {
        return in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        ]);
    }
    
    /**
     * Bestimmt MIME-Type aus Extension (Fallback wenn Magic Bytes nicht funktionieren)
     * 
     * @param string $ext Extension (ohne Punkt)
     * @return string|null MIME-Type oder null
     */
    private function getMimeTypeFromExtension(string $ext): ?string
    {
        $extensionMap = [
            // Dokumente
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            
            // Bilder
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            
            // Text
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'htm' => 'text/html',
            
            // Archive
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
        ];
        
        return $extensionMap[$ext] ?? null;
    }
}
