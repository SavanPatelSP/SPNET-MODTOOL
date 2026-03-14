CREATE TABLE IF NOT EXISTS user_settings (
    user_id BIGINT PRIMARY KEY,
    default_chat_id BIGINT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_user_settings_default_chat (default_chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
