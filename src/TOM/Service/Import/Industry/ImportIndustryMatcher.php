<?php
declare(strict_types=1);

namespace TOM\Service\Import\Industry;

/**
 * ImportIndustryMatcher
 * 
 * Handles fuzzy matching and similarity calculation for industries:
 * - Find similar industries using multiple strategies
 * - Calculate similarity scores
 * - Normalize industry names and codes
 */
class ImportIndustryMatcher
{
    /**
     * Findet ähnliche Branche (Fuzzy Match)
     * 
     * @param string $excelValue Excel-Wert
     * @param array $dbIndustries Array von DB-Branchen
     * @return array ['industry' => ?array, 'similarity' => float]
     */
    public function findSimilarIndustry(string $excelValue, array $dbIndustries): array
    {
        $bestMatch = null;
        $bestSimilarity = 0;
        
        $excelLower = mb_strtolower(trim($excelValue));
        
        // Extrahiere Schlüsselwörter aus Excel-Wert (z.B. "Chemieindustrie" → ["chemie", "industrie"])
        $excelKeywords = preg_split('/[\s\-_]+/', $excelLower);
        $excelKeywords = array_filter($excelKeywords, function($kw) {
            return mb_strlen($kw) >= 3; // Mindestens 3 Zeichen
        });
        
        foreach ($dbIndustries as $industry) {
            $dbName = mb_strtolower(trim($industry['name']));
            $dbCode = mb_strtolower(trim($industry['code'] ?? ''));
            
            // Exakter Match
            if ($excelLower === $dbName) {
                return [
                    'industry' => $industry,
                    'similarity' => 1.0
                ];
            }
            
            // Match nach Code (z.B. "C20" findet "C20 - Herstellung...")
            if (!empty($dbCode) && ($excelLower === $dbCode || strpos($excelLower, $dbCode) === 0 || strpos($dbCode, $excelLower) === 0)) {
                $similarity = 0.95;
            }
            // Teilstring-Match (beide Richtungen)
            elseif (strpos($excelLower, $dbName) !== false || strpos($dbName, $excelLower) !== false) {
                $similarity = 0.9;
            }
            // Schlüsselwort-Match (z.B. "chemie" in "chemischen Erzeugnissen")
            elseif (!empty($excelKeywords)) {
                $matchedKeywords = 0;
                foreach ($excelKeywords as $keyword) {
                    if (strpos($dbName, $keyword) !== false) {
                        $matchedKeywords++;
                    }
                }
                if ($matchedKeywords > 0) {
                    $similarity = 0.7 + ($matchedKeywords / count($excelKeywords)) * 0.2; // 0.7-0.9
                } else {
                    // Levenshtein-Distanz als Fallback
                    $maxLen = max(mb_strlen($excelLower), mb_strlen($dbName));
                    $distance = levenshtein($excelLower, $dbName);
                    $similarity = 1.0 - ($distance / $maxLen);
                }
            } else {
                // Levenshtein-Distanz
                $maxLen = max(mb_strlen($excelLower), mb_strlen($dbName));
                $distance = levenshtein($excelLower, $dbName);
                $similarity = 1.0 - ($distance / $maxLen);
            }
            
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = $industry;
            }
        }
        
        return [
            'industry' => $bestMatch,
            'similarity' => $bestSimilarity
        ];
    }
    
    /**
     * Normalisiert einen Branchen-Namen für Vergleich
     */
    public function normalizeIndustryName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
    
    /**
     * Erstellt Maps für schnelles Lookup (Name und Code)
     * 
     * @param array $dbIndustries Array von DB-Branchen
     * @return array ['nameMap' => array, 'codeMap' => array]
     */
    public function buildIndustryMaps(array $dbIndustries): array
    {
        $nameMap = [];
        $codeMap = [];
        
        foreach ($dbIndustries as $industry) {
            $normalized = $this->normalizeIndustryName($industry['name']);
            $nameMap[$normalized] = $industry;
            
            // Auch Code-Mapping (z.B. "C" → "C - Verarbeitendes Gewerbe")
            if (!empty($industry['code'])) {
                $codeNormalized = mb_strtolower(trim($industry['code']));
                $codeMap[$codeNormalized] = $industry;
            }
        }
        
        return [
            'nameMap' => $nameMap,
            'codeMap' => $codeMap
        ];
    }
    
    /**
     * Findet Branche durch exakten Match (Name oder Code)
     * 
     * @param string $excelValue Excel-Wert
     * @param array $nameMap Normalisierte Name-Map
     * @param array $codeMap Normalisierte Code-Map
     * @return array|null Gefundene Branche oder null
     */
    public function findExactMatch(string $excelValue, array $nameMap, array $codeMap): ?array
    {
        $normalized = $this->normalizeIndustryName($excelValue);
        
        // 1. Exakter Match nach Name
        if (isset($nameMap[$normalized])) {
            return $nameMap[$normalized];
        }
        
        // 2. Match nach Code (z.B. "C" oder "C20")
        if (isset($codeMap[$normalized])) {
            return $codeMap[$normalized];
        }
        
        // 3. Teilstring-Match im Code (z.B. "C20" findet "C20 - Herstellung...")
        foreach ($codeMap as $code => $industry) {
            if (strpos($code, $normalized) === 0 || strpos($normalized, $code) === 0) {
                return $industry;
            }
        }
        
        return null;
    }
}


