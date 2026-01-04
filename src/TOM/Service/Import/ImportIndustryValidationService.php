<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Import\Industry\ImportIndustryMatcher;
use TOM\Service\Import\Industry\ImportIndustryLevelValidator;
use TOM\Service\Import\Industry\ImportIndustryCombinationChecker;

/**
 * Service für Branchen-Validierung beim Import
 * Prüft, ob Branchen aus Excel in der DB existieren
 * 
 * Refactored: Delegiert an spezialisierte Services
 */
class ImportIndustryValidationService
{
    private PDO $db;
    private ImportIndustryMatcher $matcher;
    private ImportIndustryLevelValidator $levelValidator;
    private ImportIndustryCombinationChecker $combinationChecker;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->matcher = new ImportIndustryMatcher();
        $this->levelValidator = new ImportIndustryLevelValidator($this->db, $this->matcher);
        $this->combinationChecker = new ImportIndustryCombinationChecker($this->db, $this->matcher);
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
        
        // NEUE LOGIK: "Oberkategorie" wird auf Level 2 gesucht, nicht Level 1!
        // Wenn level1Col leer ist, aber level2Col gefüllt, dann ist "Oberkategorie" in level2Col
        // In diesem Fall: Suche level2Values auf Level 2 (ohne Level 1 Einschränkung)
        
        // Prüfe Level 1 (Branchenbereiche) - nur wenn wirklich Level 1 Werte vorhanden
        if (!empty($level1Values)) {
            $result['main'] = $this->levelValidator->checkIndustries($level1Values, true);
        }
        
        // Prüfe Level 2 (Branchen)
        // WICHTIG: Wenn "Oberkategorie" auf Level 2 gemappt wurde, suche auf allen Level 2 Branchen
        if (!empty($level2Values)) {
            if (empty($level1Values) && !empty($level2Col)) {
                // "Oberkategorie" wurde auf Level 2 gemappt → Suche auf allen Level 2 Branchen
                $result['sub'] = $this->levelValidator->checkIndustriesLevel2WithoutParent($level2Values);
            } else {
                // Normale Suche: Level 2 unter den gefundenen Level 1 Branchen
                $result['sub'] = $this->levelValidator->checkIndustriesLevel2($level2Values, $result['main'] ?? []);
            }
        }
        
        // Prüfe Level 3 (Unterbranchen) - optional, nur wenn Level 2 vorhanden ist
        if (!empty($level3Values)) {
            // Prüfe Level 3 unter den gefundenen Level 2 Branchen
            $result['level3'] = $this->levelValidator->checkIndustriesLevel3($level3Values, $result['sub'] ?? []);
        }
        
        // Prüfe Kombinationen: Wenn Level 1 gefunden ODER Level 2 gefunden (dann Level 1 ableiten)
        if (!empty($combinations)) {
            $result['combinations'] = $this->combinationChecker->checkCombinations3Level($combinations, $result['main'], $result['sub']);
        } else if (!empty($level2Values) && !empty($result['sub']['found'])) {
            // Fallback: Wenn keine Kombinationen, aber Level 2 gefunden, versuche Level 1 abzuleiten
            $result['combinations'] = $this->combinationChecker->deriveCombinationsFromLevel2($level2Values, $level3Values, $result['sub']);
        }
        
        // Prüfe Konsistenz: Wenn verschiedene Level 1 oder Level 2 Werte vorhanden sind
        $result['consistency'] = $this->levelValidator->checkConsistency($level1Values, $level2Values, $level3Values);
        
        return $result;
    }
}
