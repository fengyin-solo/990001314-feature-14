<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin('session.manage');

$pageTitle = '会话管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
$myTokenHash = $_SESSION['session_token_hash'] ?? '';

$stmt = $db->prepare(
    "SELECT token_hash, ip, user_agent, login_at, last_active_at, expires_at
     FROM admin_sessions
     WHERE admin_id = ? AND expires_at > NOW()
     ORDER BY last_active_at DESC"
);
$stmt->execute([$_SESSION['admin_id']]);
$sessions = $stmt->fetchAll();

$now = time();
$idleMinutes = (int)(ADMIN_IDLE_TIMEOUT / 60);

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <?php $activeNav = 'sessions'; include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h2>会话管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?>
                <span class="role-badge role-badge-<?= cleanInput($_SESSION['admin_role']) ?>"><?= roleLabel($_SESSION['admin_role']) ?></span>
            </span>
        </div>

        <div class="admin-banner admin-banner-info">
            💡 每个设备登录后都会生成一条独立会话。空闲 <?= $idleMinutes ?> 分钟或登录满 <?= (int)(ADMIN_ABS_TIMEOUT / 3600) ?> 小时后自动失效。
            退出某个设备后，该入口立即失效。
        </div>

        <div class="session-toolbar">
            <span class="text-muted">当前账号共 <?= count($sessions) ?> 个活跃会话</span>
            <?php if (count($sessions) > 1): ?>
            <button class="btn btn-warning btn-sm" onclick="killOtherSessions()">退出其他全部设备</button>
            <?php endif; ?>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>设备 / 浏览器</th>
                        <th>IP 地址</th>
                        <th>登录时间</th>
                        <th>最后活跃</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                    <tr><td colspan="6" class="text-center">暂无活跃会话</td></tr>
                    <?php else: ?>
                    <?php foreach ($sessions as $s):
                        $isCurrent = $s['token_hash'] === $myTokenHash;
                        $idleSec = $now - strtotime($s['last_active_at']);
                        $online = $idleSec < 300;
                    ?>
                    <tr>
                        <td>
                            <?= cleanInput(describeUserAgent($s['user_agent'])) ?>
                            <?php if ($isCurrent): ?><span class="badge badge-current">当前设备</span><?php endif; ?>
                        </td>
                        <td><?= cleanInput($s['ip']) ?></td>
                        <td class="td-time"><?= $s['login_at'] ?></td>
                        <td class="td-time"><?= $s['last_active_at'] ?></td>
                        <td>
                            <?php if ($isCurrent): ?>
                                <span class="status-badge status-approved">当前会话</span>
                            <?php elseif ($online): ?>
                                <span class="status-badge status-approved">在线</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">空闲</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-actions">
                            <?php if (!$isCurrent): ?>
                            <button class="btn btn-xs btn-danger" onclick="killSession('<?= $s['token_hash'] ?>')">退出该设备</button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function killSession(token) {
    if (!confirm('确定要退出该设备吗？该入口将立即失效。')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=session_kill&token=' + encodeURIComponent(token)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}

function killOtherSessions() {
    if (!confirm('确定要退出当前账号在其他设备上的全部会话吗？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=session_kill_others'
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}
</script>
