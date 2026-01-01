<?php
/**
 * TOM3 - API Input Validation
 * 
 * Zentrale Validierungs-Funktionen für API-Input
 * 
 * Pattern: Request → Validator → Service
 */

declare(strict_types=1);

/**
 * Validiert ein Pflichtfeld
 */
function validateRequired(array $data, string $field, ?string $message = null): void
{
    if (empty($data[$field])) {
        $msg = $message ?? "Field '{$field}' is required";
        http_response_code(400);
        echo json_encode(['error' => 'Validation error', 'message' => $msg, 'field' => $field]);
        exit;
    }
}

/**
 * Validiert String-Länge
 */
function validateLength(string $value, int $min, int $max, string $field): void
{
    $len = mb_strlen($value);
    if ($len < $min || $len > $max) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Validation error',
            'message' => "Field '{$field}' must be between {$min} and {$max} characters",
            'field' => $field,
            'length' => $len
        ]);
        exit;
    }
}

/**
 * Validiert E-Mail-Format
 */
function validateEmail(string $email, string $field = 'email'): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Validation error',
            'message' => "Field '{$field}' must be a valid email address",
            'field' => $field
        ]);
        exit;
    }
}

/**
 * Validiert Enum-Wert
 */
function validateEnum($value, array $allowedValues, string $field): void
{
    if (!in_array($value, $allowedValues, true)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Validation error',
            'message' => "Field '{$field}' must be one of: " . implode(', ', $allowedValues),
            'field' => $field,
            'allowed' => $allowedValues
        ]);
        exit;
    }
}

/**
 * Validiert UUID-Format
 */
function validateUuid(string $uuid, string $field = 'uuid'): void
{
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if (!preg_match($pattern, $uuid)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Validation error',
            'message' => "Field '{$field}' must be a valid UUID",
            'field' => $field
        ]);
        exit;
    }
}

/**
 * Validiert Datum-Format (YYYY-MM-DD)
 */
function validateDate(string $date, string $field = 'date'): void
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Validation error',
            'message' => "Field '{$field}' must be a valid date (YYYY-MM-DD)",
            'field' => $field
        ]);
        exit;
    }
}

/**
 * Validiert JSON-Body und gibt dekodierte Daten zurück
 */
function getValidatedJsonBody(): array
{
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid JSON',
            'message' => json_last_error_msg()
        ]);
        exit;
    }
    
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body', 'message' => 'Expected JSON object']);
        exit;
    }
    
    return $data;
}

/**
 * Beispiel: Validator für Person-Erstellung
 * 
 * Verwendung:
 * $data = getValidatedJsonBody();
 * validatePersonCreate($data);
 * // Danach: $personService->createPerson($data);
 */
function validatePersonCreate(array $data): void
{
    validateRequired($data, 'first_name');
    validateRequired($data, 'last_name');
    
    if (isset($data['first_name'])) {
        validateLength($data['first_name'], 1, 120, 'first_name');
    }
    if (isset($data['last_name'])) {
        validateLength($data['last_name'], 1, 120, 'last_name');
    }
    if (isset($data['email']) && !empty($data['email'])) {
        validateEmail($data['email']);
        validateLength($data['email'], 3, 255, 'email');
    }
    if (isset($data['phone']) && !empty($data['phone'])) {
        validateLength($data['phone'], 0, 50, 'phone');
    }
    if (isset($data['salutation']) && !empty($data['salutation'])) {
        validateEnum($data['salutation'], ['Herr', 'Frau', 'Dr.', 'Prof.'], 'salutation');
    }
}

/**
 * Beispiel: Validator für Org-Erstellung
 */
function validateOrgCreate(array $data): void
{
    validateRequired($data, 'name');
    validateLength($data['name'], 1, 255, 'name');
    
    if (isset($data['org_kind'])) {
        validateEnum($data['org_kind'], ['customer', 'supplier', 'consultant', 'internal', 'other'], 'org_kind');
    }
    if (isset($data['status'])) {
        validateEnum($data['status'], ['lead', 'prospect', 'customer', 'inactive'], 'status');
    }
    if (isset($data['revenue_range']) && !empty($data['revenue_range'])) {
        validateEnum($data['revenue_range'], ['micro', 'small', 'medium', 'large', 'enterprise'], 'revenue_range');
    }
}
