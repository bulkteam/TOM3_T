<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

/**
 * IndustryResolver
 * 
 * Macht Vorschläge für Industry-Matching:
 * - Excel "Oberkategorie" → Level 2 Kandidaten
 * - Level 2 → Level 1 ableiten
 * - Excel "Kategorie" → Level 3 Kandidaten unter Level 2
 * 
 * Nutzt IndustryNormalizer für Matching
 */
final class IndustryResolver
{
    private PDO $db;
    private IndustryNormalizer $normalizer;
    
    public function __construct(?PDO $db = null, ?IndustryNormalizer $normalizer = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->normalizer = $normalizer ?? new IndustryNormalizer();
    }
    
    /**
     * Vorschläge für Level 2 (Branche) basierend auf Excel-Label
     * 
     * @param string $excelLevel2Label Z.B. "Chemieindustrie"
     * @param int $limit Maximale Anzahl Kandidaten
     * @return array Array von IndustryCandidate-ähnlichen Arrays
     */
    public function suggestLevel2(string $excelLevel2Label, int $limit = 5): array
    {
        $query = $this->normalizer->normalize($excelLevel2Label);
        
        // Hole alle Level 2 Industries (haben einen Level 1 Parent)
        $stmt = $this->db->prepare("
            SELECT 
                i2.industry_uuid,
                i2.name,
                i2.name_short,
                i2.code,
                i2.parent_industry_uuid,
                i1.name as parent_name,
                i1.code as parent_code
            FROM industry i2
            INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
            WHERE i1.parent_industry_uuid IS NULL
            ORDER BY i2.name
        ");
        $stmt->execute();
        $allLevel2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $candidates = [];
        
        foreach ($allLevel2 as $row) {
            // Matche sowohl mit name als auch name_short, nimm besten Score
            $nameNorm = $this->normalizer->normalize($row['name']);
            $scoreName = $this->similarity($query, $nameNorm, $row['code'] ?? null);
            
            $score = $scoreName;
            
            // Wenn name_short vorhanden, teste auch damit
            if (!empty($row['name_short'])) {
                $nameShortNorm = $this->normalizer->normalize($row['name_short']);
                $scoreShort = $this->similarity($query, $nameShortNorm, $row['code'] ?? null);
                // Nimm den besseren Score
                $score = max($scoreName, $scoreShort);
            }
            
            if ($score > 0.2) { // Mindest-Schwelle
                $candidates[] = [
                    'industry_uuid' => $row['industry_uuid'],
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'name_short' => $row['name_short'] ?? null,
                    'parent_uuid' => $row['parent_industry_uuid'],
                    'parent_name' => $row['parent_name'],
                    'parent_code' => $row['parent_code'],
                    'score' => round($score, 4)
                ];
            }
        }
        
        // Sortiere nach Score (höchster zuerst)
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return array_slice($candidates, 0, $limit);
    }
    
    /**
     * Leitet Level 1 (Branchenbereich) aus Level 2 UUID ab
     * 
     * @param string $level2Uuid
     * @return array|null ['industry_uuid', 'code', 'name'] oder null
     */
    public function deriveLevel1FromLevel2(string $level2Uuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                i1.industry_uuid,
                i1.name,
                i1.code
            FROM industry i1
            INNER JOIN industry i2 ON i2.parent_industry_uuid = i1.industry_uuid
            WHERE i2.industry_uuid = :level2_uuid
            AND i1.parent_industry_uuid IS NULL
        ");
        $stmt->execute(['level2_uuid' => $level2Uuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Vorschläge für Level 3 (Unterbranche) unter einem bestimmten Level 2
     * 
     * @param string $level2Uuid
     * @param string $excelLevel3Label Z.B. "Farbenhersteller"
     * @param int $limit
     * @return array Array von IndustryCandidate-ähnlichen Arrays
     */
    public function suggestLevel3UnderLevel2(string $level2Uuid, string $excelLevel3Label, int $limit = 5): array
    {
        $query = $this->normalizer->normalize($excelLevel3Label);
        
        // Hole alle Level 3 Industries unter diesem Level 2
        $stmt = $this->db->prepare("
            SELECT 
                industry_uuid,
                name,
                name_short,
                code,
                parent_industry_uuid
            FROM industry
            WHERE parent_industry_uuid = :level2_uuid
            ORDER BY name
        ");
        $stmt->execute(['level2_uuid' => $level2Uuid]);
        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $candidates = [];
        
        foreach ($children as $row) {
            // Matche sowohl mit name als auch name_short, nimm besten Score
            $nameNorm = $this->normalizer->normalize($row['name']);
            $scoreName = $this->similarity($query, $nameNorm, $row['code'] ?? null);
            
            $score = $scoreName;
            
            // Wenn name_short vorhanden, teste auch damit
            if (!empty($row['name_short'])) {
                $nameShortNorm = $this->normalizer->normalize($row['name_short']);
                $scoreShort = $this->similarity($query, $nameShortNorm, $row['code'] ?? null);
                // Nimm den besseren Score
                $score = max($scoreName, $scoreShort);
            }
            
            if ($score > 0.2) { // Mindest-Schwelle
                $candidates[] = [
                    'industry_uuid' => $row['industry_uuid'],
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'name_short' => $row['name_short'] ?? null,
                    'parent_uuid' => $row['parent_industry_uuid'],
                    'score' => round($score, 4)
                ];
            }
        }
        
        // Sortiere nach Score
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return array_slice($candidates, 0, $limit);
    }
    
    /**
     * Berechnet Similarity-Score zwischen Query und Name
     * 
     * @param string $query Normalisierter Query-String
     * @param string $name Normalisierter Name-String
     * @param string|null $code Optional: Code (z.B. "C20")
     * @return float Score zwischen 0.0 und 1.0
     */
    private function similarity(string $query, string $name, ?string $code): float
    {
        // 1. Token-Overlap (wie viele Wörter überlappen)
        $tokenScore = $this->tokenOverlap($query, $name);
        
        // 2. Levenshtein-Ratio (String-Ähnlichkeit)
        $levScore = $this->levRatio($query, $name);
        
        // 3. Code-Boost (wenn Query Code enthält)
        $codeBoost = 0.0;
        if ($code) {
            $codeLower = strtolower($code);
            $queryLower = strtolower($query);
            if (strpos($queryLower, $codeLower) !== false) {
                $codeBoost = 0.2;
            }
        }
        
        // Kombiniere Scores (gewichteter Durchschnitt)
        $score = min(1.0, 0.6 * $tokenScore + 0.4 * $levScore + $codeBoost);
        
        return $score;
    }
    
    /**
     * Token-Overlap: Wie viele Wörter überlappen?
     * 
     * @param string $a
     * @param string $b
     * @return float Score 0.0 - 1.0
     */
    private function tokenOverlap(string $a, string $b): float
    {
        $tokensA = array_filter(explode(' ', $a));
        $tokensB = array_filter(explode(' ', $b));
        
        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }
        
        $intersection = array_intersect($tokensA, $tokensB);
        $union = array_unique(array_merge($tokensA, $tokensB));
        
        // $union kann nicht leer sein, da $tokensA und $tokensB bereits auf empty() geprüft wurden
        // Jaccard-Similarity
        return count($intersection) / count($union);
    }
    
    /**
     * Levenshtein-Ratio: String-Ähnlichkeit
     * 
     * @param string $a
     * @param string $b
     * @return float Score 0.0 - 1.0
     */
    private function levRatio(string $a, string $b): float
    {
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        
        if ($maxLen === 0) {
            return 0.0;
        }
        
        $distance = levenshtein($a, $b);
        $ratio = 1.0 - min(1.0, $distance / $maxLen);
        
        return $ratio;
    }
    
    /**
     * Prüft, ob ein Level 3 Name bereits unter einem Level 2 existiert
     * 
     * @param string $level2Uuid
     * @param string $normalizedName Normalisierter Name
     * @return array|null Industry-Row oder null
     */
    public function findLevel3ByNameUnderParent(string $level2Uuid, string $normalizedName): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                industry_uuid,
                name,
                code,
                parent_industry_uuid
            FROM industry
            WHERE parent_industry_uuid = :level2_uuid
        ");
        $stmt->execute(['level2_uuid' => $level2Uuid]);
        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($children as $row) {
            $rowNorm = $this->normalizer->normalize($row['name']);
            if ($rowNorm === $normalizedName) {
                return $row;
            }
        }
        
        return null;
    }
    
    /**
     * Erstellt eine neue Level 3 Industry
     * 
     * @param string $parentLevel2Uuid
     * @param string $name
     * @return string Neue industry_uuid
     */
    public function createLevel3(string $parentLevel2Uuid, string $name, ?string $nameShort = null): string
    {
        // Generiere UUID mit UuidHelper
        $uuid = UuidHelper::generate($this->db);
        
        // Wenn kein name_short angegeben, verwende name als name_short
        $nameShort = $nameShort ?? trim($name);
        
        $stmt = $this->db->prepare("
            INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description)
            VALUES (:uuid, :name, :name_short, NULL, :parent_uuid, NULL)
        ");
        
        $stmt->execute([
            'uuid' => $uuid,
            'name' => trim($name),
            'name_short' => $nameShort,
            'parent_uuid' => $parentLevel2Uuid
        ]);
        
        return $uuid;
    }
    
    /**
     * Holt Parent-UUID einer Industry
     * 
     * @param string $industryUuid
     * @return string|null
     */
    public function getParentUuid(string $industryUuid): ?string
    {
        $stmt = $this->db->prepare("
            SELECT parent_industry_uuid
            FROM industry
            WHERE industry_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $industryUuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['parent_industry_uuid'] ?? null;
    }
}

