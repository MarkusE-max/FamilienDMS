<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
start_secure_session();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $q = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE username = ?');
    $q->execute([$username]);
    $row = $q->fetch();
    if ($row && (int) $row['is_active'] && password_verify((string) ($_POST['password'] ?? ''), $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$row['id']]);
        header('Location: ' . app_url('dashboard.php')); exit;
    }
    $error = 'Anmeldung fehlgeschlagen.';
}
page('Anmelden', '<h1>Anmelden</h1>' . ($error ? '<p class="error">' . h($error) . '</p>' : '') . '<form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><label>Benutzername<input name="username" required autocomplete="username" maxlength="80"></label><label>Passwort<input type="password" name="password" required autocomplete="current-password"></label><button>Anmelden</button></form>');
