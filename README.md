# FamilienDMS v0.1 Alpha

Secure starter application for a small family document archive on Synology DSM 7.4.1, Web Station, PHP 8.2 and MariaDB.

## Requirements

- PHP 8.2 with `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` and sessions enabled
- MariaDB 10.5+ (InnoDB, utf8mb4)
- HTTPS in production
- A private directory outside the Web Station document root, writable by the PHP-FPM user

## Layout

`public/` is the only web root. `app/` contains request handling, upload and storage helpers, `database/migrations/` the schema, `config/` a non-secret example, and `docs/` Synology deployment notes. Never place secrets or uploaded files beneath `public/`.

## Local setup

1. Copy `config/config.example.php` to a private path outside the document root (for example `/volume1/@appdata/familiendms/config.php`) and set DB details via environment variables or that private file. Do not commit the live config.
2. Create a dedicated MariaDB database and user with only `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX` on that database. Revoke DDL rights after migration if desired.
3. Import `database/migrations/001_initial.sql` using an administrative account.
4. Point the Web Station virtual host document root at `public/`; configure `FAMILIENDMS_CONFIG` to the private config path and `FAMILIENDMS_STORAGE` to a private writable directory.
5. Open `/install.php` over HTTPS, verify prerequisites, and create the first administrator. Remove/disable the installer after successful setup.
6. Sign in at `/login.php`.

The first-run installer will refuse to create another administrator once one exists. Protect the private storage directory with filesystem permissions (for example `0700`, owned by the PHP-FPM service account). Use a separate encrypted backup plan for database and documents.

## Alpha features and boundaries

This release includes first-admin setup, secure login/logout, user listing and creation, a dashboard, and a shared document archive. Signed-in family members can upload PDF, JPEG and PNG files up to 20 MB, assign documents to shared categories, filter by category, search titles, uploader and category names, and download private files. Document owners and administrators can also categorize existing documents. Administrators can add, rename and remove categories. Removing a category leaves its documents intact and uncategorized. PDFs also have an authenticated browser preview with adjustable height. In Gestaltung, each user can select an accent green and font; these preferences are stored in that user's browser. An uploader can remove their own documents; an administrator can remove any document. Downloads require an active session and are sent as attachments. Files are stored outside the web root with opaque generated names. Keep the application behind HTTPS and a trusted LAN/VPN. There is no password reset, MFA, audit log, image preview, OCR, malware scanning, or automated migration runner yet.

The `002_categories.sql` migration adds the shared categories table and nullable document category link. Existing documents are preserved and remain uncategorized after migration. Apply migrations in order on a new installation.

## Security notes

All database values use PDO prepared statements; passwords use `password_hash()` / `password_verify()`. Session cookies are HttpOnly, SameSite=Lax and Secure when HTTPS is detected; IDs rotate on authentication. State-changing forms require a CSRF token. Uploaded-file foundation validates size, MIME and extension and generates opaque names; never serve stored files directly. Configure `display_errors=Off` in production and review PHP/Web Station logs.

See [docs/synology-deployment.md](docs/synology-deployment.md) and [CHANGELOG.md](CHANGELOG.md).
