/**
 * 后台公共脚本
 * - 每 15 秒向后端确认会话状态（过期、被其他设备退出、账号停用、权限变化时立即回登录页）
 * - 通过 BroadcastChannel / localStorage 在同浏览器多个标签页之间同步退出与权限变化
 * - 统一拦截 fetch 响应：401 带提示回登录页，403 弹出权限不足
 */
(function () {
    'use strict';

    var AUTH_TOKEN = document.documentElement.getAttribute('data-auth-token') ||
        (window.__AUTH_TOKEN__ || '');
    var AUTH_SIG = document.documentElement.getAttribute('data-auth-sig') || '';
    var HEARTBEAT_MS = 15000;
    var BC_KEY = 'community-board-admin-auth';
    var bc = null;

    try {
        if (window.BroadcastChannel) {
            bc = new BroadcastChannel(BC_KEY);
        }
    } catch (e) {
        bc = null;
    }

    function goLogin(reason, silent) {
        // silent=true 时表示这是收到其他标签页的广播，不再重复广播，避免循环跳转
        if (!silent) {
            try {
                var payload = JSON.stringify({ reason: reason || 'invalid', at: Date.now() });
                if (bc) bc.postMessage(payload);
                localStorage.setItem(BC_KEY, payload);
            } catch (e) { /* ignore */ }
        }

        var url = 'login.php?reason=' + encodeURIComponent(reason || 'invalid');
        if (location.pathname.indexOf('/admin/') === -1) url = 'admin/' + url;
        location.replace(url);
    }

    function notifyLogout() {
        try {
            var payload = JSON.stringify({ reason: 'logged_out', at: Date.now() });
            if (bc) bc.postMessage(payload);
            localStorage.setItem(BC_KEY, payload);
        } catch (e) { /* ignore */ }
    }

    function handleAuthError(data, status) {
        var reason = (data && data.reason) || (status === 403 ? 'forbidden' : 'invalid');
        if (status === 401) {
            goLogin(reason);
            return true;
        }
        if (status === 403) {
            alert((data && data.msg) || '权限不足，无法执行该操作');
            return true;
        }
        return false;
    }

    // 统一包装全局 fetch，后台页面无需各自处理登录失效
    var originalFetch = window.fetch;
    window.fetch = function () {
        return originalFetch.apply(this, arguments).then(function (resp) {
            if (resp.status === 401 || resp.status === 403) {
                var cloned = resp.clone();
                cloned.json().catch(function () { return {}; }).then(function (data) {
                    if (!handleAuthError(data, resp.status) && data && data.msg) {
                        alert(data.msg);
                    }
                });
                // 返回一个永不 resolve 的 Promise，阻断原页面 then 回调继续操作
                return new Promise(function () {});
            }
            return resp;
        });
    };

    // 会话状态轮询
    function ping() {
        originalFetch('api.php?action=session_check', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (resp) {
            if (resp.status === 401) {
                resp.json().catch(function () { return {}; }).then(function (d) {
                    goLogin(d.reason || 'invalid');
                });
                return null;
            }
            return resp.json();
        }).then(function (data) {
            if (!data) return;
            if (data.code !== 0) {
                goLogin(data.reason || 'invalid');
                return;
            }
            // 权限在服务端被改变（其他标签页/设备）：刷新以应用新权限，再由后端拦截回登录页
            if (data.data && data.data.sig && data.data.sig !== AUTH_SIG) {
                location.reload();
            }
        }).catch(function () { /* 网络抖动不处理 */ });
    }
    setInterval(ping, HEARTBEAT_MS);

    // 多标签页同步：收到广播的标签页只负责跳转，不再二次广播
    if (bc) {
        bc.onmessage = function (e) {
            try {
                var payload = JSON.parse(e.data);
                if (payload && payload.reason && payload.at && Date.now() - payload.at < 30000) {
                    goLogin(payload.reason, true);
                }
            } catch (err) { /* ignore */ }
        };
    }
    window.addEventListener('storage', function (e) {
        if (e.key !== BC_KEY || !e.newValue) return;
        try {
            var payload = JSON.parse(e.newValue);
            if (payload && payload.reason && payload.at && Date.now() - payload.at < 30000) {
                goLogin(payload.reason, true);
            }
        } catch (err) { /* ignore */ }
    });

    // 退出链接：先广播再跳转，保证多标签页一致（其他标签页统一显示“已退出登录”）
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a.admin-logout-link') : null;
        if (link) {
            e.preventDefault();
            notifyLogout();
            location.href = link.href;
        }
    });

    // 从浏览器历史缓存恢复页面时重新确认会话
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) ping();
    });
})();
