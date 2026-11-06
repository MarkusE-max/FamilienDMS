<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/upload.php';
require __DIR__ . '/../app/document_storage.php';

$user = require_login();
$error = '';
$titleValue = '';
$submittedCategoryIds = [];
$submittedFolderId = null;
$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$categoryIds = array_map(static fn(array $category): int => (int) $category['id'], $categories);
$folders = db()->query('SELECT id, parent_id, name FROM folders ORDER BY name')->fetchAll();
$folderById = [];
$folderChildren = [];
foreach ($folders as $folder) {
    $id = (int) $folder['id'];
    $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
    $folderById[$id] = ['id' => $id, 'parent_id' => $parentId, 'name' => (string) $folder['name']];
    $folderChildren[$parentId ?? 0][] = $id;
}
$folderIds = array_keys($folderById);
$folderPath = static function (?int $folderId) use ($folderById): string {
    if ($folderId === null || !isset($folderById[$folderId])) return 'Ohne Ordner';
    $names = [];
    $seen = [];
    while ($folderId !== null && isset($folderById[$folderId]) && !isset($seen[$folderId])) {
        $seen[$folderId] = true;
        $names[] = $folderById[$folderId]['name'];
        $folderId = $folderById[$folderId]['parent_id'];
    }
    return implode(' / ', array_reverse($names));
};
$folderDescendants = static function (int $folderId) use ($folderChildren): array {
    $found = [$folderId];
    $queue = [$folderId];
    for ($index = 0; $index < count($queue); $index++) {
        foreach ($folderChildren[$queue[$index]] ?? [] as $childId) {
            if (!in_array($childId, $found, true)) { $found[] = $childId; $queue[] = $childId; }
        }
    }
    return $found;
};
$folderSelectOptions = '';
$addFolderOptions = static function (int $parentId, int $depth) use (&$addFolderOptions, &$folderSelectOptions, $folderChildren, $folderById): void {
    foreach ($folderChildren[$parentId] ?? [] as $childId) {
        $folderSelectOptions .= '<option value="' . $childId . '">' . str_repeat('· ', $depth) . h($folderById[$childId]['name']) . '</option>';
        $addFolderOptions($childId, $depth + 1);
    }
};
$addFolderOptions(0, 0);
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
        $rawFolderId = $_POST['folder_id'] ?? '';
        if ($rawFolderId !== '') {
            $submittedFolderId = filter_var($rawFolderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($submittedFolderId === false || !in_array((int) $submittedFolderId, $folderIds, true)) {
                $submittedFolderId = null;
                $error = 'Bitte einen gültigen Ordner auswählen.';
            }
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
                $statement = $pdo->prepare('INSERT INTO documents (title, folder_id, storage_name, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
                $statement->execute([$titleValue, $submittedFolderId, $stored['storage_name'], $stored['mime_type'], $stored['size_bytes'], (int) $user['id']]);
                $documentId = (int) $pdo->lastInsertId();
                $indexStatement = $pdo->prepare("INSERT INTO document_text (document_id, status) VALUES (?, 'pending')");
                $indexStatement->execute([$documentId]);
                $tagStatement = $pdo->prepare('INSERT INTO document_categories (document_id, category_id) VALUES (?, ?)');
                foreach ($submittedCategoryIds as $tagId) $tagStatement->execute([$documentId, $tagId]);
                $pdo->commit();
                $returnPath = (($_POST['return_to'] ?? '') === 'mobile') ? 'mobile-upload.php?uploaded=1' : 'documents.php?uploaded=1';
                header('Location: ' . app_url($returnPath));
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
    } elseif ($action === 'set_folder') {
        $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rawFolderId = $_POST['folder_id'] ?? '';
        $newFolderId = $rawFolderId === '' ? null : filter_var($rawFolderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($documentId === false || ($rawFolderId !== '' && ($newFolderId === false || !in_array((int) $newFolderId, $folderIds, true)))) {
            $error = 'Bitte eine gültige Ordnerauswahl verwenden.';
        } else {
            $statement = db()->prepare('SELECT uploaded_by FROM documents WHERE id = ? AND deleted_at IS NULL');
            $statement->execute([$documentId]);
            $documentOwner = $statement->fetchColumn();
            if ($documentOwner === false) {
                $error = 'Das Dokument wurde nicht gefunden.';
            } elseif ($user['role'] !== 'admin' && (int) $documentOwner !== (int) $user['id']) {
                http_response_code(403);
                $error = 'Du kannst nur eigene Dokumente verschieben.';
            } else {
                $statement = db()->prepare('UPDATE documents SET folder_id = ? WHERE id = ?');
                $statement->execute([$newFolderId ?: null, $documentId]);
                header('Location: ' . app_url('documents.php?folder_updated=1'));
                exit;
            }
        }
    } elseif ($action === 'set_categories') {
        $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $newCategoryIds = $parseCategoryIds($_POST['category_ids'] ?? null);
        if ($documentId === false || $newCategoryIds === false) {
            $error = 'Bitte eine gültige Dokumenten- und Kategorieauswahl verwenden.';
        } else {
            $statement = db()->prepare('SELECT uploaded_by FROM documents WHERE id = ? AND deleted_at IS NULL');
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
    } elseif ($action === 'reindex') {
        $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($documentId === false) {
            $error = 'Das Dokument wurde nicht gefunden.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT d.uploaded_by, t.status FROM documents d JOIN document_text t ON t.document_id = d.id WHERE d.id = ? AND d.deleted_at IS NULL FOR UPDATE');
                $statement->execute([$documentId]);
                $document = $statement->fetch();
                if (!$document) {
                    $pdo->rollBack();
                    $error = 'Das Dokument wurde nicht gefunden.';
                } elseif ($user['role'] !== 'admin' && (int) $document['uploaded_by'] !== (int) $user['id']) {
                    $pdo->rollBack();
                    http_response_code(403);
                    $error = 'Du kannst nur eigene Dokumente erneut erkennen lassen.';
                } elseif ($document['status'] === 'processing') {
                    $pdo->rollBack();
                    $error = 'Dieses Dokument wird gerade verarbeitet. Bitte warte, bis der OCR-Auftrag fertig ist.';
                } else {
                    $pdo->prepare("UPDATE document_text SET status = 'pending', content = NULL, lease_until = NULL, error_code = NULL, indexed_at = NULL, force_ocr = 1, error_detail = NULL WHERE document_id = ?")->execute([$documentId]);
                    record_audit_event((int) $user['id'], 'document_reindex_requested', (int) $document['uploaded_by'], ['document_id' => (int) $documentId]);
                    $pdo->commit();
                    header('Location: ' . app_url('documents.php?reindex_queued=1'));
                    exit;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[FamilienDMS] Document reindex request failed: ' . get_class($exception));
                $error = 'Die erneute Erkennung konnte nicht vorgemerkt werden.';
            }
        }
    } elseif ($action === 'delete') {
        $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($documentId === false) {
            $error = 'Das Dokument wurde nicht gefunden.';
        } else {
            $statement = db()->prepare('SELECT id, storage_name, uploaded_by FROM documents WHERE id = ? AND deleted_at IS NULL');
            $statement->execute([$documentId]);
            $document = $statement->fetch();
            if (!$document) {
                $error = 'Das Dokument wurde nicht gefunden.';
            } elseif ($user['role'] !== 'admin' && (int) $document['uploaded_by'] !== (int) $user['id']) {
                http_response_code(403);
                $error = 'Du kannst nur eigene Dokumente entfernen.';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE documents SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE id = ? AND deleted_at IS NULL')->execute([(int) $user['id'], $documentId]);
                    record_audit_event((int) $user['id'], 'document_trashed', (int) $document['uploaded_by'], ['document_id' => (int) $documentId]);
                    $pdo->commit();
                    header('Location: ' . app_url('documents.php?deleted=1'));
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
                }
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
$folderFilter = is_string($_GET['folder'] ?? null) ? $_GET['folder'] : '';
$filterUnassigned = $folderFilter === 'none';
$selectedFolderId = null;
if ($folderFilter !== '' && !$filterUnassigned) {
    $candidateFolderId = filter_var($folderFilter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($candidateFolderId !== false && in_array((int) $candidateFolderId, $folderIds, true)) $selectedFolderId = (int) $candidateFolderId;
}
$where = ['d.deleted_at IS NULL'];
$parameters = [];
if ($query !== '') {
    $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $tagQuery = ltrim($query, '#');
    $tagLike = '%' . strtr($tagQuery, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    preg_match_all('/[\p{L}\p{N}]{3,}/u', $query, $wordMatches);
    $booleanTextQuery = implode(' ', array_map(static fn(string $word): string => '+' . $word . '*', array_slice(array_unique($wordMatches[0] ?? []), 0, 12)));
    $contentSearch = $booleanTextQuery !== '' ? 'MATCH(search_t.content) AGAINST (? IN BOOLEAN MODE)' : "search_t.content LIKE ? ESCAPE '!'";
    $where[] = "(d.title LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!' OR d.sender LIKE ? ESCAPE '!' OR d.document_type LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM document_categories search_dc JOIN categories search_c ON search_c.id = search_dc.category_id WHERE search_dc.document_id = d.id AND search_c.name LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM folders search_f WHERE search_f.id = d.folder_id AND search_f.name LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM document_text search_t WHERE search_t.document_id = d.id AND search_t.status = 'indexed' AND {$contentSearch}))";
    array_push($parameters, $like, $like, $like, $like, $tagLike, $like, $booleanTextQuery !== '' ? $booleanTextQuery : $like);
}
if ($selectedCategory !== null) {
    $where[] = 'EXISTS (SELECT 1 FROM document_categories filter_dc WHERE filter_dc.document_id = d.id AND filter_dc.category_id = ?)';
    $parameters[] = (int) $selectedCategory;
}
$folderScopeIds = $selectedFolderId === null ? [] : $folderDescendants($selectedFolderId);
if ($filterUnassigned) {
    $where[] = 'd.folder_id IS NULL';
} elseif ($folderScopeIds) {
    $where[] = 'd.folder_id IN (' . implode(',', array_fill(0, count($folderScopeIds), '?')) . ')';
    array_push($parameters, ...$folderScopeIds);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$sql = 'SELECT d.id, d.title, d.folder_id, d.sender, d.document_date, d.document_type, d.mime_type, d.size_bytes, d.uploaded_by, d.created_at, u.display_name, COALESCE(dt.status, \'pending\') AS text_status, dt.error_code AS text_error_code, dt.error_detail AS text_error_detail FROM documents d JOIN users u ON u.id = d.uploaded_by LEFT JOIN document_text dt ON dt.document_id = d.id' . $whereSql . ' ORDER BY d.created_at DESC, d.id DESC LIMIT 100';
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
    $currentFolderId = $document['folder_id'] === null ? null : (int) $document['folder_id'];
    $currentFolderPath = $folderPath($currentFolderId);
    $tags = $documentTags[$id] ?? [];
    $textStatus = (string) $document['text_status'];
    $textStatusLabel = match ($textStatus) {
        'indexed' => 'Textsuche bereit',
        'empty' => 'Kein Text gefunden',
        'failed' => 'Texterkennung fehlgeschlagen',
        'processing' => 'Text wird erkannt …',
        default => 'Text wartet auf Erkennung',
    };
    $metadata = array_values(array_filter([(string) ($document['sender'] ?? ''), !empty($document['document_date']) ? date('d.m.Y', strtotime((string) $document['document_date'])) : '', (string) ($document['document_type'] ?? '')], static fn(string $value): bool => $value !== ''));
    if ($metadata) $textStatusLabel .= ' · ' . implode(' · ', $metadata);
    $textErrorDetail = $textStatus === 'failed' ? (string) ($document['text_error_detail'] ?? 'Keine Fehlerbeschreibung gespeichert. OCR-Auftrag erneut starten.') : '';
    $tagView = $tags ? '' : '<span class="category-badge category-empty">Ohne Tag</span>';
    foreach ($tags as $tag) $tagView .= '<span class="category-badge">#' . h(ltrim($tag['name'], '#')) . '</span>';
    $rows .= '<tr><td><span class="file-type" aria-hidden="true">' . $icon . '</span></td><td><a class="document-title" href="' . h(app_url('download.php?id=' . $id)) . '">' . h($document['title']) . '</a><span class="user-secondary">' . h($document['display_name']) . '</span><span class="document-index-status" title="' . h($textStatusLabel) . '">' . h($textStatusLabel) . '</span>' . ($textErrorDetail !== '' ? '<span class="document-index-error">' . h($textErrorDetail) . '</span>' : '') . '</td><td><span class="folder-path-badge">' . h($currentFolderPath) . '</span>';
    if ($canCategorize && $folders) {
        $rows .= '<details class="document-folder-editor"><summary>Verschieben</summary><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="set_folder"><input type="hidden" name="document_id" value="' . $id . '"><label class="visually-hidden" for="document-folder-' . $id . '">Ordner für ' . h($document['title']) . '</label><select id="document-folder-' . $id . '" name="folder_id"><option value="">Ohne Ordner</option>';
        foreach ($folderById as $folderOption) {
            $selected = $currentFolderId === $folderOption['id'] ? ' selected' : '';
            $rows .= '<option value="' . $folderOption['id'] . '"' . $selected . '>' . h($folderPath($folderOption['id'])) . '</option>';
        }
        $rows .= '</select><button type="submit">Speichern</button></form></details>';
    }
    $rows .= '</td><td><div class="document-tags">' . $tagView . '</div>';
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
    if ($canCategorize && $textStatus !== 'processing') $rows .= '<form class="inline-form reindex-form" method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="reindex"><input type="hidden" name="document_id" value="' . $id . '"><button class="button-secondary" type="submit">Ausrichten &amp; erneut erkennen</button></form>';
    if ($canDelete) $rows .= '<form class="inline-form" method="post" onsubmit="return confirm(\'Dieses Dokument wirklich entfernen?\')"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="document_id" value="' . $id . '"><button class="button-danger" type="submit" aria-label="' . h('Dokument ' . $document['title'] . ' entfernen') . '">Entfernen</button></form>';
    $rows .= '</td></tr>';
}
if ($documents) {
    $documentRows = '<div class="table-wrap"><table><thead><tr><th scope="col">Typ</th><th scope="col">Dokument</th><th scope="col">Ordner</th><th scope="col">Tags</th><th scope="col">Größe</th><th scope="col">Hinzugefügt</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
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
$folderFilterOptions = '<option value="">Alle Ordner</option><option value="none"' . ($filterUnassigned ? ' selected' : '') . '>Ohne Ordner</option>';
$uploadFolderOptions = '<option value="">Ohne Ordner</option>';
foreach ($folderById as $folderOption) {
    $folderFilterOptions .= '<option value="' . $folderOption['id'] . '"' . ($selectedFolderId === $folderOption['id'] ? ' selected' : '') . '>' . h($folderPath($folderOption['id'])) . '</option>';
    $uploadFolderOptions .= '<option value="' . $folderOption['id'] . '"' . ($submittedFolderId === $folderOption['id'] ? ' selected' : '') . '>' . h($folderPath($folderOption['id'])) . '</option>';
}
$notice = '';
if (isset($_GET['uploaded'])) $notice = '<p class="alert-success" role="status">Dokument wurde sicher gespeichert.</p>';
elseif (isset($_GET['deleted'])) $notice = '<p class="alert-success" role="status">Dokument liegt jetzt im Papierkorb und kann wiederhergestellt werden.</p>';
elseif (isset($_GET['reindex_queued'])) $notice = '<p class=\"alert-success\" role=\"status\">Die erneute Ausrichtung und Texterkennung ist vorgemerkt. Der geplante OCR-Auftrag verarbeitet das Dokument beim nächsten Lauf.</p>';
elseif (isset($_GET['updated'])) $notice = '<p class="alert-success" role="status">Dokument-Tags wurden gespeichert.</p>';
elseif (isset($_GET['folder_updated'])) $notice = '<p class="alert-success" role="status">Dokument wurde in den Ordner verschoben.</p>';
$body = '<section class="page-intro"><p class="eyebrow">Gemeinsame Ablage</p><h1>Dokumentenarchiv</h1><p>Dokumente in Ordnern ablegen und mit Tags, Namen oder Text aus PDFs und Bildern wiederfinden. Die Inhaltssuche wird im Hintergrund aufgebaut.</p></section>';
$body .= $notice . ($error ? '<p class="alert-error" role="alert">' . h($error) . '</p>' : '');
$body .= '<section class="document-layout"><div class="panel upload-panel"><p class="eyebrow">Neue Ablage</p><h2>Dokument hinzufügen</h2><p class="panel-subtitle">PDF, JPEG oder PNG · maximal 20 MB</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="upload"><label>Bezeichnung<input name="title" required maxlength="200" value="' . h($titleValue) . '" placeholder="Zum Beispiel: Versicherung Haus"></label><label>Ordner<select name="folder_id">' . $uploadFolderOptions . '</select></label><fieldset class="tag-fieldset"><legend>Tags auswählen · bis zu 30</legend>' . ($uploadTags !== '' ? '<div class="tag-choice-list">' . $uploadTags . '</div>' : '<p class="form-hint">Noch keine Tags angelegt. Ein Administrator kann diese unter Kategorien erstellen.</p>') . '</fieldset><label>Datei auswählen<input type="file" name="document" accept="application/pdf,image/jpeg,image/png" required></label><button type="submit">Sicher hochladen</button></form><p class="privacy-note"><span aria-hidden="true">●</span> Dateien werden privat gespeichert und nur angemeldet bereitgestellt.</p><a class="category-manage-link" href="' . h(app_url('folders.php')) . '">Ordner verwalten →</a> · <a class="category-manage-link" href="' . h(app_url('categories.php')) . '">Tags verwalten →</a></div><div class="panel archive-list"><div class="list-heading"><div><p class="eyebrow">Familienarchiv</p><h2>Gespeicherte Dokumente</h2></div><span class="count-pill">' . $matchingCount . ($matchingCount === 1 ? ' Eintrag' : ' Einträge') . '</span></div><form class="search-form archive-filter" method="get"><label class="visually-hidden" for="document-search">Dokumente durchsuchen</label><input id="document-search" name="q" type="search" value="' . h($query) . '" maxlength="100" placeholder="Titel, Person, Tag, Ordner oder Dokumentinhalt suchen"><label class="visually-hidden" for="category-filter">Nach Tag filtern</label><select id="category-filter" name="category">' . $tagFilterOptions . '</select><label class="visually-hidden" for="folder-filter">Nach Ordner filtern</label><select id="folder-filter" name="folder">' . $folderFilterOptions . '</select><button type="submit">Filtern</button></form>' . $documentRows . ($matchingCount > 100 ? '<p class="list-footnote">Es werden die ersten 100 von ' . $matchingCount . ' Treffern angezeigt. Suche nach einem Titel, um die Liste einzugrenzen.</p>' : '') . '</div></section>';
page('Dokumentenarchiv', $body);
