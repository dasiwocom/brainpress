# BrainPress 开发规范

> Obsidian 写笔记 → 渲染成可浏览/搜索/AI 问答的自托管知识库网站。
> 纯 PHP（无数据库、无 Node 构建），入口 `index.php`。

---

## 1. 本地运行（Debian）

```bash
./start.sh          # 启动/重启，默认 http://127.0.0.1:8080
./start.sh stop     # 停止
```

- 依赖：PHP 8.4 CLI + php-mbstring 扩展（搜索必需）+ curl
- `router.php`：给 `php -S` 用的路由脚本，模拟生产 nginx 重写规则（见第 6 节）
- 正式部署按 README 配 nginx

## 2. 架构速记

| 文件 | 作用 |
|------|------|
| index.php（~3500 行） | 前台全部逻辑：路由、SSR、WebDAV(/dav/)、API、内联 CSS/JS |
| admin.php | 后台管理 |
| functions.php | 配置加载（PANEL_DIR=项目根）、is_excluded、scan_tree、tree_to_md |
| config.json | 全部配置含密钥；`ai_enabled` 为前端 AI 按钮/面板总开关 |
| vault/ | 站点笔记内容；URL 路径不含 `/vault/` 前缀 |

关键机制：

- 页面路由基于 REQUEST_URI，SPA 切换 + SSR 直达两种模式
- 目录树：`tree_to_md(scan_tree())` 生成 Markdown 内联进页面 → 前端 marked.js 渲染进抽屉 `#front-drawer-md`，再克隆到左栏 `#left-drawer-md`（事件委托）
- 移动端断点 **768px**（桌面 ≥769px）

## 3. 前台布局规范（2026-08-23 定稿）

### 3.1 三栏结构

- 左 = 站点目录树（33.33%）｜ 中 = 正文 ｜ 右 = 本文 TOC
- `#left-sidebar`（aside）：桌面常驻、内部滚动；移动端 display:none 走全屏抽屉
- 汉堡按钮：桌面折叠/展开左栏（`body.sidebar-collapsed`）；移动端开全屏抽屉
- 中栏 `.doc-main` 上限 **33.33vw**：有右侧目录时两栏弹性等分；无目录时右边留空
  - 踩坑：max-width 用百分比会相对父级 `.doc-wrap`(≈66vw) 解析，把中栏压到 ~22vw，必须用 vw

### 3.2 AI 面板（#ai-view）

- 顶栏 `#vp-ai-btn` 切换；与搜索面板互斥；Esc 关闭
- 桌面端：占据左侧目录栏位置（fixed top:56px、left:0、宽 33.33%），header 紧贴导航栏下沿
- 与左栏同一逻辑：`body.ai-open` 时目录树让位；打开时若左栏已折叠自动展开；汉堡折叠时整栏连聊天一起收起
- 移动端：保持原导航下方弹出全屏面板

### 3.3 目录树侧栏（#left-sidebar）

- flex 列布局：`.ai-header`（"Contents"）固定贴导航栏下沿，`#left-drawer-md` 独立滚动
- 标题条与 AI 面板标题同款同类（复用 `.ai-header`），边距一致（左 20 / 右 12），文字与分隔线完全对齐
  - 踩坑：sticky 在 Firefox 下会从自然位置再下推一个 top 值（56→112px），弃用 sticky 改固定 flex 结构

### 3.4 Graph 页（#graph-view）

- fixed 铺中+右两栏：`body:not(.sidebar-collapsed) .graph-view { left:33.33% }`，折叠时回 left:0 铺满
- `openGraph()` 里 `setTopHidden(false)` 强制显示顶栏（否则滚动隐藏态下打开会留 56px 空档）

### 3.5 GRAPH-VIEW 菜单条目

- 位于目录树末尾：`$frontMenuMd` 拼接 `- [Graph-View](/graph)`（index.php 搜索 GRAPH-VIEW）
- 渲染后 JS 给其 li 加 `has-children` 类并插入同款箭头 → 与文件夹行（如 Visual-Knowledge）样式完全一致（17px/700/--vp-c-text-1 + chevron）
- 文字继承规则：`.drawer-md li.has-children > a[href="/graph"] { font-size:inherit ... }`
- 点击走叶子链接分支直接 `openGraph()`，无子树不参与折叠

### 3.6 Excalidraw / PDF

- Excalidraw：doc-wrap 用 flex、只占中栏、右栏留空（隐藏 toc-panel）
- PDF：openPdf 同样隐藏 toc-panel；fitScale 测 `.doc-main` 宽度（测 .doc-wrap 会超中栏）

## 4. dev 路由与静态资源（router.php）

镜像生产 nginx 两点：

1. 敏感文件（config.json/.env/.bak 等）一律 404
2. **`/vault/` 下所有真实文件直出原始字节（含 .md/.pdf）**——对应 nginx `location ^~ /vault/ {}`（前缀匹配优先于正则）。pdf.js 依赖 `/vault/*.pdf` 原始流；此前 .pdf 进了 index.php 被当免前缀路由解析导致 404
3. `/vault/` 外的 .md/.json 及免前缀 .pdf/.excalidraw.md URL → index.php（SSR 渲染/阅读器页）
4. 路径经 rawurldecode 再判文件，支持中文/空格文件名

## 5. 测试要点

- 测试文章：`/Website-Framework/Introduction.md`（不带 /vault/ 前缀！）
- 测试绘图/PDF：`/Visual-Knowledge/` 下
- 改 PHP 后必须 `php -l index.php` 验语法；改 JS/CSS 后刷新浏览器验证
- 可用 headless Firefox + selenium 实测元素几何（本项目多次靠它定位问题）

## 6. 强制规则

- 项目内容/文案必须全英文
- 每次更新写入本文档「变更日志」记录详情
- 日夜模式保留原有颜色变量，不新增颜色样式
- 页面干净整洁：不允许 emoji，布局整齐排列铺满
- 不要动 `vault/` 里的用户笔记内容（除非明确要求）
- 用户中文交流，回复保持简洁

---

## 变更日志

### 2026-08-23（晚）
- AI 设置改为 OpenAI 兼容：config 新增 `ai_api_base`（空 = 回退 `https://api.deepseek.com`），index.php /api/ask 两处 curl 改拼 `{base}/chat/completions`；admin AI 视图加 "API base URL" 输入框，文案去掉 DeepSeek 专属表述（支持 NewAPI/one-api 等网关）
- admin 新增 Agent 视图：只读展示接入信息——Base URL（location.origin）、Bearer token（同 Site → API token）、7 个端点清单（list/article-list/file/search/ask/note POST+DELETE）与可复制的 curl 示例；写 API 需 Bearer token（空 = 禁用），读接口公开
- Graph-View 条目按要求去掉箭头 SVG：保留 has-children 类获得文件夹行排版（17px/700/rgb(58,58,58)），不插入 dir-arrow、不参与折叠
- 改动前备份：`brainpress/backups/2026-08-23-pre-openai-ai/`（index.php/admin.php/config.json/functions.php/router.php）
- 当前 config 实况：`ai_api_key` 与 `api_token` 均为空 → /api/ask 正确提示未配置；Agent 视图 token 显示 "(not set)"；启用需在 admin 分别填入
- 本地推理支持：ai_api_key 允许为空（Ollama/LM Studio 等无需鉴权，空 key 不发 Authorization 头）；curl 超时 60→120s（CPU 推理慢）；admin 文案给出两种 base 示例——云网关 `https://api.deepseek.com`、Ollama `http://127.0.0.1:11434/v1`（OpenAI 兼容路径必须含 /v1，完整 URL = {base}/chat/completions）；注意 AI 由 PHP 服务端 curl 发起，地址须服务器可达
- 搁置项：Hermes Agent / OpenClaw / Codex 等 agent 框架的专项接入暂不做（用户担心各家不统一）；现有 Agent 视图的通用 REST + Bearer 方案本身就是统一入口，任何能发 HTTP 请求的 agent 都能用，将来要深化再议
- API token 去重：Site 视图移除 "API token" 输入框，改放 Agent 视图（"Bearer token"，同一字段 config.api_token）；token 失焦保存后自动刷新视图内 curl 示例

### 2026-08-23（深夜）三栏布局重构
- 桌面端改为三栏模型：**左轨道（目录树/AI 双页签）| 中间内容画布 | 右轨道（TOC 镜像）**。取消左栏折叠（汉堡按钮在桌面端改为页签切换：AI 开着点菜单=关 AI 回树）；`sidebar-collapsed` 相关 CSS 全部删除
- CSS 变量（index.php :root）：`--vp-content-w` 由后台 `config.content_width` 服务端回显（PHP clamp 480–1600，默认 840）；`--gutter:48px`；`--rail-w:min(calc((100vw - var(--vp-content-w))/2 - var(--gutter) - var(--sbw,0px)), var(--rail-cap))`，`--rail-cap:600px`。左右轨道 flex:0 0 var(--rail-w) 永远等宽 ⇒ 内容相对视口精确居中；超出封顶后多余空间成对称外留白
- 内容画布：`.doc-wrap/.archive-flex { width:min(calc(var(--vp-content-w) + 2*var(--gutter)), 100%); padding:0 var(--gutter); margin:0 auto; translate:calc(var(--sbw,0px)/2) 0 }`。桌面 #content 自身水平 padding 归零（留白由画布自带，避免吃掉中栏度量）。`.md`（基础 max-width:760px）桌面端加 auto margin 居中
- **滚动条补偿**：#content 内部滚动条会让居中偏左半个滚动条宽。JS 测量 `offsetWidth-clientWidth` 写入 `--sbw`（ResizeObserver 跟踪），画布 translate 右移 sbw/2、轨道公式减去 sbw —— 实测 offset 0.0
- TOC 移位：`#toc-panel/#home-toc-panel` 从 .doc-wrap/.archive-flex 内移出，放进新 `<aside id="right-sidebar">`（#main 之后）；分割线挂在 `.toc-panel` 自己身上（空白态轨道无悬空线）；移动端 max-width:768 直接 `#left-sidebar,#right-sidebar{display:none!important}`
- 紧凑档（769–1399）：右轨隐藏（TOC 先让位）、左轨固定 280px、内容在剩余空间居中不足则压缩
- Graph 页：`#content .graph-view { left:var(--rail-w) }`（注意特异性必须高于基础规则——同特异性时后声明胜出曾踩坑）；AI 面板 width 同 var(--rail-w)，与树几何完全一致
- PDF 自适应：`.doc-main` 挂 ResizeObserver → debounce pdfRefit（窗口缩放/后台调宽度自动重适配）；Excalidraw SVG 内联 width:100% 天然跟随无需处理
- 后台 Site 视图新增 "Content width (px)" 输入框；POST 空=保持现值、数值夹 480–1600；admin.js payload/restore/blurIds 接线；版本号 v=20260823f
- selenium 实测（1920×1080）：轨道 480/480 等宽、doc-wrap cx 与 md 列 cx 相对视口 offset 均 0.0；graph 左缘贴齐轨道右缘；紧凑档/移动端行为符合预期；PDF 画布 840 精确铺满

### 2026-08-23（深夜 II）三栏重构回归修复（用户反馈三项）
- **主页双 CONTENTS**：TOC 移入右轨后两个面板（文章 `#toc-panel` / 首页 `#home-toc-panel`）不再随父容器隐匿。修复：`.toc-panel` 基础样式改默认 `display:none`，两处显示路径（renderToc/renderHomeToc）显式 `display:'block'`——互斥由"只显式点亮自己"保证
- **graph/excalidraw 页 TOC 泄漏**：`enterApp()` 缺 SSR_GRAPH/SSR_EXCALIDRAW 分支 → 回落主页路径复显 archive-view，MutationObserver 联动点亮首页 TOC（图谱页右轨出现 CONTENTS）。修复：补两个 SSR 早退分支（不渲染主页）；openGraph 与 renderExcalidraw 视图切换处显式隐藏两个 TOC 面板（双保险）。教训：右轨常驻后，任何视图切换都必须考虑两个 TOC 的状态
- **Excalidraw 页显示乱码字母**：vault 里 `Visual-Knowledge/Excalidraw.md` 是丢了 `.excalidraw` 后缀的 Obsidian 绘画文件（excalidraw-plugin 标记 + compressed-json 块），按扩展名判定走 md 渲染 → 压缩数据渲染成一屏字母。修复：改为内容特征判定（扩展名 `.excalidraw.md` **或** 内容同时含 `excalidraw-plugin` 与 `compressed-json`），服务端 SSR 合并分支 + 客户端 selectFile 取到内容后再路由；删除死代码 openExcalidraw()
- **AI 面板盖住搜索页**：z-index ai-view(310) > search-view(300)，AI 开着点搜索左半区被盖。修复：openSearch() 先 toggleAi(false)
- 回归实测：home/article/graph(SSR+SPA)/excalidraw(SSR+树点击)/pdf 全部 TOC 状态正确、svg 正常渲染、居中 offset 0.0

### 2026-08-23（深夜 III）Custom Path 与主 vault 平权（桌面化前置）
- 需求：项目将用 Electron 打包成 Win/Linux 桌面应用，后台 Mounts → Custom Path 填的目录（如 `/home/debian/Everything/vault`）必须与项目自带 `vault/` 同级可用。此前 custom_paths 只被 /api/list 和 /api/file 消费，左树/SSR 直达/搜索/图谱/AI 全部硬编码 `PANEL_DIR.'/vault'` → 界面上"看不见挂载"
- **functions.php 新增四个共享助手**：`custom_mount_roots()`（启用中的挂载，realpath 规范化，区分目录/单 .md 文件）、`resolve_vault_file($rel)`（相对路径→绝对路径：主 vault→挂载目录→单文件挂载）、`merge_custom_trees($tree)`（挂载扫描成树并入主树，同名主 vault 优先）、`collect_all_md_files()`（多根收集 md + 同名路径去重）
- **接入点全量替换**：/api/list 内联合并块→merge_custom_trees；搜索(/api/search)、图谱(/api/graph)、AI 检索(/api/ask)、文章清单、llms.txt 五处 collect_md_files→collect_all_md_files 且内容读取走 resolve_vault_file；SSR 文章/Excalidraw/PDF 分支改统一解析；首页文章 home_article 解析加挂载回退；FRONT_MENU_MD（左侧树源头）合并挂载树——**界面从此可见可点**
- **router.php**：`/vault/<rel>` 主 vault 未命中时从启用挂载流式输出（md 内嵌图片、pdf.js 字节流依赖此路径）；GET only、realpath 防穿越、扩展名 MIME 白名单
- 写 API（/api/note）刻意保持仅主 vault：外部目录可能只读或属用户私有
- **修复老 bug**：原 /api/list 自定义合并的去重逻辑把每个挂载"自己的第一层文件"也过滤掉（先记名后过滤同一批）→ 挂载目录全是空壳。新语义：只对先前已占用的名字去重（主树+更早挂载），不碰自己的子节点
- **收紧 Excalidraw 内容判定**：宽松子串匹配会把"介绍 excalidraw 的文档"误判为绘画（本项目开发规范文档正文含这两个词→整页被当绘画打开）。改为 `excalidraw-plugin:`（frontmatter 冒号形式）+ ```` ```compressed-json ```` 真实围栏行同时命中；服务端 SSR 与客户端 selectFile 两处同步
- 实测：左树出现 10 个挂载顶层目录且子节点完整；点击 Prompts/BrainPress开发规范.md 正常渲染（7690 字符）；SSR 直达 URL 200；搜索/图谱(105 节点)/article-list/llms.txt 均含挂载内容；挂载目录图片经 /vault/ 兜底 200 image/png；真 Excalidraw 绘画渲染不受影响；主 vault 全回归通过；config.json 穿越攻击 404
- 菜单收敛（5 项）：AI + Agent 合并为一个 AI 视图，用 Mounts 同款滑动选择栏双档切换（Chat Model / Agent API，CSS 加 `.toggle.two` 变体，滑块宽 50%）；Hide 整体并入 Tree 视图底部（"Hidden paths" 区块）；菜单剩 Mounts / Preferences / Site / AI / Graph / Tree
- 自定义访问路径（已实现，方案按用户确认）：Site 视图 "Admin path alias"（config.admin_path）+ Graph 管理页 "Graph path alias"（config.graph_path），逻辑完全一致——访问别名 URL 时 index.php 302 跳转真实路由（/admin、/graph，页面本体不变）；前台目录树按路径深度注入同名条目到**已存在的真实文件夹**内（排在现有文档后），样式与普通文档条目完全一致；graph 配置别名后树末尾的旧 Graph-View 条目不再生成（别名留空则回退旧行为）；点击整页跳转（移动抽屉直绑、桌面左栏委托各处理一次）
- 注入匹配关键点：折叠处理会给目录 li 首部插入箭头 SVG → firstChild 匹配失效会建出重复文件夹；必须用 buildDirPaths 的 data-path（或遍历文本节点）取目录名；同名真实文章已存在时跳过注入防劫持链接
- 当前 config：`admin_path` = `Visual-Knowledge/admin`、`graph_path` = `Visual-Knowledge/graph`；selenium 实测 VK 子项顺序 = 真实文档…, admin, graph，点击 graph 条目整页跳到 /graph ✓

### 2026-08-23（深夜 IV）Tree 页四列表双写法：相对=主 vault，绝对=挂载
- 需求：Tree 视图的置顶目录/置顶文章/展开目录/隐藏路径此前只作用于主 vault（挂载扫描根本没接这些配置）；用户确认四个列表全部升级为"双写法"——**相对路径（或裸名字）只匹配主 vault；绝对路径匹配自定义挂载里的对应条目**，同名歧义由此消解
- functions.php 新增判定助手：`setting_is_absolute()`（/ 开头或 Windows 盘符）、`abs_entry_hits()`（绝对条目命中判定：精确或前缀=整棵子树，两侧统一正斜杠）、`abs_under_root()`（绝对路径→所属根内相对坐标）
- **隐藏**：`merge_custom_trees()` / `collect_all_md_files()` 内对挂载来源按绝对条目过滤（新增 `filter_abs_hidden()` 递归滤树）；单文件挂载被绝对条目命中则整个跳过；主 vault 仍走原 is_excluded 相对匹配——两套互不越界
- **置顶**：绝对条目换算成"该挂载内的相对坐标"后传给 scan_tree 原生排序参数 → 挂载条目获得与主 vault 完全同款的语义（目录在本层排最前、文章在所属目录排最前）；按 is_dir/is_file 自动归类到 dirs/articles 列表
- **展开**：新增 `expand_effective_entries()` 服务端换算（含主 vault 绝对路径兜底），index.php 的 FRONT_EXPANDED_DIRS 输出有效条目而非原始配置——抽屉节点相对路径唯一，前端 `p===d || p.indexOf(d+'/')===0` 匹配逻辑零改动
- admin.php Tree 视图文案与 placeholder 全部改为双写法示例（如 `/mnt/vault/Mechanic`）
- 实测（临时改 config 四条绝对条目）：Mechanic 整棵从树+搜索消失；Coding 升至挂载区首位；BrainPress开发规范.md 在 Prompts 内排最前；FRONT_EXPANDED_DIRS 正确换算出 `Mechanic/统筹方法及补充`；主 vault 顺序不受影响；恢复 config 后 Mechanic 回归 ✓；php -l 三文件通过

### 2026-08-23（深夜 V）部署文档：宝塔伪静态一键部署 + /graph 规则补漏
- **用户生产站 404 根因**：目录树里的 dashboard(admin)/graphview(graph) 是虚拟路由，磁盘上无对应文件——dev 靠 router.php 模拟重写所以正常，生产 nginx 没有规则时请求直接 404，PHP 未接手
- deployment.md 补 `/graph` 规则（深夜 III 加图谱别名时漏同步文档）：`location ^~ /graph { try_files $uri /index.php?$query_string; ... }`，验证清单加 graph 一行
- 新增「One-paste setup for BT panel (宝塔)」一节：部署=传文件+伪静态贴一次，不用手改 vhost——上传清单（router.php 可不传）→ chown www:www → 伪静态整段粘贴（含 /admin、/graph、/api、/dav、敏感文件拦截在 .md 规则之前、/vault/ 直出、.md/.pdf 渲染路由）→ enable-php-83.conf 数字对准实际 PHP 版本 → 首次开 /admin 设密码
- 关键说明写进文档：宝塔伪静态是 include 进 server 块的独立 rewrite 文件，location 块原样可用且面板升级/重存设置不会冲掉；之前手动改过配置文件的要先删掉旧 location 再贴伪静态（重复定义 nginx 报错）
- 用户已按此方式在生产站部署成功

### 2026-08-23（深夜 VI）生产站树入口 404：伪静态缺万能兜底
- **现象**：/admin /graph 直达正常，但前台目录树的 Dashboard/Graphview 入口点击 404
- **根因**：别名条目 href = `/Visual-Knowledge/Dashboard` 这类无扩展名 URL，磁盘上无真实文件；伪静态只有 ^~ 前缀规则没有兜底 location → nginx 静态查找失败直接 404，PHP 未接手（dev 的 router.php 是全量转 index.php 所以本地正常）
- **修复**：伪静态补一行 `location / { try_files $uri $uri/ /index.php?$query_string; }`——正则规则（.md/.pdf/BT 默认静态缓存）与 ^~ 规则（/vault/、/admin 等）优先级都高于它，真实文件照常直出，仅"不存在的免后缀 URL"落到 PHP 渲染/302
- 排查过程沉淀：`nginx -t` 看语法 → `cat rewrite conf` 看是否保存 → `grep include vhost` 看 include 行 → `curl -skI -H Host https://127.0.0.1/...` 本机测 HTTPS 绕过 CDN/浏览器缓存（本次即此法确认服务端 200，问题在浏览器/CDN 缓存层）
- deployment.md 两处规则块（nginx essentials + 宝塔一键粘贴）均已加兜底行；vault 已被用户重组为 BrainPress/ 单目录结构，文档随迁

### 2026-08-23
- AI 面板改版：桌面端占左栏位（原居中下拉浮层），与左栏同一折叠逻辑，header 贴顶
- 内容列宽：中栏上限 33.33vw，无目录时右栏留空（修复百分比 max-width 压窄中栏）
- Graph 页：改占中+右两栏、左栏可开合（原全屏覆盖盖住左栏）；openGraph 强制显示顶栏
- Excalidraw/PDF：隐藏 toc-panel；fitScale 改测 doc-main；绘画一度改占中+右后按要求回退为只占中栏
- router.php：/vault/ 全量静态直出修复 pdf-test 404；加 rawurldecode
- GRAPH-VIEW 条目：树顶移至树末尾，命名 GRAPH-VIEW → Graph-View，样式改成与文件夹行完全同款（has-children + 箭头）
- 目录树加 "Contents" 顶部标题条（与 AI 面板标题对齐）；侧栏改 flex 结构规避 Firefox sticky 偏移
