<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$checks = ['PHP 8.2+' => PHP_VERSION_ID >= 80200, 'PDO MySQL' => extension_loaded('pdo_mysql'), 'Fileinfo' => extension_loaded('fileinfo'), 'mbstring' => extension_loaded('mbstring'), 'HTTPS' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'];
$error = '';
try { $usersExist = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }
catch (Throwable $e) { $usersExist = false; $error = 'Datenbank nicht erreichbar. Konfiguration und Migration prüfen.'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (in_array(false, $checks, true) || $usersExist) $error = 'Voraussetzungen nicht erfüllt oder Administrator existiert bereits.';
    else {
        $username = trim((string) ($_POST['username'] ?? '')); $display = trim((string) ($_POST['display_name'] ?? '')); $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || $display === '' || mb_strlen($display) > 120 || strlen($password) < 12) $error = 'Angaben ungültig; Passwort muss mindestens 12 Zeichen haben.';
        else { $q = db()->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, \'admin\')'); $q->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT)]); header('Location: /login.php'); exit; }
    }
}
$items = '<ul>'; foreach ($checks as $label => $ok) $items .= '<li>' . h($label) . ': ' . ($ok ? 'OK' : 'FEHLT') . '</li>'; $items .= '</ul>';
$form = (!$usersExist && !in_array(false, $checks, true)) ? '<h2>Ersten Administrator erstellen</h2><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><label>Benutzername<input name="username" required></label><label>Anzeigename<input name="display_name" required></label><label>Passwort (mindestens 12 Zeichen)<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button>Administrator erstellen</button></form>' : '';
page('Installation prüfen', '<h1>Installationsprüfung</h1><p>Nach Einrichtung den Zugriff auf diese Datei im Web Station Regelwerk sperren oder die Datei entfernen.</p>' . $items . ($error ? '<p class="error">' . h($error) . '</p>' : '') . $form);
