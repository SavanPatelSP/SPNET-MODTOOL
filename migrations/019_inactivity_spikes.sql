ALTER TABLE settings
    ADD COLUMN inactivity_spike_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN inactivity_spike_hour INT NOT NULL DEFAULT 10,
    ADD COLUMN inactivity_spike_threshold DECIMAL(6,2) NOT NULL DEFAULT 35;
