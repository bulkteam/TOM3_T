<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

/**
 * ClamAvService
 * 
 * Wrapper für ClamAV Malware-Scanning
 * Kommuniziert mit ClamAV Docker-Container über Socket oder CLI
 */
class ClamAvService
{
    private string $clamdSocket;
    private string $dockerContainer;
    private bool $useDocker;
    
    /**
     * @param string|null $clamdSocket Socket-Adresse (z.B. "127.0.0.1:3310")
     * @param string|null $dockerContainer Docker-Container-Name (z.B. "tom3-clamav")
     */
    public function __construct(?string $clamdSocket = null, ?string $dockerContainer = null)
    {
        // Standard: Docker-Container verwenden
        $this->dockerContainer = $dockerContainer ?? getenv('CLAMAV_CONTAINER') ?: 'tom3-clamav';
        $this->clamdSocket = $clamdSocket ?? getenv('CLAMAV_SOCKET') ?: '127.0.0.1:3310';
        
        // Prüfe, ob Docker verwendet werden soll
        $this->useDocker = (bool)getenv('CLAMAV_USE_DOCKER') ?: true;
    }
    
    /**
     * Scannt eine Datei auf Malware
     * 
     * @param string $filePath Vollständiger Pfad zur Datei
     * @return array ['status' => 'clean'|'infected'|'error', 'message' => string, 'threats' => array|null]
     * @throws \RuntimeException Bei Fehlern
     */
    public function scan(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        if ($this->useDocker) {
            return $this->scanViaDocker($filePath);
        } else {
            return $this->scanViaSocket($filePath);
        }
    }
    
    /**
     * Scannt über Docker exec (wenn Datei im Container erreichbar ist)
     */
    private function scanViaDocker(string $filePath): array
    {
        // Prüfe, ob Container läuft
        $containerRunning = $this->isContainerRunning();
        if (!$containerRunning) {
            throw new \RuntimeException("ClamAV Container '{$this->dockerContainer}' läuft nicht");
        }
        
        // Datei muss für Container erreichbar sein
        // Option 1: Volume-Mount (z.B. /scans/...)
        // Option 2: Datei in Container kopieren (nicht empfohlen für große Dateien)
        
        // Versuche zuerst über Volume-Mount
        // Annahme: storage/ ist gemountet als /scans
        $containerPath = $this->getContainerPath($filePath);
        
        // Verwende --archive-verbose für besseres Archive-Scanning (DOCX, ZIP, etc.)
        // --fdpass: Datei-Deskriptor-Passing für bessere Performance
        // --infected: Nur bei Infektionen Output
        // --no-summary: Keine Zusammenfassung am Ende
        $command = sprintf(
            'docker exec %s clamdscan --no-summary --infected --fdpass --archive-verbose %s 2>&1',
            escapeshellarg($this->dockerContainer),
            escapeshellarg($containerPath)
        );
        
        exec($command, $output, $returnCode);
        $outputStr = implode("\n", $output);
        
        // Parse Ergebnis
        return $this->parseScanResult($returnCode, $outputStr, $filePath);
    }
    
    /**
     * Scannt über Socket (wenn clamdscan lokal installiert ist)
     */
    private function scanViaSocket(string $filePath): array
    {
        $command = sprintf(
            'clamdscan --no-summary --infected --fdpass --socket=%s %s 2>&1',
            escapeshellarg($this->clamdSocket),
            escapeshellarg($filePath)
        );
        
        exec($command, $output, $returnCode);
        $outputStr = implode("\n", $output);
        
        return $this->parseScanResult($returnCode, $outputStr, $filePath);
    }
    
    /**
     * Parst das Scan-Ergebnis
     */
    private function parseScanResult(int $returnCode, string $output, string $filePath): array
    {
        // ClamAV Return Codes:
        // 0 = clean
        // 1 = infected
        // 2 = Fehler
        
        if ($returnCode === 0) {
            return [
                'status' => 'clean',
                'message' => 'No threats found',
                'threats' => null,
                'file' => $filePath
            ];
        } elseif ($returnCode === 1) {
            // Infected - parse Threat-Namen
            $threats = $this->extractThreats($output);
            return [
                'status' => 'infected',
                'message' => 'Threat detected: ' . implode(', ', $threats),
                'threats' => $threats,
                'file' => $filePath,
                'raw_output' => $output
            ];
        } else {
            // Fehler
            throw new \RuntimeException("ClamAV scan failed (code: {$returnCode}): {$output}");
        }
    }
    
    /**
     * Extrahiert Threat-Namen aus ClamAV-Output
     */
    private function extractThreats(string $output): array
    {
        $threats = [];
        // ClamAV Output: "filename: ThreatName FOUND"
        if (preg_match_all('/(\w+):\s*([^\s]+)\s+FOUND/i', $output, $matches)) {
            $threats = array_unique($matches[2]);
        }
        return $threats;
    }
    
    /**
     * Prüft, ob Docker-Container läuft
     */
    private function isContainerRunning(): bool
    {
        $command = sprintf(
            'docker ps --filter "name=%s" --filter "status=running" --format "{{.Names}}"',
            escapeshellarg($this->dockerContainer)
        );
        
        exec($command, $output, $returnCode);
        return $returnCode === 0 && !empty($output) && trim($output[0]) === $this->dockerContainer;
    }
    
    /**
     * Konvertiert Host-Pfad zu Container-Pfad
     * 
     * Annahme: storage/ ist gemountet als /scans
     */
    private function getContainerPath(string $filePath): string
    {
        // Normalisiere Pfade
        $filePath = str_replace('\\', '/', $filePath);
        
        // Prüfe, ob Datei in storage/ liegt
        if (strpos($filePath, '/storage/') !== false || strpos($filePath, '\\storage\\') !== false) {
            // Extrahiere relativen Pfad ab storage/
            $parts = explode('storage/', $filePath);
            if (count($parts) > 1) {
                return '/scans/' . $parts[1];
            }
        }
        
        // Fallback: Versuche absoluten Pfad (funktioniert nur mit Volume-Mount)
        // Für Windows: C:/xampp/htdocs/TOM3/storage/... -> /scans/...
        if (preg_match('/[A-Z]:/', $filePath)) {
            // Windows-Pfad
            $relativePath = str_replace('C:/xampp/htdocs/TOM3/storage/', '/scans/', $filePath);
            $relativePath = str_replace('\\', '/', $relativePath);
            return $relativePath;
        }
        
        // Wenn kein Volume-Mount: Datei muss in Container kopiert werden
        // (nicht empfohlen, aber als Fallback)
        throw new \RuntimeException("Datei-Pfad nicht für Container erreichbar: {$filePath}. Bitte Volume-Mount konfigurieren.");
    }
    
    /**
     * Prüft, ob ClamAV verfügbar ist
     */
    public function isAvailable(): bool
    {
        if ($this->useDocker) {
            return $this->isContainerRunning();
        } else {
            // Prüfe, ob clamdscan lokal verfügbar ist
            exec('clamdscan --version 2>&1', $output, $returnCode);
            return $returnCode === 0;
        }
    }
    
    /**
     * Gibt ClamAV-Version zurück
     */
    public function getVersion(): ?string
    {
        if ($this->useDocker) {
            if (!$this->isContainerRunning()) {
                return null;
            }
            $command = sprintf('docker exec %s clamdscan --version 2>&1', escapeshellarg($this->dockerContainer));
        } else {
            $command = 'clamdscan --version 2>&1';
        }
        
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return trim($output[0]);
        }
        
        return null;
    }
}


