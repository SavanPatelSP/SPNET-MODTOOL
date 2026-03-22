ALTER TABLE settings
    ADD COLUMN retention_alert_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN retention_alert_day INT NOT NULL DEFAULT 2,
    ADD COLUMN retention_alert_hour INT NOT NULL DEFAULT 10,
    ADD COLUMN retention_threshold DECIMAL(6,2) NOT NULL DEFAULT 30,
    ADD COLUMN retention_alert_last_month VARCHAR(7) NULL,
    ADD COLUMN retention_alert_last_sent_at DATETIME NULL;
