<?php
declare(strict_types=1);

namespace TOM\Service\Import\Industry;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * ImportIndustryCombinationChecker
 * 
 * Handles validation of industry combinations:
 * - Check 3-level combinations (Level 1 → Level 2 → Level 3)
 * - Check 2-level combinations (backward compatibility)
 * - Derive combinations from Level 2 when Level 1 is missing
 */
class ImportIndustryCombinationChecker
{
    private PDO $db;
    private ImportIndustryMatcher $matcher;
    
    public function __construct(?PDO $db = null, ?ImportIndustryMatcher $matcher = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->matcher = $matcher ?? new ImportIndustryMatcher();
    }
    
    /**
     * Prüft Kombinationen: Wenn Level 1 gefunden, suche passende Level 2 und 3
     * 
     * @param array $combinations Excel-Kombinationen: ['Level1' => ['Level2' => ['Level3', ...], ...]]
     * @param array $level1Result Ergebnis der Level 1 Prüfung
     * @param array $level2Result Ergebnis der Level 2 Prüfung (optional, für Ableitung von Level 1)
     * @return array Kombinations-Vorschläge
     */
    public function checkCombinations3Level(array $combinations, array $level1Result, array $level2Result = []): array
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
            
            // Wenn Level 1 nicht gefunden, aber Level 2 gefunden: Leite Level 1 aus Level 2 ab
            if (!$dbLevel1 && !empty($level2Result['found'])) {
                foreach ($level2Data as $excelLevel2 => $excelLevel3s) {
                    // Suche Level 2 in gefundenen
                    foreach ($level2Result['found'] as $level2Found) {
                        if ($level2Found['excel_value'] === $excelLevel2) {
                            $level2Industry = $level2Found['db_industry'];
                            // Hole Parent (Level 1) von Level 2
                            if (!empty($level2Industry['parent_industry_uuid'])) {
                                $parentStmt = $this->db->prepare("
                                    SELECT industry_uuid, name, code
                                    FROM industry
                                    WHERE industry_uuid = :uuid AND parent_industry_uuid IS NULL
                                ");
                                $parentStmt->execute(['uuid' => $level2Industry['parent_industry_uuid']]);
                                $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
                                if ($parent) {
                                    $dbLevel1 = $parent;
                                    // Aktualisiere level1Result für diese Kombination
                                    $level1Result['found'][] = [
                                        'excel_value' => $excelLevel1,
                                        'db_industry' => $parent
                                    ];
                                    break 2; // Breche beide Loops
                                }
                            }
                        }
                    }
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
                    $bestLevel2Match = $this->matcher->findSimilarIndustry($excelLevel2, $availableLevel2);
                    
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
                            $bestLevel3Match = $this->matcher->findSimilarIndustry($excelLevel3, $availableLevel3);
                            
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
                        // $level2Matches ist hier garantiert nicht leer, da wir gerade ein Element hinzugefügt haben
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
        
        return $suggestions;
    }
    
    /**
     * Prüft Kombinationen: Wenn Hauptbranche gefunden, suche passende Subbranchen (Rückwärtskompatibilität)
     * 
     * @param array $combinations Excel-Kombinationen: ['Hauptbranche' => ['Sub1', 'Sub2', ...]]
     * @param array $mainResult Ergebnis der Hauptbranchen-Prüfung
     * @return array Kombinations-Vorschläge
     */
    public function checkCombinations(array $combinations, array $mainResult): array
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
                    $bestMatch = $this->matcher->findSimilarIndustry($excelSub, $availableSubs);
                    
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
    
    /**
     * Leitet Kombinationen aus Level 2 ab, wenn Level 1 nicht direkt gefunden wurde
     * 
     * @param array $level2Values Excel Level 2 Werte (z.B. ["Chemieindustrie"])
     * @param array $level3Values Excel Level 3 Werte (optional)
     * @param array $level2Result Ergebnis der Level 2 Prüfung
     * @return array Kombinations-Vorschläge
     */
    public function deriveCombinationsFromLevel2(array $level2Values, array $level3Values, array $level2Result): array
    {
        $suggestions = [];
        
        // Für jeden gefundenen Level 2: Hole Parent (Level 1)
        foreach ($level2Result['found'] as $level2Found) {
            $level2Industry = $level2Found['db_industry'];
            $excelLevel2 = $level2Found['excel_value'];
            
            if (!empty($level2Industry['parent_industry_uuid'])) {
                // Hole Parent (Level 1)
                $parentStmt = $this->db->prepare("
                    SELECT industry_uuid, name, code
                    FROM industry
                    WHERE industry_uuid = :uuid AND parent_industry_uuid IS NULL
                ");
                $parentStmt->execute(['uuid' => $level2Industry['parent_industry_uuid']]);
                $dbLevel1 = $parentStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dbLevel1) {
                    // Lade Level 3 Optionen für diesen Level 2
                    $level3Stmt = $this->db->prepare("
                        SELECT industry_uuid, name, code
                        FROM industry
                        WHERE parent_industry_uuid = :level2_uuid
                    ");
                    $level3Stmt->execute(['level2_uuid' => $level2Industry['industry_uuid']]);
                    $availableLevel3 = $level3Stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $level3Matches = [];
                    $level3NeedsCreation = [];
                    
                    // Prüfe Level 3 Werte
                    foreach ($level3Values as $excelLevel3) {
                        $bestLevel3Match = $this->matcher->findSimilarIndustry($excelLevel3, $availableLevel3);
                        
                        if ($bestLevel3Match['industry'] && $bestLevel3Match['similarity'] > 0.6) {
                            $level3Matches[] = [
                                'excel_value' => $excelLevel3,
                                'db_industry' => $bestLevel3Match['industry'],
                                'similarity' => $bestLevel3Match['similarity']
                            ];
                        } else {
                            $level3NeedsCreation[] = [
                                'excel_value' => $excelLevel3,
                                'parent_level2_uuid' => $level2Industry['industry_uuid']
                            ];
                        }
                    }
                    
                    // Erstelle Kombinations-Vorschlag
                    $suggestions[] = [
                        'excel_level1' => $dbLevel1['name'], // Verwende DB-Name als Excel-Wert (da nicht direkt in Excel)
                        'db_level1' => $dbLevel1,
                        'excel_level2' => $excelLevel2,
                        'level2_matches' => [[
                            'excel_value' => $excelLevel2,
                            'db_industry' => $level2Industry,
                            'similarity' => 1.0
                        ]],
                        'excel_level3s' => $level3Values,
                        'level3_matches' => $level3Matches,
                        'level3_needs_creation' => $level3NeedsCreation
                    ];
                }
            }
        }
        
        return $suggestions;
    }
}




