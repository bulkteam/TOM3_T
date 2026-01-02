<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * Service für Mapping-Konfiguration und -Anwendung
 */
class ImportMappingService
{
    private PDO $db;
    
    // Mapping-Vorschläge (Header-Name → TOM-Feld)
    // Fokus: Nur Grunddaten, Adresse, Kommunikation, UStID
    private array $fieldSuggestions = [
        // 1. Grunddaten
        'name' => ['firmenname', 'name', 'unternehmen', 'company', 'firma', 'firma1', 'firmapdf', 'namen'],
        'website' => ['website', 'url', 'homepage', 'web'],
        'industry_level1' => ['oberkategorie', 'hauptbranche', 'branchenbereich', 'branche', 'industry', 'sektor'],
        'industry_level2' => ['branche', 'subbranche', 'unterbranche', 'kategorie'],
        'industry_level3' => ['unterbranche', 'spezialisierung', 'detailbranche'],
        // Rückwärtskompatibilität
        'industry_main' => ['oberkategorie', 'hauptbranche', 'branche', 'industry', 'sektor'],
        'industry_sub' => ['subbranche', 'unterbranche', 'kategorie'],
        'revenue_range' => ['umsatzklasse'], // Nur UmsatzKlasse, nicht "Umsatz"
        'employee_count' => ['mitarbeiter', 'employees', 'anzahl', 'manational'],
        'notes' => ['gegruendet', 'gegründet', 'gegruendetjahr', 'gegründetjahr'],
        
        // 2. Adresse
        'address_street' => ['straße', 'street', 'adresse', 'address', 'strasse'],
        'address_postal_code' => ['plz', 'postleitzahl', 'postal', 'zip'],
        'address_city' => ['ort', 'stadt', 'city'],
        'address_state' => ['bundesland', 'state'],
        
        // 3. Kommunikationskanäle
        'email' => ['email', 'e-mail', 'mail'],
        'fax' => ['fax'],
        'phone' => ['telefon', 'phone', 'tel'],
        
        // 4. UStID
        'vat_id' => ['ust-id', 'vat-id', 'umsatzsteuer', 'uin', 'ustid', 'vat']
    ];
    
    // Spalten, die ignoriert werden sollen (werden nicht gemappt)
    private array $ignorePatterns = [
        '/^gf\d+/i',              // GF1, GF2, etc. (Personen)
        '/^zentrale$/i',          // Zentrale
        '/^firmapdf$/i',          // FirmaPDF
        '/^sektor$/i',            // Sektor
        '/^namen$/i',             // Namen
        '/^verbund$/i',            // Verbund
        '/^werbung$/i',           // Werbung
        '/^gruppe$/i',            // Gruppe
        '/^gruppenkriterium$/i',  // Gruppenkriterium
        '/^holding$/i',           // Holding
        '/^wkn$/i',               // WKN
        '/^anzahljobs$/i',        // AnzahlJobs
        '/^standorte$/i',         // Standorte
        '/^maklasse$/i',          // MAKlasse
        '/^umsatz$/i',            // Umsatz (nur UmsatzKlasse verwenden)
        '/^basis$/i',             // Basis
        '/^jahr$/i',              // Jahr (außer GegründetJahr)
        '/^index$/i',             // Index
        '/^eucode$/i',            // EUCode
        '/^bundesland$/i',        // Bundesland (mappen wir aus Bestand)
        '/^regierungsbezirk$/i',  // Regierungsbezirk
        '/^kreis$/i',             // Kreis
        '/^zusatz/i',             // Zusatz1, Zusatz2
        '/^wzwlink/i',            // wzwLinkCRM
        '/^internalid$/i'         // internalID
    ];
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Generiert Mapping-Vorschlag basierend auf Spalten
     * Fokus: Nur Grunddaten, Adresse, Kommunikation, UStID
     * Gibt für jedes TOM-Feld alle passenden Spalten-Kandidaten zurück
     * 
     * @param array $columns Spalten-Array: ['A' => 'Header', ...]
     * @param array $sampleRows Optional: Beispiel-Zeilen für Vorschau
     * @return array Struktur: ['by_field' => [...], 'by_column' => [...]]
     */
    public function suggestMapping(array $columns, array $sampleRows = []): array
    {
        // Struktur: pro TOM-Feld alle Kandidaten
        $byField = [];
        
        // Struktur: pro Excel-Spalte das Mapping
        $byColumn = [];
        
        foreach ($columns as $col => $header) {
            $headerLower = mb_strtolower(trim($header));
            
            // Prüfe, ob Spalte ignoriert werden soll
            $shouldIgnore = false;
            foreach ($this->ignorePatterns as $pattern) {
                if (preg_match($pattern, $headerLower)) {
                    $shouldIgnore = true;
                    break;
                }
            }
            
            if ($shouldIgnore) {
                $byColumn[$col] = [
                    'excel_column' => $col,
                    'excel_header' => $header,
                    'tom_field' => null,
                    'confidence' => 0,
                    'ignore' => true
                ];
                continue;
            }
            
            // Suche alle passenden Felder (nicht nur das beste)
            $matches = [];
            
            foreach ($this->fieldSuggestions as $field => $keywords) {
                foreach ($keywords as $keyword) {
                    // Exakte Übereinstimmung gibt 100%
                    if ($headerLower === $keyword) {
                        $similarity = 1.0;
                    } else {
                        // Teilstring-Übereinstimmung
                        if (strpos($headerLower, $keyword) !== false || strpos($keyword, $headerLower) !== false) {
                            $similarity = 0.9;
                        } else {
                            // Levenshtein-Ähnlichkeit
                            $similarity = $this->stringSimilarity($headerLower, $keyword);
                        }
                    }
                    
                    if ($similarity > 0.6) {
                        if (!isset($matches[$field]) || $similarity > $matches[$field]['confidence']) {
                            $matches[$field] = [
                                'confidence' => $similarity,
                                'matched_keyword' => $keyword
                            ];
                        }
                    }
                }
            }
            
            // Spezielle Behandlung für "Gegründet" → notes
            if (preg_match('/gegr[üu]ndet/i', $header)) {
                $matches['notes'] = [
                    'confidence' => 0.95,
                    'matched_keyword' => 'gegründet'
                ];
            }
            
            // Hole Beispiel-Werte aus sampleRows
            $examples = [];
            if (!empty($sampleRows)) {
                foreach ($sampleRows as $rowData) {
                    if (isset($rowData[$col]) && !empty(trim($rowData[$col]))) {
                        $examples[] = trim($rowData[$col]);
                        if (count($examples) >= 3) break; // Max 3 Beispiele
                    }
                }
            }
            
            if (!empty($matches)) {
                // Sortiere Matches nach Confidence
                uasort($matches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
                $bestMatch = array_key_first($matches);
                $bestConfidence = $matches[$bestMatch]['confidence'];
                
                // Füge zu byField hinzu
                if (!isset($byField[$bestMatch])) {
                    $byField[$bestMatch] = [];
                }
                
                $byField[$bestMatch][] = [
                    'excel_column' => $col,
                    'excel_header' => $header,
                    'confidence' => round($bestConfidence * 100),
                    'examples' => $examples,
                    'matched_keyword' => $matches[$bestMatch]['matched_keyword']
                ];
                
                // Füge zu byColumn hinzu
                $byColumn[$col] = [
                    'excel_column' => $col,
                    'excel_header' => $header,
                    'tom_field' => $bestMatch,
                    'confidence' => round($bestConfidence * 100),
                    'examples' => $examples,
                    'ignore' => false
                ];
            } else {
                // Nicht gemappte Spalten werden ignoriert
                $byColumn[$col] = [
                    'excel_column' => $col,
                    'excel_header' => $header,
                    'tom_field' => null,
                    'confidence' => 0,
                    'examples' => $examples,
                    'ignore' => true
                ];
            }
        }
        
        // Sortiere Kandidaten pro Feld nach Confidence
        foreach ($byField as $field => $candidates) {
            usort($byField[$field], fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        }
        
        return [
            'by_field' => $byField,    // Pro TOM-Feld: Liste aller Kandidaten
            'by_column' => $byColumn   // Pro Excel-Spalte: Mapping-Info
        ];
    }
    
    /**
     * Liest Zeile aus Excel basierend auf Mapping
     */
    public function readRow(Worksheet $worksheet, int $row, array $mappingConfig): array
    {
        $rowData = [];
        $columns = $mappingConfig['columns'] ?? [];
        
        foreach ($columns as $field => $config) {
            $value = null;
            
            // Spaltenbuchstabe oder Header-Name?
            if (isset($config['excel_column'])) {
                $col = $config['excel_column'];
                $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
            } elseif (isset($config['excel_header'])) {
                // Header-Name basiert (muss vorher Spalte finden)
                $col = $this->findColumnByHeader($config['excel_header'], $mappingConfig);
                if ($col) {
                    $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
                }
            }
            
            // Transformationen anwenden
            if ($value !== null && isset($config['transformation'])) {
                $value = $this->applyTransformations($value, $config['transformation']);
            }
            
            // Mapping (z.B. "GmbH" → "customer")
            if ($value !== null && isset($config['mapping'])) {
                $value = $config['mapping'][$value] ?? $value;
            }
            
            // Default-Wert
            if (($value === null || $value === '') && isset($config['default'])) {
                $value = $config['default'];
            }
            
            $rowData[$field] = $value;
        }
        
        return $rowData;
    }
    
    /**
     * Findet Spalte nach Header-Name
     */
    private function findColumnByHeader(string $header, array $mappingConfig): ?string
    {
        // TODO: Implementierung
        return null;
    }
    
    /**
     * Wendet Transformationen an
     */
    private function applyTransformations($value, array $transformations)
    {
        foreach ($transformations as $transformation) {
            $type = $transformation['type'] ?? '';
            
            switch ($type) {
                case 'normalize_url':
                    $value = $this->normalizeUrl($value);
                    break;
                case 'to_int':
                    $value = (int)$value;
                    break;
                case 'to_float':
                    $value = (float)$value;
                    break;
            }
        }
        
        return $value;
    }
    
    /**
     * Normalisiert URL
     */
    private function normalizeUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }
        
        // Entferne führende/trailing Leerzeichen
        $url = trim($url);
        
        // Füge https:// hinzu, falls fehlt
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        
        return $url;
    }
    
    /**
     * Berechnet String-Ähnlichkeit (einfache Levenshtein-basierte)
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
        
        // Exakter Match
        if ($str1 === $str2) {
            return 1.0;
        }
        
        // Enthält
        if (mb_strpos($str1, $str2) !== false || mb_strpos($str2, $str1) !== false) {
            return 0.8;
        }
        
        // Levenshtein-Distanz
        $maxLen = max($len1, $len2);
        $distance = levenshtein($str1, $str2);
        
        return 1.0 - ($distance / $maxLen);
    }
}
