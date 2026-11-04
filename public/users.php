<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_admin();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
    if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || mb_strlen($display) < 1 || mb_strlen($display) > 120 || strlen($password) < 12) $error = 'Bitte gültige Angaben verwenden (Passwort mindestens 12 Zeichen).';
    else {
        try { $q = db()->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)'); $q->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT), $role]); }
        catch (PDOException $e) { $error = $e->getCode() === '23000' ? 'Dieser Benutzername ist bereits vergeben.' : 'Benutzer konnte nicht angelegt werden.'; }
    }
}
$users = db()->query('SELECT username, display_name, role, is_active, created_at FROM users ORDER BY username')->fetchAll();
$form = '<h2>Benutzer anlegen</h2><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><label>Benutzername<input name="username" required pattern="[A-Za-z0-9._-]{3,80}"></label><label>Anzeigename<input name="display_name" required maxlength="120"></label><label>Startpasswort (mindestens 12 Zeichen)<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><label>Rolle<select name="role"><option value="user">Benutzer</option><option value="admin">Administrator</option></select></label><button>Benutzer anlegen</button></form>';
$rows = '<h2>Konten</h2><ul>';
foreach ($users as $u) $rows .= '<li>' . h($u['display_name']) . ' (' . h($u['username']) . ') · ' . h($u['role']) . ($u['is_active'] ? '' : ' · deaktiviert') . '</li>';
$rows .= '</ul>';
page('Benutzerverwaltung', '<h1>Benutzerverwaltung</h1>' . ($error ? '<p class="error">' . h($error) . '</p>' : '') . $form . $rows);
