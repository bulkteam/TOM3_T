<?php
declare(strict_types=1);

namespace TOM\Service\Import;

/**
 * IndustryNormalizer
 * 
 * Normalisiert Industry-Namen für Matching:
 * - Umlaute normalisieren
 * - Groß-/Kleinschreibung
 * - Interpunktion entfernen
 * - Suffixe entfernen (industrie, hersteller, etc.)
 */
final class IndustryNormalizer
{
    /**
     * Normalisiert einen String für Matching
     * 
     * @param string $input Original-String
     * @return string Normalisierter String
     */
    public function normalize(string $input): string
    {
        // 1. Trim
        $s = trim($input);
        
        // 2. Lowercase
        $s = mb_strtolower($s, 'UTF-8');
        
        // 3. Umlaute normalisieren
        $s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $s);
        
        // 4. Interpunktion entfernen (behalte nur Buchstaben, Zahlen, Leerzeichen)
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        
        // 5. Mehrfache Leerzeichen zu einem
        $s = preg_replace('/\s+/', ' ', $s);
        $s = trim($s);
        
        // 6. Suffixe entfernen (häufige Endungen, die Matching stören)
        $suffixes = [
            'industrie',
            'hersteller',
            'produktion',
            'fertigung',
            'handel',
            'verarbeitung',
            'erzeugung',
            'erzeugnisse',
            'produkte',
            'waren',
            'güter'
        ];
        
        foreach ($suffixes as $suffix) {
            // Entferne Suffix wenn es am Ende steht (mit Wortgrenze ODER direkt am Ende)
            // Zuerst versuche Wortgrenze (separates Wort)
            $pattern = '/\b' . preg_quote($suffix, '/') . '\b/u';
            $s = preg_replace($pattern, '', $s);
            
            // Dann versuche direkt am Ende (für zusammengesetzte Wörter wie "chemieindustrie")
            $pattern = '/' . preg_quote($suffix, '/') . '$/u';
            $s = preg_replace($pattern, '', $s);
        }
        
        // 7. Nochmal Leerzeichen normalisieren
        $s = preg_replace('/\s+/', ' ', $s);
        $s = trim($s);
        
        return $s;
    }
    
    /**
     * Prüft, ob zwei Strings nach Normalisierung gleich sind
     * 
     * @param string $a
     * @param string $b
     * @return bool
     */
    public function equals(string $a, string $b): bool
    {
        return $this->normalize($a) === $this->normalize($b);
    }
}

