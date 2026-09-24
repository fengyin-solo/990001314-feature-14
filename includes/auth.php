<?php
/**
 * 后台鉴权 / 会话管理 / 登录风控 公共库
 *
 * 依赖 includes/functions.php（其中已 session_start()）与 config/database.php（getDB()）。
 * 设计要点：
 *  - 每个设备登录后在 admin_sessions 表落一条记录，$_SESSION 只保存令牌哈希；
 *    删除该记录（主动退出 / 管理员踢出）后，对应设备下一次请求立即失效。
 *  - 每次请求都以数据库中的管理员 role/status 为准，权限变化立即生效，
 *    重新登录时 session 重新生成，不会沿用旧权限。
 *  - 页面请求失效跳转 login.php?reason=xxx；API 请求返回 401/403 JSON。
 */

// 会话策略
defined('ADMIN_IDLE_TIMEOUT')  or define('ADMIN_IDLE_TIMEOUT', 1800);    // 空闲 30 分钟过期
defined('ADMIN_ABS_TIMEOUT')   or define('ADMIN_ABS_TIMEOUT', 7200);     // 最长 2 小时
defined('ADMIN_MAX_FAILURES')  or define('ADMIN_MAX_FAILURES', 5);       // 连续失败上限
defined('ADMIN_LOCK_MINUTES')  or define('ADMIN_LOCK_MINUTES', 15);      // 锁定时长（分钟）
defined('ADMIN_SESSION_TOUCH') or define('ADMIN_SESSION_TOUCH', 60);     // 更新活跃心跳的最小间隔（秒）

// 角色：super_admin 超级管理员 / auditor 审核员 / viewer 只读访客
function roleLabel($role) {
    $map = ['super_admin' => '超级管理员', 'auditor' => '审核员', 'viewer' => '只读访客'];
    return $map[$role] ?? $role;
}

/**
 * 角色权限表：permission => 允许的角色列表
 * page 查看类权限全部登录即可（含 viewer）；写操作/管理类按角色收敛。
 */
function roleCan($role, $permission) {
    static $rules = [
        'message.view'          => ['super_admin', 'auditor', 'viewer'],
        'message.audit'         => ['super_admin', 'auditor'],
        'message.delete'        => ['super_admin', 'auditor'],
        'report.view'           => ['super_admin', 'auditor', 'viewer'],
        'report.process'        => ['super_admin', 'auditor'],
        'session.manage'        => ['super_admin', 'auditor', 'viewer'], // 仅能管理自己的会话
        'admin.manage'          => ['super_admin'],
    ];
    return isset($rules[$permission]) && in_array($role, $rules[$permission], true);
}

/**
 * 获取客户端 IP
 */
function clientIp() {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/**
 * 失败原因 / 失效原因对应的中文提示（登录页与后台共用）
 */
function authReasonText($reason) {
    $map = [
        'not_logged_in' => '请先登录后台',
        'kicked'        => '您的会话已在其他设备上被退出，请重新登录',
        'expired'       => '登录会话已过期，请重新登录',
        'disabled'      => '账号已被停用，如有疑问请联系超级管理员',
        'role_changed'  => '您的账号权限已变更，请重新登录',
        'forbidden'     => '当前账号无权执行该操作',
        'logged_out'    => '您已安全退出登录',
        'locked'        => '登录失败次数过多，账号已临时锁定',
    ];
    return $map[$reason] ?? '请重新登录';
}

/**
 * 清除会话中的登录态（保留访客 visitor_id 等前台数据）
 */
function clearAdminSession() {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'],
        $_SESSION['session_token_hash'], $_SESSION['session_login_at'], $_SESSION['session_last_at']);
}

/**
 * 核心校验：检查当前请求是否持有有效管理员会话
 * 返回 ['ok'=>true,'admin'=>[...],'session'=>[...]] 或 ['ok'=>false,'reason'=>...]
 */
function adminAuthCheck($db = null) {
    if (empty($_SESSION['admin_id']) || empty($_SESSION['session_token_hash'])) {
        return ['ok' => false, 'reason' => 'not_logged_in'];
    }

    $db = $db ?: getDB();
    $stmt = $db->prepare("SELECT id, username, role, status FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin) {
        clearAdminSession();
        return ['ok' => false, 'reason' => 'kicked'];
    }
    if ((int)$admin['status'] !== 1) {
        $db->prepare("DELETE FROM admin_sessions WHERE token_hash = ?")
           ->execute([$_SESSION['session_token_hash']]);
        clearAdminSession();
        return ['ok' => false, 'reason' => 'disabled'];
    }
    // 权限/角色以数据库为准，发生变化立即要求重新登录；
    // 各设备在自己的下一次请求时独立检测并删除自己的会话行，提示语保持一致
    if (($_SESSION['admin_role'] ?? '') !== $admin['role']) {
        $db->prepare("DELETE FROM admin_sessions WHERE token_hash = ?")
           ->execute([$_SESSION['session_token_hash']]);
        clearAdminSession();
        return ['ok' => false, 'reason' => 'role_changed'];
    }

    $stmt = $db->prepare("SELECT * FROM admin_sessions WHERE token_hash = ?");
    $stmt->execute([$_SESSION['session_token_hash']]);
    $sessionRow = $stmt->fetch();

    if (!$sessionRow) {
        // 记录被主动删除：退出登录 / 被管理员踢出
        clearAdminSession();
        return ['ok' => false, 'reason' => 'kicked'];
    }

    $now = time();
    $lastActive = strtotime($sessionRow['last_active_at']);
    $expires = strtotime($sessionRow['expires_at']);
    if ($now - $lastActive > ADMIN_IDLE_TIMEOUT || $now > $expires) {
        $db->prepare("DELETE FROM admin_sessions WHERE token_hash = ?")
           ->execute([$sessionRow['token_hash']]);
        clearAdminSession();
        return ['ok' => false, 'reason' => 'expired'];
    }

    // 节流更新活跃时间，避免每个请求都写库
    if ($now - ($_SESSION['session_last_at'] ?? 0) >= ADMIN_SESSION_TOUCH) {
        $db->prepare("UPDATE admin_sessions SET last_active_at = NOW() WHERE token_hash = ?")
           ->execute([$sessionRow['token_hash']]);
        $_SESSION['session_last_at'] = $now;
    }

    return ['ok' => true, 'admin' => $admin, 'session' => $sessionRow];
}

/**
 * 页面入口校验：失败跳转登录页并携带原因；可选权限不足时回到首页提示
 */
function requireAdmin($permission = null) {
    $db = getDB();
    $check = adminAuthCheck($db);
    if (!$check['ok']) {
        header('Location: login.php?reason=' . urlencode($check['reason']));
        exit;
    }
    if ($permission !== null && !roleCan($check['admin']['role'], $permission)) {
        header('Location: index.php?reason=forbidden');
        exit;
    }
    return $check;
}

/**
 * API 入口校验：失败统一输出 JSON（401 未登录/会话失效，403 已登录但越权）
 */
function requireAdminApi($permission = null) {
    $db = getDB();
    $check = adminAuthCheck($db);
    if (!$check['ok']) {
        http_response_code(401);
        jsonResponse(401, authReasonText($check['reason']), ['reason' => $check['reason']]);
    }
    if ($permission !== null && !roleCan($check['admin']['role'], $permission)) {
        http_response_code(403);
        jsonResponse(403, authReasonText('forbidden'), ['reason' => 'forbidden']);
    }
    return $check;
}

/**
 * 登录成功：生成新会话记录
 */
function createAdminSession($db, $admin) {
    session_regenerate_id(true);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $ip = clientIp();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // 顺手清理该账号已过期的会话
    $db->prepare("DELETE FROM admin_sessions WHERE admin_id = ? AND (expires_at < NOW() OR last_active_at < DATE_SUB(NOW(), INTERVAL ? SECOND))")
       ->execute([$admin['id'], ADMIN_IDLE_TIMEOUT]);

    $db->prepare("INSERT INTO admin_sessions
        (token_hash, admin_id, ip, user_agent, login_at, last_active_at, expires_at)
        VALUES (?, ?, ?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))")
       ->execute([$tokenHash, $admin['id'], $ip, $ua, ADMIN_ABS_TIMEOUT]);

    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_name'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['session_token_hash'] = $tokenHash;
    $_SESSION['session_login_at'] = time();
    $_SESSION['session_last_at'] = time();
}

/**
 * 记录一次登录尝试
 */
function recordLoginAttempt($db, $username, $success, $adminId = null, $reason = '') {
    $db->prepare("INSERT INTO login_logs (username, admin_id, ip, user_agent, success, reason)
        VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([
           substr($username, 0, 50),
           $adminId,
           clientIp(),
           substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
           $success ? 1 : 0,
           $reason,
       ]);
}

/**
 * 检查指定用户名当前是否处于失败锁定窗口
 * 口径：最近 ADMIN_MAX_FAILURES 条记录全为失败，且最近一次失败未超过锁定时长。
 * 返回 ['locked'=>bool,'minutes'=>剩余分钟,'attempts'=>连续失败次数]
 */
function getLoginLockState($db, $username) {
    $stmt = $db->prepare(
        "SELECT success, created_at FROM login_logs
         WHERE username = ? AND reason <> 'locked'
         ORDER BY id DESC LIMIT ?"
    );
    $stmt->bindValue(1, $username);
    $stmt->bindValue(2, ADMIN_MAX_FAILURES, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $streak = 0;
    $latestFailAt = null;
    foreach ($rows as $row) {
        if ((int)$row['success'] === 1) break;
        $streak++;
        $latestFailAt = $row['created_at'];
    }

    $locked = false;
    $minutes = 0;
    if ($streak >= ADMIN_MAX_FAILURES && $latestFailAt) {
        $unlockAt = strtotime($latestFailAt) + ADMIN_LOCK_MINUTES * 60;
        $minutes = (int)ceil(($unlockAt - time()) / 60);
        if ($minutes > 0) {
            $locked = true;
        } else {
            // 锁定窗口已过，连续计数归零
            $streak = 0;
        }
    }

    return ['locked' => $locked, 'minutes' => $minutes, 'attempts' => $streak];
}

/**
 * 获取账号上一次成功登录信息（用于“异常登录提示”）
 * 注意：在记录本次成功登录之前调用，因此最近一条成功记录即上次登录
 */
function getLastLogin($db, $adminId) {
    $stmt = $db->prepare(
        "SELECT created_at, ip FROM login_logs
         WHERE admin_id = ? AND success = 1 ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$adminId]);
    return $stmt->fetch() ?: null;
}

/**
 * 踢出指定账号的全部会话（改角色 / 停用账号时调用），可保留当前令牌
 */
function revokeAllAdminSessions($db, $adminId, $keepTokenHash = null) {
    if ($keepTokenHash !== null) {
        $db->prepare("DELETE FROM admin_sessions WHERE admin_id = ? AND token_hash <> ?")
           ->execute([$adminId, $keepTokenHash]);
    } else {
        $db->prepare("DELETE FROM admin_sessions WHERE admin_id = ?")->execute([$adminId]);
    }
}

/**
 * 解析 User-Agent 为简短的设备/浏览器描述
 */
function describeUserAgent($ua) {
    if ($ua === '') return '未知设备';
    $os = '未知系统';
    if (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) $os = 'iOS';
    elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';

    $browser = '浏览器';
    if (preg_match('/Edg\//i', $ua)) $browser = 'Edge';
    elseif (preg_match('/MicroMessenger/i', $ua)) $browser = '微信';
    elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';

    return $os . ' · ' . $browser;
}
