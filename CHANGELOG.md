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
- Added shared document categories, category assignment and archive filtering, plus administrator category management.
- Added additive migrations `002_categories.sql` and `003_document_tags.sql`; existing single-category assignments are copied to the new multi-tag relation.
- Updated the dashboard with live document counts and added schema checks for the existing documents table.
