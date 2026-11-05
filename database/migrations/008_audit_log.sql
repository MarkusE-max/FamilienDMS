CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    target_user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(48) NOT NULL,
    details_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_log_created (created_at, id),
    KEY ix_audit_log_target (target_user_id, created_at),
    CONSTRAINT fk_audit_log_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
