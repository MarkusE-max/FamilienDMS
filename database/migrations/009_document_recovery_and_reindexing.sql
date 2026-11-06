ALTER TABLE documents
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN deleted_by BIGINT UNSIGNED NULL,
    ADD KEY ix_documents_deleted_created (deleted_at, created_at),
    ADD CONSTRAINT fk_documents_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE document_text
    ADD COLUMN force_ocr TINYINT(1) NOT NULL DEFAULT 0;
