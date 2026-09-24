<?php
/**
 * 后台公共侧边栏
 * 依赖：header.php 已引入，$_admin / $_token / $navPendingMessages / $navPendingReports / $navActiveSessions 已就绪
 * 用法：include __DIR__ . '/sidebar.php';
 */
$currentSidebar = $currentSidebar ?? '';
?>
<aside class="admin-sidebar">
    <div class="sidebar-header">
        <h3>📋 管理后台</h3>
    </div>
    <div class="sidebar-user">
        <span class="sidebar-user-name">👤 <?= cleanInput($_admin['username']) ?></span>
        <?= adminRoleBadgeHtml($_admin['role']) ?>
    </div>
    <nav class="sidebar-nav">
        <a href="index.php" class="sidebar-link <?= $currentSidebar === 'messages' ? 'active' : '' ?>">📝 留言管理</a>
        <a href="index.php?status=0" class="sidebar-link">⏳ 待审核留言 <?= $navPendingMessages > 0 ? "($navPendingMessages)" : '' ?></a>
        <a href="reports.php" class="sidebar-link <?= $currentSidebar === 'reports' ? 'active' : '' ?>">🚩 举报管理</a>
        <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $navPendingReports > 0 ? "($navPendingReports)" : '' ?></a>
        <a href="sessions.php" class="sidebar-link <?= $currentSidebar === 'sessions' ? 'active' : '' ?>">
            🖥️ 会话管理 <?= $navActiveSessions > 0 ? "($navActiveSessions)" : '' ?>
        </a>
        <?php if (adminCan('admin.manage')): ?>
        <a href="admins.php" class="sidebar-link <?= $currentSidebar === 'admins' ? 'active' : '' ?>">👥 管理员账号</a>
        <?php endif; ?>
        <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
        <a href="logout.php" class="sidebar-link admin-logout-link">🚪 退出登录</a>
    </nav>
</aside>
