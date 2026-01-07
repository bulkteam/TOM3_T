<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Validation;

/**
 * InputValidator
 * 
 * Zentrale Validierungs-Funktionen für API-Input
 * Wirft ValidationException bei Fehlern
 */
class InputValidator
{
    /**
     * Validiert ein Pflichtfeld
     * 
     * @param array $data Daten-Array
     * @param string $field Feldname
     * @param string|null $message Optionale Fehlermeldung
     * @throws ValidationException Wenn Feld fehlt oder leer ist
     */
    public static function validateRequired(array $data, string $field, ?string $message = null): void
    {
        if (empty($data[$field])) {
            $msg = $message ?? "Field '{$field}' is required";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert String-Länge
     * 
     * @param string $value Wert
     * @param int $min Minimale Länge
     * @param int $max Maximale Länge
     * @param string $field Feldname
     * @throws ValidationException Wenn Länge nicht im erlaubten Bereich
     */
    public static function validateLength(string $value, int $min, int $max, string $field): void
    {
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            $msg = "Field '{$field}' must be between {$min} and {$max} characters (got {$len})";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert E-Mail-Format
     * 
     * @param string $email E-Mail-Adresse
     * @param string $field Feldname (default: 'email')
     * @throws ValidationException Wenn E-Mail ungültig ist
     */
    public static function validateEmail(string $email, string $field = 'email'): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "Field '{$field}' must be a valid email address";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert Enum-Wert
     * 
     * @param mixed $value Wert
     * @param array $allowedValues Erlaubte Werte
     * @param string $field Feldname
     * @throws ValidationException Wenn Wert nicht in erlaubten Werten
     */
    public static function validateEnum($value, array $allowedValues, string $field): void
    {
        if (!in_array($value, $allowedValues, true)) {
            $msg = "Field '{$field}' must be one of: " . implode(', ', $allowedValues);
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert UUID-Format
     * 
     * @param string $uuid UUID-String
     * @param string $field Feldname (default: 'uuid')
     * @throws ValidationException Wenn UUID ungültig ist
     */
    public static function validateUuid(string $uuid, string $field = 'uuid'): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (!preg_match($pattern, $uuid)) {
            $msg = "Field '{$field}' must be a valid UUID";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert Datum-Format (YYYY-MM-DD)
     * 
     * @param string $date Datum-String
     * @param string $field Feldname (default: 'date')
     * @throws ValidationException Wenn Datum ungültig ist
     */
    public static function validateDate(string $date, string $field = 'date'): void
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $msg = "Field '{$field}' must be a valid date (YYYY-MM-DD)";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert numerischen Wert (Integer)
     * 
     * @param mixed $value Wert
     * @param int|null $min Minimaler Wert
     * @param int|null $max Maximaler Wert
     * @param string $field Feldname
     * @throws ValidationException Wenn Wert ungültig ist
     */
    public static function validateInteger($value, ?int $min = null, ?int $max = null, string $field = 'value'): void
    {
        if (!is_numeric($value) || (int)$value != $value) {
            $msg = "Field '{$field}' must be an integer";
            throw new ValidationException($msg, [$field => $msg]);
        }
        
        $intValue = (int)$value;
        
        if ($min !== null && $intValue < $min) {
            $msg = "Field '{$field}' must be at least {$min}";
            throw new ValidationException($msg, [$field => $msg]);
        }
        
        if ($max !== null && $intValue > $max) {
            $msg = "Field '{$field}' must be at most {$max}";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert numerischen Wert (Float)
     * 
     * @param mixed $value Wert
     * @param float|null $min Minimaler Wert
     * @param float|null $max Maximaler Wert
     * @param string $field Feldname
     * @throws ValidationException Wenn Wert ungültig ist
     */
    public static function validateFloat($value, ?float $min = null, ?float $max = null, string $field = 'value'): void
    {
        if (!is_numeric($value)) {
            $msg = "Field '{$field}' must be a number";
            throw new ValidationException($msg, [$field => $msg]);
        }
        
        $floatValue = (float)$value;
        
        if ($min !== null && $floatValue < $min) {
            $msg = "Field '{$field}' must be at least {$min}";
            throw new ValidationException($msg, [$field => $msg]);
        }
        
        if ($max !== null && $floatValue > $max) {
            $msg = "Field '{$field}' must be at most {$max}";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert Boolean-Wert
     * 
     * @param mixed $value Wert
     * @param string $field Feldname
     * @return bool Validierter Boolean-Wert
     * @throws ValidationException Wenn Wert kein Boolean ist
     */
    public static function validateBoolean($value, string $field = 'value'): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (in_array($value, ['true', '1', 1, 'yes'], true)) {
            return true;
        }
        
        if (in_array($value, ['false', '0', 0, 'no'], true)) {
            return false;
        }
        
        $msg = "Field '{$field}' must be a boolean";
        throw new ValidationException($msg, [$field => $msg]);
    }
    
    /**
     * Validiert Array
     * 
     * @param mixed $value Wert
     * @param string $field Feldname
     * @param int|null $minSize Minimale Array-Größe
     * @param int|null $maxSize Maximale Array-Größe
     * @throws ValidationException Wenn Wert kein Array ist oder Größe nicht passt
     */
    public static function validateArray($value, string $field = 'value', ?int $minSize = null, ?int $maxSize = null): void
    {
        if (!is_array($value)) {
            $msg = "Field '{$field}' must be an array";
            throw new ValidationException($msg, [$field => $msg]);
        }
        
        $size = count($value);
        
        if ($minSize !== null && $size < $minSize) {
            $msg = "Field '{$field}' must have at least {$minSize} elements";
            throw new ValidationException($msg, [$field => $msg]);
        }
        
        if ($maxSize !== null && $size > $maxSize) {
            $msg = "Field '{$field}' must have at most {$maxSize} elements";
            throw new ValidationException($msg, [$field => $msg]);
        }
    }
    
    /**
     * Validiert mehrere Felder auf einmal
     * 
     * @param array $data Daten-Array
     * @param array $rules Validierungsregeln: ['field' => ['required', 'length:1:255', 'email'], ...]
     * @throws ValidationException Wenn Validierung fehlschlägt
     */
    public static function validate(array $data, array $rules): void
    {
        $errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            $fieldValue = $data[$field] ?? null;
            
            foreach ($fieldRules as $rule) {
                try {
                    self::applyRule($field, $fieldValue, $rule, $data);
                } catch (ValidationException $e) {
                    $errors = array_merge($errors, $e->getErrors());
                    break; // Erster Fehler pro Feld
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }
    }
    
    /**
     * Wendet eine Validierungsregel an
     * 
     * @param string $field Feldname
     * @param mixed $value Feldwert
     * @param string $rule Regel (z.B. 'required', 'length:1:255', 'email')
     * @param array $data Gesamte Daten (für Kontext)
     * @throws ValidationException Wenn Regel fehlschlägt
     */
    private static function applyRule(string $field, $value, string $rule, array $data): void
    {
        if ($rule === 'required') {
            if (empty($value) && $value !== '0' && $value !== 0) {
                throw new ValidationException("Field '{$field}' is required", [$field => "Field '{$field}' is required"]);
            }
            return;
        }
        
        // Skip validation if field is empty and not required
        if (empty($value) && $value !== '0' && $value !== 0) {
            return;
        }
        
        if (strpos($rule, 'length:') === 0) {
            $parts = explode(':', $rule);
            $min = (int)($parts[1] ?? 0);
            $max = (int)($parts[2] ?? PHP_INT_MAX);
            self::validateLength((string)$value, $min, $max, $field);
            return;
        }
        
        if ($rule === 'email') {
            self::validateEmail((string)$value, $field);
            return;
        }
        
        if ($rule === 'uuid') {
            self::validateUuid((string)$value, $field);
            return;
        }
        
        if ($rule === 'date') {
            self::validateDate((string)$value, $field);
            return;
        }
        
        if (strpos($rule, 'enum:') === 0) {
            $parts = explode(':', $rule);
            $allowedValues = array_slice($parts, 1);
            self::validateEnum($value, $allowedValues, $field);
            return;
        }
        
        if (strpos($rule, 'integer') === 0) {
            $min = null;
            $max = null;
            if (preg_match('/integer:(\d+):(\d+)/', $rule, $matches)) {
                $min = (int)$matches[1];
                $max = (int)$matches[2];
            }
            self::validateInteger($value, $min, $max, $field);
            return;
        }
    }
}




