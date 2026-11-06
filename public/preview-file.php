<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/document_storage.php';

require_login();
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) { http_response_code(404); exit('Dokument nicht gefunden.'); }

$statement = db()->prepare('SELECT title, storage_name, mime_type FROM documents WHERE id = ? AND deleted_at IS NULL');
$statement->execute([$documentId]);
$document = $statement->fetch();
if (!$document || !in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)) { http_response_code(404); exit('Dokument nicht gefunden.'); }
$path = document_storage_file(app_config(), (string) $document['storage_name']);
if ($path === null) { http_response_code(404); exit('PDF-Dokument nicht verfügbar.'); }

$title = preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '-', (string) $document['title']) ?? 'Dokument';
$title = trim($title, " .-_\t\n\r\0\x0B");
if ($title === '') $title = 'Dokument';
$extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
$extension = $extensions[$document['mime_type']];
$filename = $title . '.' . $extension;
$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? ('document.' . $extension);
$size = filesize($path);
if ($size === false) { http_response_code(404); exit('PDF-Dokument nicht verfügbar.'); }

header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . (string) $size);
header('Content-Disposition: inline; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
readfile($path);
