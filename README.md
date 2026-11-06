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
3. Import `database/migrations/001_initial.sql`, `002_categories.sql`, `003_document_tags.sql`, `004_folders.sql`, `005_document_text.sql`, `006_document_suggestions.sql`, `007_account_recovery.sql`, `008_audit_log.sql` and `009_document_recovery_and_reindexing.sql` in order using an administrative account.
4. Point the Web Station virtual host document root at `public/`; configure `FAMILIENDMS_CONFIG` to the private config path and `FAMILIENDMS_STORAGE` to a private writable directory.
5. Open `/install.php` over HTTPS, verify prerequisites, and create the first administrator. Remove/disable the installer after successful setup.
6. Sign in at `/login.php`.

The first-run installer will refuse to create another administrator once one exists. Protect the private storage directory with filesystem permissions (for example `0700`, owned by the PHP-FPM service account). Use a separate encrypted backup plan for database and documents.

## Alpha features and boundaries

This release includes first-admin setup, secure login/logout, user listing and creation, a dashboard, and a shared document archive. Signed-in family members can upload PDF, JPEG and PNG files up to 20 MB, assign up to 30 tags, and file documents in nested folders. The archive can filter by a folder (including its subfolders) or tag, and search titles, uploader, tags, folder names and indexed document text. A scheduled CLI worker extracts text from PDFs and runs OCR on scanned PDF pages and uploaded images. When configured with OCRmyPDF Docker settings, it detects page orientation and corrects slight skew before OCR. Document owners and administrators can move their documents and change tags. Administrators can manage folders and tags; non-empty folders cannot be deleted. After OCR, local rules suggest sender, document date, document type, category and folder; users review and confirm suggestions before metadata or filing changes are saved. PDFs and images have authenticated browser previews with adjustable size. In Gestaltung, each user can select an accent green and font; these preferences are stored in that user's browser. An uploader can remove their own documents; an administrator can remove any document. Downloads require an active session and are sent as attachments. Files are stored outside the web root with opaque generated names. Keep the application behind HTTPS and a trusted LAN/VPN. Password reset requests are handled by an administrator; administrators can set a new password and deactivate accounts. Requests do not send email. An administrator-only audit log records account creation, password changes and resets, role changes, reset requests, and account activation/deactivation. It does not store passwords or network addresses.

The `002_categories.sql` migration adds shared categories. `003_document_tags.sql` adds a many-to-many link so each document can have multiple tags and copies existing category assignments into it. `004_folders.sql` adds nested folders and an optional folder assignment on documents. `005_document_text.sql` queues existing documents and creates the private full-text index. `006_document_suggestions.sql` adds confirmed metadata fields and review records without changing existing files or assignments. Apply migrations in order on a new installation; existing documents and assignments are preserved. On an existing installation that already has migration 006, import only migration 007 once.

Text extraction runs from `scripts/index_documents.php` in the background, not during upload. The worker uses a one-shot OCRmyPDF container for configured image and PDF OCR, without network access or mounting the private archive. Orientation and slight skew correction run on a temporary copy; originals remain unchanged. Container Manager and Docker CLI access are required for the scheduled task. See the Synology setup guide for the one-time migration, image setup, worker command and scheduled task.

## Security notes

All database values use PDO prepared statements; passwords use `password_hash()` / `password_verify()`. Session cookies are HttpOnly, SameSite=Lax and Secure when HTTPS is detected; IDs rotate on authentication. State-changing forms require a CSRF token. Uploaded-file foundation validates size, MIME and extension and generates opaque names; never serve stored files directly. Configure `display_errors=Off` in production and review PHP/Web Station logs.

See [docs/synology-deployment.md](docs/synology-deployment.md) and [CHANGELOG.md](CHANGELOG.md).


## Account and mobile upload updates

Signed-in users can change their password under Passwort. A current password is required; other active sessions are revoked. On a phone, use the Handy-Foto page to take or select a JPG or PNG image up to 20 MB. Each photo is submitted through the same authenticated, CSRF-protected upload handler and private storage validation as the document archive. The mobile form uploads one photo at a time.

### Änderungsprotokoll

Administratoren finden im Seitenmenü das Änderungsprotokoll. Es zeigt Benutzeraktionen chronologisch und seitenweise an. Neue Installationen benötigen Migrationen 001 bis 008. Bei einer bestehenden Installation mit Migration 007 muss `database/migrations/008_audit_log.sql` genau einmal importiert werden.


## Papierkorb und gezielte Neuerkennung

Dokumente werden beim Entfernen zunächst in den Papierkorb verschoben. Nutzer können eigene Dateien wiederherstellen; endgültiges Löschen ist Administratoren vorbehalten. Migration `009_document_recovery_and_reindexing.sql` ergänzt die dafür nötigen Felder und die Warteschlangenoption für erzwungene OCR.

Im Archiv kann der Eigentümer oder ein Administrator **Ausrichten & erneut erkennen** auswählen. Der nächste geplante OCR-Lauf liest das Original erneut ein, versucht eine stärkere automatische Drehung und korrigiert die Schräglage. Das Original wird nicht verändert. Ein niedrigerer Drehungsschwellenwert kann bei manchen Seiten zu einer falschen Drehung führen; bitte das Original anschließend in der Vorschau prüfen.

### Manuelle Dokumentausrichtung

In der PDF- oder Bildvorschau kann der Eigentümer bzw. ein Administrator die Seite live in 90°-Schritten drehen und die Vorschaugröße anpassen. **Drehung speichern & OCR erneut starten** speichert die gewählte Vierteldrehung und stellt die Neuerkennung für den nächsten geplanten OCR-Lauf ein. Bei PDFs wird die Drehung auf alle Seiten angewendet; bei Bildern auf das einzelne Bild. Das Archivoriginal bleibt unverändert. Eine leichte Schräglage wird weiterhin automatisch korrigiert. Migration `010_manual_document_rotation.sql` ergänzt die Drehungseinstellung.

Für ein Update Migrationen 008, 009 und danach 010 jeweils einmal in dieser Reihenfolge ausführen, sofern sie noch nicht importiert wurden. Die vorhandenen Installationsschritte und Datenbankdaten bleiben dabei bestehen.

