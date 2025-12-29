<?php
/**
 * PLZ-Mapping für Neo4j Migration
 * 
 * Enthält Funktionen zur Zuordnung von PLZ zu Bundesland und Stadt
 */

/**
 * Holt Koordinaten aus der CSV-Datei
 */
function getCoordinatesFromCSV($plz) {
    $filename = __DIR__ . '/definitions/plz_bundesland.csv';
    if (!file_exists($filename)) {
        return null;
    }

    if (($handle = fopen($filename, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ";");
        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            if (count($data) < 4) {
                continue;
            }
            $plz_csv = str_pad(trim((string)$data[1]), 5, '0', STR_PAD_LEFT);
            if ((string)$plz_csv === (string)$plz) {
                $geo_point = trim($data[3]);
                if (strpos($geo_point, ',') !== false) {
                    list($lat, $lon) = explode(',', $geo_point, 2);
                    $lat = preg_replace('/[^\d\.\-]/', '', $lat);
                    $lon = preg_replace('/[^\d\.\-]/', '', $lon);
                    if (is_numeric($lat) && is_numeric($lon)) {
                        fclose($handle);
                        return ['lat' => $lat, 'lon' => $lon];
                    }
                }
                break;
            }
        }
        fclose($handle);
    }
    return null;
}

/**
 * Mappt PLZ zu Bundesland und Stadt basierend auf der CSV-Datei
 * 
 * @param string $plz Postleitzahl
 * @return array Array mit 'bundesland' und 'city'
 */
function mapPlzToBundeslandAndCity($plz)
{
    // Versuche zuerst den optimierten Loader zu verwenden
    if (file_exists(__DIR__ . '/definitions/optimized_area_codes_loader.php')) {
        require_once __DIR__ . '/definitions/optimized_area_codes_loader.php';
        
        $info = OptimizedAreaCodesLoader::getBundeslandInfo($plz);
        if ($info) {
            // Koordinaten aus CSV holen
            $coords = getCoordinatesFromCSV($plz);
            return [
                'bundesland' => $info['bundesland'],
                'city' => $info['city'],
                'latitude' => $coords ? $coords['lat'] : null,
                'longitude' => $coords ? $coords['lon'] : null
            ];
        }
    }
    
    // Fallback auf CSV-basierten Ansatz
    static $plzCache = null;
    
    // Cache nur einmal laden
    if ($plzCache === null) {
        $plzCache = loadPlzMappingFromCsv(__DIR__ . '/definitions/plz_bundesland.CSV');
    }
    
    // PLZ in der Cache suchen
    if (isset($plzCache[$plz])) {
        return $plzCache[$plz];
    }
    
    return [
        'bundesland' => 'Unbekannt',
        'city' => 'Unbekannt',
        'latitude' => null,
        'longitude' => null
    ];
}

/**
 * Mappt PLZ-Präfix zu Bundesland (Legacy-Funktion für Kompatibilität)
 * 
 * @param string $plz Postleitzahl
 * @return string Bundesland-Name
 */
function mapPlzToBundesland($plz)
{
    $result = mapPlzToBundeslandAndCity($plz);
    return $result['bundesland'];
}

/**
 * Mappt PLZ zu Stadt (Legacy-Funktion für Kompatibilität)
 * 
 * @param string $plz Postleitzahl
 * @return string Stadt-Name
 */
function getCityFromPlz($plz)
{
    $result = mapPlzToBundeslandAndCity($plz);
    return $result['city'];
}

/**
 * Lädt PLZ-Mapping aus der CSV-Datei
 * 
 * @param string $csvFile Pfad zur CSV-Datei
 * @return array Array mit PLZ => ['bundesland' => ..., 'city' => ..., 'latitude' => ..., 'longitude' => ...]
 */
function loadPlzMappingFromCsv($csvFile)
{
    $mapping = [];
    
    if (file_exists($csvFile)) {
        // Datei als UTF-8 mit BOM lesen
        $content = file_get_contents($csvFile);
        
        // BOM entfernen falls vorhanden
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        
        // Zeilen aufteilen
        $lines = explode("\n", $content);
        
        // Header überspringen
        array_shift($lines);
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $data = str_getcsv($line, ';');
            if (count($data) >= 4) {
                $city = trim($data[0]);
                $plz = trim($data[1]);
                $bundesland = trim($data[2]);
                $geoPoint = trim($data[3]);
                
                if (!empty($plz) && !empty($bundesland)) {
                    // Zeichenkodierung korrigieren
                    $city = mb_convert_encoding($city, 'UTF-8', 'ISO-8859-1');
                    $bundesland = mb_convert_encoding($bundesland, 'UTF-8', 'ISO-8859-1');
                    
                    // Koordinaten extrahieren
                    $latitude = null;
                    $longitude = null;
                    
                    if (strpos($geoPoint, ',') !== false) {
                        list($lat, $lon) = explode(',', $geoPoint, 2);
                        $latitude = preg_replace('/[^\d\.\-]/', '', $lat);
                        $longitude = preg_replace('/[^\d\.\-]/', '', $lon);
                    }
                    
                    $mapping[$plz] = [
                        'bundesland' => $bundesland,
                        'city' => $city,
                        'latitude' => $latitude,
                        'longitude' => $longitude
                    ];
                }
            }
        }
    }
    
    return $mapping;
}

/**
 * Erweitert das PLZ-Stadt-Mapping um neue Einträge
 * 
 * @param array $newMappings Array mit PLZ => Stadt Zuordnungen
 * @return void
 */
function extendPlzCityMapping($newMappings)
{
    global $plzCityMapping;
    
    if (!isset($plzCityMapping)) {
        $plzCityMapping = [];
    }
    
    $plzCityMapping = array_merge($plzCityMapping, $newMappings);
} 