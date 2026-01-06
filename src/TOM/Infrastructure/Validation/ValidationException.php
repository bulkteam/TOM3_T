<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Validation;

/**
 * ValidationException
 * 
 * Wird geworfen wenn Input-Validierung fehlschlägt
 * Enthält detaillierte Fehlerinformationen für jeden fehlerhaften Feld
 */
class ValidationException extends \InvalidArgumentException
{
    private array $errors;
    
    /**
     * @param string $message Hauptfehlermeldung
     * @param array $errors Array von Feld-Fehlern: ['field' => 'error message', ...]
     */
    public function __construct(string $message = 'Validation failed', array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }
    
    /**
     * Gibt alle Validierungsfehler zurück
     * 
     * @return array ['field' => 'error message', ...]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Gibt einen Validierungsfehler für ein bestimmtes Feld zurück
     * 
     * @param string $field Feldname
     * @return string|null Fehlermeldung oder null
     */
    public function getError(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
    
    /**
     * Prüft ob ein bestimmtes Feld einen Fehler hat
     * 
     * @param string $field Feldname
     * @return bool True wenn Feld einen Fehler hat
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }
    
    /**
     * Gibt die Anzahl der Fehler zurück
     * 
     * @return int Anzahl der Fehler
     */
    public function getErrorCount(): int
    {
        return count($this->errors);
    }
}



