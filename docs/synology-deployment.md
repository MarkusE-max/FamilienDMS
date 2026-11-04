# Synology DSM 7.4.1 deployment

1. Install/enable Web Station, PHP 8.2 and MariaDB. Enable `pdo_mysql`, `mbstring` and `fileinfo` in the PHP profile. Select PHP 8.2 for the virtual host.
2. Upload the repository to a directory such as `/volume1/web/familiendms`. Set the virtual host's document root to `/volume1/web/familiendms/public`, never the project root.
3. Create private directories outside `/volume1/web`, for example `/volume1/@appdata/familiendms` and `/volume1/@appdata/familiendms/storage`. Grant access only to the Web Station PHP-FPM service identity and administrators.
4. Copy `config/config.example.php` to `/volume1/@appdata/familiendms/config.php`. Set the environment variable `FAMILIENDMS_CONFIG` to its absolute path and `FAMILIENDMS_STORAGE` to the private storage directory in the PHP profile. Set DB values through the profile/environment where possible. If config values are edited directly, keep the file mode restricted (0600).
5. In MariaDB, create a database with `utf8mb4` and a dedicated app account restricted to localhost and that database. Grant only `SELECT, INSERT, UPDATE, DELETE` for normal operation; run `database/migrations/001_initial.sql` using an administrator, then revoke any temporary DDL grants. Never grant global privileges.
6. Enable HTTPS in the reverse proxy/virtual host. Run `/install.php`, create the initial admin, then remove or deny Web Station access to `public/install.php`.
7. Confirm storage is not web accessible, test login, and configure scheduled encrypted backups for database and private files. Use DSM firewall/VPN controls to limit access.

## Operational checks

- Set production PHP `display_errors=Off`; retain server-side error logging with restricted access.
- Keep PHP and MariaDB patched; rotate credentials using the private config/environment.
- Restore-test backups. Database and file storage must be backed up together.
- Do not use the upload foundation until the UI is wired to it and authorization, CSRF, quota, download streaming and malware scanning policy are reviewed.
