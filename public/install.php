<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$allowInsecureInstall = false;
try { $allowInsecureInstall = (app_config()['allow_insecure_install'] ?? false) === true; }
catch (Throwable) { $allowInsecureInstall = false; }
$httpsDetected = is_https_request();
$httpsLabel = $httpsDetected ? 'HTTPS' : 'HTTPS (Entwicklungsfreigabe)';
$checks = ['PHP 8.2+' => PHP_VERSION_ID >= 80200, 'PDO MySQL' => extension_loaded('pdo_mysql'), 'Fileinfo' => extension_loaded('fileinfo'), 'mbstring' => extension_loaded('mbstring'), $httpsLabel => ($httpsDetected || $allowInsecureInstall)];
$error = '';
$dbConnected = false;
$schemaReady = false;
$usersExist = false;
try {
    $pdo = db();
    $dbConnected = true;
    $usersExist = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    $pdo->query('SELECT id FROM documents LIMIT 0');
    $schemaReady = true;
} catch (Throwable $e) {
    $error = $dbConnected
        ? 'Datenbankverbindung steht, aber die Tabellen fehlen. Bitte database/migrations/001_initial.sql importieren.'
        : 'Datenbankverbindung fehlgeschlagen. Bitte config/config.php sowie MariaDB-Host, Port 3308, Datenbankname und App-Benutzer prüfen.';
}
$checks['Datenbankverbindung'] = $dbConnected;
$checks['Datenbankschema (users- und documents-Tabelle)'] = $schemaReady;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (in_array(false, $checks, true) || $usersExist) $error = $usersExist ? 'Ein Benutzerkonto existiert bereits; die Ersteinrichtung ist gesperrt.' : 'Bitte zuerst die oben als FEHLT markierten Voraussetzungen beheben.';
    else {
        $username = trim((string) ($_POST['username'] ?? '')); $display = trim((string) ($_POST['display_name'] ?? '')); $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || $display === '' || mb_strlen($display) > 120 || strlen($password) < 12) $error = 'Angaben ungültig; Passwort muss mindestens 12 Zeichen haben.';
        else { $q = db()->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, \'admin\')'); $q->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT)]); header('Location: ' . app_url('login.php')); exit; }
    }
}
$items = '<ul class="install-checks">'; foreach ($checks as $label => $ok) $items .= '<li><span>' . h($label) . '</span><strong class="' . ($ok ? 'check-ok' : 'check-missing') . '">' . ($ok ? 'Bereit' : 'Fehlt') . '</strong></li>'; $items .= '</ul>';
$form = (!$usersExist && $schemaReady && !in_array(false, $checks, true)) ? '<section class="panel install-form"><p class="eyebrow">Letzter Schritt</p><h2>Ersten Administrator erstellen</h2><p class="panel-subtitle">Dieses Konto verwaltet später die Familienzugänge.</p><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><label>Benutzername<input name="username" required autocomplete="username"></label><label>Anzeigename<input name="display_name" required autocomplete="name"></label><label>Passwort · mindestens 12 Zeichen<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button type="submit">Administrator erstellen</button></form></section>' : '';
$note = '<div class="install-note">Nach der Einrichtung bitte den Zugriff auf diese Installationsseite in Web Station sperren oder die Datei entfernen.</div>';
$body = '<section class="page-intro"><p class="eyebrow">Systemstatus</p><h1>Installationsprüfung</h1><p>Diese Übersicht prüft die wichtigsten Voraussetzungen für FamilienDMS.</p></section>' . $note . $items . ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '') . $form;
page('Installationsprüfung', $body);
