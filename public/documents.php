<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/upload.php';
require __DIR__ . '/../app/document_storage.php';

$user = require_login();
$error = '';
$titleValue = '';
$submittedCategoryIds = [];
$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$categoryIds = array_map(static fn(array $category): int => (int) $category['id'], $categories);
$parseCategoryIds = static function (mixed $raw) use ($categoryIds): array|false {
    if ($raw === null || $raw === '') return [];
    if (!is_array($raw) || count($raw) > 30) return false;
    $ids = [];
    foreach ($raw as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !in_array((int) $id, $categoryIds, true)) return false;
        $ids[] = (int) $id;
    }
    return array_values(array_unique($ids));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'upload') {
        $titleValue = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        $submittedCategoryIds = $parseCategoryIds($_POST['category_ids'] ?? null);
        if ($submittedCategoryIds === false) {
            $submittedCategoryIds = [];
            $error = 'Bitte nur gültige Kategorien auswählen.';
        }
        if ($error === '' && ($titleValue === '' || mb_strlen($titleValue) > 200)) {
            $error = 'Bitte einen Titel mit höchstens 200 Zeichen eingeben.';
        }
        if ($error === '') {
            $stored = null;
            try {
                $file = $_FILES['document'] ?? [];
                if (!is_array($file)) throw new RuntimeException('Bitte eine Datei auswählen.');
                $stored = store_document_upload($file, app_config());
                $pdo = db();
                $pdo->beginTransaction();
                $statement = $pdo->prepare('INSERT INTO documents (title, storage_name, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?)');
                $statement->execute([$titleValue, $stored['storage_name'], $stored['mime_type'], $stored['size_bytes'], (int) $user['id']]);
                $documentId = (int) $pdo->lastInsertId();
                $tagStatement = $pdo->prepare('INSERT INTO document_categories (document_id, category_id) VALUES (?, ?)');
                foreach ($submittedCategoryIds as $tagId) $tagStatement->execute([$documentId, $tagId]);
                $pdo->commit();
                header('Location: ' . app_url('documents.php?uploaded=1'));
                exit;
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
                if (is_array($stored) && isset($stored['storage_name'])) {
                    $path = document_storage_file(app_config(), (string) $stored['storage_name']);
                    if ($path !== null) @unlink($path);
                }
                if ($exception instanceof RuntimeException && !($exception instanceof PDOException)) $error = $exception->getMessage();
                else {
                    error_log('[FamilienDMS] Document upload failed: ' . get_class($exception));
                    $error = 'Das Dokument konnte nicht gespeichert werden. Bitte Speicherplatz und Datenbankprotokoll prüfen.';
                }
            }
        }
    } elseif ($action === 'set_categories') {
        $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $newCategoryIds = $parseCategoryIds($_POST['category_ids'] ?? null);
        if ($documentId === false || $newCategoryIds === false) {
            $error = 'Bitte eine gültige Dokumenten- und Kategorieauswahl verwenden.';
        } else {
            $statement = db()->prepare('SELECT uploaded_by FROM documents WHERE id = ?');
            $statement->execute([$documentId]);
            $documentOwner = $statement->fetchColumn();
            if ($documentOwner === false) {
                $error = 'Das Dokument wurde nicht gefunden.';
            } elseif ($user['role'] !== 'admin' && (int) $documentOwner !== (int) $user['id']) {
                http_response_code(403);
                $error = 'Du kannst nur eigene Dokumente kategorisieren.';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $statement = $pdo->prepare('DELETE FROM document_categories WHERE document_id = ?');
                    $statement->execute([$documentId]);
                    $statement = $pdo->prepare('INSERT INTO document_categories (document_id, category_id) VALUES (?, ?)');
                    foreach ($newCategoryIds as $tagId) $statement->execute([$documentId, $tagId]);
                    $pdo->commit();
                    header('Location: ' . app_url('documents.php?updated=1'));
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
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
$selectedCategory = filter_var($_GET['category'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($selectedCategory === false || ($selectedCategory !== null && !in_array((int) $selectedCategory, $categoryIds, true))) $selectedCategory = null;
$where = [];
$parameters = [];
if ($query !== '') {
    $where[] = "(d.title LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM document_categories search_dc JOIN categories search_c ON search_c.id = search_dc.category_id WHERE search_dc.document_id = d.id AND search_c.name LIKE ? ESCAPE '!'))";
    $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $tagQuery = ltrim($query, '#');
    $tagLike = '%' . strtr($tagQuery, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    array_push($parameters, $like, $like, $tagLike);
}
if ($selectedCategory !== null) {
    $where[] = 'EXISTS (SELECT 1 FROM document_categories filter_dc WHERE filter_dc.document_id = d.id AND filter_dc.category_id = ?)';
    $parameters[] = (int) $selectedCategory;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$sql = 'SELECT d.id, d.title, d.mime_type, d.size_bytes, d.uploaded_by, d.created_at, u.display_name FROM documents d JOIN users u ON u.id = d.uploaded_by' . $whereSql . ' ORDER BY d.created_at DESC, d.id DESC LIMIT 100';
$statement = db()->prepare($sql);
$statement->execute($parameters);
$documents = $statement->fetchAll();
$countStatement = db()->prepare('SELECT COUNT(*) FROM documents d JOIN users u ON u.id = d.uploaded_by' . $whereSql);
$countStatement->execute($parameters);
$matchingCount = (int) $countStatement->fetchColumn();

$documentTags = [];
if ($documents) {
    $documentIds = array_map(static fn(array $document): int => (int) $document['id'], $documents);
    $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
    $tagStatement = db()->prepare('SELECT dc.document_id, c.id, c.name FROM document_categories dc JOIN categories c ON c.id = dc.category_id WHERE dc.document_id IN (' . $placeholders . ') ORDER BY c.name');
    $tagStatement->execute($documentIds);
    foreach ($tagStatement->fetchAll() as $tag) $documentTags[(int) $tag['document_id']][] = ['id' => (int) $tag['id'], 'name' => (string) $tag['name']];
}

$formatSize = static function (int $bytes): string {
    if ($bytes >= 1_000_000) return number_format($bytes / 1_000_000, 1, ',', '.') . ' MB';
    if ($bytes >= 1_000) return number_format($bytes / 1_000, 0, ',', '.') . ' KB';
    return $bytes . ' B';
};
$rows = '';
foreach ($documents as $document) {
    $id = (int) $document['id'];
    $icon = $document['mime_type'] === 'application/pdf' ? 'PDF' : 'IMG';
    $canDelete = $user['role'] === 'admin' || (int) $document['uploaded_by'] === (int) $user['id'];
    $canCategorize = $canDelete;
    $created = date('d.m.Y · H:i', strtotime((string) $document['created_at']));
    $tags = $documentTags[$id] ?? [];
    $tagView = $tags ? '' : '<span class="category-badge category-empty">Ohne Tag</span>';
    foreach ($tags as $tag) $tagView .= '<span class="category-badge">#' . h(ltrim($tag['name'], '#')) . '</span>';
    $rows .= '<tr><td><span class="file-type" aria-hidden="true">' . $icon . '</span></td><td><a class="document-title" href="' . h(app_url('download.php?id=' . $id)) . '">' . h($document['title']) . '</a><span class="user-secondary">' . h($document['display_name']) . '</span></td><td><div class="document-tags">' . $tagView . '</div>';
    if ($canCategorize) {
        $tagCheckboxes = '';
        $selectedTagIds = array_column($tags, 'id');
        foreach ($categories as $category) {
            $tagId = (int) $category['id'];
            $checked = in_array($tagId, $selectedTagIds, true) ? ' checked' : '';
            $tagCheckboxes .= '<label class="tag-choice"><input type="checkbox" name="category_ids[]" value="' . $tagId . '"' . $checked . '><span>#' . h(ltrim((string) $category['name'], '#')) . '</span></label>';
        }
        if ($tagCheckboxes !== '') $rows .= '<details class="document-tag-editor"><summary>Tags ändern</summary><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="set_categories"><input type="hidden" name="document_id" value="' . $id . '"><div class="tag-choice-list">' . $tagCheckboxes . '</div><button type="submit">Speichern</button></form></details>';
    }
    $rows .= '</td><td>' . h($formatSize((int) $document['size_bytes'])) . '</td><td>' . h($created) . '</td><td>';
    if (in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)) $rows .= '<a class="preview-link" href="' . h(app_url('preview.php?id=' . $id)) . '">Vorschau</a>';
    if ($canDelete) $rows .= '<form class="inline-form" method="post" onsubmit="return confirm(\'Dieses Dokument wirklich entfernen?\')"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="document_id" value="' . $id . '"><button class="button-danger" type="submit" aria-label="' . h('Dokument ' . $document['title'] . ' entfernen') . '">Entfernen</button></form>';
    $rows .= '</td></tr>';
}
if ($documents) {
    $documentRows = '<div class="table-wrap"><table><thead><tr><th scope="col">Typ</th><th scope="col">Dokument</th><th scope="col">Tags</th><th scope="col">Größe</th><th scope="col">Hinzugefügt</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
} else {
    $documentRows = '<div class="empty-state"><span class="empty-icon" aria-hidden="true">▤</span><strong>' . ($query === '' && $selectedCategory === null ? 'Euer Archiv ist noch leer.' : 'Keine passenden Dokumente gefunden.') . '</strong><p>' . ($query === '' && $selectedCategory === null ? 'Lade das erste PDF oder Bild hoch, um es hier abzulegen.' : 'Passe den Suchbegriff oder den Tag-Filter an.') . '</p></div>';
}

$uploadTags = '';
foreach ($categories as $category) {
    $tagId = (int) $category['id'];
    $checked = in_array($tagId, $submittedCategoryIds, true) ? ' checked' : '';
    $uploadTags .= '<label class="tag-choice"><input type="checkbox" name="category_ids[]" value="' . $tagId . '"' . $checked . '><span>#' . h(ltrim((string) $category['name'], '#')) . '</span></label>';
}
$tagFilterOptions = '<option value="">Alle Tags</option>';
foreach ($categories as $category) {
    $id = (int) $category['id'];
    $tagFilterOptions .= '<option value="' . $id . '"' . ($selectedCategory === $id ? ' selected' : '') . '>#' . h(ltrim((string) $category['name'], '#')) . '</option>';
}
$notice = '';
if (isset($_GET['uploaded'])) $notice = '<p class="alert-success" role="status">Dokument wurde sicher gespeichert.</p>';
elseif (isset($_GET['deleted'])) $notice = '<p class="alert-success" role="status">Dokument wurde entfernt.</p>';
elseif (isset($_GET['updated'])) $notice = '<p class="alert-success" role="status">Dokument-Tags wurden gespeichert.</p>';
$body = '<section class="page-intro"><p class="eyebrow">Gemeinsame Ablage</p><h1>Dokumentenarchiv</h1><p>Dokumente sicher ablegen und mit mehreren Tags wie #Haus oder #Steuer wiederfinden.</p></section>';
$body .= $notice . ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '');
$body .= '<section class="document-layout"><div class="panel upload-panel"><p class="eyebrow">Neue Ablage</p><h2>Dokument hinzufügen</h2><p class="panel-subtitle">PDF, JPEG oder PNG · maximal 20 MB</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="upload"><label>Bezeichnung<input name="title" required maxlength="200" value="' . h($titleValue) . '" placeholder="Zum Beispiel: Versicherung Haus"></label><fieldset class="tag-fieldset"><legend>Tags auswählen · bis zu 30</legend>' . ($uploadTags !== '' ? '<div class="tag-choice-list">' . $uploadTags . '</div>' : '<p class="form-hint">Noch keine Tags angelegt. Ein Administrator kann diese unter Kategorien erstellen.</p>') . '</fieldset><label>Datei auswählen<input type="file" name="document" accept="application/pdf,image/jpeg,image/png" required></label><button type="submit">Sicher hochladen</button></form><p class="privacy-note"><span aria-hidden="true">●</span> Dateien werden privat gespeichert und nur angemeldet bereitgestellt.</p><a class="category-manage-link" href="' . h(app_url('categories.php')) . '">Tags verwalten →</a></div><div class="panel archive-list"><div class="list-heading"><div><p class="eyebrow">Familienarchiv</p><h2>Gespeicherte Dokumente</h2></div><span class="count-pill">' . $matchingCount . ($matchingCount === 1 ? ' Eintrag' : ' Einträge') . '</span></div><form class="search-form archive-filter" method="get"><label class="visually-hidden" for="document-search">Dokumente durchsuchen</label><input id="document-search" name="q" type="search" value="' . h($query) . '" maxlength="100" placeholder="Titel, Person oder Tag suchen"><label class="visually-hidden" for="category-filter">Nach Tag filtern</label><select id="category-filter" name="category">' . $tagFilterOptions . '</select><button type="submit">Filtern</button></form>' . $documentRows . ($matchingCount > 100 ? '<p class="list-footnote">Es werden die ersten 100 von ' . $matchingCount . ' Treffern angezeigt. Suche nach einem Titel, um die Liste einzugrenzen.</p>' : '') . '</div></section>';
page('Dokumentenarchiv', $body);
