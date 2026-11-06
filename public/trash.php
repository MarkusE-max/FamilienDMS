<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/document_storage.php';
$user = require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($documentId === false || !in_array($action, ['restore', 'purge'], true)) {
        $error = 'Die Anfrage konnte nicht verarbeitet werden.';
    } elseif ($action === 'purge' && ($_POST['confirm_purge'] ?? '') !== '1') {
        $error = 'Bitte bestätige das endgültige Löschen ausdrücklich.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id, storage_name, uploaded_by FROM documents WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE');
            $q->execute([$documentId]);
            $document = $q->fetch();
            if (!$document) {
                $pdo->rollBack();
                $error = 'Das Dokument wurde im Papierkorb nicht gefunden.';
            } elseif ($action === 'restore' && $user['role'] !== 'admin' && (int) $document['uploaded_by'] !== (int) $user['id']) {
                $pdo->rollBack();
                http_response_code(403);
                $error = 'Du kannst nur eigene Dokumente wiederherstellen.';
            } elseif ($action === 'purge' && $user['role'] !== 'admin') {
                $pdo->rollBack();
                http_response_code(403);
                $error = 'Nur Administratoren können Dokumente endgültig löschen.';
            } elseif ($action === 'restore') {
                $pdo->prepare('UPDATE documents SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$documentId]);
                record_audit_event((int) $user['id'], 'document_restored', (int) $document['uploaded_by'], ['document_id' => (int) $documentId]);
                $pdo->commit();
                header('Location: ' . app_url('trash.php?restored=1'));
                exit;
            } else {
                $path = document_storage_file(app_config(), (string) $document['storage_name']);
                record_audit_event((int) $user['id'], 'document_permanently_deleted', (int) $document['uploaded_by'], ['document_id' => (int) $documentId]);
                $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$documentId]);
                $pdo->commit();
                if ($path !== null && !@unlink($path)) error_log('[FamilienDMS] A permanently deleted document file could not be removed from private storage.');
                header('Location: ' . app_url('trash.php?purged=1'));
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[FamilienDMS] Trash operation failed: ' . get_class($exception));
            $error = 'Die Änderung im Papierkorb konnte nicht gespeichert werden.';
        }
    }
}
$perPage = 50;
$currentPage = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
$filter = $user['role'] === 'admin' ? '' : ' AND d.uploaded_by = ?';
$count = db()->prepare('SELECT COUNT(*) FROM documents d WHERE d.deleted_at IS NOT NULL' . $filter);
$count->execute($user['role'] === 'admin' ? [] : [(int) $user['id']]);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$currentPage = min($currentPage, $pages);
$statement = db()->prepare('SELECT d.id, d.title, d.mime_type, d.size_bytes, d.uploaded_by, d.deleted_at, u.display_name FROM documents d JOIN users u ON u.id = d.uploaded_by WHERE d.deleted_at IS NOT NULL' . $filter . ' ORDER BY d.deleted_at DESC, d.id DESC LIMIT ? OFFSET ?');
$parameter = 1;
if ($user['role'] !== 'admin') $statement->bindValue($parameter++, (int) $user['id'], PDO::PARAM_INT);
$statement->bindValue($parameter++, $perPage, PDO::PARAM_INT);
$statement->bindValue($parameter, ($currentPage - 1) * $perPage, PDO::PARAM_INT);
$statement->execute();
$documents = $statement->fetchAll();
$body = '<section class="page-intro"><p class="eyebrow">Wiederherstellen</p><h1>Papierkorb</h1><p>Entfernte Dokumente bleiben hier gespeichert. Du kannst eigene Dokumente wiederherstellen; endgültiges Löschen ist Administratoren vorbehalten.</p></section>';
if (isset($_GET['restored'])) $body .= '<p class="alert-success" role="status">Dokument wurde wiederhergestellt.</p>';
if (isset($_GET['purged'])) $body .= '<p class="alert-success" role="status">Dokument wurde endgültig gelöscht.</p>';
if ($error !== '') $body .= '<p class="alert-error" role="alert">' . h($error) . '</p>';
$body .= '<section class="panel"><p class="panel-subtitle">' . $total . ($total === 1 ? ' Dokument im Papierkorb' : ' Dokumente im Papierkorb') . '</p>';
if (!$documents) {
    $body .= '<div class="empty-state">Der Papierkorb ist leer.</div>';
} else {
    $body .= '<div class="table-wrap"><table><thead><tr><th>Dokument</th><th>Hinzugefügt von</th><th>Entfernt am</th><th>Aktionen</th></tr></thead><tbody>';
    foreach ($documents as $document) {
        $id = (int) $document['id'];
        $date = date('d.m.Y · H:i', strtotime((string) $document['deleted_at']));
        $body .= '<tr><td>' . h((string) $document['title']) . '<span class="user-secondary">' . h($document['mime_type'] === 'application/pdf' ? 'PDF' : 'Bild') . ' · ' . (int) $document['size_bytes'] . ' Byte</span></td><td>' . h((string) $document['display_name']) . '</td><td>' . h($date) . '</td><td><div class="user-actions"><form class="inline-form" method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="restore"><input type="hidden" name="document_id" value="' . $id . '"><button type="submit">Wiederherstellen</button></form>';
        if ($user['role'] === 'admin') $body .= '<form class="inline-form purge-form" method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="purge"><input type="hidden" name="document_id" value="' . $id . '"><label><input type="checkbox" name="confirm_purge" value="1" required> Endgültiges Löschen bestätigen</label><button class="button-danger" type="submit">Endgültig löschen</button></form>';
        $body .= '</div></td></tr>';
    }
    $body .= '</tbody></table></div>';
    if ($pages > 1) {
        $body .= '<nav class="pagination" aria-label="Papierkorbseiten">';
        if ($currentPage > 1) $body .= '<a class="button-secondary" href="' . h(app_url('trash.php?page=' . ($currentPage - 1))) . '">Zurück</a>';
        $body .= '<span>Seite ' . $currentPage . ' von ' . $pages . '</span>';
        if ($currentPage < $pages) $body .= '<a class="button-secondary" href="' . h(app_url('trash.php?page=' . ($currentPage + 1))) . '">Weiter</a>';
        $body .= '</nav>';
    }
}
$body .= '<p class="form-hint">Endgültig gelöschte Dokumente und ihre privaten Dateien können nicht wiederhergestellt werden.</p></section>';
page('Papierkorb', $body);
