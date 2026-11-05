-- Queue every existing document for private text extraction and OCR indexing.
CREATE TABLE document_text (
    document_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'processing', 'indexed', 'empty', 'failed') NOT NULL DEFAULT 'pending',
    content LONGTEXT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lease_until DATETIME NULL,
    error_code VARCHAR(40) NULL,
    indexed_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id),
    KEY ix_document_text_queue (status, lease_until, updated_at),
    FULLTEXT KEY ft_document_text_content (content),
    CONSTRAINT fk_document_text_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO document_text (document_id, status)
SELECT id, 'pending' FROM documents;
