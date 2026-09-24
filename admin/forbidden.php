<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
$admin = requireAdmin();

$pageTitle = '权限不足 - 社区便民留言板';
$currentSidebar = '';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';
http_response_code(403);

include __DIR__ . '/header.php';
?>
<div class="admin-container">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="forbidden-box">
            <div class="forbidden-icon">🚫</div>
            <h2>权限不足</h2>
            <p>当前角色「<?= cleanOutput(adminRoles()[$admin['role']] ?? $admin['role']) ?>」无权访问该页面或执行该操作。</p>
            <p class="text-muted">如需更高权限，请联系超级管理员调整角色后重新登录。</p>
            <div class="forbidden-actions">
                <a href="index.php" class="btn btn-primary">返回留言管理</a>
                <a href="sessions.php" class="btn btn-secondary">查看我的会话</a>
            </div>
        </div>
    </div>
</div>
