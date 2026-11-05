<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
function app_config(): array
{
    static $config;
    if ($config !== null) return $config;
    $path = getenv('FAMILIENDMS_CONFIG') ?: APP_ROOT . '/config/config.php';
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Private configuration is missing or unreadable.');
    $config = require $path;
    if (!is_array($config) || empty($config['db']['password'])) throw new RuntimeException('Database configuration is incomplete.');
    return $config;
}
function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = app_config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('FDMMSSESSID');
    session_set_cookie_params(['httponly' => true, 'secure' => is_https_request(), 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}
function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') return true;
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;

    // Only trust forwarded scheme headers when the operator explicitly enables
    // this for a reverse proxy that overwrites the header and is the sole route
    // to the application.
    $trustProxyHeaders = getenv('FAMILIENDMS_TRUST_PROXY_HEADERS') === '1';
    if (!$trustProxyHeaders) {
        try { $trustProxyHeaders = (bool) (app_config()['trust_proxy_headers'] ?? false); }
        catch (Throwable) { $trustProxyHeaders = false; }
    }
    if ($trustProxyHeaders) {
        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwardedProto === 'https') return true;
    }
    return false;
}
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void
{
    start_secure_session();
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) { http_response_code(419); exit('Ungültige Anfrage. Bitte Seite neu laden.'); }
}
function current_user(): ?array
{
    start_secure_session();
    if (empty($_SESSION['user_id'])) return null;
    $q = db()->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = ?');
    $q->execute([(int) $_SESSION['user_id']]);
    $user = $q->fetch();
    if (!$user || !(int) $user['is_active']) { $_SESSION = []; session_regenerate_id(true); return null; }
    return $user;
}
function require_login(): array
{
    $user = current_user();
    if (!$user) { header('Location: /login.php'); exit; }
    return $user;
}
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') { http_response_code(403); exit('Zugriff verweigert.'); }
    return $user;
}
function page(string $title, string $body): void
{
    // The installer must still render a useful diagnostic when configuration is
    // missing or unreadable. Avoid making the shared layout depend on DB config.
    try {
        $name = h((string) (app_config()['app_name'] ?? 'FamilienDMS'));
    } catch (Throwable) {
        $name = 'FamilienDMS';
    }
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' · ' . $name . '</title><style>body{font:16px system-ui;max-width:900px;margin:3rem auto;padding:0 1rem;color:#172033}nav{display:flex;gap:1rem;margin-bottom:2rem}form{display:grid;gap:.7rem;max-width:30rem}input,button{font:inherit;padding:.6rem}a{color:#1456a0}.error{color:#a00}.card{padding:1rem;border:1px solid #ccd3df;border-radius:.6rem;margin:.8rem 0}</style><body><nav><a href="/">FamilienDMS</a> <a href="/dashboard.php">Übersicht</a> <a href="/users.php">Benutzer</a> <a href="/logout.php">Abmelden</a></nav><main>' . $body . '</main></body></html>';
}
