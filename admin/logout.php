<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// 删除当前设备在服务端的会话记录，入口立即失效
if (!empty($_SESSION['session_token_hash'])) {
    try {
        $db = getDB();
        $db->prepare("DELETE FROM admin_sessions WHERE token_hash = ?")
           ->execute([$_SESSION['session_token_hash']]);
    } catch (Exception $e) {
        // 数据库不可用时仍允许清理本地会话
    }
}

clearAdminSession();
// 不调用 session_destroy()，保留 visitor_id 等前台匿名数据
header('Location: login.php?reason=logged_out');
exit;
