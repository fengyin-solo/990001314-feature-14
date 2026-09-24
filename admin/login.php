<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$pageTitle = '后台登录 - 社区便民留言板';
$cssPath = '../assets/css/style.css';

// 已登录且会话仍然有效则直接进入后台
if (!empty($_SESSION['admin_id']) && !empty($_SESSION['session_token'])) {
    [$admin] = validateAdminSession(getDB());
    if ($admin) {
        header('Location: index.php');
        exit;
    }
    clearAdminIdentity();
}

// 从上一跳带过来的异常提示（退出、过期、被踢、权限变更等）
$reason = $_GET['reason'] ?? '';
$notice = authReasonExists($reason) ? authReasonText($reason) : '';
$noticeType = ($reason === 'logged_out') ? 'alert-success' : 'alert-warning';

$error = '';
$lockedRemaining = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = clientIp();

    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } else {
        $db = getDB();

        if (mt_rand(1, 10) === 1) {
            cleanupAuthTables($db);
        }

        $lock = getLoginLockStatus($db, $username, $ip);
        if ($lock['locked']) {
            $lockedRemaining = $lock['remaining'];
            $error = '连续登录失败次数过多，请 ' . ceil($lockedRemaining / 60) . ' 分钟后再试';
        } else {
            $admin = findAdminByUsername($db, $username);

            if ($admin && password_verify($password, $admin['password'])) {
                if ((int)$admin['status'] !== 1) {
                    $error = '该账号已被停用，请联系超级管理员';
                } else {
                    clearLoginFailures($db, $username, $ip);
                    createAdminSession($db, $admin);
                    header('Location: index.php');
                    exit;
                }
            } else {
                recordLoginFailure($db, $username, $ip);
                $lock = getLoginLockStatus($db, $username, $ip);
                if ($lock['locked']) {
                    $lockedRemaining = $lock['remaining'];
                    $error = '连续登录失败 ' . ADMIN_LOGIN_MAX_FAILURES . ' 次，账号已锁定 '
                           . ceil($lockedRemaining / 60) . ' 分钟';
                } else {
                    $left = ADMIN_LOGIN_MAX_FAILURES - $lock['failures'];
                    $error = '用户名或密码错误';
                    if ($left <= 3) {
                        $error .= '；还可尝试 ' . $left . ' 次，超限将锁定 '
                               . round(ADMIN_LOGIN_LOCK_SECONDS / 60) . ' 分钟';
                    }
                }
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
    <title><?= cleanInput($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= $cssPath ?>">
</head>
<body class="login-body">
<div class="login-card">
    <h2>🔐 后台管理登录</h2>
    <p class="login-subtitle">社区便民留言板管理系统</p>

    <?php if ($notice): ?>
    <div class="alert <?= $noticeType ?>" id="loginNotice"><?= cleanInput($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error" id="loginError"><?= cleanInput($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="login-form" id="loginForm"
          <?= $lockedRemaining > 0 ? 'data-lock="' . $lockedRemaining . '"' : '' ?>>
        <div class="form-group">
            <label for="username">用户名</label>
            <input type="text" id="username" name="username" placeholder="请输入管理员账号" required autofocus
                   value="<?= cleanInput($username ?? '') ?>" autocomplete="username"
                   <?= $lockedRemaining > 0 ? 'disabled' : '' ?>>
        </div>
        <div class="form-group">
            <label for="password">密码</label>
            <input type="password" id="password" name="password" placeholder="请输入密码" required
                   autocomplete="current-password"
                   <?= $lockedRemaining > 0 ? 'disabled' : '' ?>>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block"
                <?= $lockedRemaining > 0 ? 'disabled id="loginSubmit"' : '' ?>>登 录</button>
    </form>
    <div class="login-footer">
        <a href="../index.php">← 返回首页</a>
    </div>
</div>
<?php if ($lockedRemaining > 0): ?>
<script>
// 锁定倒计时，到期后恢复表单
(function () {
    var remain = <?= (int)$lockedRemaining ?>;
    var form = document.getElementById('loginForm');
    var btn = document.getElementById('loginSubmit');
    var timer = setInterval(function () {
        remain--;
        if (remain <= 0) {
            clearInterval(timer);
            location.reload();
        } else if (btn) {
            btn.textContent = '已锁定（' + Math.ceil(remain / 60) + ' 分钟）';
        }
    }, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
