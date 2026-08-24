<?php
$pageTitle = 'Dashboard';

// ── Handle AJAX request to mark tour as complete (BEFORE header) ─
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_tour') {
    header('Content-Type: application/json');
    
    // Start session to access user info
    require_once __DIR__ . '/includes/auth.php';
    startSecureSession();
    
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    try {
        $db = getDB();
        $user = currentUser();
        $userId = (int)($user['id'] ?? 0);
        
        if ($userId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        
        // Update the tour completed flag
        $stmt = $db->prepare("UPDATE users SET dashboard_tour_completed = 1, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$userId]);
        
        // Log for debugging
        error_log("Tour completion: User $userId, Update result: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        if ($result) {
            // Verify the update was actually saved
            $verify = $db->prepare("SELECT dashboard_tour_completed FROM users WHERE id = ?");
            $verify->execute([$userId]);
            $newStatus = $verify->fetchColumn();
            
            error_log("Tour completion verified: User $userId, New status: $newStatus");
            
            echo json_encode(['success' => true, 'message' => 'Tour marked as complete', 'verified_status' => (int)$newStatus]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database update failed', 'rowsAffected' => $stmt->rowCount()]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        error_log("Tour completion error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Now include header
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

// ── Check if user has completed dashboard tour ──────────────
$db = getDB();
$user = currentUser();
$userId = (int)($user['id'] ?? 0);

// Fetch current tour status
$tourCompleted = false;
if ($userId > 0) {
    $stmt = $db->prepare("SELECT dashboard_tour_completed FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $tourStatus = $stmt->fetchColumn();
    $tourCompleted = (bool)$tourStatus;
    
    // Debug: Log tour status (uncomment to debug)
    // error_log("User ID: $userId, Tour Status (raw): " . var_export($tourStatus, true) . ", Tour Completed: " . ($tourCompleted ? 'YES' : 'NO'));
}

// ── Stats ────────────────────────────────────────────────────
$db = getDB();

// Build district filter clause
$districtFilter = '';
if (shouldFilterByDistrict()) {
    $districtId = getSessionDistrict();
    $districtFilter = " AND t.school_id IN (SELECT id FROM schools WHERE district_id = " . (int)$districtId . ")";
}

$totalTeachers  = $db->query("SELECT COUNT(*) FROM teachers t WHERE 1=1 $districtFilter")->fetchColumn();
$totalSchools   = $db->query("SELECT COUNT(*) FROM schools WHERE 1=1" . (shouldFilterByDistrict() ? " AND district_id = " . (int)getSessionDistrict() : ""))->fetchColumn();
$totalDistricts = shouldFilterByDistrict() ? 1 : $db->query('SELECT COUNT(*) FROM districts')->fetchColumn();
$pwdCount       = $db->query("SELECT COUNT(*) FROM teachers t WHERE LOWER(pwd_status) IN ('yes','pwd','1','true') $districtFilter")->fetchColumn();

$retirementWatchBaseWhere = "t.birthdate IS NOT NULL
       AND t.birthdate <> '0000-00-00'
       AND TIMESTAMPDIFF(YEAR, t.birthdate, CURDATE()) BETWEEN 59 AND 60 $districtFilter";

$retirementWatchSummary = $db->query(
    "SELECT
        COUNT(*) AS total_watch,
        SUM(CASE WHEN TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 60 YEAR)) BETWEEN 0 AND 12 THEN 1 ELSE 0 END) AS due_12
     FROM teachers t
     WHERE $retirementWatchBaseWhere"
)->fetch() ?: ['total_watch' => 0, 'due_12' => 0];

$retirementWatchRows = $db->query(
    "SELECT
        t.id,
        t.first_name,
        t.last_name,
        t.birthdate,
        t.position,
        COALESCE(s.school_name, t.school_name_raw, 'Unassigned') AS school_name,
        TIMESTAMPDIFF(YEAR, t.birthdate, CURDATE()) AS age_years,
        TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 60 YEAR)) AS months_until_60
     FROM teachers t
     LEFT JOIN schools s ON t.school_id = s.id
     WHERE $retirementWatchBaseWhere
     ORDER BY months_until_60 ASC, t.last_name ASC, t.first_name ASC
     LIMIT 15"
)->fetchAll();

$retirementWatchCount = (int)($retirementWatchSummary['total_watch'] ?? 0);
$retireWithin12Months = (int)($retirementWatchSummary['due_12'] ?? 0);

$formatRetirementMonths = static function(int $months): string {
    if ($months === 0) {
        return 'Turns 60 this month';
    }
    $abs = abs($months);
    $years = intdiv($abs, 12);
    $remMonths = $abs % 12;
    $parts = [];
    if ($years > 0) {
        $parts[] = $years . ' year' . ($years !== 1 ? 's' : '');
    }
    if ($remMonths > 0 || $years === 0) {
        $parts[] = $remMonths . ' month' . ($remMonths !== 1 ? 's' : '');
    }
    $text = implode(' ', $parts);
    return $months > 0 ? ($text . ' to age 60') : ('Past 60 by ' . $text);
};

$schoolsWithTeachers = (int)$db->query(
    'SELECT COUNT(*)
     FROM schools s
     WHERE EXISTS (
         SELECT 1
         FROM teachers t
         WHERE t.school_id = s.id
            OR (
                t.school_id IS NULL
                AND s.school_id_code IS NOT NULL
                AND TRIM(s.school_id_code) <> ""
                AND t.school_id_code_raw IS NOT NULL
                AND TRIM(t.school_id_code_raw) <> ""
                AND LOWER(TRIM(t.school_id_code_raw)) = LOWER(TRIM(s.school_id_code))
            )
            OR (
                t.school_id IS NULL
                AND t.school_name_raw IS NOT NULL
                AND TRIM(t.school_name_raw) <> ""
                AND LOWER(TRIM(t.school_name_raw)) = LOWER(TRIM(s.school_name))
            )
     )'
)->fetchColumn();
$schoolsWithoutTeachers = max(0, (int)$totalSchools - $schoolsWithTeachers);
$schoolCoveragePct = $totalSchools > 0 ? round(($schoolsWithTeachers / $totalSchools) * 100, 1) : 0;

$schoolCols = [];
foreach ($db->query('SHOW COLUMNS FROM schools')->fetchAll() as $colMeta) {
    $schoolCols[] = $colMeta['Field'];
}
$hasLearnersPerTeacher = in_array('learners_per_teacher', $schoolCols, true);

// Gender breakdown
$genderData = $db->query(
    "SELECT
        CASE
            WHEN LOWER(TRIM(COALESCE(gender, ''))) IN ('male', 'm') THEN 'Male'
            WHEN LOWER(TRIM(COALESCE(gender, ''))) IN ('female', 'f') THEN 'Female'
            ELSE 'Not Set'
        END AS gender,
        COUNT(*) as cnt
     FROM teachers
     GROUP BY gender
     ORDER BY cnt DESC"
)->fetchAll();

// Teachers per district
$districtData = $db->query(
    'SELECT
        CASE
            WHEN LOWER(TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name))) LIKE "% district"
                THEN TRIM(SUBSTRING(TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name)), 1, CHAR_LENGTH(TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name))) - 9))
            ELSE TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name))
        END AS district,
        COUNT(t.id) AS cnt
     FROM teachers t
     LEFT JOIN schools s ON t.school_id = s.id
     LEFT JOIN districts d ON s.district_id = d.id
     WHERE COALESCE(NULLIF(TRIM(t.district_raw), ""), TRIM(d.district_name)) IS NOT NULL
       AND COALESCE(NULLIF(TRIM(t.district_raw), ""), TRIM(d.district_name)) <> ""
     GROUP BY
        CASE
            WHEN LOWER(TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name))) LIKE "% district"
                THEN TRIM(SUBSTRING(TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name)), 1, CHAR_LENGTH(TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name))) - 9))
            ELSE TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name))
        END
     ORDER BY cnt DESC
     LIMIT 10'
)->fetchAll();

foreach ($districtData as &$districtRow) {
    $districtRow['district'] = strtoupper((string)($districtRow['district'] ?? ''));
}
unset($districtRow);

// Position breakdown
$positionData = $db->query(
    'SELECT position, COUNT(*) as cnt FROM teachers
     WHERE position IS NOT NULL AND position != ""
     GROUP BY position ORDER BY cnt DESC LIMIT 8'
)->fetchAll();

// School teacher need (top shortages)
$schoolNeedQuery = $hasLearnersPerTeacher
    ? "SELECT
          s.school_name,
          GREATEST(0, CEIL(COALESCE(s.learner_count, 0) / NULLIF(COALESCE(s.learners_per_teacher, 35), 0)) - COUNT(t.id)) AS teacher_need
      FROM schools s
      LEFT JOIN teachers t ON (
          t.school_id = s.id
          OR (
              t.school_id IS NULL
              AND s.school_id_code IS NOT NULL
              AND TRIM(s.school_id_code) <> ''
              AND t.school_id_code_raw IS NOT NULL
              AND TRIM(t.school_id_code_raw) <> ''
              AND LOWER(TRIM(t.school_id_code_raw)) = LOWER(TRIM(s.school_id_code))
          )
          OR (
              t.school_id IS NULL
              AND t.school_name_raw IS NOT NULL
              AND TRIM(t.school_name_raw) <> ''
              AND LOWER(TRIM(t.school_name_raw)) = LOWER(TRIM(s.school_name))
          )
      )
      GROUP BY s.id, s.school_name, s.learner_count, s.learners_per_teacher
      HAVING teacher_need > 0
      ORDER BY teacher_need DESC, s.school_name ASC
      LIMIT 10"
    : "SELECT
          s.school_name,
          GREATEST(0, CEIL(COALESCE(s.learner_count, 0) / 35) - COUNT(t.id)) AS teacher_need
      FROM schools s
      LEFT JOIN teachers t ON (
          t.school_id = s.id
          OR (
              t.school_id IS NULL
              AND s.school_id_code IS NOT NULL
              AND TRIM(s.school_id_code) <> ''
              AND t.school_id_code_raw IS NOT NULL
              AND TRIM(t.school_id_code_raw) <> ''
              AND LOWER(TRIM(t.school_id_code_raw)) = LOWER(TRIM(s.school_id_code))
          )
          OR (
              t.school_id IS NULL
              AND t.school_name_raw IS NOT NULL
              AND TRIM(t.school_name_raw) <> ''
              AND LOWER(TRIM(t.school_name_raw)) = LOWER(TRIM(s.school_name))
          )
      )
      GROUP BY s.id, s.school_name, s.learner_count
      HAVING teacher_need > 0
      ORDER BY teacher_need DESC, s.school_name ASC
      LIMIT 10";
$schoolNeedData = $db->query($schoolNeedQuery)->fetchAll();

// Age brackets
$ageData = $db->query(
    "SELECT
        CASE
          WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) < 30 THEN 'Under 30'
          WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 30 AND 39 THEN '30 – 39'
          WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 40 AND 49 THEN '40 – 49'
          WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 50 AND 59 THEN '50 – 59'
                    WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) = 60 THEN '60'
        END AS bracket,
        COUNT(*) AS cnt
         FROM teachers
         WHERE birthdate IS NOT NULL
             AND birthdate != '0000-00-00'
             AND TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) <= 60
     GROUP BY bracket
         ORDER BY FIELD(bracket, 'Under 30', '30 – 39', '40 – 49', '50 – 59', '60')"
)->fetchAll();

// Recent additions
$recentTeachers = $db->query(
    'SELECT t.*, s.school_name FROM teachers t
     LEFT JOIN schools s ON t.school_id = s.id
     ORDER BY t.created_at DESC LIMIT 5'
)->fetchAll();

// Latest uploads
$recentUploads = $db->query(
    'SELECT u.*, us.full_name AS uploader_name FROM upload_logs u
     LEFT JOIN users us ON u.uploaded_by = us.id
     ORDER BY u.created_at DESC LIMIT 5'
)->fetchAll();

// Dashboard notifications
$dashboardNotifications = [];
$addDashboardNotification = static function(string $type, string $icon, string $title, string $detail, string $href, string $cta) use (&$dashboardNotifications): void {
    $dashboardNotifications[] = [
        'type' => $type,
        'icon' => $icon,
        'title' => $title,
        'detail' => $detail,
        'href' => $href,
        'cta' => $cta,
    ];
};

$unreadDirectMessages = 0;
$groupMessagesSinceLastLogin = 0;

try {
    ensureChatSystemSchema($db);

    $unreadDirectStmt = $db->prepare(
        'SELECT COUNT(*)
         FROM chat_messages
         WHERE recipient_id = ?
           AND group_id IS NULL
           AND is_read = 0'
    );
    $unreadDirectStmt->execute([$userId]);
    $unreadDirectMessages = (int)$unreadDirectStmt->fetchColumn();

    $lastLoginStmt = $db->prepare('SELECT last_login FROM users WHERE id = ? LIMIT 1');
    $lastLoginStmt->execute([$userId]);
    $lastLoginRaw = (string)$lastLoginStmt->fetchColumn();
    $groupSince = $lastLoginRaw !== ''
        ? date('Y-m-d H:i:s', strtotime($lastLoginRaw))
        : date('Y-m-d H:i:s', strtotime('-24 hours'));

    $groupMsgStmt = $db->prepare(
        'SELECT COUNT(DISTINCT m.group_id)
         FROM chat_messages m
         INNER JOIN chat_group_members gm ON gm.group_id = m.group_id
         INNER JOIN chat_groups g ON g.id = m.group_id
         WHERE gm.user_id = ?
           AND m.sender_id <> ?
           AND m.group_id IS NOT NULL
           AND m.created_at >= ?
           AND g.is_archived = 0'
    );
    $groupMsgStmt->execute([$userId, $userId, $groupSince]);
    $groupMessagesSinceLastLogin = (int)$groupMsgStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('TPMS dashboard notification chat check warning: ' . $e->getMessage());
}

if ($unreadDirectMessages > 0) {
    $addDashboardNotification(
        'urgent',
        'fa-envelope-open-text',
        'Unread Direct Messages',
        number_format($unreadDirectMessages) . ' new direct message' . ($unreadDirectMessages !== 1 ? 's' : '') . ' waiting in Team Chat.',
        APP_URL . '/chat.php',
        'Open Chat'
    );
}

if ($groupMessagesSinceLastLogin > 0) {
    $addDashboardNotification(
        'info',
        'fa-users-viewfinder',
        'Group Chat Activity',
        number_format($groupMessagesSinceLastLogin) . ' group chat' . ($groupMessagesSinceLastLogin !== 1 ? 's have' : ' has') . ' new messages since your last login.',
        APP_URL . '/chat.php',
        'Review Groups'
    );
}

$totalUnreadMessages = $unreadDirectMessages + $groupMessagesSinceLastLogin;

// Chart JSON
$genderLabels   = json_encode(array_column($genderData, 'gender'));
$genderCounts   = json_encode(array_column($genderData, 'cnt'));
$districtLabels = json_encode(array_column($districtData, 'district'));
$districtCounts = json_encode(array_column($districtData, 'cnt'));
$ageLabels      = json_encode(array_column($ageData, 'bracket'));
$ageCounts      = json_encode(array_column($ageData, 'cnt'));
$posLabels      = json_encode(array_column($positionData, 'position'));
$posCounts      = json_encode(array_column($positionData, 'cnt'));
$schoolNeedLabels = json_encode(array_column($schoolNeedData, 'school_name'));
$schoolNeedCounts = json_encode(array_map('intval', array_column($schoolNeedData, 'teacher_need')));
$schoolsWithNeed  = count($schoolNeedData);
$totalTeacherNeed = array_sum(array_map('intval', array_column($schoolNeedData, 'teacher_need')));

// Workforce snapshot totals by school type (mirrors schools.php logic)
$schoolTypeStats = $db->query(
    'SELECT
        COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("elementary", "es", "es/jhs", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS elementary_count,
        COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("jhs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS jhs_count,
        COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS shs_count,
        COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") = "als" THEN 1 ELSE 0 END), 0) AS als_count,
        COALESCE(SUM(CASE WHEN school_type IS NULL OR TRIM(school_type) = "" OR REPLACE(LOWER(TRIM(school_type)), " ", "") NOT IN ("elementary", "es", "jhs", "shs", "es/jhs", "es/shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs/shs", "alloffering", "als", "public", "private") THEN 1 ELSE 0 END), 0) AS untagged_count
     FROM schools'
)->fetch() ?: [
    'elementary_count' => 0,
    'jhs_count' => 0,
    'shs_count' => 0,
    'als_count' => 0,
    'untagged_count' => 0,
];

$exactSchoolTypeCounts = [
    'elementary' => 0,
    'jhs' => 0,
    'shs' => 0,
    'es/jhs' => 0,
    'es/shs' => 0,
    'jhs/shs' => 0,
    'all offering' => 0,
    'als' => 0,
];

foreach ($db->query('SELECT REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") AS type_key, COUNT(*) AS cnt FROM schools GROUP BY type_key') as $schoolTypeRow) {
    $key = trim((string)($schoolTypeRow['type_key'] ?? ''));
    $count = (int)($schoolTypeRow['cnt'] ?? 0);

    if ($key === 'jhs-shs' || $key === 'juniorandseniorhighschool') {
        $key = 'jhs/shs';
    } elseif ($key === 'es/jhs/shs' || $key === 'alloffering') {
        $key = 'all offering';
    }

    if (isset($exactSchoolTypeCounts[$key])) {
        $exactSchoolTypeCounts[$key] += $count;
    }
}

$snapshotElementary = (int)($schoolTypeStats['elementary_count'] ?? 0);
$snapshotJhs = (int)($schoolTypeStats['jhs_count'] ?? 0);
$snapshotShs = (int)($schoolTypeStats['shs_count'] ?? 0);
$snapshotAls = (int)($schoolTypeStats['als_count'] ?? 0);
$snapshotUntagged = (int)($schoolTypeStats['untagged_count'] ?? 0);

$schoolTypeFilterChips = [
    ['label' => 'Elem (All)', 'type' => 'elementary', 'count' => $snapshotElementary],
    ['label' => 'JHS (All)', 'type' => 'jhs', 'count' => $snapshotJhs],
    ['label' => 'SHS (All)', 'type' => 'shs', 'count' => $snapshotShs],
    ['label' => 'ES/JHS', 'type' => 'es/jhs', 'count' => (int)($exactSchoolTypeCounts['es/jhs'] ?? 0)],
    ['label' => 'JHS/SHS', 'type' => 'jhs/shs', 'count' => (int)($exactSchoolTypeCounts['jhs/shs'] ?? 0)],
    ['label' => 'Pure SHS', 'type' => 'pure_shs', 'count' => (int)($exactSchoolTypeCounts['shs'] ?? 0)],
    ['label' => 'All Offering', 'type' => 'all offering', 'count' => (int)($exactSchoolTypeCounts['all offering'] ?? 0)],
];

$compositionHoverRows = [
    'elementary' => [
        ['label' => 'Elementary', 'count' => (int)($exactSchoolTypeCounts['elementary'] ?? 0)],
        ['label' => 'ES with JHS', 'count' => (int)($exactSchoolTypeCounts['es/jhs'] ?? 0)],
        ['label' => 'ALL OFFERING', 'count' => (int)($exactSchoolTypeCounts['all offering'] ?? 0)],
        ['label' => 'Total', 'count' => (int)$snapshotElementary, 'emphasis' => true],
    ],
    'jhs' => [
        ['label' => 'JHS', 'count' => (int)($exactSchoolTypeCounts['jhs'] ?? 0)],
        ['label' => 'ES with JHS', 'count' => (int)($exactSchoolTypeCounts['es/jhs'] ?? 0)],
        ['label' => 'JHS with SHS', 'count' => (int)($exactSchoolTypeCounts['jhs/shs'] ?? 0)],
        ['label' => 'ALL OFFERING', 'count' => (int)($exactSchoolTypeCounts['all offering'] ?? 0)],
        ['label' => 'Total', 'count' => (int)$snapshotJhs, 'emphasis' => true],
    ],
    'shs' => [
        ['label' => 'SHS', 'count' => (int)($exactSchoolTypeCounts['shs'] ?? 0)],
        ['label' => 'JHS with SHS', 'count' => (int)($exactSchoolTypeCounts['jhs/shs'] ?? 0)],
        ['label' => 'ALL OFFERING', 'count' => (int)($exactSchoolTypeCounts['all offering'] ?? 0)],
        ['label' => 'Total', 'count' => (int)$snapshotShs, 'emphasis' => true],
    ],
    'als' => [
        ['label' => 'ALS', 'count' => (int)($exactSchoolTypeCounts['als'] ?? 0)],
    ],
];

$compositionTooltipTitles = [];
foreach ($compositionHoverRows as $key => $rows) {
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = ($row['label'] ?? '') . ': ' . number_format((int)($row['count'] ?? 0));
    }
    $compositionTooltipTitles[$key] = implode("\n", $lines);
}
?>

<!-- ── Customize Controls ─────────────────────────────────── -->
<style>
    .dashboard-shell {
        position: relative;
        display: grid;
        gap: 22px;
        padding-top: 4px;
    }
    .dashboard-shell .glass-card {
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(18px) saturate(135%);
        -webkit-backdrop-filter: blur(18px) saturate(135%);
    }
    .dashboard-shell .glass-card::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        background: linear-gradient(140deg, rgba(255,255,255,.26) 0%, rgba(255,255,255,.08) 28%, rgba(255,255,255,0) 52%);
        opacity: .46;
    }
    .dashboard-shell::before {
        content: '';
        position: absolute;
        inset: -24px -16px auto;
        height: 320px;
        background: radial-gradient(circle at 10% 10%, rgba(14, 165, 233, .24), transparent 42%),
                    radial-gradient(circle at 88% 18%, rgba(251, 146, 60, .22), transparent 42%),
                    linear-gradient(180deg, rgba(15, 23, 42, .12), transparent 78%);
        z-index: 0;
        pointer-events: none;
    }
    .dashboard-shell::after {
        content: '';
        position: absolute;
        inset: 120px -10px auto;
        height: 280px;
        background:
            radial-gradient(circle at 12% 40%, rgba(34, 197, 94, .15), transparent 36%),
            radial-gradient(circle at 78% 26%, rgba(56, 189, 248, .18), transparent 40%),
            repeating-linear-gradient(120deg, rgba(148, 163, 184, .05) 0 2px, transparent 2px 22px);
        opacity: .55;
        filter: blur(2px);
        z-index: 0;
        pointer-events: none;
    }
    .dashboard-shell > * {
        position: relative;
        z-index: 1;
    }
    .dashboard-ribbon {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(280px, .75fr);
        gap: 18px;
        align-items: stretch;
        padding: 22px 24px;
        border: 1px solid rgba(148, 163, 184, .26);
        border-radius: 26px;
        background:
            radial-gradient(circle at 18% 18%, rgba(56, 189, 248, .22), transparent 32%),
            radial-gradient(circle at 88% 14%, rgba(167, 139, 250, .18), transparent 30%),
            linear-gradient(145deg, rgba(255,255,255,.18) 0%, rgba(255,255,255,.08) 26%, rgba(255,255,255,.03) 54%, rgba(255,255,255,0) 100%),
            linear-gradient(180deg, rgba(15, 23, 42, .70), rgba(15, 23, 42, .50));
        box-shadow: 0 24px 54px rgba(2, 6, 23, .26), inset 0 1px 0 rgba(255,255,255,.18);
        backdrop-filter: blur(18px) saturate(135%);
        -webkit-backdrop-filter: blur(18px) saturate(135%);
        overflow: hidden;
    }
    .dashboard-ribbon::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        background: linear-gradient(140deg, rgba(255,255,255,.18) 0%, rgba(255,255,255,.06) 22%, rgba(255,255,255,0) 45%);
        opacity: .75;
    }
    .dashboard-ribbon > * {
        position: relative;
        z-index: 1;
    }
    .dashboard-ribbon h2 {
        margin: 8px 0 10px;
        color: #f8fafc;
        font-size: clamp(1.65rem, 2.8vw, 2.35rem);
        line-height: 1.08;
        letter-spacing: -.02em;
    }
    .dashboard-ribbon p {
        margin: 0;
        color: #cbd5e1;
        font-size: .96rem;
        line-height: 1.6;
        max-width: 62ch;
    }
    .ribbon-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        align-items: stretch;
    }
    .ribbon-metric {
        display: grid;
        gap: 4px;
        padding: 14px 14px 12px;
        border-radius: 18px;
        border: 1px solid rgba(148, 163, 184, .22);
        background:
            linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.04)),
            rgba(15, 23, 42, .34);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.16);
    }
    .ribbon-metric span {
        color: #94a3b8;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .12em;
    }
    .ribbon-metric strong {
        color: #f8fafc;
        font-size: 1.18rem;
        line-height: 1.1;
    }
    .ribbon-metric small {
        color: #cbd5e1;
        font-size: .78rem;
        line-height: 1.35;
    }
    .dashboard-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(320px, .75fr);
        gap: 20px;
        align-items: stretch;
        padding: 28px;
        border: 1px solid rgba(148, 163, 184, .26);
        border-radius: 28px;
        box-shadow: 0 26px 62px rgba(2, 6, 23, .3), inset 0 1px 0 rgba(255,255,255,.18);
        background:
            radial-gradient(circle at 12% 10%, rgba(125, 211, 252, .30), transparent 30%),
            radial-gradient(circle at 88% 8%, rgba(251, 191, 36, .20), transparent 32%),
            radial-gradient(circle at 84% 92%, rgba(45, 212, 191, .18), transparent 32%),
            linear-gradient(138deg, rgba(15, 23, 42, .84), rgba(30, 41, 59, .64));
        transition: transform .28s ease, border-color .28s ease, box-shadow .28s ease;
    }
    .dashboard-hero:hover {
        transform: translateY(-4px);
        border-color: rgba(125, 211, 252, .36);
        box-shadow: 0 34px 74px rgba(2, 6, 23, .34), inset 0 1px 0 rgba(255,255,255,.28);
    }
    .eyebrow-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        padding: 6px 10px;
        border-radius: 999px;
        background: rgba(148, 163, 184, .12);
        color: #cbd5e1;
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .12em;
    }
    .dashboard-hero h2 {
        margin: 0;
        font-size: 1.5rem;
        color: #f8fafc;
        letter-spacing: .01em;
    }
    .dashboard-hero p {
        margin: 8px 0 0;
        color: #cbd5e1;
        font-size: .96rem;
        max-width: 58ch;
    }
    .hero-kpis {
        margin-top: 16px;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }
    .hero-kpi {
        border: 1px solid rgba(148, 163, 184, .26);
        background:
            linear-gradient(170deg, rgba(255,255,255,.16) 0%, rgba(255,255,255,.05) 32%, rgba(255,255,255,0) 62%),
            linear-gradient(180deg, rgba(15, 23, 42, .56), rgba(15, 23, 42, .34));
        border-radius: 16px;
        padding: 12px 14px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.2), 0 10px 22px rgba(2, 6, 23, .18);
        transition: transform .24s ease, border-color .24s ease, box-shadow .24s ease;
        position: relative;
        overflow: hidden;
    }
    .hero-kpi:hover {
        z-index: 6;
    }
    .hero-kpi::after {
        content: '';
        position: absolute;
        inset: auto -10% -52% -10%;
        height: 70%;
        background: radial-gradient(circle, rgba(125, 211, 252, .18), transparent 70%);
        pointer-events: none;
    }
    .hero-kpi:hover {
        transform: translateY(-2px);
        border-color: rgba(125, 211, 252, .38);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.26), 0 16px 30px rgba(2, 6, 23, .24);
    }
    .hero-kpi-label {
        color: #94a3b8;
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .hero-kpi-value {
        margin-top: 5px;
        color: #f8fafc;
        font-size: 1.22rem;
        font-weight: 700;
        line-height: 1.1;
    }
    .hero-kpi-wrap {
        position: relative;
        z-index: 1;
        overflow: visible;
    }
    .composition-tooltip-layer {
        position: fixed;
        left: 0;
        top: 0;
        width: min(280px, calc(100vw - 24px));
        padding: 12px 12px 10px;
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, .24);
        background: linear-gradient(160deg, rgba(15, 23, 42, .98), rgba(15, 23, 42, .88));
        box-shadow: 0 20px 38px rgba(2, 6, 23, .36), inset 0 1px 0 rgba(255,255,255,.12);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translate(-50%, 0) scale(.98);
        transition: opacity .14s ease, transform .14s ease, visibility .14s ease;
        z-index: 9999;
    }
    .composition-tooltip-layer.is-visible {
        opacity: 1;
        visibility: visible;
        transform: translate(-50%, 0) scale(1);
    }
    .composition-tooltip-layer::before {
        content: '';
        position: absolute;
        left: 50%;
        top: -7px;
        width: 14px;
        height: 14px;
        transform: translateX(-50%) rotate(45deg);
        background: rgba(15, 23, 42, .96);
        border-left: 1px solid rgba(148, 163, 184, .24);
        border-top: 1px solid rgba(148, 163, 184, .24);
    }
    .composition-tooltip-layer .stt-title {
        margin-bottom: 8px;
        color: #f8fafc;
        font-size: .78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .12em;
    }
    .composition-tooltip-layer .stt-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 4px 0;
        color: #cbd5e1;
        font-size: .8rem;
        line-height: 1.35;
    }
    .composition-tooltip-layer .stt-k {
        color: #cbd5e1;
    }
    .composition-tooltip-layer .stt-v {
        color: #f8fafc;
        font-weight: 700;
    }
    .composition-tooltip-layer .stt-divider {
        margin: 8px 0 6px;
        border: 0;
        border-top: 1px solid rgba(148, 163, 184, .16);
    }
    .composition-tooltip {
        display: none !important;
    }
    .hero-kpi-wrap:hover {
        z-index: 20;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 16px;
        margin-bottom: 18px;
    }
    .dashboard-hero-actions {
        display: grid;
        grid-template-rows: auto auto;
        gap: 12px;
    }
    .workforce-snapshot-graph {
        width: 100%;
        min-width: 0;
        height: 170px;
        border: 1px solid rgba(148, 163, 184, .28);
        border-radius: 18px;
        background:
            linear-gradient(165deg, rgba(255,255,255,.12) 0%, rgba(255,255,255,.03) 30%, rgba(255,255,255,0) 62%),
            linear-gradient(160deg, rgba(15, 23, 42, .84), rgba(30, 41, 59, .54));
        padding: 14px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.2), 0 12px 24px rgba(2, 6, 23, .18);
        transition: border-color .24s ease, box-shadow .24s ease;
        position: relative;
        overflow: hidden;
    }
    .workforce-snapshot-graph::before {
        content: '';
        position: absolute;
        inset: -30% auto auto -18%;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(56, 189, 248, .2), transparent 72%);
        pointer-events: none;
    }
    .workforce-snapshot-graph:hover {
        border-color: rgba(56, 189, 248, .36);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.24), 0 18px 30px rgba(2, 6, 23, .24);
    }
    .workforce-graph-title {
        margin: 0 0 4px;
        font-size: .78rem;
        letter-spacing: .06em;
        color: #cbd5e1;
        text-transform: uppercase;
    }
    .workforce-summary-text {
        margin-top: 16px;
        font-size: .88rem;
        color: #cbd5e1;
        line-height: 1.5;
    }
    .workforce-summary-text strong {
        color: #f8fafc;
    }
    .workforce-filter-chips {
        margin-top: 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .workforce-filter-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 10px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .26);
        background: rgba(15, 23, 42, .36);
        text-decoration: none;
        color: #cbd5e1;
        font-size: .74rem;
        line-height: 1;
        transition: transform .2s ease, border-color .2s ease, background-color .2s ease;
    }
    .workforce-filter-chip strong {
        color: #f8fafc;
        font-weight: 700;
        font-size: .78rem;
    }
    .workforce-filter-chip:hover {
        transform: translateY(-1px);
        border-color: rgba(125, 211, 252, .4);
        background: rgba(15, 23, 42, .5);
    }
    .dashboard-highlights {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-top: 16px;
    }
    .highlight-chip {
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, .24);
        background:
            linear-gradient(170deg, rgba(255,255,255,.15) 0%, rgba(255,255,255,.04) 28%, rgba(255,255,255,0) 58%),
            rgba(15, 23, 42, .34);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.18);
        transition: transform .22s ease, border-color .22s ease;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .highlight-chip:hover {
        transform: translateY(-2px);
        border-color: rgba(125, 211, 252, .34);
    }
    .highlight-chip strong {
        display: block;
        color: #f8fafc;
        font-size: 1.1rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .highlight-chip span {
        display: block;
        margin-top: 4px;
        color: #94a3b8;
        font-size: .78rem;
        line-height: 1.3;
    }
    .dashboard-hero .btn {
        min-width: 140px;
        border-radius: 12px;
        font-weight: 600;
        letter-spacing: .01em;
        transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
    }
    .dashboard-hero .btn:hover {
        transform: translateY(-2px);
        filter: saturate(1.08);
        box-shadow: 0 10px 20px rgba(2, 6, 23, .22);
    }
    .dashboard-section-spacer {
        display: grid;
        gap: 14px;
    }
    .dashboard-section-spacer .section-heading {
        margin-top: 0;
    }
    .dashboard-section-spacer .section-heading h3 {
        font-size: 1.06rem;
    }
    .customize-panel { 
        position: fixed; right: 0; top: 0; width: 320px; height: 100vh; 
        background: rgba(2, 6, 23, .94); border-left: 1px solid rgba(148,163,184,.25);
        transform: translateX(100%); transition: transform 0.3s; z-index: 200;
        padding: 20px; overflow-y: auto; backdrop-filter: blur(10px);
    }
    .customize-panel.active { transform: translateX(0); }
    .customize-panel h3 { color: #e2e8f0; margin-bottom: 15px; }
    .color-option {
        display: inline-block; width: 30px; height: 30px; border-radius: 50%;
        margin: 5px; cursor: pointer; border: 2px solid transparent; 
        transition: border 0.2s;
    }
    .color-option:hover { border-color: #fff; }
    .card-customize { margin-bottom: 15px; }
    .card-customize label { color: #cbd5e1; font-size: 12px; display: block; margin-bottom: 5px; }
    .dashboard-container.customize-mode .draggable-card { 
        cursor: move; position: relative;
    }
    .dashboard-container.customize-mode .draggable-card:hover {
        opacity: 0.8; outline: 2px dashed rgba(99,102,241,0.5);
    }
    .draggable-card.dragging { opacity: 0.5; }
    .color-picker { width: 100%; padding: 8px; background: rgba(255,255,255,.05); 
        border: 1px solid rgba(255,255,255,.1); color: #e2e8f0; border-radius: 4px; }
    .stat-card {
        border: 1px solid rgba(148, 163, 184, .24);
        border-radius: 20px;
        background:
            linear-gradient(170deg, rgba(255,255,255,.16) 0%, rgba(255,255,255,.04) 34%, rgba(255,255,255,0) 60%),
            linear-gradient(180deg, rgba(15, 23, 42, .74), rgba(15, 23, 42, .52));
        box-shadow: 0 14px 30px rgba(2, 6, 23, .26), inset 0 1px 0 rgba(255,255,255,.16);
        transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease;
        position: relative;
        overflow: hidden;
    }
    .stat-card::before {
        content: '';
        position: absolute;
        inset: 0 0 auto;
        height: 3px;
        background: linear-gradient(90deg, rgba(56, 189, 248, .72), rgba(34, 197, 94, .72));
        opacity: .76;
    }
    .stat-card:hover {
        transform: translateY(-5px);
        border-color: rgba(125, 211, 252, .42);
        box-shadow: 0 24px 42px rgba(2, 6, 23, .32), inset 0 1px 0 rgba(255,255,255,.24);
    }
    .chart-card {
        border: 1px solid rgba(148, 163, 184, .24);
        border-radius: 22px;
        box-shadow: 0 18px 36px rgba(2, 6, 23, .24), inset 0 1px 0 rgba(255,255,255,.18);
        background:
            linear-gradient(165deg, rgba(255,255,255,.14) 0%, rgba(255,255,255,.03) 30%, rgba(255,255,255,0) 62%),
            linear-gradient(180deg, rgba(15, 23, 42, .78), rgba(15, 23, 42, .56)),
            radial-gradient(circle at top right, rgba(34, 211, 238, .14), transparent 34%);
        overflow: hidden;
        transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease;
    }
    .chart-card:hover {
        transform: translateY(-4px);
        border-color: rgba(125, 211, 252, .38);
        box-shadow: 0 24px 44px rgba(2, 6, 23, .3), inset 0 1px 0 rgba(255,255,255,.24);
    }
    .charts-grid {
        display: grid;
        gap: 14px;
    }
    .section-heading + .charts-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        margin-top: 2px;
    }
    .section-heading + .charts-grid + .charts-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .charts-grid.coverage-row {
        grid-template-columns: 1fr;
    }
    .chart-wide {
        grid-column: 1 / -1;
    }
    .card-header {
        border-bottom: 1px solid rgba(148, 163, 184, .12);
        margin-bottom: 14px;
        padding-bottom: 12px;
    }
    .card-title {
        color: #e2e8f0;
        letter-spacing: .01em;
    }
    .section-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 4px 0 -6px;
    }
    .section-heading h3 {
        margin: 0;
        color: #f8fafc;
        font-size: 1rem;
        letter-spacing: .01em;
    }
    .section-heading p {
        margin: 0;
        color: #94a3b8;
        font-size: .84rem;
    }
    .tables-grid .table-card {
        border: 1px solid rgba(148, 163, 184, .24);
        border-radius: 22px;
        background:
            linear-gradient(165deg, rgba(255,255,255,.14) 0%, rgba(255,255,255,.03) 28%, rgba(255,255,255,0) 60%),
            linear-gradient(180deg, rgba(15, 23, 42, .78), rgba(15, 23, 42, .54));
        box-shadow: 0 16px 34px rgba(2, 6, 23, .22), inset 0 1px 0 rgba(255,255,255,.16);
        transition: transform .24s ease, border-color .24s ease, box-shadow .24s ease;
    }
    .tables-grid .table-card:hover {
        transform: translateY(-4px);
        border-color: rgba(125, 211, 252, .34);
        box-shadow: 0 22px 40px rgba(2, 6, 23, .28), inset 0 1px 0 rgba(255,255,255,.22);
    }
    .tables-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 18px;
    }
    .chart-container {
        min-height: 250px;
    }
    .chart-container.chart-sm {
        min-height: 220px;
    }
    .coverage-card {
        padding: 2px 0 4px;
    }
    .coverage-row {
        grid-template-columns: minmax(0, 1fr);
    }
    .coverage-card .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }
    .coverage-summary-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 11px;
        border-radius: 999px;
        background:black;
        border: 1px solid rgba(56, 189, 248, .18);
        color: #bae6fd;
        font-size: .76rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .coverage-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(250px, .7fr);
        gap: 16px;
        align-items: stretch;
    }
    .coverage-meter {
        padding: 22px;
        border-radius: 20px;
        border: 1px solid rgba(148, 163, 184, .24);
        background:
            linear-gradient(170deg, rgba(255,255,255,.14) 0%, rgba(255,255,255,.03) 28%, rgba(255,255,255,0) 62%),
            radial-gradient(circle at top right, rgba(34, 211, 238, .16), transparent 34%),
            linear-gradient(180deg, rgba(15, 23, 42, .62), rgba(15, 23, 42, .38));
        box-shadow: inset 0 1px 0 rgba(255,255,255,.16), 0 14px 30px rgba(2, 6, 23, .18);
    }
    .coverage-meter-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
    }
    .coverage-percent {
        font-size: clamp(2.4rem, 4vw, 3.4rem);
        line-height: .9;
        font-weight: 800;
        color: #f8fafc;
    }
    .coverage-kicker {
        margin-bottom: 8px;
        color: #7dd3fc;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .14em;
        text-transform: uppercase;
    }
    .coverage-caption {
        color: #e2e8f0;
        font-size: .98rem;
        max-width: 24ch;
        text-align: right;
        line-height: 1.45;
        font-weight: 600;
    }
    .coverage-bar-track {
        position: relative;
        height: 14px;
        border-radius: 999px;
        overflow: hidden;
        background: rgba(30, 41, 59, .92);
        box-shadow: inset 0 1px 2px rgba(2, 6, 23, .5);
    }
    .coverage-bar-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #38bdf8 0%, #14b8a6 55%, #22c55e 100%);
        box-shadow: 0 0 18px rgba(34, 197, 94, .22);
    }
    .coverage-scale {
        margin-top: 12px;
        display: flex;
        justify-content: space-between;
        color: #94a3b8;
        font-size: .78rem;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .coverage-footnote {
        margin-top: 14px;
        color: #94a3b8;
        font-size: .82rem;
        line-height: 1.55;
    }
    .coverage-stats {
        display: grid;
        gap: 12px;
    }
    .coverage-stat {
        padding: 18px;
        border-radius: 20px;
        border: 1px solid rgba(148, 163, 184, .24);
        background:
            linear-gradient(170deg, rgba(255,255,255,.14) 0%, rgba(255,255,255,.03) 30%, rgba(255,255,255,0) 62%),
            linear-gradient(180deg, rgba(15, 23, 42, .58), rgba(15, 23, 42, .34));
        display: grid;
        gap: 6px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.16), 0 12px 24px rgba(2, 6, 23, .16);
        transition: transform .22s ease, border-color .22s ease, box-shadow .22s ease;
    }
    .coverage-stat:hover {
        transform: translateY(-3px);
        border-color: rgba(125, 211, 252, .34);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.22), 0 18px 30px rgba(2, 6, 23, .22);
    }
    .coverage-stat-label {
        color: #94a3b8;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .12em;
    }
    .coverage-stat-value {
        color: #f8fafc;
        font-size: 1.9rem;
        font-weight: 700;
        line-height: 1;
    }
    .coverage-stat-note {
        color: #cbd5e1;
        font-size: .8rem;
        line-height: 1.45;
    }
    .table-card .table-scroll {
        padding-right: 2px;
    }
    .table-card .card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }
    .table-heading-group {
        display: grid;
        gap: 4px;
    }
    .table-subtitle {
        color: #94a3b8;
        font-size: .82rem;
        line-height: 1.45;
    }
    .table-card .data-table thead th {
        color: #94a3b8;
        font-size: .74rem;
        text-transform: uppercase;
        letter-spacing: .08em;
    }
    .table-card .data-table tbody td {
        color: #e2e8f0;
        vertical-align: middle;
    }
    .table-card .data-table tbody tr:hover td {
        background: rgba(148, 163, 184, .05);
    }
    .dashboard-shell .table-card .data-table tbody tr {
        transition: background-color .18s ease;
    }
    .teacher-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .teacher-meta {
        display: grid;
        gap: 3px;
    }
    .teacher-name {
        color: #f8fafc;
        font-weight: 600;
        line-height: 1.2;
    }
    .teacher-school,
    .teacher-position,
    .muted-inline {
        color: #94a3b8;
        font-size: .8rem;
        line-height: 1.35;
    }
    .teacher-date-badge,
    .record-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .16);
        background: rgba(15, 23, 42, .5);
        color: #cbd5e1;
        font-size: .78rem;
        white-space: nowrap;
    }
    .dashboard-shell .glass-card {
        animation: cardReveal .35s ease both;
    }
    .dashboard-shell .glass-card:nth-child(2) { animation-delay: .05s; }
    .dashboard-shell .glass-card:nth-child(3) { animation-delay: .1s; }
    .dashboard-shell .glass-card:nth-child(4) { animation-delay: .15s; }
    .dashboard-shell .glass-card:nth-child(5) { animation-delay: .2s; }

    /* Keep dashboard copy readable across all themes/background colors */
    .dashboard-shell .dashboard-hero h2,
    .dashboard-shell .section-heading h3,
    .dashboard-shell .card-title,
    .dashboard-shell .hero-kpi-value,
    .dashboard-shell .highlight-chip strong,
    .dashboard-shell .coverage-percent,
    .dashboard-shell .coverage-stat-value,
    .dashboard-shell .workforce-summary-text strong,
    .dashboard-shell .teacher-name,
    .dashboard-shell .table-card .data-table tbody td,
    .dashboard-shell .table-card .card-title,
    .dashboard-shell .customize-panel h3,
    .dashboard-shell .coverage-summary-badge {
        color: var(--text) !important;
    }

    .dashboard-shell .dashboard-hero p,
    .dashboard-shell .section-heading p,
    .dashboard-shell .eyebrow-label,
    .dashboard-shell .workforce-graph-title,
    .dashboard-shell .workforce-summary-text,
    .dashboard-shell .hero-kpi-label,
    .dashboard-shell .highlight-chip span,
    .dashboard-shell .coverage-kicker,
    .dashboard-shell .coverage-caption,
    .dashboard-shell .coverage-scale,
    .dashboard-shell .coverage-footnote,
    .dashboard-shell .coverage-stat-label,
    .dashboard-shell .coverage-stat-note,
    .dashboard-shell .table-subtitle,
    .dashboard-shell .table-card .data-table thead th,
    .dashboard-shell .teacher-school,
    .dashboard-shell .teacher-position,
    .dashboard-shell .muted-inline,
    .dashboard-shell .record-badge,
    .dashboard-shell .teacher-date-badge,
    .dashboard-shell .card-customize label,
    .dashboard-shell .color-picker {
        color: var(--text-muted) !important;
    }

    .dashboard-shell .teacher-date-badge,
    .dashboard-shell .record-badge,
    .dashboard-shell .color-picker,
    .dashboard-shell .coverage-summary-badge,
    .dashboard-shell .eyebrow-label {
        border-color: var(--glass-border) !important;
        background: var(--glass-bg) !important;
    }

    .dashboard-shell .customize-panel {
        color: var(--text);
        background: var(--glass-bg);
        border-left-color: var(--glass-border);
    }

    .dashboard-shell .coverage-bar-track {
        background: rgba(30, 41, 59, .5);
    }

    .dashboard-shell .coverage-bar-fill {
        box-shadow: 0 0 14px rgba(34, 197, 94, .16);
    }

    @keyframes cardReveal {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 860px) {
        .dashboard-hero {
            grid-template-columns: 1fr;
        }
        .dashboard-highlights {
            grid-template-columns: 1fr;
        }
        .hero-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .section-heading + .charts-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .section-heading + .charts-grid + .charts-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .dashboard-hero-actions {
            grid-template-rows: auto;
        }
        .dashboard-hero .btn {
            min-width: auto;
        }
        .workforce-snapshot-graph {
            width: 100%;
            min-width: 0;
        }
        .coverage-layout {
            grid-template-columns: 1fr;
        }
        .coverage-meter-top {
            align-items: flex-start;
            flex-direction: column;
        }
        .coverage-caption {
            text-align: left;
            max-width: none;
        }
    }
    @media (min-width: 861px) and (max-width: 1160px) {
        .hero-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 480px) {
        .dashboard-hero {
            padding: 16px;
            gap: 12px;
        }
        .hero-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .dashboard-highlights {
            grid-template-columns: 1fr;
            gap: 8px;
        }
        .hero-kpi {
            padding: 10px 12px;
        }
        .hero-kpi-value {
            font-size: 1rem;
        }
        .section-heading + .charts-grid {
            grid-template-columns: 1fr;
        }
        .section-heading + .charts-grid + .charts-grid {
            grid-template-columns: 1fr;
        }
        .workforce-snapshot-graph {
            height: 150px;
        }
        .coverage-bar-track {
            height: 12px;
        }
        .chart-wide {
            grid-column: 1;
        }
        .tables-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Customize Panel -->
<div id="customizePanel" class="customize-panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="margin: 0;"><i class="fas fa-palette"></i> Customize</h3>
        <button id="closePanelBtn" style="background: none; border: none; color: var(--text); font-size: 20px; cursor: pointer;">✕</button>
    </div>
    
    <div style="margin-bottom: 20px;">
        <h4 style="color: #cbd5e1; font-size: 12px; margin-bottom: 10px;">DASHBOARD MODE</h4>
        <button id="toggleEditMode" class="btn btn-sm btn-primary" style="width: 100%;">
            <i class="fas fa-lock"></i> Enable Edit Mode
        </button>
    </div>

    <div style="margin-bottom: 20px;">
        <h4 style="color: #cbd5e1; font-size: 12px; margin-bottom: 10px;">CARD COLORS</h4>
        <div id="cardsList"></div>
    </div>

    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,.1);">
        <button id="resetDashboard" class="btn btn-sm btn-ghost" style="width: 100%;">
            <i class="fas fa-undo"></i> Reset to Default
        </button>
    </div>
</div>

<!-- ── Tour Modal Overlay (Blocks dashboard until tour is completed) ── -->
<?php if (!$tourCompleted): ?>
<div class="tour-modal-backdrop" id="tourBackdrop"></div>
<?php endif; ?>

<div class="dashboard-shell" id="dashboardShell">

<!-- ── Welcome Hero Section ──────────────────────────────── -->
<!-- DEBUG: Tour Status - tourCompleted=<?= $tourCompleted ? 'TRUE (hidden)' : 'FALSE (showing)' ?>, userId=<?= $userId ?> -->
<?php if (!$tourCompleted): ?>
<div class="welcome-hero glass-card" id="dashboardTour">
    <div class="tour-close-btn" id="tourCloseBtn" title="Close tour">
        <i class="fas fa-times"></i>
    </div>
    
    <div class="welcome-hero-content">
        <div class="welcome-eyebrow">
            <span>Teacher Profiling Management System</span>
        </div>
        <h1 class="welcome-title">Welcome, <?= clean($user['role'] ?? 'User') ?></h1>
        <p class="welcome-subtitle">School Management</p>
        <p class="welcome-description">You now have access to your school's teacher and personnel management. View your staff, manage assignments, and access school-wide reports and analytics.</p>
        
        <div class="welcome-features">
            <div class="welcome-feature-card">
                <div class="feature-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="feature-content">
                    <h3>Dashboard</h3>
                    <p>Get real-time insights into your personnel and schools</p>
                </div>
            </div>
            
            <div class="welcome-feature-card">
                <div class="feature-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="feature-content">
                    <h3>Teachers</h3>
                    <p>Manage and track teacher information and assignments</p>
                </div>
            </div>
            
            <div class="welcome-feature-card">
                <div class="feature-icon">
                    <i class="fas fa-school"></i>
                </div>
                <div class="feature-content">
                    <h3>Schools</h3>
                    <p>View and manage school details and statistics</p>
                </div>
            </div>
            
            <div class="welcome-feature-card">
                <div class="feature-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="feature-content">
                    <h3>Reports</h3>
                    <p>Generate comprehensive reports for planning and analysis</p>
                </div>
            </div>
        </div>
        
        <div class="welcome-actions">
            <button class="btn btn-lg btn-primary welcome-btn-primary" id="tourGetStartedBtn">
                <i class="fas fa-arrow-right"></i> Get Started
            </button>
            <a href="<?= APP_URL ?>/reports.php" class="btn btn-lg btn-ghost welcome-btn-secondary">
                <i class="fas fa-file-lines"></i> View Reports
            </a>
        </div>
    </div>
    
    <div class="welcome-hero-graphic">
        <div class="welcome-shape welcome-shape-1"></div>
        <div class="welcome-shape welcome-shape-2"></div>
        <div class="welcome-shape welcome-shape-3"></div>
    </div>
</div>
<?php endif; ?>

<style>
/* ── Tour Modal Backdrop ──────────────────────────────────────── */
.tour-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.95);
    backdrop-filter: blur(4px);
    z-index: 99;
    pointer-events: none !important;
}

.welcome-hero {
    position: relative;
    display: grid !important;
    grid-template-columns: 1.2fr 1fr;
    gap: 40px;
    align-items: center;
    padding: 48px 50px;
    border: 1px solid rgba(148, 163, 184, .26);
    border-radius: 28px;
    background: linear-gradient(135deg, rgba(15, 23, 42, .85) 0%, rgba(30, 41, 59, .75) 50%, rgba(15, 23, 42, .8) 100%);
    box-shadow: 0 26px 62px rgba(2, 6, 23, .3), inset 0 1px 0 rgba(255,255,255,.18);
    overflow: visible;
    margin-bottom: 28px;
    animation: tourSlideIn 0.5s ease-out;
    visibility: visible;
    opacity: 1;
    z-index: 100;
}

.welcome-hero.tour-exit {
    animation: tourSlideOut 0.4s ease-in forwards;
}

.tour-close-btn {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: rgba(255, 255, 255, .1);
    border: 1px solid rgba(148, 163, 184, .2);
    color: #cbd5e1;
    cursor: pointer;
    transition: all .3s ease;
    z-index: 101;
    pointer-events: auto;
}

.tour-close-btn:hover {
    background: rgba(255, 255, 255, .15);
    border-color: rgba(148, 163, 184, .3);
    color: #f8fafc;
    transform: rotate(90deg);
}

@keyframes tourSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes tourSlideOut {
    from {
        opacity: 1;
        transform: translateY(0);
    }
    to {
        opacity: 0;
        transform: translateY(-20px);
    }
}

.welcome-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 10% 20%, rgba(125, 211, 252, .15), transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(167, 139, 250, .12), transparent 35%);
    pointer-events: none;
}

.welcome-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(140deg, rgba(255,255,255,.12) 0%, rgba(255,255,255,.04) 25%, rgba(255,255,255,0) 55%);
    opacity: .6;
}

.welcome-hero-content {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.welcome-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    width: fit-content;
    border-radius: 999px;
    background: rgba(125, 211, 252, .15);
    border: 1px solid rgba(125, 211, 252, .28);
    color: #38bdf8;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
}

.welcome-title {
    margin: 0;
    font-size: clamp(1.8rem, 4vw, 2.8rem);
    font-weight: 900;
    color: #f8fafc;
    line-height: 1.1;
    letter-spacing: -.02em;
}

.welcome-subtitle {
    margin: 0;
    font-size: clamp(1rem, 2.5vw, 1.5rem);
    font-weight: 600;
    color: #cbd5e1;
    line-height: 1.3;
}

.welcome-description {
    margin: 0;
    font-size: clamp(.9rem, 1.5vw, 1.05rem);
    color: #94a3b8;
    line-height: 1.7;
    max-width: 60ch;
}

.welcome-features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-top: 12px;
}

.welcome-feature-card {
    display: flex;
    gap: 14px;
    padding: 16px;
    border-radius: 16px;
    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(148, 163, 184, .18);
    transition: all .3s ease;
}

.welcome-feature-card:hover {
    background: rgba(255, 255, 255, .12);
    border-color: rgba(148, 163, 184, .32);
    transform: translateY(-2px);
}

.feature-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(125, 211, 252, .2), rgba(167, 139, 250, .15));
    color: #38bdf8;
    font-size: 1.2rem;
}

.feature-content h3 {
    margin: 0 0 4px;
    font-size: .95rem;
    font-weight: 700;
    color: #f8fafc;
}

.feature-content p {
    margin: 0;
    font-size: .85rem;
    color: #cbd5e1;
    line-height: 1.4;
}

.welcome-actions {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin-top: 8px;
    pointer-events: auto !important;
}

.welcome-btn-primary {
    padding: 14px 32px;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: 0;
    border-radius: 12px;
    font-weight: 700;
    font-size: .95rem;
    cursor: pointer !important;
    transition: all .3s ease;
    box-shadow: 0 10px 25px rgba(59, 130, 246, .3);
    pointer-events: auto !important;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

.welcome-btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 35px rgba(59, 130, 246, .4);
}

.welcome-btn-secondary {
    padding: 14px 32px;
    background: rgba(255, 255, 255, .1);
    color: #e0e7ff;
    border: 1px solid rgba(148, 163, 184, .28);
    border-radius: 12px;
    font-weight: 700;
    font-size: .95rem;
    text-decoration: none;
    cursor: pointer !important;
    transition: all .3s ease;
    pointer-events: auto !important;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

.welcome-btn-secondary:hover {
    background: rgba(255, 255, 255, .15);
    border-color: rgba(148, 163, 184, .42);
    transform: translateY(-3px);
}

.welcome-hero-graphic {
    position: relative;
    width: 100%;
    height: 280px;
}

.welcome-shape {
    position: absolute;
    border-radius: 50%;
    opacity: .8;
}

.welcome-shape-1 {
    width: 120px;
    height: 120px;
    top: 10%;
    right: 10%;
    background: radial-gradient(circle, rgba(125, 211, 252, .3), transparent);
    animation: float 8s ease-in-out infinite;
}

.welcome-shape-2 {
    width: 90px;
    height: 90px;
    bottom: 15%;
    left: 5%;
    background: radial-gradient(circle, rgba(167, 139, 250, .25), transparent);
    animation: float 10s ease-in-out infinite 1s;
}

.welcome-shape-3 {
    width: 70px;
    height: 70px;
    top: 50%;
    right: 30%;
    background: radial-gradient(circle, rgba(34, 197, 94, .2), transparent);
    animation: float 12s ease-in-out infinite 2s;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-20px); }
}

/* Tablet (768px - 1024px) */
@media (max-width: 1024px) {
    .welcome-hero {
        grid-template-columns: 1fr;
        gap: 32px;
        padding: 40px 36px;
    }
    
    .welcome-title {
        font-size: clamp(1.6rem, 3.5vw, 2.4rem);
    }
    
    .welcome-features {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .welcome-hero-graphic {
        height: 240px;
    }
    
    .welcome-actions {
        flex-direction: column;
    }
    
    .welcome-btn-primary,
    .welcome-btn-secondary {
        width: 100%;
        text-align: center;
    }
}

/* Large Mobile (480px - 768px) */
@media (max-width: 768px) {
    .welcome-hero {
        padding: 32px 24px;
        gap: 24px;
        border-radius: 20px;
        margin-bottom: 20px;
    }
    
    .welcome-title {
        font-size: clamp(1.4rem, 3vw, 2rem);
    }
    
    .welcome-subtitle {
        font-size: clamp(.9rem, 2vw, 1.2rem);
    }
    
    .welcome-description {
        font-size: .95rem;
    }
    
    .welcome-features {
        grid-template-columns: 1fr;
        gap: 12px;
        margin-top: 8px;
    }
    
    .welcome-feature-card {
        padding: 14px;
        gap: 12px;
    }
    
    .feature-icon {
        width: 36px;
        height: 36px;
        font-size: 1rem;
    }
    
    .feature-content h3 {
        font-size: .9rem;
    }
    
    .feature-content p {
        font-size: .8rem;
    }
    
    .welcome-hero-graphic {
        height: 200px;
    }
    
    .welcome-shape-1 {
        width: 100px;
        height: 100px;
    }
    
    .welcome-shape-2 {
        width: 70px;
        height: 70px;
    }
    
    .welcome-shape-3 {
        width: 50px;
        height: 50px;
    }
    
    .welcome-actions {
        gap: 10px;
    }
    
    .welcome-btn-primary,
    .welcome-btn-secondary {
        padding: 12px 24px;
        font-size: .9rem;
    }
}

/* Small Mobile (<480px) */
@media (max-width: 480px) {
    .welcome-hero {
        padding: 24px 16px;
        gap: 20px;
        border-radius: 18px;
        margin-bottom: 16px;
    }
    
    .welcome-eyebrow {
        font-size: .7rem;
        padding: 5px 10px;
    }
    
    .welcome-title {
        font-size: clamp(1.2rem, 2.5vw, 1.8rem);
        margin-bottom: 4px;
    }
    
    .welcome-subtitle {
        font-size: clamp(.85rem, 1.8vw, 1rem);
    }
    
    .welcome-description {
        font-size: .9rem;
        line-height: 1.6;
    }
    
    .welcome-features {
        gap: 10px;
        margin-top: 6px;
    }
    
    .welcome-feature-card {
        padding: 12px;
        gap: 10px;
        border-radius: 12px;
    }
    
    .feature-icon {
        width: 32px;
        height: 32px;
        font-size: .9rem;
        border-radius: 8px;
    }
    
    .feature-content h3 {
        font-size: .85rem;
        margin-bottom: 2px;
    }
    
    .feature-content p {
        font-size: .75rem;
        line-height: 1.3;
    }
    
    .welcome-hero-graphic {
        display: none;
    }
    
    .welcome-actions {
        flex-direction: column;
        gap: 8px;
        margin-top: 0;
    }
    
    .welcome-btn-primary,
    .welcome-btn-secondary {
        width: 100%;
        padding: 11px 20px;
        font-size: .85rem;
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
}

/* Extra Small (<360px) */
@media (max-width: 360px) {
    .welcome-hero {
        padding: 16px 12px;
        gap: 16px;
        border-radius: 16px;
    }
    
    .welcome-title {
        font-size: 1.1rem;
    }
    
    .welcome-subtitle {
        font-size: .8rem;
    }
    
    .welcome-description {
        font-size: .85rem;
    }
    
    .welcome-features {
        gap: 8px;
    }
    
    .welcome-feature-card {
        padding: 10px;
    }
    
    .welcome-btn-primary,
    .welcome-btn-secondary {
        width: 100%;
        padding: 10px 14px;
        font-size: .8rem;
        min-height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    
    .tour-close-btn {
        width: 36px;
        height: 36px;
        font-size: 16px;
        top: 12px;
        right: 12px;
    }
}
</style>
    
   

<!-- ── Stat Cards ─────────────────────────────────────────── -->
<div class="dashboard-section-spacer">
<div class="section-heading">
    <div>
        <h3>SYSTEM DASHBOARD</h3>
        <p>Four live counts that anchor the rest of the dashboard.</p>
    </div>
</div>
<div class="stats-grid dashboard-container" id="dashboardContainer">
    <a href="<?= APP_URL ?>/teachers.php" class="report-stat-link" style="text-decoration:none;color:inherit;">
        <div class="stat-card glass-card draggable-card" data-card-id="card-teachers" data-card-name="Total Teachers" data-card-color="">
            <div class="stat-icon icon-blue"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format((int)$totalTeachers) ?></div>
                <div class="stat-label">Total Teachers</div>
            </div>
        </div>
    </a>
    <a href="<?= APP_URL ?>/schools.php" class="report-stat-link" style="text-decoration:none;color:inherit;">
        <div class="stat-card glass-card draggable-card" data-card-id="card-schools" data-card-name="Schools" data-card-color="">
            <div class="stat-icon icon-green"><i class="fas fa-school"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format((int)$totalSchools) ?></div>
                <div class="stat-label">Schools</div>
            </div>
        </div>
    </a>
    <a href="<?= APP_URL ?>/districts.php" class="report-stat-link" style="text-decoration:none;color:inherit;">
        <div class="stat-card glass-card draggable-card" data-card-id="card-districts" data-card-name="Districts" data-card-color="">
            <div class="stat-icon icon-purple"><i class="fas fa-map-marker-alt"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format((int)$totalDistricts) ?></div>
                <div class="stat-label">Districts</div>
            </div>
        </div>
    </a>
    <a href="<?= APP_URL ?>/teachers.php?pwd=yes" class="report-stat-link" style="text-decoration:none;color:inherit;">
        <div class="stat-card glass-card draggable-card" data-card-id="card-pwd" data-card-name="PWD Personnel" data-card-color="">
            <div class="stat-icon icon-orange"><i class="fas fa-universal-access"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format((int)$pwdCount) ?></div>
                <div class="stat-label">PWD Personnel</div>
            </div>
        </div>
    </a>
    <a href="<?= APP_URL ?>/retirement_watch.php" class="report-stat-link" style="text-decoration:none;color:inherit;">
        <div class="stat-card glass-card draggable-card" data-card-id="card-retirements" data-card-name="Retirement Watch (59-60)" data-card-color="">
            <div class="stat-icon icon-red"><i class="fas fa-user-clock"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format((int)$retirementWatchCount) ?></div>
                <div class="stat-label">Retirement Watch (59-60)</div>
            </div>
        </div>
    </a>
</div>
</div>

<style>
    .dashboard-notifications {
        display: grid;
        gap: 12px;
        margin-bottom: 16px;
    }
    .dashboard-notifications-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .dashboard-notifications-head h3 {
        margin: 0;
        font-size: 1.05rem;
    }
    .dashboard-notifications-head p {
        margin: 2px 0 0;
        color: var(--text-muted);
        font-size: .84rem;
    }
    .notification-pill {
        border-radius: 999px;
        padding: 6px 10px;
        font-size: .72rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #f8fafc;
        border: 1px solid rgba(148, 163, 184, .3);
        background: rgba(15, 23, 42, .45);
    }
    .dashboard-notification-list {
        display: grid;
        gap: 10px;
    }
    .dashboard-notification-item {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 12px;
        align-items: center;
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, .26);
        background: rgba(15, 23, 42, .34);
    }
    .dashboard-notification-item .ni-icon {
        width: 40px;
        height: 40px;
        border-radius: 11px;
        display: grid;
        place-items: center;
        border: 1px solid rgba(148, 163, 184, .35);
        color: #e2e8f0;
        background: rgba(148, 163, 184, .18);
    }
    .dashboard-notification-item .ni-title {
        margin: 0;
        font-size: .92rem;
        color: #f8fafc;
        font-weight: 700;
    }
    .dashboard-notification-item .ni-detail {
        margin: 4px 0 0;
        color: #cbd5e1;
        font-size: .82rem;
        line-height: 1.45;
    }
    .dashboard-notification-item.urgent {
        border-color: rgba(248, 113, 113, .44);
        background: linear-gradient(145deg, rgba(127, 29, 29, .34), rgba(15, 23, 42, .46));
    }
    .dashboard-notification-item.warning {
        border-color: rgba(251, 191, 36, .44);
        background: linear-gradient(145deg, rgba(120, 53, 15, .30), rgba(15, 23, 42, .46));
    }
    .dashboard-notification-item.info {
        border-color: rgba(56, 189, 248, .44);
        background: linear-gradient(145deg, rgba(8, 47, 73, .34), rgba(15, 23, 42, .46));
    }
    .dashboard-notification-item.ok {
        border-color: rgba(74, 222, 128, .42);
        background: linear-gradient(145deg, rgba(20, 83, 45, .30), rgba(15, 23, 42, .46));
    }
    @media (max-width: 860px) {
        .dashboard-notification-item {
            grid-template-columns: auto 1fr;
            gap: 10px;
        }
        .dashboard-notification-item .btn {
            grid-column: 2;
            justify-self: start;
        }
    }
</style>

<?php if (!empty($dashboardNotifications)): ?>
<section class="dashboard-notifications glass-card">
    <div class="dashboard-notifications-head">
        <div>
            <h3><i class="fas fa-bell"></i> Message Notifications</h3>
            <p>Direct and group chat message updates.</p>
        </div>
        <span class="notification-pill"><?= number_format(count($dashboardNotifications)) ?> active</span>
    </div>
    <div class="dashboard-notification-list">
        <?php foreach ($dashboardNotifications as $notification): ?>
        <div class="dashboard-notification-item <?= clean((string)($notification['type'] ?? 'info')) ?>">
            <div class="ni-icon"><i class="fas <?= clean((string)($notification['icon'] ?? 'fa-circle-info')) ?>"></i></div>
            <div>
                <p class="ni-title"><?= clean((string)($notification['title'] ?? 'Notification')) ?></p>
                <p class="ni-detail"><?= clean((string)($notification['detail'] ?? '')) ?></p>
            </div>
            <a href="<?= clean((string)($notification['href'] ?? (APP_URL . '/dashboard.php'))) ?>" class="btn btn-ghost btn-sm">
                <?= clean((string)($notification['cta'] ?? 'Open')) ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!in_array(strtolower($user['role'] ?? ''), ['psds', 'sdc'], true)): ?>
 <section class="dashboard-hero glass-card">
        <div>
         
            <p>Live school composition profile across Elementary, JHS, SHS, and ALS campuses.</p>
            <div class="hero-kpis">
                <div class="hero-kpi-wrap" data-tooltip-title="Includes" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['elementary'], ENT_QUOTES) ?>">
                    <div class="hero-kpi">
                        <div class="hero-kpi-label">Elementary</div>
                        <div class="hero-kpi-value"><?= number_format((int)$snapshotElementary) ?></div>
                    </div>
                    <div class="composition-tooltip-data" data-tooltip-title="Includes" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['elementary'], ENT_QUOTES) ?>"></div>
                    <div class="composition-tooltip" role="tooltip" aria-label="Elementary composition breakdown">
                        <div class="stt-title">Includes</div>
                        <?php foreach ($compositionHoverRows['elementary'] as $row): ?>
                            <?php if (!empty($row['emphasis'])): ?>
                                <hr class="stt-divider">
                            <?php endif; ?>
                            <div class="stt-row"><span class="stt-k"><?= clean($row['label']) ?></span><span class="stt-v"><?= number_format((int)$row['count']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hero-kpi-wrap" data-tooltip-title="Includes" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['jhs'], ENT_QUOTES) ?>">
                    <div class="hero-kpi">
                        <div class="hero-kpi-label">JHS</div>
                        <div class="hero-kpi-value"><?= number_format((int)$snapshotJhs) ?></div>
                    </div>
                    <div class="composition-tooltip-data" data-tooltip-title="Includes" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['jhs'], ENT_QUOTES) ?>"></div>
                    <div class="composition-tooltip" role="tooltip" aria-label="JHS composition breakdown">
                        <div class="stt-title">Includes</div>
                        <?php foreach ($compositionHoverRows['jhs'] as $row): ?>
                            <?php if (!empty($row['emphasis'])): ?>
                                <hr class="stt-divider">
                            <?php endif; ?>
                            <div class="stt-row"><span class="stt-k"><?= clean($row['label']) ?></span><span class="stt-v"><?= number_format((int)$row['count']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hero-kpi-wrap" data-tooltip-title="Includes" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['shs'], ENT_QUOTES) ?>">
                    <div class="hero-kpi">
                        <div class="hero-kpi-label">SHS</div>
                        <div class="hero-kpi-value"><?= number_format((int)$snapshotShs) ?></div>
                    </div>
                    <div class="composition-tooltip-data" data-tooltip-title="Includes" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['shs'], ENT_QUOTES) ?>"></div>
                    <div class="composition-tooltip" role="tooltip" aria-label="SHS composition breakdown">
                        <div class="stt-title">Includes</div>
                        <?php foreach ($compositionHoverRows['shs'] as $row): ?>
                            <?php if (!empty($row['emphasis'])): ?>
                                <hr class="stt-divider">
                            <?php endif; ?>
                            <div class="stt-row"><span class="stt-k"><?= clean($row['label']) ?></span><span class="stt-v"><?= number_format((int)$row['count']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hero-kpi-wrap" data-tooltip-title="Breakdown" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['als'], ENT_QUOTES) ?>">
                    <div class="hero-kpi">
                        <div class="hero-kpi-label">ALS</div>
                        <div class="hero-kpi-value"><?= number_format((int)$snapshotAls) ?></div>
                    </div>
                    <div class="composition-tooltip-data" data-tooltip-title="Breakdown" data-tooltip-breakdown="<?= htmlspecialchars($compositionTooltipTitles['als'], ENT_QUOTES) ?>"></div>
                    <div class="composition-tooltip" role="tooltip" aria-label="ALS composition breakdown">
                        <div class="stt-title">Breakdown</div>
                        <?php foreach ($compositionHoverRows['als'] as $row): ?>
                            <?php if (!empty($row['emphasis'])): ?>
                                <hr class="stt-divider">
                            <?php endif; ?>
                            <div class="stt-row"><span class="stt-k"><?= clean($row['label']) ?></span><span class="stt-v"><?= number_format((int)$row['count']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="workforce-summary-text">
                Untagged schools: <strong><?= number_format((int)$snapshotUntagged) ?></strong> | Total schools: <strong><?= number_format((int)$totalSchools) ?></strong>
            </div>
          
            <div class="dashboard-highlights">
                <div class="highlight-chip">
                    <strong><?= number_format((int)$schoolsWithTeachers) ?></strong>
                    <span>Schools already staffed</span>
                </div>
                <div class="highlight-chip">
                    <strong><?= number_format($schoolCoveragePct, 1) ?>%</strong>
                    <span>System coverage right now</span>
                </div>
            </div>
        </div>
        <div class="dashboard-hero-actions">
            <div class="workforce-snapshot-graph">
                <p class="workforce-graph-title">Schools by Type</p>
                <canvas id="workforceSnapshotChart"></canvas>
            </div>
            <div class="dashboard-hero-actions" style="grid-template-rows:none;grid-template-columns:1fr 1fr;">
                <button id="customizeToggle" class="btn btn-sm btn-primary" title="Customize Dashboard">
                    <i class="fas fa-sliders-h"></i> Customize Layout
                </button>
                <a href="<?= APP_URL ?>/reports.php" class="btn btn-sm btn-ghost">
                    <i class="fas fa-chart-column"></i> View Reports
                </a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if (!in_array(strtolower($user['role'] ?? ''), ['psds', 'sdc'], true)): ?>
<!-- ── Charts Row 1 ───────────────────────────────────────── -->
<div class="section-heading">
    <div>
        <h3>Gender & District Distribution</h3>
        <p>Compare gender balance and district staffing side by side.</p>
    </div>
</div>
<div class="charts-grid dashboard-container">
    <div class="chart-card glass-card draggable-card" data-card-id="card-gender" data-card-name="Gender Distribution" data-card-color="">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-venus-mars"></i> Gender Distribution</h3>
        </div>
        <div class="chart-container chart-sm">
            <canvas id="genderChart"></canvas>
        </div>
    </div>

    <div class="chart-card glass-card draggable-card" data-card-id="card-district" data-card-name="Teachers per District" data-card-color="">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-map-marked-alt"></i> Teachers per District</h3>
        </div>
        <div class="chart-container">
            <canvas id="districtChart"></canvas>
        </div>
    </div>
</div>

<!-- ── Charts Row 2 ───────────────────────────────────────── -->
<div class="charts-grid dashboard-container">
    <div class="chart-card glass-card draggable-card" data-card-id="card-age" data-card-name="Age Distribution" data-card-color="">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-birthday-cake"></i> Age Distribution</h3>
        </div>
        <div class="chart-container">
            <canvas id="ageChart"></canvas>
        </div>
    </div>
    <div class="chart-card glass-card draggable-card" data-card-id="card-position" data-card-name="Position Breakdown" data-card-color="">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-briefcase"></i> Position Breakdown</h3>
        </div>
        <div class="chart-container">
            <canvas id="positionChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Charts Row 2.5 ─────────────────────────────────────── -->
<div class="charts-grid dashboard-container coverage-row">
    <div class="chart-card glass-card chart-wide draggable-card" data-card-id="card-retirement-watch" data-card-name="Retirement Projection" data-card-color="">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-hourglass-half"></i> Retirement Projection (Age 59+)</h3>
            <span class="text-muted small"><?= number_format((int)$retireWithin12Months) ?> reaching 60 within 12 months</span>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Teacher</th>
                        <th>School</th>
                        <th>Position</th>
                        <th>Current Age</th>
                        <th>Projection to 60</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($retirementWatchRows as $rt): ?>
                    <?php
                        $monthsTo60 = (int)($rt['months_until_60'] ?? 0);
                        $projectionText = $formatRetirementMonths($monthsTo60);
                        $projectionBadge = $monthsTo60 < 0
                            ? 'badge-danger'
                            : ($monthsTo60 <= 12 ? 'badge-orange' : 'badge-blue');
                    ?>
                    <tr>
                        <td><?= clean(trim((string)($rt['last_name'] ?? '') . ', ' . (string)($rt['first_name'] ?? ''))) ?></td>
                        <td><?= clean((string)($rt['school_name'] ?? '—')) ?></td>
                        <td><?= clean((string)($rt['position'] ?? '—')) ?></td>
                        <td><?= number_format((int)($rt['age_years'] ?? 0)) ?></td>
                        <td><span class="badge <?= $projectionBadge ?>"><?= clean($projectionText) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($retirementWatchRows)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No teachers are currently at age 59 and above.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php if (!in_array(strtolower($user['role'] ?? ''), ['psds', 'sdc'], true)): ?>
    <div class="chart-card glass-card draggable-card coverage-card" data-card-id="card-school-coverage" data-card-name="School Coverage" data-card-color="">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-school-circle-check"></i> Schools With Teachers</h3>
            <div class="coverage-summary-badge"><i class="fas fa-chart-line"></i> <?= number_format($schoolCoveragePct, 1) ?>% coverage</div>
        </div>
        <div class="coverage-layout">
            <div class="coverage-meter">
                <div class="coverage-meter-top">
                    <div>
                        <div class="coverage-kicker">Coverage Snapshot</div>
                        <div class="coverage-percent"><?= number_format($schoolCoveragePct, 1) ?>%</div>
                    </div>
                    <div class="coverage-caption"><?= number_format((int)$schoolsWithTeachers) ?> of <?= number_format((int)$totalSchools) ?> schools are currently staffed.</div>
                </div>
                <div class="coverage-bar-track" aria-hidden="true">
                    <div class="coverage-bar-fill" style="width: <?= min(100, max(0, (float)$schoolCoveragePct)) ?>%"></div>
                </div>
                <div class="coverage-scale">
                    <span>0%</span>
                    <span>Staffed</span>
                    <span>100%</span>
                </div>
                <div class="coverage-footnote">A school is counted as staffed once it has at least one linked teacher record.</div>
            </div>
            <div class="coverage-stats">
                <div class="coverage-stat">
                    <div class="coverage-stat-label">With Teachers</div>
                    <div class="coverage-stat-value"><?= number_format((int)$schoolsWithTeachers) ?></div>
                    <div class="coverage-stat-note">Schools with active teacher links.</div>
                </div>
                <div class="coverage-stat">
                    <div class="coverage-stat-label">Still Unstaffed</div>
                    <div class="coverage-stat-value"><?= number_format((int)$schoolsWithoutTeachers) ?></div>
                    <div class="coverage-stat-note">Schools still missing a linked teacher.</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!in_array(strtolower($user['role'] ?? ''), ['psds', 'sdc'], true)): ?>
<!-- ── Charts Row 3 ───────────────────────────────────────── -->
<div class="charts-grid dashboard-container coverage-row">
    <div class="chart-card glass-card chart-wide draggable-card" data-card-id="card-school-need" data-card-name="School Teacher Need" data-card-color="">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user-plus"></i> School Teacher Need (Top Shortage)</h3>
        </div>
        <div class="chart-container">
            <canvas id="schoolNeedChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!in_array(strtolower($user['role'] ?? ''), ['psds', 'sdc'], true)): ?>
<!-- ── Recent Data Tables ─────────────────────────────────── -->
<div class="section-heading">
    <div>
        <h3>Recent Activity</h3>
        <p>Newest teacher records and upload history.</p>
    </div>
</div>
<div class="tables-grid">
    <div class="table-card glass-card">
        <div class="card-header">
            <div class="table-heading-group">
                <h3 class="card-title"><i class="fas fa-clock"></i> Recently Added Teachers</h3>
                <div class="table-subtitle">Latest teacher records added to the system.</div>
            </div>
            <a href="<?= APP_URL ?>/teachers.php" class="btn btn-sm btn-ghost">View All</a>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Added</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentTeachers as $t): ?>
                <tr>
                    <td>
                        <div class="teacher-cell">
                            <div class="mini-avatar">
                                <?php if ($t['profile_photo']): ?>
                                <img src="<?= UPLOAD_URL . clean($t['profile_photo']) ?>" alt="">
                                <?php else: ?>
                                <?= strtoupper(substr($t['last_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="teacher-meta">
                                <div class="teacher-name"><?= clean($t['last_name']) ?>, <?= clean($t['first_name']) ?></div>
                                <div class="teacher-position"><?= clean($t['position'] ?? '—') ?></div>
                                <div class="teacher-school"><?= clean($t['school_name'] ?? '—') ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="teacher-date-badge"><?= formatDate($t['created_at'] ?? '') ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recentTeachers): ?>
                <tr><td colspan="2" class="text-center text-muted">No records yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-card glass-card">
        <div class="card-header">
            <div class="table-heading-group">
                <h3 class="card-title"><i class="fas fa-upload"></i> Recent Uploads</h3>
                <div class="table-subtitle">Most recent bulk import activity.</div>
            </div>
            <a href="<?= APP_URL ?>/upload.php" class="btn btn-sm btn-ghost">Upload</a>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Records</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentUploads as $u): ?>
                <tr>
                    <td>
                        <div class="teacher-meta">
                            <div class="teacher-name"><?= clean($u['file_name']) ?></div>
                            <div class="muted-inline">by <?= clean($u['uploader_name'] ?? '—') ?></div>
                        </div>
                    </td>
                    <td><span class="record-badge"><?= number_format((int)$u['total_rows']) ?> records</span></td>
                    <td><span class="teacher-date-badge"><?= formatDate($u['created_at'] ?? '') ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recentUploads): ?>
                <tr><td colspan="3" class="text-center text-muted">No uploads yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>
<?php endif; ?>

<script>
// @ts-nocheck
// ── Dashboard Customization ─────────────────────────────────
const COLORS = {
    blue: 'rgba(99,102,241,.2)',
    green: 'rgba(16,185,129,.2)',
    purple: 'rgba(168,85,247,.2)',
    orange: 'rgba(245,158,11,.2)',
    red: 'rgba(239,68,68,.2)',
    pink: 'rgba(236,72,153,.2)',
    cyan: 'rgba(34,211,238,.2)',
    yellow: 'rgba(234,179,8,.2)'
};

const COLOR_HEX = {
    blue: '#6366f1',
    green: '#10b981',
    purple: '#a855f7',
    orange: '#f59e0b',
    red: '#ef4444',
    pink: '#ec4899',
    cyan: '#22d3ee',
    yellow: '#eab308'
};

class DashboardCustomizer {
    constructor() {
        this.customizeMode = false;
        this.cardOrder = [];
        this.cardColors = {};
        this.draggedElement = null;
        this.init();
    }

    init() {
        this.loadPreferences();
        this.setupEventListeners();
        this.renderCardsList();
        this.applyCustomizations();
    }

    loadPreferences() {
        const saved = localStorage.getItem('dashboardPreferences');
        if (saved) {
            const prefs = JSON.parse(saved);
            this.cardColors = prefs.colors || {};
        }
    }

    savePreferences() {
        localStorage.setItem('dashboardPreferences', JSON.stringify({
            colors: this.cardColors
        }));
    }

    setupEventListeners() {
        const customizeToggle = document.getElementById('customizeToggle');
        const closePanelBtn = document.getElementById('closePanelBtn');
        const toggleEditMode = document.getElementById('toggleEditMode');
        const resetDashboard = document.getElementById('resetDashboard');
        
        if (customizeToggle) customizeToggle.addEventListener('click', () => this.togglePanel());
        if (closePanelBtn) closePanelBtn.addEventListener('click', () => this.togglePanel());
        if (toggleEditMode) toggleEditMode.addEventListener('click', () => this.toggleEditMode());
        if (resetDashboard) resetDashboard.addEventListener('click', () => this.resetToDefault());

        // Drag and drop listeners
        document.addEventListener('dragstart', (e) => this.handleDragStart(e));
        document.addEventListener('dragover', (e) => this.handleDragOver(e));
        document.addEventListener('drop', (e) => this.handleDrop(e));
        document.addEventListener('dragend', (e) => this.handleDragEnd(e));
    }

    togglePanel() {
        document.getElementById('customizePanel').classList.toggle('active');
    }

    toggleEditMode() {
        this.customizeMode = !this.customizeMode;
        const btn = document.getElementById('toggleEditMode');
        const container = document.getElementById('dashboardContainer').closest('main');
        
        if (this.customizeMode) {
            document.querySelectorAll('.draggable-card').forEach(card => {
                card.draggable = true;
            });
            btn.innerHTML = '<i class="fas fa-unlock"></i> Disable Edit Mode';
            container.classList.add('customize-mode');
            Swal.fire('Edit Mode Enabled', 'Drag cards to reorder them. Click customize again to finish.', 'info');
        } else {
            document.querySelectorAll('.draggable-card').forEach(card => {
                card.draggable = false;
            });
            btn.innerHTML = '<i class="fas fa-lock"></i> Enable Edit Mode';
            container.classList.remove('customize-mode');
        }
    }

    handleDragStart(e) {
        if (!this.customizeMode || !e.target.classList.contains('draggable-card')) return;
        this.draggedElement = e.target;
        e.target.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    }

    handleDragOver(e) {
        if (!this.customizeMode) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        
        const card = e.target.closest('.draggable-card');
        if (card && card !== this.draggedElement) {
            card.style.opacity = '0.5';
        }
    }

    handleDrop(e) {
        if (!this.customizeMode) return;
        e.preventDefault();
        
        const dropTarget = e.target.closest('.draggable-card');
        if (dropTarget && dropTarget !== this.draggedElement && dropTarget.parentNode === this.draggedElement.parentNode) {
            dropTarget.parentNode.insertBefore(this.draggedElement, dropTarget);
        }
    }

    handleDragEnd(e) {
        if (!this.customizeMode) return;
        e.target.classList.remove('dragging');
        document.querySelectorAll('.draggable-card').forEach(card => {
            card.style.opacity = '1';
        });
    }

    renderCardsList() {
        const container = document.getElementById('cardsList');
        const cards = [
            { id: 'card-teachers', name: 'Total Teachers' },
            { id: 'card-schools', name: 'Schools' },
            { id: 'card-districts', name: 'Districts' },
            { id: 'card-pwd', name: 'PWD Personnel' },
            { id: 'card-retirements', name: 'Retirement Watch (59-60)' },
            { id: 'card-gender', name: 'Gender Distribution' },
            { id: 'card-district', name: 'Teachers per District' },
            { id: 'card-age', name: 'Age Distribution' },
            { id: 'card-position', name: 'Position Breakdown' },
            { id: 'card-retirement-watch', name: 'Retirement Projection' },
            { id: 'card-school-coverage', name: 'School Coverage' },
            { id: 'card-school-need', name: 'School Teacher Need' }
        ];

        container.innerHTML = cards.map(card => `
            <div class="card-customize">
                <label>${card.name}</label>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                    ${Object.entries(COLOR_HEX).map(([colorName, colorValue]) => `
                        <div class="color-option" 
                             style="background-color: ${colorValue};" 
                             data-card="${card.id}" 
                             data-color="${colorName}"
                             title="${colorName}"
                             onclick="dashboardCustomizer.setCardColor('${card.id}', '${colorName}')">
                        </div>
                    `).join('')}
                </div>
            </div>
        `).join('');
    }

    setCardColor(cardId, colorName) {
        this.cardColors[cardId] = colorName;
        this.savePreferences();
        this.applyCustomizations();
    }

    applyCustomizations() {
        document.querySelectorAll('.draggable-card').forEach(card => {
            const cardId = card.dataset.cardId;
            const color = this.cardColors[cardId];
            
            if (color) {
                card.style.backgroundColor = COLORS[color];
                card.style.borderColor = COLOR_HEX[color];
                card.style.borderWidth = '1px';
            } else {
                card.style.backgroundColor = '';
                card.style.borderColor = '';
            }
        });
    }

    resetToDefault() {
        if (confirm('Reset all customizations to default?')) {
            this.cardColors = {};
            localStorage.removeItem('dashboardPreferences');
            this.applyCustomizations();
            Swal.fire('Reset Complete', 'Dashboard customizations have been reset.', 'success');
        }
    }
}

// Initialize customizer (only if customize button exists)
if (document.getElementById('customizeToggle')) {
    const dashboardCustomizer = new DashboardCustomizer();
}

// ── Composition Count Hover Tooltip ─────────────────────────
(function initCompositionHoverTooltip() {
    const layer = document.createElement('div');
    layer.id = 'compositionTooltipLayer';
    layer.className = 'composition-tooltip-layer';
    layer.setAttribute('aria-hidden', 'true');
    document.body.appendChild(layer);

    let activeTarget = null;

    function showTooltip(target) {
        const title = target.getAttribute('data-tooltip-title') || 'Includes';
        const breakdown = target.getAttribute('data-tooltip-breakdown') || '';
        const lines = breakdown ? breakdown.split('\n').filter(Boolean) : [];

        layer.innerHTML = '';
        const titleEl = document.createElement('div');
        titleEl.className = 'stt-title';
        titleEl.textContent = title;
        layer.appendChild(titleEl);

        lines.forEach((line, index) => {
            const parts = line.split(': ');
            if (parts.length < 2) return;
            if (index > 0 && parts[0] === 'Total') {
                const divider = document.createElement('hr');
                divider.className = 'stt-divider';
                layer.appendChild(divider);
            }
            const row = document.createElement('div');
            row.className = 'stt-row';
            const label = document.createElement('span');
            label.className = 'stt-k';
            label.textContent = parts[0];
            const value = document.createElement('span');
            value.className = 'stt-v';
            value.textContent = parts.slice(1).join(': ');
            row.appendChild(label);
            row.appendChild(value);
            layer.appendChild(row);
        });

        const rect = target.getBoundingClientRect();
        const tooltipRect = layer.getBoundingClientRect();
        const preferredTop = rect.bottom + 10;
        const top = (preferredTop + tooltipRect.height + 12 < window.innerHeight) ? preferredTop : Math.max(12, rect.top - tooltipRect.height - 10);
        const left = Math.min(Math.max(rect.left + (rect.width / 2), 16), window.innerWidth - 16);

        layer.style.left = left + 'px';
        layer.style.top = top + 'px';
        layer.classList.add('is-visible');
        activeTarget = target;
    }

    function hideTooltip() {
        layer.classList.remove('is-visible');
        activeTarget = null;
    }

    document.querySelectorAll('.hero-kpi-wrap').forEach((target) => {
        target.addEventListener('mouseenter', () => showTooltip(target));
        target.addEventListener('mouseleave', () => hideTooltip());
        target.addEventListener('focusin', () => showTooltip(target));
        target.addEventListener('focusout', () => hideTooltip());
    });

    window.addEventListener('scroll', () => {
        if (activeTarget) showTooltip(activeTarget);
    }, { passive: true });
    window.addEventListener('resize', () => {
        if (activeTarget) showTooltip(activeTarget);
    });
})();

// ── Dashboard Charts ─────────────────────────────────────────
const glassGradients = ['rgba(14,165,233,.82)','rgba(16,185,129,.82)','rgba(249,115,22,.82)','rgba(239,68,68,.82)','rgba(59,130,246,.82)','rgba(234,179,8,.82)'];

function cssVar(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name);
    const cleaned = String(value || '').trim();
    return cleaned || fallback;
}

function dashboardChartPalette() {
    return {
        text: cssVar('--text', '#e2e8f0'),
        muted: cssVar('--text-muted', '#94a3b8'),
        sub: cssVar('--text-sub', '#cbd5e1'),
        gridSoft: 'rgba(148,163,184,.16)',
        gridLite: 'rgba(148,163,184,.12)'
    };
}

// Wait for Chart.js to load
function initCharts() {
    if (typeof Chart === 'undefined') {
        setTimeout(initCharts, 100);
        return;
    }

    const palette = dashboardChartPalette();

    // Gender Doughnut
    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut',
        data: { labels: <?= $genderLabels ?>, datasets: [{ data: <?= $genderCounts ?>, backgroundColor: glassGradients, borderWidth: 0, hoverOffset: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: palette.text, font: { size: 12 } } } } }
    });

    // Workforce Snapshot (hero)
    new Chart(document.getElementById('workforceSnapshotChart'), {
        type: 'bar',
        data: {
            labels: ['Elementary', 'JHS', 'SHS', 'ALS', 'Untagged'],
            datasets: [{
                data: [<?= (int)$snapshotElementary ?>, <?= (int)$snapshotJhs ?>, <?= (int)$snapshotShs ?>, <?= (int)$snapshotAls ?>, <?= (int)$snapshotUntagged ?>],
                backgroundColor: ['rgba(16,185,129,.78)', 'rgba(59,130,246,.78)', 'rgba(245,158,11,.8)', 'rgba(236,72,153,.78)', 'rgba(148,163,184,.75)'],
                borderRadius: 9,
                barThickness: 18,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.raw;
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { color: palette.muted, precision: 0 },
                    grid: { color: palette.gridSoft }
                },
                y: {
                    ticks: { color: palette.sub, font: { size: 10 } },
                    grid: { display: false }
                }
            }
        }
    });

    // District Bar
    new Chart(document.getElementById('districtChart'), {
        type: 'bar',
        data: {
            labels: <?= $districtLabels ?>,
            datasets: [{ label: 'Teachers', data: <?= $districtCounts ?>, backgroundColor: 'rgba(99,102,241,.7)', borderRadius: 6, borderWidth: 0 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: palette.muted }, grid: { color: palette.gridLite } },
                y: { ticks: { color: palette.text, font: { size: 11 } }, grid: { display: false } }
            }
        }
    });

    // Age Bar
    new Chart(document.getElementById('ageChart'), {
        type: 'bar',
        data: {
            labels: <?= $ageLabels ?>,
            datasets: [{ label: 'Count', data: <?= $ageCounts ?>, backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 6, borderWidth: 0 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: palette.text }, grid: { display: false } },
                y: { ticks: { color: palette.muted }, grid: { color: palette.gridLite } }
            }
        }
    });

    // Position Doughnut
    new Chart(document.getElementById('positionChart'), {
        type: 'doughnut',
        data: { labels: <?= $posLabels ?>, datasets: [{ data: <?= $posCounts ?>, backgroundColor: glassGradients, borderWidth: 0, hoverOffset: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: palette.text, font: { size: 11 } } } } }
    });

    // School Teacher Need Bar
    const schoolNeedLabels = <?= $schoolNeedLabels ?>;
    const schoolNeedCounts = <?= $schoolNeedCounts ?>;
    new Chart(document.getElementById('schoolNeedChart'), {
        type: 'bar',
        data: {
            labels: schoolNeedLabels.length ? schoolNeedLabels : ['No shortage found'],
            datasets: [{
                label: 'Teacher Need',
                data: schoolNeedCounts.length ? schoolNeedCounts : [0],
                backgroundColor: 'rgba(239,68,68,.75)',
                borderRadius: 6,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Need: ' + context.raw + ' teacher(s)';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { color: palette.muted, precision: 0 },
                    grid: { color: palette.gridLite }
                },
                y: {
                    ticks: { color: palette.text, font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });
}

// Initialize charts when DOM is ready
document.addEventListener('DOMContentLoaded', initCharts);

// ── Dashboard Tour Functionality ───────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const tourElement = document.getElementById('dashboardTour');
    const backdrop = document.getElementById('tourBackdrop');
    const closeBtn = document.getElementById('tourCloseBtn');
    const getStartedBtn = document.getElementById('tourGetStartedBtn');
    
    if (!tourElement) return; // Tour already completed or hidden
    
    // Function to complete tour
    function completeTour() {
        if (!tourElement) return;
        
        // Show loading state on button
        if (getStartedBtn) {
            getStartedBtn.disabled = true;
            getStartedBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        }
        
        tourElement.classList.add('tour-exit');
        
        // Send completion request to server
        fetch('<?= APP_URL ?>/dashboard.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'action=complete_tour',
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('AJAX Response status:', response.status);
            if (!response.ok) {
                console.warn('Tour completion response not ok:', response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Tour completion response:', data);
            if (data.success) {
                // Database update succeeded - hide tour and reload to confirm persistence
                if (backdrop) {
                    backdrop.style.display = 'none';
                }
                if (tourElement) {
                    tourElement.style.display = 'none';
                }
                console.log('Tour hidden successfully, reloading page to confirm...');
                
                // Reload after a brief delay to confirm tour stays hidden
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                console.error('Tour completion failed:', data.error);
                // Re-enable button if it fails
                if (getStartedBtn) {
                    getStartedBtn.disabled = false;
                    getStartedBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Get Started';
                }
            }
        })
        .catch(err => {
            console.error('Tour completion fetch error:', err);
            // Still hide tour even if request fails
            if (backdrop) {
                backdrop.style.display = 'none';
            }
            if (tourElement) {
                tourElement.style.display = 'none';
            }
            // Re-enable button if it fails
            if (getStartedBtn) {
                getStartedBtn.disabled = false;
                getStartedBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Get Started';
            }
        });
    }
    
    // Close button handler
    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Tour close button clicked');
            completeTour();
        });
    }
    
    // Get started button handler
    if (getStartedBtn) {
        getStartedBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Tour get started button clicked');
            completeTour();
        });
    }
    
    console.log('Tour initialized:', {tourElement: !!tourElement, closeBtn: !!closeBtn, getStartedBtn: !!getStartedBtn});
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
