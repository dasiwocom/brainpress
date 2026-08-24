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

### 2026-08-26（六）项目文档同步：五处过时描述对齐当前行为
- introduction.md：核心特性表 PDF 阅读器一行重写（旧文案"支持夜间模式/night-mode inversion"已不符实现——现深色保持白纸不反色；新文案为大纲/页码导航/缩放/全屏、内嵌与直开共用界面）；新增"Obsidian 语法完整支持"特性行（Callout/KaTeX/高亮/嵌套标签/块ID/脚注/双链嵌入）；Next Steps 移除已完成项"笔记双链支持"
- getting-started.md：Add content 一节 PDF 描述更新（旧：page turn/zoom/night mode/fullscreen → 新：outline/page navigation/zoom presets/fullscreen）并补 `![[book.pdf]]` 嵌入与 `[[book.pdf#page=3]]` 深链说明
- configuration.md：Behavior notes 首条重写——树配置四个列表（exclude/pinned×2/expanded）的绝对路径双写法语义（相对=仅主 vault，绝对=匹配 custom_paths 挂载整棵子树）
- api-reference.md：Server-rendered pages 章节补 `.pdf` URL 行为（返回预载阅读器的应用壳而非触发下载）

### 2026-08-26（五）PDF 阅读器统一重构：直开与内嵌共用组件 + 页面铺满零间距
- **致命修复（用户实测反馈"嵌入一片空白"）**：renderSlot 建画布后漏调 page.render()——画布只是空元素，页面永远空白；此前自动化测试只断言几何尺寸（canvas 存在/宽高/铺满）未验证像素，全部绿灯造成误判。现补上 `page.render({canvasContext, viewport})`，且 `done` 类改在渲染 promise 完成后才加、失败回滚 rendered 标记允许重试；测试升级为全量像素扫描断言暗像素数（嵌入 pdf.pdf 8110 / 直开 multipage 数千），几何假绿不可能再发生
- **用户反馈**：① 直接访问 /xxx.pdf 的旧版单页阅读器（‹ 1/N ›、±20% 步进、⛶）与笔记内嵌的 Obsidian 式工具栏不一致；② 嵌入框内页面四周留白（padding/gap/阴影），要求 PDF 完全填满框体；③ 反馈"md 里嵌入没正常显示"——headless 探针显示嵌入渲染正常（760px 框、canvas 722×1022），疑为浏览器缓存旧版中间态，强刷即可
- **共享组件 buildPdfPane(el, doc, opts)**：从 mountPdfEmbed 抽出全部骨架与交互（工具栏/懒渲染/滚动跟随页码/缩放下拉/大纲抽屉/全屏/ResizeObserver），嵌入与直开同构调用；opts.full 直开模式（吃满视口高 calc(100vh - 140px)、无边框圆角、键盘翻页常驻），opts.sub/#page=N 与 opts.initPage 初始跳转双入口
- **openPdf 重写**：只做视图切换+标题，加载后建 host 调 buildPdfPane；删除旧单页机器约 160 行死代码（fitScale/pdfRefit/renderPdfPage/makePdfDarkCopy/pdfImgOrig/pdfImgDark/pdfDoc/pdfPageNum/pdfScale 模块变量/RO/__pdfRoT）
- **主题切换像素副本 hack 移除**：applyTheme 里针对旧 #pdf-canvas 的 putImageData 特殊分支与 html.dark invert 滤镜一并删除——阅读器保持白纸不反色（Obsidian 同款），切主题走通用逻辑
- **CSS 泛化**：.md 前缀选择器全部改为通用 .ob-pdf（#pdf-view 区新块）；补 .md .ob-embed.ob-pdf 同权重选择器压过 .md .ob-embed.loaded 的边框清除规则（特异性坑）；新增 .ob-pdf-full 变体
- **铺满零间距**：ob-pdf-body padding 0、pages gap 0、availW 改 body.clientWidth 全宽、goPage 去掉 -8 偏移、canvas 改 width:100%/height:auto（不再设 px）、页阴影换 1px 细缝线 border-bottom
- **测试基建升级**：bp_md_test.py 新增自建夹具 _bptest.md（^block-id 块ID + 页内锚 + 跨笔记 data-sub 链接），跑完自动删除——用户实时编辑 md.md 删掉了旧夹具导致三项误报，现与用户文件解耦；missingEmbeds 期望同步更新为唯一 missing ![[test2^block]]（test2.md 已删）；新增 bp_pdf_unify_test.py 19 项断言（嵌入 flush 布局/直开同构翻页/键盘/主题不反色全绿）；bp_md_test.py 回归 ALL PASS

### 2026-08-26（三）侧栏树两处修正：资源目录不再产生裸目录行 + 根级 Dashboard/Graphview 目录化样式（无箭头）
- **用户反馈**：① 目录里没有 md 文档时格式错乱，不会按一级目录正常渲染（如 Attachments 只有媒体/图片）；② Dashboard 和 Graphview 直接填到根 vault 时应与其他一级目录样式相同、只是不需要折叠箭头 SVG
- **裸目录行根因**：tree_to_md 对目录无条件输出行，子项全是被过滤的资源（媒体/图片）时产出没有内嵌 ul 的 li——前端折叠逻辑 `childUl(li)` 判不出目录，既无箭头也不可折叠，样式塌成普通文本行
- **修复**：tree_to_md 递归重构——先渲染子级，子级输出为空则整个目录行跳过（空目录/仅资源目录/深层空壳一律不进菜单；与挂载模式 `$forMount && !$children` 行为对齐）；顺带把图片也从菜单剔除（与媒体同理：嵌入资源非文档，菜单只放文档），树 JSON 数据仍保留供 resolveAsset 按名解析
- **alias-dir 样式**：前端注入完别名条目后新增 markAliasDir 扫描——根层级的 Dashboard/Graphview（含未配 graph_path 时服务端直出的 Graph-View）加 `.alias-dir` 类：字号字重内边距与一级目录行完全一致（17px/700/2px 10px），内链 a 去 padding 继承字号，不加 dir-arrow；点击行为不变（整页跳转）
- **vault 同步适配**：用户 Obsidian 同步把附件重组到根级 Attachments/ 并改名 mp4.mp4——test3.md 引用同步改为 `![[mp4.mp4]]`，resolveAsset 树走自动命中新路径
- **实测**：CLI 单测合成树（空目录/仅媒体/嵌套空壳全跳过、md 保留）；浏览器确认菜单无 Attachments/png、无裸目录行、Dashboard/Graphview 17px/700 无箭头且 /graph 整页跳转 ✓；selenium 回归 17/17

### 2026-08-26（四）PDF 嵌入重构：Obsidian 式操作栏阅读器（用户要求与官方一致）
- **需求**：`![[x.pdf]]` 不再显示未解析链接——Obsidian 的 PDF 嵌入顶部带操作栏、框内滚动翻页，按此逻辑完全重构
- **结构**（与官方阅读器同构）：`.ob-pdf` 容器（clamp 高度 480~920px、圆角边框）= 顶部工具栏（钉住）+ 内部滚动区 + 大纲抽屉
- **工具栏**：大纲开关（doc.getOutline() 为空自动隐藏）｜‹ 页码输入/N › 翻页组｜缩放下拉（Automatic/Page Fit/Page Width/50~400%，pdf.js 同序，± 沿列表步进）｜全屏。滚动跟随：内部滚动时 rAF 节流更新当前页码；页码输入 Enter 跳转；全屏内左右方向键/PgUp/PgDn 翻页
- **渲染**：懒渲染（IntersectionObserver root=内部滚动区，rootMargin 300px）；统一 scale（首页定基准）× DPR×1.5 超采样；缩放/尺寸变化清空重渲染；ResizeObserver 防抖自适应；深色模式保持白纸不反色（Obsidian 同款）
- **配套改动**：① `findAsset()` 从 resolveAsset 抽出（树内严格查找不猜路径），PDF 链接分支用它判定存在性 → `[[手册.pdf#page=3]]` 点击直接开 openPdf 阅读器（openPdf 新增 initPage 参数）；② mountEmbed 新增 .pdf 分支（在笔记嵌入兜底之前）；③ 失败仍走 embedFail→重试闭环
- **验证**：手工构造 5 页 multipage.pdf（python 手写 PDF+xref+FlateDecode）实测：双嵌入独立、5 槽懒渲染、next 后 inp=2 且内部滚动 949px、150% 缩放画布 722→918px 重绘、输入 5 回车跳至 scrollTop 4804、无书签时大纲按钮隐藏、#page=4 直达；md.md 全量回归 29+7 全过

### 2026-08-26（三）md.md 语法矩阵攻坚：致命 splitRef 崩溃 + Callout/数学/高亮/嵌套标签/块ID/表格对齐/任务清单
- **用户反馈**：以重组后的 `vault/Compositions/md.md` 为基准逐项验收，第 11–16 节不渲染、清单多圆点、无 `==高亮==`、表格对齐失效、第 10 节嵌入多个不显示
- **致命根因（连锁一切）**：processObsidian 正则循环把 `splitRef(m[2])` 提到分支外——标签命中时 m[2] 为 undefined → `ref.indexOf` TypeError → 整个遍历中途炸掉：异常点之后全部原样（15 标签/16 块ID）、_docMap 不建、loadTree/retryObsidian 全灭。test2/test3 无标签故从未触发。修复：分支内各自取 m[2]，标签走 m[4]；顺带正则支持嵌套标签 `#a/b/c`
- **代码围栏防污染**：目录树图里的 `# 注释` 会被当标签转链——文本遍历跳过 `pre/code/.ob-math` 子树
- **Callout 提示块**：transformCallouts 把 `> [!type]± 标题` blockquote 转官方风格彩色卡片（18 种类型色板 + Lucide 图标、--callout-color 注入、color-mix 8% 底色、折叠 ± 兼容 ] 前后两种写法、点击标题开合）
- **数学公式**：本地 vendor KaTeX 0.16.11（assets/katex.min.js/css + katex-fonts/，css 内 url 重写 /assets/katex-fonts/）；protectObsidian 新增 `$...$`/`$$...$$` 占位保护（块级先行防吃定界符），还原为 .ob-math[data-tex]（base64 存 attr），processObsidian 里 katex.render；块级 .ob-math-block 居中
- **占位符体系重构**：payload 改无填充 base64url——标准 base64 的 `==` 尾巴会被 ==高亮== 扩展误认成定界符把占位符腰斩（实测嵌入全变 mark 包裹的残片）
- **`==高亮==`**：marked inline 扩展 obHighlight → `<mark>`（黄底亮字暗色适配）
- **块 ID U+2011 兼容**：用户文件用不换行连字符 `^block‑id`——applyBlockIds/jumpToAnchor/自引用嵌入统一 `\u2011→-` 归一化；applyBlockIds 重写为扫描块内所有文本节点（相邻行被 marked 合并进同一 <p> 时 lastChild 不是 ^id 行导致漏摘）
- **表格对齐**：`.md th/td[align=center/right/left]` 显式规则覆盖默认 text-align:left
- **任务清单去点**：`.md li:has(>input[type=checkbox])` 隐藏圆点 + accent-color 品牌色勾选框
- **重试时序竞争（潜伏 bug）**：loadTree 与文章渲染并行，树先到时 retryObsidian 跑在空视图上，之后渲染出的缺失无人补——showArticle 渲染完成后若树已就绪补一次 retryObsidian（幂等）；embedFail 自身在树就绪时 setTimeout(retryObsidian) 闭环（404 回调可能晚于一次性重试）；图片去掉 loading=lazy（离屏图不发请求 onerror 永不触发）
- **KaTeX 资产**：npm registry 拉取 dist（fonts 仅 woff2/woff，636K）
- **Callout 标题重复修复（用户反馈）**：首行 `[!note] 标题` 只摘了标记前缀，标题文本仍留在正文首行——改为整行摘除（含随行 <br>，正文从下一行开始与 Obsidian 一致）；单行 callout 搬运后的空 <p> 一并清理；测试新增 dupTitle/emptyP 断言
- **实测**：php -l ✓；selenium 全量 27+2/29 全过（callout×5 含折叠默认收起、KaTeX 字体生效行内+块级、mark、嵌套标签、U+2011 块ID 锚点、清单无圆点、th 对齐 left/center/right、png 正确路径加载、mp4 readyState=4、canvas SVG、pdf 保持未解析样式、围栏 # 注释不被污染、树导航整页跳转+返回嵌入完好、标题不重复、无空段）

### 2026-08-26（二）mp4 嵌入修复：媒体资源入树 + mountEmbed 统一挂载 + 重试闭环
- **用户反馈**：test3 的 `![[Screencast....mp4]]` 根本不显示——文件一直在 `~/Videos/Screencasts/` 从未入库；复制进 `vault/BrainPress/Attachments/` 后仍缺失
- **两层根因**：① scan_tree 只收 md/image/pdf/canvas，音视频不入树 → resolveAsset 按名解析不到；② SSR 渲染早于树到达时 resolveAsset 落到"同目录兜底"猜错路径 → onerror 兜底 span 没有 data-name → retryObsidian 无法二次修复（死在半路）
- **修复**：functions.php 新增 `is_media()`（扩展名与前端分派列表一致）并收录进树（挂载目录仍排除——无静态路由死链）；`tree_to_md` 跳过媒体条目（侧栏树保持干净）；搜索(.md/.canvas)/图谱(collect_all_md_files) 本就过滤 ✓
- **重构**：嵌入分派逻辑抽成 `mountEmbed(el,name,sub)`（图片/视频/音频/画布/笔记统一入口），processObsidian 只造占位 span（带 data-name/data-sub）；失败兜底统一 `embedFail()`（Obsidian 未解析链接样式 + data-name）；retryObsidian 嵌入段简化为一行 mountEmbed 重调——树就绪后所有 missing[data-name] 都能正确升级（含此前必死的视频/图片路径）
- CSS：媒体包容器后去自身 margin 防叠加（`.ob-embed.loaded > video/audio/img`）
- **实测**：video 元素 src 正确且 readyState=4 可播放；侧栏菜单/图谱数据均无 Screencast 泄漏；selenium 回归 17/17（mp4 改验可播放、pdf 保持未解析样式验证）

### 2026-08-26 Obsidian 语法补全（对照语法手册 + test2/test3）：脚注/块ID/块嵌入/页内锚点/%%注释/音视频嵌入 + 嵌入无缝样式 + 双导航覆盖修复
- **起因**：用户要求对照《Obsidian 完整 Markdown 语法手册》与 test2/test3 找出未支持的语法（PDF 除外）并修复踩到的部分
- **根因级 bug**：`window._docMap` 从未被构建——renderTree() 是归档页重构后的死代码，所有按名字解析的双链/嵌入全部 not-found。新增 `buildDocMap()` 在 loadTree() 拉平后调用
- **树晚于 SSR 渲染**：新增 `retryObsidian()`，loadTree 完成后对 `ob-link-missing[data-link]` / `.ob-embed-missing[data-name]` 二次升级并重跑 renderBacklinks
- **新支持语法**：`%%Obsidian 注释%%`（stripObsidianComments，围栏感知、跨行）；脚注 `[^1]`/`[^1]:`（marked.use 自定义 block/inline 扩展，上标纯数字 + 定义行 "N." 对齐官方；点击滚动用视口相对坐标 scrollMdTo，不能用 hash 锚——会误触文章路由）；块 ID `^gjgj`（applyBlockIds 隐藏尾缀 + data-block-id 锚点）；页内定位 `[[#h2|别名]]` 与跨笔记 `[[笔记#标题/#^块]]`（_pendingAnchor + showArticle 后延迟 jumpToAnchor）；块引用嵌入 `![[笔记#^id]]`（整篇渲染后只留被引块）与自引用 `![[#^id]]`；音频/视频嵌入分支 + onerror 兜底；`.canvas` 内嵌（复用 renderCanvasScene）
- **嵌入样式贴合官方**（用户反馈"全用一个框包起来"）：加载完成后 `.ob-embed.loaded` 无边框无背景无缝内联（Obsidian 笔记/块嵌入即如此）；画布保留细框；缺文件从红色错误框改为官方"未解析链接"样式（灰字虚线下划线显示 `![[原名]]` + title 提示）
- **双导航覆盖修复**：wikilink 点击后视图被旧文章顶回——片段导航同时触发 popstate，而 popstate 处理器只看 pathname（仍是旧文章）→ selectFile 竞态。修复：hash 为文档路由（#dir/x.md）时 popstate 走 handleHash；handleHash 的 p===state.path 幂等守卫保证只发一次请求
- **滚动定位修正**：offsetTop 减法受定位祖先影响不准（实测落点偏 ~214px），jumpToAnchor/脚注跳转统一改 scrollMdTo（getBoundingClientRect 相对坐标 + scrollTop 换算）
- **test2 表格之谜**：`|jofej|jgfe;ja|` + `|--|` 表头 2 列分隔行 1 列，GFM 规范要求单元格数一致才识别为表格 → 渲染为段落是正确行为（Obsidian 同样不识别），非 bug
- **实测**：php -l ✓；node --check 三个内联脚本 ✓；Node 层 marked 管线单测 ✓；selenium headless Firefox 回归 16/16 全过（含 wikilink 导航→返回、脚注滚动入视口、canvas SVG 内嵌、mp4 缺失兜底样式）

### 2026-08-25（六）挂载同级平权重构：不套目录，custom 内容直接铺进顶层（推翻前缀方案）
- **用户反馈**：说过 custom 和 vault 是同级别的，不该给挂载内容套一层目录
- **重构**：删掉整个 URL 前缀机制（mount_url_prefix 助手、剥前缀逻辑全部移除）——挂载根用 `relPrefix=''` 扫描后**逐节点合并进树顶层**（同名目录递归并入、同名文件主 vault 优先），URL 与主 vault 完全同构（`BrainPress/changelog.md`、`Computing/x.md`）
- 连带收益：搜索/graph 输出的挂载内相对路径与树 URL 天然一致了（之前是两套坐标）；保留字撞名问题不复存在（没有合成目录名了）；resolve_vault_file / /api/file / router.php 三处解析回归最简单的直接拼接
- 保留：md/canvas 落回 index.php 渲染（router 兜底）、挂载树过滤图片/PDF 死链与空目录、同名去重主 vault 优先（文件重复用户自己同步管理）
- **实测**：dav off + 整库挂载 on——顶层直接是 Bookmarks/BrainPress/Computing；点击 Computing 文章渲染 ✓、canvas ✓；三个直达 URL 全 200；`/vault/Attachments/*.png` 内嵌图流式 200

### 2026-08-25（五）挂载路径语义修正：保留字 vault 撞名根治 + 挂载树降噪 + /api/file 前缀剥离
- **用户反馈**：只开 custom 关 dav 时显示异常、点击「文件不存在」；并明确语义——dav=项目自带 `vault/`（Obsidian 同步目标），custom=所在机器任意路径（部署在云服务器，本机路径只是开发期测试）
- **三个真问题**：
  1. **`/api/file` 目录挂载没剥 basename 前缀**（与 resolve_vault_file 平行的另一份解析代码）——树里能看到 `vault/xxx.md` 但点击 404。修复：先剥前缀再解析、退回直接拼接，两处同口径
  2. **挂载目录恰好叫 `vault` 时撞站点静态命名空间 `/vault/`**——文章 href 落进静态路由，直达/刷新拿到项目同名副本的原始字节而非渲染页。根治：新增 `mount_url_prefix()`，保留字自动改名 `mount-vault`；merge_custom_trees / resolve_vault_file / /api/file / router.php 静态兜底四处统一走它
  3. **router 挂载兜底把 `.md`/`.canvas` 当原始字节流吐出**（直达变下载）。修复：这两类落回 index.php 渲染成页面，仅流式输出资源文件（图片/pdf 等，内嵌截图因此可用）
- **挂载树降噪**：`scan_tree` 加 `$forMount` 参数——不收录图片/PDF（无静态路由时是死链）、过滤后变空的目录（纯图片的 Attachments）不显示
- **实测**（dav off + 整库挂载 on）：树只剩 md/canvas 条目；SPA 点击 md→渲染、canvas→画布全通；`/mount-vault/BrainPress/changelog.md` 直达 200 进应用；内嵌图片 200 image/png；graph 83 节点来自挂载

### 2026-08-25（四）GraphView 视觉降噪：标签排版 + 屏幕等大 + 分级显示阈值
- **用户反馈**：动画可以了，但文章一多页面脏乱——怀疑是文章名大小粗细、节点间距或显示阈值的问题
- **改动**（三管齐下）：
  - **标签排版**：17px/700 粗体（实际被 `.graph-node text` 覆盖成 11px 混搭）→ 统一 10.5px/600 中等字重、`--vp-c-text-2` 弱化色；加 `paint-order:stroke` 背景色描边光晕（3px）——压在线上依然清晰但不抢戏；删掉冲突的重复 CSS 规则
  - **屏幕等大**：标签原来随世界坐标缩放——缩小时满屏糊字、放大时巨字。改为 transform `translate(0,r+11) scale(1/k)` 反缩放（钳制 0.7~2.2），任何缩放级别字号恒定（applyGraphTransform 每次同步更新，走 simEls 缓存不查 DOM）
  - **分级阈值**：k≥0.75 全部显示，缩小后**全部隐藏**（无例外）——79 篇实测缩到 0.66 时标签全隐
  - 间距微调：斥力 2800→3300、理想链长 95→105、聚类半径 130→140（呼吸感）
- **实测**：初始标签样式全对（10.5px/600/stroke 光晕）；缩放两档后反缩放系数正确（1/k=1.52）、低度数标签隐藏

### 2026-08-25（三）GraphView 物理重写：d3-force 同款 alpha 衰减（Quartz/Obsidian 级丝滑）
- **用户反馈**：咱们的图谱像自己写的渲染，Obsidian/Quartz 丝滑得多、逻辑更好
- **根因**：旧引擎「先同步算 220 轮再画 + 最多 200 帧硬停」——打开即静止死板、拖拽联动生硬；丝滑感的本质是 d3-force 的 **alpha 能量模型**：每帧 alpha 向 target 指数衰减、所有力乘 alpha、velocityDecay 阻尼积分
- **重写**（零依赖不变）：
  - `simAlpha` 衰减曲线 `1-0.001^(1/300)`≈0.023/帧（d3 默认）：满能量开局 → 约 3 秒有机舒展动画 → 自然冷却到 <0.002 停帧（无硬停）；初排降为 45 轮带衰减同步预跑（不空白又不失生命感）
  - 力全部 alpha 缩放：多体斥力 O(n²)、链接弹簧（REST=95）、同目录弱聚类（130 内）、中心引力；阻尼 0.6=d3 velocityDecay 默认
  - **拖拽再加热**：alphaTarget=0.3（冷图快起热 0.15），邻居实时跟随由常驻 tick 渲染（替代旧的 move 里手动 stepOnce）；松手 target 归 0 自然冷却
  - CSS 丝滑补强：.graph-label 加 opacity 过渡、.graph-node 加 will-change:transform（GPU 合成）
  - 删除死代码 updateLinkEls/旧 startSim/stopSim/simRunning
- **实测**：开局可见舒展动画→自然收敛静止；hover 高亮邻居正常；合成拖拽后图仍在滑动（再加热生效）且最终冷却停帧；滚轮缩放光标居中正确；单击节点正常跳转文章（拖拽抑制逻辑保留）

### 2026-08-25（二）修复视图互斥：canvas/excalidraw 切换后同页残留
- **用户反馈**：切换文件时 canvas 和 excalidraw 会同时出现在一个页面，必须刷新才消失
- **根因**：各视图切换代码只藏自己认识的容器——renderExcalidraw 尾部不藏 canvas-view/pdf/graph，finishCanvasView 不藏 excalidraw-view/pdf/graph（openPdf/openGraph/回首页分支同样只顾自己）→ A→B 切换时 A 的 display:block 残留
- **修复**：IIFE 顶部新增 `hideSpecialViews()` 统一隐藏四个特殊视图（pdf-view/excalidraw-view/canvas-view/graph-view），全部切换点改为先调它再显示自己：openPdf、showArticle（替换原 4 行）、openGraph、renderExcalidraw 尾部、finishCanvasView、logo 回首页、handleHash 空分支、popstate 回首页分支
- **坑**：hideSpecialViews 必须在显示本视图**之前**调用（放后面会把刚 show 的自己又藏掉——实测抓到后修正顺序）
- **实测**：SSR excalidraw→canvas→excalidraw→md 四连切，每步仅一个视图可见（探针读 style.display+offsetWidth/Height；offsetParent 对这些容器不可靠）

### 2026-08-25 修复 WebDAV 渲染开关关不干净：SSR 菜单/搜索/图谱漏收主 vault
- **用户反馈**：后台关闭 DAV 渲染开关后目录仍显示，点击才报「文件不存在」
- **根因**：`/api/list` 和 `/api/file` 尊重 `render_webdav`，但另外两处无条件收录主 vault——① SSR 内联菜单树 `$frontTree`（index.php 页面头部直接 scan_tree，不查开关）；② `collect_all_md_files`（搜索 /api/search 与图谱 /api/graph 的语料来源）。于是关=树里还在、点进去被 /api/file 拒绝，表现为「开关无效」
- **修复**：两处都加同一开关判断——`$frontTree` 仅在 render_webdav 开时扫描 vault；collect_all_md_files 跳过主 root。挂载目录走各自 custom_paths 的 on 开关不受影响，MinIO 同理（render_minio）
- **实测**：关=FRONT_MENU_MD 空、/api/list 空树、search 0、graph nodes 0、首页正常渲染无 JS 报错；开=全部恢复（search 2、graph 7、菜单含 BrainPress）

### 2026-08-24（夜）新增 Obsidian Canvas 白板渲染（.canvas）+ 挂载直连修复
- **Canvas 渲染**：`.canvas` 文件（Obsidian Canvas 白板 JSON）现在像 `.md`/`.pdf`/`.excalidraw.md` 一样是一等公民——SSR 直达 URL 与 SPA 树点击双路都进画布视图，标题显示去后缀文件名、右轨 TOC 双面板隐藏
  - 前端 `renderCanvas`：JSON.parse → SVG 场景（viewBox=节点 bbox+80 padding）；节点类型 text（圆角框+foreignObject 内 marked+DOMPurify 行内 md）/file（📄 卡片链接，站内跳转）/link（🔗 外链卡片）/group（虚线容器垫底+左上角标签）/图片 file 节点整卡铺图（clipPath 圆角裁剪）
  - 连线：sidePoint 取矩形边中点，cubic bezier 控制点沿边法向外伸 k=clamp(dist/2,30,120)，终点手绘三角箭头（不用 marker），label 在 t=0.5 贝塞尔点画 pill；颜色支持 Obsidian 预设 "1"-"6"→色板映射 + #hex 原样，CSS 变量适配日夜主题
  - 接线点：SSR 分支（.canvas 判定）、404 链、`/api/file` 三处 is_md 门禁放行、scan_tree 收录 .canvas、tree_to_md 显示名剥后缀、搜索收录正则、点击委托与 popstate 的 `/(\.md|\.canvas)$/i`
  - **时序坑**：底部独立 SSR 触发器先于 init() 同步执行、会被 enterApp 的直达分支重新隐藏 doc-wrap（excalidraw 是 async 函数侥幸没事）→ canvas 改在 enterApp 的 SSR_CANVAS 分支内直接调用（同 SSR_PDF 模式）
- **挂载直连修复（resolve_vault_file）**：目录挂载以 basename(root) 为 URL 前缀（如 BrainPress/xxx.md 实际位于 <root>/xxx.md），但解析时直接拼 root+rel → 挂载文件全部 404（树里能看到、URL 打不开；主 vault 同名目录的文件恰好遮掩了问题）。修复：先剥前缀再解析、退回直接拼接兼容嵌套。此前 mount 开关验证只测了树没测直连，漏掉了这层
- **回归实测**：canvas SSR 直达+SPA 点击+返回/前进全绿（3 节点 2 边 2 箭头、中英文本正确）；用户真实 excalidraw-test.md 走官方引擎 23 mask 断线/22 文本 Virgil/0 外链 ✓；挂载 ON 直连 5 文件全 200、OFF 干净 404
- README：nginx 示例补 `.canvas$` 重写规则，vault 描述行提 canvas

### 2026-08-24（晚）Excalidraw 切换完整官方包：绑定标签 + 蒙版断线（与 Obsidian 完全一致）
- **用户反馈**：字母是双击箭头自动插入的（labeled arrow），Obsidian 里能明显看到**线在字母处断开一个口**；站点预览仍不一致，要求别自己写几何逻辑、用官方引擎看别人怎么做
- **根因**：`@excalidraw/utils@0.1.2` 是老版导出工具，没有现代渲染管线的两个关键行为——① 绑定标签的定位/蒙版断线（官方用 `<mask>` 把线在标签 bbox 处挖口）；② 新 schema 兼容。用户画的图全是 labeled arrow，所以怎么调坐标都差口气
- **方案**：换完整官方包 `@excalidraw/excalidraw@0.17.6` UMD（与 Obsidian 插件同源渲染管线）——vendor `assets/react.production.min.js`(11KB)、`react-dom.production.min.js`(132KB)、`excalidraw.production.min.js`(1.19MB)+LICENSE、`assets/dist/excalidraw-assets/` 全部 6 个 woff2（Virgil/Cascadia/Assistant×4）；懒加载链 React→ReactDOM→ExcalidrawLib，全局名 `window.ExcalidrawLib`；删除 excalidraw-utils.min.js
- **关键坑（全部实测定位）**：
  1. **字体码代差**：插件 2.26.4 存 `fontFamily:5`（Virgil legacy），引擎只认 1/2/3 → 未知码回落 Segoe UI Emoji 系统体，字形墨迹偏移=「字浮在线外」的主因 → 导出前映射 `{4:2, 5:1}`（normalizeExcalidrawScene，仅此一项归一化，标签绑定原样交给引擎）
  2. **EXCALIDRAW_ASSET_PATH 拼接规则刁钻**：head 注入的 @font-face 用 `AP+"excalidraw-assets/<file>"` 模板（AP 需带尾斜杠且含 /dist），导出侧模板又不同 → 取 `'/assets/dist/'` 使 head 侧正确（导出侧坏 URL 本就会被剥）；此前 AP='/assets' 时拼出 `assetsexcalidraw-assets/...` 404 → document.fonts.load 报 network error、文字回退系统字体
  3. **内联 SVG 内嵌 @font-face 与页面级同名 face 冲突**：共存时 Firefox load() 报 network error（单独加载任一文件都正常）→ 剥除导出包注入的全部 @font-face style 块，统一走页面级自托管 Virgil（index.css）
  4. 插入前 `document.fonts.load('20px Virgil')` 就绪等待（1.5s 超时兜底），避免按回退字体 metrics 先画再跳变
- **度量方法论沉淀**：getBBox/getPointAtLength 返回各自局部坐标系数值，跨元素比较必须经 getCTM().matrixTransform 归一到同一空间（否则测出 ~392px 的假偏移=viewBox 平移量）；官方断线用蒙版实现，路径几何仍穿过文字，「线穿字」要数 mask 数量而不是量路径距离
- **实测**（selenium headless Firefox，SSR 直达 + SPA 树点击双路全过）：22 个 labeled arrow 全部带 mask 断线 ✓；CTM 归一度量字母到自己线的最短距离 ≤1.89px ✓；document.fonts.check('20px Virgil')=true 且 canvas 实测 Virgil 度量生效（48.07 ≠ monospace 40/serif 57）✓；无外链引用 ✓；官方包改名模拟失败 → 手写兜底接管（22 polyline + 22 text）✓；php -l 与内联 script node --check 通过

### 2026-08-24 Excalidraw 渲染升级：官方 exportToSvg 引擎（与 Obsidian 同源）
- **问题**：手写迷你渲染器（六种基础元素 + polyline 直线近似）与 Obsidian 原图差距大——弯曲箭头变折线、freedraw 手绘笔迹丢失、hachure 排线填充无法还原；用户反馈"好多地方和原图不准确"。预导出静态 SVG/PNG 方案不可行：vault 走 WebDAV 双端同步，导出图瞬间过期且污染 vault
- **方案**：vendor `@excalidraw/utils@0.1.2` UMD 官方构建 → `assets/excalidraw-utils.min.js`（~1.4MB，全局 window.ExcalidrawUtils）；renderExcalidraw 重构为三层：总入口（lz-string 解码 compressed-json）→ renderExcalidrawOfficial（官方 exportToSvg，与 Obsidian 插件同款 rough.js 引擎：贝塞尔弯曲箭头 / freedraw 笔迹 / hachure 填充全量还原）→ renderExcalidrawFallback（原手写渲染器整体保留兜底）
- **懒加载**：vendor 包只在绘画页动态注入 `<script>`（loadExcalidrawUtils 缓存 promise 防重复注入），普通文章页零开销；exportWithDarkMode 跟随站点日夜模式；exportPadding=16
- **CDN 剥离**：官方包导出的 SVG 内嵌指向 excalidraw.com 的 @font-face 块——违反本项目"assets 本地库、无 CDN"约定 → 导出后剥除含 @font-face 的 style 块，内联 SVG 复用页面自托管 Virgil（assets/fonts/Virgil.woff2）；同时移除根节点 width/height 只留 viewBox → 既有 `.excalidraw-canvas svg{max-width:100%}` 等比缩放
- **健壮性**：总入口全量捕获异常（官方失败→兜底接管；兜底再失败→画布内错误提示），自身永不 reject——SSR boot 与 selectFile 两个调用点都没接 promise catch
- **实测**（selenium headless Firefox 全过）：SPA 目录树点击流程 ✓ 官方引擎出图（66 个 rough.js path + 22 text、无 @font-face 残留、无外链引用、零 JS 报错）；SSR 直达 URL `/BrainPress/excalidraw.md` ✓（TOC 正确隐藏、标题正确）；vendor 包临时改名模拟离线 → 手写兜底自动接管（22 polyline + 22 text）✓；php -l 通过、三段内联 script `node --check` 通过

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
