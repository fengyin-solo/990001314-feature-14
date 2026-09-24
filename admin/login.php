<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = '后台登录 - 社区便民留言板';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

// 已持有有效会话则跳转；旧版本残留的不完整会话要清掉，否则会与首页形成跳转死循环
if (!empty($_SESSION['admin_id'])) {
    try {
        $existCheck = adminAuthCheck(getDB());
    } catch (Exception $e) {
        $existCheck = ['ok' => false, 'reason' => 'not_logged_in'];
    }
    if ($existCheck['ok']) {
        header('Location: index.php');
        exit;
    }
    clearAdminSession();
}

$reason = $_GET['reason'] ?? '';
$notice = ($reason && $reason !== 'not_logged_in') ? authReasonText($reason) : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $db = getDB();

        // 1. 先检查连续失败锁定（对不存在的用户名同样生效，避免被探测）
        $lock = getLoginLockState($db, $username);
        if ($lock['locked']) {
            recordLoginAttempt($db, $username, false, null, 'locked');
            $error = '登录失败次数过多，账号已锁定，请 ' . $lock['minutes'] . ' 分钟后再试';
        } else {
            $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if (!$admin) {
                recordLoginAttempt($db, $username, false, null, 'wrong_password');
                $left = ADMIN_MAX_FAILURES - ($lock['attempts'] + 1);
                $error = '用户名或密码错误' . ($left > 0 ? "，还可尝试 {$left} 次" : '，账号将被临时锁定');
            } elseif ((int)$admin['status'] !== 1) {
                recordLoginAttempt($db, $username, false, $admin['id'], 'disabled');
                $error = '该账号已被停用，请联系超级管理员';
            } elseif (!password_verify($password, $admin['password'])) {
                recordLoginAttempt($db, $username, false, $admin['id'], 'wrong_password');
                $left = ADMIN_MAX_FAILURES - ($lock['attempts'] + 1);
                $error = '用户名或密码错误' . ($left > 0 ? "，还可尝试 {$left} 次" : '，账号将被临时锁定');
            } else {
                // 登录成功
                $lastLogin = getLastLogin($db, $admin['id']);
                recordLoginAttempt($db, $username, true, $admin['id']);
                $db->prepare("UPDATE admins SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?")
                   ->execute([clientIp(), $admin['id']]);

                // 全新会话，旧标签页残留的权限状态一律作废
                createAdminSession($db, $admin);

                $url = 'index.php';
                if ($lastLogin) {
                    $url .= '?last_login=' . urlencode($lastLogin['created_at'] . '|' . $lastLogin['ip']);
                }
                header('Location: ' . $url);
                exit;
            }

            // 本次失败后重新计算，若已触发锁定则覆盖提示
            $lock = getLoginLockState($db, $username);
            if ($lock['locked']) {
                $error = '登录失败次数过多，账号已锁定，请 ' . $lock['minutes'] . ' 分钟后再试';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= $cssPath ?>">
</head>
<body class="login-body">
<div class="login-card">
    <h2>🔐 后台管理登录</h2>
    <p class="login-subtitle">社区便民留言板管理系统</p>
    <?php if ($notice): ?>
    <div class="alert alert-error" id="loginNotice">⚠️ <?= cleanInput($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error">⛔ <?= cleanInput($error) ?></div>
    <?php endif; ?>
    <form method="POST" class="login-form">
        <div class="form-group">
            <label for="username">用户名</label>
            <input type="text" id="username" name="username" placeholder="请输入管理员账号"
                   value="<?= cleanInput($username ?? '') ?>" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">密码</label>
            <input type="password" id="password" name="password" placeholder="请输入密码" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block">登 录</button>
    </form>
    <p class="login-tip">连续失败 <?= ADMIN_MAX_FAILURES ?> 次将锁定 <?= ADMIN_LOCK_MINUTES ?> 分钟；会话空闲 30 分钟自动过期</p>
    <div class="login-footer">
        <a href="../index.php">← 返回首页</a>
    </div>
</div>
</body>
</html>
