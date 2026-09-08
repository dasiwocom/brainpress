/* BrainPress 前台脚本（从 index.php 抽出独立维护）
 * 依赖：页内联 boot 变量（FRONT_MENU_MD / SSR_* / HOME_MD / SITE_TITLE 等，见 index.php）
 */
(function () {
    'use strict';
    var state = { path: null, tree: [] };
    // 文章渲染缓存：目录树切换复用已渲染 HTML，免重复网络请求 + 整篇 md 重渲染（FIFO 上限防内存膨胀）
    var _articleCache = {}, _articleCacheOrder = [];
    function cacheArticle(path, html) {
        if (!_articleCache[path]) {
            _articleCacheOrder.push(path);
            if (_articleCacheOrder.length > 50) {
                var evicted = _articleCacheOrder.shift();
                delete _articleCache[evicted];
            }
        }
        _articleCache[path] = html;
    }
    /* 渲染核心已抽到 assets/render.js（window.BP_Render，纯 Markdown→DOM，无壳状态耦合）；
       此处保留编排层：processObsidian 仍留 site.js（嵌入/锚点/反链需 state.tree/视图切换） */
    var esc = BP_Render.esc;
    var mdToHtml = BP_Render.markdown;
    var renderToc = BP_Render.toc;
    var addCodeCopy = BP_Render.codeCopy;
    var addCodeLineNumbers = BP_Render.codeLines;
    var fallbackCopy = BP_Render.copyFallback;
    var loadHighlightLib = BP_Render.hljsLoad;
    var applyHighlight = BP_Render.hljsApply;
    var loadMermaidLib = BP_Render.mermaidLoad;
    var renderMermaid = BP_Render.mermaid;
    var transformCallouts = BP_Render.callouts;
    var applyBlockIds = BP_Render.blockIds;
    var renderMath = BP_Render.math;
    var loadKatexLib = BP_Render.mathLoad;
    var $ = function (id) { return document.getElementById(id); };
    // 内容视图互斥：切换前隐藏全部特殊视图（PDF/Excalidraw/Canvas/Graph）——
    // 各渲染器只藏自己认识的容器会导致上一个画布残留（如 canvas 与 excalidraw 同页，需刷新才消失）
    function hideSpecialViews() {
        ['pdf-view', 'excalidraw-view', 'canvas-view', 'graph-view'].forEach(function (id) {
            var e = $(id);
            if (e) e.style.display = 'none';
        });
    }

    /* ---------- 基础 ---------- */
    function toast(msg) {
        var el = $('toast');
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.classList.remove('show'); }, 2200);
    }

    async function api(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({}, opts.headers || {});
        if (opts.body && typeof opts.body === 'object') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        var res = await fetch(url, opts);
        var data = null;
        try { data = await res.json(); } catch (e) { /* ignore */ }
        if (!res.ok) {
            throw new Error(data && data.error ? data.error : ('Request failed' + ' ' + res.status));
        }
        return data;
    }

    // 与 PHP tree_to_md 对齐（rawurlencode + 还原斜杠）：文件名含空格/括号/加号/逗号等字符时，
    // 树 markdown 的链接 URL 必须预编码，否则 marked 解析不出 <a> 或 href 错乱
    function pathHref(path) {
        return encodeURIComponent(path).replace(/[!'()*]/g, function (c) {
            return '%' + c.charCodeAt(0).toString(16).toUpperCase();
        }).replace(/%2F/g, '/');
    }
    // 完整解码路径片段：decodeURI 会保留 %2B/%2C 等保留字符的转义，二次编码后路径失配 → 文件不存在
    function decPath(s) {
        try { return decodeURIComponent(s); } catch (e) { return decodeURI(s); }
    }

    function renderHome() {
        if (!window.HOME_MD) {
            hideSpecialViews();
            $('doc-wrap').style.display = 'none';
            $('md-view').style.display = 'none';
            $('tag-view').style.display = 'none';
            $('toc-panel').style.display = 'none';
            if (lgWrap) lgWrap.style.display = 'none';
            $('empty-state').textContent = 'No home article configured. Set it in Admin → Site Settings.';
            $('empty-state').style.display = '';
            return;
        }
        showArticle(mdToHtml(window.HOME_MD), window.HOME_PATH || '/', window.HOME_TITLE || undefined);
        if (window.HOME_PATH) state.path = window.HOME_PATH;
    }
    // 标签页：#tag/<name> — 独立整页，收集所有含该标签的笔记列表
    // 标签渲染序号：同一路线的 popstate + hashchange 可能各自触发一次 showTag（或快速连续点多个标签）。
    // 每批渲染只认最新一次调用——旧的异步结果一律丢弃，避免 #tag-list 里出现重复条目/重复计数。
    var tagRenderSeq = 0;
    function showTag(tagName) {
        if (!tagName) return false;
        var mySeq = ++tagRenderSeq;
        var tag = tagName.toLowerCase();
        // 树未就绪时（SSR 直达 #/tag/... 时序早于 loadTree 完成）自行拉一次文件列表，
        // 保证标签页不依赖 state.tree 的加载时机
        var ensureTree = function () {
            if (state.tree && state.tree.length) return Promise.resolve();
            return api('/api/list').then(function (data) {
                state.tree = data.tree || [];
                buildDocMap(state.tree);
            }).catch(function () {});
        };
        ensureTree().then(function () {
            if (mySeq !== tagRenderSeq) return;  // 已有更新的 showTag，本批作废
            var files = collectFiles();
            hideSpecialViews();
            $('archive-view').style.display = 'none';
            $('empty-state').style.display = 'none';
            $('doc-wrap').style.display = 'none';
            $('toc-panel').style.display = 'none';
            $('tag-title').textContent = '#' + tagName;
            $('tag-title').style.display = '';
            $('tag-view').style.display = '';
            if (lgWrap) lgWrap.style.display = 'none';   // 标签页不显示局部图
            var md = $('tag-list');
            md.innerHTML = '<div class="tag-page-heading">Notes tagged <code>#' + esc(tagName) + '</code></div>';
            var hitCount = 0;
            var pending = files.length;
            files.forEach(function (f) {
                api('/api/file?path=' + encodeURIComponent(f.path)).then(function (data) {
                    if (mySeq !== tagRenderSeq) return;  // 过期批次：终止
                    var c = data.content || '';
                    var matched = c.toLowerCase().indexOf('#' + tag) > -1;
                    // 兼容 frontmatter tags: ["tag"]（无 # 前缀）
                    if (!matched) {
                        var fmMatch = c.match(/^---\n([\s\S]*?)\n---/);
                        if (fmMatch) {
                            var fmBody = fmMatch[1];
                            var tagsLine = fmBody.match(/^tags:\s*\[([^\]]*)\]/m);
                            if (tagsLine) {
                                var tagItems = tagsLine[1].split(',');
                                for (var ti = 0; ti < tagItems.length; ti++) {
                                    var tName = tagItems[ti].replace(/^\s*["']?|["']?\s*$/g, '').toLowerCase();
                                    if (tName === tag) { matched = true; break; }
                                }
                            }
                        }
                    }
                    if (matched) {
                        hitCount++;
                        var disp = (f.name || '').replace(/^\d+-/, '').replace(/\.md$/i, '');
                        var row = document.createElement('div');
                        row.className = 'tag-item';
                        row.innerHTML = '<span class="tag-item-name" data-path="' + esc(f.path) + '">' + esc(disp) + '</span>';
                        row.querySelector('.tag-item-name').addEventListener('click', function () { selectFile({ path: f.path }); });
                        md.appendChild(row);
                    }
                    if (--pending <= 0) finishTag();
                }).catch(function () {
                    if (mySeq !== tagRenderSeq) return;  // 过期批次：终止
                    if (--pending <= 0) finishTag();
                });
            });
            if (!files.length) finishTag();
            function finishTag() {
                if (mySeq !== tagRenderSeq) return;  // 过期批次：终止
                var info = document.createElement('div');
                info.className = 'tag-item-count';
                info.textContent = hitCount + ' note' + (hitCount === 1 ? '' : 's');
                md.appendChild(info);
                $('content').scrollTop = 0;
            }
        });
        return true;
    }
    function enterApp() {
        $('app').classList.add('show');
        // 服务端渲染直达（URL 直接访问 /xxx.md）：内联内容同步渲染显示（无 fetch 等待，打开即文章）
        if (window.SSR_MD) {
            renderHome(); // 预渲染主页（隐藏状态）：SSR 直达时主页默认未渲染，logo 回首页需立即可用
            var h0 = '';
            try { h0 = decPath(location.hash.replace(/^#/, '')); } catch (e) {}
            // 直达标签页（/Article.md#/tag/<name>）：不渲染正文，直接进标签页，避免先闪一遍文章
            if (h0 && /^\/?tag\//.test(h0)) {
                state.path = '';
                loadTree();
                handleHash();  // handleHash 会把地址规范化到 /#/tag/<name>
                return;
            }
            state.path = window.SSR_PATH;
            var shtml = mdToHtml(window.SSR_MD);
            showArticle(shtml, window.SSR_PATH);
            loadTree();
            return;
        }
        // 服务端渲染直达（URL 直接访问 /xxx.pdf）：内联路径，前端 pdf.js 渲染阅读器
        if (window.SSR_PDF) {
            renderHome(); // 预渲染主页（隐藏状态）
            state.path = window.SSR_PDF;
            openPdf(window.SSR_PDF);
            loadTree();
            return;
        }
        // 服务端渲染直达（URL 直接访问 .excalidraw.md）：renderExcalidraw 异步切换视图（等字体加载），
        // 这里同样跳过主页路径；两个 TOC 面板由 renderExcalidraw 显式隐藏
        if (window.SSR_EXCALIDRAW) {
            state.path = window.EXCALIDRAW_PATH || '';
            $('doc-wrap').style.display = 'none';
            $('archive-view').style.display = 'none';
            loadTree();
            return;
        }
        // 服务端渲染直达（URL 直接访问 .canvas）：同步渲染画布并切换视图（同 SSR_PDF 模式——
        // 底部独立触发器会先于 init() 执行、被本分支重新隐藏，故必须在分支内调用）
        if (window.SSR_CANVAS) {
            state.path = window.CANVAS_PATH || '';
            $('archive-view').style.display = 'none';
            renderCanvas();
            loadTree();
            return;
        }
        // 服务端渲染直达（URL 直接访问 .html）：内联原始 HTML，前端直接渲染（保留脚本/样式）
        if (window.SSR_HTML) {
            renderHome(); // 预渲染主页（隐藏状态）
            state.path = window.HTML_PATH || '';
            showHtml(window.HTML_CONTENT);
            loadTree();
            return;
        }
        // 默认显示首页（渲染首页文章正文——与普通文章同管线）
        $('doc-wrap').style.display = 'none';
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        // 带 hash（直达文章）：完整路径立即加载（selectFile 不依赖树，避免黑屏等待 loadTree）；
        // 数字 ID / 纯文件名依赖 _docMap（loadTree 构建），保持隐藏等 loadTree 后 handleHash 处理
        var h = '';
        try { h = decPath(location.hash.replace(/^#/, '')); } catch (e) {}
        if (h) {
            var tm = h.replace(/^\//, '').match(/^tag\/(.+)$/);
            if (tm) {
                // 直达标签页（/#/tag/<name>）：提前隐藏其余视图，showTag 异步出列表
                hideSpecialViews();
                $('archive-view').style.display = 'none';
                $('doc-wrap').style.display = 'none';
                var tn0;
                try { tn0 = decodeURI(tm[1]); } catch (e2) { tn0 = tm[1]; }
                showTag(tn0);
            } else if (h.indexOf('/') > -1 || /\.md$/i.test(h)) {
                selectFile({ path: h });
            } else {
                hideSpecialViews();
                $('archive-view').style.display = 'none';
                $('doc-wrap').style.display = 'none';
            }
        } else {
            // 主页：与普通文章完全一致的渲染管线（renderHome → showArticle）
            renderHome();
        }
        loadTree();
    }

    /* ---------- 文件树 ---------- */
    // 构建名字映射（数字 ID / 显示名 → 完整路径）：双链、嵌入、反向链接、hash 直达共用。
    function buildDocMap(nodes) {
        window._docMap = window._docMap || {};
        (function walk(list) {
            list.forEach(function (node) {
                if (node.type === 'dir') {
                    walk(node.children || []);
                } else if (node.type === 'file' && /\.md$/i.test(node.name)) {
                    var m = node.name.match(/^(\d+)-/);
                    if (m) window._docMap[m[1]] = node.path;
                    // 显示名（去 ID 前缀、去 .md）也映射，双链 [[名]] 可匹配无 ID 文章
                    window._docMap[node.name.replace(/^\d+-/, '').replace(/\.md$/i, '')] = node.path;
                }
            });
        })(nodes);
    }
    // 树（_docMap）晚于正文渲染到达时的补救：升级渲染瞬间因查不到名字而标 missing 的双链/嵌入
    function retryObsidian() {
        var root = $('md-view');
        if (!root) return;
        var touched = false;
        root.querySelectorAll('a.ob-link.ob-link-missing[data-link]').forEach(function (a) {
            var target = findNote(a.dataset.link);
            if (!target) return;
            a.classList.remove('ob-link-missing');
            a.href = '#' + encodeURI(target);
            a.title = target;
            a.dataset.path = target;
            if (a.dataset.sub) a.addEventListener('click', function () { window._pendingAnchor = a.dataset.sub; });
            touched = true;
        });
        root.querySelectorAll('.ob-embed.ob-embed-missing[data-name]').forEach(function (el) {
            var nm = el.dataset.name;
            el.classList.remove('ob-embed-missing');
            el.textContent = 'Loading embed: ' + nm + '...';
            mountEmbed(el, nm, el.dataset.sub || null);
            touched = true;
        });
        // backlinks 功能已移除（页面底部双链列表不再渲染）
    }
    async function loadTree() {
        try {
            var data = await api('/api/list');
            state.tree = data.tree;
            buildDocMap(state.tree);
            // SSR 直达/快速点击时正文先于树渲染：补一次按名解析（双链/嵌入/反向链接）
            if (state.path) retryObsidian();
            // 主页已由 PHP 服务端渲染最新文章列表，树只用于搜索索引 + hash 直达
            // 文件树就绪后，处理 URL hash（数字 ID、完整路径或纯文件名直达）
            var h = location.hash.replace(/^#/, '');
            if (h && !state.path && !/^\/?tag\//.test(h)) {
                try {
                    var path = null;
                    if (/^\d+$/.test(h) && window._docMap && window._docMap[h]) {
                        path = window._docMap[h];
                    } else {
                        path = decPath(h);
                        // 兜底：纯文件名（无路径前缀）时查 _docMap 显示名映射
                        if (path.indexOf('/') === -1 && window._docMap && window._docMap[path]) {
                            path = window._docMap[path];
                        }
                    }
                    if (path) selectFile({ path: path });
                } catch (e) {}
            }
        } catch (e) {
            toast(e.message);
        }
    }


    /* ---------- 文件操作 ---------- */
    // 生成文章目录（TOC）：扫描 md-view 里的 h1-h3，点击平滑滚动
    // PDF 渲染前置：按需加载 pdf.min.js（不拖慢首页）；文件从前端 /vault/ 路径加载
    function loadPdfJs(cb) {
        if (window.pdfjsLib) { cb(); return; }
        var s = document.createElement('script');
        s.src = '/assets/pdfjs/pdf.min.js';
        s.onload = cb;
        document.head.appendChild(s);
    }
    /* ===== PDF 阅读器共享组件：笔记嵌入与 URL 直开同构（Obsidian 式工具栏 + 框内滚动） ===== */
    function pdfIcon(d) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>';
    }
    // opts.full    直开整页模式：吃满 #pdf-view、键盘翻页常驻（嵌入模式仅全屏时响应）
    // opts.sub     嵌入语法 #page=N 子定位；opts.initPage 直开初始页
    function buildPdfPane(el, doc, opts) {
        opts = opts || {};
        el.classList.add('ob-pdf');
        if (opts.full) el.classList.add('ob-pdf-full');
        var st = { mode: 'auto', pct: 100, scale: 0, page: 1, dpr: Math.min((window.devicePixelRatio || 1) * 1.5, 3) };
        var w1 = 612, h1 = 792;   // 首页基准尺寸（加载后修正）

        /* ---- 骨架：工具栏 + 内部滚动区 ---- */
        var bar = document.createElement('div');
        bar.className = 'ob-pdf-toolbar';
        bar.innerHTML =
            '<button type="button" class="ob-pdf-btn ob-tocbtn" title="Outline" hidden>' + pdfIcon('M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01') + '</button>' +
            '<span class="ob-pdf-sep"></span>' +
            '<button type="button" class="ob-pdf-btn ob-prev" title="Previous page">' + pdfIcon('m15 18-6-6 6-6') + '</button>' +
            '<input type="text" inputmode="numeric" class="ob-pdf-pageinp" value="1" title="Page number">' +
            '<span class="ob-pdf-total">/ ' + doc.numPages + '</span>' +
            '<button type="button" class="ob-pdf-btn ob-next" title="Next page">' + pdfIcon('m9 18 6-6-6-6') + '</button>' +
            '<span class="ob-pdf-sep"></span>' +
            '<button type="button" class="ob-pdf-btn ob-zout" title="Zoom out">' + pdfIcon('M5 12h14') + '</button>' +
            '<select class="ob-pdf-zoomsel" title="Zoom">' +
                '<option value="auto" selected>Automatic</option>' +
                '<option value="page-fit">Page Fit</option>' +
                '<option value="page-width">Page Width</option>' +
                '<option value="50">50%</option><option value="75">75%</option>' +
                '<option value="100">100%</option><option value="125">125%</option>' +
                '<option value="150">150%</option><option value="200">200%</option>' +
                '<option value="300">300%</option><option value="400">400%</option>' +
            '</select>' +
            '<button type="button" class="ob-pdf-btn ob-zin" title="Zoom in">' + pdfIcon('M5 12h14M12 5v14') + '</button>' +
            '<span class="sp"></span>' +
            '<button type="button" class="ob-pdf-btn ob-fs" title="Fullscreen">' + pdfIcon('M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3') + '</button>';
        el.appendChild(bar);
        var body = document.createElement('div');
        body.className = 'ob-pdf-body';
        var box = document.createElement('div');
        box.className = 'ob-pdf-pages';
        body.appendChild(box);
        el.appendChild(body);
        var inp = bar.querySelector('.ob-pdf-pageinp');
        var sel = bar.querySelector('.ob-pdf-zoomsel');

        /* ---- 页槽与懒渲染 ---- */
        var io = null, rendered = {};
        function clearPages() {
            rendered = {};
            [].forEach.call(box.children, function (slot) {
                [].forEach.call(slot.querySelectorAll('canvas'), function (c) { c.remove(); });
                slot.classList.remove('done');
            });
        }
        function renderSlot(slot) {
            var n = parseInt(slot.dataset.page, 10);
            if (rendered[n]) return;
            rendered[n] = 1;
            doc.getPage(n).then(function (page) {
                var vp = page.getViewport({ scale: st.scale * st.dpr });
                var cv = document.createElement('canvas');
                cv.width = Math.round(vp.width);
                cv.height = Math.round(vp.height);
                slot.appendChild(cv);   // 显示尺寸由 CSS width:100% 控制，铺满框体
                // 像素渲染：pdf.js 绘入画布（缺失则整页空白）
                page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise
                    .then(function () { slot.classList.add('done'); })
                    .catch(function () { rendered[n] = 0; });
            });
        }
        function observeSlots() {
            if (io) io.disconnect();
            io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (ents) {
                ents.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    io.unobserve(en.target);
                    renderSlot(en.target);
                });
            }, { root: body, rootMargin: '300px' }) : null;
            [].forEach.call(box.children, function (slot) {
                var n = parseInt(slot.dataset.page, 10);
                if (rendered[n]) return;
                if (io) io.observe(slot);
                else renderSlot(slot);
            });
        }

        /* ---- 缩放：Automatic/Page Width=适配框宽；Page Fit=整页入框；其余为绝对百分比 ---- */
        function applyMode() {
            var availW = body.clientWidth || 600;   // 页面铺满框体，无内边距
            if (st.mode === 'auto' || st.mode === 'page-width') st.scale = availW / w1;
            else if (st.mode === 'page-fit') st.scale = Math.min(availW / w1, (body.clientHeight - 2) / h1);
            else st.scale = st.pct / 100;
            st.scale = Math.max(0.05, st.scale);
            [].forEach.call(box.children, function (slot) {
                slot.style.minHeight = Math.round(h1 * st.scale) + 'px';
            });
            clearPages();
            observeSlots();
        }

        /* ---- 翻页与滚动跟随（rect 相对坐标，不依赖 offsetParent） ---- */
        function relTop(node) {
            return node.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop;
        }
        function goPage(n, scroll) {
            n = Math.min(Math.max(1, n), doc.numPages);
            st.page = n;
            inp.value = n;
            if (scroll !== false) {
                var s = box.querySelector('[data-page="' + n + '"]');
                if (s) body.scrollTo({ top: Math.max(0, relTop(s)), behavior: 'smooth' });
            }
        }
        var ticking = false;
        body.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () {
                ticking = false;
                var cur = 1;
                [].forEach.call(box.children, function (slot) {
                    if (relTop(slot) <= body.scrollTop + 20) cur = parseInt(slot.dataset.page, 10);
                });
                if (document.activeElement !== inp && cur !== st.page) { st.page = cur; inp.value = cur; }
            });
        });

        /* ---- 工具栏交互 ---- */
        bar.querySelector('.ob-prev').addEventListener('click', function () { goPage(st.page - 1); });
        bar.querySelector('.ob-next').addEventListener('click', function () { goPage(st.page + 1); });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { goPage(parseInt(inp.value, 10) || 1); inp.blur(); }
        });
        inp.addEventListener('blur', function () { inp.value = st.page; });
        // 缩放步进沿下拉列表方向：越往下百分比越大（pdf.js 同序）
        bar.querySelector('.ob-zout').addEventListener('click', function () {
            if (sel.selectedIndex > 0) { sel.selectedIndex -= 1; sel.dispatchEvent(new Event('change')); }
        });
        bar.querySelector('.ob-zin').addEventListener('click', function () {
            if (sel.selectedIndex < sel.options.length - 1) { sel.selectedIndex += 1; sel.dispatchEvent(new Event('change')); }
        });
        sel.addEventListener('change', function () {
            var v = sel.value;
            if (v === 'auto' || v === 'page-fit' || v === 'page-width') { st.mode = v; }
            else { st.mode = 'pct'; st.pct = parseInt(v, 10); }
            applyMode();
        });
        bar.querySelector('.ob-fs').addEventListener('click', function () {
            if (document.fullscreenElement === el) {
                if (document.exitFullscreen) document.exitFullscreen();
            } else if (el.classList.contains('fs')) {
                el.classList.remove('fs');   // CSS 模拟全屏退出
            } else {
                var fn = el.requestFullscreen || el.webkitRequestFullscreen;
                if (fn) { try { fn.call(el); } catch (e) { el.classList.add('fs'); } }
                else el.classList.add('fs');
            }
            setTimeout(applyMode, 120);
        });
        // 键盘翻页：直开模式常驻；嵌入模式仅全屏时响应
        document.addEventListener('keydown', function pdfKey(e) {
            if (!el.isConnected) { document.removeEventListener('keydown', pdfKey); return; }
            if (!opts.full && document.fullscreenElement !== el && !el.classList.contains('fs')) return;
            if (e.target && /INPUT|SELECT|TEXTAREA/.test(e.target.tagName)) return;
            if (e.key === 'ArrowLeft' || e.key === 'PageUp') goPage(st.page - 1);
            else if (e.key === 'ArrowRight' || e.key === 'PageDown') goPage(st.page + 1);
        });

        /* ---- 文档大纲（getOutline → 抽屉；无大纲隐藏按钮） ---- */
        try {
            doc.getOutline().then(function (ol) {
                if (!ol || !ol.length) return;
                var tocBtn = bar.querySelector('.ob-tocbtn');
                tocBtn.hidden = false;
                var drawer = document.createElement('div');
                drawer.className = 'ob-pdf-outline';
                function build(items) {
                    var ul = document.createElement('ul');
                    items.forEach(function (it) {
                        var li = document.createElement('li');
                        var a = document.createElement('a');
                        a.textContent = it.title || '—';
                        a.addEventListener('click', function () {
                            Promise.resolve(typeof it.dest === 'string' ? doc.getDestination(it.dest) : it.dest)
                                .then(function (d) { return d ? doc.getPageIndex(d[0]) : null; })
                                .then(function (idx) { if (idx != null) goPage(idx + 1); })
                                .catch(function () {});
                        });
                        li.appendChild(a);
                        if (it.items && it.items.length) li.appendChild(build(it.items));
                        ul.appendChild(li);
                    });
                    return ul;
                }
                drawer.appendChild(build(ol));
                el.appendChild(drawer);
                tocBtn.addEventListener('click', function () { el.classList.toggle('outline-open'); });
            });
        } catch (e) {}

        /* ---- 尺寸自适应：窗口/容器变化时按模式重新适配（防抖） ---- */
        var rt;
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(function () {
                clearTimeout(rt);
                rt = setTimeout(function () {
                    if (!el.isConnected) return;
                    if (st.mode === 'auto' || st.mode === 'page-width' || st.mode === 'page-fit') applyMode();
                }, 160);
            }).observe(body);
        }

        /* ---- 初始化：首页定基准 → 建槽 → 应用缩放 → 初始页跳转 ---- */
        doc.getPage(1).then(function (p1) {
            w1 = p1.getViewport({ scale: 1 }).width;
            h1 = p1.getViewport({ scale: 1 }).height;
            for (var i = 1; i <= doc.numPages; i++) {
                (function (i) {
                    var slot = document.createElement('div');
                    slot.className = 'ob-pdf-page';
                    slot.dataset.page = i;
                    slot.style.minHeight = Math.round(body.clientWidth * h1 / w1) + 'px';
                    box.appendChild(slot);
                })(i);
            }
            applyMode();
            var pm = /^page=(\d+)$/i.exec((opts.sub || '').trim());
            var start = parseInt(pm ? pm[1] : (opts.initPage || 1), 10);
            if (start > 1) setTimeout(function () { goPage(start); }, 120);
        });
    }

    // URL 直开 PDF（/xxx.pdf SSR 路径）：视图切换后复用同一阅读器组件
    function openPdf(path, initPage) {
        hideSpecialViews();
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        $('doc-wrap').style.display = 'flex';
        var tp = $('toc-panel'); if (tp) tp.style.display = 'none';  // PDF 无目录 → 右栏留空
        if (lgWrap) lgWrap.style.display = 'none';   // PDF 视图不显示局部图
        $('md-view').style.display = 'none';
        // 标题：文件名（去 .pdf 扩展名，与目录树一致）
        var docName = path.split('/').pop().replace(/\.pdf$/i, '');
        $('doc-title').textContent = docName;
        try { document.title = docName + ' · ' + (window.SITE_TITLE || 'BrainPress'); } catch (e) {}
        var pv = $('pdf-view');
        pv.style.display = '';
        pv.innerHTML = '<div style="margin:auto;color:var(--vp-c-text-mute);font-size:13px;">Loading…</div>';
        loadPdfJs(function () {
            pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/pdfjs/pdf.worker.min.js';
            pdfjsLib.getDocument('/vault/' + path + '?embed=1').promise.then(function (doc) {
                pv.innerHTML = '';
                var host = document.createElement('div');
                pv.appendChild(host);
                buildPdfPane(host, doc, { full: true, initPage: initPage });
            }).catch(function (err) {
                pv.innerHTML = '<div style="padding:48px;text-align:center;color:var(--vp-c-text-mute);">Failed to load PDF: ' + ((err && err.message) || 'unknown error') + '</div>';
            });
        });
    }

    // 文章渲染统一出口：内联 HTML → 高亮/降级/标题/视图切换/增强（selectFile 与 SSR 共用）
    function showArticle(html, nodePath, titleOverride) {
        // markdown 图片（![](x.png)）的 <img> 不带嵌入标记——png 渲染开关关闭时 /vault/ 门禁会 404。
        // 统一补 ?embed=1：文章内 <img> 一律视为"嵌入引用"（与 ![[..]] 同语义）。相对图片以文章所在目录为基，重写为 /vault/ 绝对路径（与程序内嵌逻辑一致）
        var baseDir = String(nodePath || '').replace(/[^/]*$/, '');
        html = html.replace(/<img\b([^>]*)\bsrc="([^"]+)"([^>]*)>/gi, function (m0, att1, src, att2) {
            var nsrc = src;
            if (src.indexOf('/vault/') === 0) {
                nsrc = src.split('?')[0] + '?embed=1';
            } else if (/^https?:\/\//i.test(src) || src.indexOf('data:') === 0) {
                // 外部/内联图片不动
            } else if (/\.md$/i.test(String(nodePath || ''))) {
                // 相对图片：仅当本页是 vault markdown 文章才重写为 /vault/ 绝对路径（首页/RSS 无真实相对基）
                var rel = baseDir + decodeURIComponent(src.split('?')[0].replace(/^\.\//, ''));
                nsrc = '/vault/' + rel.split('?')[0] + '?embed=1';
            }
            return '<img' + att1 + 'src="' + nsrc + '"' + att2 + '>';
        });
        $('md-view').innerHTML = html;
        renderMermaid($('md-view'));
        // 正文允许 H1：不再降级（标题栏显示文件名，正文 H1 与文件名可并存）
        // 文档标题 = 文件名（去扩展名），清除旧翻译标记；首页传 titleOverride（= HOME_TITLE）
        var docName = (titleOverride !== undefined && titleOverride !== null && titleOverride !== '')
            ? titleOverride
            : nodePath.split('/').pop().replace(/\.md$/i, '');
        $('doc-title').textContent = docName;
        $('doc-title').style.display = '';
        delete $('doc-title').dataset.orig;
        // 视图切换：内容就绪后一次性显示
        hideSpecialViews();  // 清掉可能残留的 PDF/画布/图谱（互斥）
        $('tag-view').style.display = 'none';
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        $('doc-wrap').style.display = 'flex';
        $('md-view').style.display = '';
        $('content').scrollTop = 0;
        // 文章页脚（Site 设置控制；支持 HTML——默认 "Created with BrainPress v3.0.0 © 2026"）
        if (window.ARTICLE_FOOTER && window.ARTICLE_FOOTER_HTML) {
            var foot = document.createElement('div');
            foot.className = 'md-footer';
            foot.innerHTML = window.ARTICLE_FOOTER_HTML;
            $('md-view').appendChild(foot);
        }
        renderToc();
        lgRender(state.path);   // 当前文章邻域图（Quartz local graph）
        addCodeCopy();
        // 代码高亮/行号按需：文章里有代码块才加载 hljs（首页与无代码文章零开销）；mermaid 不参与
        if (window.hljs) { try { applyHighlight(); } catch (e) {} }
        else if ($('md-view').querySelector('pre code')) {
            loadHighlightLib().then(applyHighlight).catch(function () {});
        }
        processObsidian();
        // 树可能先于文章就绪（retry 已在空视图上跑过）：渲染完成后补一次解析，双保险且幂等
        if (state.tree && state.tree.length) retryObsidian();
        // 跨笔记锚点定位（[[笔记#标题 / #^块ID]]）：文章渲染完成后滚动到位
        if (window._pendingAnchor) {
            var pa = window._pendingAnchor;
            window._pendingAnchor = null;
            setTimeout(function () { jumpToAnchor(pa); }, 80);
        }
    }
    // HTML 文件（在线工具/自定义页面）：直接渲染原始 HTML（不走 md 管线，保留脚本/样式）
    function showHtml(html) {
        // 从原始 HTML 中摘出 <script>（含内联与 src）——innerHTML 插入时脚本不会执行，
        // 须剥离后重建才能运行（样式 <style> 可随 innerHTML 生效，无需特殊处理）
        var m = html.match(/<script\b[^>]*>([\s\S]*?)<\/script>/gi) || [];
        html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        $('md-view').innerHTML = html;
        // 视图切换：内容就绪后一次性显示
        hideSpecialViews();
        $('tag-view').style.display = 'none';
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        $('doc-wrap').style.display = 'flex';
        $('md-view').style.display = '';
        $('content').scrollTop = 0;
        // 文档标题 = 文件名（去扩展名）
        var docName = state.path.split('/').pop().replace(/\.html$/i, '');
        $('doc-title').textContent = docName;
        $('doc-title').style.display = '';
        delete $('doc-title').dataset.orig;
        // 重建并执行摘出的脚本（内联脚本直接 eval；带 src 的异步加载）
        m.forEach(function (tagStr) {
            var srcM = tagStr.match(/<script\b[^>]*\bsrc=["']([^"']+)["']/i);
            if (srcM) {
                var s = document.createElement('script');
                s.src = srcM[1];
                document.head.appendChild(s);
            } else {
                var codeM = tagStr.match(/<script\b[^>]*>([\s\S]*?)<\/script>/i);
                if (codeM && codeM[1] && codeM[1].trim()) {
                    try { (0, eval)(codeM[1]); } catch (e) { if (window.console) console.error('tool script:', e); }
                }
            }
        });
    }

    // 面包屑目录跳转：展开侧栏/抽屉树并定位到指定目录（保留：目录树点击仍可用）
    function revealInTree(dirPath) {
        if (!dirPath) return;
        if (typeof setFrontDrawer === 'function') setFrontDrawer(true);  // 打开抽屉（含移动端）
        var target = null;
        var roots = [frontDrawerMd];
        var left = (typeof $ !== 'undefined') ? $('left-drawer-md') : null;
        if (left) roots.push(left);
        roots.forEach(function (root) {
            if (!root || target) return;
            var q = 'li[data-path="' + String(dirPath).replace(/"/g, '\\"') + '"]';
            target = root.querySelector(q);
        });
        if (!target) return;
        // 展开 target 及其所有祖先（去 collapsed）
        var n = target;
        while (n && n.nodeType === 1) {
            if (n.classList) n.classList.remove('collapsed');
            n = n.parentNode;
        }
        try { target.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
    }

    async function selectFile(node) {
        // RSS 伪路径可能带前导斜杠（来自树 href）：归一化后走通用 /api/file 渲染（与 IMA 同流程）
        if (/^\/rss:\/\//.test(node.path)) node.path = node.path.slice(1);
        state.path = node.path;
        // PDF 文件：走阅读器（pdf.js），不走 md 渲染管线
        if (/\.pdf$/i.test(node.path)) {
            openPdf(node.path);
            return;
        }
        // Canvas 白板：读原文 → 画布渲染（不走 md 管线）
        if (/\.canvas$/i.test(node.path)) {
            try {
                var cdata = await api('/api/file?path=' + encodeURIComponent(node.path));
                if (!cdata || !cdata.ok) { toast((cdata && cdata.error) || 'Failed to read file'); return; }
                window.CANVAS_PATH = node.path;
                window.CANVAS_JSON = cdata.content || '';
                renderCanvas();
            } catch (e) { toast(e.message); }
            return;
        }
        // HTML 文件（在线工具/自定义页面）：直接渲染原始 HTML（不走 md 管线，避免 DOMPurify 过滤脚本）
        if (/\.html$/i.test(node.path)) {
            try {
                var hdata = await api('/api/file?path=' + encodeURIComponent(node.path));
                if (!hdata || !hdata.ok) { toast((hdata && hdata.error) || 'Failed to read file'); return; }
                showHtml(hdata.content);
            } catch (e) { toast(e.message); }
            return;
        }
        try {
            // 已渲染缓存命中：跳过网络与 md 重渲染，直接回显（来回切换秒开）
            var html = _articleCache[node.path];
            if (html === undefined) {
                // 先请求内容并渲染好，再一次性切换视图（避免空白闪烁）
                var data = await api('/api/file?path=' + encodeURIComponent(node.path));
                if (!data || !data.ok) {
                    toast((data && data.error) || 'Failed to read file');
                    return;
                }
                // Excalidraw 判定：扩展名或内容特征（真实 frontmatter 标记 + 真实代码围栏——
                // 宽松的子串匹配会把"介绍 excalidraw 的文档"误判成绘画文件）
                var raw = data.content || '';
                if (/\.excalidraw\.md$/i.test(node.path) || (raw.indexOf('excalidraw-plugin:') !== -1 && /^```compressed-json\s*$/m.test(raw))) {
                    window.EXCALIDRAW_PATH = node.path;
                    window.EXCALIDRAW_RAW = raw;
                    renderExcalidraw();
                    return;
                }
                html = mdToHtml(data.content);
                cacheArticle(node.path, html);
            }
            showArticle(html, node.path);
            // 更新地址栏：SPA 点击已 pushState 路径 URL 时不重复设 hash（避免 /path#path 冗余）
            try {
                if (!/\.md$/i.test(location.pathname)) {
                    var docId = null;
                    for (var k in (window._docMap || {})) {
                        if (window._docMap[k] === node.path) { docId = k; break; }
                    }
                    location.hash = docId || encodeURI(node.path);
                }
            } catch (e) {}
        } catch (e) {
            toast(e.message);
        }
    }

    // 展开树节点（移除 collapsed class）
    function expandTreeNode(path) {
        var roots = [frontDrawerMd, $('left-drawer-md')].filter(Boolean);
        roots.forEach(function (root) {
            var li = root.querySelector('li[data-path="' + path.replace(/"/g, '\\"') + '"]');
            if (li) {
                li.classList.remove('collapsed');
                // 递归展开所有祖先
                var p = li.parentElement;
                while (p && p !== root) {
                    if (p.tagName === 'LI') p.classList.remove('collapsed');
                    p = p.parentElement;
                }
            }
        });
    }

    // 给代码块加复制按钮
    /* ===== Popover 链接预览（Quartz 同款）===== */
    // 悬停内部链接弹出目标笔记预览卡片；pointer-events:none 不挡链接点击；用事件委托覆盖动态生成的双链
    (function () {
        var card = document.createElement('div');
        card.className = 'note-popover';
        document.body.appendChild(card);
        var showTimer = null, tipTimer = null, cur = null;
        function loothed(ev) {
            var t = ev.target && ev.target.closest ? ev.target.closest('a.ob-link[data-link]') : null;
            if (!t) { cleanup(); return; }
            var link = t.dataset.link;
            if (link && cur === t) return;
            cur = t;
            // 先显示占位，再异步加载
            card.innerHTML = '<div class="np-title">' + esc(link.replace(/\.md$/i, '')) + '</div><div class="np-loading">Loading…</div>';
            positionCard(t);
            card.classList.add('show');
            var path = findNote(link);
            if (!path) { card.querySelector('.np-loading').className = 'np-missing'; card.querySelector('.np-loading').textContent = 'Missing note'; return; }
            api('/api/file?path=' + encodeURIComponent(path)).then(function (data) {
                if (cur !== t) return;
                var c = data.content || '';
                var title = c.match(/^#\s+(.+)$/m);
                var body = extractPlain(c);
                card.innerHTML = '<div class="np-title">' + esc((title ? title[1].trim() : path.split('/').pop().replace(/\.md$/i, ''))) + '</div>' +
                    '<div class="np-body">' + esc(body) + '</div>';
                positionCard(t);
            }).catch(function () {
                if (cur === t) { var l = card.querySelector('.np-loading'); if (l) { l.className = 'np-missing'; l.textContent = 'Unavailable'; } }
            });
        }
        function positionCard(t) {
            card.style.visibility = 'hidden';
            var r = t.getBoundingClientRect();
            var cw = card.offsetWidth;
            var left = r.right + 10;
            if (left + cw > window.innerWidth - 8) left = r.left - cw - 10;
            if (left < 8) left = 8;
            var top = r.top - 8;
            card.style.left = left + 'px';
            card.style.top = top + 'px';
            card.style.visibility = '';
        }
        function extractPlain(c) {
            var s = c.replace(/^---[\s\S]*?---\r?\n/, '');
            s = s.replace(/```[\s\S]*?```/g, ' ');
            s = s.replace(/!\[\[[^\]]*\]\]|\[\[[^\]]*\]\]|#[^\s#]+|^\s*#+[^\n]*|[>_*`~|]/gm, ' ');
            return s.replace(/\s+/g, ' ').trim().slice(0, 300);
        }
        function cleanup() { cur = null; card.classList.remove('show'); }
        document.addEventListener('pointerover', function (ev) {
            var t = ev.target && ev.target.closest ? ev.target.closest('a.ob-link[data-link]') : null;
            if (!t) return;
            clearTimeout(tipTimer);
            if (cur !== t) tipTimer = setTimeout(function () { loothed(ev); }, 220);
        });
        document.addEventListener('pointerout', function (ev) {
            var t = ev.target && ev.target.closest ? ev.target.closest('a.ob-link[data-link]') : null;
            if (!t) return;
            clearTimeout(tipTimer);
            var to = (ev.relatedTarget || null);
            if (to && to.closest && to.closest('.note-popover')) return;
            tipTimer = setTimeout(cleanup, 200);
        });
    })();

    /* ===== Obsidian 兼容：双链 / 嵌入 / 标签 ===== */
    function findNote(name) {
        var map = window._docMap || {};
        var target = name.replace(/\.md$/i, '');
        // 1. ID 直接匹配
        if (map[target]) return map[target];
        // 2. 按显示名匹配（去 ID 前缀、去 .md）
        for (var k in map) {
            var p = map[k];
            var base = p.split('/').pop().replace(/^\d+-/, '').replace(/\.md$/i, '');
            if (base === target) return p;
        }
        return null;
    }
    // 拆 [[目标#子定位|别名]]：子定位 = 标题 或 ^块ID；别名可能写在 # 之后（[[a#b|c]]）
    function splitRef(ref) {
        var alias = null, pipe = ref.indexOf('|');
        if (pipe > -1) { alias = ref.slice(pipe + 1).trim(); ref = ref.slice(0, pipe); }
        ref = ref.trim();
        var hash = ref.indexOf('#');
        if (hash > -1) return { target: ref.slice(0, hash).trim(), sub: ref.slice(hash + 1).trim(), alias: alias };
        return { target: ref, sub: '', alias: alias };
    }
    // 树内严格按文件名查找（不猜路径）：存在返回路径，不存在返回 null（链接/嵌入判定"未解析"用）
    function findAsset(name) {
        var found = null;
        (function walk(nodes) {
            nodes.forEach(function (n) {
                if (found) return;
                if (n.type === 'file' && n.name === name) found = n.path;
                else if (n.children) walk(n.children);
            });
        })(state.tree);
        return found;
    }
    // 资产路径解析（嵌入图片/音视频/画布/PDF）：树内精确文件名 → 当前笔记同目录兜底 → vault 根
    function resolveAsset(name) {
        var found = findAsset(name);
        if (found) return found;
        if (state.path && state.path.indexOf('/') > -1) {
            return state.path.slice(0, state.path.lastIndexOf('/') + 1) + name;
        }
        return name;
    }
    // 滚动容器内精确定位：用视口相对坐标换算（offsetTop 受定位祖先影响不可靠）
    function scrollMdTo(el, off) {
        var c = $('content');
        var top = el.getBoundingClientRect().top - c.getBoundingClientRect().top + c.scrollTop - (off || 90);
        c.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    }
    // 锚点定位：#^块ID → data-block-id 元素；#标题 → 标题文本匹配；命中则平滑滚动
    function jumpToAnchor(sub) {
        var view = $('md-view');
        if (!view) return false;
        var el = null;
        if (sub.charAt(0) === '^') {
            // U+2011（不换行连字符）视作普通 '-'：Obsidian 输入法/粘贴可能产生
            var bid = sub.slice(1).replace(/\u2011/g, '-').replace(/[^\w-]/g, '');
            if (bid) el = view.querySelector('[data-block-id="' + bid + '"]');
        } else {
            var want = sub.toLowerCase();
            view.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(function (h) {
                if (!el && h.textContent.trim().toLowerCase() === want) el = h;
            });
        }
        if (!el) return false;
        scrollMdTo(el);
        return true;
    }
    // 画布嵌入：拉取 .canvas JSON，复用 renderCanvasScene 渲染为内嵌 SVG 场景
    function embedCanvas(el, name) {
        api('/api/file?path=' + encodeURIComponent(resolveAsset(name))).then(function (data) {
            var scene;
            try { scene = JSON.parse(data.content || '{}'); }
            catch (e) { throw new Error('invalid'); }
            el.innerHTML = '';
            var box = document.createElement('div');
            box.className = 'ob-embed-canvas';
            el.appendChild(box);
            renderCanvasScene(scene, box);
            el.classList.add('loaded');
        }).catch(function () {
            embedFail(el, name);
        });
    }
    // 嵌入失败兜底：按 Obsidian 官方样式显示未解析链接文本；带 data-name 供 retryObsidian 二次修复。
    // 树若已就绪（失败回调晚于一次性重试），立刻排一次重试闭环；树未就绪则等 loadTree 后统一重试
    function embedFail(el, name) {
        var miss = document.createElement('span');
        miss.className = 'ob-embed ob-embed-missing';
        miss.dataset.name = name;
        miss.textContent = '![[' + name + ']]';
        miss.title = 'Embed target not found';
        el.replaceWith(miss);
        // 重试只用于"树未就绪、路径猜错"的场景。树已加载且 findAsset 确认资产不存在 → 永久缺失，
        // 停止重试（否则视频/图片 onerror → embedFail → retry → 重新挂载 → 又 404，无限 抖动）。
        if (state.tree && state.tree.length) {
            if (findAsset(name)) setTimeout(retryObsidian, 0);
        } else {
            setTimeout(retryObsidian, 0);
        }
    }
    // 嵌入统一挂载：按扩展名分派 图片/视频/音频/画布/笔记（含块引用提取）。
    // 树未就绪时 resolveAsset 只能同目录兜底猜路径 → 失败落成 missing[data-name]，loadTree 后统一重试
    function mountEmbed(el, name, sub) {
        if (/\.(png|jpe?g|gif|webp|svg|bmp|avif)$/i.test(name)) {
            var img = document.createElement('img');
            img.className = 'ob-embed-img';
            img.src = '/vault/' + encodeURI(resolveAsset(name)) + '?embed=1';
            img.alt = name;
            // 不用 lazy：离屏图片不发起请求，onerror 永不触发，缺失兜底与重试机制会失效
            img.onerror = function () { embedFail(img, name); };  // 树未就绪猜错路径 → 落 missing 等重试
            el.innerHTML = '';
            el.classList.add('loaded');
            el.appendChild(img);
        } else if (/\.(mp4|webm|ogv|mov|m4v)$/i.test(name)) {
            var vid = document.createElement('video');
            vid.className = 'ob-embed-media';
            vid.controls = true;
            vid.preload = 'metadata';
            vid.src = '/vault/' + encodeURI(resolveAsset(name)) + '?embed=1';
            vid.onerror = function () { embedFail(vid, name); };
            el.innerHTML = '';
            el.classList.add('loaded');
            el.appendChild(vid);
        } else if (/\.(mp3|wav|ogg|m4a|flac|aac|opus)$/i.test(name)) {
            var aud = document.createElement('audio');
            aud.className = 'ob-embed-media';
            aud.controls = true;
            aud.preload = 'metadata';
            aud.src = '/vault/' + encodeURI(resolveAsset(name)) + '?embed=1';
            aud.onerror = function () { embedFail(aud, name); };
            el.innerHTML = '';
            el.classList.add('loaded');
            el.appendChild(aud);
        } else if (/\.canvas$/i.test(name)) {
            embedCanvas(el, name);
        } else if (/\.pdf$/i.test(name)) {
            mountPdfEmbed(el, name, sub);
        } else {
            loadEmbed(el, name, sub);
        }
    }
    /* ===== Obsidian 式 PDF 内嵌：复用共享阅读器组件 buildPdfPane =====
       工具栏：大纲 | ‹ 页码/N › | 缩放下拉 −/+ | 全屏；正文懒渲染（IntersectionObserver）；
       深色模式保持白纸不反色（Obsidian 同款）；#page=N 打开即跳转 */
    function mountPdfEmbed(el, name, sub) {
        loadPdfJs(function () {
            pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/pdfjs/pdf.worker.min.js';
            pdfjsLib.getDocument('/vault/' + encodeURI(resolveAsset(name)) + '?embed=1').promise.then(function (doc) {
                el.innerHTML = '';
                el.classList.add('loaded');
                buildPdfPane(el, doc, { sub: sub });
            }).catch(function () {
                embedFail(el, name);  // 树未就绪猜错路径 → 落 missing 等重试
            });
        });
    }
    function processObsidian() {
        var root = $('md-view');
        if (!root) return;
        renderMath(root);      // 先渲染公式，避免 $ 内文本进入下方扫描
        applyBlockIds(root);   // 先摘块 ID，避免 ^id 文本进入下方双链/标签扫描
        transformCallouts(root);  // Obsidian Callout：> [!type] 转彩色卡片（须在文本扫描前替换节点）
        // 遍历文本节点，处理 [[双链]]、![[嵌入]]、#标签；跳过代码块/行内代码与已渲染公式（内部 # 等是内容不是语法）
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        while (walker.nextNode()) {
            var host = walker.currentNode.parentElement;
            if (host && host.closest && host.closest('pre, code, .ob-math')) continue;
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function (textNode) {
            var text = textNode.nodeValue;
            if (!text) return;
            var out = [];
            var last = 0;
            // 标签允许嵌套层级（Obsidian：#父/子/孙）；嵌入/双链分支才持有 m[2]，标签走 m[4]，
            // splitRef 不能提到分支外——标签命中时 m[2] 为 undefined 会抛错中断整个遍历
            var re = /(!?)\[\[([^\[\]]+)\]\]|(^|\s)#([\u4e00-\u9fa5\w-]+(?:\/[\u4e00-\u9fa5\w-]+)*)/g;
            var m;
            while ((m = re.exec(text)) !== null) {
                if (m.index > last) out.push(text.slice(last, m.index));
                if (m[1] === '!') {
                    // 嵌入 ![[名字]] / ![[名字#^块ID]]
                    var er = splitRef(m[2]);
                    out.push({ embed: er.target, sub: er.sub });
                } else if (m[2]) {
                    // 双链 [[名字]] / [[名字|别名]] / [[#标题|别名]] / [[名字#^块ID]]
                    var lr = splitRef(m[2]);
                    out.push({ link: lr.target, sub: lr.sub, alias: lr.alias });
                } else if (m[4]) {
                    // 标签 #标签 / #嵌套/标签
                    out.push({ tag: m[4], leading: m[3] });
                }
                last = m.index + m[0].length;
            }
            if (!out.length) return;
            if (last < text.length) out.push(text.slice(last));
            // 重建节点
            var frag = document.createDocumentFragment();
            out.forEach(function (item) {
                if (typeof item === 'string') {
                    frag.appendChild(document.createTextNode(item));
                } else if (item.embed !== undefined) {
                    // 嵌入分支：自引用块就地克隆；其余统一交给 mountEmbed（可被 retryObsidian 重试）
                    var embedName = item.embed;
                    if (!embedName && /^\^/.test(item.sub)) {
                        // 自引用块嵌入 ![[#^id]]：克隆本文已挂锚点的块
                        var bid0 = item.sub.slice(1).replace(/\u2011/g, '-').replace(/[^\w-]/g, '');
                        var blk0 = bid0 ? root.querySelector('[data-block-id="' + bid0 + '"]') : null;
                        var em0 = document.createElement('span');
                        em0.className = 'ob-embed';
                        if (blk0) {
                            var inn0 = document.createElement('div');
                            inn0.className = 'ob-embed-inner';
                            inn0.appendChild(blk0.cloneNode(true));
                            em0.appendChild(inn0);
                            em0.classList.add('loaded');
                        } else {
                            // 未解析嵌入：按 Obsidian 官方样式显示原文链接文本
                            em0.textContent = '![[' + item.sub + ']]';
                            em0.title = 'Unresolved embed';
                            em0.classList.add('ob-embed-missing');
                        }
                        frag.appendChild(em0);
                    } else {
                        var span = document.createElement('span');
                        span.className = 'ob-embed';
                        span.dataset.name = embedName;
                        if (item.sub) span.dataset.sub = item.sub;
                        span.textContent = 'Loading embed: ' + embedName + '...';
                        frag.appendChild(span);
                        mountEmbed(span, embedName, item.sub);
                    }
                } else if (item.link !== undefined) {
                    var a = document.createElement('a');
                    a.className = 'ob-link';
                    if (!item.link && item.sub) {
                        // 页内定位 [[#标题]] / [[#^块ID|别名]]
                        a.textContent = item.alias || item.sub.replace(/^\^/, '');
                        a.href = '#';
                        a.addEventListener('click', function (e) {
                            e.preventDefault();
                            if (!jumpToAnchor(item.sub)) toast('Anchor not found: ' + item.sub);
                        });
                        frag.appendChild(a);
                    } else {
                        var target = findNote(item.link);
                        a.textContent = item.alias || item.link;
                        a.dataset.link = item.link;
                        if (item.sub) a.dataset.sub = item.sub;
                        if (!target && /\.pdf$/i.test(item.link)) {
                            // PDF 链接：树内找到 → 打开阅读器（#page=N 直达页）；找不到按未解析处理
                            var pdfPath = findAsset(item.link);
                            if (pdfPath) {
                                var pg = /^page=(\d+)$/i.exec((item.sub || '').trim());
                                a.title = 'Open PDF: ' + item.link;
                                a.href = '#';
                                a.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    openPdf(pdfPath, pg ? parseInt(pg[1], 10) : 1);
                                });
                                frag.appendChild(a);
                                return;
                            }
                        }
                        if (target) {
                            // 用完整路径做 hash（encodeURI 保留斜杠），无 ID 文章也能直达
                            a.href = '#' + encodeURI(target);
                            a.title = target;
                            a.dataset.path = target;
                            if (item.sub) {
                                // 跨笔记定位 [[笔记#标题/#^块]]：目标文章渲染完成后滚动到位
                                a.addEventListener('click', function () { window._pendingAnchor = item.sub; });
                            }
                        } else {
                            a.classList.add('ob-link-missing');
                            a.title = 'Note not found: ' + item.link;
                        }
                        frag.appendChild(a);
                    }
} else if (item.tag) {
                    var t = document.createElement('a');
                    t.className = 'ob-tag';
                    t.textContent = '#' + item.tag;
                    t.dataset.tag = item.tag;
                    t.href = '#/tag/' + item.tag;
                    t.addEventListener('click', function (e) {
                        e.preventDefault();
                        window.location.href = '#/tag/' + encodeURIComponent(item.tag);
                    });
                    frag.appendChild(document.createTextNode(item.leading || ''));
                    frag.appendChild(t);
                }
            });
            textNode.parentNode.replaceChild(frag, textNode);
        });
        // 脚注引用点击：滚动到对应定义（不能用 href 锚点——hash 变化会触发文章路由 handleHash）
        root.querySelectorAll('sup.fn-ref[data-fnid]').forEach(function (s) {
            s.addEventListener('click', function () {
                var id = s.dataset.fnid;
                var def = null;
                root.querySelectorAll('.fn-def[data-fnid]').forEach(function (d) {
                    if (!def && d.dataset.fnid === id) def = d;
                });
                if (def) scrollMdTo(def);
            });
        });
        // backlinks 功能已移除
    }
    // 获取笔记的 ID
    function getDocId(path) {
        for (var k in (window._docMap || {})) {
            if (window._docMap[k] === path) return k;
        }
        return '';
    }
    // 加载嵌入内容（name 支持块引用 sub=^id：只保留被引用的块）
    function loadEmbed(el, name, sub) {
        var path = findNote(name);
        if (!path) {
            el.textContent = '![[' + name + (sub || '') + ']]';
            el.title = 'Unresolved embed';
            el.classList.add('ob-embed-missing');
            return;
        }
        api('/api/file?path=' + encodeURIComponent(path)).then(function (data) {
            var html = mdToHtml(data.content);
            // 去掉第一个 h1
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var h1 = tmp.querySelector('h1');
            if (h1) h1.remove();
            applyBlockIds(tmp);
            // 块引用嵌入 ![[笔记#^id]]：整篇渲染后只保留被引用的块
            if (sub && sub.charAt(0) === '^') {
                var bid = sub.slice(1).replace(/[^\w-]/g, '');
                var blk = bid ? tmp.querySelector('[data-block-id="' + bid + '"]') : null;
                tmp.innerHTML = '';
                if (blk) tmp.appendChild(blk);
                else {
                    el.textContent = '![[' + name + sub + ']]';
                    el.title = 'Block not found';
                    el.classList.add('ob-embed-missing');
                    return;
                }
            }
            if (window.hljs) {
                try {
                    tmp.querySelectorAll('pre code').forEach(function (el) {
                        hljs.highlightElement(el);
                    });
                } catch (e) {}
            }
            el.innerHTML = '';
            var inner = document.createElement('div');
            inner.className = 'ob-embed-inner';
            inner.innerHTML = tmp.innerHTML;
            el.appendChild(inner);
            el.classList.add('loaded');
        }).catch(function () {
            el.textContent = 'Embed failed: ' + name;
            el.classList.add('ob-embed-missing');
        });
    }
    // backlinks 反向链接功能已移除（不再渲染页面底部双链列表）

    /* ---------- 搜索功能（见下方） ---------- */
    // （编辑/余额/上传功能已随二层顶部栏移除）

    /* ---------- 滚动时隐藏/显示第一层导航栏 ---------- */
    // 方向判断：向下滚动隐藏、向上滚动显示
    // 切换后重置 lastY（padding 变化会改变 scrollHeight/scrollTop，必须校准基准）
    // PIN_NAVBAR：后台偏好设置开启时导航栏始终可见，跳过滚动隐藏逻辑
    var navWrap = $('nav-wrap');
    var mainEl = $('main');
    var contentEl = $('content');
    var lastY = 0;
    var topHidden = false;
    function setTopHidden(hidden) {
        if (hidden === topHidden) return;
        topHidden = hidden;
        navWrap.classList.toggle('hidden', hidden);
        mainEl.classList.toggle('hide-top', hidden);
        lastY = contentEl.scrollTop;   // 校准基准，防止突变干扰方向判断
    }
    contentEl.addEventListener('scroll', function () {
        if (window.PIN_NAVBAR) { lastY = contentEl.scrollTop; return; }
        var y = contentEl.scrollTop;
        var delta = y - lastY;
        var maxY = contentEl.scrollHeight - contentEl.clientHeight;
        // 顶部兜底：scrollTop 接近 0 时强制显示一层（猛拽回顶部也能拉回来）
        if (y < 10) {
            setTopHidden(false);
            lastY = y;
            return;
        }
        // 死区：滚动距离小于 6px 不判断（过滤微抖）
        if (Math.abs(delta) < 6) { lastY = y; return; }
        if (delta > 0) {
            // 向下滚：隐藏（贴底 60px 内不隐藏，避免贴底抖动）
            if (maxY - y < 60) { lastY = y; return; }
            setTopHidden(true);
        } else {
            // 向上滚：显示（不受底部限制）
            setTopHidden(false);
        }
        lastY = y;
    });

    /* ---------- 顶部导航 ---------- */
    // logo 点击：回到主页。非首页路径（文章/图谱）或带 hash 时整页跳转 '/'（最可靠的“刷新到主页”）；已在主页则滚回顶部
    $('vp-logo').addEventListener('click', function () {
        if (location.pathname !== '/' || location.hash) {
            location.href = '/';
            return;
        }
        $('doc-wrap').style.display = 'none';
        renderHome();
        $('content').scrollTop = 0;
    });
    // 主题切换（日间/夜间，localStorage 记忆）
    function applyTheme(dark) {
        // PDF 阅读器保持白纸不反色（Obsidian 同款），主题切换走通用逻辑
        document.documentElement.classList.toggle('dark', dark);
        try { localStorage.setItem('vp-theme', dark ? 'dark' : 'light'); document.cookie = 'vp-theme=' + (dark ? 'dark' : 'light') + '; path=/'; } catch (e) {}
    }
    try {
        var savedTheme = localStorage.getItem('vp-theme');
        if (savedTheme === 'dark') applyTheme(true);
    } catch (e) {}

/* ===== Graph View（Quartz graph.inline.ts 全行为忠诚移植：局部图 + 全屏全局图共用 renderGraph） ===== */
    // ---- 共享数据与工具 ----
    var lgWrap = $('graph-local-wrap');
    var lgBox = $('graph-local-box');
    var lgSvg = $('graph-local-svg');          // 局部图容器（渲染器在内部创建 svg）
    var lgData = null;                          // /api/graph 缓存 {ok, nodes, links}
    var QZG = 'http://www.w3.org/2000/svg';
    // Quartz「graph-visited」localStorage 访问追踪 → 当前/已访问/未访问三态着色
    var graphVisited = new Set();
    function getVisited() {
        try { return new Set(JSON.parse(localStorage.getItem('graph-visited') || '[]')); } catch (e) { return new Set(); }
    }
    function refreshVisited() { graphVisited = getVisited(); }
    function addToVisited(id) {
        try { graphVisited.add(id); localStorage.setItem('graph-visited', JSON.stringify(Array.from(graphVisited))); } catch (e) {}
    }
    function normPath(p) { return String(p || '').replace(/^\/+/, ''); }

    // 右栏是否可见：BrainPress 在 ≥1400px 才显示 #right-sidebar（769–1399 与 ≤768 均 display:none）。
    // 局部图必须始终可见——右栏可见时留在栏内（TOC 上方），否则搬进内容流（正文下方）兜底。
    function lgIsRail() { return window.matchMedia && window.matchMedia('(min-width:1400px)').matches; }

    // 容器入流：右栏可见 → 放回右栏 TOC 上方；否则（右栏隐藏）→ 放进正文 md-view 内、页脚之前
    //（紧跟文章正文与反向链接、位于 Created-with 页脚之上 = 「文章正文与页底之间」）
    function lgSettleContainer() {
        if (!lgWrap) return;
        if (lgIsRail()) {
            var rail = $('right-sidebar');
            var toc = $('toc-panel');
            if (rail && lgWrap.parentNode !== rail) rail.insertBefore(lgWrap, toc || null);
            lgWrap.classList.remove('lg-in-flow');
        } else {
            var mdv = $('md-view');
            if (mdv && lgWrap.parentNode !== mdv) {
                var foot = mdv.querySelector('.md-footer');
                mdv.insertBefore(lgWrap, foot || null);
            }
            lgWrap.classList.add('lg-in-flow');
        }
    }

    function lgLoadData() {
        if (lgData) return Promise.resolve(lgData);
        return fetch('/api/graph').then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (d) {
            if (d && d.ok) lgData = d;
            return lgData;
        });
    }

    // ---- 配置（Quartz D3Config：缺省即 Quartz 默认值，后台 GraphView 配置可覆盖） ----
    function qzCfg() {
        var base = { drag: true, zoom: true, depth: 1, scale: 1.1, repelForce: 0.5, centerForce: 0.3, linkDistance: 30, fontSize: 0.6, opacityScale: 1 };
        var g = window.GRAPH_CONFIG || {};
        return {
            drag: true, zoom: true,
            depth: g.depth != null ? g.depth : base.depth,
            scale: base.scale,
            repelForce: g.repel != null ? g.repel : base.repelForce,
            centerForce: g.center != null ? g.center : base.centerForce,
            linkDistance: g.linkDistance != null ? g.linkDistance : base.linkDistance,
            fontSize: g.fontSize != null ? g.fontSize : base.fontSize,
            opacityScale: g.opacityScale != null ? g.opacityScale : base.opacityScale
        };
    }

    // ---- 渲染器：graph.inline.ts「renderGraph」的忠实移植（零依赖） ----
    // container：绘制容器；cfg：qzCfg(kind)；currentPath：当前文章路径
    function qzRenderGraph(container, cfg, currentPath) {
        container.innerHTML = '';
        if (!lgData || !lgData.nodes || !lgData.nodes.length) return;

        // 节点 id → 记录；全量边（id 对）；完全度数（决定节点半径，Quartz nodeRadius）
        var byId = {};
        lgData.nodes.forEach(function (n) { byId[n.id] = n; });
        var degree = {};
        lgData.nodes.forEach(function (n) { degree[n.id] = 0; });
        var fullLinks = [];
        (lgData.links || []).forEach(function (l) {
            if (!byId[l.source] || !byId[l.target]) return;
            fullLinks.push({ source: l.source, target: l.target });
            degree[l.source]++; degree[l.target]++;
        });

        // ---- 邻域 BFS（Quartz 同款：双向遍历，depth=0 仅自身；depth<0 → 全图） ----
        var neighbourhood = new Set();
        var start = null;
        if (cfg.depth >= 0) {
            for (var si = 0; si < lgData.nodes.length; si++) {
                if (normPath(lgData.nodes[si].path) === normPath(currentPath)) { start = lgData.nodes[si].id; break; }
            }
            if (start == null) return;
            var wl = [start, '__SENTINEL'];
            var rem = cfg.depth;
            while (rem >= 0 && wl.length) {
                var cur = wl.shift();
                if (cur === '__SENTINEL') { rem--; wl.push('__SENTINEL'); }
                else {
                    neighbourhood.add(cur);
                    fullLinks.forEach(function (l) {
                        if (l.source === cur) wl.push(l.target);
                        else if (l.target === cur) wl.push(l.source);
                    });
                }
            }
        } else {
            lgData.nodes.forEach(function (n) { neighbourhood.add(n.id); });
            // 全局图也定位当前文章节点（作中心锚），便于把它软牵引到球心
            for (var gi = 0; gi < lgData.nodes.length; gi++) {
                if (normPath(lgData.nodes[gi].path) === normPath(currentPath)) { start = lgData.nodes[gi].id; break; }
            }
        }

        // 图数据：边直接引用节点对象（= d3 forceLink 初始化后的形态）
        var nodeById = {};
        var nodes = Array.from(neighbourhood).map(function (id) {
            var rec = byId[id] || {};
            var d = { id: id, path: rec.path || id, title: rec.name || rec.title || id, x: null, y: null, vx: 0, vy: 0, fx: null, fy: null };
            nodeById[id] = d;
            return d;
        });
        nodes.forEach(function (n, i) { n.index = i; });
        var links = fullLinks
            .filter(function (l) { return neighbourhood.has(l.source) && neighbourhood.has(l.target); })
            .map(function (l) { return { source: nodeById[l.source], target: nodeById[l.target] }; });

        // 初始位：d3.forceSimulation 默认 phyllotaxis（initialRadius=10、goldenAngle）——与 Quartz 同一开场动画
        var goldenAngle = Math.PI * (3 - Math.sqrt(5));
        nodes.forEach(function (d, i) {
            var rad = 10 * Math.sqrt(0.5 + i);
            var ang = i * goldenAngle;
            d.x = Math.cos(ang) * rad;
            d.y = Math.sin(ang) * rad;
        });

        // 中心锚点：局部图中把当前文章节点软牵引到原点（邻居围绕它辐射 → Quartz「一个中心发散」观感）。
        // 全局图同样把当前文章节点牵引到中心，让当前内容成为球心、其余节点绕它铺开——与局部图一致的中心吸附观感。
        // 用 forceX/forceY 的 strength 访问器实现「软弹簧」而非 fx/fy 硬钉——
        // 硬钉会与邻居对称力共同把两个邻居钉到一条过原点的直线（等边三角退化成直线，Alpha 收敛即冻结）。
        // 软牵引与其它力平衡，三角/多边形能找正解，中心感仍在。
        var centerAnchor = null;
        if (start != null && nodeById[start]) {
            centerAnchor = nodeById[start];
            centerAnchor._isCenter = true;
        }

        var W = container.clientWidth || 300;
        var H = Math.max(container.clientHeight || 250, 250);

        // ---- svg：viewBox 原点在盒子中心，scale 控制整体显示大小（Quartz 同款） ----
        var svg = document.createElementNS(QZG, 'svg');
        svg.setAttribute('width', W);
        svg.setAttribute('height', H);
        svg.setAttribute('viewBox', [-W / 2 / cfg.scale, -H / 2 / cfg.scale, W / cfg.scale, H / cfg.scale].join(' '));
        container.appendChild(svg);

        var hitEls = [];   // 命中圆（与视觉圆同步移动；承接事件）
        var lineEls = [], nodeGs = [], circleEls = [], labelEls = [];
        var currentNorm = normPath(currentPath);
        // hover/高亮性能：预建「节点 → 直连边索引」映射，避免每次悬停 O(全部边) 遍历
        var nodeLinks = {};
        (links || []).forEach(function (l, li) {
            (nodeLinks[l.source.id] = nodeLinks[l.source.id] || []).push(li);
            (nodeLinks[l.target.id] = nodeLinks[l.target.id] || []).push(li);
        });

        function colorOf(d) {
            if (currentNorm && normPath(d.path) === currentNorm) return 'var(--graph-primary)';   // = Quartz var(--secondary)
            if (graphVisited.has(normPath(d.path))) return 'var(--graph-visited)';               // = Quartz var(--tertiary)
            return 'var(--graph-node)';                                                          // = Quartz var(--gray)
        }
        var curNeighbours = new Set();
        if (start != null) {
            fullLinks.forEach(function (l) {
                if (l.source === start) curNeighbours.add(l.target);
                if (l.target === start) curNeighbours.add(l.source);
            });
        }

        // ---- 拖拽（d3.drag：fx/fy 钉住 + alphaTarget(1) 加热；对 node 与 label 均可拖动） ----
        var dragActive = false;
        function qzDragStart(node, ev) {
            ev.preventDefault(); ev.stopPropagation();
            userInteract = true;
            var rect = svg.getBoundingClientRect();
            function toLocal(e2) {
                // pointer → svg viewBox → zoomed 坐标（account for zoom transform）
                var svgX = ((e2.clientX - rect.left) - W / 2) / cfg.scale;
                var svgY = ((e2.clientY - rect.top) - H / 2) / cfg.scale;
                return { x: (svgX - zoomT.x) / zoomT.k, y: (svgY - zoomT.y) / zoomT.k };
            }
            // 掌上拖拽稳如桌面：setPointerCapture 让 pointermove 全程归属本元素（手指滑出圆外也不丢）
            // + lostpointercapture/pointercancel（浏览器抢走手势时）必须终结拖拽，否则 fx/fy 永久钉住、alphaTarget 不回零
            var ptr = ev.pointerId;
            var capEl = ev.currentTarget && ev.currentTarget.setPointerCapture ? ev.currentTarget : null;
            if (capEl) { try { capEl.setPointerCapture(ptr); } catch (e) {} }
            node.fx = node.x; node.fy = node.y;
            node._dragged = false;
            if (!dragActive && sim) { sim.alphaTarget(1).restart(); }   // 原版 Quartz 手感：拖拽注入高热量，邻域富有弹性跟随
            var sx0 = ev.clientX, sy0 = ev.clientY;
            dragActive = true;
            function mv(e2) {
                if (!node._dragged && (Math.abs(e2.clientX - sx0) + Math.abs(e2.clientY - sy0) > 5)) node._dragged = true;
                if (!node._dragged) return;   // 位移未超阈值：视为静止（未拖拽，锁定点击）
                var p = toLocal(e2);
                // 即时钉到手指下：fx/fy 只会在下一个 rAF tick 由模拟落实到 x/y，手机掉帧时节点会滞后一拍、
                // 看起来连线"贴"在节点上而非焊死。这里同步写 x/y 并立刻刷 DOM，节点圆心零延迟跟随手指，连线同帧锁死。
                node.fx = p.x; node.fy = p.y;
                node.x = p.x; node.y = p.y;
                updateDOM();
            }
            function up() {
                dragActive = false;
                node._dragged = false;
                node.fx = null; node.fy = null;
                if (sim) { sim.alphaTarget(0).restart(); }   // 松手后自然回弹收敛（弹性归位）
                if (capEl) { try { capEl.releasePointerCapture(ptr); } catch (e) {} }
                window.removeEventListener('pointermove', mv);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                window.removeEventListener('lostpointercapture', up);
            }
            window.addEventListener('pointermove', mv);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
            window.addEventListener('lostpointercapture', up);
        }

        // ---- 边（先画边，节点在其上，避免连线盖住节点；line 不响应事件，点击可穿透到节点） ----
        links.forEach(function (l) {
            var ln = document.createElementNS(QZG, 'line');
            ln.setAttribute('class', 'graph-link');
            ln.setAttribute('stroke', 'var(--graph-line)');
            ln.setAttribute('stroke-width', 1);
            ln.style.pointerEvents = 'none';
            svg.appendChild(ln);
            lineEls.push(ln);
        });

        // ---- 节点 + 文本 ----
        nodes.forEach(function (d) {
            var r = 2 + Math.sqrt(degree[d.id] || 0);
            var g = document.createElementNS(QZG, 'g');
            g.setAttribute('class', 'graph-node');
            var hit = document.createElementNS(QZG, 'circle');   // 透明命中区：r 更大，方便鼠标/手指选中
            hit.setAttribute('class', 'node-hit');
            hit.setAttribute('r', Math.max(r + 6, 16));
            hit.setAttribute('fill', 'transparent');
            hit.style.cursor = 'pointer';
            g.appendChild(hit);
            hitEls.push(hit);
            d._hit = hit;
            var c = document.createElementNS(QZG, 'circle');
            c.setAttribute('class', 'node');
            c.setAttribute('id', d.id);
            c.setAttribute('r', r);
            c.setAttribute('fill', colorOf(d));
            c.style.cursor = 'pointer';
            c.style.pointerEvents = 'none';   // 视觉圆不拦事件，由 hit 区兜底
            g.appendChild(c);
            var t = document.createElementNS(QZG, 'text');
            t.setAttribute('dx', 0);
            t.setAttribute('dy', (-r) + 'px');
            t.setAttribute('text-anchor', 'middle');
            t.textContent = d.title;
            t.setAttribute('opacity', (cfg.opacityScale - 1) / 3.75);
            t.style.fontSize = cfg.fontSize + 'em';
            t.style.pointerEvents = 'none';
            g.appendChild(t);
            svg.appendChild(g);
            nodeGs.push(g); circleEls.push(c); labelEls.push(t);
            d._circle = c;

            // click → 打开文章（Quartz spaNavigate 对应）
            hit.addEventListener('click', function (ev) {
                ev.stopPropagation();
                if (d._dragged) { d._dragged = false; return; }
                addToVisited(normPath(d.path));
                selectFile({ path: d.path });
            });

            // hover（Quartz mouseover/mouseleave：直连边加深，标签升顶放大显示）
            hit.addEventListener('mouseover', function () {
                var nbs = nodeLinks[d.id] || [];
                for (var nbi = 0; nbi < nbs.length; nbi++) lineEls[nbs[nbi]].setAttribute('stroke', 'var(--graph-line-hot)');
                svg.appendChild(g);                 // .raise()：悬停节点浮到最上层
                t.setAttribute('data-opacity-old', t.getAttribute('opacity'));
                t.setAttribute('opacity', 1);
                t.style.fontSize = (cfg.fontSize * 1.5) + 'em';
            });
            hit.addEventListener('mouseleave', function () {
                var nbs = nodeLinks[d.id] || [];
                for (var nbi = 0; nbi < nbs.length; nbi++) lineEls[nbs[nbi]].setAttribute('stroke', 'var(--graph-line)');
                var old = t.getAttribute('data-opacity-old');
                t.setAttribute('opacity', old != null ? old : (cfg.opacityScale - 1) / 3.75);
                t.style.fontSize = cfg.fontSize + 'em';
            });

            if (cfg.drag) {
                g.addEventListener('pointerdown', function (ev) { qzDragStart(d, ev); });
                t.addEventListener('pointerdown', function (ev) { qzDragStart(d, ev); });
            }
        });

        // ---- 缩放/平移（d3.zoom：scaleExtent [0.25,4]；tag 随缩放渐显 opacity=max((k*opacityScale-1)/3.75,0)） ----
        var zoomT = { x: 0, y: 0, k: 1 };
        function applyZoom() {
            var tr = 'translate(' + zoomT.x + ',' + zoomT.y + ') scale(' + zoomT.k + ')';
            lineEls.forEach(function (el) { el.setAttribute('transform', tr); });
            circleEls.forEach(function (el) { el.setAttribute('transform', tr); });
            (hitEls || []).forEach(function (el) { el.setAttribute('transform', tr); });   // 命中圆必须与视觉同步缩放平移，否则选中错位
            labelEls.forEach(function (el) {
                el.setAttribute('transform', tr);
                el.setAttribute('opacity', Math.max((zoomT.k * cfg.opacityScale - 1) / 3.75, 0));
            });
        }
        if (cfg.zoom) {
            svg.addEventListener('wheel', function (ev) {
                ev.preventDefault();
                userInteract = true;
                var rect = svg.getBoundingClientRect();
                var px = ev.clientX - rect.left, py = ev.clientY - rect.top;
                var ux = ((px - W / 2) / cfg.scale - zoomT.x) / zoomT.k;
                var uy = ((py - H / 2) / cfg.scale - zoomT.y) / zoomT.k;
                var k2 = zoomT.k * (ev.deltaY < 0 ? 1.12 : 0.89);
                k2 = Math.max(0.25, Math.min(4, k2));
                zoomT.x = (px - W / 2) / cfg.scale - ux * k2;
                zoomT.y = (py - H / 2) / cfg.scale - uy * k2;
                zoomT.k = k2;
                applyZoom();
            }, { passive: false });
            var pan = null;
            var panCapEl = null, panPtr = null;
            svg.addEventListener('pointerdown', function (ev) {
                if (ev.target !== svg) return;
                userInteract = true;
                pan = { x: ev.clientX, y: ev.clientY };
                panPtr = ev.pointerId;
                panCapEl = svg.setPointerCapture ? svg : null;
                if (panCapEl) { try { panCapEl.setPointerCapture(panPtr); } catch (e) {} }
                svg.style.cursor = 'grabbing';
            });
            var panMove = function (ev) {
                if (!pan) return;
                zoomT.x += (ev.clientX - pan.x) / cfg.scale;
                zoomT.y += (ev.clientY - pan.y) / cfg.scale;
                pan.x = ev.clientX; pan.y = ev.clientY;
                applyZoom();
            };
            var panUp = function () {
                if (!pan) return;
                pan = null;
                if (panCapEl) { try { panCapEl.releasePointerCapture(panPtr); } catch (e) {} }
                svg.style.cursor = 'default';
            };
            window.addEventListener('pointermove', panMove);
            window.addEventListener('pointerup', panUp);
            window.addEventListener('pointercancel', panUp);
            window.addEventListener('lostpointercapture', panUp);
        }

        // ---- d3-force 模拟（真实 d3-force 引擎，与 Quartz 完全一致） ----
        // forceManyBody().strength(-100*repelForce)：Barnes-Hut 斥力，重合节点 jiggle 随机扰动
        // forceLink(links).id(id).distance(linkDistance)：默认强度 1/min(两端度数) + 按度数 bias 分摊；位置+速度双项 + 重合 jiggle
        // forceCenter().strength(centerForce)：视图原点居中
        stopCurrentSim();   // 渲染新图前停掉上一张图的模拟（local/global 只保留一张）

        // 自适应缩放：局部图每 tick 跟随布局实时适配 → 打开即整图入框、无需手动缩放；
        // 全局图在首轮收敛后做一次性全览。用户手动拖拽/缩放后停止自动适配（尊重手动控制）。
        var sim = null, tickSkip = 0, userInteract = false;
        var bigGraph = nodes.length > 60;   // 大图（全局 176 节点）每 2 tick 同步一次 DOM → 渲染开销减半
        function fitGraph() {
            if (!container || !container.contains(svg)) return;
            var mnx = Infinity, mxx = -Infinity, mny = Infinity, mxy = -Infinity;
            nodes.forEach(function (d) {
                if (d.x < mnx) mnx = d.x; if (d.x > mxx) mxx = d.x;
                if (d.y < mny) mny = d.y; if (d.y > mxy) mxy = d.y;
            });
            var midX = (mnx + mxx) / 2, midY = (mny + mxy) / 2;
            var pad = 40;   // 边距（单位=viewBox）：留白避免撑满四角，观感更像悬浮圆簇而非拉方块
            var rx = Math.max((mxx - mnx) / 2 + pad, 10), ry = Math.max((mxy - mny) / 2 + pad, 10);
            var vbx = W / 2 / cfg.scale, vby = H / 2 / cfg.scale;
            var k = Math.min(vbx / rx, vby / ry);
            // 局部图：只缩不放（紧凑圆保持原尺寸）；全局图：可放大到 2.5 倍。
            // 两者都保证整团完整入框（k 自然 <1 时团会铺满视口，而非压扁成不可读的小点）。
            var kMax = (cfg.depth < 0) ? 2.5 : 1.0;
            k = Math.max(0.15, Math.min(k, kMax));
            zoomT.x = -midX * k; zoomT.y = -midY * k; zoomT.k = k;
            applyZoom();
        }
        if (window.d3 && window.d3.forceSimulation) {
            sim = d3.forceSimulation(nodes)
                .force('charge', d3.forceManyBody().strength(-100 * cfg.repelForce))
                .force('link', d3.forceLink(links).id(function (d) { return d.id; }).distance(cfg.linkDistance))
                .force('center', d3.forceCenter().strength(cfg.centerForce));
            if (centerAnchor) {
                // 软中心牵引：只作用于当前文章节点，力度远小于力链（≈1/min(度)），
                // 使中心节点留在原点附近但允许让位给三角形/多边形的真实平衡位置。
                sim.force('centeroff', d3.forceX().x(0).strength(function (d) { return d === centerAnchor ? 0.05 : 0; }));
                sim.force('centeroff2', d3.forceY().y(0).strength(function (d) { return d === centerAnchor ? 0.05 : 0; }));
            }
            sim.on('tick', function () {
                    if (bigGraph && (++tickSkip % 2)) return;   // 大图隔次同步，减轻积压
                    updateDOM();
                    if (cfg.depth >= 0 && !userInteract && (tickSkip % 4 === 0 || !bigGraph)) {
                        fitGraph();   // 局部图：节流 zoom（每 4 tick 一次），正文流畅，不做无谓重算
                    }
                });
            sim.on('end', function () { updateDOM(); if (!userInteract) fitGraph(); });   // 收敛后兜底一次（含全局图）
            // forceSimulation 创建即自动起跑（alpha 从 1 自然收敛到 alphaMin 0.001）
            curSim = sim;
        } else {
            updateDOM();
        }

        function updateDOM() {
            links.forEach(function (l, li) {
                var el = lineEls[li];
                el.setAttribute('x1', l.source.x); el.setAttribute('y1', l.source.y);
                el.setAttribute('x2', l.target.x); el.setAttribute('y2', l.target.y);
            });
            nodes.forEach(function (d, i) {
                circleEls[i].setAttribute('cx', d.x); circleEls[i].setAttribute('cy', d.y);
                if (hitEls[i]) { hitEls[i].setAttribute('cx', d.x); hitEls[i].setAttribute('cy', d.y); }   // hit 必须与视觉圆同帧移动，否则事件区停留原点
                labelEls[i].setAttribute('x', d.x); labelEls[i].setAttribute('y', d.y);
            });
        }

        }

    // ---- 局部图入口（showArticle 时调用） ----
    function lgRender(path) {
        if (!lgWrap) return;
        if (!window.GRAPH_ENABLED || !path) { lgWrap.style.display = 'none'; return; }
        lgLoadData().then(function (data) {
            if (!data || !data.nodes || !data.nodes.length) { if (lgWrap) lgWrap.style.display = 'none'; return; }
            lgData = data;
            refreshVisited();
            addToVisited(normPath(path));   // Quartz：导航即标记访问（着色用）
            lgSettleContainer();
            lgWrap.style.display = '';
            qzRenderGraph(lgSvg, qzCfg(), path);
        }).catch(function () { if (lgWrap) lgWrap.style.display = 'none'; });
    }

    var curSim = null;
    function stopCurrentSim() { if (curSim) { try { curSim.stop(); } catch (e) {} curSim = null; } }

    // ---- 局部图展开（右上角按钮）：全屏预览层渲染当前文章的邻域图放大版 ----
    function renderZoomedLocal() {
        if (!window.GRAPH_ENABLED) return;
        var overlay = $('graph-preview-outer');
        if (!overlay) return;
        try { setTopHidden(false); } catch (e) {}
        overlay.classList.add('active');
        lgLoadData().then(function (data) {
            if (!data) return;
            lgData = data;
            refreshVisited();
            if (state.path) addToVisited(normPath(state.path));
            var cont = $('graph-preview-container');
            if (cont) qzRenderGraph(cont, qzCfg(), state.path || '');
        }).catch(function () {});
    }
    function hidePreview() {
        var overlay = $('graph-preview-outer');
        if (overlay) overlay.classList.remove('active');
        var cont = $('graph-preview-container');
        if (cont && cont.children.length) cont.innerHTML = '';
        stopCurrentSim();
    }
    (function () {
        var btn = $('graph-local-full');
        if (btn) btn.addEventListener('click', function () { renderZoomedLocal(); });
        var overlay = $('graph-preview-outer');
        if (overlay) overlay.addEventListener('click', function (ev) { if (ev.target === overlay) hidePreview(); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' || ev.key === 'Esc') hidePreview(); });
    })();
    // 初始化数据预取（首次打开邻域图秒开）
    if (window.GRAPH_ENABLED) { lgLoadData().catch(function () {}); }
    /* ===== Excalidraw 绘画渲染（.excalidraw.md：lz-string 解码 compressed-json → 官方 exportToSvg 引擎，手写 SVG 兜底） ===== */
    function escapeXml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    var exAscent = 0.9;  // Virgil 字形 ascent 比例（canvas 实测缓存——不依赖 dominant-baseline，所有浏览器一致）
    // 渲染序号：主题切换快速连点时只让最新一次渲染落地（官方导出/字体加载都是异步的，防止旧批覆盖新批）
    var exRenderSeq = 0;
    // 官方渲染器懒加载：只有绘画页才注入脚本（其余页面零开销）。
    // 完整官方包 @excalidraw/excalidraw UMD（与 Obsidian 插件同源渲染管线）：React UMD → ReactDOM UMD → ExcalidrawLib，
    // 全局名 window.ExcalidrawLib。EXCALIDRAW_ASSET_PATH 指向本地 vendor 字体，导出的 @font-face 落在同源路径
    var exLibPromise = null;
    function loadExcalidrawLib() {
        if (window.ExcalidrawLib && window.ExcalidrawLib.exportToSvg) return Promise.resolve();
        if (!exLibPromise) {
            exLibPromise = new Promise(function (res, rej) {
                if (!window.EXCALIDRAW_ASSET_PATH) window.EXCALIDRAW_ASSET_PATH = '/assets/dist/';
                var urls = ['/assets/react.production.min.js', '/assets/react-dom.production.min.js', '/assets/excalidraw.production.min.js'];
                (function next(i) {
                    if (i >= urls.length) {
                        (window.ExcalidrawLib && window.ExcalidrawLib.exportToSvg) ? res() : rej(new Error('ExcalidrawLib load failed'));
                        return;
                    }
                    var sc = document.createElement('script');
                    sc.src = urls[i];
                    sc.onload = function () { next(i + 1); };
                    sc.onerror = function () { exLibPromise = null; rej(new Error('script load failed: ' + urls[i])); };
                    document.head.appendChild(sc);
                })(0);
            });
        }
        return exLibPromise;
    }
    // 字体码兼容：Obsidian 插件 2.x 新 schema 的字体枚举与本引擎不同（5=Virgil legacy、4=Helvetica）。
    // 不映射的话未知码会被回落成系统字体（Segoe UI Emoji），字形墨迹偏离基线——视觉上"字母脱离线外"。
    // 线绑定文字（双击箭头插入的标签）保持原样：官方引擎自带标签定位与蒙版断线（线在字母处挖口，与 Obsidian 一致）
    var EX_FAMAP = { 4: 2, 5: 1 };
    function normalizeExcalidrawScene(els) {
        els.forEach(function (e) {
            if (e.fontFamily != null && EX_FAMAP[e.fontFamily] != null) e.fontFamily = EX_FAMAP[e.fontFamily];
        });
    }
    // 官方引擎渲染：rough.js 手绘笔画、贝塞尔弯曲箭头、freedraw 笔迹、hachure 填充、绑定标签+蒙版断线全量还原
    // 阅读模式对齐 Obsidian：图随主题（背景/配色跟随 .dark），作者显式设置的画布底色除外
    function exIsDark() { return !!(document.documentElement.classList && document.documentElement.classList.contains('dark')); }
    async function renderExcalidrawOfficial(scene, dv, my) {
        await loadExcalidrawLib();
        if (my !== exRenderSeq) return;  // 已有更新的渲染（主题快速切换），丢弃本批
        var els = JSON.parse(JSON.stringify((scene.elements || []).filter(function (e) { return e && !e.isDeleted && e.type !== 'frame'; })));
        normalizeExcalidrawScene(els);
        // 原生展示在页面背景上（同 Obsidian 阅读）：不导出画布底色，页面 --vp-c-bg 直接透出；
        // 仅当作者显式存了非默认底色（白底是默认值）才保留，让绘图底色跟日夜主题一起切换
        var sbg2 = ((scene.viewBackgroundColor || '') + '').toLowerCase();
        var customBg = sbg2 && sbg2 !== '#fff' && sbg2 !== '#ffffff';
        var svg = await window.ExcalidrawLib.exportToSvg({
            elements: els,
            appState: {
                exportBackground: !!customBg,
                viewBackgroundColor: customBg ? scene.viewBackgroundColor : '#ffffff',
                exportWithDarkMode: exIsDark()
            },
            files: scene.files || {},
            exportPadding: 16
        });
        if (my !== exRenderSeq) return;  // 丢弃过期批次
        // 字体就绪再插入：导出坐标按 Virgil metrics 计算，避免先以回退字体绘制再跳变
        try { await Promise.race([document.fonts.load('20px Virgil', 'Ag'), new Promise(function (r) { setTimeout(r, 1500); })]); } catch (e) {}
        if (my !== exRenderSeq) return;
        // 剥除导出包注入的全部 @font-face 块：内联 SVG 内嵌字体声明在部分浏览器与页面级同名 face 冲突导致
        // 加载报 network error（实测），且会引用未 vendor 的辅助字体。统一走页面级自托管 Virgil（index.css）
        var sts = svg.querySelectorAll('style');
        for (var i = sts.length - 1; i >= 0; i--) {
            if ((sts[i].textContent || '').indexOf('@font-face') !== -1 && sts[i].parentNode) sts[i].parentNode.removeChild(sts[i]);
        }
        svg.removeAttribute('width'); svg.removeAttribute('height');  // 只留 viewBox → 等比缩放
        svg.style.width = '100%'; svg.style.height = 'auto';
        var wrap = dv.querySelector('.excalidraw-canvas') || dv;
        wrap.innerHTML = '';
        wrap.appendChild(svg);
    }
    // 手写兜底渲染（官方包加载失败/导出异常时仍能出图——离线部署场景）：仅支持六种基础元素
    async function renderExcalidrawFallback(scene, dv, my) {
        var els = scene.elements || [];
        // 等 Virgil 字体加载（文字位置依赖其 metrics），超时 1.5s 兜底
        try { await Promise.race([document.fonts.load('20px Virgil'), new Promise(function (r) { setTimeout(r, 1500); })]); } catch (e) {}
        if (my !== exRenderSeq) return;  // 过期批次：终止仍写入
        // canvas 实测 Virgil ascent 比例（基线渲染的精确偏移；字体没就绪时 fallback 0.9）
        try {
            var cc = document.createElement('canvas'), ctx = cc.getContext('2d');
            ctx.font = '20px Virgil, sans-serif';
            var mm = ctx.measureText('Ag');
            if (mm && mm.actualBoundingBoxAscent) exAscent = mm.actualBoundingBoxAscent / 20;
        } catch (e) {}
        // 线文本（bound text）：Excalidraw 把绑定线的文字渲染在线段中点（忽略未吸附的保存坐标——F/I/H/J 错位根因）
        // 预构建：线 id → 中点（text 侧用 containerId=线 id 反向查找）
        var lineMid = {};
        els.forEach(function (a) {
            if (a.type !== 'arrow' && a.type !== 'line') return;
            var pts = a.points || [[0, 0], [100, 0]];
            lineMid[a.id] = {
                x: (a.x + pts[0][0] + a.x + pts[pts.length - 1][0]) / 2,
                y: (a.y + pts[0][1] + a.y + pts[pts.length - 1][1]) / 2
            };
        });
        var minX = 1e9, minY = 1e9, maxX = -1e9, maxY = -1e9;
        els.forEach(function (e) {
            if (e.isDeleted || e.type === 'frame') return;
            if (e.points && e.points.length) {
                e.points.forEach(function (p) {
                    minX = Math.min(minX, e.x + p[0]); maxX = Math.max(maxX, e.x + p[0]);
                    minY = Math.min(minY, e.y + p[1]); maxY = Math.max(maxY, e.y + p[1]);
                });
            } else {
                minX = Math.min(minX, e.x); minY = Math.min(minY, e.y);
                maxX = Math.max(maxX, e.x + (e.width || 0)); maxY = Math.max(maxY, e.y + (e.height || 0));
            }
        });
        if (!isFinite(minX)) { minX = 0; minY = 0; maxX = 800; maxY = 600; }
        // 原生展示：画布底色透明（页面背景透出），仅保留作者显式设置的非默认底色
        var fbg2 = ((scene.viewBackgroundColor || '') + '').toLowerCase();
        var fbgAttr = (fbg2 && fbg2 !== '#fff' && fbg2 !== '#ffffff') ? scene.viewBackgroundColor : 'transparent';
        var pad = 40, vw = maxX - minX + pad * 2, vh = maxY - minY + pad * 2;
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' + (minX - pad) + ' ' + (minY - pad) + ' ' + vw + ' ' + vh + '" style="width:100%;height:auto;background:' + fbgAttr + '">';
        svg += '<defs><marker id="ex-arrow" markerWidth="12" markerHeight="12" refX="9" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L0,6 L9,3 z" fill="#000000"/></marker></defs>';
        els.forEach(function (e) {
            if (e.isDeleted || e.type === 'frame') return;
            var col = e.strokeColor || '#000';
            var bgc = e.backgroundColor || 'transparent';
            var sw = e.strokeWidth || 1;
            var dash = (e.strokeStyle === 'dashed') ? ' stroke-dasharray="7,5"' : '';
            var op = (e.opacity != null && e.opacity < 100) ? ' opacity="' + (e.opacity / 100) + '"' : '';
            if (e.type === 'text') {
                var fs = e.fontSize || 20;
                var lines = String(e.text || '').split('\n');
                var anchor = e.textAlign === 'center' ? 'middle' : (e.textAlign === 'right' ? 'end' : 'start');
                // 绑定线文字：渲染在线段中点（Excalidraw bound text 引擎行为——替换保存坐标）；自由文字用原坐标
                var ex = e.x, ey = e.y;
                var lm = e.containerId ? lineMid[e.containerId] : null;
                if (lm) { ex = lm.x - (e.width || 0) / 2; ey = lm.y - (e.height || 0) / 2; }
                var tx = e.textAlign === 'center' ? ex + (e.width || 0) / 2 : (e.textAlign === 'right' ? ex + (e.width || 0) : ex);
                // y 是文字顶部；基线渲染 = y + ascent*fs（字形顶部 ≈ ey——不依赖 dominant-baseline，全浏览器一致）
                var baseY = ey + exAscent * fs;
                var rotT = '';
                if (e.angle) { var tc = e.x + (e.width || 0) / 2, tc2 = e.y + (e.height || 0) / 2; rotT = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + tc + ' ' + tc2 + ')"'; }
                svg += '<text x="' + tx + '" y="' + baseY.toFixed(2) + '" font-size="' + fs + '" fill="' + col + '" font-family="Virgil, Segoe UI Emoji, sans-serif" text-anchor="' + anchor + '"' + rotT + op + '>';
                for (var li = 0; li < lines.length; li++) {
                    if (li > 0) svg += '<tspan x="' + tx + '" dy="' + (fs * 1.25) + '">';  // Excalidraw 行高 1.25
                    svg += escapeXml(lines[li]);
                    if (li > 0) svg += '</tspan>';
                }
                svg += '</text>';
            } else if (e.type === 'arrow' || e.type === 'line') {
                var pts = (e.points || [[0, 0], [100, 0]]).map(function (p) { return (e.x + p[0]).toFixed(1) + ',' + (e.y + p[1]).toFixed(1); });
                var rotL = '';
                if (e.angle) { var lc = e.x + (e.width || 0) / 2, lc2 = e.y + (e.height || 0) / 2; rotL = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + lc + ' ' + lc2 + ')"'; }
                // polyline 全段 points（弯曲箭头还原）+ 箭头 marker（arrow 才有）
                svg += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + col + '" stroke-width="' + sw + '"' + (e.type === 'arrow' ? ' marker-end="url(#ex-arrow)"' : '') + dash + op + rotL + '/>';
            } else if (e.type === 'rectangle') {
                var rx = (e.roundness && e.roundness.type === 'round') ? Math.min(12, (e.roundness.value || 8)) : 0;
                var rotR = '';
                if (e.angle) { var rc = e.x + (e.width || 0) / 2, rc2 = e.y + (e.height || 0) / 2; rotR = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + rc + ' ' + rc2 + ')"'; }
                svg += '<rect x="' + e.x + '" y="' + e.y + '" width="' + (e.width || 0) + '" height="' + (e.height || 0) + '" fill="' + bgc + '" stroke="' + col + '" stroke-width="' + sw + '" rx="' + rx + '"' + dash + op + rotR + '/>';
            } else if (e.type === 'ellipse') {
                var rotE = '';
                if (e.angle) { var ecx = e.x + (e.width || 0) / 2, ecy = e.y + (e.height || 0) / 2; rotE = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + ecx + ' ' + ecy + ')"'; }
                svg += '<ellipse cx="' + (e.x + (e.width || 0) / 2) + '" cy="' + (e.y + (e.height || 0) / 2) + '" rx="' + ((e.width || 0) / 2) + '" ry="' + ((e.height || 0) / 2) + '" fill="' + bgc + '" stroke="' + col + '" stroke-width="' + sw + '"' + dash + op + rotE + '/>';
            } else if (e.type === 'diamond') {
                var cx = e.x + (e.width || 0) / 2, cy = e.y + (e.height || 0) / 2;
                var rotD = '';
                if (e.angle) { var dc = e.x + (e.width || 0) / 2, dc2 = e.y + (e.height || 0) / 2; rotD = ' transform="rotate(' + (e.angle * 57.2958).toFixed(2) + ' ' + dc + ' ' + dc2 + ')"'; }
                svg += '<polygon points="' + cx + ',' + e.y + ' ' + (e.x + (e.width || 0)) + ',' + cy + ' ' + cx + ',' + (e.y + (e.height || 0)) + ' ' + e.x + ',' + cy + '" fill="' + bgc + '" stroke="' + col + '" stroke-width="' + sw + '"' + dash + op + rotD + '/>';
            }
        });
        svg += '</svg>';
        var wrap2 = dv.querySelector('.excalidraw-canvas') || dv;
        wrap2.innerHTML = svg;
    }
    // 总入口：解码 compressed-json → 官方引擎优先，任何异常回落手写兜底（自身永不抛出——两个调用点都未接 promise）
    async function renderExcalidraw(restorePz) {
        var my = ++exRenderSeq;  // 主题快速切换时只让最新一次渲染落地
        var raw = window.EXCALIDRAW_RAW || '';
        var m = raw.match(/```compressed-json\s*([\s\S]*?)```/);
        var dv = $('excalidraw-view');
        if (!dv) return;
        if (!m || typeof LZString === 'undefined') { dv.innerHTML = '<p>Excalidraw data not found in this file.</p>'; return; }
        var scene;
        try { scene = JSON.parse(LZString.decompressFromBase64(m[1].replace(/\s+/g, ''))); }
        catch (e) { dv.innerHTML = '<p>Failed to decode drawing: ' + escapeXml(e.message) + '</p>'; return; }
        try { await renderExcalidrawOfficial(scene, dv, my); }
        catch (oe) {
            try { await renderExcalidrawFallback(scene, dv, my); }
            catch (fe) { if (my === exRenderSeq) dv.innerHTML = '<p>Render failed: ' + escapeXml((fe && fe.message) || fe) + '</p>'; return; }
        }
        if (my !== exRenderSeq) return;  // 已有更新的渲染，视图切换交给最新一批
        // 视图切换：文章容器显示（excalidraw 在 doc-main 内，正常左中右结构）md 隐藏；
        // 绘画无目录 → 右轨两个 TOC 面板都隐藏（右栏留空）
        hideSpecialViews();  // 清掉可能残留的 PDF/Canvas/图谱（互斥）——必须在显示本视图之前
        dv.style.display = 'block';  // 显示画布容器（初始 display:none——忘了设置会导致空白）
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        $('doc-wrap').style.display = 'flex';
        var tp = $('toc-panel'); if (tp) tp.style.display = 'none';
        var mdv = $('md-view'); if (mdv) mdv.style.display = 'none';
        var ttl = $('doc-title');
        if (lgWrap) lgWrap.style.display = 'none';   // Excalidraw 全屏不显示局部图
        // 全屏沉浸：隐藏文章标题（.excalidraw 打开无标题栏，画布吃满中+右）
        if (ttl) { ttl.textContent = ''; ttl.style.display = 'none'; }
        // 主题切换重渲染后：新 SVG 节点的 pan/zoom 需重新绑定；
        // restorePz 携带切换前的平移/缩放，恢复原位置而非重置到首帧
        var w0 = dv.querySelector('.excalidraw-canvas'); if (w0) delete w0.dataset.pz;
        // 交互（阅读模式对齐 Obsidian，只平移/缩放不编辑）：初始适配显示全貌，滚轮/双指缩放、空白拖拽平移
        enableExcalidrawPanZoom(dv, restorePz || null);
    }
    // Excalidraw pan/zoom：svg 按自然尺寸渲染，外层 holder 承载 translate+scale（同 Canvas / Graph 的平移+缩放模式）
    function enableExcalidrawPanZoom(dv, restorePz) {
        var svg = dv.querySelector('svg');
        if (!svg) return;
        var wrap = dv.querySelector('.excalidraw-canvas') || dv;
        if (wrap.dataset.pz) return;
        wrap.dataset.pz = '1';
        var nat = svg.getBoundingClientRect();
        svg.style.width = nat.width + 'px';
        svg.style.maxWidth = 'none';
        var holder = document.createElement('div');
        holder.style.cssText = 'position:absolute;inset:0;transform-origin:0 0;will-change:transform;';
        svg.parentNode.replaceChild(holder, svg);
        holder.appendChild(svg);
        var k = 1, tx = 0, ty = 0;
        function apply() { holder.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + k + ')'; wrap.dataset.pzState = JSON.stringify({ k: k, tx: tx, ty: ty }); }
        var cw = wrap.clientWidth || 600, ch = wrap.clientHeight || 400;
        if (restorePz && typeof restorePz.k === 'number' && isFinite(restorePz.k)) {
            // 主题切换重渲染：恢复切换前的平移/缩放位置，不重置到首帧
            k = restorePz.k; tx = restorePz.tx; ty = restorePz.ty;
            apply();
        } else {
            // 首帧适配（同 Obsidian 打开即见全貌）：等比缩放进视口并居中；内容本身小于视口也放大到 1.5 上限
            var f = Math.min(cw / (nat.width || 1), ch / (nat.height || 1), 1.5);
            f = Math.max(f, 0.05);
            k = f;
            tx = (cw - nat.width * f) / 2;
            ty = (ch - nat.height * f) / 2;
            apply();
        }
        wrap.style.cursor = 'grab';
        // 滚轮缩放（同 Graph/Canvas）
        wrap.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            var r = wrap.getBoundingClientRect();
            var px = ev.clientX - r.left, py = ev.clientY - r.top;
            var k2 = k * (ev.deltaY < 0 ? 1.12 : 0.892);
            k2 = Math.max(0.15, Math.min(8, k2));
            tx = px - (px - tx) * (k2 / k);
            ty = py - (py - ty) * (k2 / k);
            k = k2; apply();
        }, { passive: false });
        // 指针平移 + 双指 pinch 缩放（对齐 Graph/Canvas 模式：window 监听，移动端更可靠）
        var pointers = {}, lastPinchDist = 0, panning = null;
        wrap.addEventListener('pointerdown', function (ev) {
            ev.preventDefault();
            if (ev.target.closest('a')) return;
            pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
            var nP = Object.keys(pointers).length;
            if (nP >= 2) {
                panning = null;  // 双指 → 缩放模式，停止平移
                var ids = Object.keys(pointers);
                lastPinchDist = Math.hypot(pointers[ids[0]].x - pointers[ids[1]].x, pointers[ids[0]].y - pointers[ids[1]].y);
            } else if (nP === 1) {
                panning = { x: ev.clientX, y: ev.clientY };
                wrap.style.cursor = 'grabbing';
            }
        });
        window.addEventListener('pointermove', function (ev) {
            if (pointers[ev.pointerId]) { pointers[ev.pointerId].x = ev.clientX; pointers[ev.pointerId].y = ev.clientY; }
            var ids = Object.keys(pointers);
            // 双指 pinch 缩放（围绕两指中点）
            if (ids.length >= 2 && lastPinchDist > 0) {
                var p1 = pointers[ids[0]], p2 = pointers[ids[1]];
                var dist = Math.hypot(p2.x - p1.x, p2.y - p1.y);
                var rect = wrap.getBoundingClientRect();
                var mx = (p1.x + p2.x) / 2 - rect.left, my = (p1.y + p2.y) / 2 - rect.top;
                var k2 = k * (dist / lastPinchDist);
                k2 = Math.max(0.15, Math.min(8, k2));
                tx = mx - (mx - tx) * (k2 / k);
                ty = my - (my - ty) * (k2 / k);
                k = k2; lastPinchDist = dist; apply();
                return;
            }
            // 单指平移
            if (!panning) return;
            tx += ev.clientX - panning.x;
            ty += ev.clientY - panning.y;
            panning.x = ev.clientX; panning.y = ev.clientY;
            apply();
        });
        function endPtr(ev) {
            delete pointers[ev.pointerId];
            if (Object.keys(pointers).length === 0) {
                panning = null; lastPinchDist = 0;
                wrap.style.cursor = 'grab';
            }
        }
        window.addEventListener('pointerup', endPtr);
        window.addEventListener('pointercancel', endPtr);
    }
    if (window.SSR_EXCALIDRAW) { try { renderExcalidraw(); } catch (e) { if (window.console) console.log('excalidraw err', e); } }
    // 阅读模式跟随主题：`.dark` 切换时重渲染当前绘画（导出引擎会按新主题重新配色）。
    // 重渲染前先记住用户当前的平移/缩放（holder transform），渲染后原样恢复位置，避免被重置到首帧
    try {
        new MutationObserver(function () {
            var dv = $('excalidraw-view');
            if (dv && dv.style.display !== 'none' && window.EXCALIDRAW_RAW) {
                // 从 wrap 的 dataset 直接读上次的平移/缩放（CSS transform 会被浏览器缩写，
                // 解析字符串不可靠；dataset 随 wrap 跨渲染保留）
                var restore = null;
                var w = dv.querySelector('.excalidraw-canvas') || dv;
                if (w.dataset.pz) {
                    try { restore = JSON.parse(w.dataset.pzState || 'null'); } catch (e) { restore = null; }
                }
                try { renderExcalidraw(restore); } catch (e) { if (window.console) console.log('excalidraw re-render err', e); }
            }
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    } catch (e) {}

    /* ===== Obsidian Canvas 白板渲染（.canvas：JSON nodes/edges → SVG 画布，配色跟随日夜主题） ===== */
    // 节点颜色：Obsidian 官方色板 1-6 的近似色值；其余接受 #hex 原样
    var CV_COLORS = { '1': '#fb464c', '2': '#faa53d', '3': '#ffe100', '4': '#21c95e', '5': '#1db5f5', '6': '#a882ff' };
    function cvColor(c) {
        if (c == null) return '';
        c = String(c);
        return CV_COLORS[c] || (/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(c) ? c : '');
    }
    function renderCanvas() {
        var dv = $('canvas-view'), board = $('canvas-board');
        if (!dv || !board) return;
        finishCanvasView();   // 先显示视图，保证 board.clientWidth 有真实值供首帧缩放适配
        var data;
        try { data = JSON.parse(window.CANVAS_JSON || '{}'); }
        catch (e) { board.innerHTML = '<p style="padding:24px">Invalid canvas file: ' + escapeXml(e.message) + '</p>'; return; }
        try { renderCanvasScene(data, board, { interactive: true }); }
        catch (e) { board.innerHTML = '<p style="padding:24px">Render failed: ' + escapeXml((e && e.message) || e) + '</p>'; }
    }
    // 视图切换（同 Excalidraw）：白板无目录 → 右轨两个 TOC 面板都隐藏
    function finishCanvasView() {
        var dv = $('canvas-view');
        if (!dv) return;
        hideSpecialViews();  // 清掉可能残留的 PDF/Excalidraw/图谱（互斥）
        dv.style.display = 'block';
        $('archive-view').style.display = 'none';
        $('empty-state').style.display = 'none';
        $('doc-wrap').style.display = 'flex';
        var tp = $('toc-panel'); if (tp) tp.style.display = 'none';
        var mdv = $('md-view'); if (mdv) mdv.style.display = 'none';
        if (lgWrap) lgWrap.style.display = 'none';   // Canvas 全屏不显示局部图
        // Canvas 全屏沉浸：隐藏文章标题，中+右全部让给画布（Obsidian 打开 .canvas 无标题栏）
        var ttl = $('doc-title');
        if (ttl) { ttl.textContent = ''; ttl.style.display = 'none'; }
    }
    function renderCanvasScene(data, board, opts) {
        var interactive = !!(opts && opts.interactive);
        var NS = 'http://www.w3.org/2000/svg';
        var nodes = (data.nodes || []).filter(function (n) { return n && n.id && n.x != null; });
        var byId = {};
        nodes.forEach(function (n) { byId[n.id] = n; });
        var pad = 80, minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        nodes.forEach(function (n) {
            var w = n.width || 0, h = n.height || 0;
            if (n.x < minX) minX = n.x; if (n.y < minY) minY = n.y;
            if (n.x + w > maxX) maxX = n.x + w; if (n.y + h > maxY) maxY = n.y + h;
        });
        if (!isFinite(minX)) { minX = -40; minY = -40; maxX = 760; maxY = 560; }
        var cw = maxX - minX + pad * 2, ch = maxY - minY + pad * 2;
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('xmlns', NS);
        // 交互模式：svg 铺满容器、无 viewBox 自适应；内容挂进可平移缩放的 cv-view 分组（同 Obsidian 视口语义）
        var cvv = svg;
        if (interactive) {
            svg.setAttribute('width', '100%');
            svg.setAttribute('height', '100%');
            cvv = document.createElementNS(NS, 'g');
            cvv.setAttribute('class', 'cv-view');
            svg.appendChild(cvv);
        } else {
            svg.setAttribute('viewBox', (minX - pad) + ' ' + (minY - pad) + ' ' + cw + ' ' + ch);
            svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        }
        function el(tag, attrs, parent) {
            var e = document.createElementNS(NS, tag);
            for (var k in attrs) if (attrs[k] != null) e.setAttribute(k, attrs[k]);
            (parent || cvv).appendChild(e);
            return e;
        }
        // 卡片分组：交互模式把每个内容节点的元素包进 <g class="cv-card">，拖拽改其自身 translate（世界坐标偏移）
        var cards = {};
        function startCard(id) {
            var g = document.createElementNS(NS, 'g');
            g.setAttribute('class', 'cv-card');
            g.setAttribute('data-id', id);
            cvv.appendChild(g);
            cards[id] = { g: g, tx: 0, ty: 0 };
            return g;
        }
        // 边端点：节点指定边的中点；贝塞尔控制点沿该边法向外伸（Obsidian 同款平滑曲线语义）
        function sidePt(n, side) {
            var x = n.x || 0, y = n.y || 0, w = n.width || 0, h = n.height || 0;
            if (side === 'top') return [x + w / 2, y];
            if (side === 'bottom') return [x + w / 2, y + h];
            if (side === 'left') return [x, y + h / 2];
            return [x + w, y + h / 2];
        }
        var DIR = { top: [0, -1], bottom: [0, 1], left: [-1, 0], right: [1, 0] };
        function cubicAt(p0, c1, c2, p1, t) {
            var u = 1 - t, a = u * u * u, b = 3 * u * u * t, c = 3 * u * t * t, d = t * t * t;
            return [a * p0[0] + b * c1[0] + c * c2[0] + d * p1[0], a * p0[1] + b * c1[1] + c * c2[1] + d * p1[1]];
        }
        // 分层：组节点永远垫底（Obsidian 中 group 在内容下方），内容节点按文件顺序
        (nodes.filter(function (n) { return n.type === 'group'; })).forEach(function (g) {
            var col = cvColor(g.color);
            var r = el('rect', { x: g.x, y: g.y, width: g.width, height: g.height, rx: 10, 'class': 'cv-group' });
            if (col) r.setAttribute('stroke', col);
            if (g.label) {
                var t = el('text', { x: (g.x || 0) + 8, y: (g.y || 0) - 8, 'class': 'cv-glabel' });
                t.textContent = g.label;
            }
        });
        (nodes.filter(function (n) { return n.type !== 'group'; })).forEach(function (n) {
            var col = cvColor(n.color);
            var x = n.x || 0, y = n.y || 0, w = n.width || 200, h = n.height || 60;
            // 图片文件节点：整卡铺图（圆角裁剪，等比 cover）
            if (n.type === 'file' && /\.(png|jpe?g|gif|webp|bmp|avif|svg)$/i.test(n.file || '')) {
                var hostCard = startCard(n.id);
                var cid = 'cvclip-' + String(n.id).replace(/[^a-zA-Z0-9-]/g, '');
                var cp = el('clipPath', { id: cid });
                el('rect', { x: x, y: y, width: w, height: h, rx: 8 }, cp);
                hostCard.appendChild(cp);
                var im = el('image', { x: x, y: y, width: w, height: h, 'clip-path': 'url(#' + cid + ')', preserveAspectRatio: 'xMidYMid slice' }, hostCard);
                im.setAttribute('href', '/vault/' + encodeURI(String(n.file || '').replace(/^\.?\//, '')).replace(/%2F/gi, '/') + '?embed=1');
                cards[n.id].baseX = x; cards[n.id].baseY = y;
                return;
            }
            hostCard = startCard(n.id);
            var rect = el('rect', { x: x, y: y, width: w, height: h, rx: 8, 'class': 'cv-node' }, hostCard);
            if (col) rect.setAttribute('stroke', col);
            var fo = document.createElementNS(NS, 'foreignObject');
            fo.setAttribute('x', x); fo.setAttribute('y', y); fo.setAttribute('width', w); fo.setAttribute('height', h);
            var box = document.createElement('div');
            box.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
            if (n.type === 'file') {
                var f = String(n.file || '');
                var a = document.createElement('a');
                a.className = 'cv-filecard';
                a.href = '/' + encodeURI(f.replace(/^\.?\//, '')).replace(/%2F/gi, '/');
                var ic = document.createElement('span'); ic.className = 'cv-icon'; ic.textContent = /\.(md|canvas)$/i.test(f) ? '📝' : (/\.(pdf)$/i.test(f) ? '📕' : '📄');
                var nm = document.createElement('span'); nm.className = 'cv-fname'; nm.textContent = f.split('/').pop();
                a.appendChild(ic); a.appendChild(nm);
                box.appendChild(a);
            } else if (n.type === 'link') {
                var u = String(n.url || '');
                var host = u.replace(/^https?:\/\//i, '').split('/')[0];
                var la = document.createElement('a');
                la.className = 'cv-linkcard'; la.href = u; la.target = '_blank'; la.rel = 'noopener';
                var lic = document.createElement('span'); lic.className = 'cv-icon'; lic.textContent = '🔗';
                var lnm = document.createElement('span'); lnm.className = 'cv-fname'; lnm.textContent = host || u;
                la.appendChild(lic); la.appendChild(lnm);
                box.appendChild(la);
            } else {
                // text 节点：复用站点渲染栈（marked + DOMPurify），支持行内 markdown
                box.className = 'cv-content';
                box.innerHTML = DOMPurify.sanitize(marked.parse(String(n.text || ''), { gfm: true, breaks: true }));
            }
            fo.appendChild(box);
            hostCard.appendChild(fo);
            cards[n.id].baseX = x; cards[n.id].baseY = y;
        });
        // 连线（画在节点之后：Obsidian 连线浮于卡片上方）；交互模式存 refs 供卡片拖拽时跟随
        var edges = [];
        (data.edges || []).forEach(function (ed) {
            var a = byId[ed.fromNode], b = byId[ed.toNode];
            if (!a || !b) return;
            var fs = ed.fromSide || 'right', ts = ed.toSide || 'left';
            var p1 = sidePt(a, fs), p2 = sidePt(b, ts);
            var d1 = DIR[fs] || [1, 0], d2 = DIR[ts] || [-1, 0];
            var dist = Math.hypot(p2[0] - p1[0], p2[1] - p1[1]);
            var k = Math.max(30, Math.min(dist / 2, 120));
            var c1 = [p1[0] + d1[0] * k, p1[1] + d1[1] * k], c2 = [p2[0] + d2[0] * k, p2[1] + d2[1] * k];
            var col = cvColor(ed.color);
            var path = el('path', { d: 'M ' + p1[0] + ' ' + p1[1] + ' C ' + c1[0] + ' ' + c1[1] + ', ' + c2[0] + ' ' + c2[1] + ', ' + p2[0] + ' ' + p2[1], 'class': 'cv-edge' });
            if (col) path.setAttribute('stroke', col);
            // 箭头：终点沿进入方向手绘小三角（不用 marker——每条线换色无需重复定义 marker）
            var ax = p2[0] - c2[0], ay = p2[1] - c2[1], L = Math.hypot(ax, ay) || 1;
            ax /= L; ay /= L;
            var s = 8, px = -ay, py = ax;
            var tri = el('polygon', {
                points: p2[0] + ',' + p2[1] + ' '
                    + (p2[0] - ax * s + px * s * 0.45) + ',' + (p2[1] - ay * s + py * s * 0.45) + ' '
                    + (p2[0] - ax * s - px * s * 0.45) + ',' + (p2[1] - ay * s - py * s * 0.45),
                'class': 'cv-arrow'
            });
            if (col) tri.setAttribute('fill', col);
            if (ed.label) {
                var mid = cubicAt(p1, c1, c2, p2, 0.5);
                var lab = ed.label;
                var tw = Math.max(24, lab.length * 7 + 14);  // 中英混排近似宽度
                var g = el('g', { 'class': 'cv-label' });
                el('rect', { x: mid[0] - tw / 2, y: mid[1] - 11, width: tw, height: 22, rx: 5 }, g);
                var t = el('text', { x: mid[0], y: mid[1] + 4, 'text-anchor': 'middle' }, g);
                t.textContent = lab;
            }
            if (interactive) edges.push({ path: path, tri: tri, a: a, b: b, fs: fs, ts: ts });
        });
        board.innerHTML = '';
        board.appendChild(svg);
        if (interactive) initCanvasInteractions(board, svg, cvv, minX - pad, minY - pad, cw, ch);
    }
    /* Canvas 交互（Obsidian 体验）：空白拖拽平移、滚轮/双指缩放、点卡片进笔记、拖卡片移动（连线跟随）。
       平移缩放作用于 cv-view 的 translate/scale；卡片拖拽改卡片自身 translate（世界单位）并同步节点坐标。 */
    function initCanvasInteractions(board, svg, cvv, ox, oy, cw, ch) {
        var tx = 0, ty = 0, k = 1;
        // 内容世界坐标范围：[ox..ox+cw]。首帧自动缩放适配并居中（同 Obsidian 打开即见全貌）
        (function firstFit() {
            var bw = board.clientWidth || 600, bh = board.clientHeight || 400;
            var vw = cw || 1, vh = ch || 1;
            k = Math.min(bw / vw, bh / vh, 1.5);
            tx = (bw - vw * k) / 2 - ox * k;
            ty = (bh - vh * k) / 2 - oy * k;
            applyView();
        })();
        function applyView() {
            cvv.setAttribute('transform', 'translate(' + tx + ',' + ty + ') scale(' + k + ')');
        }
        // wheel 缩放（rAF 节流 + 围绕光标）
        var wheelPending = false;
        board.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            var rect = board.getBoundingClientRect();
            var px = ev.clientX - rect.left, py = ev.clientY - rect.top;
            var k2 = k * (ev.deltaY < 0 ? 1.12 : 0.892);
            k2 = Math.max(0.15, Math.min(6, k2));
            tx = px - (px - tx) * (k2 / k);
            ty = py - (py - ty) * (k2 / k);
            k = k2;
            applyView();
        }, { passive: false });
        // 阅读模式（对齐 Obsidian Canvas 阅读视图）：单指空白=平移，双指=缩放，点卡片=打开目标；
        // 卡片不可拖拽、无编辑功能——只读展示
        var pointers = {}, lastPinch = 0, pan = null;
        board.addEventListener('pointerdown', function (ev) {
            pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
            var ids = Object.keys(pointers);
            if (ids.length >= 2) {
                pan = null;
                var a = pointers[ids[0]], b = pointers[ids[1]];
                lastPinch = Math.hypot(a.x - b.x, a.y - b.y);
                return;
            }
            pan = { x: ev.clientX, y: ev.clientY, ax: 0, ay: 0, moved: false };
        });
        board.addEventListener('pointermove', function (ev) {
            if (pointers[ev.pointerId]) { pointers[ev.pointerId].x = ev.clientX; pointers[ev.pointerId].y = ev.clientY; }
            var ids = Object.keys(pointers);
            // 双指缩放（围绕两指中点）
            if (ids.length >= 2 && lastPinch > 0) {
                var a = pointers[ids[0]], b = pointers[ids[1]];
                var d = Math.hypot(a.x - b.x, a.y - b.y);
                var rect = board.getBoundingClientRect();
                var mx = (a.x + b.x) / 2 - rect.left, my = (a.y + b.y) / 2 - rect.top;
                var k2 = k * (d / lastPinch);
                k2 = Math.max(0.15, Math.min(6, k2));
                tx = mx - (mx - tx) * (k2 / k);
                ty = my - (my - ty) * (k2 / k);
                k = k2;
                lastPinch = d;
                applyView();
                return;
            }
            if (!pan) return;
            if (Math.abs(ev.clientX - pan.x) + Math.abs(ev.clientY - pan.y) > 6) pan.moved = true;
            pan.ax += ev.clientX - pan.x; pan.ay += ev.clientY - pan.y;
            pan.x = ev.clientX; pan.y = ev.clientY;
            if (!pan.r) {
                pan.r = requestAnimationFrame(function () {
                    pan.r = 0;
                    tx += pan.ax; ty += pan.ay; pan.ax = 0; pan.ay = 0;
                    applyView();
                });
            }
        });
        function endPtr(ev) {
            delete pointers[ev.pointerId];
            if (Object.keys(pointers).length < 2) lastPinch = 0;
            if (Object.keys(pointers).length === 0) {
                var wasPan = pan && pan.moved;
                pan = null;
                // 未平移的单击 → 打开卡片目标（文件/链接卡带 <a>；Obsidian 阅读语义）
                if (!wasPan && ev.target) {
                    var a = ev.target.closest('a[href]');
                    var note = ev.target.closest('.cv-content a[data-note]');
                    if (a) {
                        var href = a.getAttribute('href');
                        if (href && (a.classList.contains('cv-filecard') || a.classList.contains('cv-linkcard'))) {
                            if (a.classList.contains('cv-linkcard')) { window.open(href, '_blank', 'noopener'); return; }
                            selectFile({ path: href.replace(/^\//, '') }); return;
                        }
                    } else if (note) {
                        selectFile({ path: note.getAttribute('data-note').replace(/^\//, '') }); return;
                    }
                }
            }
        }
        board.addEventListener('pointerup', endPtr);
        board.addEventListener('pointercancel', endPtr);
    }
    // SSR 直达的触发在 enterApp 的 SSR_CANVAS 分支内（时序原因，见该处注释）；SPA 点击走 selectFile 分支

    // 搜索功能：整页切换，实时过滤文档
    var searchView = $('search-view');
    var searchInput = $('search-input');
    var searchResults = $('search-results');
    function collectFiles() {
        var files = [];
        (function walk(nodes, cat) {
            nodes.forEach(function (n) {
                if (n.type === 'file' && /\.(md|canvas)$/i.test(n.name)) files.push({ name: n.name, path: n.path, cat: cat });
                else if (n.children) walk(n.children, n.name);
            });
        })(state.tree, '');
        return files;
    }
    function openSearch() {
        // 搜索面板从顶部栏下方滑出 + 按钮图标 morph 为叉子
        try { toggleAi(false); } catch (e) {}  // AI 面板 z-index 高于搜索层：先关掉避免盖住搜索结果
        $('content').style.display = 'none';
        searchView.classList.add('open');
        $('vp-search-btn').classList.add('open');
        searchInput.value = '';
        searchResults.innerHTML = '';
        // 等面板滑出动画完成后聚焦，避免页面跳动
        setTimeout(function () { searchInput.focus({ preventScroll: true }); }, 320);
    }
    function closeSearch() {
        searchView.classList.remove('open');
        $('vp-search-btn').classList.remove('open');
        $('content').style.display = '';
    }
    function renderSearch(q) {
        if (!q) { searchResults.innerHTML = ''; return; }
        // 标签搜索：#标签 匹配笔记内容里的 #标签
        var tagMatch = q.match(/^#(.+)$/);
        if (tagMatch) {
            var tag = tagMatch[1].toLowerCase();
            var files = collectFiles();
            var results = [];
            var checked = 0;
            if (!files.length) { searchResults.innerHTML = '<div class="search-empty">' + 'No notes with this tag' + '</div>'; return; }
            files.forEach(function (f) {
                api('/api/file?path=' + encodeURIComponent(f.path)).then(function (data) {
                    var c = data.content || '';
                    if (c.toLowerCase().indexOf('#' + tag) > -1) results.push(f);
                    checked++;
                    if (checked >= files.length) renderSearchItems(results);
                }).catch(function () {
                    checked++;
                    if (checked >= files.length) renderSearchItems(results);
                });
            });
            return;
        }
        // 全文搜索：lunr 索引
        if (!window._lunrIndex) {
            buildSearchIndex().then(function () { doLunrSearch(q); });
            searchResults.innerHTML = '<div class="search-empty">Building index…</div>';
            return;
        }
        doLunrSearch(q);
    }
    // 构建全文索引：加载所有文章内容 → lunr 索引
    function buildSearchIndex() {
        var files = collectFiles();
        var docs = [];
        var chain = Promise.resolve();
        files.forEach(function (f) {
            chain = chain.then(function () {
                return api('/api/file?path=' + encodeURIComponent(f.path)).then(function (data) {
                    docs.push({
                        id: f.path,
                        path: f.path,
                        name: f.name.replace(/^\d+-/, '').replace(/\.md$/i, ''),
                        cat: f.cat || '',
                        content: data.content || ''
                    });
                }).catch(function () {});
            });
        });
        return chain.then(function () {
            window._lunrDocs = docs;
            window._lunrIndex = lunr(function () {
                this.ref('id');
                this.field('name', { boost: 10 });
                this.field('content');
                docs.forEach(function (d) { this.add(d); }, this);
            });
        });
    }
    // lunr 搜索结果
    function doLunrSearch(q) {
        var results = [];
        try {
            var hits = window._lunrIndex.search(q);
            results = hits.map(function (h) {
                return window._lunrDocs.filter(function (d) { return d.id === h.ref; })[0];
            }).filter(Boolean);
        } catch (e) {
            // 搜索语法错误（如特殊字符）→ 退化为标题匹配
            var kw = q.toLowerCase();
            results = collectFiles().filter(function (f) {
                return f.name.toLowerCase().indexOf(kw) > -1;
            });
        }
        renderSearchItems(results);
    }
    function renderSearchItems(files) {
        if (!files.length) {
            searchResults.innerHTML = '<div class="search-empty">' + 'No matching notes' + '</div>';
            return;
        }
        var html = files.map(function (f) {
            var dispName = f.name.replace(/^\d+-/, '').replace(/\.md$/, '');
            // 内容匹配片段（截取关键词上下文）
            var snippet = '';
            if (f.content) {
                var kwMatch = searchInput.value.trim().toLowerCase();
                var c = f.content.replace(/[#*`_>\[\]|!-]/g, ' ').replace(/\s+/g, ' ').toLowerCase();
                var idx = kwMatch ? c.indexOf(kwMatch) : -1;
                if (idx > -1) {
                    var start = Math.max(0, idx - 40);
                    snippet = '…' + f.content.slice(start, idx + kwMatch.length + 60).replace(/\n/g, ' ') + '…';
                }
            }
            return '<div class="search-item" data-path="' + esc(f.path) + '">' +
                '<div class="search-item-main">' +
                (f.cat ? '<span class="search-cat">' + esc(f.cat) + '</span>' : '') +
                '<span class="search-name">' + esc(dispName) + '</span>' +
                '</div>' +
                (snippet ? '<div class="search-snippet">' + esc(snippet) + '</div>' : '') +
                '<span class="search-arrow">↵</span></div>';
        }).join('');
        searchResults.innerHTML = html;
        searchResults.querySelectorAll('.search-item').forEach(function (el) {
            el.addEventListener('click', function () {
                var path = el.dataset.path;
                closeSearch();
                selectFile({ path: path });
            });
        });
    }
    // 点击面板外部关闭搜索（面板打开时）
    document.addEventListener('click', function (e) {
        if (!searchView.classList.contains('open')) return;
        if (searchView.contains(e.target)) return;
        if ($('vp-search-btn').contains(e.target)) return;
        closeSearch();
    });
    // 搜索按钮：打开时是叉子（点击关闭），关闭时是放大镜（点击打开）
    // AI 对话：顶栏按钮展开面板，问知识库（/api/ask：检索 + DeepSeek 生成回答）
    // 总开关关闭：隐藏 AI 按钮（面板无入口）
    var aiBtn = $('vp-ai-btn');
    if (!window.AI_ENABLED && aiBtn) aiBtn.style.display = 'none';

    var aiView = $('ai-view');
    var aiMsgs = $('ai-msgs');
    var aiInput = $('ai-input');
    /* AI = 面板内容的第二种渲染。toggleAi 只切 body.ai-open 模式：
       桌面端左栏原地换内容；窄屏端把抽屉拉开/收起（抽屉里此刻渲染的是 AI） */
    function toggleAi(open) {
        if (open === undefined) open = !document.body.classList.contains('ai-open');
        if (open && searchView.classList.contains('open')) closeSearch();
        document.body.classList.toggle('ai-open', open);
        var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
        if (!desktop) setFrontDrawer(open);
        else if (open) setTimeout(function () { aiInput.focus({ preventScroll: true }); }, 100);
    }
    /* 把 AI 内容块挂进当前断点对应的面板容器（桌面=左栏 / 窄屏=抽屉） */
    function placeAi() {
        var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
        var target = desktop ? document.getElementById('left-sidebar') : drawerEl;
        if (target && aiView.parentNode !== target) target.appendChild(aiView);
    }
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
                    a.addEventListener('click', function (e) {
                        e.preventDefault();
                        toggleAi(false);
                        selectFile({ path: s.path });
                    });
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
    $('vp-ai-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        // 同级内容切换：桌面端已处于 AI 则保持（幂等）；窄屏端点按钮 = 开/关抽屉（AI 内容）
        var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
        toggleAi(desktop ? true : undefined);
    });
    // 跨断点：缩到窄屏且 AI 开着 → 自动关闭只留内容区；容器归属变化时重新挂载
    window.addEventListener('resize', function () {
        placeAi();
        var desktop = window.matchMedia && window.matchMedia('(min-width:769px)').matches;
        if (!desktop && document.body.classList.contains('ai-open')) {
            document.body.classList.remove('ai-open');
            setFrontDrawer(false);
        }
    });
    $('ai-send').addEventListener('click', aiAsk);
    aiInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); aiAsk(); }
        else if (e.key === 'Escape') { toggleAi(false); }
    });

    $('vp-search-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        if (searchView.classList.contains('open')) {
            closeSearch();
        } else {
            openSearch();
        }
    });
    searchInput.addEventListener('input', function () { renderSearch(searchInput.value); });
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            var first = searchResults.querySelector('.search-item');
            if (first) first.click();
        } else if (e.key === 'Escape') {
            closeSearch();
        }
    });

    // 主题切换按钮（桌面右侧 / 移动最左）：点击切换日夜模式
    function bindThemeBtn(id) {
        $(id).addEventListener('click', function (e) {
            e.stopPropagation();
            var dark = !document.documentElement.classList.contains('dark');
            applyTheme(dark);
        });
    }
    bindThemeBtn('vp-theme-btn');
    bindThemeBtn('vp-theme-btn-m');

    // 菜单按钮：切换全屏侧滑菜单（文章目录树，预渲染零延迟）
    var drawerEl = $('vp-drawer');
    var frontDrawerMd = $('front-drawer-md');
    function setFrontDrawer(open) {
        drawerEl.classList.toggle('open', open);
        document.body.classList.toggle('drawer-open', open); // 锁定页面滚动
        var nw = document.getElementById('nav-wrap');
        if (nw) nw.classList.toggle('no-blur', open); // 顶部栏实心化防透字
    }
    placeAi(); // 初始挂载：桌面→左栏 / 窄屏→抽屉
    // 将 state.tree 转为 markdown（前端版 tree_to_md）
    function treeToMd(items, prefix) {
        prefix = prefix || '';
        var lines = [];
        items.forEach(function (item) {
            if (item.type === 'dir') {
                var childMd = treeToMd(item.children || [], prefix + '  ');
                if (childMd === '') return;
                lines.push(prefix + '- ' + item.name);
                lines.push(childMd);
            } else {
                if (item.rss_item || item.rss_placeholder) return; // RSS 占位/文章不进菜单 markdown（动态加载）
                if (is_media(item.name) || is_image(item.name)) return;
                var name = item.name.replace(/\.(md|pdf|canvas|html)$/i, '');
                var href = pathHref(item.path);
                lines.push(prefix + '- [' + name + '](/' + href + ')');
            }
        });
        return lines.join('\n');
    }

    // 渲染目录树到 DOM（抽屉 + 左侧栏）
    function renderTreeToDom(md) {
        try {
            frontDrawerMd.innerHTML = DOMPurify.sanitize(marked.parse(md, { gfm: true }));
            // 兼容写法：找 li 的直接子 UL / 向上找 li 祖先（不用 :scope/closest）
            function childUl(li) {
                for (var i = 0; i < li.children.length; i++) {
                    if (li.children[i].tagName === 'UL') return li.children[i];
                }
                return null;
            }
            function parentLi(el) {
                var n = el.parentNode;
                while (n && n !== frontDrawerMd && n.tagName !== 'LI') n = n.parentNode;
                return n && n.tagName === 'LI' ? n : null;
            }
            // 为每个目录项重建相对路径（用于强制展开匹配）
            function buildDirPaths(root) {
                function walkUl(ul, prefix) {
                    var items = ul.children;
                    for (var i = 0; i < items.length; i++) {
                        var li = items[i];
                        var nameNode = li.firstChild;
                        var name = nameNode ? nameNode.textContent.trim() : '';
                        if (!name) continue;
                        var path = prefix ? prefix + '/' + name : name;
                        li.dataset.path = path;
                        var sub = null;
                        for (var j = 0; j < li.children.length; j++) {
                            if (li.children[j].tagName === 'UL') { sub = li.children[j]; break; }
                        }
                        if (sub) walkUl(sub, path);
                    }
                }
                var rootUl = root.querySelector('ul');
                if (rootUl) walkUl(rootUl, '');
            }
            buildDirPaths(frontDrawerMd);
            // 折叠树：父项点击折叠/展开
            var lis = frontDrawerMd.querySelectorAll('li');
            for (var i = 0; i < lis.length; i++) {
                (function (li) {
                    var sub = childUl(li);
                    if (!sub) return;
                    li.classList.add('has-children');
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
                    if (window.FRONT_DRAWER_EXPANDED === false) {
                        var forceExpand = false;
                        var dirs = window.FRONT_EXPANDED_DIRS || [];
                        var p = li.dataset.path || '';
                        for (var k = 0; k < dirs.length; k++) {
                            var d = dirs[k];
                            if (d && (p === d || p.indexOf(d + '/') === 0)) { forceExpand = true; break; }
                        }
                        if (!forceExpand) li.classList.add('collapsed');
                    }
                    li.addEventListener('click', function (ev) {
                        if (sub.contains(ev.target)) return;
                        ev.preventDefault();
                        li.classList.toggle('collapsed');
                    });
                })(lis[i]);
            }
            // 叶子链接：点击打开文章 + 关闭抽屉
            var as = frontDrawerMd.querySelectorAll('a');
            for (var j = 0; j < as.length; j++) {
                (function (a) {
                    var li = parentLi(a);
                    if (li && childUl(li)) return;
                    a.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        var href = a.getAttribute('href') || '';
                        var h = href.replace(/^#/, '');
                        if (!h) return;
                        setFrontDrawer(false);
                        selectFile({ path: decPath(h) });
                    });
                })(as[j]);
            }
            // 克隆到左侧常驻栏
            var leftTree = $('left-drawer-md');
            if (leftTree) {
                leftTree.innerHTML = frontDrawerMd.innerHTML;
                function subUl(li) {
                    for (var i = 0; i < li.children.length; i++) {
                        if (li.children[i].tagName === 'UL') return li.children[i];
                    }
                    return null;
                }
                leftTree.removeEventListener('click', leftTree._treeClickHandler);
                leftTree._treeClickHandler = function (ev) {
                    var t = ev.target;
                    var a = t.closest ? t.closest('a') : null;
                    var li = t.closest ? t.closest('li') : null;
                    if (li) {
                        var sub = subUl(li);
                        if (sub && !sub.contains(t)) {
                            ev.preventDefault();
                            li.classList.toggle('collapsed');
                            return;
                        }
                    }
                    if (a) {
                        var n = a.parentNode, isParent = false;
                        while (n && n !== leftTree) {
                            if (n.tagName === 'LI' && subUl(n)) { isParent = true; break; }
                            n = n.parentNode;
                        }
                        if (isParent) return;
                        ev.preventDefault();
                        var href = a.getAttribute('href') || '';
                        var h = href.replace(/^#/, '');
                        if (!h) return;
                        selectFile({ path: decPath(h) });
                    }
                };
                leftTree.addEventListener('click', leftTree._treeClickHandler);
            }
        } catch (e) {}
    }

// 预渲染：PHP 内联的 FRONT_MENU_MD（vault/ 文章目录树）
    if (window.FRONT_MENU_MD) {
        renderTreeToDom(window.FRONT_MENU_MD);
    }
    $('vp-menu-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        // 菜单按钮 = "目录"入口：桌面端切回目录渲染；窄屏打开抽屉并确保里面是目录
        if (window.matchMedia && window.matchMedia('(min-width:769px)').matches) {
            if (document.body.classList.contains('ai-open')) toggleAi(false);
            return;
        }
        // 关着→开(目录)；开着但是AI→切回目录(面板保持)；开着且是目录→关闭
        var open = drawerEl.classList.contains('open');
        if (!open || document.body.classList.contains('ai-open')) {
            document.body.classList.remove('ai-open');
            setFrontDrawer(true);
        } else {
            setFrontDrawer(false);
        }
    });
    // 点击侧滑菜单空白处关闭
    drawerEl.addEventListener('click', function (e) {
        if (e.target === drawerEl || e.target === frontDrawerMd) {
            setFrontDrawer(false);
        }
    });

    /* ---------- 桌面端左侧常驻目录栏：克隆抽屉处理完的 DOM（含箭头/折叠状态），事件用委托 ---------- */
    (function () {
        var leftTree = $('left-drawer-md');
        if (!leftTree || !window.FRONT_MENU_MD || !frontDrawerMd.innerHTML) return;
        leftTree.innerHTML = frontDrawerMd.innerHTML;
        function subUl(li) {
            for (var i = 0; i < li.children.length; i++) {
                if (li.children[i].tagName === 'UL') return li.children[i];
            }
            return null;
        }
        leftTree.addEventListener('click', function (ev) {
            var t = ev.target;
            var a = t.closest ? t.closest('a') : null;
            var li = t.closest ? t.closest('li') : null;
            // 父项标签（非子树内部）→ 折叠/展开
            if (li) {
                var sub = subUl(li);
                if (sub && !sub.contains(t)) {
                    ev.preventDefault();
                    li.classList.toggle('collapsed');
                    return;
                }
            }
            // 叶子链接 → 打开文章（父项链接交给上面的折叠逻辑）
            if (a) {
                var n = a.parentNode, isParent = false;
                while (n && n !== leftTree) {
                    if (n.tagName === 'LI' && subUl(n)) { isParent = true; break; }
                    n = n.parentNode;
                }
                if (isParent) return;
                ev.preventDefault();
                var href = a.getAttribute('href') || '';
                var h = href.replace(/^#/, '');
                if (!h) return;
                selectFile({ path: decPath(h) });
            }
        });
    })();

    /* ---------- 浏览器返回/前进：hash 变化时同步视图 ---------- */
    // 站内文章链接（/xxx.md / xxx.html）：SPA 切换（无刷新）+ pushState 路径化 URL；刷新/直达走服务端渲染
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        // RSS 伪路径（侧边栏文章/源目录）→ 交给 selectFile 用通用 /api/file 渲染，绝不导航
        var rssTest = decodeURIComponent((href.charAt(0) === '/' ? href.substring(1) : href));
        if (/^rss:\/\//.test(rssTest)) {
            e.preventDefault();
            selectFile({ path: rssTest });
            return;
        }
        if (/(\.md|\.canvas|\.html)$/i.test(href) && href.charAt(0) === '/') {
            e.preventDefault();
            var p = href.substring(1);
            try { p = decPath(p); } catch (err) {}  // 菜单/内链 href 带 URL 编码 → 解码后再查（防双重编码）
            try { history.pushState(null, '', href); } catch (err) {}
            selectFile({ path: p });
        }
    });
    function handleHash() {
        var h = location.hash.replace(/^#/, '');
        // 标签页：#tag/<name>（兼容 hash 前导斜杠 #/tag/... 与 #tag/...）
        var tm = h.replace(/^\//, '').match(/^tag\/(.+)$/);
        if (tm) {
            // 标签名可能在 hash 里被 URL 编码（中文/嵌套标签 #a/b）：解码后匹配
            var tagName;
            try { tagName = decodeURI(tm[1]); } catch (e) { tagName = tm[1]; }
            // 规范化标签页地址到首页路径名（/#/tag/<name>）：标签页是靠片段导航（location.hash）
            // 打开的，此时 pathname 还残留在旧文章路径（如 /Article.md#/tag/...）。若不改掉，
            // 地址栏会一直指向旧文章：刷新先闪旧文再切标签、标签列表里点文章时 selectFile 因
            // pathname 为 .md 而跳过更新地址、甚至 URL 与显示内容完全不符。
            try { if (location.pathname !== '/') history.replaceState(null, '', '/#/tag/' + tm[1]); } catch (e) {}
            showTag(tagName);
            return;
        }
        if (!h) {
            // hash 为空 → 回到首页（与普通文章完全一致的渲染管线）
            renderHome();
            // 重置滚动位置（避免回归档时错位）
            $('content').scrollTop = 0;
            return;
        }
        if (/^\d+$/.test(h) && window._docMap && window._docMap[h]) {
            // 数字 ID 直达（旧版兼容）
            if (window._docMap[h] !== state.path) {
                selectFile({ path: window._docMap[h] });
            }
        } else if (h) {
            // 完整路径直达（新逻辑：#posts/draft/xxx.md）
            var p = decPath(h);
            // 兜底：纯文件名（无路径前缀）时查 _docMap 显示名映射
            if (p.indexOf('/') === -1 && window._docMap && window._docMap[p]) {
                p = window._docMap[p];
            }
            if (p !== state.path) {
                selectFile({ path: p });
            }
        }
    }
    window.addEventListener('hashchange', handleHash);
    // SPA 路径导航（pushState）的返回/前进：popstate 时按当前路径恢复文章或回首页
    window.addEventListener('popstate', function () {
        var h = decPath(location.hash.replace(/^#/, ''));
        // 任意非空 hash（文章路径 / 标签页 #/tag/... / 数字ID）都交给 hash 路由：
        // 片段导航（location.hash、锚点、标签点击）会触发 popstate，此时 pathname
        // 仍是旧文章，不能优先用 pathname 恢复——否则刚打开的标签列表/文章会被覆盖回旧文章。
        if (h) {
            handleHash();
            return;
        }
        // 无 hash：pushState 路径导航（/xxx.md）的返回/前进按 pathname 恢复
        var p = location.pathname.replace(/^\//, '');
        if (/(\.md|\.canvas)$/i.test(p)) {
            selectFile({ path: p });
        } else {
            hideSpecialViews();  // 返回首页：清掉可能残留的 PDF/画布/图谱（互斥）
            renderHome();
        }
    });

    /* ---------- 初始化 ---------- */
    (function init() {
        // 已移除访问密码，直接进入（URL hash 直达在 loadTree 完成后处理）
        enterApp();
    })();
})();

// 滚动条宽度测量：#content 内部滚动条的宽度写入 CSS 变量 --sbw，
// 供 .doc-wrap translate 补偿（否则滚动条会让居中偏左约半个滚动条宽）
(function () {
    var ce = document.getElementById('content');
    if (!ce) return;
    function measureSbw() {
        var s = Math.max(0, ce.offsetWidth - ce.clientWidth);
        document.documentElement.style.setProperty('--sbw', s + 'px');
    }
    measureSbw();
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(measureSbw).observe(ce);
})();
