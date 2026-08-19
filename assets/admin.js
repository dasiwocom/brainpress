/* MD2HTML 后台配置面板脚本（从 index.php 抽出独立维护）
 * 依赖：页面内联的 window.ADMIN_MENU_MD（侧滑菜单 md 内容）
 */
function $(id) { return document.getElementById(id); }
function copyVal(id) {
    var v = $(id).textContent;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(v).catch(function(){ fallbackCopy(v); });
    } else fallbackCopy(v);
}
function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
}
// 滑块切换：WebDAV Mount / MinIO Storage / Custom Path
var currentMode = 'webdav';
function setMode(mode) {
    currentMode = mode;
    document.querySelectorAll('.toggle-opt').forEach(function (el) {
        el.classList.toggle('active', el.dataset.mode === mode);
    });
    var thumb = $('toggle-thumb');
    thumb.classList.toggle('mid', mode === 'minio');
    thumb.classList.toggle('right', mode === 'custom');
    $('panel-webdav').style.display = mode === 'webdav' ? '' : 'none';
    $('panel-minio').style.display = mode === 'minio' ? '' : 'none';
    $('panel-custom').style.display = mode === 'custom' ? '' : 'none';
}
document.querySelectorAll('.toggle-opt').forEach(function (el) {
    el.addEventListener('click', function () { setMode(el.dataset.mode); });
});
// 渲染开关（多选：可同时开，点击即自动保存）
function bindSwitch(btnId) {
    $(btnId).addEventListener('click', function () {
        this.classList.toggle('on');
        saveConfig();
    });
}
bindSwitch('switch-webdav');
bindSwitch('switch-minio');
var CUSTOM_COUNT = 5;
for (var ci = 1; ci <= CUSTOM_COUNT; ci++) {
    bindSwitch('switch-custom-' + ci);
}
bindSwitch('switch-light');
bindSwitch('switch-drawer');
bindSwitch('switch-ai-enabled');
bindSwitch('switch-ai-mode');
bindSwitch('switch-graph-labels');
fetch('/api/admin/config').then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) return;
    try { $('dav-url').textContent = d.webdav_url; } catch (e) { console.log('restore dav:', e); }
    try { $('dav-user').textContent = d.webdav_user; } catch (e) { console.log('restore dav-user:', e); }
    try { $('dav-pass').textContent = d.webdav_pass; } catch (e) { console.log('restore dav-pass:', e); }
    try { $('minio-endpoint').value = d.minio.endpoint; } catch (e) { console.log('restore minio:', e); }
    try { $('minio-access').value = d.minio.access; } catch (e) { console.log('restore minio-access:', e); }
    try { $('minio-secret').value = d.minio.secret; } catch (e) { console.log('restore minio-secret:', e); }
    try { $('minio-bucket').value = d.minio.bucket; } catch (e) { console.log('restore minio-bucket:', e); }
    try {
        var cps = d.custom_paths || [];
        for (var i = 1; i <= CUSTOM_COUNT; i++) {
            var s = cps[i - 1] || { path: '', on: false };
            $('custom-path-' + i).value = s.path || '';
            if (s.on) $('switch-custom-' + i).classList.add('on');
        }
    } catch (e) { console.log('restore custom:', e); }
    try { if (d.render_webdav !== false) $('switch-webdav').classList.add('on'); } catch (e) {}
    try { if (d.render_minio !== false) $('switch-minio').classList.add('on'); } catch (e) {}
    try { if (d.default_light) $('switch-light').classList.add('on'); } catch (e) {}
    try { if (d.front_drawer_expanded !== false) $('switch-drawer').classList.add('on'); } catch (e) {}
    try { if (d.ai_mode !== 'strict') $('switch-ai-mode').classList.add('on'); } catch (e) {}
    try { if (d.ai_enabled !== false) $('switch-ai-enabled').classList.add('on'); } catch (e) {}
    try { if (d.graph_show_labels) $('switch-graph-labels').classList.add('on'); } catch (e) {}
    try { $('site-title').value = d.site_title || 'MD2HTML'; } catch (e) {}
    try { $('home-article').value = d.home_article || ''; } catch (e) {}
    try { $('api-token').value = d.api_token || ''; } catch (e) {}
    try { $('ai-api-key').value = d.ai_api_key || ''; } catch (e) {}
    try { $('ai-model').value = d.ai_model || 'deepseek-chat'; } catch (e) {}
    // 隐藏列表：原地合并服务器已有值（保持数组引用，避免闭包绑定失效）
    try {
        (d.exclude_paths || []).forEach(function (p) {
            if (excludeItems.indexOf(p) === -1) excludeItems.push(p);
        });
        renderExcludeList();
    } catch (e) { console.log('restore exclude:', e); }
    // Tree：置顶目录 + 置顶文章 + 展开目录（原地合并）
    try {
        (d.pinned_dirs || []).forEach(function (p) {
            if (pinnedDirs.indexOf(p) === -1) pinnedDirs.push(p);
        });
        renderPinnedDirList();
    } catch (e) { console.log('restore pinned-dirs:', e); }
    try {
        (d.pinned_articles || []).forEach(function (p) {
            if (pinnedArticles.indexOf(p) === -1) pinnedArticles.push(p);
        });
        renderPinnedArticleList();
    } catch (e) { console.log('restore pinned-articles:', e); }
    try {
        (d.expanded_dirs || []).forEach(function (p) {
            if (expandedDirs.indexOf(p) === -1) expandedDirs.push(p);
        });
        renderExpandedDirList();
    } catch (e) { console.log('restore expanded-dirs:', e); }
});
// 合并服务器列表与本地已添加项（去重）
function mergeList(serverList, localList) {
    serverList = serverList || [];
    localList = localList || [];
    var out = serverList.slice();
    localList.forEach(function (p) {
        if (out.indexOf(p) === -1) out.push(p);
    });
    return out;
}

// 隐藏列表：添加 / 删除（每项 = 目录名或文件路径，命中即前台不可见）
var excludeItems = [];
function renderExcludeList() {
    var box = $('exclude-list');
    box.innerHTML = '';
    excludeItems.forEach(function (p, idx) {
        var row = document.createElement('div');
        row.className = 'exclude-row';
        var span = document.createElement('span');
        span.className = 'exclude-path';
        span.textContent = p;
        var del = document.createElement('button');
        del.className = 'copy-btn';
        del.textContent = '×';
        del.setAttribute('aria-label', 'remove ' + p);
        del.addEventListener('click', function () {
            excludeItems.splice(idx, 1);
            renderExcludeList();
            saveConfig();
        });
        row.appendChild(span);
        row.appendChild(del);
        box.appendChild(row);
    });
}
$('btn-exclude-add').addEventListener('click', function () {
    var v = $('exclude-input').value.trim();
    if (!v) return;
    if (excludeItems.indexOf(v) === -1) {
        excludeItems.push(v);
        renderExcludeList();
        saveConfig();
    }
    $('exclude-input').value = '';
});
// 输入框回车等同 Add
$('exclude-input').addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') {
        ev.preventDefault();
        $('btn-exclude-add').click();
    }
});
// Tree：置顶目录 + 置顶文章 + 展开目录（列表式，复用隐藏列表交互）
var pinnedDirs = [];
var pinnedArticles = [];
var expandedDirs = [];
function renderPinnedDirList() {
    var box = $('pinned-dir-list');
    box.innerHTML = '';
    if (!pinnedDirs.length) {
        box.innerHTML = '<div class="list-empty">No pinned dirs yet</div>';
        return;
    }
    pinnedDirs.forEach(function (p, idx) {
        var row = document.createElement('div');
        row.className = 'exclude-row';
        var span = document.createElement('span');
        span.className = 'exclude-path';
        span.textContent = p;
        var del = document.createElement('button');
        del.className = 'copy-btn';
        del.textContent = '×';
        del.setAttribute('aria-label', 'remove ' + p);
        del.addEventListener('click', function () {
            pinnedDirs.splice(idx, 1);
            renderPinnedDirList();
            saveConfig();
        });
        row.appendChild(span);
        row.appendChild(del);
        box.appendChild(row);
    });
}
function renderPinnedArticleList() {
    var box = $('pinned-article-list');
    box.innerHTML = '';
    if (!pinnedArticles.length) {
        box.innerHTML = '<div class="list-empty">No pinned articles yet</div>';
        return;
    }
    pinnedArticles.forEach(function (p, idx) {
        var row = document.createElement('div');
        row.className = 'exclude-row';
        var span = document.createElement('span');
        span.className = 'exclude-path';
        span.textContent = p;
        var del = document.createElement('button');
        del.className = 'copy-btn';
        del.textContent = '×';
        del.setAttribute('aria-label', 'remove ' + p);
        del.addEventListener('click', function () {
            pinnedArticles.splice(idx, 1);
            renderPinnedArticleList();
            saveConfig();
        });
        row.appendChild(span);
        row.appendChild(del);
        box.appendChild(row);
    });
}
function renderExpandedDirList() {
    var box = $('expanded-dir-list');
    box.innerHTML = '';
    if (!expandedDirs.length) {
        box.innerHTML = '<div class="list-empty">No expanded dirs yet</div>';
        return;
    }
    expandedDirs.forEach(function (p, idx) {
        var row = document.createElement('div');
        row.className = 'exclude-row';
        var span = document.createElement('span');
        span.className = 'exclude-path';
        span.textContent = p;
        var del = document.createElement('button');
        del.className = 'copy-btn';
        del.textContent = '×';
        del.setAttribute('aria-label', 'remove ' + p);
        del.addEventListener('click', function () {
            expandedDirs.splice(idx, 1);
            renderExpandedDirList();
            saveConfig();
        });
        row.appendChild(span);
        row.appendChild(del);
        box.appendChild(row);
    });
}
function bindAddList(inputId, btnId, list, renderFn) {
    $(btnId).addEventListener('click', function () {
        var v = $(inputId).value.trim();
        if (!v) return;
        if (list.indexOf(v) === -1) {
            list.push(v);
            renderFn();
            saveConfig();
        }
        $(inputId).value = '';
    });
    $(inputId).addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            $(btnId).click();
        }
    });
}
bindAddList('pinned-dir-input', 'btn-pinned-dir-add', pinnedDirs, renderPinnedDirList);
bindAddList('pinned-article-input', 'btn-pinned-article-add', pinnedArticles, renderPinnedArticleList);
bindAddList('expanded-dir-input', 'btn-expanded-dir-add', expandedDirs, renderExpandedDirList);
// 当前视图的消息提示
function curMsg() {
    if (currentMode === 'custom') return $('msg-custom');
    if (currentMode === 'minio') return $('msg');
    if (document.getElementById('view-prefs').style.display !== 'none') return $('msg-prefs');
    if (document.getElementById('view-site').style.display !== 'none') return $('msg-site');
    if (document.getElementById('view-hidden').style.display !== 'none') return $('msg-hidden');
    if (document.getElementById('view-tree').style.display !== 'none') return $('msg-tree');
    return $('msg-webdav');
}
// 收集 5 条自定义路径状态
function customState() {
    var arr = [];
    for (var i = 1; i <= CUSTOM_COUNT; i++) {
        arr.push({
            path: $('custom-path-' + i).value.trim(),
            on: $('switch-custom-' + i).classList.contains('on')
        });
    }
    return arr;
}
// 自动保存：输入框失焦（点击屏幕其他处）即保存
function saveConfig() {
    var payload = {
        storage: currentMode,
        endpoint: $('minio-endpoint').value.trim(),
        access: $('minio-access').value.trim(),
        secret: $('minio-secret').value.trim(),
        bucket: $('minio-bucket').value.trim(),
        render_webdav: $('switch-webdav').classList.contains('on'),
        render_minio: $('switch-minio').classList.contains('on'),
        custom_paths: customState(),
        exclude_paths: excludeItems.slice(),
        pinned_dirs: pinnedDirs.slice(),
        pinned_articles: pinnedArticles.slice(),
        expanded_dirs: expandedDirs.slice(),
        default_light: $('switch-light').classList.contains('on'),
        front_drawer_expanded: $('switch-drawer').classList.contains('on'),
        ai_mode: $('switch-ai-mode').classList.contains('on'),
        ai_enabled: $('switch-ai-enabled').classList.contains('on'),
        graph_show_labels: $('switch-graph-labels').classList.contains('on'),
        site_title: $('site-title').value.trim(),
        home_article: $('home-article').value.trim(),
        api_token: $('api-token').value.trim(),
        ai_api_key: $('ai-api-key').value.trim(),
        ai_model: $('ai-model').value.trim()
    };
    fetch('/api/admin/config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (d) {
        var msg = curMsg();
        if (d.ok) {
            msg.className = 'msg ok';
            msg.textContent = d.tested === 'skip' ? 'Saved' : 'Saved: MinIO tested ' + d.tested;
        } else {
            msg.className = 'msg err';
            msg.textContent = d.error || 'Save failed';
        }
    }).catch(function () {
        var msg = curMsg();
        msg.className = 'msg err';
        msg.textContent = 'Request failed';
    });
}
// 修改密码：新密码失焦提交，需填旧密码验证身份（旧密码本身不触发保存）
$('site-password').addEventListener('blur', function () {
    var msg = $('msg-site');
    var oldP = $('site-password-old').value;
    var newP = $('site-password').value;
    if (!newP) return; // 新密码为空则不操作
    if (!oldP) { msg.className = 'msg err'; msg.textContent = 'Enter current password first'; return; }
    if (newP.length < 4) { msg.className = 'msg err'; msg.textContent = 'Password must be at least 4 characters'; return; }
    fetch('/api/admin/password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ old_password: oldP, new_password: newP })
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) {
            $('site-password').value = '';
            $('site-password-old').value = '';
            msg.className = 'msg ok';
            msg.textContent = 'Password updated';
        } else {
            msg.className = 'msg err';
            msg.textContent = d.error || 'Update failed';
        }
    }).catch(function () {
        msg.className = 'msg err';
        msg.textContent = 'Request failed';
    });
});
// pinned-dir 已改为列表式（输入框 + Add），无失焦保存逻辑
var blurIds = ['minio-endpoint', 'minio-access', 'minio-secret', 'minio-bucket', 'site-title', 'home-article', 'api-token', 'ai-api-key', 'ai-model'];
for (var bi = 1; bi <= CUSTOM_COUNT; bi++) blurIds.push('custom-path-' + bi);
blurIds.forEach(function (id) {
    $(id).addEventListener('blur', saveConfig);
});
// AI 接入：测试连接（发一个测试问题给 /api/ask，验证 key 与检索链路）
$('btn-ai-test').addEventListener('click', function () {
    var msg = $('msg-ai');
    msg.className = 'msg';
    msg.textContent = 'Testing...';
    fetch('/api/ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question: 'Test: introduce this knowledge base in one sentence.' })
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) {
            msg.className = 'msg ok';
            msg.textContent = 'OK — ' + (d.answer || '').substring(0, 160) + (d.answer && d.answer.length > 160 ? '...' : '') + ' (sources: ' + (d.sources || []).length + ')';
        } else {
            msg.className = 'msg err';
            msg.textContent = d.error || 'Request failed';
        }
    }).catch(function () {
        msg.className = 'msg err';
        msg.textContent = 'Request failed';
    });
});
// 主题切换（与主站一致）：点击切换日夜模式"
function applyTheme(dark) {
    document.documentElement.classList.toggle('dark', dark);
    try { localStorage.setItem('vp-theme', dark ? 'dark' : 'light'); } catch (e) {}
}
function bindThemeBtn(id) {
    $(id).addEventListener('click', function (e) {
        e.stopPropagation();
        applyTheme(!document.documentElement.classList.contains('dark'));
    });
}
bindThemeBtn('vp-theme-btn');
bindThemeBtn('vp-theme-btn-m');
// 门图标：返回主站首页
$('vp-home-btn').addEventListener('click', function () {
    window.location.href = '/';
});
// 视图切换：挂载设置 / 偏好设置 / 站点设置 / AI 接入 / 图谱设置 / 隐藏管理 / 目录管理
function showView(name) {
    var views = ['mounts', 'prefs', 'site', 'ai', 'graph', 'hidden', 'tree'];
    for (var i = 0; i < views.length; i++) {
        $('view-' + views[i]).style.display = views[i] === name ? '' : 'none';
    }
    // 菜单高亮当前视图
    var links = drawerMd.querySelectorAll('a');
    for (var j = 0; j < links.length; j++) {
        var h = links[j].getAttribute('href') || '';
        links[j].style.color = h.indexOf('view=' + name) > -1 ? 'var(--vp-c-brand)' : '';
    }
}
// 菜单按钮：切换全屏侧滑菜单（内容已预渲染，点击仅切换动画零延迟）
var menuBtn = $('vp-menu-btn');
var drawer = $('vp-drawer');
var drawerMd = $('drawer-md');
function setDrawer(open) {
    drawer.classList.toggle('open', open);
    document.body.classList.toggle('drawer-open', open); // 锁定页面滚动防抖动
    // 动画期间关顶部栏模糊，防掉帧卡顿（动画结束后恢复）
    $('nav-wrap').classList.toggle('no-blur', open);
}
// 页面加载时预渲染菜单内容（隐藏状态，点击时无需再渲染）
if (window.ADMIN_MENU_MD) {
    try {
        drawerMd.innerHTML = DOMPurify.sanitize(marked.parse(window.ADMIN_MENU_MD, { gfm: true }));
        // 兼容写法：找 li 的直接子 UL（不用 :scope，微信旧内核不支持）
        function childUl(li) {
            for (var i = 0; i < li.children.length; i++) {
                if (li.children[i].tagName === 'UL') return li.children[i];
            }
            return null;
        }
        // 向上找最近的 li 祖先（不用 closest）
        function parentLi(el) {
            var n = el.parentNode;
            while (n && n !== drawerMd && n.tagName !== 'LI') n = n.parentNode;
            return n && n.tagName === 'LI' ? n : null;
        }
        // 折叠树：有子列表的父项 → 点击父项折叠/展开
        var allLi = drawerMd.querySelectorAll('li');
        for (var i = 0; i < allLi.length; i++) {
            (function (li) {
                var sub = childUl(li);
                if (!sub) return;
                li.classList.add('has-children');
                li.addEventListener('click', function (ev) {
                    if (sub.contains(ev.target)) return; // 子项区域交给链接处理
                    ev.preventDefault();
                    li.classList.toggle('collapsed');
                });
            })(allLi[i]);
        }
        // 子项链接点击：解析 view 参数切换视图；无 view 则只关闭抽屉
        var allA = drawerMd.querySelectorAll('a');
        for (var j = 0; j < allA.length; j++) {
            (function (a) {
                var li = parentLi(a);
                if (li && childUl(li)) return;
                a.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    var h = a.getAttribute('href').replace(/^#/, '');
                    if (h.indexOf('view=') === 0) {
                        showView(h.substring(5));
                    }
                    setDrawer(false);
                });
            })(allA[j]);
        }
    } catch (e) {}
}
menuBtn.addEventListener('click', function () {
    setDrawer(!drawer.classList.contains('open'));
});
// 点击侧滑菜单空白处关闭
drawer.addEventListener('click', function (e) {
    if (e.target === drawer || e.target === drawerMd) {
        setDrawer(false);
    }
});
