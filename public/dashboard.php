<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();
$count = (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
page('Übersicht', '<h1>Willkommen, ' . h($user['display_name']) . '</h1><div class="card"><strong>Benutzerkonten</strong><p>' . $count . ' aktive Konten</p></div><div class="card"><strong>Dokumentenarchiv</strong><p>Dokumentenverwaltung folgt in einer späteren Alpha-Ausbaustufe.</p></div>');
