CREATE TABLE IF NOT EXISTS reward_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    month VARCHAR(7) NOT NULL,
    user_id BIGINT NOT NULL,
    rank INT NOT NULL,
    score DECIMAL(12,2) NOT NULL,
    reward DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_reward_history (chat_id, month, user_id),
    INDEX idx_reward_history_chat_month (chat_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
