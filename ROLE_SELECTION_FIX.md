# Role Selection Fix - Setup Instructions

## Problem Summary
After uploading to cPanel, the login flow was skipping the role selection page. This was because the `role` column in the `users` table had a DEFAULT value of `'viewer'`, so it was never actually NULL.

## Solution Overview
The database schema needed to be updated to:
1. Remove the DEFAULT `'viewer'` from the role column
2. Add support for new roles: `psds`, `sdc`, `unit_head`
3. Add missing columns: `district_id`, `dashboard_tour_completed`, `twofa_*`
4. Allow role to be NULL for role selection flow

## How to Apply the Fix

### Option 1: Automatic Migration (Recommended)

1. **Upload the updated files** from your local machine to cPanel:
   - `migrate.php` (updated)
   - `login.php` (updated)
   - `setup.php` (updated)
   - `select-role.php`
   - `select-district.php`
   - `setup-districts.php`

2. **Run the migration script** in your browser:
   ```
   https://your-domain.com/tpms/migrate.php
   ```
   
3. **Monitor the output** - it will show:
   ✓ Column added successfully (or already exists)
   ✓ Role ENUM updated
   etc.

4. **Delete the migrate.php file** after it completes (for security)

### Option 2: Manual SQL Migration (cPanel phpMyAdmin)

If the automatic migration doesn't work, do this manually:

1. **Go to cPanel > phpMyAdmin**

2. **Select your TPMS database**

3. **Click "SQL" tab** and paste these commands:

```sql
-- Add missing columns
ALTER TABLE users ADD COLUMN IF NOT EXISTS district_id INT UNSIGNED DEFAULT NULL AFTER role;
ALTER TABLE users ADD COLUMN IF NOT EXISTS twofa_enabled TINYINT(1) DEFAULT 0 AFTER is_active;
ALTER TABLE users ADD COLUMN IF NOT EXISTS twofa_secret VARCHAR(64) DEFAULT NULL AFTER twofa_enabled;
ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_tour_completed TINYINT(1) DEFAULT 0 AFTER twofa_secret;

-- Update role ENUM to include new roles and remove DEFAULT
ALTER TABLE users MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL;

-- Add index for performance
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_district_id (district_id);
```

4. **Click "Go"** to execute

## After Migration

### Test the Flow

1. **Log in with a test user** that has role = `'viewer'`
   
2. **Expected behavior:**
   - After login, redirected to `/select-role` page
   - Select one of: psds, sdc, or unit_head
   
3. **For PSDS/SDC users:**
   - After role selection, redirected to `/select-district`
   - Select or setup their district
   
4. **For Unit Head users:**
   - After role selection, redirected to `/onboarding`
   
5. **For Admin/HR users:**
   - Continue to dashboard (no role selection needed)

### Clean Up

1. **Delete** `migrate.php` after migration completes
2. **Delete** `DATABASE_MIGRATION.sql` (no longer needed)
3. **Delete** this file if not needed for reference

## Troubleshooting

### Issue: Role selection still skipped

**Check 1:** Verify the migration ran
- Go to cPanel > phpMyAdmin
- Click SQL tab
- Run: `SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='users' AND COLUMN_NAME='role';`
- Should show: `enum('admin','hr','school_head','viewer','psds','sdc','unit_head')`

**Check 2:** Verify the DEFAULT was removed
- Same query, should show: `NULL` (not 'viewer')

**Check 3:** Check user role in database
- Run: `SELECT id, username, role FROM users LIMIT 10;`
- Look for users with role = 'viewer'
- These users should be prompted to select a role

### Issue: Migration script shows errors

**For role ENUM error:**
- Try manually in phpMyAdmin SQL tab
- Copy the ALTER TABLE statement from the error message
- Paste and execute

**For other errors:**
- Check that your database user has ALTER TABLE permissions
- Contact your hosting provider if permissions are missing

## Files Modified/Added

### Updated Files:
- `login.php` - Fixed role selection flow
- `setup.php` - Updated schema definition
- `database.sql` - Updated schema definition
- `migrate.php` - Comprehensive migration script

### Required Files:
- `select-role.php` - Role selection page
- `select-district.php` - District selection page
- `setup-districts.php` - District setup page
- `includes/auth.php` - Authentication functions
- `includes/functions.php` - Helper functions

## Technical Details

### Login Flow After Fix:

```
Login (username/password)
    ↓
Check if role is NULL/empty
    ↓ YES
Redirect to → select-role.php
    ↓
User selects role (psds, sdc, or unit_head)
    ↓
PSDS/SDC users:
    Redirect to → select-district.php or setup-districts.php
    ↓
All roles check onboarding:
    Redirect to → first-login-setup.php (if needed)
    ↓
Redirect to → dashboard.php
```

### Database Changes:

| Column Name | Old | New |
|------------|-----|-----|
| `role` | `ENUM(...) DEFAULT 'viewer'` | `ENUM(..., 'psds', 'sdc', 'unit_head') DEFAULT NULL` |
| `district_id` | ❌ Missing | ✅ Added |
| `twofa_enabled` | ❌ Missing | ✅ Added |
| `twofa_secret` | ❌ Missing | ✅ Added |
| `dashboard_tour_completed` | ❌ Missing | ✅ Added |

## Need Help?

If issues persist:
1. Check file permissions (755 for directories, 644 for PHP files)
2. Verify database credentials in `config.php`
3. Check that your hosting supports the PHP and MySQL versions required
4. Review error logs in cPanel > Error Log

---

**Last Updated:** 2026-07-12
**Version:** 2.0 (Role Selection Update)
