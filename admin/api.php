<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// 所有接口必须登录；失效统一返回 401 JSON（由 admin.js 拦截回登录页）
requireAdmin('api');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();
$admin = $GLOBALS['current_admin'];
$currentToken = $GLOBALS['current_session_token'];

/* -------------- 会话状态心跳 -------------- */
if ($action === 'session_check') {
    jsonResponse(0, 'ok', [
        'sig'  => md5($admin['id'] . '|' . $admin['role']),
        'role' => $admin['role'],
    ]);
}

/* -------------- 留言相关 -------------- */
if ($action === 'detail') {
    // 全部登录角色可查看
    $id = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
    $stmt->execute([$id]);
    $msg = $stmt->fetch();
    if (!$msg) jsonResponse(1, '留言不存在');
    $msg['type_label'] = getTypeLabel($msg['type']);
    $msg['status_label'] = getStatusLabel($msg['status']);
    $msg['content'] = nl2br(cleanInput($msg['content']));
    $msg['title'] = cleanInput($msg['title']);
    $msg['nickname'] = cleanInput($msg['nickname']);
    jsonResponse(0, 'ok', $msg);
}

if ($action === 'audit') {
    requirePermission('message.audit', 'api');
    $id = intval($_POST['id'] ?? 0);
    $status = intval($_POST['status'] ?? 0);
    if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
    $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    jsonResponse(0, '操作成功');
}

if ($action === 'delete') {
    requirePermission('message.delete', 'api');
    $id = intval($_POST['id'] ?? 0);
    // 删除关联图片
    $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
    $stmt->execute([$id]);
    $msg = $stmt->fetch();
    if ($msg && $msg['image']) {
        $imgFile = __DIR__ . '/../' . $msg['image'];
        if (file_exists($imgFile)) unlink($imgFile);
    }
    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
    jsonResponse(0, '删除成功');
}

/* -------------- 举报相关 -------------- */
if ($action === 'report_detail') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch();
    if (!$report) jsonResponse(1, '举报不存在');

    $report['report_type_label'] = getReportTypeLabel($report['report_type']);
    $report['status_label'] = getReportStatusLabel($report['status']);
    $report['status_class'] = getReportStatusClass($report['status']);
    $report['message_exists'] = !empty($report['message_title']);
    $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
    $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
    $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
    $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
    $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
    $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
    $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

    jsonResponse(0, 'ok', $report);
}

if ($action === 'process_report') {
    requirePermission('report.process', 'api');
    $id = intval($_POST['id'] ?? 0);
    $status = intval($_POST['status'] ?? 0);
    $note = cleanInput($_POST['note'] ?? '');

    if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效状态');

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在或已处理');

        if ($status === 1) {
            $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
            $stmt->execute([$report['message_id']]);
            $msg = $stmt->fetch();
            if ($msg && $msg['image']) {
                $imgFile = __DIR__ . '/../' . $msg['image'];
                if (file_exists($imgFile)) unlink($imgFile);
            }
            $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
        }

        $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
        $stmt->execute([$status, $admin['id'], $note, $id]);

        $db->commit();

        $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
        jsonResponse(0, $statusMsg[$status] . '成功');
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(1, '操作失败: ' . $e->getMessage());
    }
}

/* -------------- 会话管理（所有登录账号可管理本人会话；超管可管理全部） -------------- */
if ($action === 'session_revoke') {
    $token = $_POST['token'] ?? '';
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) jsonResponse(1, '参数错误');
    if ($token === $currentToken) jsonResponse(1, '当前会话请使用“退出登录”');

    // 只能退出自己的会话；操作他人会话需要 admin.manage 权限
    if (!adminCan('admin.manage')) {
        $stmt = $db->prepare("SELECT id FROM admin_sessions WHERE token = ? AND admin_id = ?");
        $stmt->execute([$token, $admin['id']]);
        if (!$stmt->fetch()) requirePermission('admin.manage', 'api');
    }

    revokeAdminSession($db, $token, 'kicked');
    jsonResponse(0, '该设备已退出，对应入口立即失效');
}

if ($action === 'session_revoke_others') {
    // 退出本人的其他设备（任何角色都可以）
    revokeAdminSessionsById($db, $admin['id'], 'kicked', $currentToken);
    jsonResponse(0, '其他设备已全部退出');
}

if ($action === 'session_revoke_admin_others') {
    requirePermission('admin.manage', 'api');
    $targetAdminId = intval($_POST['admin_id'] ?? 0);
    if ($targetAdminId <= 0) jsonResponse(1, '参数错误');
    revokeAdminSessionsById($db, $targetAdminId, 'kicked');
    jsonResponse(0, '该账号的全部会话已退出');
}

/* -------------- 管理员账号管理（仅超级管理员） -------------- */
if ($action === 'admin_create') {
    requirePermission('admin.manage', 'api');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,50}$/u', $username)) {
        jsonResponse(1, '用户名需为 2-50 位中英文、数字或下划线');
    }
    if (strlen($password) < 6 || strlen($password) > 64) {
        jsonResponse(1, '密码长度需为 6-64 位');
    }
    if (!isValidAdminRole($role)) jsonResponse(1, '请选择有效角色');
    if (findAdminByUsername($db, $username)) jsonResponse(1, '用户名已存在');

    $stmt = $db->prepare("INSERT INTO admins (username, password, role, status) VALUES (?, ?, ?, 1)");
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
    jsonResponse(0, '管理员已创建');
}

if ($action === 'admin_update') {
    requirePermission('admin.manage', 'api');
    $id = intval($_POST['id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';

    $target = findAdminById($db, $id);
    if (!$target) jsonResponse(1, '管理员不存在');

    if ($field === 'role') {
        if (!isValidAdminRole($value)) jsonResponse(1, '请选择有效角色');
        if ($id === (int)$admin['id']) jsonResponse(1, '不能修改自己的角色');
        if ($target['role'] === 'super_admin' && $value !== 'super_admin'
            && countEnabledSuperAdmins($db) <= 1) {
            jsonResponse(1, '系统至少保留一个启用的超级管理员');
        }
        $db->prepare("UPDATE admins SET role = ? WHERE id = ?")->execute([$value, $id]);
        // 角色变更：该账号现有会话立即失效，重新登录才能拿到新权限
        revokeAdminSessionsById($db, $id, 'role_changed');
        jsonResponse(0, '角色已更新，相关会话已要求重新登录');
    }

    if ($field === 'status') {
        $status = (int)$value;
        if (!in_array($status, [0, 1], true)) jsonResponse(1, '状态值无效');
        if ($id === (int)$admin['id']) jsonResponse(1, '不能停用自己的账号');
        if ($status === 0 && $target['role'] === 'super_admin'
            && countEnabledSuperAdmins($db) <= 1) {
            jsonResponse(1, '系统至少保留一个启用的超级管理员');
        }
        $db->prepare("UPDATE admins SET status = ? WHERE id = ?")->execute([$status, $id]);
        if ($status === 0) {
            // 停用立即踢下线
            revokeAdminSessionsById($db, $id, 'disabled');
        }
        jsonResponse(0, $status === 0 ? '账号已停用，其全部会话立即失效' : '账号已启用');
    }

    if ($field === 'password') {
        if (strlen($value) < 6 || strlen($value) > 64) jsonResponse(1, '密码长度需为 6-64 位');
        $db->prepare("UPDATE admins SET password = ? WHERE id = ?")
            ->execute([password_hash($value, PASSWORD_DEFAULT), $id]);
        // 重置密码后让该账号其他入口重新认证（保留操作者本人的会话，便于修改自己密码）
        revokeAdminSessionsById($db, $id, 'manual', $id === (int)$admin['id'] ? $currentToken : null);
        jsonResponse(0, '密码已重置，相关设备需要重新登录');
    }

    jsonResponse(1, '不支持的修改项');
}

if ($action === 'admin_delete') {
    requirePermission('admin.manage', 'api');
    $id = intval($_POST['id'] ?? 0);
    if ($id === (int)$admin['id']) jsonResponse(1, '不能删除自己的账号');

    $target = findAdminById($db, $id);
    if (!$target) jsonResponse(1, '管理员不存在');
    if ($target['role'] === 'super_admin' && countEnabledSuperAdmins($db) <= 1) {
        jsonResponse(1, '系统至少保留一个启用的超级管理员');
    }

    revokeAdminSessionsById($db, $id, 'disabled');
    $db->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
    jsonResponse(0, '管理员已删除，其全部会话立即失效');
}

jsonResponse(1, '未知操作');
