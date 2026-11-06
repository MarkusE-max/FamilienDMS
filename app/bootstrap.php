<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

set_exception_handler(static function (Throwable $exception): void {
    $reference = 'FDM-' . strtoupper(bin2hex(random_bytes(4)));
    error_log(sprintf('[FamilienDMS %s] %s', $reference, (string) $exception));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Seite nicht verfügbar · FamilienDMS</title><body style="font:16px system-ui,sans-serif;max-width:680px;margin:10vh auto;padding:24px;color:#1e293b"><p style="color:#54766b;font-weight:700;letter-spacing:.08em;text-transform:uppercase">FamilienDMS</p><h1>Diese Seite konnte nicht geladen werden.</h1><p>Die Anmeldung ist davon nicht betroffen. Bitte notiere den Fehlercode und prüfe das PHP-Fehlerprotokoll der Synology.</p><p>Fehlercode: <strong>' . htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p><p><a href="' . htmlspecialchars(app_url('dashboard.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Zur Übersicht zurück</a></p></body></html>';
});

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
function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = rtrim(dirname($scriptName), '/');
    return $directory === '.' ? '' : $directory;
}
function app_url(string $path): string
{
    return app_base_path() . '/' . ltrim($path, '/');
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
function record_audit_event(int $actorId, string $eventType, ?int $targetId = null, array $details = []): void
{
    $allowed = ['account_created', 'password_reset_by_admin', 'role_changed', 'account_deactivated', 'account_reactivated', 'password_changed', 'password_reset_requested', 'document_trashed', 'document_restored', 'document_permanently_deleted', 'document_reindex_requested'];
    if (!in_array($eventType, $allowed, true)) throw new InvalidArgumentException('Unsupported audit event.');
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
    $q = db()->prepare('INSERT INTO audit_log (actor_user_id, target_user_id, event_type, details_json) VALUES (?, ?, ?, ?)');
    $q->execute([$actorId, $targetId, $eventType, $json]);
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('FDMMSSESSID');
    session_set_cookie_params(['httponly' => true, 'secure' => is_https_request() || force_secure_cookies(), 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}
function force_secure_cookies(): bool
{
    try { return (app_config()['force_secure_cookies'] ?? false) === true; }
    catch (Throwable) { return false; }
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
    $q = db()->prepare('SELECT id, username, display_name, role, is_active, auth_version FROM users WHERE id = ?');
    $q->execute([(int) $_SESSION['user_id']]);
    $user = $q->fetch();
    if (!$user || !(int) $user['is_active'] || (int) ($user['auth_version'] ?? -1) !== (int) ($_SESSION['auth_version'] ?? -2)) { $_SESSION = []; session_regenerate_id(true); return null; }
    return $user;
}
function require_login(): array
{
    $user = current_user();
    if (!$user) { header('Location: ' . app_url('login.php')); exit; }
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
    $signedIn = session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']);
    $nav = $signedIn
        ? '<a class="nav-link" href="' . h(app_url('dashboard.php')) . '"><span class="nav-icon" aria-hidden="true">⌂</span><span>Übersicht</span></a><a class="nav-link" href="' . h(app_url('documents.php')) . '"><span class="nav-icon" aria-hidden="true">▤</span><span>Dokumente</span></a><a class="nav-link" href="' . h(app_url('mobile-upload.php')) . '"><span class="nav-icon" aria-hidden="true">▧</span><span>Handy-Foto</span></a><a class="nav-link" href="' . h(app_url('change-password.php')) . '"><span class="nav-icon" aria-hidden="true">⌑</span><span>Passwort</span></a><a class="nav-link" href="suggestions.php"><span class="nav-icon" aria-hidden="true">✦</span><span>Vorschläge</span></a><a class="nav-link" href="' . h(app_url('folders.php')) . '"><span class="nav-icon" aria-hidden="true">▰</span><span>Ordner</span></a><a class="nav-link" href="' . h(app_url('categories.php')) . '"><span class="nav-icon" aria-hidden="true">▣</span><span>Kategorien</span></a><a class="nav-link" href="' . h(app_url('users.php')) . '"><span class="nav-icon" aria-hidden="true">♙</span><span>Benutzer</span></a><a class="nav-link" href="' . h(app_url('audit-log.php')) . '"><span class="nav-icon" aria-hidden="true">◷</span><span>Änderungsprotokoll</span></a><a class="nav-link" href="' . h(app_url('trash.php')) . '"><span class="nav-icon" aria-hidden="true">▱</span><span>Papierkorb</span></a><a class="nav-link" href="' . h(app_url('settings.php')) . '"><span class="nav-icon" aria-hidden="true">⚙</span><span>Gestaltung</span></a><a class="nav-link nav-link-logout" href="' . h(app_url('logout.php')) . '"><span class="nav-icon" aria-hidden="true">↗</span><span>Abmelden</span></a>'
        : '<a class="nav-link" href="' . h(app_url('login.php')) . '"><span class="nav-icon" aria-hidden="true">→</span><span>Anmelden</span></a>';
    $preferenceUser = $signedIn ? (int) $_SESSION['user_id'] : 0;
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f4f6f3"><title>' . h($title) . ' · ' . $name . '</title><link rel="stylesheet" href="' . h(app_url('assets/app.css')) . '"></head><body data-preference-user="' . $preferenceUser . '"><div class="app-shell"><aside class="sidebar"><a class="brand" href="' . h(app_url($signedIn ? 'dashboard.php' : 'login.php')) . '"><span class="brand-mark" aria-hidden="true">F</span><span class="brand-copy"><strong>FamilienDMS</strong><small>Dokumente. Gemeinsam.</small></span></a><div class="sidebar-label">Arbeitsbereich</div><nav class="main-nav" aria-label="Hauptnavigation">' . $nav . '</nav><div class="sidebar-bottom"><span class="status-dot" aria-hidden="true"></span><span>Privater Bereich</span><small>v0.1 Alpha</small></div></aside><div class="main-column"><header class="topbar"><div class="topbar-title">' . h($title) . '</div><div class="topbar-meta"><span class="topbar-indicator" aria-hidden="true"></span>Heimnetz</div></header><main class="content">' . $body . '</main><footer class="app-footer"><span>FamilienDMS · v0.1 Alpha</span><span>Für die private Familienablage</span></footer></div></div><script defer src="' . h(app_url('assets/preferences.js')) . '"></script><script defer src="' . h(app_url('assets/preview.js')) . '"></script></body></html>';
}
