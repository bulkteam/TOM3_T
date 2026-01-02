<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

/**
 * Extrahiert Text aus alten DOC-Dateien (Microsoft Word 97-2003)
 * 
 * DOC ist ein binäres Format (OLE2). Diese Klasse verwendet eine einfache
 * Text-Extraktion über externe Tools oder PHP-Bibliotheken.
 * 
 * Hinweis: Für zuverlässige DOC-Extraktion wird empfohlen, externe Tools zu verwenden:
 * - LibreOffice (headless): libreoffice --headless --convert-to txt
 * - Antiword (Linux): antiword file.doc
 * - catdoc (Linux): catdoc file.doc
 */
class DocTextExtractor
{
    /**
     * Extrahiert Text aus einer DOC-Datei
     * 
     * @param string $filePath Pfad zur DOC-Datei
     * @return string Extrahierter Text
     * @throws \Exception Bei Fehlern
     */
    public function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        // Versuche zuerst mit externen Tools
        $text = $this->extractWithLibreOffice($filePath);
        if ($text !== null) {
            return $text;
        }
        
        $text = $this->extractWithAntiword($filePath);
        if ($text !== null) {
            return $text;
        }
        
        $text = $this->extractWithCatdoc($filePath);
        if ($text !== null) {
            return $text;
        }
        
        // Fallback: Einfache binäre Text-Extraktion (nicht sehr zuverlässig)
        return $this->extractFallback($filePath);
    }
    
    /**
     * Versucht Extraktion mit LibreOffice (headless)
     */
    private function extractWithLibreOffice(string $filePath): ?string
    {
        // Prüfe, ob LibreOffice verfügbar ist
        $libreOfficePath = $this->findExecutable('soffice') ?? $this->findExecutable('libreoffice');
        if (!$libreOfficePath) {
            return null;
        }
        
        try {
            $tempDir = sys_get_temp_dir() . '/doc_extract_' . uniqid();
            @mkdir($tempDir, 0755, true);
            
            // Konvertiere DOC zu TXT
            // Windows: Verwende doppelte Anführungszeichen für Pfade
            if (PHP_OS_FAMILY === 'Windows') {
                $command = '"' . $libreOfficePath . '"' . 
                          ' --headless --convert-to txt:Text' .
                          ' --outdir "' . $tempDir . '"' .
                          ' "' . $filePath . '" 2>&1';
            } else {
                $command = escapeshellarg($libreOfficePath) . 
                          ' --headless --convert-to txt:Text' .
                          ' --outdir ' . escapeshellarg($tempDir) .
                          ' ' . escapeshellarg($filePath) . ' 2>&1';
            }
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                // Finde die generierte TXT-Datei
                $baseName = pathinfo($filePath, PATHINFO_FILENAME);
                $txtFile = $tempDir . '/' . $baseName . '.txt';
                
                if (file_exists($txtFile)) {
                    $text = file_get_contents($txtFile);
                    @unlink($txtFile);
                    @rmdir($tempDir);
                    
                    if ($text !== false) {
                        return trim($text);
                    }
                }
            }
            
            @rmdir($tempDir);
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Versucht Extraktion mit Antiword
     */
    private function extractWithAntiword(string $filePath): ?string
    {
        $antiwordPath = $this->findExecutable('antiword');
        if (!$antiwordPath) {
            return null;
        }
        
        try {
            $command = escapeshellarg($antiwordPath) . ' ' . escapeshellarg($filePath) . ' 2>&1';
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && !empty($output)) {
                $text = implode("\n", $output);
                return trim($text);
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Versucht Extraktion mit catdoc
     */
    private function extractWithCatdoc(string $filePath): ?string
    {
        $catdocPath = $this->findExecutable('catdoc');
        if (!$catdocPath) {
            return null;
        }
        
        try {
            $command = escapeshellarg($catdocPath) . ' ' . escapeshellarg($filePath) . ' 2>&1';
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && !empty($output)) {
                $text = implode("\n", $output);
                return trim($text);
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Fallback: Einfache binäre Text-Extraktion
     * 
     * Extrahiert alle druckbaren ASCII/UTF-8 Zeichen aus der Datei.
     * Nicht sehr zuverlässig, aber besser als nichts.
     */
    private function extractFallback(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Konnte DOC-Datei nicht lesen");
        }
        
        // Extrahiere druckbare Zeichen (ASCII + UTF-8)
        // DOC-Dateien enthalten oft Text in UTF-16 oder anderen Encodings
        $text = '';
        $length = strlen($content);
        
        // Versuche UTF-16 zu erkennen
        if ($length >= 2 && $content[0] === "\xFF" && $content[1] === "\xFE") {
            // UTF-16 LE
            $text = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif ($length >= 2 && $content[0] === "\xFE" && $content[1] === "\xFF") {
            // UTF-16 BE
            $text = mb_convert_encoding($content, 'UTF-8', 'UTF-16BE');
        } else {
            // Versuche verschiedene Encodings
            $encodings = ['Windows-1252', 'ISO-8859-1', 'UTF-8'];
            foreach ($encodings as $encoding) {
                $decoded = @mb_convert_encoding($content, 'UTF-8', $encoding);
                if ($decoded !== false) {
                    $text = $decoded;
                    break;
                }
            }
            
            if (empty($text)) {
                $text = $content;
            }
        }
        
        // Filtere nur druckbare Zeichen
        $text = preg_replace('/[^\x20-\x7E\xC0-\xFF\x{0100}-\x{FFFF}]/u', ' ', $text);
        
        // Normalisiere Whitespace
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Findet ausführbare Datei im PATH
     */
    private function findExecutable(string $name): ?string
    {
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        
        // Windows: Füge .exe hinzu
        if (PHP_OS_FAMILY === 'Windows') {
            $name .= '.exe';
        }
        
        foreach ($paths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $name;
            if (file_exists($fullPath) && is_executable($fullPath)) {
                return $fullPath;
            }
        }
        
        // Windows: Prüfe auch in typischen Installationspfaden
        if (PHP_OS_FAMILY === 'Windows') {
            $commonPaths = [
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            ];
            
            foreach ($commonPaths as $commonPath) {
                if (file_exists($commonPath)) {
                    return $commonPath;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Holt Metadaten aus der DOC-Datei
     * 
     * @param string $filePath Pfad zur DOC-Datei
     * @return array Metadaten
     */
    public function getMetadata(string $filePath): array
    {
        // DOC-Metadaten sind schwer zu extrahieren ohne spezielle Bibliotheken
        // Für jetzt: Basis-Informationen
        $metadata = [];
        
        if (file_exists($filePath)) {
            $metadata['size_bytes'] = filesize($filePath);
        }
        
        return $metadata;
    }
}
