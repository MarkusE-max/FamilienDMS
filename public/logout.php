<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
start_secure_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { page('Abmelden', '<h1>Abmelden</h1><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><button>Jetzt abmelden</button></form>'); exit; }
csrf_check();
$_SESSION = [];
if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']); }
session_destroy();
header('Location: ' . app_url('login.php'));
