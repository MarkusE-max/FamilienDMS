<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/document_storage.php';

require_login();
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) { http_response_code(404); exit('Dokument nicht gefunden.'); }

$statement = db()->prepare('SELECT title, storage_name, mime_type, size_bytes FROM documents WHERE id = ? AND deleted_at IS NULL');
$statement->execute([$documentId]);
$document = $statement->fetch();
if (!$document) { http_response_code(404); exit('Dokument nicht gefunden.'); }

$extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
if (!isset($extensions[$document['mime_type']])) { http_response_code(404); exit('Dokument nicht verfügbar.'); }
$path = document_storage_file(app_config(), (string) $document['storage_name']);
if ($path === null) { http_response_code(404); exit('Dokument nicht verfügbar.'); }

$title = preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '-', (string) $document['title']) ?? 'Dokument';
$title = trim($title, " .-_\t\n\r\0\x0B");
if ($title === '') $title = 'Dokument';
$extension = $extensions[$document['mime_type']];
$filename = $title . '.' . $extension;
$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? ('document.' . $extension);
$size = filesize($path);
if ($size === false) { http_response_code(404); exit('Dokument nicht verfügbar.'); }

header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . (string) $size);
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
readfile($path);
