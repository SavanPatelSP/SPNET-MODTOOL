ALTER TABLE settings
    ADD COLUMN daily_feedback_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN daily_feedback_hour INT NOT NULL DEFAULT 20,
    ADD COLUMN daily_feedback_last_date DATE NULL,
    ADD COLUMN daily_feedback_last_sent_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS mod_goals (
    chat_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    month CHAR(7) NOT NULL,
    messages_target INT NULL,
    active_hours_target DECIMAL(8,2) NULL,
    actions_target INT NULL,
    days_active_target INT NULL,
    score_target DECIMAL(8,2) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (chat_id, user_id, month),
    INDEX idx_mod_goals_user (user_id),
    INDEX idx_mod_goals_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
