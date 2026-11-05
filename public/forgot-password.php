<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
start_secure_session();
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    if ($username !== '' && mb_strlen($username) <= 80) {
        $q = db()->prepare("UPDATE users SET password_reset_requested_at = CURRENT_TIMESTAMP WHERE username = ? AND is_active = 1 AND (password_reset_requested_at IS NULL OR password_reset_requested_at < CURRENT_TIMESTAMP - INTERVAL 15 MINUTE)");
        $q->execute([$username]);
    }
    $sent = true;
}
$body = '<section class="auth-layout"><div class="auth-aside"><div><p class="eyebrow" style="color:#b7cbbd">FamilienDMS · privat</p><h2>Wir helfen dir wieder hinein.</h2><p>Ein Administrator kann den Zugang sicher zurücksetzen.</p></div><span class="auth-quote">Gemeinsam verwaltet. Geschützt abgelegt.</span></div><div class="auth-form-wrap"><p class="eyebrow">Kontozugang</p><h1>Passwort vergessen?</h1>' . ($sent ? '<p class="alert-success" role="status">Falls das aktive Konto existiert, ist die Rücksetzungsanfrage hinterlegt. Bitte informiere einen Administrator, damit er ein neues Passwort setzen kann.</p>' : '<p>Gib deinen Benutzernamen ein. Die Anfrage wird in der Benutzerverwaltung für einen Administrator sichtbar.</p><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><label>Benutzername<input name="username" required maxlength="80" autocomplete="username"></label><div class="form-actions"><button type="submit">Rücksetzung anfragen</button></div></form>') . '<p><a href="' . h(app_url('login.php')) . '">Zur Anmeldung</a></p></div></section>';
page('Passwort vergessen', $body);
