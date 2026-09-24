<?php
/**
 * 后台公共侧边栏
 * 依赖：requireAdmin() 已在页面入口通过，$_SESSION 中存在最新角色信息
 * 变量 $activeNav 可用于高亮当前菜单（messages/reports/sessions/admins）
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$activeNav = $activeNav ?? '';
$role = $_SESSION['admin_role'] ?? '';
try {
    $navPendingMessages = (int)getDB()->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
} catch (Exception $e) {
    $navPendingMessages = 0;
}
$navPendingReports = (int)getPendingReportCount();
?>
<aside class="admin-sidebar">
    <div class="sidebar-header">
        <h3>📋 管理后台</h3>
    </div>
    <nav class="sidebar-nav">
        <a href="index.php" class="sidebar-link <?= $activeNav === 'messages' ? 'active' : '' ?>">📝 留言管理</a>
        <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $navPendingMessages > 0 ? "($navPendingMessages)" : '' ?></a>
        <a href="reports.php" class="sidebar-link <?= $activeNav === 'reports' ? 'active' : '' ?>">🚩 举报管理</a>
        <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $navPendingReports > 0 ? "($navPendingReports)" : '' ?></a>
        <a href="sessions.php" class="sidebar-link <?= $activeNav === 'sessions' ? 'active' : '' ?>">💻 会话管理</a>
        <?php if (roleCan($role, 'admin.manage')): ?>
        <a href="admins.php" class="sidebar-link <?= $activeNav === 'admins' ? 'active' : '' ?>">👥 管理员管理</a>
        <?php endif; ?>
        <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
        <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
    </nav>
</aside>
