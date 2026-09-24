<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();
requirePermission('admin.manage');

$pageTitle = '管理员账号 - 社区便民留言板';
$currentSidebar = 'admins';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
$admin = $GLOBALS['current_admin'];

// 每个管理员当前活跃会话数
$stmt = $db->query("SELECT admin_id, COUNT(*) AS cnt
                    FROM admin_sessions
                    WHERE revoked_at IS NULL AND expires_at > NOW()
                    GROUP BY admin_id");
$sessionCountMap = array_column($stmt->fetchAll(), 'cnt', 'admin_id');

$admins = $db->query("SELECT * FROM admins ORDER BY id ASC")->fetchAll();
$roleNames = adminRoles();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h2>👥 管理员账号</h2>
            <button class="btn btn-primary btn-sm" onclick="openCreateModal()">+ 新建管理员</button>
        </div>

        <div class="alert alert-info">
            修改角色或停用账号后，该账号的所有会话立即失效，需重新登录并按新角色加载权限。
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
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $a): ?>
                    <?php $isSelf = (int)$a['id'] === (int)$admin['id']; ?>
                    <tr>
                        <td><?= (int)$a['id'] ?></td>
                        <td>
                            <?= cleanInput($a['username']) ?>
                            <?php if ($isSelf): ?><span class="text-muted">（我）</span><?php endif; ?>
                        </td>
                        <td><?= adminRoleBadgeHtml($a['role']) ?></td>
                        <td>
                            <?php if ((int)$a['status'] === 1): ?>
                                <span class="status-badge status-approved">启用</span>
                            <?php else: ?>
                                <span class="status-badge status-rejected">停用</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)($sessionCountMap[$a['id']] ?? 0) ?></td>
                        <td class="td-time"><?= date('Y-m-d H:i', strtotime($a['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info js-reset-pwd"
                                    data-id="<?= (int)$a['id'] ?>"
                                    data-username="<?= cleanOutput($a['username']) ?>">重置密码</button>

                            <select class="role-select btn-xs"
                                    onchange="changeRole(<?= (int)$a['id'] ?>, this.value)"
                                    <?= $isSelf ? 'disabled title="不能修改自己的角色"' : '' ?>>
                                <?php foreach ($roleNames as $roleKey => $roleLabel): ?>
                                <option value="<?= $roleKey ?>" <?= $a['role'] === $roleKey ? 'selected' : '' ?>><?= $roleLabel ?></option>
                                <?php endforeach; ?>
                            </select>

                            <?php if ((int)$a['status'] === 1): ?>
                                <button class="btn btn-xs btn-warning"
                                    <?= $isSelf ? 'disabled title="不能停用自己"' : '' ?>
                                    onclick="toggleStatus(<?= (int)$a['id'] ?>, 0)">停用</button>
                            <?php else: ?>
                                <button class="btn btn-xs btn-success"
                                    onclick="toggleStatus(<?= (int)$a['id'] ?>, 1)">启用</button>
                            <?php endif; ?>

                            <?php if ((int)($sessionCountMap[$a['id']] ?? 0) > 0): ?>
                                <button class="btn btn-xs btn-danger" onclick="kickAll(<?= (int)$a['id'] ?>)">全部退出</button>
                            <?php endif; ?>

                            <button class="btn btn-xs btn-danger"
                                <?= $isSelf ? 'disabled title="不能删除自己"' : '' ?>
                                onclick="deleteAdmin(<?= (int)$a['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 新建管理员 -->
<div class="modal" id="createModal" style="display:none;">
    <div class="modal-content" style="max-width:460px;">
        <div class="modal-header">
            <h3>新建管理员</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>用户名</label>
                <input type="text" id="newUsername" maxlength="50" placeholder="2-50 位中英文、数字或下划线">
            </div>
            <div class="form-group">
                <label>初始密码</label>
                <input type="password" id="newPassword" maxlength="64" placeholder="至少 6 位">
            </div>
            <div class="form-group">
                <label>角色</label>
                <select id="newRole">
                    <?php foreach ($roleNames as $roleKey => $roleLabel): ?>
                    <option value="<?= $roleKey ?>"><?= $roleLabel ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-tip">
                <p>超级管理员：全部功能；审核员：审核/删除留言、处理举报；只读账号：仅查看。</p>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeCreateModal()">取消</button>
                <button class="btn btn-primary" onclick="submitCreate()">创建</button>
            </div>
        </div>
    </div>
</div>

<!-- 重置密码 -->
<div class="modal" id="passwordModal" style="display:none;">
    <div class="modal-content" style="max-width:460px;">
        <div class="modal-header">
            <h3>重置密码 - <span id="passwordAdminName"></span></h3>
            <button class="modal-close" onclick="closePasswordModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>新密码</label>
                <input type="password" id="resetPassword" maxlength="64" placeholder="至少 6 位">
            </div>
            <div class="form-tip">
                <p>重置后该账号其他设备的会话将立即失效，需要用新密码重新登录。</p>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closePasswordModal()">取消</button>
                <button class="btn btn-primary" onclick="submitPassword()">确认重置</button>
            </div>
        </div>
    </div>
</div>

<script>
let passwordAdminId = null;

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-reset-pwd');
    if (btn) {
        openPasswordModal(parseInt(btn.dataset.id, 10), btn.dataset.username);
    }
});

function postAction(formData, doneText, after) {
    fetch('api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.code === 0) {
                alert(data.msg || doneText);
                if (after) after(); else location.reload();
            } else {
                alert(data.msg || '操作失败');
            }
        });
}

function openCreateModal() {
    document.getElementById('newUsername').value = '';
    document.getElementById('newPassword').value = '';
    document.getElementById('createModal').style.display = 'flex';
}
function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}
function submitCreate() {
    const fd = new FormData();
    fd.append('action', 'admin_create');
    fd.append('username', document.getElementById('newUsername').value.trim());
    fd.append('password', document.getElementById('newPassword').value);
    fd.append('role', document.getElementById('newRole').value);
    postAction(fd, '创建成功', closeCreateModal);
}

function openPasswordModal(id, name) {
    passwordAdminId = id;
    document.getElementById('passwordAdminName').textContent = name;
    document.getElementById('resetPassword').value = '';
    document.getElementById('passwordModal').style.display = 'flex';
}
function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
    passwordAdminId = null;
}
function submitPassword() {
    if (!passwordAdminId) return;
    const fd = new FormData();
    fd.append('action', 'admin_update');
    fd.append('field', 'password');
    fd.append('id', passwordAdminId);
    fd.append('value', document.getElementById('resetPassword').value);
    postAction(fd, '重置成功', closePasswordModal);
}

function changeRole(id, role) {
    if (!confirm('修改角色后该账号所有会话立即失效，需要重新登录。确定继续？')) {
        location.reload();
        return;
    }
    const fd = new FormData();
    fd.append('action', 'admin_update');
    fd.append('field', 'role');
    fd.append('id', id);
    fd.append('value', role);
    postAction(fd, '角色已更新');
}

function toggleStatus(id, status) {
    const text = status === 0 ? '停用后该账号所有会话立即失效。确定停用？' : '确定启用该账号？';
    if (!confirm(text)) { location.reload(); return; }
    const fd = new FormData();
    fd.append('action', 'admin_update');
    fd.append('field', 'status');
    fd.append('id', id);
    fd.append('value', status);
    postAction(fd, '状态已更新');
}

function kickAll(id) {
    if (!confirm('确定退出该账号的全部会话吗？')) return;
    const fd = new FormData();
    fd.append('action', 'session_revoke_admin_others');
    fd.append('admin_id', id);
    postAction(fd, '已全部退出');
}

function deleteAdmin(id) {
    if (!confirm('确定删除该管理员账号吗？删除后其所有会话立即失效，且不可恢复！')) return;
    const fd = new FormData();
    fd.append('action', 'admin_delete');
    fd.append('id', id);
    postAction(fd, '已删除');
}

document.getElementById('createModal').addEventListener('click', function (e) {
    if (e.target === this) closeCreateModal();
});
document.getElementById('passwordModal').addEventListener('click', function (e) {
    if (e.target === this) closePasswordModal();
});
</script>
