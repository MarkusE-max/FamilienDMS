<?php
declare(strict_types=1);

/** Return a real file path only for generated document names inside private storage. */
function document_storage_file(array $config, string $storageName): ?string
{
    if (!preg_match('/\A[a-f0-9]{48}\.(?:pdf|jpg|png)\z/', $storageName)) return null;
    $configuredRoot = $config['storage_path'] ?? null;
    if (!is_string($configuredRoot) || $configuredRoot === '') return null;
    $root = realpath($configuredRoot);
    if ($root === false || !is_dir($root)) return null;
    $path = realpath($root . DIRECTORY_SEPARATOR . $storageName);
    if ($path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return null;
    return $path;
}
