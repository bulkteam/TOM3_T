<?php
/**
 * Prüft und erstellt Test-User (Admin, Manager, etc.)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "=== Test-User Prüfung ===\n\n";
    
    // Prüfe vorhandene User
    $stmt = $db->query("
        SELECT u.user_id, u.email, u.name, GROUP_CONCAT(r.role_code) as roles 
        FROM users u
        LEFT JOIN user_role ur ON u.user_id = ur.user_id
        LEFT JOIN role r ON ur.role_id = r.role_id
        GROUP BY u.user_id
        ORDER BY u.name
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Vorhandene User:\n";
    foreach ($users as $user) {
        echo "  - {$user['name']} ({$user['email']}) - Rollen: " . ($user['roles'] ?: 'keine') . "\n";
    }
    echo "\n";
    
    // Prüfe ob Admin, Manager, etc. existieren
    $testUsers = [
        ['name' => 'Admin', 'email' => 'admin@tom.local', 'role' => 'admin'],
        ['name' => 'Manager', 'email' => 'manager@tom.local', 'role' => 'manager'],
        ['name' => 'User', 'email' => 'user@tom.local', 'role' => 'user'],
        ['name' => 'Readonly', 'email' => 'readonly@tom.local', 'role' => 'readonly']
    ];
    
    echo "Erstelle fehlende Test-User...\n";
    
    foreach ($testUsers as $testUser) {
        // Prüfe ob User bereits existiert
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$testUser['email']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            echo "  ✓ {$testUser['name']} existiert bereits\n";
            continue;
        }
        
        // Erstelle User
        $stmt = $db->prepare("INSERT INTO users (email, name, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$testUser['email'], $testUser['name']]);
        $userId = $db->lastInsertId();
        
        // Weise Rolle zu
        $stmt = $db->prepare("
            INSERT INTO user_role (user_id, role_id)
            SELECT ?, role_id FROM role WHERE role_code = ?
        ");
        $stmt->execute([$userId, $testUser['role']]);
        
        echo "  ✓ {$testUser['name']} erstellt (Rolle: {$testUser['role']})\n";
    }
    
    echo "\n✅ Test-User Setup abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "✗ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}


