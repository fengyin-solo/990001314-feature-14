<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin('admin.manage');

$pageTitle = '管理员管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
$admins = $db->query(
    "SELECT a.id, a.username, a.role, a.status, a.last_login_at, a.last_login_ip, a.created_at,
            (SELECT COUNT(*) FROM admin_sessions s WHERE s.admin_id = a.id AND s.expires_at > NOW()) AS active_sessions
     FROM admins a ORDER BY a.id ASC"
)->fetchAll();

$myId = (int)$_SESSION['admin_id'];

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <?php $activeNav = 'admins'; include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h2>管理员管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?>
                <span class="role-badge role-badge-<?= cleanInput($_SESSION['admin_role']) ?>"><?= roleLabel($_SESSION['admin_role']) ?></span>
            </span>
        </div>

        <div class="admin-banner admin-banner-info">
            💡 修改角色或停用账号后，该账号已登录的设备下次操作时会收到明确提示并需重新登录；重置密码会立即退出其他设备。重新登录后统一按最新权限生效。
        </div>

        <!-- 新增管理员 -->
        <div class="admin-filter">
            <form class="filter-form" onsubmit="return createAdmin(event)">
                <input type="text" id="newUsername" placeholder="用户名（3-50位字母数字下划线）" required>
                <input type="password" id="newPassword" placeholder="初始密码（至少6位）" required>
                <select id="newRole">
                    <option value="auditor">审核员</option>
                    <option value="viewer">只读访客</option>
                    <option value="super_admin">超级管理员</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">新增管理员</button>
            </form>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>角色</th>
                        <th>状态</th>
                        <th>活跃会话</th>
                        <th>最近登录</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $a):
                        $isSelf = (int)$a['id'] === $myId;
                    ?>
                    <tr>
                        <td><?= $a['id'] ?></td>
                        <td>
                            <?= cleanInput($a['username']) ?>
                            <?php if ($isSelf): ?><span class="badge badge-current">当前账号</span><?php endif; ?>
                        </td>
                        <td>
                            <select class="role-select" id="role-<?= $a['id'] ?>" <?= $isSelf ? 'disabled' : '' ?>>
                                <?php foreach (['super_admin', 'auditor', 'viewer'] as $r): ?>
                                <option value="<?= $r ?>" <?= $a['role'] === $r ? 'selected' : '' ?>><?= roleLabel($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <?php if ((int)$a['status'] === 1): ?>
                                <span class="status-badge status-approved">启用</span>
                            <?php else: ?>
                                <span class="status-badge status-rejected">停用</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$a['active_sessions'] ?></td>
                        <td class="td-time">
                            <?= $a['last_login_at'] ? $a['last_login_at'] : '从未登录' ?>
                            <?= $a['last_login_ip'] ? '<br><span class="text-muted">' . cleanInput($a['last_login_ip']) . '</span>' : '' ?>
                        </td>
                        <td class="td-actions">
                            <?php if (!$isSelf): ?>
                            <button class="btn btn-xs btn-primary" onclick="saveAdmin(<?= $a['id'] ?>, 1)">保存</button>
                            <?php if ((int)$a['status'] === 1): ?>
                            <button class="btn btn-xs btn-warning" onclick="saveAdmin(<?= $a['id'] ?>, 0)">停用</button>
                            <?php else: ?>
                            <button class="btn btn-xs btn-success" onclick="saveAdmin(<?= $a['id'] ?>, 1)">启用</button>
                            <?php endif; ?>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-info" onclick="resetPassword(<?= $a['id'] ?>, '<?= cleanInput($a['username']) ?>')">重置密码</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function saveAdmin(id, status) {
    const role = document.getElementById('role-' + id).value;
    if (!confirm('确定保存该账号的角色/状态吗？\n保存后该账号已登录的设备下次操作时将收到提示并需重新登录。')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=admin_save&id=' + id + '&role=' + encodeURIComponent(role) + '&status=' + status
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}

function createAdmin(e) {
    e.preventDefault();
    const username = document.getElementById('newUsername').value.trim();
    const password = document.getElementById('newPassword').value;
    const role = document.getElementById('newRole').value;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=admin_create&username=' + encodeURIComponent(username)
            + '&password=' + encodeURIComponent(password) + '&role=' + encodeURIComponent(role)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
    return false;
}

function resetPassword(id, username) {
    const pwd = prompt('为 ' + username + ' 设置新密码（至少6位）：');
    if (pwd === null) return;
    if (pwd.length < 6) { alert('密码至少 6 位'); return; }
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=admin_reset_password&id=' + id + '&password=' + encodeURIComponent(pwd)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}
</script>
