<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * Service für Duplikat-Erkennung
 */
class ImportDedupeService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Findet Duplikate gegen bestehende DB
     */
    public function findDuplicates(array $rowData): array
    {
        $duplicates = [];
        
        $name = $this->normalizeString($rowData['name'] ?? '');
        $domain = $this->extractDomain($rowData['website'] ?? '');
        $postalCode = $rowData['address_postal_code'] ?? null;
        $country = strtoupper(trim($rowData['address_country'] ?? 'DE'));
        $city = $this->normalizeString($rowData['address_city'] ?? '');
        
        $query = "
            SELECT org_uuid, name, website
            FROM org
            WHERE 1=1
        ";
        
        $params = [];
        
        // Name-Match
        if (!empty($name)) {
            $nameNoLegal = $this->stripLegalForms($name);
            $query .= " AND LOWER(TRIM(name)) LIKE :name_pattern";
            $params['name_pattern'] = '%' . $nameNoLegal . '%';
        }
        
        // Domain nicht als SQL-Filter erzwingen; nur im Score berücksichtigen
        
        // PLZ-Match (über Adressen)
        if (!empty($postalCode)) {
            $query .= " AND EXISTS (
                SELECT 1 FROM org_address 
                WHERE org_address.org_uuid = org.org_uuid 
                AND org_address.postal_code = :postal_code
            )";
            $params['postal_code'] = $postalCode;
        }
        
        // City-Match (über Adressen)
        if (!empty($city)) {
            $query .= " AND EXISTS (
                SELECT 1 FROM org_address 
                WHERE org_address.org_uuid = org.org_uuid 
                  AND LOWER(TRIM(org_address.city)) = :city
            )";
            $params['city'] = $city;
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        foreach ($candidates as $candidate) {
            $score = $this->calculateMatchScore($rowData, $candidate);
            
            if ($score > 0.7) { // Threshold: 70%
                $duplicates[] = [
                    'org_uuid' => $candidate['org_uuid'],
                    'score' => $score,
                    'reasons' => $this->getMatchReasons($rowData, $candidate)
                ];
            }
        }
        
        // Sortiere nach Score (höchste zuerst)
        usort($duplicates, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return $duplicates;
    }
    
    /**
     * Berechnet Match-Score
     */
    private function calculateMatchScore(array $rowData, array $candidate): float
    {
        $scores = [];
        
        $name1 = $this->normalizeString($rowData['name'] ?? '');
        $name2 = $this->normalizeString($candidate['name'] ?? '');
        if (!empty($name1) && !empty($name2)) {
            $scores['name'] = $this->stringSimilarity($this->normalizeForNameComparison($name1), $this->normalizeForNameComparison($name2));
        }
        
        $domain1 = $this->extractDomain($rowData['website'] ?? '');
        $domain2 = $this->extractDomain($candidate['website'] ?? '');
        if (!empty($domain1) && !empty($domain2)) {
            $scores['domain'] = $domain1 === $domain2 ? 1.0 : 0.0;
        }
        
        $city1 = $this->normalizeString($rowData['address_city'] ?? '');
        if (!empty($city1)) {
            $scores['city'] = 1.0; // City-Match wird durch SQL-EXISTS abgesichert
        }
        
        $weights = ['name' => 0.6, 'domain' => 0.2, 'city' => 0.2];
        $totalScore = 0.0;
        $totalWeight = 0.0;
        
        foreach ($scores as $key => $score) {
            $weight = isset($weights[$key]) ? $weights[$key] : 0.0;
            $totalScore += $score * $weight;
            $totalWeight += $weight;
        }
        
        return $totalWeight > 0 ? ($totalScore / $totalWeight) : 0.0;
    }
    
    /**
     * Gibt Match-Gründe zurück
     */
    private function getMatchReasons(array $rowData, array $candidate): array
    {
        $reasons = [];
        
        $name1 = $this->normalizeString($rowData['name'] ?? '');
        $name2 = $this->normalizeString($candidate['name'] ?? '');
        if (!empty($name1) && !empty($name2)) {
            $reasons['name_match'] = $this->stringSimilarity($this->normalizeForNameComparison($name1), $this->normalizeForNameComparison($name2));
        }
        
        $domain1 = $this->extractDomain($rowData['website'] ?? '');
        $domain2 = $this->extractDomain($candidate['website'] ?? '');
        if (!empty($domain1) && !empty($domain2)) {
            $reasons['domain_match'] = $domain1 === $domain2 ? 1.0 : 0.0;
        }
        
        $city1 = $this->normalizeString($rowData['address_city'] ?? '');
        if (!empty($city1)) {
            $reasons['city_match'] = 1.0;
        }
        
        return $reasons;
    }
    
    /**
     * Speichert Duplikat-Kandidaten
     */
    public function saveDuplicateCandidate(
        string $stagingUuid,
        string $candidateOrgUuid,
        float $matchScore,
        array $matchReasons
    ): string {
        $candidateUuid = \TOM\Infrastructure\Utils\UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO import_duplicate_candidates (
                candidate_uuid, staging_uuid, candidate_org_uuid,
                match_score, match_reason_json
            )
            VALUES (
                :candidate_uuid, :staging_uuid, :candidate_org_uuid,
                :match_score, :match_reason_json
            )
        ");
        
        $stmt->execute([
            'candidate_uuid' => $candidateUuid,
            'staging_uuid' => $stagingUuid,
            'candidate_org_uuid' => $candidateOrgUuid,
            'match_score' => $matchScore,
            'match_reason_json' => json_encode($matchReasons)
        ]);
        
        return $candidateUuid;
    }
    
    /**
     * Normalisiert String
     */
    private function normalizeString(string $str): string
    {
        $str = mb_strtolower(trim($str));
        $str = preg_replace('/\s+/', ' ', $str);
        return $str;
    }
    
    private function normalizeForNameComparison(string $str): string
    {
        $str = mb_strtolower($str);
        $str = $this->stripLegalForms($str);
        $str = preg_replace('/[^a-z0-9]/u', '', $str);
        return $str;
    }
    
    private function stripLegalForms(string $str): string
    {
        $forms = [
            'gmbh', 'ag', 'kg', 'mbh', 'co', 'cokg', 'se', 'ug', 'ek', 'e.k.', 'ltd', 'llc',
            'inc', 'sarl', 'spa', 'bv', 'nv', 'oy', 'oyj', 'kk', 'as', 'ab'
        ];
        foreach ($forms as $form) {
            $str = preg_replace('/\b' . preg_quote($form, '/') . '\b/u', '', $str);
        }
        // Spezielle zusammengesetzte Form
        $str = preg_replace('/\bgmbh\s*&\s*co\s*kg\b/u', '', $str);
        // Mehrfache Leerzeichen reduzieren
        $str = preg_replace('/\s+/u', ' ', trim($str));
        return $str;
    }
    
    /**
     * Extrahiert Domain
     */
    private function extractDomain(string $url): string
    {
        if (empty($url)) {
            return '';
        }
        
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        
        $parsed = parse_url($url);
        return mb_strtolower($parsed['host'] ?? '');
    }
    
    /**
     * String-Ähnlichkeit
     */
    private function stringSimilarity(string $str1, string $str2): float
    {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        
        if ($len1 === 0 && $len2 === 0) {
            return 1.0;
        }
        
        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }
        
        if ($str1 === $str2) {
            return 1.0;
        }
        
        $maxLen = max($len1, $len2);
        $distance = levenshtein($str1, $str2);
        
        return 1.0 - ($distance / $maxLen);
    }
}
