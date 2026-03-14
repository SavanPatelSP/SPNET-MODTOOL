CREATE TABLE IF NOT EXISTS external_user_stats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    source VARCHAR(32) NOT NULL,
    month VARCHAR(7) NOT NULL,
    messages INT NOT NULL DEFAULT 0,
    replies INT NOT NULL DEFAULT 0,
    reputation_take INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_external_user_stats (chat_id, user_id, source, month),
    INDEX idx_external_user_stats_chat_month (chat_id, month),
    INDEX idx_external_user_stats_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
