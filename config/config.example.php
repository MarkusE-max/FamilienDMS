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
];
