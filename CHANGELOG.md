# Changelog

## 0.1.0-alpha

- Initial deployable PHP/MariaDB foundation for Synology Web Station.
- First administrator setup, login/logout, user management and dashboard.
- CSRF, secure sessions, prepared statements and private-storage upload primitives.
- Added a private document archive with PDF/JPEG/PNG upload (20 MB limit), title/uploader search, authenticated attachment downloads and owner/admin deletion.
- Added an authenticated inline PDF preview with a download fallback.
- Added authenticated in-browser previews for JPEG and PNG images with the same adjustable preview area.
- Added adjustable PDF preview height, including compact, reading and large sizes.
- Added per-user browser preferences for accent green and font family; no database migration is needed.
- Added administrator-managed nested folders, optional document filing, move controls, and folder/subfolder filtering in the archive.
- Added additive migration `004_folders.sql`; existing documents remain unchanged and appear under “Ohne Ordner” until filed.
- Added a private full-text index queue and scheduled CLI worker for PDF text extraction and optional OCR of scanned PDFs and images.
- Added migration `005_document_text.sql`; existing documents are queued for indexing and search includes recognized document text.
- Added container-based OCR execution: image files can use a running Tesseract container; PDF OCR uses a bounded, isolated OCRmyPDF job without archive volume mounts.
- Added shared document categories, category assignment and archive filtering, plus administrator category management.
- Added additive migrations `002_categories.sql` and `003_document_tags.sql`; existing single-category assignments are copied to the new multi-tag relation.
- Added local OCR-based sender, date and document-type suggestions with category and folder proposals that require user review before saving.
- Added confirmed document metadata fields and additive migration 006_document_suggestions.sql; existing files, OCR text and assignments remain unchanged until a proposal is accepted.
- Added an authenticated Ablagevorschläge page.
- Updated the dashboard with live document counts and added schema checks for the existing documents table.
- Added a password-reset request page with a generic response and administrator-visible request marker; no email service or account enumeration.
- Administrators can set a new password, which invalidates prior sessions, and deactivate/reactivate accounts; the last active administrator and own account cannot be deactivated.
- Added migration 007_account_recovery.sql for reset-request status and authentication versioning.
- Added administrator role editing for user accounts, with a guard against removing the last active administrator.
- Added a dashboard alert for administrators when active users have requested password resets, linking directly to user management.
- Added a signed-in password-change page requiring the current password and confirmation; changing it invalidates other sessions while keeping the current session active.
- Added a mobile-first JPG/PNG photo upload page with rear-camera capture preference and a return path for uploading additional photos; uploads use the existing private, validated document handler.
- Added automatic page rotation and slight-skew correction in the isolated OCRmyPDF path for new photo uploads and scanned PDFs; archived originals are never changed.
- Reworked the document archive into a full-width vertical layout, with upload details above the archive table and responsive stacking on phones.
- Constrained the archive title column and added compact document cards on tablet and phone widths so preview and row actions remain visible without horizontal scrolling.
- Added an administrator-only audit log for account creation, password reset/change requests, password changes, role changes, and account activation/deactivation; no password values, IP addresses or user-agent strings are stored.
- Added migration 008_audit_log.sql and a paginated Änderungsprotokoll page.
- Added a recoverable document trash with owner-scoped restore, administrator-only permanent deletion and audit events.
- Added one-click re-alignment and forced OCR reprocessing from the archive; the scheduled worker performs a more sensitive rotation pass and deskew on a temporary OCR copy while preserving the original.
- Added migration 009_document_recovery_and_reindexing.sql.


## FamilienDMS v0.1 Alpha – manuelle Ausrichtung

- Ergänzt live sichtbare 90°-Drehung für PDF- und Bildvorschauen, inklusive Größensteuerung.
- Eigentümer und Administratoren können eine gewählte Drehung speichern und die OCR-Neuerkennung für den nächsten geplanten Lauf vormerken.
- OCR verarbeitet eine temporäre Kopie; Originaldateien werden nicht geändert. PDFs werden seitenübergreifend rotiert und anschließend weiterhin automatisch leicht begradigt.
- Ergänzt Migration `010_manual_document_rotation.sql` sowie den Drehwinkel im Änderungsprotokoll.
