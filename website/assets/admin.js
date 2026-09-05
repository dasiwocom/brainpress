/* BrainPress 后台配置面板脚本（从 index.php 抽出独立维护）
 * 依赖：页面内联的 window.ADMIN_MENU_MD（侧滑菜单 md 内容）
 */
function $(id) { return document.getElementById(id); }
// 三栏布局初始化
var appEl = $('app');
if (appEl) appEl.classList.add('show');
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
// Local Mounts two-tab toggle: Vault Render / Custom Path
var mountTab = 'vault';
function setMountTab(tab) {
    mountTab = tab;
    var opts = document.querySelectorAll('#toggle-mounts .toggle-opt');
    for (var i = 0; i < opts.length; i++) {
        opts[i].classList.toggle('active', opts[i].dataset.mode === tab);
    }
    $('toggle-mounts-thumb').classList.toggle('right', tab === 'custom');
    $('panel-vault').style.display = tab === 'vault' ? '' : 'none';
    $('panel-custom').style.display = tab === 'custom' ? '' : 'none';
}
document.querySelectorAll('#toggle-mounts .toggle-opt').forEach(function (el) {
    el.addEventListener('click', function () { setMountTab(el.dataset.mode); });
});
// AI 视图双档选择栏：Chat Model / Agent API
var aiTab = 'chat';
function setAiTab(tab) {
    aiTab = tab;
    var opts = document.querySelectorAll('#toggle-ai .toggle-opt');
    for (var i = 0; i < opts.length; i++) {
        opts[i].classList.toggle('active', opts[i].dataset.tab === tab);
    }
    $('toggle-ai-thumb').classList.toggle('right', tab === 'agent');
    $('panel-ai-chat').style.display = tab === 'chat' ? '' : 'none';
    $('panel-ai-agent').style.display = tab === 'agent' ? '' : 'none';
}
document.querySelectorAll('#toggle-ai .toggle-opt').forEach(function (el) {
    el.addEventListener('click', function () { setAiTab(el.dataset.tab); });
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
bindSwitch('switch-ima');
var CUSTOM_COUNT = 5;
for (var ci = 1; ci <= CUSTOM_COUNT; ci++) {
    bindSwitch('switch-custom-' + ci);
}
var RSS_COUNT = 5;
for (var ri = 1; ri <= RSS_COUNT; ri++) {
    bindSwitch('switch-rss-' + ri);
}
bindSwitch('switch-rt-markdown');
bindSwitch('switch-rt-pdf');
bindSwitch('switch-rt-html');
bindSwitch('switch-rt-canvas');
bindSwitch('switch-light');
bindSwitch('switch-drawer');
bindSwitch('switch-ai-enabled');
bindSwitch('switch-ai-mode');
bindSwitch('switch-graph-labels');
bindSwitch('switch-pin-nav');
bindSwitch('switch-footer');
// 脚注：失焦保存（支持 HTML）
$('footer-html').addEventListener('change', saveConfig);
// 字体切换：选择后立即保存 + 实时预览
$('font-preset').addEventListener('change', function () {
    saveConfig();
    applyFontPreset(this.value);
});
function applyFontPreset(preset) {
    var serifStack = '"DejaVu Serif","Songti SC","STSong","SimSun","Noto Serif CJK SC",serif';
    var nunitoStack = 'system-ui, -apple-system, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", "Noto Sans CJK SC", sans-serif';
    var stack = preset === 'serif' ? serifStack : nunitoStack;
    // body 也有显式 font-family，只设 <html> 会被覆盖，预览看不出变化；这里同时覆盖 body 子树
    document.documentElement.style.fontFamily = stack;
    document.body.style.fontFamily = stack;
}
fetch('/api/admin/config').then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) return;
    try { $('dav-url').textContent = d.webdav_url; } catch (e) { console.log('restore dav:', e); }
    try { davAccounts = (d.webdav_mounts || []).map(function (m) { return { user: m.user || '', pass: m.pass || '', path: m.path || '' }; }); renderDavRows(); } catch (e) { console.log('restore webdav_mounts:', e); }
    try { $('minio-endpoint').value = d.minio.endpoint; } catch (e) { console.log('restore minio:', e); }
    try { $('minio-access').value = d.minio.access; } catch (e) { console.log('restore minio-access:', e); }
    try { $('minio-secret').value = d.minio.secret; } catch (e) { console.log('restore minio-secret:', e); }
    try { $('minio-bucket').value = d.minio.bucket; } catch (e) { console.log('restore minio-bucket:', e); }
    try { $('ima-client-id').value = d.ima.client_id || ''; } catch (e) { console.log('restore ima-client-id:', e); }
    try { $('ima-api-key').value = d.ima.api_key || ''; } catch (e) { console.log('restore ima-api-key:', e); }
    try {
        var cps = d.custom_paths || [];
        for (var i = 1; i <= CUSTOM_COUNT; i++) {
            var s = cps[i - 1] || { path: '', on: false };
            $('custom-path-' + i).value = s.path || '';
            if (s.on) $('switch-custom-' + i).classList.add('on');
        }
    } catch (e) { console.log('restore custom:', e); }
    try { if (d.render_webdav === true) $('switch-webdav').classList.add('on'); } catch (e) {}
    try { if (d.render_minio !== false) $('switch-minio').classList.add('on'); } catch (e) {}
    try { if (d.render_ima === true) $('switch-ima').classList.add('on'); } catch (e) {}
    try {
        var rfs = d.rss_feeds || [];
        for (var i = 1; i <= RSS_COUNT; i++) {
            var s = rfs[i - 1] || { url: '', title: '', on: false };
            $('rss-feed-' + i + '-url').value = s.url || '';
            $('rss-feed-' + i + '-title').value = s.title || '';
            if (s.on) $('switch-rss-' + i).classList.add('on');
        }
    } catch (e) { console.log('restore rss:', e); }
    try { if (d.default_light) $('switch-light').classList.add('on'); } catch (e) {}
    try { if (d.front_drawer_expanded !== false) $('switch-drawer').classList.add('on'); } catch (e) {}
    try { if (d.ai_mode !== 'strict') $('switch-ai-mode').classList.add('on'); } catch (e) {}
    try { if (d.ai_enabled !== false) $('switch-ai-enabled').classList.add('on'); } catch (e) {}
    try { if (d.graph_show_labels) $('switch-graph-labels').classList.add('on'); } catch (e) {}
    try { if (d.pin_navbar) $('switch-pin-nav').classList.add('on'); } catch (e) {}
    // 渲染文件类型开关（默认全开）
    var rt = d.render_types || { markdown: true, pdf: true, html: true, canvas: true };
    try { if (rt.markdown !== false) $('switch-rt-markdown').classList.add('on'); } catch (e) {}
    try { if (rt.pdf !== false) $('switch-rt-pdf').classList.add('on'); } catch (e) {}
    try { if (rt.html !== false) $('switch-rt-html').classList.add('on'); } catch (e) {}
    try { if (rt.canvas !== false) $('switch-rt-canvas').classList.add('on'); } catch (e) {}
    try { $('graph-path').value = d.graph_path || ''; } catch (e) {}
    try { $('site-title').value = d.site_title || 'BrainPress'; } catch (e) {}
    try { $('home-article').value = d.home_article || ''; } catch (e) {}
    try { $('content-width').value = d.content_width || 840; } catch (e) {}
    try { if (d.article_footer !== false) $('switch-footer').classList.add('on'); } catch (e) {}
    try { $('footer-html').value = d.article_footer_html || ''; } catch (e) {}
    try { $('api-token').value = d.api_token || ''; } catch (e) {}
    try { $('ai-api-base').value = d.ai_api_base || ''; } catch (e) {}
    try { $('ai-api-key').value = d.ai_api_key || ''; } catch (e) {}
    try { $('ai-model').value = d.ai_model || 'deepseek-chat'; } catch (e) {}
    try { $('font-preset').value = d.font_preset || 'nunito'; } catch (e) {}
    try { fillAgentView(); } catch (e) {}
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
// 当前视图的消息提示（Mounts 内按当前 Toggle 页定位）
function curMsg() {
    if (document.getElementById('view-dav').style.display !== 'none') return $('msg-dav');
    if (document.getElementById('view-mounts').style.display !== 'none') {
        return mountTab === 'minio' ? $('msg-minio') : mountTab === 'custom' ? $('msg-custom') : $('msg-vault');
    }
    if (document.getElementById('view-rss').style.display !== 'none') return $('msg-rss');
    if (document.getElementById('view-prefs').style.display !== 'none') return $('msg-prefs');
    if (document.getElementById('view-site').style.display !== 'none') return $('msg-site');
    if (document.getElementById('view-ai').style.display !== 'none') {
        return aiTab === 'agent' ? $('msg-agent') : $('msg-ai');
    }
    if (document.getElementById('view-graph').style.display !== 'none') return $('msg-graph');
    if (document.getElementById('view-types').style.display !== 'none') return $('msg-types');
    if (document.getElementById('view-pinned').style.display !== 'none') return $('msg-pinned');
    if (document.getElementById('view-expanded').style.display !== 'none') return $('msg-expanded');
    if (document.getElementById('view-hidden').style.display !== 'none') return $('msg-hidden');
    if (document.getElementById('view-minio').style.display !== 'none') return $('msg-minio');
    if (document.getElementById('view-ima').style.display !== 'none') return $('msg-ima');
    return $('msg-vault');
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
// 收集 5 条 RSS feed 状态
function rssState() {
    var arr = [];
    for (var i = 1; i <= RSS_COUNT; i++) {
        arr.push({
            url: $('rss-feed-' + i + '-url').value.trim(),
            title: $('rss-feed-' + i + '-title').value.trim(),
            on: $('switch-rss-' + i).classList.contains('on')
        });
    }
    return arr;
}
// WebDAV 同步账号（多账号：各自 user/pass/path，保存到 webdav_mounts）
var davAccounts = [];
function renderDavRows() {
    var box = $('dav-rows');
    box.innerHTML = '';
    if (!davAccounts.length) {
        var empty = document.createElement('p');
        empty.className = 'desc';
        empty.textContent = 'No sync accounts yet. Add one below — e.g. username "alice", folder "Notes".';
        box.appendChild(empty);
        return;
    }
    davAccounts.forEach(function (acc, idx) {
        var row = document.createElement('div');
        row.className = 'dav-row';
        var u = document.createElement('input');
        u.type = 'text'; u.className = 'dav-user'; u.placeholder = 'Username'; u.value = acc.user;
        var p = document.createElement('input');
        p.type = 'password'; p.className = 'dav-pass'; p.autocomplete = 'new-password'; p.placeholder = 'Password'; p.value = acc.pass;
        var d = document.createElement('input');
        d.type = 'text'; d.className = 'dav-path'; d.placeholder = 'Sync folder · empty = vault root'; d.value = acc.path;
        var del = document.createElement('button');
        del.className = 'copy-btn dav-del'; del.textContent = '×';
        del.setAttribute('aria-label', 'remove account');
        del.addEventListener('click', function () {
            davAccounts.splice(idx, 1);
            renderDavRows();
        });
        row.appendChild(u); row.appendChild(p); row.appendChild(d); row.appendChild(del);
        box.appendChild(row);
    });
}
function collectDavRows() {
    var rows = $('dav-rows').querySelectorAll('.dav-row');
    var list = [];
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var u = r.querySelector('.dav-user').value.trim();
        var p = r.querySelector('.dav-pass').value;
        var pt = r.querySelector('.dav-path').value.trim().replace(/^\/+|\/+$/g, '');
        if (u === '' && p === '' && pt === '') continue;
        list.push({ user: u, pass: p, path: pt });
    }
    return list;
}
$('btn-dav-add').addEventListener('click', function () {
    davAccounts.push({ user: '', pass: '', path: '' });
    renderDavRows();
});
$('btn-dav-save').addEventListener('click', function () {
    saveConfig();
});
// 自动保存：输入框失焦（点击屏幕其他处）即保存
function saveConfig() {
    var payload = {
        endpoint: $('minio-endpoint').value.trim(),
        access: $('minio-access').value.trim(),
        secret: $('minio-secret').value.trim(),
        bucket: $('minio-bucket').value.trim(),
        render_webdav: $('switch-webdav').classList.contains('on'),
        render_minio: $('switch-minio').classList.contains('on'),
        render_ima: $('switch-ima').classList.contains('on'),
        ima_client_id: $('ima-client-id').value.trim(),
        ima_api_key: $('ima-api-key').value.trim(),
        custom_paths: customState(),
        rss_feeds: rssState(),
        exclude_paths: excludeItems.slice(),
        pinned_dirs: pinnedDirs.slice(),
        pinned_articles: pinnedArticles.slice(),
        expanded_dirs: expandedDirs.slice(),
        default_light: $('switch-light').classList.contains('on'),
        front_drawer_expanded: $('switch-drawer').classList.contains('on'),
        ai_mode: $('switch-ai-mode').classList.contains('on'),
        ai_enabled: $('switch-ai-enabled').classList.contains('on'),
        graph_show_labels: $('switch-graph-labels').classList.contains('on'),
        render_types: {
            markdown: $('switch-rt-markdown').classList.contains('on'),
            pdf: $('switch-rt-pdf').classList.contains('on'),
            html: $('switch-rt-html').classList.contains('on'),
            canvas: $('switch-rt-canvas').classList.contains('on')
        },
        pin_navbar: $('switch-pin-nav').classList.contains('on'),
        graph_path: $('graph-path').value.trim(),
        site_title: $('site-title').value.trim(),
        home_article: $('home-article').value.trim(),
        content_width: parseInt($('content-width').value, 10) || '',
        article_footer: $('switch-footer').classList.contains('on'),
        article_footer_html: $('footer-html').value.trim(),
        api_token: $('api-token').value.trim(),
        webdav_mounts: collectDavRows(),
        ai_api_base: $('ai-api-base').value.trim(),
        ai_api_key: $('ai-api-key').value.trim(),
        ai_model: $('ai-model').value.trim(),
        font_preset: $('font-preset').value
    };
    fetch('/api/admin/config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (d) {
        var msg = curMsg();
        if (d.ok) {
            msg.className = 'msg ok';
            msg.textContent = d.tested === 'skip' ? 'Saved' : ('Saved: ' + d.tested);
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
var blurIds = ['minio-endpoint', 'minio-access', 'minio-secret', 'minio-bucket', 'ima-client-id', 'ima-api-key', 'site-title', 'home-article', 'content-width', 'footer-html', 'api-token', 'ai-api-base', 'ai-api-key', 'ai-model', 'graph-path'];
for (var bi = 1; bi <= CUSTOM_COUNT; bi++) blurIds.push('custom-path-' + bi);
for (var bi = 1; bi <= RSS_COUNT; bi++) {
    blurIds.push('rss-feed-' + bi + '-url');
    blurIds.push('rss-feed-' + bi + '-title');
}
blurIds.forEach(function (id) {
    $(id).addEventListener('blur', saveConfig);
});
// token 失焦：保存后同步刷新 Agent 视图里的 curl 示例
$('api-token').addEventListener('blur', fillAgentView);
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
    try { localStorage.setItem('vp-theme', dark ? 'dark' : 'light'); document.cookie = 'vp-theme=' + (dark ? 'dark' : 'light') + '; path=/'; } catch (e) {}
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
// Agent 接入视图：填充 base URL / 示例（token 从输入框实时读取，失焦后重新生成）
function fillAgentView() {
    try {
        var base = location.origin;
        var tok = $('api-token').value.trim();
        $('agent-base-url').textContent = base;
        $('agent-example-write').textContent =
            'curl -X POST ' + base + '/api/note \\\n' +
            '  -H "Authorization: Bearer ' + (tok || 'YOUR_TOKEN') + '" \\\n' +
            '  -H "Content-Type: application/json" \\\n' +
            '  -d \'{"path":"notes/example.md","content":"# Example\\n\\nWritten by an agent."}\'';
        $('agent-example-ask').textContent =
            'curl -X POST ' + base + '/api/ask \\\n' +
            '  -H "Content-Type: application/json" \\\n' +
            '  -d \'{"question":"Summarize what this knowledge base covers."}\'';
    } catch (e) { console.log('fill agent:', e); }
}
$('btn-agent-copy-url').addEventListener('click', function () { fallbackCopy($('agent-base-url').textContent); });
// 视图切换：挂载设置 / 偏好设置 / 站点设置 / AI（Chat+Agent 双档） / 图谱设置 / 目录管理
function showView(name) {
    var views = ['dav', 'mounts', 'rss', 'minio', 'prefs', 'site', 'ai', 'graph', 'pinned', 'expanded', 'hidden', 'ima', 'types'];
    for (var i = 0; i < views.length; i++) {
        $('view-' + views[i]).style.display = views[i] === name ? '' : 'none';
    }
    // 菜单高亮当前视图（同时高亮抽屉和左侧栏）
    function highlightLinks(root) {
        if (!root) return;
        var links = root.querySelectorAll('a');
        for (var j = 0; j < links.length; j++) {
            var h = links[j].getAttribute('href') || '';
            links[j].style.color = h.indexOf('view=' + name) > -1 ? 'var(--vp-c-brand)' : '';
        }
    }
    highlightLinks(drawerMd);
    highlightLinks(leftDrawerMd);
}
// 菜单按钮：切换全屏侧滑菜单（内容已预渲染，点击仅切换动画零延迟）
var menuBtn = $('vp-menu-btn');
var drawer = $('vp-drawer');
var drawerMd = $('drawer-md');
var leftDrawerMd = $('left-drawer-md');
function setDrawer(open) {
    drawer.classList.toggle('open', open);
    document.body.classList.toggle('drawer-open', open);
    $('nav-wrap').classList.toggle('no-blur', open);
}
// AI 面板切换（同前台：桌面端原地填充左栏，窄屏端走抽屉）
var aiViewEl = $('ai-view');
var aiInput = $('ai-input');
var aiMsgs = $('ai-msgs');
function toggleAi(open) {
    if (open === undefined) open = !document.body.classList.contains('ai-open');
    document.body.classList.toggle('ai-open', open);
    var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
    if (open) {
        placeAi();
        if (!desktop) setDrawer(true);
        else setTimeout(function () { aiInput.focus({ preventScroll: true }); }, 100);
    } else {
        if (!desktop) setDrawer(false);
    }
}
// 把 AI 面板挂进当前断点对应的面板容器（桌面=左栏 / 窄屏=抽屉）—— 同前台逻辑
function placeAi() {
    var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
    var target = desktop ? document.getElementById('left-sidebar') : drawer;
    if (target && aiViewEl.parentNode !== target) target.appendChild(aiViewEl);
}
window.addEventListener('resize', function () {
    placeAi();
    var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
    if (!desktop && document.body.classList.contains('ai-open')) {
        document.body.classList.remove('ai-open');
        setDrawer(false);
    }
});
// AI 对话：发消息（同前台逻辑）
function aiAddMsg(text, role) {
    var d = document.createElement('div');
    d.className = 'ai-msg ' + (role === 'user' ? 'ai-user' : 'ai-bot');
    d.textContent = text;
    aiMsgs.appendChild(d);
    aiMsgs.scrollTop = aiMsgs.scrollHeight;
    return d;
}
function aiAsk() {
    var q = aiInput.value.trim();
    if (!q) return;
    aiAddMsg(q, 'user');
    aiInput.value = '';
    var loading = aiAddMsg('Thinking...', 'bot');
    loading.classList.add('ai-typing');
    fetch('/api/ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question: q })
    }).then(function (r) { return r.json(); }).then(function (d) {
        loading.remove();
        if (!d.ok) {
            aiAddMsg('Error: ' + (d.error || 'unknown error'), 'bot');
            return;
        }
        var bot = aiAddMsg(d.answer, 'bot');
        if (d.sources && d.sources.length) {
            var src = document.createElement('div');
            src.className = 'ai-src';
            src.appendChild(document.createTextNode('Sources: '));
            d.sources.forEach(function (s, i) {
                var a = document.createElement('a');
                a.href = '/' + s.path;
                a.textContent = s.name;
                src.appendChild(a);
                if (i < d.sources.length - 1) src.appendChild(document.createTextNode(' · '));
            });
            bot.appendChild(src);
        }
    }).catch(function (err) {
        loading.remove();
        aiAddMsg('Network error: ' + (err && err.message ? err.message : 'unknown'), 'bot');
    });
}
// AI 按钮：桌面端左栏原地切换 AI 面板，窄屏走抽屉
$('vp-ai-btn').addEventListener('click', function (e) {
    e.stopPropagation();
    var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
    toggleAi(desktop ? true : undefined);
});
$('ai-send').addEventListener('click', aiAsk);
aiInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); aiAsk(); }
    else if (e.key === 'Escape') { toggleAi(false); }
});
// 页面加载时预渲染菜单内容到左侧栏和抽屉
if (window.ADMIN_MENU_MD) {
    try {
        var parsed = DOMPurify.sanitize(marked.parse(window.ADMIN_MENU_MD, { gfm: true }));
        drawerMd.innerHTML = parsed;
        if (leftDrawerMd) leftDrawerMd.innerHTML = parsed;
        // 兼容写法：找 li 的直接子 UL（不用 :scope，微信旧内核不支持）
        function childUl(li) {
            for (var i = 0; i < li.children.length; i++) {
                if (li.children[i].tagName === 'UL') return li.children[i];
            }
            return null;
        }
        // 向上找最近的 li 祖先（不用 closest）
        function parentLi(el, root) {
            var n = el.parentNode;
            while (n && n !== root && n.tagName !== 'LI') n = n.parentNode;
            return n && n.tagName === 'LI' ? n : null;
        }
        // 折叠树 + 链接点击：同时绑定到抽屉和左侧栏
        function bindMenu(container) {
            if (!container) return;
            var lis = container.querySelectorAll('li');
            for (var i = 0; i < lis.length; i++) {
                (function (li) {
                    var sub = childUl(li);
                    if (!sub) return;
                    li.classList.add('has-children');
                    // 插入左侧箭头 SVG（chevron，同前台）
                    var arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    arrow.setAttribute('class', 'dir-arrow');
                    arrow.setAttribute('width', '15');
                    arrow.setAttribute('height', '15');
                    arrow.setAttribute('viewBox', '0 0 24 24');
                    arrow.setAttribute('fill', 'none');
                    arrow.setAttribute('stroke', 'currentColor');
                    arrow.setAttribute('stroke-width', '3');
                    arrow.setAttribute('stroke-linecap', 'round');
                    arrow.setAttribute('stroke-linejoin', 'round');
                    arrow.innerHTML = '<path d="M9 6l6 6-6 6"/>';
                    li.insertBefore(arrow, li.firstChild);
                    li.addEventListener('click', function (ev) {
                        if (sub.contains(ev.target)) return;
                        ev.preventDefault();
                        li.classList.toggle('collapsed');
                    });
                })(lis[i]);
            }
            var links = container.querySelectorAll('a');
            for (var j = 0; j < links.length; j++) {
                (function (a) {
                    var li = parentLi(a, container);
                    if (li && childUl(li)) return;
                    a.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        var h = a.getAttribute('href').replace(/^#/, '');
                        if (h.indexOf('view=') === 0) {
                            showView(h.substring(5));
                        }
                        setDrawer(false);
                    });
                })(links[j]);
            }
        }
        bindMenu(drawerMd);
        bindMenu(leftDrawerMd);
    } catch (e) {}
}
menuBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    // 桌面端：AI 打开时关闭 AI 回到目录树；AI 关闭时无反应（目录已常驻）
    if (window.matchMedia && window.matchMedia('(min-width:769px)').matches) {
        if (document.body.classList.contains('ai-open')) toggleAi(false);
        return;
    }
    // 窄屏端：开/关抽屉
    var open = drawer.classList.contains('open');
    if (!open || document.body.classList.contains('ai-open')) {
        document.body.classList.remove('ai-open');
        setDrawer(true);
    } else {
        setDrawer(false);
    }
});
// 点击侧滑菜单空白处关闭
drawer.addEventListener('click', function (e) {
    if (e.target === drawer || e.target === drawerMd) {
        if (document.body.classList.contains('ai-open')) toggleAi(false);
        setDrawer(false);
    }
});
