CREATE TABLE IF NOT EXISTS notification_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    notification_type VARCHAR(32) NOT NULL,
    period VARCHAR(7) NULL,
    sent_at DATETIME NOT NULL,
    INDEX idx_notif_chat_type_period (chat_id, notification_type, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
