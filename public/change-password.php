<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['confirm_password'] ?? '');
    $q = db()->prepare('SELECT password_hash, auth_version FROM users WHERE id = ? AND is_active = 1');
    $q->execute([(int) $user['id']]);
    $account = $q->fetch();
    if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
        $error = 'Das aktuelle Passwort stimmt nicht.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'Das neue Passwort muss mindestens 12 Zeichen lang sein.';
    } elseif (!hash_equals($newPassword, $confirmation)) {
        $error = 'Die beiden neuen Passwörter stimmen nicht überein.';
    } elseif (password_verify($newPassword, (string) $account['password_hash'])) {
        $error = 'Bitte wähle ein neues Passwort, das sich vom aktuellen unterscheidet.';
    } else {
        $q = db()->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ? AND is_active = 1');
        $q->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);
        if ($q->rowCount() !== 1) {
            $error = 'Das Passwort konnte nicht geändert werden. Bitte lade die Seite neu und versuche es erneut.';
        } else {
            $_SESSION['auth_version'] = (int) $account['auth_version'] + 1;
            session_regenerate_id(true);
            $success = 'Dein Passwort wurde geändert. Andere angemeldete Sitzungen wurden beendet.';
        }
    }
}
$body = '<section class="page-intro"><p class="eyebrow">Konto & Sicherheit</p><h1>Passwort ändern</h1><p>Wähle ein persönliches Passwort, das mindestens 12 Zeichen lang ist.</p></section>' .
    ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '') .
    ($success ? '<p class="alert-success" role="status">' . h($success) . '</p>' : '') .
    '<section class="panel account-form"><form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">' .
    '<label>Aktuelles Passwort<input type="password" name="current_password" required autocomplete="current-password"></label>' .
    '<label>Neues Passwort<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>' .
    '<label>Neues Passwort wiederholen<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label>' .
    '<div class="form-actions"><button type="submit">Passwort speichern</button></div></form></section>';
page('Passwort ändern', $body);
