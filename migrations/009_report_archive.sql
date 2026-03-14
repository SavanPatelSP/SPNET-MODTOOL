CREATE TABLE IF NOT EXISTS report_archive (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NULL,
    report_type VARCHAR(32) NOT NULL,
    month VARCHAR(7) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_archive_chat_month (chat_id, month),
    INDEX idx_archive_type (report_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
