CREATE TABLE IF NOT EXISTS mod_roster (
    chat_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    role VARCHAR(50) NOT NULL,
    notes VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (chat_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
