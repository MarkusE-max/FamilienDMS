<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$isAdmin = $user['role'] === 'admin';
$error = '';
$notice = '';
$nameValue = '';
$successMessages = [
    'created' => 'Kategorie wurde angelegt.',
    'updated' => 'Kategorie wurde umbenannt.',
    'deleted' => 'Kategorie wurde entfernt. Zugeordnete Dokumente bleiben erhalten.',
];
$messageKey = is_string($_GET['message'] ?? null) ? $_GET['message'] : '';
if (isset($successMessages[$messageKey])) $notice = $successMessages[$messageKey];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$isAdmin) {
        http_response_code(403);
        $error = 'Nur ein Administrator kann Kategorien verwalten.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $nameValue = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        try {
            if ($action === 'create' || $action === 'rename') {
                if ($nameValue === '' || mb_strlen($nameValue) > 100) {
                    $error = 'Bitte einen Kategorienamen mit höchstens 100 Zeichen eingeben.';
                } else {
                    if ($action === 'create') {
                        $statement = db()->prepare('INSERT INTO categories (name) VALUES (?)');
                        $statement->execute([$nameValue]);
                        header('Location: ' . app_url('categories.php?message=created'));
                    } else {
                        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($categoryId === false) {
                            $error = 'Die Kategorie wurde nicht gefunden.';
                        } else {
                            $statement = db()->prepare('UPDATE categories SET name = ? WHERE id = ?');
                            $statement->execute([$nameValue, $categoryId]);
                            header('Location: ' . app_url('categories.php?message=updated'));
                        }
                    }
                    if ($error === '') exit;
                }
            } elseif ($action === 'delete') {
                $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($categoryId === false) {
                    $error = 'Die Kategorie wurde nicht gefunden.';
                } else {
                    $statement = db()->prepare('DELETE FROM categories WHERE id = ?');
                    $statement->execute([$categoryId]);
                    header('Location: ' . app_url('categories.php?message=deleted'));
                    exit;
                }
            } else {
                http_response_code(400);
                $error = 'Die Anfrage konnte nicht verarbeitet werden.';
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') $error = 'Diese Kategorie gibt es bereits. Namen müssen eindeutig sein.';
            else {
                error_log('[FamilienDMS] Category operation failed: ' . get_class($exception));
                $error = 'Die Kategorie konnte nicht gespeichert werden. Bitte Datenbankprotokoll prüfen.';
            }
        }
    }
}

$categories = db()->query('SELECT c.id, c.name, c.created_at, COUNT(d.id) AS document_count FROM categories c LEFT JOIN documents d ON d.category_id = c.id GROUP BY c.id, c.name, c.created_at ORDER BY c.name')->fetchAll();
$rows = '';
foreach ($categories as $category) {
    $id = (int) $category['id'];
    $rows .= '<tr><td>' . h((string) $category['name']) . '</td><td>' . (int) $category['document_count'] . '</td>';
    if ($isAdmin) {
        $rows .= '<td><form class="category-edit-form" method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="rename"><input type="hidden" name="category_id" value="' . $id . '"><label class="visually-hidden" for="category-name-' . $id . '">Neuer Name für ' . h((string) $category['name']) . '</label><input id="category-name-' . $id . '" name="name" maxlength="100" required value="' . h((string) $category['name']) . '"><button class="button-secondary" type="submit">Umbenennen</button></form></td><td><form class="inline-form" method="post" onsubmit="return confirm(\'Kategorie entfernen? Dokumente bleiben erhalten und werden nicht kategorisiert.\')"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="' . $id . '"><button class="button-danger" type="submit">Entfernen</button></form></td>';
    }
    $rows .= '</tr>';
}
$table = $categories
    ? '<div class="table-wrap"><table><thead><tr><th scope="col">Kategorie</th><th scope="col">Dokumente</th>' . ($isAdmin ? '<th scope="col">Ändern</th><th scope="col">Entfernen</th>' : '') . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
    : '<div class="empty-state"><span class="empty-icon" aria-hidden="true">▣</span><strong>Noch keine Kategorien</strong><p>' . ($isAdmin ? 'Lege zum Beispiel „Versicherung“, „Gesundheit“ oder „Haus“ an.' : 'Ein Administrator kann Kategorien für das Familienarchiv anlegen.') . '</p></div>';
$body = '<section class="page-intro"><p class="eyebrow">Archiv strukturieren</p><h1>Kategorien</h1><p>Ordnet Dokumente gemeinsamen Themen zu. Beim Entfernen einer Kategorie bleiben die Dokumente erhalten.</p></section>';
if ($notice !== '') $body .= '<p class="alert-success" role="status">' . h($notice) . '</p>';
if ($error !== '') $body .= '<p class="alert-error" role="alert">' . h($error) . '</p>';
if ($isAdmin) $body .= '<section class="panel category-create-panel"><h2>Kategorie hinzufügen</h2><form class="category-create-form" method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="create"><label for="new-category-name">Name<input id="new-category-name" name="name" maxlength="100" required value="' . h($nameValue) . '" placeholder="Zum Beispiel: Versicherung"></label><button type="submit">Hinzufügen</button></form></section>';
$body .= '<section class="panel category-list-panel"><div class="list-heading"><div><p class="eyebrow">Gemeinsame Auswahl</p><h2>Vorhandene Kategorien</h2></div><a class="button button-secondary" href="' . h(app_url('documents.php')) . '">Zum Archiv</a></div>' . $table . '</section>';
page('Kategorien', $body);
