<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {
    // ---------- 会话心跳：所有已登录角色可用，守卫脚本轮询 ----------
    case 'ping':
        $auth = requireAdminApi();
        jsonResponse(0, 'ok', [
            'time'  => date('Y-m-d H:i:s'),
            'role'  => $auth['admin']['role'],
            'name'  => $auth['admin']['username'],
        ]);
        break;

    // ---------- 留言 ----------
    case 'detail':
        $auth = requireAdminApi('message.view');
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
        break;

    case 'audit':
        $auth = requireAdminApi('message.audit');
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $auth = requireAdminApi('message.delete');
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
        break;

    // ---------- 举报 ----------
    case 'report_detail':
        $auth = requireAdminApi('report.view');
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
        break;

    case 'process_report':
        $auth = requireAdminApi('report.process');
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
            $stmt->execute([$status, $auth['admin']['id'], $note, $id]);

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    // ---------- 会话管理 ----------
    case 'session_list':
        $auth = requireAdminApi('session.manage');
        $adminId = $auth['admin']['id'];
        $myTokenHash = $_SESSION['session_token_hash'] ?? '';

        $stmt = $db->prepare(
            "SELECT s.token_hash, s.admin_id, s.ip, s.user_agent, s.login_at, s.last_active_at, s.expires_at,
                    a.username
             FROM admin_sessions s JOIN admins a ON a.id = s.admin_id
             WHERE s.admin_id = ? AND s.expires_at > NOW()
             ORDER BY s.last_active_at DESC"
        );
        $stmt->execute([$adminId]);
        $rows = $stmt->fetchAll();

        $now = time();
        foreach ($rows as &$r) {
            $r['device'] = describeUserAgent($r['user_agent']);
            $r['is_current'] = ($r['token_hash'] === $myTokenHash) ? 1 : 0;
            $r['online'] = ($now - strtotime($r['last_active_at']) < 300) ? 1 : 0;
            $r['idle_seconds_left'] = max(0, ADMIN_IDLE_TIMEOUT - ($now - strtotime($r['last_active_at'])));
        }
        unset($r);
        jsonResponse(0, 'ok', [
            'list'          => $rows,
            'current_token' => $myTokenHash,
            'idle_timeout'  => ADMIN_IDLE_TIMEOUT,
        ]);
        break;

    case 'session_kill':
        $auth = requireAdminApi('session.manage');
        $tokenHash = $_POST['token'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $tokenHash)) jsonResponse(1, '无效的会话标识');
        if ($tokenHash === ($_SESSION['session_token_hash'] ?? '')) {
            jsonResponse(1, '不能退出当前设备，请使用“退出登录”');
        }
        // 只能退出自己账号的其他设备
        $stmt = $db->prepare("SELECT admin_id FROM admin_sessions WHERE token_hash = ?");
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(1, '会话已失效');
        if ((int)$row['admin_id'] !== (int)$auth['admin']['id']) {
            http_response_code(403);
            jsonResponse(403, '只能退出本人账号的会话', ['reason' => 'forbidden']);
        }
        $db->prepare("DELETE FROM admin_sessions WHERE token_hash = ? AND admin_id = ?")
           ->execute([$tokenHash, $auth['admin']['id']]);
        jsonResponse(0, '该设备已退出，对应入口立即失效');
        break;

    case 'session_kill_others':
        $auth = requireAdminApi('session.manage');
        $myTokenHash = $_SESSION['session_token_hash'] ?? '';
        $db->prepare("DELETE FROM admin_sessions WHERE admin_id = ? AND token_hash <> ?")
           ->execute([$auth['admin']['id'], $myTokenHash]);
        jsonResponse(0, '已退出当前账号在其他设备上的全部会话');
        break;

    // ---------- 管理员管理（仅超级管理员） ----------
    case 'admin_list':
        $auth = requireAdminApi('admin.manage');
        $rows = $db->query(
            "SELECT a.id, a.username, a.role, a.status, a.last_login_at, a.last_login_ip, a.created_at,
                    (SELECT COUNT(*) FROM admin_sessions s WHERE s.admin_id = a.id AND s.expires_at > NOW()) AS active_sessions
             FROM admins a ORDER BY a.id ASC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['role_label'] = roleLabel($r['role']);
        }
        unset($r);
        jsonResponse(0, 'ok', ['list' => $rows, 'current_id' => $auth['admin']['id']]);
        break;

    case 'admin_save':
        $auth = requireAdminApi('admin.manage');
        $id = intval($_POST['id'] ?? 0);
        $role = $_POST['role'] ?? '';
        $status = intval($_POST['status'] ?? 1);

        if (!in_array($role, ['super_admin', 'auditor', 'viewer'], true)) jsonResponse(1, '无效角色');
        if (!in_array($status, [0, 1], true)) jsonResponse(1, '无效状态');

        $stmt = $db->prepare("SELECT id, username FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) jsonResponse(1, '管理员不存在');

        // 保护自己：不能停用或降级自己，避免锁死后台
        if ($id === (int)$auth['admin']['id'] && ($status === 0 || $role !== 'super_admin')) {
            jsonResponse(1, '不能停用或降低当前登录账号的权限');
        }

        $db->prepare("UPDATE admins SET role = ?, status = ? WHERE id = ?")
           ->execute([$role, $status, $id]);

        // 不直接删会话：目标设备下次请求时由鉴权检查发现角色/状态变化，
        // 收到精确的 role_changed / disabled 提示并回到登录页，重新登录后按新权限生效
        jsonResponse(0, '已保存，该账号已登录的设备将在下次操作时收到提示并需重新登录');
        break;

    case 'admin_create':
        $auth = requireAdminApi('admin.manage');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';

        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) jsonResponse(1, '用户名需为 3-50 位字母、数字或下划线');
        if (strlen($password) < 6) jsonResponse(1, '密码至少 6 位');
        if (!in_array($role, ['super_admin', 'auditor', 'viewer'], true)) jsonResponse(1, '无效角色');

        $stmt = $db->prepare("SELECT id FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) jsonResponse(1, '用户名已存在');

        $db->prepare("INSERT INTO admins (username, password, role, status) VALUES (?, ?, ?, 1)")
           ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
        jsonResponse(0, '管理员已创建');
        break;

    case 'admin_reset_password':
        $auth = requireAdminApi('admin.manage');
        $id = intval($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 6) jsonResponse(1, '密码至少 6 位');

        $stmt = $db->prepare("SELECT id FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) jsonResponse(1, '管理员不存在');

        $db->prepare("UPDATE admins SET password = ? WHERE id = ?")
           ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);

        // 改密后踢出除操作者本人设备外的全部会话（给自己改密时不应把自己踢下线）
        $keep = ($id === (int)$auth['admin']['id']) ? ($_SESSION['session_token_hash'] ?? null) : null;
        revokeAllAdminSessions($db, $id, $keep);
        jsonResponse(0, '密码已重置，其他设备已要求重新登录');
        break;

    default:
        jsonResponse(1, '未知操作');
}
