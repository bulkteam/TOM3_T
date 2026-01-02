<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

/**
 * Extrahiert Text aus einfachen Textdateien (TXT, CSV, HTML)
 */
class TextFileExtractor
{
    /**
     * Extrahiert Text aus einer Textdatei
     * 
     * @param string $filePath Pfad zur Datei
     * @param string $mimeType MIME-Type der Datei
     * @return string Extrahierter Text
     * @throws \Exception Bei Fehlern
     */
    public function extract(string $filePath, string $mimeType): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \RuntimeException("Konnte Datei nicht lesen: {$filePath}");
            }
            
            // Encoding erkennen und konvertieren
            $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
            
            // Je nach MIME-Type unterschiedlich behandeln
            if ($mimeType === 'text/csv') {
                return $this->extractFromCsv($content);
            } elseif ($mimeType === 'text/html' || $mimeType === 'text/xml') {
                return $this->extractFromHtml($content);
            } else {
                // Plain Text
                return $this->extractFromPlainText($content);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException("Fehler bei Text-Extraktion: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Extrahiert Text aus Plain Text
     */
    private function extractFromPlainText(string $content): string
    {
        // Normalisiere Zeilenumbrüche
        $text = str_replace(["\r\n", "\r"], "\n", $content);
        
        // Entferne zu viele Leerzeilen
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        
        // Trim
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Extrahiert Text aus CSV
     */
    private function extractFromCsv(string $content): string
    {
        // CSV parsen und als Text formatieren
        // Verwende str_getcsv mit korrekter Zeilen-Trennung
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $textParts = [];
        
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            
            // Parse CSV-Zeile (berücksichtigt Anführungszeichen und Kommas)
            $fields = str_getcsv($line);
            
            // Filtere leere Felder
            $nonEmptyFields = array_filter($fields, function($field) {
                return trim($field) !== '';
            });
            
            if (!empty($nonEmptyFields)) {
                // Füge Felder zusammen (für bessere Lesbarkeit)
                $textParts[] = implode(' | ', array_map('trim', $nonEmptyFields));
            }
        }
        
        $text = implode("\n", $textParts);
        
        // Normalisiere
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Extrahiert Text aus HTML
     */
    private function extractFromHtml(string $content): string
    {
        // Versuche mit DOMDocument zu parsen
        $dom = new \DOMDocument();
        
        // Suppress warnings für fehlerhaftes HTML
        libxml_use_internal_errors(true);
        
        // Versuche HTML zu laden
        $loaded = @$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        if ($loaded) {
            // Extrahiere Text aus allen Text-Knoten
            $xpath = new \DOMXPath($dom);
            
            // Entferne Script- und Style-Tags
            $scripts = $xpath->query('//script | //style');
            foreach ($scripts as $script) {
                $script->parentNode->removeChild($script);
            }
            
            // Hole Text-Inhalt
            $textNodes = $xpath->query('//text()');
            $textParts = [];
            
            foreach ($textNodes as $node) {
                $text = trim($node->nodeValue ?? '');
                if (!empty($text) && strlen($text) > 1) {
                    $textParts[] = $text;
                }
            }
            
            $text = implode(' ', $textParts);
        } else {
            // Fallback: Einfache Regex-basierte Extraktion
            // Entferne Script- und Style-Tags
            $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
            $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
            
            // Entferne HTML-Tags
            $text = strip_tags($text);
            
            // Decode HTML-Entities
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        // Normalisiere Whitespace
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = trim($text);
        
        libxml_clear_errors();
        
        return $text;
    }
    
    /**
     * Holt Metadaten aus der Datei
     * 
     * @param string $filePath Pfad zur Datei
     * @param string $mimeType MIME-Type
     * @return array Metadaten
     */
    public function getMetadata(string $filePath, string $mimeType): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $metadata = [];
        
        // Dateigröße
        $size = filesize($filePath);
        if ($size !== false) {
            $metadata['size_bytes'] = $size;
        }
        
        // Zeilenanzahl (für TXT/CSV)
        if (in_array($mimeType, ['text/plain', 'text/csv'])) {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                $lines = substr_count($content, "\n") + (substr($content, -1) !== "\n" ? 1 : 0);
                $metadata['lines'] = $lines;
            }
        }
        
        // Für CSV: Spaltenanzahl (erste Zeile)
        if ($mimeType === 'text/csv') {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                $firstLine = strtok($content, "\n");
                if ($firstLine !== false) {
                    $columns = str_getcsv($firstLine);
                    $metadata['columns'] = count($columns);
                }
            }
        }
        
        // Für HTML: Titel extrahieren
        if ($mimeType === 'text/html') {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $content, $matches)) {
                    $metadata['title'] = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        
        return $metadata;
    }
}
