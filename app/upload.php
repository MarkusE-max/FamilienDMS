<?php
declare(strict_types=1);

/** Validate and store an upload outside the web root; caller must enforce auth and CSRF. */
function store_document_upload(array $file, array $config, int $maxBytes = 20_000_000): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    $temporaryPath = $file['tmp_name'] ?? null;
    $size = $file['size'] ?? null;
    if (!is_int($error) || !is_string($temporaryPath) || !is_int($size)) throw new RuntimeException('Upload fehlgeschlagen. Bitte erneut versuchen.');
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || $size > $maxBytes) throw new RuntimeException('Die Datei ist größer als die erlaubten 20 MB.');
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($temporaryPath)) throw new RuntimeException('Upload fehlgeschlagen. Bitte Datei erneut auswählen.');
    if ($size < 1) throw new RuntimeException('Die Datei ist leer.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Dateityp nicht erlaubt.');
    $root = rtrim((string) ($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
    if ($root === '') throw new RuntimeException('Privater Speicher ist nicht konfiguriert.');
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Privater Speicher ist nicht verfügbar.');
    $name = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    $destination = $root . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($temporaryPath, $destination)) throw new RuntimeException('Datei konnte nicht gespeichert werden.');
    if (!chmod($destination, 0600)) { @unlink($destination); throw new RuntimeException('Dateirechte konnten nicht sicher gesetzt werden.'); }
    return ['storage_name' => $name, 'mime_type' => $mime, 'size_bytes' => $size];
}
