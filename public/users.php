<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
start_secure_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $display = trim((string) ($_POST['display_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
            if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || mb_strlen($display) < 1 || mb_strlen($display) > 120 || strlen($password) < 12) throw new RuntimeException('Bitte gültige Angaben verwenden (Passwort mindestens 12 Zeichen).');
            $q = db()->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)');
            $q->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT), $role]);
            $_SESSION['user_flash'] = 'Das Konto wurde angelegt.';
        } elseif ($action === 'reset_password') {
            $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $password = (string) ($_POST['new_password'] ?? '');
            if (!$id || strlen($password) < 12) throw new RuntimeException('Das neue Passwort muss mindestens 12 Zeichen lang sein.');
            $q = db()->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1, password_reset_requested_at = NULL WHERE id = ? AND is_active = 1');
            $q->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            if ($q->rowCount() !== 1) throw new RuntimeException('Das aktive Benutzerkonto wurde nicht gefunden.');
            if ((int) $id === (int) $admin['id']) $_SESSION['auth_version'] = (int) $_SESSION['auth_version'] + 1;
            $_SESSION['user_flash'] = 'Passwort geändert. Teile das neue Passwort persönlich und sicher mit dem Benutzer.';
        } elseif ($action === 'change_role') {
            $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $role = (string) ($_POST['role'] ?? '');
            if (!$id || !in_array($role, ['admin', 'user'], true)) throw new RuntimeException('Bitte eine gültige Rolle auswählen.');
            $targetQ = db()->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
            $targetQ->execute([$id]); $target = $targetQ->fetch();
            if (!$target) throw new RuntimeException('Benutzerkonto wurde nicht gefunden.');
            if ($target['role'] === 'admin' && $role === 'user' && (int) $target['is_active'] === 1) {
                $count = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                if ($count <= 1) throw new RuntimeException('Die Rolle des letzten aktiven Administrators kann nicht geändert werden.');
            }
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            $_SESSION['user_flash'] = 'Rolle geändert. Die Berechtigung gilt ab dem nächsten Seitenaufruf.';
        } elseif ($action === 'toggle_active') {
            $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $targetQ = db()->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
            $targetQ->execute([$id]); $target = $targetQ->fetch();
            if (!$target) throw new RuntimeException('Benutzerkonto wurde nicht gefunden.');
            $deactivate = (int) $target['is_active'] === 1;
            if ($deactivate && (int) $id === (int) $admin['id']) throw new RuntimeException('Du kannst dein eigenes Konto nicht deaktivieren.');
            if ($deactivate && $target['role'] === 'admin') {
                $count = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                if ($count <= 1) throw new RuntimeException('Der letzte aktive Administrator kann nicht deaktiviert werden.');
            }
            $q = db()->prepare('UPDATE users SET is_active = ?, auth_version = auth_version + 1, password_reset_requested_at = NULL WHERE id = ?');
            $q->execute([$deactivate ? 0 : 1, $id]);
            $_SESSION['user_flash'] = $deactivate ? 'Benutzerkonto deaktiviert. Bestehende Sitzungen werden beim nächsten Seitenaufruf beendet.' : 'Benutzerkonto wieder aktiviert.';
        } else { throw new RuntimeException('Unbekannte Aktion.'); }
    } catch (PDOException $e) {
        $_SESSION['user_flash_error'] = $e->getCode() === '23000' ? 'Dieser Benutzername ist bereits vergeben.' : 'Die Änderung konnte nicht gespeichert werden.';
    } catch (RuntimeException $e) { $_SESSION['user_flash_error'] = $e->getMessage(); }
    header('Location: ' . app_url('users.php')); exit;
}
$notice = (string) ($_SESSION['user_flash'] ?? ''); unset($_SESSION['user_flash']);
$error = (string) ($_SESSION['user_flash_error'] ?? ''); unset($_SESSION['user_flash_error']);
$users = db()->query('SELECT id, username, display_name, role, is_active, password_reset_requested_at, created_at FROM users ORDER BY username')->fetchAll();
$form = '<section class="panel"><p class="eyebrow">Zugang verwalten</p><h2>Familienkonto hinzufügen</h2><p class="panel-subtitle">Lege ein eigenes Konto für jedes Familienmitglied an.</p><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="create"><div class="form-grid"><label>Benutzername<input name="username" required pattern="[A-Za-z0-9._-]{3,80}" autocomplete="off"></label><label>Anzeigename<input name="display_name" required maxlength="120" autocomplete="name"></label><label class="span-2">Startpasswort · mindestens 12 Zeichen<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><label>Rolle<select name="role"><option value="user">Benutzer</option><option value="admin">Administrator</option></select></label><div class="form-actions" style="align-self:end"><button type="submit">Konto anlegen</button></div></div></form></section>';
$rows = '<section class="panel" style="margin-top:18px"><p class="eyebrow">Zugriffsübersicht</p><h2>Familienkonten</h2><p class="panel-subtitle">Passwörter werden nur als Hash gespeichert. Gib ein neu gesetztes Passwort außerhalb der Anwendung weiter.</p>';
if ($users) {
    $rows .= '<div class="table-wrap"><table><thead><tr><th>Anzeigename</th><th>Benutzername</th><th>Rolle</th><th>Status / Rücksetzung</th><th>Aktionen</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $role = $u['role'] === 'admin' ? 'Administrator' : 'Benutzer';
        $active = (int) $u['is_active'] === 1;
        $status = $active ? 'Aktiv' : 'Deaktiviert';
        if ($active && $u['password_reset_requested_at']) $status .= ' · Rücksetzung angefragt';
        $rows .= '<tr><td class="user-name">' . h($u['display_name']) . '</td><td>' . h($u['username']) . '</td><td><span class="role-badge' . ($u['role'] === 'admin' ? '' : ' user') . '">' . h($role) . '</span><form method="post" class="inline-form role-form"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="change_role"><input type="hidden" name="user_id" value="' . (int) $u['id'] . '"><label class="sr-only" for="role' . (int) $u['id'] . '">Rolle für ' . h($u['display_name']) . '</label><select id="role' . (int) $u['id'] . '" name="role"><option value="user"' . ($u['role'] === 'user' ? ' selected' : '') . '>Benutzer</option><option value="admin"' . ($u['role'] === 'admin' ? ' selected' : '') . '>Administrator</option></select><button type="submit" class="button-secondary">Rolle speichern</button></form></td><td>' . h($status) . '</td><td><div class="user-actions">';
        if ($active) {
            $rows .= '<form method="post" class="inline-form"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="' . (int) $u['id'] . '"><label class="sr-only" for="pw' . (int) $u['id'] . '">Neues Passwort für ' . h($u['display_name']) . '</label><input id="pw' . (int) $u['id'] . '" type="password" name="new_password" required minlength="12" autocomplete="new-password" placeholder="Neues Passwort (12+)"><button type="submit">Passwort ändern</button></form>';
        }
        if ((int) $u['id'] !== (int) $admin['id']) {
            $rows .= '<form method="post" class="inline-form"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="' . (int) $u['id'] . '"><button type="submit" class="button-secondary">' . ($active ? 'Deaktivieren' : 'Wieder aktivieren') . '</button></form>';
        }
        $rows .= '</div></td></tr>';
    }
    $rows .= '</tbody></table></div>';
} else { $rows .= '<div class="empty-state">Noch keine Konten vorhanden.</div>'; }
$rows .= '</section>';
$body = '<section class="page-intro"><p class="eyebrow">Familie &amp; Zugriff</p><h1>Benutzerverwaltung</h1><p>Verwalte Konten, Passwörter und Zugriffsstatus für das gemeinsame Dokumentenarchiv.</p></section>' . ($notice ? '<p class="alert-success" role="status">' . h($notice) . '</p>' : '') . ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '') . $form . $rows;
page('Benutzerverwaltung', $body);
