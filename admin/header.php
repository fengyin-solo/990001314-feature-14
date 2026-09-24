<?php
if (!defined('ADMIN_HEADER_INCLUDED')) {
    define('ADMIN_HEADER_INCLUDED', true);

    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../config/database.php';
    requireAdmin();

    // 当前管理员信息（页面内统一使用，不直接读 $_SESSION）
    $_admin = $GLOBALS['current_admin'];
    $_token = $GLOBALS['current_session_token'];
}

// 每次渲染头部时计算一次待处理数量与当前会话签名
$_dbh = getDB();
$navPendingMessages = (int)$_dbh->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
$navPendingReports  = getPendingReportCount();
$navActiveSessions  = (int)$_dbh->query("SELECT COUNT(*) FROM admin_sessions
                                         WHERE revoked_at IS NULL AND expires_at > NOW()")->fetchColumn();
// 用于多标签页检测权限是否在其他标签页/设备上被改变
$authSignature = md5($_admin['id'] . '|' . $_admin['role']);
?><!DOCTYPE html>
<html lang="zh-CN"
      data-auth-token="<?= cleanOutput($_token) ?>"
      data-auth-sig="<?= cleanOutput($authSignature) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= cleanInput($pageTitle ?? '后台管理') ?></title>
    <link rel="stylesheet" href="<?= $cssPath ?? '../assets/css/style.css' ?>">
    <script src="../assets/js/admin.js" defer></script>
</head>
<body class="admin-body">
