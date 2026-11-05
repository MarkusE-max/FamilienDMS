-- Allow any document to have multiple categories/tags. Preserve the existing
-- single category relationship by copying it into the new join table.
CREATE TABLE document_categories (
    document_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id, category_id),
    KEY ix_document_categories_category (category_id, document_id),
    CONSTRAINT fk_document_categories_document
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_categories_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO document_categories (document_id, category_id)
SELECT id, category_id FROM documents WHERE category_id IS NOT NULL;
