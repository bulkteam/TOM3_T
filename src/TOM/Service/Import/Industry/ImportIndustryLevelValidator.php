<?php
declare(strict_types=1);

namespace TOM\Service\Import\Industry;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * ImportIndustryLevelValidator
 * 
 * Handles validation of industry levels (1, 2, 3):
 * - Check industries against database
 * - Validate Level 2 under Level 1
 * - Validate Level 3 under Level 2
 * - Check consistency across levels
 */
class ImportIndustryLevelValidator
{
    private PDO $db;
    private ImportIndustryMatcher $matcher;
    
    public function __construct(?PDO $db = null, ?ImportIndustryMatcher $matcher = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->matcher = $matcher ?? new ImportIndustryMatcher();
    }
    
    /**
     * Prüft, ob Branchen in DB existieren
     * 
     * @param array $industryNames Liste von Branchen-Namen
     * @param bool $mainOnly Nur Hauptklassen (parent_industry_uuid IS NULL)
     * @return array ['found' => [...], 'missing' => [...], 'suggestions' => [...]]
     */
    public function checkIndustries(array $industryNames, bool $mainOnly): array
    {
        $found = [];
        $missing = [];
        $suggestions = [];
        
        // Lade alle Branchen aus DB
        if ($mainOnly) {
            $stmt = $this->db->prepare("
                SELECT industry_uuid, name, code
                FROM industry
                WHERE parent_industry_uuid IS NULL
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT industry_uuid, name, code, parent_industry_uuid
                FROM industry
            ");
        }
        $stmt->execute();
        $dbIndustries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Erstelle Maps für schnelles Lookup
        $maps = $this->matcher->buildIndustryMaps($dbIndustries);
        $nameMap = $maps['nameMap'];
        $codeMap = $maps['codeMap'];
        
        // Prüfe jede Excel-Branche
        foreach ($industryNames as $excelIndustry) {
            $foundIndustry = $this->matcher->findExactMatch($excelIndustry, $nameMap, $codeMap);
            
            if ($foundIndustry) {
                $found[] = [
                    'excel_value' => $excelIndustry,
                    'db_industry' => $foundIndustry
                ];
            } else {
                // Suche ähnliche Branchen (Fuzzy Match)
                $bestMatch = $this->matcher->findSimilarIndustry($excelIndustry, $dbIndustries);
                
                // Wenn Ähnlichkeit > 0.7, als "found" behandeln (nicht als "missing")
                if ($bestMatch['similarity'] > 0.7 && $bestMatch['industry']) {
                    $found[] = [
                        'excel_value' => $excelIndustry,
                        'db_industry' => $bestMatch['industry'],
                        'similarity' => $bestMatch['similarity']
                    ];
                    $suggestions[] = [
                        'excel_value' => $excelIndustry,
                        'suggested' => $bestMatch['industry'],
                        'similarity' => $bestMatch['similarity']
                    ];
                } else {
                    $missing[] = [
                        'excel_value' => $excelIndustry,
                        'similarity' => $bestMatch['similarity'] ?? 0,
                        'suggestion' => $bestMatch['industry'] ?? null
                    ];
                }
            }
        }
        
        return [
            'found' => $found,
            'missing' => $missing,
            'suggestions' => $suggestions
        ];
    }
    
    /**
     * Prüft Level 2 Branchen auf allen Level 2 Branchen (ohne Level 1 Einschränkung)
     * Wird verwendet, wenn "Oberkategorie" auf Level 2 gemappt wurde
     */
    public function checkIndustriesLevel2WithoutParent(array $level2Values): array
    {
        // Lade ALLE Level 2 Branchen (ohne Parent-Einschränkung)
        $stmt = $this->db->prepare("
            SELECT industry_uuid, name, code, parent_industry_uuid
            FROM industry
            WHERE parent_industry_uuid IS NOT NULL
        ");
        $stmt->execute();
        $dbLevel2Industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filtere nur Level 2 (nicht Level 3)
        $level2Only = [];
        foreach ($dbLevel2Industries as $industry) {
            // Prüfe, ob Parent ein Level 1 ist (parent_industry_uuid IS NULL beim Parent)
            $parentStmt = $this->db->prepare("SELECT parent_industry_uuid FROM industry WHERE industry_uuid = :uuid");
            $parentStmt->execute(['uuid' => $industry['parent_industry_uuid']]);
            $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($parent && $parent['parent_industry_uuid'] === null) {
                $level2Only[] = $industry;
            }
        }
        
        return $this->checkIndustriesAgainstList($level2Values, $level2Only);
    }
    
    /**
     * Prüft Branchenwerte gegen eine gegebene Liste von Branchen
     */
    public function checkIndustriesAgainstList(array $industryNames, array $dbIndustries): array
    {
        $found = [];
        $missing = [];
        $suggestions = [];
        
        // Erstelle Maps für schnelles Lookup
        $maps = $this->matcher->buildIndustryMaps($dbIndustries);
        $nameMap = $maps['nameMap'];
        $codeMap = $maps['codeMap'];
        
        // Prüfe jede Excel-Branche
        foreach ($industryNames as $excelIndustry) {
            $foundIndustry = $this->matcher->findExactMatch($excelIndustry, $nameMap, $codeMap);
            
            if ($foundIndustry) {
                $found[] = [
                    'excel_value' => $excelIndustry,
                    'db_industry' => $foundIndustry
                ];
            } else {
                // Suche ähnliche Branchen (Fuzzy Match)
                $bestMatch = $this->matcher->findSimilarIndustry($excelIndustry, $dbIndustries);
                
                // Wenn Ähnlichkeit > 0.7, als "found" behandeln (nicht als "missing")
                if ($bestMatch['similarity'] > 0.7 && $bestMatch['industry']) {
                    $found[] = [
                        'excel_value' => $excelIndustry,
                        'db_industry' => $bestMatch['industry'],
                        'similarity' => $bestMatch['similarity']
                    ];
                    $suggestions[] = [
                        'excel_value' => $excelIndustry,
                        'suggested' => $bestMatch['industry'],
                        'similarity' => $bestMatch['similarity']
                    ];
                } else {
                    $missing[] = [
                        'excel_value' => $excelIndustry,
                        'similarity' => $bestMatch['similarity'] ?? 0,
                        'suggestion' => $bestMatch['industry'] ?? null
                    ];
                }
            }
        }
        
        return [
            'found' => $found,
            'missing' => $missing,
            'suggestions' => $suggestions
        ];
    }
    
    /**
     * Prüft Level 2 Branchen unter den gefundenen Level 1 Branchen
     */
    public function checkIndustriesLevel2(array $level2Values, array $level1Result): array
    {
        $found = [];
        $missing = [];
        $suggestions = [];
        
        // Lade alle Level 2 Branchen, die unter den gefundenen Level 1 Branchen sind
        $level1Uuids = [];
        foreach ($level1Result['found'] as $foundItem) {
            $level1Uuids[] = $foundItem['db_industry']['industry_uuid'];
        }
        
        if (empty($level1Uuids)) {
            // Keine Level 1 gefunden, prüfe alle Level 2
            return $this->checkIndustries($level2Values, false);
        }
        
        // Lade Level 2 Branchen unter den gefundenen Level 1
        $placeholders = implode(',', array_fill(0, count($level1Uuids), '?'));
        $stmt = $this->db->prepare("
            SELECT industry_uuid, name, code, parent_industry_uuid
            FROM industry
            WHERE parent_industry_uuid IN ($placeholders)
        ");
        $stmt->execute($level1Uuids);
        $dbLevel2Industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Erstelle Maps für schnelles Lookup
        $maps = $this->matcher->buildIndustryMaps($dbLevel2Industries);
        $nameMap = $maps['nameMap'];
        $codeMap = $maps['codeMap'];
        
        // Prüfe jede Excel-Level-2-Branche
        foreach ($level2Values as $excelIndustry) {
            $foundIndustry = $this->matcher->findExactMatch($excelIndustry, $nameMap, $codeMap);
            
            if ($foundIndustry) {
                $found[] = [
                    'excel_value' => $excelIndustry,
                    'db_industry' => $foundIndustry
                ];
            } else {
                // Suche ähnliche Branchen (Fuzzy Match)
                $bestMatch = $this->matcher->findSimilarIndustry($excelIndustry, $dbLevel2Industries);
                
                $missing[] = [
                    'excel_value' => $excelIndustry,
                    'similarity' => $bestMatch['similarity'] ?? 0,
                    'suggestion' => $bestMatch['industry'] ?? null
                ];
                
                if ($bestMatch['similarity'] > 0.7) {
                    $suggestions[] = [
                        'excel_value' => $excelIndustry,
                        'suggested' => $bestMatch['industry'],
                        'similarity' => $bestMatch['similarity']
                    ];
                }
            }
        }
        
        return [
            'found' => $found,
            'missing' => $missing,
            'suggestions' => $suggestions
        ];
    }
    
    /**
     * Prüft Level 3 Unterbranchen unter den gefundenen Level 2 Branchen
     */
    public function checkIndustriesLevel3(array $level3Values, array $level2Result): array
    {
        $found = [];
        $missing = [];
        $suggestions = [];
        
        // Lade alle Level 3 Branchen, die unter den gefundenen Level 2 Branchen sind
        $level2Uuids = [];
        foreach ($level2Result['found'] as $foundItem) {
            $level2Uuids[] = $foundItem['db_industry']['industry_uuid'];
        }
        
        if (empty($level2Uuids)) {
            // Keine Level 2 gefunden, alle Level 3 sind missing
            foreach ($level3Values as $excelIndustry) {
                $missing[] = [
                    'excel_value' => $excelIndustry,
                    'similarity' => 0,
                    'suggestion' => null
                ];
            }
            return [
                'found' => [],
                'missing' => $missing,
                'suggestions' => []
            ];
        }
        
        // Lade Level 3 Branchen unter den gefundenen Level 2
        $placeholders = implode(',', array_fill(0, count($level2Uuids), '?'));
        $stmt = $this->db->prepare("
            SELECT industry_uuid, name, code, parent_industry_uuid
            FROM industry
            WHERE parent_industry_uuid IN ($placeholders)
        ");
        $stmt->execute($level2Uuids);
        $dbLevel3Industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Erstelle Maps für schnelles Lookup
        $maps = $this->matcher->buildIndustryMaps($dbLevel3Industries);
        $nameMap = $maps['nameMap'];
        $codeMap = $maps['codeMap'];
        
        // Prüfe jede Excel-Level-3-Branche
        foreach ($level3Values as $excelIndustry) {
            $foundIndustry = $this->matcher->findExactMatch($excelIndustry, $nameMap, $codeMap);
            
            if ($foundIndustry) {
                $found[] = [
                    'excel_value' => $excelIndustry,
                    'db_industry' => $foundIndustry
                ];
            } else {
                // Suche ähnliche Branchen (Fuzzy Match)
                $bestMatch = $this->matcher->findSimilarIndustry($excelIndustry, $dbLevel3Industries);
                
                $missing[] = [
                    'excel_value' => $excelIndustry,
                    'similarity' => $bestMatch['similarity'] ?? 0,
                    'suggestion' => $bestMatch['industry'] ?? null
                ];
                
                if ($bestMatch['similarity'] > 0.7) {
                    $suggestions[] = [
                        'excel_value' => $excelIndustry,
                        'suggested' => $bestMatch['industry'],
                        'similarity' => $bestMatch['similarity']
                    ];
                }
            }
        }
        
        return [
            'found' => $found,
            'missing' => $missing,
            'suggestions' => $suggestions
        ];
    }
    
    /**
     * Prüft, ob Branchenwerte konsistent sind (alle gleich oder nur ein Wert)
     * 
     * @param array $level1Values Alle Level 1 Werte
     * @param array $level2Values Alle Level 2 Werte
     * @param array $level3Values Alle Level 3 Werte
     * @return array ['is_consistent' => bool, 'level1_count' => int, 'level2_count' => int, 'level3_count' => int, 'warning' => string]
     */
    public function checkConsistency(array $level1Values, array $level2Values, array $level3Values): array
    {
        $level1Count = count(array_unique($level1Values));
        $level2Count = count(array_unique($level2Values));
        $level3Count = count(array_unique($level3Values));
        
        $isConsistent = ($level1Count <= 1 && $level2Count <= 1);
        
        $warning = null;
        if (!$isConsistent) {
            $parts = [];
            if ($level1Count > 1) {
                $parts[] = "verschiedene Branchenbereiche (Level 1): " . implode(', ', array_unique($level1Values));
            }
            if ($level2Count > 1) {
                $parts[] = "verschiedene Branchen (Level 2): " . implode(', ', array_unique($level2Values));
            }
            $warning = "Die Importdatei enthält " . implode(' und ', $parts) . ". Ein automatisches Branchenmapping ist nicht möglich, da sonst falsche Zuordnungen entstehen könnten. Die Branchendaten müssen nach dem Import manuell nachgetragen werden.";
        }
        
        return [
            'is_consistent' => $isConsistent,
            'level1_count' => $level1Count,
            'level2_count' => $level2Count,
            'level3_count' => $level3Count,
            'level1_values' => array_unique($level1Values),
            'level2_values' => array_unique($level2Values),
            'level3_values' => array_unique($level3Values),
            'warning' => $warning
        ];
    }
}




