-- Add shared categories to the existing document archive without changing
-- existing rows. Documents stay uncategorized until a user assigns a category.
CREATE TABLE categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documents
    ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER title,
    ADD KEY ix_documents_category_created (category_id, created_at),
    ADD CONSTRAINT fk_documents_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;
