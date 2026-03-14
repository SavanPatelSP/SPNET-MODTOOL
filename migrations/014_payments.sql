CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    method VARCHAR(16) NOT NULL,
    amount DECIMAL(18,6) NOT NULL DEFAULT 0,
    currency VARCHAR(16) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'test',
    plan VARCHAR(16) NULL,
    days INT NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_payments_chat (chat_id),
    INDEX idx_payments_user (user_id),
    INDEX idx_payments_method (method),
    INDEX idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
