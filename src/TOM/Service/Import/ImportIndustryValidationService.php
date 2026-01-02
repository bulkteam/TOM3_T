<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * Service für Branchen-Validierung beim Import
 * Prüft, ob Branchen aus Excel in der DB existieren
 */
class ImportIndustryValidationService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Prüft Branchen aus Excel-Daten gegen DB
     * 
     * @param array $sampleRows Beispiel-Zeilen aus Excel
     * @param array $mappingConfig Mapping-Konfiguration (enthält industry_level1, industry_level2, industry_level3 Spalten)
     * @return array ['main' => [...], 'sub' => [...], 'level3' => [...], 'combinations' => [...]]
     */
    public function validateIndustries(array $sampleRows, array $mappingConfig): array
    {
        $result = [
            'main' => [
                'found' => [],
                'missing' => [],
                'suggestions' => []
            ],
            'sub' => [
                'found' => [],
                'missing' => [],
                'suggestions' => []
            ],
            'level3' => [
                'found' => [],
                'missing' => [],
                'suggestions' => []
            ],
            'combinations' => [] // Kombinationen aus Excel: {level1: "X", level2: "Y", level3: "Z"} → DB-Vorschläge
        ];
        
        // Finde Spalten für industry_level1, industry_level2, industry_level3 (und Rückwärtskompatibilität)
        $level1Col = null;
        $level2Col = null;
        $level3Col = null;
        
        $byColumn = $mappingConfig['by_column'] ?? [];
        foreach ($byColumn as $col => $mapping) {
            $tomField = $mapping['tom_field'] ?? '';
            if ($tomField === 'industry_level1' || $tomField === 'industry_main') {
                $level1Col = $col;
            }
            if ($tomField === 'industry_level2' || $tomField === 'industry_sub') {
                $level2Col = $col;
            }
            if ($tomField === 'industry_level3') {
                $level3Col = $col;
            }
        }
        
        // Extrahiere alle eindeutigen Branchen-Kombinationen aus Excel (3-stufig)
        $level1Values = [];
        $level2Values = [];
        $level3Values = [];
        $combinations = []; // Kombinationen: level1 => [level2 => [level3, ...], ...]
        
        foreach ($sampleRows as $row) {
            $level1Value = null;
            $level2Value = null;
            $level3Value = null;
            
            if ($level1Col && isset($row[$level1Col]) && !empty(trim($row[$level1Col]))) {
                $level1Value = trim($row[$level1Col]);
                if (!in_array($level1Value, $level1Values)) {
                    $level1Values[] = $level1Value;
                }
            }
            if ($level2Col && isset($row[$level2Col]) && !empty(trim($row[$level2Col]))) {
                $level2Value = trim($row[$level2Col]);
                if (!in_array($level2Value, $level2Values)) {
                    $level2Values[] = $level2Value;
                }
            }
            if ($level3Col && isset($row[$level3Col]) && !empty(trim($row[$level3Col]))) {
                $level3Value = trim($row[$level3Col]);
                if (!in_array($level3Value, $level3Values)) {
                    $level3Values[] = $level3Value;
                }
            }
            
            // Speichere Kombination (3-stufig)
            if ($level1Value && $level2Value) {
                if (!isset($combinations[$level1Value])) {
                    $combinations[$level1Value] = [];
                }
                if (!isset($combinations[$level1Value][$level2Value])) {
                    $combinations[$level1Value][$level2Value] = [];
                }
                if ($level3Value && !in_array($level3Value, $combinations[$level1Value][$level2Value])) {
                    $combinations[$level1Value][$level2Value][] = $level3Value;
                }
            }
        }
        
        // Prüfe Level 1 (Branchenbereiche)
        if (!empty($level1Values)) {
            $result['main'] = $this->checkIndustries($level1Values, true);
        }
        
        // Prüfe Level 2 (Branchen) - nur wenn Level 1 vorhanden ist
        if (!empty($level2Values)) {
            // Prüfe Level 2 unter den gefundenen Level 1 Branchen
            $result['sub'] = $this->checkIndustriesLevel2($level2Values, $result['main'] ?? []);
        }
        
        // Prüfe Level 3 (Unterbranchen) - optional, nur wenn Level 2 vorhanden ist
        if (!empty($level3Values)) {
            // Prüfe Level 3 unter den gefundenen Level 2 Branchen
            $result['level3'] = $this->checkIndustriesLevel3($level3Values, $result['sub'] ?? []);
        }
        
        // Prüfe Kombinationen: Wenn Level 1 gefunden, prüfe passende Level 2 und 3
        if (!empty($combinations)) {
            $result['combinations'] = $this->checkCombinations3Level($combinations, $result['main']);
        }
        
        // Prüfe Konsistenz: Wenn verschiedene Level 1 oder Level 2 Werte vorhanden sind
        $result['consistency'] = $this->checkConsistency($level1Values, $level2Values, $level3Values);
        
        return $result;
    }
    
    /**
     * Prüft, ob Branchenwerte konsistent sind (alle gleich oder nur ein Wert)
     * 
     * @param array $level1Values Alle Level 1 Werte
     * @param array $level2Values Alle Level 2 Werte
     * @param array $level3Values Alle Level 3 Werte
     * @return array ['is_consistent' => bool, 'level1_count' => int, 'level2_count' => int, 'level3_count' => int, 'warning' => string]
     */
    private function checkConsistency(array $level1Values, array $level2Values, array $level3Values): array
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
    
    /**
     * Prüft, ob Branchen in DB existieren
     * 
     * @param array $industryNames Liste von Branchen-Namen
     * @param bool $mainOnly Nur Hauptklassen (parent_industry_uuid IS NULL)
     * @return array ['found' => [...], 'missing' => [...], 'suggestions' => [...]]
     */
    private function checkIndustries(array $industryNames, bool $mainOnly): array
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
        
        // Normalisiere DB-Branchen (lowercase für Vergleich) + Code-Map
        $dbIndustryMap = [];
        $dbCodeMap = [];
        foreach ($dbIndustries as $industry) {
            $normalized = mb_strtolower(trim($industry['name']));
            $dbIndustryMap[$normalized] = $industry;
            
            // Auch Code-Mapping (z.B. "C" → "C - Verarbeitendes Gewerbe")
            if (!empty($industry['code'])) {
                $codeNormalized = mb_strtolower(trim($industry['code']));
                $dbCodeMap[$codeNormalized] = $industry;
            }
        }
        
        // Prüfe jede Excel-Branche
        foreach ($industryNames as $excelIndustry) {
            $normalized = mb_strtolower(trim($excelIndustry));
            $foundIndustry = null;
            
            // 1. Exakter Match nach Name
            if (isset($dbIndustryMap[$normalized])) {
                $foundIndustry = $dbIndustryMap[$normalized];
            }
            // 2. Match nach Code (z.B. "C" oder "C20")
            elseif (isset($dbCodeMap[$normalized])) {
                $foundIndustry = $dbCodeMap[$normalized];
            }
            // 3. Teilstring-Match im Code (z.B. "C20" findet "C20 - Herstellung...")
            else {
                foreach ($dbCodeMap as $code => $industry) {
                    if (strpos($code, $normalized) === 0 || strpos($normalized, $code) === 0) {
                        $foundIndustry = $industry;
                        break;
                    }
                }
            }
            
            if ($foundIndustry) {
                $found[] = [
                    'excel_value' => $excelIndustry,
                    'db_industry' => $foundIndustry
                ];
            } else {
                // Suche ähnliche Branchen (Fuzzy Match)
                $bestMatch = $this->findSimilarIndustry($excelIndustry, $dbIndustries);
                
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
     * Prüft Level 2 Branchen unter den gefundenen Level 1 Branchen
     */
    private function checkIndustriesLevel2(array $level2Values, array $level1Result): array
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
        
        // Normalisiere DB-Branchen (lowercase für Vergleich) + Code-Map
        $dbIndustryMap = [];
        $dbCodeMap = [];
        foreach ($dbLevel2Industries as $industry) {
            $normalized = mb_strtolower(trim($industry['name']));
            $dbIndustryMap[$normalized] = $industry;
            
            // Auch Code-Mapping (z.B. "C20" → "C20 - Herstellung...")
            if (!empty($industry['code'])) {
                $codeNormalized = mb_strtolower(trim($industry['code']));
                $dbCodeMap[$codeNormalized] = $industry;
            }
        }
        
        // Prüfe jede Excel-Level-2-Branche
        foreach ($level2Values as $excelIndustry) {
            $normalized = mb_strtolower(trim($excelIndustry));
            $foundIndustry = null;
            
            // 1. Exakter Match nach Name
            if (isset($dbIndustryMap[$normalized])) {
                $foundIndustry = $dbIndustryMap[$normalized];
            }
            // 2. Match nach Code (z.B. "C20")
            elseif (isset($dbCodeMap[$normalized])) {
                $foundIndustry = $dbCodeMap[$normalized];
            }
            // 3. Teilstring-Match im Code (z.B. "C20" findet "C20 - Herstellung...")
            else {
                foreach ($dbCodeMap as $code => $industry) {
                    if (strpos($code, $normalized) === 0 || strpos($normalized, $code) === 0) {
                        $foundIndustry = $industry;
                        break;
                    }
                }
            }
            
            if ($foundIndustry) {
                $found[] = [
                    'excel_value' => $excelIndustry,
                    'db_industry' => $foundIndustry
                ];
            } else {
                // Suche ähnliche Branchen (Fuzzy Match)
                $bestMatch = $this->findSimilarIndustry($excelIndustry, $dbLevel2Industries);
                
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
    private function checkIndustriesLevel3(array $level3Values, array $level2Result): array
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
        
        // Normalisiere DB-Branchen (lowercase für Vergleich) + Code-Map
        $dbIndustryMap = [];
        $dbCodeMap = [];
        foreach ($dbLevel3Industries as $industry) {
            $normalized = mb_strtolower(trim($industry['name']));
            $dbIndustryMap[$normalized] = $industry;
            
            // Auch Code-Mapping
            if (!empty($industry['code'])) {
                $codeNormalized = mb_strtolower(trim($industry['code']));
                $dbCodeMap[$codeNormalized] = $industry;
            }
        }
        
        // Prüfe jede Excel-Level-3-Branche
        foreach ($level3Values as $excelIndustry) {
            $normalized = mb_strtolower(trim($excelIndustry));
            $foundIndustry = null;
            
            // 1. Exakter Match nach Name
            if (isset($dbIndustryMap[$normalized])) {
                $foundIndustry = $dbIndustryMap[$normalized];
            }
            // 2. Match nach Code
            elseif (isset($dbCodeMap[$normalized])) {
                $foundIndustry = $dbCodeMap[$normalized];
            }
            // 3. Teilstring-Match im Code
            else {
                foreach ($dbCodeMap as $code => $industry) {
                    if (strpos($code, $normalized) === 0 || strpos($normalized, $code) === 0) {
                        $foundIndustry = $industry;
                        break;
                    }
                }
            }
            
            if ($foundIndustry) {
                $found[] = [
                    'excel_value' => $excelIndustry,
                    'db_industry' => $foundIndustry
                ];
            } else {
                // Suche ähnliche Branchen (Fuzzy Match)
                $bestMatch = $this->findSimilarIndustry($excelIndustry, $dbLevel3Industries);
                
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
     * Findet ähnliche Branche (Fuzzy Match)
     */
    private function findSimilarIndustry(string $excelValue, array $dbIndustries): array
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
     * Prüft Kombinationen: Wenn Level 1 gefunden, suche passende Level 2 und 3
     * 
     * @param array $combinations Excel-Kombinationen: ['Level1' => ['Level2' => ['Level3', ...], ...]]
     * @param array $level1Result Ergebnis der Level 1 Prüfung
     * @return array Kombinations-Vorschläge
     */
    private function checkCombinations3Level(array $combinations, array $level1Result): array
    {
        $suggestions = [];
        
        // Lade alle Level 2 und Level 3 Branchen aus DB
        $stmt = $this->db->prepare("
            SELECT industry_uuid, name, code, parent_industry_uuid
            FROM industry
            WHERE parent_industry_uuid IS NOT NULL
        ");
        $stmt->execute();
        $dbSubIndustries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Gruppiere nach parent (Level 2 unter Level 1, Level 3 unter Level 2)
        $level2ByLevel1 = [];
        $level3ByLevel2 = [];
        
        foreach ($dbSubIndustries as $sub) {
            $parentUuid = $sub['parent_industry_uuid'];
            
            // Prüfe, ob parent ein Level 1 ist (parent_industry_uuid IS NULL)
            $parentStmt = $this->db->prepare("SELECT parent_industry_uuid FROM industry WHERE industry_uuid = :uuid");
            $parentStmt->execute(['uuid' => $parentUuid]);
            $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($parent && $parent['parent_industry_uuid'] === null) {
                // Level 2 unter Level 1
                if (!isset($level2ByLevel1[$parentUuid])) {
                    $level2ByLevel1[$parentUuid] = [];
                }
                $level2ByLevel1[$parentUuid][] = $sub;
            } else {
                // Level 3 unter Level 2
                if (!isset($level3ByLevel2[$parentUuid])) {
                    $level3ByLevel2[$parentUuid] = [];
                }
                $level3ByLevel2[$parentUuid][] = $sub;
            }
        }
        
        // Prüfe jede Excel-Kombination (3-stufig)
        foreach ($combinations as $excelLevel1 => $level2Data) {
            // Suche passende Level 1 in DB
            $dbLevel1 = null;
            
            foreach ($level1Result['found'] as $found) {
                if ($found['excel_value'] === $excelLevel1) {
                    $dbLevel1 = $found['db_industry'];
                    break;
                }
            }
            
            if ($dbLevel1) {
                $level1Uuid = $dbLevel1['industry_uuid'];
                $availableLevel2 = $level2ByLevel1[$level1Uuid] ?? [];
                
                // Prüfe Level 2
                foreach ($level2Data as $excelLevel2 => $excelLevel3s) {
                    $level2Matches = [];
                    $level3Matches = [];
                    
                    // Suche passende Level 2
                    $bestLevel2Match = $this->findSimilarIndustry($excelLevel2, $availableLevel2);
                    
                    if ($bestLevel2Match['industry'] && $bestLevel2Match['similarity'] > 0.6) {
                        $level2Matches[] = [
                            'excel_value' => $excelLevel2,
                            'db_industry' => $bestLevel2Match['industry'],
                            'similarity' => $bestLevel2Match['similarity']
                        ];
                        
                        // Prüfe Level 3 unter diesem Level 2
                        $level2Uuid = $bestLevel2Match['industry']['industry_uuid'];
                        $availableLevel3 = $level3ByLevel2[$level2Uuid] ?? [];
                        
                        $level3Results = [];
                        foreach ($excelLevel3s as $excelLevel3) {
                            $bestLevel3Match = $this->findSimilarIndustry($excelLevel3, $availableLevel3);
                            
                            if ($bestLevel3Match['industry'] && $bestLevel3Match['similarity'] > 0.6) {
                                $level3Matches[] = [
                                    'excel_value' => $excelLevel3,
                                    'db_industry' => $bestLevel3Match['industry'],
                                    'similarity' => $bestLevel3Match['similarity']
                                ];
                            } else {
                                // Level 3 nicht gefunden - muss neu angelegt werden
                                $level3Results[] = [
                                    'excel_value' => $excelLevel3,
                                    'needs_creation' => true,
                                    'parent_level2_uuid' => $level2Uuid
                                ];
                            }
                        }
                        
                        // Füge Kombination hinzu, auch wenn Level 3 neu angelegt werden muss
                        if (!empty($level2Matches)) {
                            $suggestions[] = [
                                'excel_level1' => $excelLevel1,
                                'excel_level2' => $excelLevel2,
                                'excel_level3s' => $excelLevel3s,
                                'db_level1' => $dbLevel1,
                                'level2_matches' => $level2Matches,
                                'level3_matches' => $level3Matches,
                                'level3_needs_creation' => $level3Results
                            ];
                        }
                    }
                }
            }
        }
        
        return $suggestions;
    }
    
    /**
     * Prüft Kombinationen: Wenn Hauptbranche gefunden, suche passende Subbranchen (Rückwärtskompatibilität)
     * 
     * @param array $combinations Excel-Kombinationen: ['Hauptbranche' => ['Sub1', 'Sub2', ...]]
     * @param array $mainResult Ergebnis der Hauptbranchen-Prüfung
     * @return array Kombinations-Vorschläge
     */
    private function checkCombinations(array $combinations, array $mainResult): array
    {
        $suggestions = [];
        
        // Lade alle Subbranchen aus DB
        $stmt = $this->db->prepare("
            SELECT industry_uuid, name, code, parent_industry_uuid
            FROM industry
            WHERE parent_industry_uuid IS NOT NULL
        ");
        $stmt->execute();
        $dbSubIndustries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Gruppiere Subbranchen nach parent
        $subByParent = [];
        foreach ($dbSubIndustries as $sub) {
            $parentUuid = $sub['parent_industry_uuid'];
            if (!isset($subByParent[$parentUuid])) {
                $subByParent[$parentUuid] = [];
            }
            $subByParent[$parentUuid][] = $sub;
        }
        
        // Prüfe jede Excel-Kombination
        foreach ($combinations as $excelMain => $excelSubs) {
            // Suche passende Hauptbranche in DB
            $dbMain = null;
            
            // Prüfe gefundene Hauptbranchen
            foreach ($mainResult['found'] as $found) {
                if ($found['excel_value'] === $excelMain) {
                    $dbMain = $found['db_industry'];
                    break;
                }
            }
            
            // Wenn Hauptbranche gefunden, prüfe Subbranchen
            if ($dbMain) {
                $mainUuid = $dbMain['industry_uuid'];
                $availableSubs = $subByParent[$mainUuid] ?? [];
                
                // Für jede Excel-Subbranche: Suche passende DB-Subbranche
                $subMatches = [];
                foreach ($excelSubs as $excelSub) {
                    $bestMatch = $this->findSimilarIndustry($excelSub, $availableSubs);
                    
                    if ($bestMatch['industry'] && $bestMatch['similarity'] > 0.6) {
                        $subMatches[] = [
                            'excel_value' => $excelSub,
                            'db_industry' => $bestMatch['industry'],
                            'similarity' => $bestMatch['similarity']
                        ];
                    }
                }
                
                if (!empty($subMatches)) {
                    $suggestions[] = [
                        'excel_main' => $excelMain,
                        'excel_subs' => $excelSubs,
                        'db_main' => $dbMain,
                        'sub_matches' => $subMatches
                    ];
                }
            }
        }
        
        return $suggestions;
    }
}
