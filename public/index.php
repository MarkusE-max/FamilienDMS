<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
header('Location: ' . app_url(current_user() ? 'dashboard.php' : 'login.php'));
