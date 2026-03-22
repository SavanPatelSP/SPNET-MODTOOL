ALTER TABLE settings
    ADD COLUMN ai_review_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN ai_review_day INT NOT NULL DEFAULT 1,
    ADD COLUMN ai_review_hour INT NOT NULL DEFAULT 9,
    ADD COLUMN ai_review_last_month VARCHAR(7) NULL,
    ADD COLUMN ai_review_last_sent_at DATETIME NULL;
