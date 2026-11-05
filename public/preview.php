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
if (!$document || $document['mime_type'] !== 'application/pdf') { http_response_code(404); exit('PDF-Dokument nicht gefunden.'); }
if (document_storage_file(app_config(), (string) $document['storage_name']) === null) { http_response_code(404); exit('PDF-Dokument nicht verfügbar.'); }

header("Content-Security-Policy: default-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
$previewUrl = app_url('preview-file.php?id=' . (int) $document['id']);
$downloadUrl = app_url('download.php?id=' . (int) $document['id']);
$body = '<section class="page-intro"><p class="eyebrow">PDF-Vorschau</p><h1>' . h($document['title']) . '</h1><p>Die Vorschau wird in deinem Browser angezeigt. Der Zugriff erfordert eine Anmeldung.</p><div class="preview-actions"><a class="button button-secondary" href="' . h(app_url('documents.php')) . '">← Zurück zum Archiv</a><a class="button" href="' . h($downloadUrl) . '">PDF herunterladen</a></div></section>';
$body .= '<section class="panel preview-panel"><iframe class="pdf-preview-frame" src="' . h($previewUrl) . '" title="PDF-Vorschau: ' . h($document['title']) . '" loading="eager" referrerpolicy="no-referrer"></iframe><p class="preview-fallback">Falls dein Browser PDFs nicht direkt anzeigen kann, <a href="' . h($downloadUrl) . '">lade die Datei herunter</a>.</p></section>';
page('PDF-Vorschau', $body);
