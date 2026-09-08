/* ===== render.js — Markdown→DOM 渲染核心（纯函数，无站点壳状态耦合）=====
   site.js 抽出的文章渲染管线：md 解析(marked+DOMPurify+Obsidian 语法)、TOC、
   代码复制/行号、代码高亮(hljs 按需)、Mermaid(按需)、Callout 转换、块 ID 摘取、
   KaTeX 公式(按需)。不依赖 state/tree/视图切换/反链/嵌入网络逻辑（processObsidian
   作为编排层仍留在 site.js）。暴露 window.BP_Render。 */
(function () {
    'use strict';
    function $(id) { return document.getElementById(id); }
    function escapeXml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /* ---------- Obsidian 语法保护：marked 渲染前占位，渲染后还原 ---------- */
    // marked 会把 ![[xxx.png]] 里的 [[xxx]] 误判为链接（尤其 www 开头），先替换成占位符；
    // 数学公式同理——$ 内的 *_[] 等会被 marked 吃掉，先摘出，还原为 .ob-math 空元素（tex 走 base64 存 data-tex）
    // 占位符 payload 用无填充 base64url：标准 base64 的 == 尾巴会被 ==高亮== 扩展误认成定界符

    function b64e(s) {
        return btoa(unescape(encodeURIComponent(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function b64d(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        return decodeURIComponent(escape(atob(s)));
    }
    function protectObsidian(text) {
        // 围栏代码块(```/~~~)整块摘出保护：块内 $ 变量($uri 等)、[[ ]]、![[ ]] 都是代码，
        // 不能被公式/嵌入/链接正则误判（否则 nginx 示例里的 $uri 会被当 LaTeX 吞掉 $）。
        // 还原放在最后，marked 渲染前代码块已恢复原样，内部内容始终不碰占位替换。
        var fences = [];
        var shielded = text.replace(/```[\s\S]*?```|~~~[\s\S]*?~~~/g, function (m) {
            fences.push(m);
            return '%%OBS_FENCE%%' + fences.length + '%%END%%';
        });
        return shielded
            // 块级公式先替换，避免内联正则吃掉 $$ 的定界符；I/D 标记行内/块级
            .replace(/\$\$([\s\S]+?)\$\$/g, function (m, inner) {
                if (!inner.trim()) return m;
                return '%%OBS_MATH%%D:' + b64e(inner) + '%%END%%';
            })
            .replace(/\$(?!\s)((?:[^$\n\\]|\\.)+?)\$/g, function (m, inner) {
                if (!inner.trim()) return m;
                return '%%OBS_MATH%%I:' + b64e(inner) + '%%END%%';
            })
            .replace(/!\[\[([^\]]+)\]\]/g, function (m, inner) {
                return '%%OBS_EMBED%%' + b64e(inner) + '%%END%%';
            })
            .replace(/\[\[([^\]]+)\]\]/g, function (m, inner) {
                return '%%OBS_LINK%%' + b64e(inner) + '%%END%%';
            })
            .replace(/%%OBS_FENCE%%(\d+)%%END%%/g, function (m, i) {
                return fences[Number(i) - 1];
            });
    }
    function restoreObsidian(html) {
        return html
            .replace(/%%OBS_MATH%%([ID]):([A-Za-z0-9_-]+)%%END%%/g, function (m, mode, b64) {
                return '<span class="ob-math" data-display="' + (mode === 'D' ? '1' : '0') + '" data-tex="' + b64 + '"></span>';
            })
            .replace(/%%OBS_EMBED%%([A-Za-z0-9_-]+)%%END%%/g, function (m, b64) {
                return '![[' + b64d(b64) + ']]';
            })
            .replace(/%%OBS_LINK%%([A-Za-z0-9_-]+)%%END%%/g, function (m, b64) {
                return '[[' + b64d(b64) + ']]';
            });
    }

    /* Obsidian 注释 %%...%%（预览不可见）：渲染前剥离；可跨行；```/~~~ 围栏内不剥（防破坏代码示例） */

    function stripObsidianComments(text) {
        if (text.indexOf('%%') === -1) return text;
        var lines = text.split('\n'), out = [], fence = null, inComment = false;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (fence) {
                out.push(line);
                var t = line.trim();
                if (t.charAt(0) === fence.ch && RegExp('^' + fence.ch + '{' + fence.len + '}\\s*$').test(t)) fence = null;
                continue;
            }
            var fm = /^\s*(`{3,}|~{3,})/.exec(line);
            if (fm) { fence = { ch: fm[1].charAt(0), len: fm[1].length }; out.push(line); continue; }
            var res = '', j = 0;
            while (j < line.length) {
                if (inComment) {
                    var end = line.indexOf('%%', j);
                    if (end === -1) { j = line.length; }   // 整行都在注释内
                    else { inComment = false; j = end + 2; }
                } else {
                    var st = line.indexOf('%%', j);
                    if (st === -1) { res += line.slice(j); j = line.length; }
                    else { res += line.slice(j, st); j = st + 2; inComment = true; }
                }
            }
            out.push(res);
        }
        return out.join('\n');
    }

    /* 统一 md 渲染管线：注释剥离 → Obsidian 占位保护 → marked → 还原 → 消毒（所有渲染出口共用） */

    function mdToHtml(raw) {
        return DOMPurify.sanitize(restoreObsidian(marked.parse(protectObsidian(stripObsidianComments(String(raw || ''))), { gfm: true, breaks: true })));
    }

    /* GFM 脚注扩展（marked 原生不支持）：[^id] 上标引用 / [^id]: 定义。
       引用不用 href 锚点——hash 变化会触发 handleHash 误当文章路径导航，改为 JS 点击滚动 */
    marked.use({
        extensions: [
            {
                name: 'footnoteDef', level: 'block',
                start: function (src) { var i = src.search(/^\[\^/m); return i === -1 ? undefined : i; },
                tokenizer: function (src) {
                    var m = /^\[\^([^\]\s]+)\]:[ \t]*(.*)\n?/.exec(src);
                    if (!m) return undefined;
                    return { type: 'footnoteDef', raw: m[0], id: m[1], tokens: this.lexer.inlineTokens(m[2]) };
                },
                renderer: function (token) {
                    return '<div class="fn-def" data-fnid="' + esc(token.id) + '"><span class="fn-mark">' + esc(token.id) + '.</span>' + this.parser.parseInline(token.tokens) + '</div>';
                }
            },
            {
                name: 'footnoteRef', level: 'inline',
                start: function (src) { var i = src.indexOf('[^'); return i === -1 ? undefined : i; },
                tokenizer: function (src) {
                    var m = /^\[\^([^\]\s]+)\](?!:)/.exec(src);
                    if (!m) return undefined;
                    return { type: 'footnoteRef', raw: m[0], id: m[1] };
                },
                renderer: function (token) {
                    return '<sup class="fn-ref" data-fnid="' + esc(token.id) + '">' + esc(token.id) + '</sup>';
                }
            },
            {
                /* Obsidian 高亮 ==文字== → <mark>（不与加粗/斜体冲突：== 前后需非等号边界） */
                name: 'obHighlight', level: 'inline',
                start: function (src) { var i = src.indexOf('=='); return i === -1 ? undefined : i; },
                tokenizer: function (src) {
                    var m = /^==(?!\s)([\s\S]+?)==(?!=)/.exec(src);
                    if (!m || !m[1].trim()) return undefined;
                    return { type: 'obHighlight', raw: m[0], tokens: this.lexer.inlineTokens(m[1]) };
                },
                renderer: function (token) {
                    return '<mark>' + this.parser.parseInline(token.tokens) + '</mark>';
                }
            }
        ]
    });

    /* ---------- 界面切换 ---------- */
    // 首页：渲染站点设置配置的首页文章正文（复用文章渲染管线；幂等——只渲染一次，避免二次渲染重排抽搐）
    // 首页文章与普通文章完全同管线：showArticle 同一渲染通道（页脚 / TOC / mermaid / 代码复制 /
    // Obsidian 链接 / 反链全部一致）。首页内容每次重新渲染——md-view 与文章共用，需防被覆盖后残留旧文

    function renderToc() {
        var list = $('toc-list');
        if (!list) return;
        var headings = $('md-view').querySelectorAll('h1, h2, h3, h4');
        if (!headings.length) {
            $('toc-panel').style.display = 'none';
            return;
        }
        $('toc-panel').style.display = 'block';
        list.innerHTML = '';
        headings.forEach(function (h) {
            var lv = parseInt(h.tagName.charAt(1), 10);
            var text = h.textContent.trim();
            if (!text) return;
            var link = document.createElement('button');
            link.className = 'toc-link lv-' + lv;
            link.textContent = text;
            link.addEventListener('click', function () {
                var top = h.offsetTop - $('content').offsetTop;
                $('content').scrollTo({ top: top - 90, behavior: 'smooth' });
            });
            list.appendChild(link);
        });
    }

    // 首页文章已走 showArticle 管线：TOC 复用文章同一 #toc-panel，不再有独立首页目录

    function addCodeCopy() {
        $('md-view').querySelectorAll('pre').forEach(function (pre) {
            if (pre.querySelector('.code-copy')) return;
            var btn = document.createElement('button');
            btn.className = 'code-copy';
            btn.title = 'Copy code';
            btn.setAttribute('aria-label', 'Copy code');
            // 单行代码块：按钮垂直居中（避免偏下不对称）
            if (pre.scrollHeight <= 60) btn.classList.add('single-line');
            btn.addEventListener('click', function () {
                var code = pre.querySelector('code');
                var text = code ? code.innerText : pre.innerText;
                var done = function () {
                    btn.classList.add('copied');
                    btn.title = 'Copied';
                    setTimeout(function () {
                        btn.classList.remove('copied');
                        btn.title = 'Copy code';
                    }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(function () {
                        fallbackCopy(text);
                        done();
                    });
                } else {
                    fallbackCopy(text);
                    done();
                }
            });
            pre.appendChild(btn);
        });
    }
    /* 代码块行号（Quartz/Obsidian 同款）：每行左侧带行号。逐行重高亮，跨行注释/字符串的
       连续高亮可能不完整（取舍），但行号稳定、可选中复制。 */
    function addCodeLineNumbers() {
        $('md-view').querySelectorAll('pre').forEach(function (pre) {
            if (pre.querySelector('.code-lines') || !pre.querySelector('code')) return;
            var code = pre.querySelector('code');
            var lang = ((code.className.match(/language-([\w+-]+)/) || [])[1] || '').toLowerCase();
            if (lang === 'mermaid') return;  // mermaid 图表不编号（renderMermaid 处理）
            var text = code.textContent || '';
            var lines = text.replace(/\r\n/g, '\n').replace(/\n$/, '').split('\n');
            if (lines.length < 2) return;  // 单行不编号（保持简洁）
            if (lines.length === 1 && lines[0] === '') return;
            var table = document.createElement('table');
            table.className = 'code-lines';
            var tb = document.createElement('tbody');
            var lng = hljs.getLanguage && hljs.getLanguage(lang);
            lines.forEach(function (line, i) {
                var tr = document.createElement('tr');
                var num = document.createElement('td');
                num.className = 'code-line-num';
                num.textContent = String(i + 1);
                var body = document.createElement('td');
                body.className = 'code-line-body';
                var html;
                try {
                    html = lng ? hljs.highlight(line, { language: lang }).value : escapeXml(line);
                } catch (e) { html = escapeXml(line); }
                // 保证空行保高（行号对齐）
                if (!html || html === '') html = '&nbsp;';
                body.innerHTML = html;
                tr.appendChild(num); tr.appendChild(body);
                tb.appendChild(tr);
            });
            table.appendChild(tb);
            code.style.display = 'none';
            pre.appendChild(table);
            pre.classList.add('has-lines');
        });
    }
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    /* ===== 代码高亮（按需加载 hljs：首页/无代码文章零开销）=====
       highlight.min.js + nginx 语言包在首次遇到代码块时再注入；
       主题色由 site.css 统一控制（亮/暗两套，注入第三方 vs* 会与 site.css 冲突） */

    var _hlPromise = null;
    function loadHighlightLib() {
        if (window.hljs) return Promise.resolve();
        if (_hlPromise) return _hlPromise;
        _hlPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.onload = function () {
                // highlight.min.js 只带 core；语言包加载失败不阻塞主体可用
                var lang = document.createElement('script');
                lang.onload = function () { resolve(); };
                lang.onerror = function () { resolve(); };
                lang.src = '/assets/nginx.min.js';
                document.head.appendChild(lang);
            };
            s.onerror = function () { _hlPromise = null; reject(new Error('highlight load failed')); };
            s.src = '/assets/highlight.min.js';
            document.head.appendChild(s);
        });
        return _hlPromise;
    }
    function applyHighlight() {
        try {
            $('md-view').querySelectorAll('pre code').forEach(function (el) {
                if (/\blanguage-mermaid\b/.test(el.className)) return;
                hljs.highlightElement(el);
            });
        } catch (e) {}
        addCodeLineNumbers();
    }
    /* ===== Mermaid 图表（```mermaid 代码块 → 渲染流程图/时序图等）===== */
    // 懒加载 mermaid.min.js（仅当页面出现 mermaid 代码块），默认从本机 /assets/ 自托管加载

    var _mermaidPromise = null;
    function loadMermaidLib() {
        if (window.mermaid) return Promise.resolve(window.mermaid);
        if (!_mermaidPromise) {
            _mermaidPromise = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = '/assets/mermaid.min.js';
                s.onload = function () {
                    try {
                        window.mermaid.initialize({ startOnLoad: false, theme: document.documentElement.classList.contains('dark') ? 'dark' : 'default', securityLevel: 'loose' });
                    } catch (e) {}
                    resolve(window.mermaid);
                };
                s.onerror = function () { _mermaidPromise = null; reject(new Error('Mermaid failed to load')); };
                document.head.appendChild(s);
            });
        }
        return _mermaidPromise;
    }
    function renderMermaid(rootEl) {
        if (!rootEl) return;
        rootEl.querySelectorAll('pre code.language-mermaid').forEach(function (code) {
            if (code.dataset.mmd) return;
            var src = code.textContent || '';
            if (!src.trim()) return;
            var pre = code.closest('pre');
            var holder = document.createElement('div');
            holder.className = 'mermaid-holder';
            holder.textContent = 'Loading diagram…';
            if (pre) { pre.replaceWith(holder); } else { code.replaceWith(holder); }
            loadMermaidLib().then(function (mmd) {
                var id = 'mmd' + (window._mmdid = (window._mmdid || 0) + 1);
                var box = document.createElement('div');
                box.className = 'mermaid';
                holder.textContent = '';
                holder.appendChild(box);
                mmd.render(id, src).then(function (r) {
                    box.innerHTML = r.svg;
                }).catch(function (e) {
                    holder.textContent = 'Mermaid render error: ' + (e && e.message || e);
                });
            }).catch(function (e) {
                holder.textContent = 'Mermaid library unavailable.';
            });
        });
    }


    /* ===== Obsidian Callout 提示块：> [!type] 标题 → 官方风格彩色卡片 ===== */
    // 类型 → [颜色, 图标path]；颜色取 Obsidian 官方色板近似值，图标为 Lucide 线条路径
    var CALLOUT_TYPES = {
        note: ['#086ddd', 'M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z'],
        info: ['#086ddd', 'M12 22a10 10 0 1 0-10-10 10 10 0 0 0 10 10Zm0-14v8m0-12h.01'],
        todo: ['#086ddd', 'm9 11 3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'],
        abstract: ['#00bfbc', 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'],
        summary: ['#00bfbc', 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'],
        tldr: ['#00bfbc', 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'],
        tip: ['#00bdd0', 'M9 18h6m-5 4h4m-3.09-8c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8a6 6 0 0 0-12 0c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14Z'],
        hint: ['#00bdd0', 'M9 18h6m-5 4h4m-3.09-8c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8a6 6 0 0 0-12 0c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14Z'],
        important: ['#00bdd0', 'M9 18h6m-5 4h4m-3.09-8c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8a6 6 0 0 0-12 0c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14Z'],
        success: ['#08b94e', 'M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3'],
        check: ['#08b94e', 'M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3'],
        done: ['#08b94e', 'M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3'],
        question: ['#ec7500', 'M12 22a10 10 0 1 0-10-10 10 10 0 0 0 10 10ZM9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3m0 3h.01'],
        help: ['#ec7500', 'M12 22a10 10 0 1 0-10-10 10 10 0 0 0 10 10ZM9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3m0 3h.01'],
        faq: ['#ec7500', 'M12 22a10 10 0 1 0-10-10 10 10 0 0 0 10 10ZM9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3m0 3h.01'],
        warning: ['#e0ac00', 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3ZM12 9v4m0 4h.01'],
        caution: ['#e0ac00', 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3ZM12 9v4m0 4h.01'],
        attention: ['#e0ac00', 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3ZM12 9v4m0 4h.01'],
        danger: ['#e93147', 'M7.86 2h8.28L22 7.86v8.28L16.14 22H7.86L2 16.14V7.86L7.86 2ZM12 8v4m0 4h.01'],
        error: ['#e93147', 'M7.86 2h8.28L22 7.86v8.28L16.14 22H7.86L2 16.14V7.86L7.86 2ZM12 8v4m0 4h.01'],
        failure: ['#e93147', 'M7.86 2h8.28L22 7.86v8.28L16.14 22H7.86L2 16.14V7.86L7.86 2ZM12 8v4m0 4h.01'],
        fail: ['#e93147', 'M7.86 2h8.28L22 7.86v8.28L16.14 22H7.86L2 16.14V7.86L7.86 2ZM12 8v4m0 4h.01'],
        missing: ['#e93147', 'M7.86 2h8.28L22 7.86v8.28L16.14 22H7.86L2 16.14V7.86L7.86 2ZM12 8v4m0 4h.01'],
        bug: ['#e93147', 'M7.86 2h8.28L22 7.86v8.28L16.14 22H7.86L2 16.14V7.86L7.86 2ZM12 8v4m0 4h.01'],
        example: ['#7852ee', 'm7.5 4.27 9 5.15M21 8a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 8V6a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 6Z'],
        quote: ['#808080', '']
    };
    function transformCallouts(root) {
        [].forEach.call(root.querySelectorAll('blockquote'), function (bq) {
            var firstP = bq.querySelector('p');
            if (!firstP || !firstP.firstChild) return;
            var tn = firstP.firstChild.nodeType === 3 ? firstP.firstChild : null;
            if (!tn) return;
            // 头部形如 [!type]± 标题（- 默认折叠 / + 默认展开；官方标记在 ] 前，此处兼容 ] 后写法）
            var m = /^\s*\[!([\w-]+?)([+-]?)\][ \t]*/.exec(tn.nodeValue);
            if (!m) return;
            var type = m[1].toLowerCase();
            var cfg = CALLOUT_TYPES[type] || CALLOUT_TYPES.note;
            var fold = m[2];
            var title = tn.nodeValue.slice(m[0].length).trim();
            // 容错：] 后缀折叠标记（后随空白才认定，避免误吃 "-xxx" 正文）
            if (!fold && /^[+-][ \t]/.test(title)) {
                fold = title.charAt(0);
                title = title.slice(1).trim();
            }
            if (!title) title = type.charAt(0).toUpperCase() + type.slice(1);
            // 首行整行是标题（Obsidian：正文从下一行开始）——标记连同标题文本一起摘除，
            // 否则标题会在内容区重复出现；随后的 <br> 一并清掉，避免留空行
            tn.nodeValue = '';
            var nbr = tn.nextSibling;
            if (nbr && nbr.nodeType === 1 && nbr.tagName === 'BR') nbr.parentNode.removeChild(nbr);
            var div = document.createElement('div');
            div.className = 'callout callout-' + type + (fold === '-' ? ' is-collapsed' : '');
            div.style.setProperty('--callout-color', cfg[0]);
            var head = document.createElement('div');
            head.className = 'callout-title';
            var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            icon.setAttribute('viewBox', '0 0 24 24');
            icon.setAttribute('fill', 'none');
            icon.setAttribute('stroke', 'currentColor');
            icon.setAttribute('stroke-width', '2');
            icon.setAttribute('stroke-linecap', 'round');
            icon.setAttribute('stroke-linejoin', 'round');
            icon.innerHTML = '<path d="' + cfg[1] + '"/>';
            head.appendChild(icon);
            var ttl = document.createElement('span');
            ttl.textContent = title;
            head.appendChild(ttl);
            div.appendChild(head);
            var content = document.createElement('div');
            content.className = 'callout-content';
            while (bq.firstChild) content.appendChild(bq.firstChild);
            // 单行 callout（只有标题没有正文）：清掉搬进来的空 <p>
            [].forEach.call(content.querySelectorAll('p'), function (p) {
                if (!p.textContent.trim() && !p.querySelector('img,video,audio,input,iframe,svg')) p.parentNode.removeChild(p);
            });
            div.appendChild(content);
            bq.parentNode.replaceChild(div, bq);
            if (fold !== '') {
                div.classList.add('callout-foldable');
                head.addEventListener('click', function () { div.classList.toggle('is-collapsed'); });
            }
        });
    }
    // 块 ID：块级元素末尾 " ^id" 是不可见元数据——摘除文本并在元素上挂 data-block-id 锚点（供 [[..#^id]] 定位/嵌入）。
    // 相邻行会被 marked 合并进同一 <p>（中间仅 <br>），故扫描块内所有文本节点，命中首个 "行尾 ^id" 即止
    function applyBlockIds(scope) {
        scope.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, blockquote').forEach(function (el) {
            if (el.dataset.blockId) return;
            var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
            var tn;
            while ((tn = walker.nextNode())) {
                var host = tn.parentElement;
                if (host && host.closest && host.closest('pre, code, .ob-math')) continue;
                // \u2011（不换行连字符）与 '-' 同义：Obsidian 粘贴的块ID 可能带它
                var m = /[ \t]\^([\w\u2011-]+)[ \t]*$/.exec(tn.nodeValue);
                if (!m) continue;
                tn.nodeValue = tn.nodeValue.slice(0, m.index);
                el.dataset.blockId = m[1].replace(/\u2011/g, '-');
                break;
            }
        });
    }

    /* 数学公式：还原阶段生成 .ob-math 空元素（data-tex 存 base64），此处统一 KaTeX 渲染 */
    /* KaTeX 按需加载：页面默认不加载 katex；首次遇到公式才注入 katex.min.css（/assets/katex-fonts
       同源自托管字体）+ katex.min.js。加载期间以原始 LaTeX 占位，加载完成后对根节点统一重渲染 */
    var _katexPromise = null;
    function loadKatexLib() {
        if (window.katex) return Promise.resolve(window.katex);
        if (!_katexPromise) {
            _katexPromise = new Promise(function (resolve, reject) {
                var css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = '/assets/katex.min.css';
                document.head.appendChild(css);
                var s = document.createElement('script');
                s.src = '/assets/katex.min.js';
                s.onload = function () { resolve(window.katex); };
                s.onerror = function () { _katexPromise = null; reject(new Error('katex load failed')); };
                document.head.appendChild(s);
            });
        }
        return _katexPromise;
    }
    function renderMath(root) {
        var spans = root.querySelectorAll('span.ob-math');
        if (!spans.length) return;
        if (!window.katex) {
            spans.forEach(function (el) {
                if (el.dataset.rendered) return;
                try { el.textContent = b64d(el.dataset.tex) || el.dataset.tex; } catch (e) {}
            });
            loadKatexLib().then(function () { renderMath(root); }).catch(function () {});
            return;
        }
        spans.forEach(function (el) {
            if (el.dataset.rendered) return;
            el.dataset.rendered = '1';
            var tex;
            try { tex = b64d(el.dataset.tex); } catch (e) { return; }
            try {
                katex.render(tex, el, { displayMode: el.dataset.display === '1', throwOnError: false });
                if (el.dataset.display === '1') el.classList.add('ob-math-block');
            } catch (e) { el.textContent = tex; }
        });
    }

    window.BP_Render = {
        esc: esc, b64e: b64e, b64d: b64d,
        markdown: mdToHtml,
        toc: renderToc,
        codeCopy: addCodeCopy, codeLines: addCodeLineNumbers, copyFallback: fallbackCopy,
        hljsLoad: loadHighlightLib, hljsApply: applyHighlight,
        mermaidLoad: loadMermaidLib, mermaid: renderMermaid,
        callouts: transformCallouts, blockIds: applyBlockIds,
        math: renderMath, mathLoad: loadKatexLib
    };
})();
