<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

/**
 * Extrahiert Text aus DOCX-Dateien
 * 
 * DOCX ist ein ZIP-Archiv, das word/document.xml enthält.
 * Diese Klasse parst das XML direkt, ohne externe Bibliotheken.
 */
class DocxTextExtractor
{
    /**
     * Extrahiert Text aus einer DOCX-Datei
     * 
     * @param string $filePath Pfad zur DOCX-Datei
     * @return string Extrahierter Text
     * @throws \Exception Bei Fehlern
     */
    public function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        // Prüfe, ob ZIP-Extension verfügbar ist
        if (!extension_loaded('zip')) {
            throw new \RuntimeException("ZIP-Extension ist nicht verfügbar. DOCX-Extraktion erfordert ext-zip.");
        }
        
        try {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) !== true) {
                throw new \RuntimeException("Konnte DOCX-Datei nicht öffnen: {$filePath}");
            }
            
            // Lese word/document.xml aus dem ZIP
            $documentXml = $zip->getFromName('word/document.xml');
            if ($documentXml === false) {
                $zip->close();
                throw new \RuntimeException("Konnte word/document.xml nicht aus DOCX lesen");
            }
            
            $zip->close();
            
            // Parse XML
            $dom = new \DOMDocument();
            if (!@$dom->loadXML($documentXml)) {
                throw new \RuntimeException("Konnte XML nicht parsen");
            }
            
            // Extrahiere Text aus allen <w:t> Elementen (Word Text-Elemente)
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            
            $textNodes = $xpath->query('//w:t');
            $textParts = [];
            
            foreach ($textNodes as $node) {
                $text = trim($node->nodeValue ?? '');
                if (!empty($text)) {
                    $textParts[] = $text;
                }
            }
            
            $text = implode(' ', $textParts);
            
            // Normalisiere Text (mehrfache Leerzeichen)
            $text = preg_replace('/\s+/u', ' ', $text);
            $text = trim($text);
            
            return $text;
        } catch (\Exception $e) {
            throw new \RuntimeException("Fehler bei DOCX-Extraktion: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Holt Metadaten aus der DOCX (Seitenzahl, etc.)
     * 
     * @param string $filePath Pfad zur DOCX-Datei
     * @return array Metadaten
     */
    public function getMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        if (!extension_loaded('zip')) {
            return [];
        }
        
        try {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) !== true) {
                return [];
            }
            
            $metadata = [];
            
            // Versuche core.xml zu lesen (enthält Metadaten)
            $coreXml = $zip->getFromName('docProps/core.xml');
            if ($coreXml !== false) {
                $dom = new \DOMDocument();
                if (@$dom->loadXML($coreXml)) {
                    $xpath = new \DOMXPath($dom);
                    $xpath->registerNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');
                    $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                    
                    $title = $xpath->evaluate('string(//dc:title)');
                    $creator = $xpath->evaluate('string(//dc:creator)');
                    $subject = $xpath->evaluate('string(//dc:subject)');
                    $created = $xpath->evaluate('string(//cp:created)');
                    $modified = $xpath->evaluate('string(//cp:modified)');
                    
                    if (!empty($title)) $metadata['title'] = $title;
                    if (!empty($creator)) $metadata['author'] = $creator;
                    if (!empty($subject)) $metadata['subject'] = $subject;
                    if (!empty($created)) $metadata['created'] = $created;
                    if (!empty($modified)) $metadata['modified'] = $modified;
                }
            }
            
            $zip->close();
            
            return $metadata;
        } catch (\Exception $e) {
            return [];
        }
    }
}
