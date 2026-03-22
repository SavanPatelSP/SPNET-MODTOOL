ALTER TABLE settings
    ADD COLUMN inactivity_alert_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN inactivity_alert_days INT NOT NULL DEFAULT 7,
    ADD COLUMN inactivity_alert_hour INT NOT NULL DEFAULT 10;
