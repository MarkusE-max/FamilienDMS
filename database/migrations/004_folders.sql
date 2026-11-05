-- Add a hierarchical folder tree without changing existing documents or tags.
CREATE TABLE folders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folders_parent_name (parent_id, name),
    CONSTRAINT fk_folders_parent FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE SET NULL,
    CONSTRAINT fk_folders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE documents
    ADD COLUMN folder_id BIGINT UNSIGNED NULL AFTER title,
    ADD KEY ix_documents_folder_created (folder_id, created_at),
    ADD CONSTRAINT fk_documents_folder
        FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL;
