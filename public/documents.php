<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/upload.php';
require __DIR__ . '/../app/document_storage.php';

$user = require_login();
$error = '';
$titleValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'upload') {
        $titleValue = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        if ($titleValue === '' || mb_strlen($titleValue) > 200) {
            $error = 'Bitte einen Titel mit höchstens 200 Zeichen eingeben.';
        } else {
            $stored = null;
            try {
                $file = $_FILES['document'] ?? [];
                if (!is_array($file)) throw new RuntimeException('Bitte eine Datei auswählen.');
                $stored = store_document_upload($file, app_config());
                $statement = db()->prepare('INSERT INTO documents (title, storage_name, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?)');
                $statement->execute([$titleValue, $stored['storage_name'], $stored['mime_type'], $stored['size_bytes'], (int) $user['id']]);
                header('Location: ' . app_url('documents.php?uploaded=1'));
                exit;
            } catch (Throwable $exception) {
                if (is_array($stored) && isset($stored['storage_name'])) {
                    $path = document_storage_file(app_config(), (string) $stored['storage_name']);
                    if ($path !== null) @unlink($path);
                }
                if ($exception instanceof RuntimeException) $error = $exception->getMessage();
                else {
                    error_log('[FamilienDMS] Document upload failed: ' . get_class($exception));
                    $error = 'Das Dokument konnte nicht gespeichert werden. Bitte Speicherplatz und Datenbankprotokoll prüfen.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($documentId === false) {
            $error = 'Das Dokument wurde nicht gefunden.';
        } else {
            $statement = db()->prepare('SELECT id, storage_name, uploaded_by FROM documents WHERE id = ?');
            $statement->execute([$documentId]);
            $document = $statement->fetch();
            if (!$document) {
                $error = 'Das Dokument wurde nicht gefunden.';
            } elseif ($user['role'] !== 'admin' && (int) $document['uploaded_by'] !== (int) $user['id']) {
                http_response_code(403);
                $error = 'Du kannst nur eigene Dokumente entfernen.';
            } else {
                $path = document_storage_file(app_config(), (string) $document['storage_name']);
                $statement = db()->prepare('DELETE FROM documents WHERE id = ?');
                $statement->execute([$documentId]);
                if ($path !== null && !@unlink($path)) error_log('[FamilienDMS] A deleted document file could not be removed from private storage.');
                header('Location: ' . app_url('documents.php?deleted=1'));
                exit;
            }
        }
    } else {
        http_response_code(400);
        $error = 'Die Anfrage konnte nicht verarbeitet werden.';
    }
}

$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($query) > 100) $query = mb_substr($query, 0, 100);
$sql = 'SELECT d.id, d.title, d.mime_type, d.size_bytes, d.uploaded_by, d.created_at, u.display_name FROM documents d JOIN users u ON u.id = d.uploaded_by';
$parameters = [];
if ($query !== '') {
    $sql .= " WHERE d.title LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!'";
    $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $parameters = [$like, $like];
}
$sql .= ' ORDER BY d.created_at DESC, d.id DESC LIMIT 100';
$statement = db()->prepare($sql);
$statement->execute($parameters);
$documents = $statement->fetchAll();
$countSql = 'SELECT COUNT(*) FROM documents d JOIN users u ON u.id = d.uploaded_by';
if ($query !== '') $countSql .= " WHERE d.title LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!'";
$countStatement = db()->prepare($countSql);
$countStatement->execute($parameters);
$matchingCount = (int) $countStatement->fetchColumn();

$formatSize = static function (int $bytes): string {
    if ($bytes >= 1_000_000) return number_format($bytes / 1_000_000, 1, ',', '.') . ' MB';
    if ($bytes >= 1_000) return number_format($bytes / 1_000, 0, ',', '.') . ' KB';
    return $bytes . ' B';
};
$rows = '';
foreach ($documents as $document) {
    $icon = $document['mime_type'] === 'application/pdf' ? 'PDF' : 'IMG';
    $canDelete = $user['role'] === 'admin' || (int) $document['uploaded_by'] === (int) $user['id'];
    $created = date('d.m.Y · H:i', strtotime((string) $document['created_at']));
    $rows .= '<tr><td><span class="file-type" aria-hidden="true">' . $icon . '</span></td><td><a class="document-title" href="' . h(app_url('download.php?id=' . (int) $document['id'])) . '">' . h($document['title']) . '</a><span class="user-secondary">' . h($document['display_name']) . '</span></td><td>' . h($formatSize((int) $document['size_bytes'])) . '</td><td>' . h($created) . '</td><td>';
    if ($document['mime_type'] === 'application/pdf') {
        $rows .= '<a class="preview-link" href="' . h(app_url('preview.php?id=' . (int) $document['id'])) . '">Vorschau</a>';
    }
    if ($canDelete) {
        $rows .= '<form class="inline-form" method="post" onsubmit="return confirm(\'Dieses Dokument wirklich entfernen?\')"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="document_id" value="' . (int) $document['id'] . '"><button class="button-danger" type="submit" aria-label="' . h('Dokument ' . $document['title'] . ' entfernen') . '">Entfernen</button></form>';
    }
    $rows .= '</td></tr>';
}
if ($documents) {
    $documentRows = '<div class="table-wrap"><table><thead><tr><th scope="col">Typ</th><th scope="col">Dokument</th><th scope="col">Größe</th><th scope="col">Hinzugefügt</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
} else {
    $documentRows = '<div class="empty-state"><span class="empty-icon" aria-hidden="true">▤</span><strong>' . ($query === '' ? 'Euer Archiv ist noch leer.' : 'Keine passenden Dokumente gefunden.') . '</strong><p>' . ($query === '' ? 'Lade das erste PDF oder Bild hoch, um es hier abzulegen.' : 'Versuche einen anderen Suchbegriff.') . '</p></div>';
}

$notice = '';
if (isset($_GET['uploaded'])) $notice = '<p class="alert-success" role="status">Dokument wurde sicher gespeichert.</p>';
elseif (isset($_GET['deleted'])) $notice = '<p class="alert-success" role="status">Dokument wurde entfernt.</p>';
$body = '<section class="page-intro"><p class="eyebrow">Gemeinsame Ablage</p><h1>Dokumentenarchiv</h1><p>PDF-Dateien und Bilder sicher ablegen, wiederfinden und herunterladen.</p></section>';
$body .= $notice . ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '');
$body .= '<section class="document-layout"><div class="panel upload-panel"><p class="eyebrow">Neue Ablage</p><h2>Dokument hinzufügen</h2><p class="panel-subtitle">PDF, JPEG oder PNG · maximal 20 MB</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="upload"><label>Bezeichnung<input name="title" required maxlength="200" value="' . h($titleValue) . '" placeholder="Zum Beispiel: Versicherung Haus"></label><label>Datei auswählen<input type="file" name="document" accept="application/pdf,image/jpeg,image/png" required></label><button type="submit">Sicher hochladen</button></form><p class="privacy-note"><span aria-hidden="true">●</span> Dateien werden privat gespeichert und nur angemeldet bereitgestellt.</p></div><div class="panel archive-list"><div class="list-heading"><div><p class="eyebrow">Familienarchiv</p><h2>Gespeicherte Dokumente</h2></div><span class="count-pill">' . $matchingCount . ($matchingCount === 1 ? ' Eintrag' : ' Einträge') . '</span></div><form class="search-form" method="get"><label class="visually-hidden" for="document-search">Dokumente durchsuchen</label><input id="document-search" name="q" type="search" value="' . h($query) . '" maxlength="100" placeholder="Titel oder Person suchen"><button type="submit">Suchen</button></form>' . $documentRows . ($matchingCount > 100 ? '<p class="list-footnote">Es werden die ersten 100 von ' . $matchingCount . ' Treffern angezeigt. Suche nach einem Titel, um die Liste einzugrenzen.</p>' : '') . '</div></section>';
page('Dokumentenarchiv', $body);
