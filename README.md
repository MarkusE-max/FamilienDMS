# FamilienDMS v0.1 Alpha

Secure starter application for a small family document archive on Synology DSM 7.4.1, Web Station, PHP 8.2 and MariaDB.

## Requirements

- PHP 8.2 with `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` and sessions enabled
- MariaDB 10.5+ (InnoDB, utf8mb4)
- HTTPS in production
- A private directory outside the Web Station document root, writable by the PHP-FPM user

## Layout

`public/` is the only web root. `app/` contains request handling and security helpers, `database/migrations/` the schema, `config/` a non-secret example, `storage/` private runtime data, and `docs/` Synology deployment notes. Never place secrets or uploaded files beneath `public/`.

## Local setup

1. Copy `config/config.example.php` to a private path outside the document root (for example `/volume1/@appdata/familiendms/config.php`) and set DB details via environment variables or that private file. Do not commit the live config.
2. Create a dedicated MariaDB database and user with only `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX` on that database. Revoke DDL rights after migration if desired.
3. Import `database/migrations/001_initial.sql` using an administrative account.
4. Point the Web Station virtual host document root at `public/`; configure `FAMILIENDMS_CONFIG` to the private config path and `FAMILIENDMS_STORAGE` to a private writable directory.
5. Open `/install.php` over HTTPS, verify prerequisites, and create the first administrator. Remove/disable the installer after successful setup.
6. Sign in at `/login.php`.

The first-run installer will refuse to create another administrator once one exists. Protect the private storage directory with filesystem permissions (for example `0700`, owned by the PHP-FPM service account). Use a separate encrypted backup plan for database and documents.

## Alpha features and boundaries

This release includes first-admin setup, secure login/logout, user listing and creation, and a dashboard. It establishes private storage and upload validation primitives; the document upload UI and document catalogue are intentionally not yet exposed. Keep the application behind HTTPS and a trusted LAN/VPN. There is no password reset, MFA, audit log, or automated migration runner yet.

## Security notes

All database values use PDO prepared statements; passwords use `password_hash()` / `password_verify()`. Session cookies are HttpOnly, SameSite=Lax and Secure when HTTPS is detected; IDs rotate on authentication. State-changing forms require a CSRF token. Uploaded-file foundation validates size, MIME and extension and generates opaque names; never serve stored files directly. Configure `display_errors=Off` in production and review PHP/Web Station logs.

See [docs/synology-deployment.md](docs/synology-deployment.md) and [CHANGELOG.md](CHANGELOG.md).
