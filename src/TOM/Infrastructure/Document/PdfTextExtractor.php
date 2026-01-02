<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

use Smalot\PdfParser\Parser;

/**
 * Extrahiert Text aus PDF-Dateien
 */
class PdfTextExtractor
{
    private ?Parser $parser = null;
    
    /**
     * Extrahiert Text aus einer PDF-Datei
     * 
     * @param string $filePath Pfad zur PDF-Datei
     * @return string Extrahierter Text
     * @throws \Exception Bei Fehlern
     */
    public function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        try {
            if ($this->parser === null) {
                $this->parser = new Parser();
            }
            
            $pdf = $this->parser->parseFile($filePath);
            $text = $pdf->getText();
            
            // Normalisiere Text (mehrfache Leerzeichen, Zeilenumbrüche)
            $text = preg_replace('/\s+/u', ' ', $text);
            $text = trim($text);
            
            return $text;
        } catch (\Exception $e) {
            throw new \RuntimeException("Fehler bei PDF-Extraktion: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Holt Metadaten aus der PDF (Seitenzahl, etc.)
     * 
     * @param string $filePath Pfad zur PDF-Datei
     * @return array Metadaten (pages, title, author, etc.)
     */
    public function getMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        try {
            if ($this->parser === null) {
                $this->parser = new Parser();
            }
            
            $pdf = $this->parser->parseFile($filePath);
            $details = $pdf->getDetails();
            
            $metadata = [
                'pages' => $this->extractPageCount($pdf),
                'title' => $details['Title'] ?? null,
                'author' => $details['Author'] ?? null,
                'subject' => $details['Subject'] ?? null,
                'creator' => $details['Creator'] ?? null,
                'producer' => $details['Producer'] ?? null,
                'created' => $details['CreationDate'] ?? null,
                'modified' => $details['ModDate'] ?? null
            ];
            
            // Entferne null-Werte
            return array_filter($metadata, fn($value) => $value !== null);
        } catch (\Exception $e) {
            // Bei Fehlern: Leere Metadaten zurückgeben
            return [];
        }
    }
    
    /**
     * Versucht die Seitenzahl aus der PDF zu extrahieren
     */
    private function extractPageCount($pdf): ?int
    {
        try {
            $pages = $pdf->getPages();
            return count($pages);
        } catch (\Exception $e) {
            return null;
        }
    }
}
