<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '会话管理 - 社区便民留言板';
$currentSidebar = 'sessions';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
$admin = $GLOBALS['current_admin'];
$currentToken = $GLOBALS['current_session_token'];

$isSuper = adminCan('admin.manage');
$scope = $_GET['scope'] ?? 'mine';
if (!$isSuper) {
    $scope = 'mine'; // 非超管只能看自己的
}

$sessions = listActiveSessions($db, $scope === 'all' ? null : (int)$admin['id']);

// 自己的其他设备数量（用于“一键退出其他设备”）
$stmt = $db->prepare("SELECT COUNT(*) FROM admin_sessions
                      WHERE admin_id = ? AND token <> ?
                        AND revoked_at IS NULL AND expires_at > NOW()");
$stmt->execute([$admin['id'], $currentToken]);
$myOtherCount = (int)$stmt->fetchColumn();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h2>🖥️ 会话管理</h2>
            <span class="admin-user">👤 <?= cleanInput($admin['username']) ?> <?= adminRoleBadgeHtml() ?></span>
        </div>

        <div class="alert alert-info">
            空闲超过 <?= round(ADMIN_SESSION_IDLE_TIMEOUT / 60) ?> 分钟或登录超过
            <?= round(ADMIN_SESSION_ABSOLUTE_TIMEOUT / 3600) ?> 小时的会话会自动失效。主动退出其他设备后，该设备的入口立即失效。
        </div>

        <?php if ($isSuper): ?>
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="scope" onchange="this.form.submit()">
                    <option value="mine" <?= $scope === 'mine' ? 'selected' : '' ?>>我的会话</option>
                    <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>全部管理员会话</option>
                </select>
            </form>
        </div>
        <?php endif; ?>

        <div class="admin-toolbar">
            <?php if ($myOtherCount > 0): ?>
            <button class="btn btn-danger btn-sm" onclick="revokeMyOthers(this)">
                退出我的其他设备（<?= $myOtherCount ?>）
            </button>
            <?php else: ?>
            <span class="text-muted">当前没有其他活跃设备</span>
            <?php endif; ?>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <?php if ($isSuper && $scope === 'all'): ?><th>管理员</th><?php endif; ?>
                        <th>设备</th>
                        <th>登录IP</th>
                        <th>最近IP</th>
                        <th>登录时间</th>
                        <th>最后活跃</th>
                        <th>到期时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                    <tr><td colspan="<?= $isSuper && $scope === 'all' ? 9 : 8 ?>" class="text-center">暂无活跃会话</td></tr>
                    <?php else: ?>
                    <?php foreach ($sessions as $s): ?>
                    <?php $ua = parseUserAgent($s['user_agent']); ?>
                    <tr>
                        <td><?= (int)$s['id'] ?></td>
                        <?php if ($isSuper && $scope === 'all'): ?>
                        <td><?= cleanInput($s['username']) ?><?= (int)$s['admin_id'] === (int)$admin['id'] ? '（我）' : '' ?></td>
                        <?php endif; ?>
                        <td><?= cleanOutput($ua['os'] . ' · ' . $ua['browser']) ?></td>
                        <td><?= cleanOutput($s['login_ip']) ?></td>
                        <td><?= cleanOutput($s['last_ip']) ?></td>
                        <td class="td-time"><?= date('Y-m-d H:i', strtotime($s['login_at'])) ?></td>
                        <td class="td-time"><?= timeAgo($s['last_active_at']) ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($s['expires_at'])) ?></td>
                        <td class="td-actions">
                            <?php if ($s['token'] === $currentToken): ?>
                                <span class="status-badge status-approved">当前设备</span>
                            <?php else: ?>
                                <button class="btn btn-xs btn-danger"
                                        onclick="revokeSession('<?= $s['token'] ?>', this)">退出该设备</button>
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
function revokeSession(token, btn) {
    if (!confirm('确定退出该设备吗？退出后该设备的登录入口立即失效。')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'session_revoke');
    fd.append('token', token);
    fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.code === 0) {
                alert(data.msg);
                location.reload();
            } else {
                alert(data.msg || '操作失败');
                btn.disabled = false;
            }
        })
        .catch(() => { btn.disabled = false; });
}

function revokeMyOthers(btn) {
    if (!confirm('确定退出当前账号在其他设备上的全部会话吗？')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'session_revoke_others');
    fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.code === 0) {
                alert(data.msg);
                location.reload();
            } else {
                alert(data.msg || '操作失败');
                btn.disabled = false;
            }
        })
        .catch(() => { btn.disabled = false; });
}
</script>
