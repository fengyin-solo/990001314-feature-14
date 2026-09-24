<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// 主动退出：当前会话登记立即失效，其他设备不受影响
if (!empty($_SESSION['admin_id']) && !empty($_SESSION['session_token'])) {
    revokeAdminSession(getDB(), $_SESSION['session_token'], 'logout');
}
clearAdminIdentity();

header('Location: login.php?reason=logged_out');
exit;
