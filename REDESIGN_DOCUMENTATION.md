# TPMS User Management System - Redesign Documentation

## Overview
This document describes the comprehensive redesign of the TPMS user management system with new role-based district filtering and enhanced UI/UX.

## Changes Made

### 1. **Database Schema Updates** (`migrations/add_new_roles_and_district.sql`)

#### New Roles Added
- **PSDS** - Public Schools Division Supervisor (manages provincial level)
- **SDC** - Schools Division Coordinator (manages division level)
- **Unit Head** - Unit Head (manages specific unit)

#### Schema Changes
```sql
-- Modified users.role ENUM to include: 'psds', 'sdc', 'unit_head'
ALTER TABLE users 
MODIFY COLUMN role ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head');

-- Added district_id field to users table
ALTER TABLE users 
ADD COLUMN district_id INT UNSIGNED DEFAULT NULL;

-- Created user_districts junction table for PSDS/SDC multi-district management
CREATE TABLE user_districts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    district_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_district (user_id, district_id),
    CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_ud_district FOREIGN KEY (district_id) REFERENCES districts(id)
);
```

**To apply these changes:**
```bash
mysql -u root -p tpms < migrations/add_new_roles_and_district.sql
```

### 2. **Enhanced User Management Page** (`users.php`)

#### New Features
- **Modern Card & List Views** - Switch between grid and table layouts
- **Role-Based District Assignment** - PSDS/SDC/Unit Head users can manage multiple districts
- **Color-Coded Roles** - Visual distinction for each role type
- **Improved Form Layout** - Sectioned form with clear organization
- **District Management UI** - Multi-select checkboxes for district assignment

#### UI Enhancements
- Glass-morphism design with gradient backgrounds
- Smooth animations and transitions
- Responsive grid layout
- Better visual hierarchy with color-coded badges
- Improved accessibility with better labels and ARIA attributes

#### New Role Badges
```
🔴 Administrator - Full system access (Red: #ef4444)
🔵 HR / Personnel - Teachers & staff management (Blue: #3b82f6)
🟣 School Head - School operations (Purple: #8b5cf6)
🔷 Unit Head - Unit operations (Cyan: #06b6d4)
🩷 PSDS - Public supervisor (Pink: #ec4899)
🟧 SDC - Division coordinator (Orange: #f59e0b)
⚫ Viewer - Read-only access (Gray: #6b7280)
```

### 3. **Login System with District Selection** (`login.php` & `select-district.php`)

#### New District Selection Flow
After successful authentication, users with PSDS/SDC/Unit Head roles are presented with:

1. **Auto-Selection** - If assigned to 1 district, it's automatically selected
2. **Selection Modal** - If assigned to multiple districts, user selects which district to work with
3. **Session Storage** - Selected district stored in `$_SESSION['selected_district_id']`

#### New Page: `select-district.php`
Beautiful, modern district selection interface with:
- User information display
- Radio button district selection with rich styling
- Smooth animations and transitions
- Option to switch accounts (logout)
- Responsive mobile design

### 4. **Helper Functions** (`includes/functions.php`)

#### New Functions Added

```php
// Get all districts assigned to a user
function getUserDistricts(PDO $db, int $userId): array

// Check if user can access a specific district
function userCanAccessDistrict(PDO $db, int $userId, int $districtId): bool

// Get user by ID
function getUserById(PDO $db, int $userId): ?array

// Get role display name
function getRoleDisplayName(string $role): string

// Get selected district from session
function getSessionDistrict(): ?int

// Set selected district in session
function setSessionDistrict(int $districtId): void

// Clear selected district from session
function clearSessionDistrict(): void
```

## Implementation Guide

### Step 1: Apply Database Migration
```bash
mysql -u root -p tpms < /xampp/htdocs/tpms/migrations/add_new_roles_and_district.sql
```

### Step 2: Add Users with New Roles
In the Users management interface:
1. Click "Add User"
2. Select role (PSDS, SDC, or Unit Head)
3. Choose assigned districts from the multi-select
4. Save user

### Step 3: District Filtering (For Developers)
To implement district-based data filtering throughout the application:

```php
// Get current user's accessible district
$districtId = getSessionDistrict();

// Verify access before querying
if (!userCanAccessDistrict($db, currentUser()['id'], $districtId)) {
    flash('error', 'Access denied');
    redirect(APP_URL . '/dashboard');
}

// Query only data for selected district
$stmt = $db->prepare('SELECT * FROM schools WHERE district_id = ?');
$stmt->execute([$districtId]);
```

## UI/UX Improvements

### Visual Enhancements
- **Glass-Morphism Design** - Frosted glass effect with backdrop blur
- **Gradient Backgrounds** - Modern color gradients for visual appeal
- **Color Coding** - Consistent color scheme for roles and status
- **Smooth Animations** - Fade-in, slide-in effects for better UX
- **Better Typography** - Improved font hierarchy and spacing
- **Icon Integration** - FontAwesome icons throughout for visual cues

### Interaction Patterns
- **Modal Dialogs** - Modern modal for adding/editing users
- **Form Sections** - Grouped form inputs with visual separators
- **Hover Effects** - Interactive elements with visual feedback
- **Loading States** - Disabled states for buttons and forms
- **Responsive Design** - Mobile-friendly layouts

## File Structure
```
├── migrations/
│   └── add_new_roles_and_district.sql    # Database migration
├── users.php                               # Redesigned user management
├── select-district.php                     # District selection page
├── login.php                               # Enhanced login with district logic
├── login_old.php                           # Backup of original login
├── users_old.php                           # Backup of original users
└── includes/
    └── functions.php                       # New helper functions added
```

## Migration Checklist

- [ ] Backup current database
- [ ] Run SQL migration
- [ ] Test user creation with new roles
- [ ] Test login flow with district selection
- [ ] Verify district-based data filtering
- [ ] Test role-based access control
- [ ] Update other modules to respect selected district
- [ ] Train staff on new interface

## Security Considerations

1. **CSRF Protection** - All forms use CSRF tokens
2. **Password Verification** - Sensitive operations require password confirmation
3. **Session Validation** - District selection stored securely in session
4. **Access Control** - `userCanAccessDistrict()` prevents unauthorized access
5. **SQL Injection Prevention** - All queries use prepared statements
6. **Role Validation** - Role enums prevent invalid role assignment

## Performance Notes

- `user_districts` table indexed on `(user_id, district_id)` for fast lookups
- `users.district_id` indexed for quick filtering
- District queries optimized with LIMIT statements
- Session-based district storage avoids repeated DB queries

## Future Enhancements

1. **District Transfer** - Allow users to request different district
2. **Multi-District Dashboard** - Overview across managed districts
3. **Audit Logging** - Track district selection and access
4. **District-Level Reports** - Segregated reporting by district
5. **Bulk Operations** - District-scoped bulk actions
6. **API Integration** - REST API for district filtering

## Testing Recommendations

### Unit Tests
- [ ] Role validation
- [ ] District assignment logic
- [ ] Permission checking functions
- [ ] Session management

### Integration Tests
- [ ] User creation with multiple districts
- [ ] Login and district selection flow
- [ ] District-based data filtering
- [ ] Role-based access control

### UI/UX Tests
- [ ] Form validation messages
- [ ] Mobile responsiveness
- [ ] Accessibility (keyboard navigation, screen readers)
- [ ] Cross-browser compatibility

## Support & Troubleshooting

### Common Issues

**Issue: Users can't select district after login**
- Solution: Verify `user_districts` table has records for that user

**Issue: District filter not working**
- Solution: Check if queries include `getSessionDistrict()` in WHERE clause

**Issue: Role dropdown not showing new roles**
- Solution: Verify database migration was applied successfully

## Rollback Instructions

If needed to rollback:
```bash
# Restore from backup
mysql -u root -p tpms < backup.sql

# Restore old PHP files
cp users_old.php users.php
cp login_old.php login.php
```

---

**Last Updated:** 2026-07-12  
**Version:** 1.0  
**Status:** Released
