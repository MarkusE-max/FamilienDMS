# Synology DSM 7.4.1 deployment

1. Install/enable Web Station, PHP 8.2 and MariaDB. Enable `pdo_mysql`, `mbstring` and `fileinfo` in the PHP profile. Select PHP 8.2 for the virtual host.
2. Upload the repository to a directory such as `/volume1/web/familiendms`. Set the virtual host's document root to `/volume1/web/familiendms/public`, never the project root.
3. Create private directories outside `/volume1/web`, for example `/volume1/@appdata/familiendms` and `/volume1/@appdata/familiendms/storage`. Grant access only to the Web Station PHP-FPM service identity and administrators.
4. Copy `config/config.example.php` to `/volume1/@appdata/familiendms/config.php`. Set the environment variable `FAMILIENDMS_CONFIG` to its absolute path and `FAMILIENDMS_STORAGE` to the private storage directory in the PHP profile. Set DB values through the profile/environment where possible. If config values are edited directly, keep the file mode restricted (0600).
5. In MariaDB, create a database with `utf8mb4` and a dedicated app account restricted to localhost and that database. Run `database/migrations/001_initial.sql`, `002_categories.sql` and `003_document_tags.sql` in order using an administrator. The last migration preserves existing category assignments as tags. The app only needs `SELECT, INSERT, UPDATE, DELETE` for normal operation; revoke temporary DDL grants after the imports. Never grant global privileges.
6. Enable HTTPS in the reverse proxy/virtual host. Run `/install.php`, create the initial admin, then remove or deny Web Station access to `public/install.php`.
7. Confirm storage is not web accessible, test login, and configure scheduled encrypted backups for database and private files. Use DSM firewall/VPN controls to limit access.

The document archive accepts PDF, JPEG and PNG files up to 20 MB. In the PHP 8.2 profile, set `upload_max_filesize` to at least `20M` and `post_max_size` to at least `24M`. `002_categories.sql` adds shared tags; `003_document_tags.sql` lets each document have multiple tags and migrates existing category assignments. Run each migration once and in order. Uploaded files remain in the configured private storage directory and are served only through authenticated preview/download endpoints. PDFs and images can be previewed in the browser; preview height can be set to compact, reading or large and adjusted by dragging its lower edge. Each user can also choose their accent green and font under Gestaltung; these choices are kept in that user's browser. Up to 30 tags can be assigned during upload or afterward by a document owner/administrator and used to filter or search the archive. Administrators can create, rename and remove tags; removing one leaves its documents intact.

## Operational checks

- Set production PHP `display_errors=Off`; retain server-side error logging with restricted access.
- Keep PHP and MariaDB patched; rotate credentials using the private config/environment.
- Restore-test backups. Database and file storage must be backed up together.
- Uploads are restricted to PDF/JPEG/PNG and a 20 MB application limit. Keep the storage directory outside the document root and ensure it is included in encrypted backups. The application does not scan files for malware; downloads are forced to attachment disposition, while PDFs and raster images have authenticated inline previews.
