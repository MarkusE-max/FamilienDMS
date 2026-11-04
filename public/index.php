<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
header('Location: ' . (current_user() ? '/dashboard.php' : '/login.php'));
