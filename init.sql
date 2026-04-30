-- 1. Create the Database
CREATE DATABASE IF NOT EXISTS uptime;
USE uptime;

-- 2. Table for the websites you are monitoring
CREATE TABLE IF NOT EXISTS sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(255) NOT NULL,
    alias VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    last_status INT DEFAULT NULL,
    last_health_status VARCHAR(10) DEFAULT 'unknown', -- 'green', 'yellow', 'red'
    pending_alert BOOLEAN DEFAULT 0, -- Tracks alerts delayed due to Quiet Time
    timeout_seconds INT DEFAULT 30,
    retries INT DEFAULT 2,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for historical ping data (if not already created)
CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT,
    status_code INT,
    response_time FLOAT,
    health_status VARCHAR(10), -- 'green', 'yellow', 'red'
    total_attempts INT DEFAULT 1,
    cumulative_time FLOAT, -- total time including retries
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(site_id),
    INDEX(checked_at),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
);

-- 4. Table for App Settings
CREATE TABLE IF NOT EXISTS config (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT
);

-- 5. Seed Initial Config Data
INSERT INTO config (setting_key, setting_value) VALUES 
('telegram_bot_token', ''), 
('telegram_chat_id', ''),
('admin_username', ''),
('admin_password_hash', ''),
('admin_password_salt', ''),
('active_start_hour', '08'),       -- Notifications start at 8 AM
('active_end_hour', '22'),         -- Notifications stop at 10 PM
('timezone', 'Australia/Melbourne'),
('default_timeout', '30'),         -- Default timeout in seconds
('default_retries', '2');          -- Default number of retries