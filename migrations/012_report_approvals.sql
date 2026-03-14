ALTER TABLE settings
    ADD COLUMN approval_required TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS report_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    month VARCHAR(7) NOT NULL,
    report_type VARCHAR(32) NOT NULL DEFAULT 'reward',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    approved_by BIGINT NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_report (chat_id, month, report_type),
    INDEX idx_report_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
