ALTER TABLE settings
    ADD COLUMN weekly_summary_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN weekly_summary_weekday INT NOT NULL DEFAULT 1,
    ADD COLUMN weekly_summary_hour INT NOT NULL DEFAULT 10,
    ADD COLUMN weekly_summary_last_week VARCHAR(10) NULL,
    ADD COLUMN weekly_summary_last_sent_at DATETIME NULL;
