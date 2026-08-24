-- DATABASE MIGRATION SCRIPT (SQL Version Compatible)
-- Works with: MySQL 5.7+, MySQL 8.0+, MariaDB 10.1+
-- Run this in cPanel > phpMyAdmin to update your existing database
-- This fixes the role selection issue and adds missing columns

-- 1. Add district_id column if it doesn't exist
-- Compatible with all MySQL versions
ALTER TABLE users ADD COLUMN IF NOT EXISTS district_id INT UNSIGNED DEFAULT NULL;

-- 2. Add twofa_enabled column if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS twofa_enabled TINYINT(1) DEFAULT 0;

-- 3. Add twofa_secret column if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS twofa_secret VARCHAR(64) DEFAULT NULL;

-- 4. Add dashboard_tour_completed column if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_tour_completed TINYINT(1) DEFAULT 0;

-- 5. Update the role ENUM to include new roles and remove default
-- Method 1: Using MODIFY (works on most MySQL versions)
ALTER TABLE users MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL;

-- If Method 1 fails, try Method 2: Using CHANGE (also widely supported)
-- ALTER TABLE users CHANGE COLUMN role role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL;

-- 6. Add index for district_id if it doesn't exist
-- This improves query performance when filtering by district
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_district_id (district_id);

-- 7. Verify the changes:
-- SELECT id, username, role, district_id, twofa_enabled, is_active FROM users LIMIT 5;

-- 8. Check role column definition:
-- SHOW COLUMNS FROM users WHERE Field='role';

-- Expected output for role column:
-- Field: role
-- Type: enum('admin','hr','school_head','viewer','psds','sdc','unit_head')
-- Null: YES
-- Key: MUL
-- Default: NULL

