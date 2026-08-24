<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireRole(['admin', 'hr']);
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'Invalid undo request.');
    redirect(APP_URL . '/teachers.php');
}

$db = getDB();

try {
    $me = (int)(currentUser()['id'] ?? 0);
    $requestedUploadId = (int)($_POST['upload_log_id'] ?? 0);
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($confirmPassword === '') {
        flash('error', 'Password confirmation is required to undo uploads.');
        redirect(APP_URL . '/teachers.php');
    }

    $pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $pwStmt->execute([$me]);
    $passwordHash = (string)$pwStmt->fetchColumn();
    if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
        flash('error', 'Invalid password. Undo was not performed.');
        redirect(APP_URL . '/teachers.php');
    }

    if ($requestedUploadId > 0) {
        $latestStmt = $db->prepare(
            'SELECT id, file_name, imported_rows
             FROM upload_logs
             WHERE id = ? AND uploaded_by = ?
             LIMIT 1'
        );
        $latestStmt->execute([$requestedUploadId, $me]);
    } else {
        $latestStmt = $db->prepare(
            'SELECT id, file_name, imported_rows
             FROM upload_logs
             WHERE uploaded_by = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $latestStmt->execute([$me]);
    }

    $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$latest) {
        flash('error', $requestedUploadId > 0 ? 'Selected upload was not found.' : 'No upload found to undo.');
        redirect(APP_URL . '/teachers.php');
    }

    $uploadLogId = (int)$latest['id'];

    $changesStmt = $db->prepare(
        'SELECT *
         FROM upload_teacher_changes
         WHERE upload_log_id = ?
         ORDER BY sequence_no DESC, id DESC'
    );
    $changesStmt->execute([$uploadLogId]);
    $changes = $changesStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$changes) {
        flash('error', 'Undo data for this upload is not available.');
        redirect(APP_URL . '/teachers.php');
    }

    $teacherCols = [];
    foreach ($db->query('SHOW COLUMNS FROM teachers')->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $teacherCols[$col['Field']] = true;
    }

    $db->beginTransaction();

    foreach ($changes as $chg) {
        $action = (string)($chg['action_type'] ?? '');
        $teacherId = (int)($chg['teacher_id'] ?? 0);
        $empNo = (string)($chg['employee_number'] ?? '');

        if ($action === 'insert') {
            if ($teacherId > 0) {
                $del = $db->prepare('DELETE FROM teachers WHERE id = ? LIMIT 1');
                $del->execute([$teacherId]);
            } elseif ($empNo !== '') {
                $del = $db->prepare('DELETE FROM teachers WHERE employee_number = ? LIMIT 1');
                $del->execute([$empNo]);
            }
            continue;
        }

        if ($action === 'update') {
            $prev = json_decode((string)($chg['previous_data'] ?? ''), true);
            if (!is_array($prev) || !$prev) {
                continue;
            }

            $setCols = [];
            $vals = [];
            foreach ($prev as $col => $val) {
                if (!isset($teacherCols[$col]) || $col === 'id' || $col === 'created_at' || $col === 'created_by') {
                    continue;
                }
                $setCols[] = "$col = ?";
                $vals[] = $val;
            }

            if ($setCols) {
                $sql = 'UPDATE teachers SET ' . implode(', ', $setCols) . ' WHERE id = ? LIMIT 1';
                $vals[] = $teacherId;
                $up = $db->prepare($sql);
                $up->execute($vals);
            }
        }
    }

    $db->prepare('DELETE FROM upload_teacher_changes WHERE upload_log_id = ?')->execute([$uploadLogId]);
    $db->prepare('DELETE FROM upload_logs WHERE id = ? LIMIT 1')->execute([$uploadLogId]);

    $db->commit();

    logActivity('UNDO', 'teachers', null, 'Undid upload: ' . ($latest['file_name'] ?? ('#' . $uploadLogId)));
    flash('success', 'Selected upload has been undone successfully.');
    redirect(APP_URL . '/teachers.php');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('TPMS Undo Latest Upload Error: ' . $e->getMessage());
    flash('error', 'Unable to undo latest upload.');
    redirect(APP_URL . '/teachers.php');
}
