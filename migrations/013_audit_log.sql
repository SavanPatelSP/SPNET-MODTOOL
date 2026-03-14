CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(64) NOT NULL,
    actor_id BIGINT NOT NULL,
    chat_id BIGINT NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_actor (actor_id),
    INDEX idx_audit_chat (chat_id),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
