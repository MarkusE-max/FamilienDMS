<?php
declare(strict_types=1);

/** Validate and store an upload outside the web root; caller must enforce auth and CSRF. */
function store_document_upload(array $file, array $config, int $maxBytes = 20_000_000): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Upload fehlgeschlagen.');
    if ((int) $file['size'] < 1 || (int) $file['size'] > $maxBytes) throw new RuntimeException('Datei ist leer oder zu groß.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Dateityp nicht erlaubt.');
    $root = rtrim((string) $config['storage_path'], DIRECTORY_SEPARATOR);
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Privater Speicher ist nicht verfügbar.');
    $name = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $root . DIRECTORY_SEPARATOR . $name)) throw new RuntimeException('Datei konnte nicht gespeichert werden.');
    chmod($root . DIRECTORY_SEPARATOR . $name, 0600);
    return ['storage_name' => $name, 'mime_type' => $mime, 'size_bytes' => (int) $file['size']];
}
