<?php
declare(strict_types=1);

// Copy outside the public web root and set FAMILIENDMS_CONFIG to its absolute path.
return [
    'db' => [
        'host' => getenv('FDMMS_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('FDMMS_DB_PORT') ?: 3306),
        'name' => getenv('FDMMS_DB_NAME') ?: 'familiendms',
        'user' => getenv('FDMMS_DB_USER') ?: 'familiendms_app',
        'password' => getenv('FDMMS_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'storage_path' => getenv('FAMILIENDMS_STORAGE') ?: '/volume1/@appdata/familiendms/storage',
    'app_name' => 'FamilienDMS',
    // Set true only behind a trusted reverse proxy that overwrites X-Forwarded-Proto
    // and is the only network route to this application.
    'trust_proxy_headers' => false,
    // Temporary: true only while creating the first admin during local development.
    'allow_insecure_install' => false,
    // Set true when the browser uses HTTPS but the web server cannot detect it.
    'force_secure_cookies' => false,
];
