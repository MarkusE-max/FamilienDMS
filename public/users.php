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
$form = '<section class="panel"><p class="eyebrow">Zugang verwalten</p><h2>Familienkonto hinzufügen</h2><p class="panel-subtitle">Lege ein eigenes Konto für jedes Familienmitglied an.</p><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><div class="form-grid"><label>Benutzername<input name="username" required pattern="[A-Za-z0-9._-]{3,80}" autocomplete="off"></label><label>Anzeigename<input name="display_name" required maxlength="120" autocomplete="name"></label><label class="span-2">Startpasswort · mindestens 12 Zeichen<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><label>Rolle<select name="role"><option value="user">Benutzer</option><option value="admin">Administrator</option></select></label><div class="form-actions" style="align-self:end"><button type="submit">Konto anlegen</button></div></div></form></section>';
$rows = '<section class="panel" style="margin-top:18px"><p class="eyebrow">Zugriffsübersicht</p><h2>Familienkonten</h2><p class="panel-subtitle">Konten und Berechtigungen für dieses Archiv.</p>';
if ($users) {
    $rows .= '<div class="table-wrap"><table><thead><tr><th>Anzeigename</th><th>Benutzername</th><th>Rolle</th><th>Status</th><th>Erstellt</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $role = $u['role'] === 'admin' ? 'Administrator' : 'Benutzer';
        $status = (int) $u['is_active'] === 1 ? 'Aktiv' : 'Deaktiviert';
        $created = !empty($u['created_at']) ? date('d.m.Y', strtotime((string) $u['created_at'])) : '—';
        $rows .= '<tr><td class="user-name">' . h($u['display_name']) . '</td><td>' . h($u['username']) . '</td><td><span class="role-badge' . ($u['role'] === 'admin' ? '' : ' user') . '">' . h($role) . '</span></td><td>' . h($status) . '</td><td>' . h($created) . '</td></tr>';
    }
    $rows .= '</tbody></table></div>';
} else {
    $rows .= '<div class="empty-state">Noch keine Konten vorhanden.</div>';
}
$rows .= '</section>';
$body = '<section class="page-intro"><p class="eyebrow">Familie &amp; Zugriff</p><h1>Benutzerverwaltung</h1><p>Verwalte, wer auf das gemeinsame Dokumentenarchiv zugreifen kann.</p></section>' . ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '') . $form . $rows;
page('Benutzerverwaltung', $body);
