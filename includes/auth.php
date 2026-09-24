<?php
/**
 * 后台认证、会话管理与基于角色的权限控制
 *
 * - 会话令牌登记在 admin_sessions 表，支持查看活跃会话、远程退出其他设备
 * - 空闲超时 / 绝对超时 / 账号停用 / 角色变更都会使会话立即失效
 * - login_failures 记录连续登录失败，超限锁定
 * - 角色：super_admin(超级管理员) / auditor(审核员) / viewer(只读账号)
 */

if (!defined('ADMIN_SESSION_IDLE_TIMEOUT')) {
    define('ADMIN_SESSION_IDLE_TIMEOUT', 1800);      // 30 分钟无操作过期
    define('ADMIN_SESSION_ABSOLUTE_TIMEOUT', 43200); // 12 小时绝对有效期
    define('ADMIN_SESSION_HEARTBEAT_GAP', 60);       // 最后活跃时间更新粒度(秒)
    define('ADMIN_LOGIN_MAX_FAILURES', 5);           // 连续失败次数上限
    define('ADMIN_LOGIN_LOCK_SECONDS', 900);         // 锁定时长(秒)
}

// 启动全站会话(前台访客标识与后台登录共用)，加固 Cookie 属性
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
    session_name('cb_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}

/* ====================== 角色与权限 ====================== */

/**
 * 全部角色
 */
function adminRoles() {
    return [
        'super_admin' => '超级管理员',
        'auditor'     => '审核员',
        'viewer'      => '只读账号',
    ];
}

/**
 * 各角色拥有的权限点
 * message.audit    审核留言(通过/拒绝)
 * message.delete   删除留言
 * report.process   处理举报(删除留言/忽略/驳回)
 * admin.manage     管理员账号与会话管理
 */
function adminRolePerms($role) {
    switch ($role) {
        case 'super_admin':
            return ['message.audit', 'message.delete', 'report.process', 'admin.manage'];
        case 'auditor':
            return ['message.audit', 'message.delete', 'report.process'];
        case 'viewer':
        default:
            return [];
    }
}

function isValidAdminRole($role) {
    return is_string($role) && array_key_exists($role, adminRoles());
}

/**
 * 当前(或指定)角色是否拥有某权限
 */
function adminCan($perm, $role = null) {
    if ($role === null) {
        $role = $_SESSION['admin_role'] ?? '';
    }
    return in_array($perm, adminRolePerms($role), true);
}

/**
 * 角色徽章 HTML
 */
function adminRoleBadgeHtml($role = null) {
    if ($role === null) {
        $role = $_SESSION['admin_role'] ?? '';
    }
    $names = adminRoles();
    $label = $names[$role] ?? '未知角色';
    return '<span class="role-badge role-' . cleanOutput($role) . '">' . cleanOutput($label) . '</span>';
}

/**
 * 与 cleanInput 同义的输出转义(auth 可能被各处复用，单独提供避免依赖顺序)
 */
if (!function_exists('cleanOutput')) {
    function cleanOutput($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

/* ====================== 失效原因文案 ====================== */

function authReasonMessages() {
    return [
        'invalid'       => '登录状态已失效，请重新登录',
        'manual'        => '该登录会话已被强制失效，请重新登录',
        'logged_out'    => '您已安全退出登录',
        'replaced'      => '该浏览器已重新登录，旧会话自动失效',
        'idle'          => '会话因长时间无操作已过期，请重新登录',
        'expired'       => '会话已过期，请重新登录',
        'disabled'      => '该管理员账号已被停用，如有疑问请联系超级管理员',
        'role_changed'  => '账号权限已发生变更，请重新登录以获取最新权限',
        'forbidden'     => '权限不足，无法访问该页面或执行该操作',
        'revoked'       => '该登录会话已被退出，请重新登录',
        'kicked'        => '该会话已被管理员主动退出，请重新登录',
    ];
}

function authReasonText($reason) {
    $map = authReasonMessages();
    return isset($map[$reason]) ? $map[$reason] : $map['invalid'];
}

function authReasonExists($reason) {
    return is_string($reason) && isset(authReasonMessages()[$reason]);
}

/* ====================== 客户端信息 ====================== */

function clientIp() {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function clientUserAgent() {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

/**
 * 从 User-Agent 粗略解析设备系统与浏览器，用于会话列表展示
 */
function parseUserAgent($ua) {
    $os = '未知设备';
    if (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    $browser = '未知浏览器';
    if (preg_match('/MicroMessenger/i', $ua)) {
        $browser = '微信浏览器';
    } elseif (preg_match('/Edg\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/Chrome|CriOS/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox|FxiOS/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/MSIE|Trident/i', $ua)) {
        $browser = 'IE';
    } elseif (preg_match('/Safari/i', $ua)) {
        $browser = 'Safari';
    }

    return ['os' => $os, 'browser' => $browser];
}

/* ====================== 管理员查询 ====================== */

function findAdminById($pdo, $id) {
    static $cache = [];
    $id = (int)$id;
    if ($id <= 0) return null;
    if (array_key_exists($id, $cache)) return $cache[$id];
    $stmt = $pdo->prepare("SELECT id, username, password, role, status, created_at FROM admins WHERE id = ?");
    $stmt->execute([$id]);
    return $cache[$id] = $stmt->fetch() ?: null;
}

function findAdminByUsername($pdo, $username) {
    $stmt = $pdo->prepare("SELECT id, username, password, role, status, created_at FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

function countEnabledSuperAdmins($pdo, $exceptId = 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND status = 1 AND id <> ?");
    $stmt->execute([(int)$exceptId]);
    return (int)$stmt->fetchColumn();
}

/* ====================== 会话登记与校验 ====================== */

/**
 * 登录成功后创建新会话(防固定会话攻击)，并返回会话令牌
 */
function createAdminSession($pdo, $admin) {
    // 同一浏览器旧身份对应的会话立即标记为被替换
    if (!empty($_SESSION['session_token'])) {
        $stmt = $pdo->prepare("UPDATE admin_sessions SET revoked_at = NOW(), revoke_reason = 'replaced'
                               WHERE token = ? AND revoked_at IS NULL");
        $stmt->execute([$_SESSION['session_token']]);
    }

    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['session_token']);
    session_regenerate_id(true);

    $token = bin2hex(random_bytes(32));
    $ip = clientIp();
    $ua = clientUserAgent();
    $expiresAt = date('Y-m-d H:i:s', time() + ADMIN_SESSION_ABSOLUTE_TIMEOUT);

    $stmt = $pdo->prepare("INSERT INTO admin_sessions
                              (token, admin_id, login_ip, last_ip, user_agent, expires_at)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$token, $admin['id'], $ip, $ip, $ua, $expiresAt]);

    $_SESSION['admin_id']      = (int)$admin['id'];
    $_SESSION['admin_name']    = $admin['username'];
    $_SESSION['admin_role']    = $admin['role'];
    $_SESSION['session_token'] = $token;

    return $token;
}

/**
 * 使指定令牌的会话立即失效
 */
function revokeAdminSession($pdo, $token, $reason = 'manual') {
    $stmt = $pdo->prepare("UPDATE admin_sessions
                           SET revoked_at = NOW(), revoke_reason = ?
                           WHERE token = ? AND revoked_at IS NULL");
    $stmt->execute([$reason, $token]);
}

/**
 * 使某管理员的全部会话失效(可排除当前令牌)
 */
function revokeAdminSessionsById($pdo, $adminId, $reason = 'manual', $exceptToken = null) {
    $sql = "UPDATE admin_sessions SET revoked_at = NOW(), revoke_reason = ?
            WHERE admin_id = ? AND revoked_at IS NULL";
    $params = [$reason, (int)$adminId];
    if ($exceptToken !== null) {
        $sql .= " AND token <> ?";
        $params[] = $exceptToken;
    }
    $pdo->prepare($sql)->execute($params);
}

/**
 * 清除当前 PHP 会话中的管理员身份(保留访客标识)
 */
function clearAdminIdentity() {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['session_token']);
}

/**
 * 校验当前会话，返回 [管理员信息数组, null] 或 [null, 失效原因]
 */
function validateAdminSession($pdo) {
    if (empty($_SESSION['admin_id']) || empty($_SESSION['session_token'])) {
        return [null, 'invalid'];
    }

    $stmt = $pdo->prepare("SELECT s.*, a.username, a.role, a.status
                           FROM admin_sessions s
                           JOIN admins a ON a.id = s.admin_id
                           WHERE s.token = ? LIMIT 1");
    $stmt->execute([$_SESSION['session_token']]);
    $sess = $stmt->fetch();

    if (!$sess) {
        return [null, 'invalid'];
    }
    if (!empty($sess['revoked_at'])) {
        return [null, $sess['revoke_reason'] ?: 'revoked'];
    }

    $now = time();
    if (strtotime($sess['expires_at']) <= $now) {
        revokeAdminSession($pdo, $sess['token'], 'expired');
        return [null, 'expired'];
    }
    if ($now - strtotime($sess['last_active_at']) > ADMIN_SESSION_IDLE_TIMEOUT) {
        revokeAdminSession($pdo, $sess['token'], 'idle');
        return [null, 'idle'];
    }
    if ((int)$sess['status'] !== 1) {
        revokeAdminSession($pdo, $sess['token'], 'disabled');
        return [null, 'disabled'];
    }
    if ($sess['role'] !== ($_SESSION['admin_role'] ?? null)) {
        revokeAdminSession($pdo, $sess['token'], 'role_changed');
        return [null, 'role_changed'];
    }

    // 活跃心跳(限频)，IP 变化立即更新
    $ip = clientIp();
    if ($sess['last_ip'] !== $ip || $now - strtotime($sess['last_active_at']) >= ADMIN_SESSION_HEARTBEAT_GAP) {
        $stmt = $pdo->prepare("UPDATE admin_sessions SET last_active_at = NOW(), last_ip = ? WHERE id = ?");
        $stmt->execute([$ip, $sess['id']]);
    }

    return [[
        'id'       => (int)$sess['admin_id'],
        'username' => $sess['username'],
        'role'     => $sess['role'],
    ], null];
}

/**
 * 活跃会话列表；$adminId 为 null 时返回全部(仅超管调用)
 */
function listActiveSessions($pdo, $adminId = null) {
    $sql = "SELECT s.*, a.username
            FROM admin_sessions s
            JOIN admins a ON a.id = s.admin_id
            WHERE s.revoked_at IS NULL AND s.expires_at > NOW()";
    $params = [];
    if ($adminId !== null) {
        $sql .= " AND s.admin_id = ?";
        $params[] = (int)$adminId;
    }
    $sql .= " ORDER BY s.last_active_at DESC, s.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/* ====================== 登录失败锁定 ====================== */

/**
 * 返回锁定状态：['locked' => bool, 'remaining' => 秒, 'failures' => 窗口内失败次数]
 */
function getLoginLockStatus($pdo, $username, $ip) {
    $threshold = date('Y-m-d H:i:s', time() - ADMIN_LOGIN_LOCK_SECONDS);
    $stmt = $pdo->prepare("SELECT created_at FROM login_failures
                           WHERE username = ? AND ip = ? AND created_at > ?
                           ORDER BY created_at ASC LIMIT " . (int)ADMIN_LOGIN_MAX_FAILURES);
    $stmt->execute([$username, $ip, $threshold]);
    $times = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($times) >= ADMIN_LOGIN_MAX_FAILURES) {
        $remaining = strtotime($times[0]) + ADMIN_LOGIN_LOCK_SECONDS - time();
        if ($remaining > 0) {
            return ['locked' => true, 'remaining' => $remaining, 'failures' => count($times)];
        }
    }
    return ['locked' => false, 'remaining' => 0, 'failures' => count($times)];
}

function recordLoginFailure($pdo, $username, $ip) {
    $stmt = $pdo->prepare("INSERT INTO login_failures (username, ip) VALUES (?, ?)");
    $stmt->execute([$username, $ip]);
}

function clearLoginFailures($pdo, $username, $ip) {
    $stmt = $pdo->prepare("DELETE FROM login_failures WHERE username = ? AND ip = ?");
    $stmt->execute([$username, $ip]);
}

/**
 * 清理过期的失败记录与已失效会话(低频调用)
 */
function cleanupAuthTables($pdo) {
    try {
        $pdo->exec("DELETE FROM login_failures WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $pdo->exec("DELETE FROM admin_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $pdo->exec("DELETE FROM admin_sessions
                    WHERE revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Exception $e) {
        // 清理失败不影响登录流程
    }
}

/* ====================== 入口鉴权 ====================== */

/**
 * API 请求鉴权失败时输出 401/403 JSON
 */
function authApiFail($httpStatus, $reason) {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code'   => $httpStatus,
        'msg'    => authReasonText($reason),
        'reason' => $reason,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 必须登录。page 模式：失效跳登录页并带原因；api 模式：返回 401 JSON
 */
function requireAdmin($mode = 'page') {
    $pdo = getDB();
    [$admin, $reason] = validateAdminSession($pdo);

    if (!$admin) {
        clearAdminIdentity();
        if ($mode === 'api') {
            authApiFail(401, $reason ?: 'invalid');
        }
        header('Location: login.php?reason=' . urlencode($reason ?: 'invalid'));
        exit;
    }

    if ($mode === 'page') {
        // 后台页面不缓存，避免退出/失效后通过浏览器缓存看到旧内容
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    $GLOBALS['current_admin'] = $admin;
    $GLOBALS['current_session_token'] = $_SESSION['session_token'];
    return $admin;
}

function currentAdmin() {
    return $GLOBALS['current_admin'] ?? null;
}

/**
 * 必须拥有指定权限。api 模式返回 403 JSON，page 模式跳转 403 页
 */
function requirePermission($perm, $mode = 'page') {
    if (adminCan($perm)) {
        return;
    }
    if ($mode === 'api') {
        authApiFail(403, 'forbidden');
    }
    header('Location: forbidden.php');
    exit;
}
