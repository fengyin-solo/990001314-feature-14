<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// 统一异常提示：会话/权限类原因以醒目横幅展示一次
$bannerReason = $_GET['reason'] ?? '';
$bannerText = '';
$bannerClass = 'alert-error';
if ($bannerReason === 'forbidden') {
    $bannerText = '⛔ ' . authReasonText('forbidden') . '（当前角色：' . roleLabel($_SESSION['admin_role'] ?? '') . '）';
}

// 登录后展示上次成功登录信息（异常登录提示）
$lastLoginRaw = $_GET['last_login'] ?? '';
$lastLoginText = '';
if ($lastLoginRaw && strpos($lastLoginRaw, '|') !== false) {
    [$llTime, $llIp] = explode('|', $lastLoginRaw, 2);
    $lastLoginText = 'ℹ️ 上次成功登录：' . cleanInput($llTime) . '，IP：' . cleanInput($llIp) . '。如非本人操作，请立即修改密码并退出其他设备。';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? '后台管理' ?></title>
    <link rel="stylesheet" href="<?= $cssPath ?? '../assets/css/style.css' ?>">
</head>
<body class="admin-body">
<?php if ($bannerText): ?>
<div class="admin-banner admin-banner-error"><?= $bannerText ?></div>
<?php endif; ?>
<?php if ($lastLoginText): ?>
<div class="admin-banner admin-banner-info"><?= $lastLoginText ?></div>
<?php endif; ?>
<script src="admin-auth.js"></script>
