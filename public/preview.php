<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/document_storage.php';

require_login();
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) { http_response_code(404); exit('Dokument nicht gefunden.'); }

$statement = db()->prepare('SELECT id, title, mime_type, storage_name FROM documents WHERE id = ?');
$statement->execute([$documentId]);
$document = $statement->fetch();
if (!$document || !in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)) { http_response_code(404); exit('Dokument nicht gefunden.'); }
if (document_storage_file(app_config(), (string) $document['storage_name']) === null) { http_response_code(404); exit('Dokument nicht verfügbar.'); }

header("Content-Security-Policy: default-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
$previewUrl = app_url('preview-file.php?id=' . (int) $document['id']);
$downloadUrl = app_url('download.php?id=' . (int) $document['id']);
$isPdf = $document['mime_type'] === 'application/pdf';
$kind = $isPdf ? 'PDF' : 'Bild';
$downloadLabel = $kind . ' herunterladen';
$body = '<section class="page-intro"><p class="eyebrow">' . $kind . '-Vorschau</p><h1>' . h($document['title']) . '</h1><p>Die Vorschau ist nur nach der Anmeldung verfügbar.</p><div class="preview-actions"><a class="button button-secondary" href="' . h(app_url('documents.php')) . '">← Zurück zum Archiv</a><a class="button" href="' . h($downloadUrl) . '">' . $downloadLabel . '</a></div></section>';
$body .= '<section class="panel preview-panel"><div class="preview-toolbar"><span>Vorschaugröße</span><div class="preview-size-options" role="group" aria-label="Vorschaugröße"><button class="button-secondary preview-size-button" type="button" data-preview-height="58" aria-pressed="false">Kompakt</button><button class="button-secondary preview-size-button" type="button" data-preview-height="75" aria-pressed="true">Lesen</button><button class="button-secondary preview-size-button" type="button" data-preview-height="86" aria-pressed="false">Groß</button></div><span class="preview-resize-hint">Auch an der unteren rechten Ecke ziehbar</span></div>';
if ($isPdf) {
    $body .= '<div class="pdf-preview-resizable" id="document-preview-resizable"><iframe class="pdf-preview-frame" src="' . h($previewUrl) . '#zoom=page-width" title="PDF-Vorschau: ' . h($document['title']) . '" loading="eager" referrerpolicy="no-referrer"></iframe></div><p class="preview-fallback">Zum Lesen kannst du zusätzlich die Zoom-Steuerung im PDF-Betrachter deines Browsers verwenden. Falls dein Browser PDFs nicht direkt anzeigen kann, <a href="' . h($downloadUrl) . '">lade die Datei herunter</a>.</p>';
} else {
    $body .= '<div class="image-preview-resizable" id="document-preview-resizable"><img class="image-preview-image" src="' . h($previewUrl) . '" alt="Vorschau von ' . h($document['title']) . '" loading="eager" referrerpolicy="no-referrer"></div><p class="preview-fallback">Das Bild wird passend in der Vorschaufläche angezeigt. Ziehe die Ecke, um den sichtbaren Bereich zu vergrößern.</p>';
}
$body .= '</section>';
page($kind . '-Vorschau', $body);
