ALTER TABLE settings
    ADD COLUMN progress_report_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN progress_report_day INT NOT NULL DEFAULT 15,
    ADD COLUMN progress_report_hour INT NOT NULL DEFAULT 12,
    ADD COLUMN progress_report_last_month VARCHAR(7) NULL,
    ADD COLUMN progress_report_last_sent_at DATETIME NULL;
