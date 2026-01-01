<?php
/**
 * TOM3 - Login (Dev-Auth)
 * 
 * Einfache User-Auswahl für Entwicklung
 * Nur aktiv wenn AUTH_MODE=dev
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Auth\AuthService;
use TOM\Infrastructure\Activity\ActivityLogService;

$authMode = getenv('AUTH_MODE') ?: 'dev';
if ($authMode !== 'dev') {
    http_response_code(404);
    die('Not found');
}

try {
    $activityLogService = new ActivityLogService();
    $auth = new AuthService(null, $activityLogService);
} catch (Exception $e) {
    http_response_code(500);
    die('Auth service initialization failed: ' . $e->getMessage());
}

// Wenn schon eingeloggt -> weiter zur Hauptseite
$currentUser = $auth->getCurrentUser();
if ($currentUser) {
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }
    header('Location: ' . $basePath . '/index.html');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if ($auth->login($userId)) {
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }
        header('Location: ' . $basePath . '/index.html');
        exit;
    } else {
        $error = "Ungültiger User.";
    }
}

$users = $auth->getActiveUsers();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login (Dev) - TOM3</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: var(--bg);
        }
        .login-box {
            background: var(--bg-card);
            padding: 3rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        .login-box h1 {
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }
        .login-box .form-group {
            margin-bottom: 1.5rem;
        }
        .login-box label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .login-box select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.375rem;
            font-size: 12pt;
        }
        .login-box .error {
            color: var(--danger);
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 0.375rem;
        }
        .login-box .btn {
            width: 100%;
            padding: 0.75rem;
            font-size: 12pt;
            font-weight: 600;
        }
        .login-box .dev-badge {
            margin-top: 1rem;
            padding: 0.5rem;
            background: var(--warning);
            color: #000;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>TOM3 Login (Dev)</h1>
            
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label for="user_id">Als User anmelden:</label>
                    <select name="user_id" id="user_id" required>
                        <option value="">-- Bitte wählen --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int)$user['user_id'] ?>">
                                <?= htmlspecialchars(
                                    $user['name'] . 
                                    ' (' . $user['email'] . ')' . 
                                    ($user['roles'] ? ' [' . $user['roles'] . ']' : ''),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-success">Anmelden</button>
            </form>
            
            <div class="dev-badge">
                ⚠️ Dev-Auth Modus - Nur für Entwicklung
            </div>
        </div>
    </div>
</body>
</html>

