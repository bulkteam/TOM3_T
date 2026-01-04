<?php
declare(strict_types=1);

namespace TOM\Service;

/**
 * TOM3 - Search Query Helper (Zentral)
 * Gemeinsame Utilities für Query-Parsing in Suchfunktionen
 * Eliminiert Code-Duplikation zwischen OrgService und PersonService
 */
class SearchQueryHelper
{
    /**
     * Bereitet einen Suchbegriff für LIKE-Queries vor
     * 
     * @param string $query Der Suchbegriff
     * @return array ['search' => '%query%', 'exact' => 'query', 'starts' => 'query%']
     */
    public static function prepareSearchTerms(string $query): array
    {
        $trimmed = trim($query);
        return [
            'search' => '%' . $trimmed . '%',
            'exact' => $trimmed,
            'starts' => $trimmed . '%'
        ];
    }
    
    /**
     * Prüft, ob eine Query leer ist (nach Trim)
     */
    public static function isEmpty(string $query): bool
    {
        return empty(trim($query));
    }
    
    /**
     * Normalisiert einen Suchbegriff (trim, lowercase für Vergleich)
     */
    public static function normalize(string $query): string
    {
        return strtolower(trim($query));
    }
    
    /**
     * Erstellt eine LIKE-Bedingung für mehrere Felder
     * 
     * @param array $fields Array von Feldnamen
     * @param string $paramName Parameter-Name für Prepared Statement
     * @return string SQL-Bedingung (z.B. "field1 LIKE :search OR field2 LIKE :search")
     */
    public static function buildLikeCondition(array $fields, string $paramName = 'search'): string
    {
        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = "$field LIKE :$paramName";
        }
        return '(' . implode(' OR ', $conditions) . ')';
    }
    
    /**
     * Erstellt eine ORDER BY-Klausel mit Relevanz-Sortierung
     * 
     * @param array $priorityFields Array von Feldnamen in Prioritäts-Reihenfolge
     * @param string $exactParam Parameter-Name für exakte Übereinstimmung
     * @param string $startsParam Parameter-Name für "beginnt mit"
     * @param string $searchParam Parameter-Name für "enthält"
     * @param string $fallbackOrder Fallback-ORDER BY (z.B. "name ASC")
     * @return string SQL ORDER BY-Klausel
     */
    public static function buildRelevanceOrder(
        array $priorityFields,
        string $exactParam = 'exact',
        string $startsParam = 'starts',
        string $searchParam = 'search',
        string $fallbackOrder = 'name'
    ): string {
        $cases = [];
        $priority = 1;
        
        foreach ($priorityFields as $field) {
            $cases[] = "WHEN $field = :$exactParam THEN $priority";
            $priority++;
            $cases[] = "WHEN $field LIKE :$startsParam THEN $priority";
            $priority++;
            $cases[] = "WHEN $field LIKE :$searchParam THEN $priority";
            $priority++;
        }
        
        if (empty($cases)) {
            return "ORDER BY $fallbackOrder";
        }
        
        return "ORDER BY CASE " . implode(' ', $cases) . " ELSE $priority END, $fallbackOrder";
    }
}


