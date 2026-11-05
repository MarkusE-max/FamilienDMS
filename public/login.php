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
$body = '<section class="auth-layout"><div class="auth-aside"><div><p class="eyebrow" style="color:#b7cbbd">FamilienDMS · privat</p><h2>Wichtige Unterlagen. An einem sicheren Ort.</h2><p>Ein gemeinsames digitales Archiv für die Dinge, die in der Familie zählen.</p></div><span class="auth-quote">Übersichtlich. Verlässlich. Für euch.</span></div><div class="auth-form-wrap"><p class="eyebrow">Willkommen zurück</p><h1>Anmelden</h1><p>Melde dich mit deinem Familienkonto an, um fortzufahren.</p>' . ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '') . '<form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><label>Benutzername<input name="username" required autocomplete="username" maxlength="80" placeholder="Dein Benutzername"></label><label>Passwort<input type="password" name="password" required autocomplete="current-password" placeholder="Dein Passwort"></label><div class="form-actions"><button type="submit">Anmelden <span aria-hidden="true">→</span></button></div></form></div></section>';
page('Anmelden', $body);
