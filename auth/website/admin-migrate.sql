-- Admin dashboard migration
-- Run once on the production database to add signup/login tracking
-- Safe to run multiple times (uses IF NOT EXISTS / column checks)

-- Add created_at to users (defaults to NOW() for existing rows)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS signup_site VARCHAR(100) DEFAULT NULL;

-- Login activity log
CREATE TABLE IF NOT EXISTS login_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    site        VARCHAR(100) NOT NULL DEFAULT '',
    method      ENUM('password','magic') NOT NULL DEFAULT 'password',
    logged_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_logged_in_at (logged_in_at),
    INDEX idx_site (site)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
