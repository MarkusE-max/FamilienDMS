<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$isAdmin = $user['role'] === 'admin';
$error = '';
$nameValue = '';
$notice = '';
$messages = ['created' => 'Ordner wurde angelegt.', 'updated' => 'Ordner wurde gespeichert.', 'deleted' => 'Leerer Ordner wurde entfernt.'];
$messageKey = is_string($_GET['message'] ?? null) ? $_GET['message'] : '';
if (isset($messages[$messageKey])) $notice = $messages[$messageKey];

$loadFolders = static function (): array {
    return db()->query('SELECT id, parent_id, name FROM folders ORDER BY name')->fetchAll();
};
$folders = $loadFolders();
$folderById = [];
$children = [];
foreach ($folders as $folder) {
    $id = (int) $folder['id'];
    $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
    $folderById[$id] = ['id' => $id, 'parent_id' => $parentId, 'name' => (string) $folder['name']];
    $children[$parentId ?? 0][] = $id;
}
$getDescendants = static function (int $folderId) use ($children): array {
    $found = [$folderId];
    $queue = [$folderId];
    for ($index = 0; $index < count($queue); $index++) {
        foreach ($children[$queue[$index]] ?? [] as $childId) {
            if (!in_array($childId, $found, true)) { $found[] = $childId; $queue[] = $childId; }
        }
    }
    return $found;
};
$parentSelect = static function (?int $selected, array $excludeIds = []) use ($children, $folderById): string {
    $html = '<option value="">Oberster Ordner</option>';
    $render = static function (int $parentId, int $depth) use (&$render, $children, $folderById, $excludeIds, $selected, &$html): void {
        foreach ($children[$parentId] ?? [] as $childId) {
            if (in_array($childId, $excludeIds, true)) continue;
            $folder = $folderById[$childId];
            $html .= '<option value="' . $childId . '"' . ($selected === $childId ? ' selected' : '') . '>' . str_repeat('· ', $depth) . h($folder['name']) . '</option>';
            $render($childId, $depth + 1);
        }
    };
    $render(0, 0);
    return $html;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$isAdmin) {
        http_response_code(403);
        $error = 'Nur Administratoren können die Ordnerstruktur ändern.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $nameValue = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        if (str_contains($nameValue, '/') || str_contains($nameValue, '\\')) $error = 'Ordnernamen dürfen keinen Schrägstrich enthalten.';
        try {
            if ($action === 'create' || $action === 'save') {
                if ($nameValue === '' || mb_strlen($nameValue) > 100) {
                    $error = 'Bitte einen Ordnernamen mit höchstens 100 Zeichen eingeben.';
                } else {
                    $rawParent = $_POST['parent_id'] ?? '';
                    $parentId = $rawParent === '' ? null : filter_var($rawParent, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($parentId === false || ($parentId !== null && !isset($folderById[(int) $parentId]))) {
                        $error = 'Der übergeordnete Ordner wurde nicht gefunden.';
                    } else {
                        $folderId = null;
                        $excludeIds = [];
                        if ($action === 'save') {
                            $folderId = filter_var($_POST['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                            if ($folderId === false || !isset($folderById[(int) $folderId])) {
                                $error = 'Der Ordner wurde nicht gefunden.';
                            } else {
                                $folderId = (int) $folderId;
                                $excludeIds = $getDescendants($folderId);
                                if ($parentId !== null && in_array((int) $parentId, $excludeIds, true)) $error = 'Ein Ordner kann nicht in sich selbst oder einen eigenen Unterordner verschoben werden.';
                            }
                        }
                        if ($error === '') {
                            $duplicate = db()->prepare('SELECT id FROM folders WHERE name = ? AND parent_id <=> ? AND (? IS NULL OR id <> ?) LIMIT 1');
                            $duplicate->execute([$nameValue, $parentId, $folderId, $folderId]);
                            if ($duplicate->fetchColumn() !== false) {
                                $error = 'In diesem Ordner gibt es bereits einen gleichnamigen Eintrag.';
                            } elseif ($action === 'create') {
                                $statement = db()->prepare('INSERT INTO folders (parent_id, name, created_by) VALUES (?, ?, ?)');
                                $statement->execute([$parentId, $nameValue, (int) $user['id']]);
                                header('Location: ' . app_url('folders.php?message=created'));
                                exit;
                            } else {
                                $statement = db()->prepare('UPDATE folders SET parent_id = ?, name = ? WHERE id = ?');
                                $statement->execute([$parentId, $nameValue, $folderId]);
                                header('Location: ' . app_url('folders.php?message=updated'));
                                exit;
                            }
                        }
                    }
                }
            } elseif ($action === 'delete') {
                $folderId = filter_var($_POST['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($folderId === false || !isset($folderById[(int) $folderId])) {
                    $error = 'Der Ordner wurde nicht gefunden.';
                } else {
                    $statement = db()->prepare('SELECT (SELECT COUNT(*) FROM documents WHERE folder_id = ?) + (SELECT COUNT(*) FROM folders WHERE parent_id = ?)');
                    $statement->execute([$folderId, $folderId]);
                    if ((int) $statement->fetchColumn() > 0) {
                        $error = 'Der Ordner ist noch belegt. Verschiebe zuerst die Dokumente und Unterordner.';
                    } else {
                        $statement = db()->prepare('DELETE FROM folders WHERE id = ?');
                        $statement->execute([$folderId]);
                        header('Location: ' . app_url('folders.php?message=deleted'));
                        exit;
                    }
                }
            } else {
                http_response_code(400);
                $error = 'Die Anfrage konnte nicht verarbeitet werden.';
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') $error = 'Ein Ordner mit diesem Namen existiert dort bereits.';
            else {
                error_log('[FamilienDMS] Folder operation failed: ' . get_class($exception));
                $error = 'Der Ordner konnte nicht gespeichert werden. Bitte Datenbankprotokoll prüfen.';
            }
        }
    }
}

$folderDocumentCounts = [];
foreach (db()->query('SELECT folder_id, COUNT(*) AS total FROM documents WHERE folder_id IS NOT NULL GROUP BY folder_id')->fetchAll() as $count) $folderDocumentCounts[(int) $count['folder_id']] = (int) $count['total'];
$rows = '';
$renderRows = static function (int $parentId, int $depth) use (&$renderRows, &$rows, $children, $folderById, $folderDocumentCounts, $isAdmin, $parentSelect): void {
    foreach ($children[$parentId] ?? [] as $folderId) {
        $folder = $folderById[$folderId];
        $label = str_repeat('　', min($depth, 8)) . ($depth > 0 ? '↳ ' : '') . $folder['name'];
        $rows .= '<tr><td><a class="folder-name-link" href="' . h(app_url('documents.php?folder=' . $folderId)) . '">' . h($label) . '</a></td><td>' . ($folderDocumentCounts[$folderId] ?? 0) . '</td><td>' . count($children[$folderId] ?? []) . '</td>';
        if ($isAdmin) {
            $excluded = [];
            $pending = [$folderId];
            while ($pending) { $next = array_pop($pending); if (in_array($next, $excluded, true)) continue; $excluded[] = $next; foreach ($children[$next] ?? [] as $child) $pending[] = $child; }
            $rows .= '<td><details class="folder-edit-details"><summary>Bearbeiten</summary><form class="folder-edit-form" method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="folder_id" value="' . $folderId . '"><label>Name<input name="name" maxlength="100" required value="' . h($folder['name']) . '"></label><label>Übergeordneter Ordner<select name="parent_id">' . $parentSelect($folder['parent_id'], $excluded) . '</select></label><button class="button-secondary" type="submit">Speichern</button></form></details><form class="inline-form folder-delete-form" method="post" onsubmit="return confirm(\'Leeren Ordner entfernen?\')"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="folder_id" value="' . $folderId . '"><button class="button-danger" type="submit">Entfernen</button></form></td>';
        }
        $rows .= '</tr>';
        $renderRows($folderId, $depth + 1);
    }
};
$renderRows(0, 0);
$table = $rows !== ''
    ? '<div class="table-wrap"><table><thead><tr><th scope="col">Ordnerpfad</th><th scope="col">Direkte Dokumente</th><th scope="col">Unterordner</th>' . ($isAdmin ? '<th scope="col">Verwaltung</th>' : '') . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
    : '<div class="empty-state"><span class="empty-icon" aria-hidden="true">▰</span><strong>Noch keine Ordner</strong><p>' . ($isAdmin ? 'Lege den ersten Ordner an, zum Beispiel „Versicherungen“.' : 'Ein Administrator kann Ordner für das Archiv anlegen.') . '</p></div>';
$body = '<section class="page-intro"><p class="eyebrow">Ablage strukturieren</p><h1>Ordner</h1><p>Erstelle Ordner und Unterordner. Tags bleiben zusätzlich verfügbar und können Dokumente themenübergreifend verbinden.</p></section>';
if ($notice !== '') $body .= '<p class="alert-success" role="status">' . h($notice) . '</p>';
if ($error !== '') $body .= '<p class="alert-error" role="alert">' . h($error) . '</p>';
if ($isAdmin) $body .= '<section class="panel category-create-panel"><h2>Ordner hinzufügen</h2><form class="folder-create-form" method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="create"><label>Name<input name="name" maxlength="100" required value="' . h($nameValue) . '" placeholder="Zum Beispiel: Versicherungen"></label><label>Übergeordneter Ordner<select name="parent_id">' . $parentSelect(null) . '</select></label><button type="submit">Ordner hinzufügen</button></form></section>';
$body .= '<section class="panel category-list-panel"><div class="list-heading"><div><p class="eyebrow">Familienarchiv</p><h2>Ordnerstruktur</h2></div><a class="button button-secondary" href="' . h(app_url('documents.php')) . '">Zum Archiv</a></div>' . $table . '</section>';
page('Ordner', $body);
