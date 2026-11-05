-- Store confirmed metadata and remember who reviewed OCR-based suggestions.
-- Proposed values are calculated locally from the private OCR index at review time.
ALTER TABLE documents
    ADD COLUMN sender VARCHAR(160) NULL,
    ADD COLUMN document_date DATE NULL,
    ADD COLUMN document_type VARCHAR(80) NULL;

CREATE TABLE document_suggestion_reviews (
    document_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('accepted', 'dismissed') NOT NULL,
    reviewed_by BIGINT UNSIGNED NOT NULL,
    reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id),
    KEY ix_suggestion_reviews_user_date (reviewed_by, reviewed_at),
    CONSTRAINT fk_suggestion_reviews_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_suggestion_reviews_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
