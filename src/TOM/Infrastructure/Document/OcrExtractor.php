<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

/**
 * Extrahiert Text aus Bildern mit OCR (Optical Character Recognition)
 * 
 * Verwendet Tesseract OCR für die Texterkennung.
 * 
 * Installation Tesseract:
 * - Windows: https://github.com/UB-Mannheim/tesseract/wiki
 * - Linux: sudo apt-get install tesseract-ocr tesseract-ocr-deu
 * - macOS: brew install tesseract
 */
class OcrExtractor
{
    private ?string $tesseractPath = null;
    private string $language = 'deu+eng'; // Deutsch + Englisch
    
    public function __construct(?string $tesseractPath = null, string $language = 'deu+eng')
    {
        $this->tesseractPath = $tesseractPath;
        $this->language = $language;
    }
    
    /**
     * Extrahiert Text aus einem Bild mit OCR
     * 
     * @param string $filePath Pfad zur Bilddatei
     * @return string Extrahierter Text
     * @throws \Exception Bei Fehlern
     */
    public function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        // Prüfe, ob Tesseract verfügbar ist
        $tesseract = $this->getTesseractPath();
        if (!$tesseract) {
            throw new \RuntimeException("Tesseract OCR ist nicht verfügbar. Bitte installieren Sie Tesseract.");
        }
        
        try {
            // Erstelle temporäre Ausgabedatei
            $tempFile = sys_get_temp_dir() . '/ocr_output_' . uniqid() . '.txt';
            
            // Tesseract-Befehl
            $command = escapeshellarg($tesseract) .
                      ' ' . escapeshellarg($filePath) .
                      ' ' . escapeshellarg(pathinfo($tempFile, PATHINFO_FILENAME)) .
                      ' -l ' . escapeshellarg($this->language) .
                      ' --psm 3' . // Auto page segmentation
                      ' 2>&1';
            
            // Führe OCR aus
            exec($command, $output, $returnCode);
            
            // Lese Ergebnis
            $resultFile = pathinfo($tempFile, PATHINFO_DIRNAME) . '/' . pathinfo($tempFile, PATHINFO_FILENAME) . '.txt';
            
            if ($returnCode === 0 && file_exists($resultFile)) {
                $text = file_get_contents($resultFile);
                @unlink($resultFile);
                
                if ($text !== false) {
                    // Normalisiere Text
                    $text = preg_replace('/\s+/u', ' ', $text);
                    $text = trim($text);
                    return $text;
                }
            }
            
            // Bei Fehler: Ausgabe loggen
            $error = implode("\n", $output);
            throw new \RuntimeException("OCR-Fehler: " . $error);
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Fehler bei OCR-Extraktion: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Erkennt die Sprache des Textes (vereinfacht)
     * 
     * @param string $text Extrahierter Text
     * @return string Sprache (z.B. 'de', 'en')
     */
    public function detectLanguage(string $text): string
    {
        // Einfache Heuristik: Zähle typische Zeichen
        $germanChars = ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'];
        $germanCount = 0;
        
        foreach ($germanChars as $char) {
            $germanCount += mb_substr_count($text, $char);
        }
        
        // Wenn viele deutsche Zeichen: Deutsch, sonst Englisch
        if ($germanCount > 5) {
            return 'de';
        }
        
        return 'en';
    }
    
    /**
     * Findet Tesseract-Pfad
     */
    private function getTesseractPath(): ?string
    {
        // Wenn explizit gesetzt, verwende diesen
        if ($this->tesseractPath && file_exists($this->tesseractPath)) {
            return $this->tesseractPath;
        }
        
        // Suche im PATH
        $name = PHP_OS_FAMILY === 'Windows' ? 'tesseract.exe' : 'tesseract';
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        
        foreach ($paths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $name;
            if (file_exists($fullPath) && is_executable($fullPath)) {
                return $fullPath;
            }
        }
        
        // Windows: Prüfe typische Installationspfade
        if (PHP_OS_FAMILY === 'Windows') {
            $commonPaths = [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
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
     * Prüft, ob Tesseract verfügbar ist
     */
    public function isAvailable(): bool
    {
        return $this->getTesseractPath() !== null;
    }
    
    /**
     * Holt Metadaten (Sprache, etc.)
     * 
     * @param string $filePath Pfad zur Bilddatei
     * @return array Metadaten
     */
    public function getMetadata(string $filePath): array
    {
        $metadata = [];
        
        if (file_exists($filePath)) {
            // Bild-Dimensionen
            $imageInfo = @getimagesize($filePath);
            if ($imageInfo !== false) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['mime'] = $imageInfo['mime'];
            }
            
            $metadata['size_bytes'] = filesize($filePath);
        }
        
        return $metadata;
    }
}


