/**
 * 后台会话守卫：
 * 1. 定时 + 标签页重新可见时向 api.php?action=ping 校验会话；
 * 2. 会话被踢/过期/账号停用/权限变化（401）时，明确提示并回到登录页；
 * 3. 通过 localStorage storage 事件广播，多个标签页同时跳转，保持一致；
 * 4. 拦截页面内 fetch：401 统一处理，403 越权给出提示（按钮因角色已隐藏，此为兜底）。
 */
(function () {
    'use strict';

    var REASON_TEXT = {
        kicked: '您的会话已在其他设备上被退出，请重新登录',
        expired: '登录会话已过期，请重新登录',
        disabled: '账号已被停用，请联系超级管理员',
        role_changed: '您的账号权限已变更，请重新登录',
        not_logged_in: '登录状态已失效，请重新登录'
    };

    function reasonText(reason, msg) {
        if (msg) return msg;
        return REASON_TEXT[reason] || '登录状态已失效，请重新登录';
    }

    // sessionStorage 按标签页隔离：保证每个标签页都弹一次提示，但同页不重复弹
    function alreadyHandled() {
        try {
            if (sessionStorage.getItem('admin_kicked_handled')) return true;
            sessionStorage.setItem('admin_kicked_handled', '1');
        } catch (e) {}
        return false;
    }

    function handleKick(reason, msg) {
        if (alreadyHandled()) return;
        var text = reasonText(reason, msg);
        // 广播给同源的其他标签页
        try {
            localStorage.setItem('admin_kicked', JSON.stringify({reason: reason, msg: text, t: Date.now()}));
        } catch (e) {}
        alert('⚠️ ' + text);
        location.replace('login.php?reason=' + encodeURIComponent(reason || 'expired'));
    }

    // 其他标签页发出的踢出广播
    window.addEventListener('storage', function (e) {
        if (e.key !== 'admin_kicked' || !e.newValue) return;
        try {
            var payload = JSON.parse(e.newValue);
            if (alreadyHandled()) return;
            alert('⚠️ ' + reasonText(payload.reason, payload.msg));
            location.replace('login.php?reason=' + encodeURIComponent(payload.reason || 'expired'));
        } catch (err) {}
    });

    // 轻量心跳：用 XHR，避免与被包装的 fetch 互相干扰
    var lastPing = 0;
    function ping() {
        var now = Date.now();
        if (now - lastPing < 5000) return;
        lastPing = now;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api.php?action=ping&_=' + now, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 401) {
                var reason = 'expired', msg = '';
                try {
                    var j = JSON.parse(xhr.responseText);
                    reason = (j.data && j.data.reason) || reason;
                    msg = j.msg || '';
                } catch (e) {}
                handleKick(reason, msg);
            }
        };
        xhr.send();
    }

    setInterval(ping, 30000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') ping();
    });
    window.addEventListener('focus', ping);

    // 统一包装 fetch：页面内既有调用无需逐个改造
    var originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function () {
            var args = arguments;
            var requestUrl = typeof args[0] === 'string' ? args[0]
                : (args[0] && args[0].url) || '';

            return originalFetch.apply(this, args).then(function (res) {
                if (requestUrl.indexOf('api.php') === -1) return res;

                if (res.status === 401) {
                    res.clone().json().then(function (j) {
                        handleKick(j.data && j.data.reason, j.msg);
                    }).catch(function () {
                        handleKick('expired');
                    });
                    // 页面即将跳转，挂起后续 .then，避免重复弹窗
                    return new Promise(function () {});
                }

                if (res.status === 403) {
                    // 透传一个 JSON 响应，由各页面已有的 code!==0 分支弹出 msg
                    return res;
                }

                return res;
            });
        };
    }
})();
