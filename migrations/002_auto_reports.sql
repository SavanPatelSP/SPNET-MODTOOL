ALTER TABLE settings
    ADD COLUMN auto_report_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN auto_report_day INT NOT NULL DEFAULT 1,
    ADD COLUMN auto_report_hour INT NOT NULL DEFAULT 9,
    ADD COLUMN auto_report_last_month VARCHAR(7) NULL,
    ADD COLUMN auto_report_last_sent_at DATETIME NULL;
