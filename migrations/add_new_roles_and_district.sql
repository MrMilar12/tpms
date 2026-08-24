-- Migration: Add new roles (PSDS, SDC, Unit Head) and district field to users
-- Run this migration to update the users table

-- Step 1: Modify the role ENUM to include new roles
ALTER TABLE users 
MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT 'viewer';

-- Step 2: Add district_id column to users table
ALTER TABLE users 
ADD COLUMN district_id INT UNSIGNED DEFAULT NULL AFTER role,
ADD CONSTRAINT fk_user_district FOREIGN KEY (district_id)
    REFERENCES districts(id) ON DELETE SET NULL ON UPDATE CASCADE,
ADD INDEX idx_district_id (district_id);

-- Step 3: Add pending_district_id for temporary selection (if role is null during login)
ALTER TABLE users 
ADD COLUMN pending_district_id INT UNSIGNED DEFAULT NULL AFTER district_id,
ADD INDEX idx_pending_district_id (pending_district_id);

-- Step 4: Create user_districts junction table for PSDS/SDC to manage multiple districts
CREATE TABLE IF NOT EXISTS user_districts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    district_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_district (user_id, district_id),
    CONSTRAINT fk_ud_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ud_district FOREIGN KEY (district_id)
        REFERENCES districts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_district_id (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Complete!
