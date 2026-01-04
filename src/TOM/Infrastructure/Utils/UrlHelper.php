<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Utils;

/**
 * URL Helper - Normalisiert und validiert URLs
 */
class UrlHelper
{
    /**
     * Normalisiert eine URL zu einem einheitlichen Format
     * 
     * Beispiele:
     * - "www.example.com" → "https://www.example.com"
     * - "example.com" → "https://example.com"
     * - "http://example.com" → "http://example.com"
     * - "https://example.com" → "https://example.com"
     * 
     * @param string|null $url Die zu normalisierende URL
     * @return string|null Die normalisierte URL oder null wenn leer/ungültig
     */
    public static function normalize(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        
        // Entferne Leerzeichen am Anfang und Ende
        $url = trim($url);
        
        if (empty($url)) {
            return null;
        }
        
        // Entferne führende/trailing Slashes (außer nach Protokoll)
        $url = trim($url, '/');
        
        // Wenn bereits ein Protokoll vorhanden ist, prüfe ob es gültig ist
        if (preg_match('/^https?:\/\//i', $url)) {
            // URL hat bereits Protokoll, nur normalisieren
            return self::cleanUrl($url);
        }
        
        // Kein Protokoll vorhanden - füge https:// hinzu
        // Prüfe ob es mit www. beginnt
        if (preg_match('/^www\./i', $url)) {
            return 'https://' . self::cleanUrl($url);
        }
        
        // Normale Domain ohne www
        return 'https://' . self::cleanUrl($url);
    }
    
    /**
     * Bereinigt eine URL (entfernt doppelte Slashes, normalisiert)
     * 
     * @param string $url Die zu bereinigende URL
     * @return string Die bereinigte URL
     */
    private static function cleanUrl(string $url): string
    {
        // Entferne Leerzeichen
        $url = trim($url);
        
        // Entferne doppelte Slashes (außer nach Protokoll)
        $url = preg_replace('#([^:])//+#', '$1/', $url);
        
        // Entferne trailing Slash (optional - kann auch behalten werden)
        // $url = rtrim($url, '/');
        
        return $url;
    }
    
    /**
     * Validiert ob eine URL ein gültiges Format hat
     * 
     * @param string|null $url Die zu validierende URL
     * @return bool True wenn die URL gültig ist
     */
    public static function isValid(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }
        
        // Normalisiere zuerst
        $normalized = self::normalize($url);
        
        if (!$normalized) {
            return false;
        }
        
        // Validiere mit filter_var
        return filter_var($normalized, FILTER_VALIDATE_URL) !== false;
    }
}





