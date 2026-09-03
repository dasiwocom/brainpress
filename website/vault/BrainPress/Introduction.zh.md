# BrainPress

**如果 Obsidian 是你的第二大脑，BrainPress 就是你的大脑打印机。**

用 Obsidian 写作，用 BrainPress 打印。

## 这是什么？

**BrainPress** 是一个**自托管的 Markdown 知识库**。将它指向一个笔记文件夹，它就能立即渲染出一个可浏览、可搜索、支持 AI 对话的网站——**无需构建步骤、无需数据库、无需 Node 工具链**。

它还内置了 **PDF 阅读器**和 **WebDAV 同步端点**，可与 Obsidian 的 Remotely Save 插件配合使用；同时原生支持 **Excalidraw 绘图**和 **Obsidian Canvas** 白板，渲染为实时 SVG；还提供**知识图谱**视图。

> 该项目最初为 **AI 记忆可视化**而构建：一个 AI 代理在服务器上编写经验教训笔记，你通过网页界面观察它的学习过程。如今它同样适用于普通用户。

## 核心功能

| 功能 | 描述 |
|------|------|
| 实时 Markdown 渲染 | 编辑笔记，刷新（或直接点击）即可看到更改——无需重新构建 |
| 完整 Obsidian 语法 | Callouts、数学公式（KaTeX）、`==高亮==`、嵌套标签（`#a/b/c`）、块 ID（`^id`）、脚注、wiki 链接（`[[...]]`）和嵌入（`![[...]]`）、块嵌入（`![[note#^id]]`）、注释（`%%...%%`）、媒体嵌入（图片/音频/视频） |
| 自动生成侧边栏树 | 由文件夹结构驱动；支持置顶、强制展开和隐藏路径 |
| 深色/浅色模式 | 一键切换，在 `localStorage` 中记住；可选强制浅色默认 |
| PDF 阅读器（pdf.js） | 大纲、页面导航、缩放预设、全屏——嵌入（`![[book.pdf]]`）和直接访问共用同一界面，支持 `[[book.pdf#page=3]]` 深度链接 |
| Excalidraw 绘图 | `.excalidraw.md` 文件使用官方 Excalidraw 引擎渲染（lz-string 压缩 JSON → SVG），与 Obsidian 效果一致，服务端渲染且支持 SPA 导航 |
| Obsidian Canvas | `.canvas` 白板文件渲染为 SVG 场景（文本/文件/链接/分组节点、贝塞尔箭头、支持主题） |
| 知识图谱 | `/graph` 视图，d3-force 物理引擎、标签阈值、点击打开 |
| AI 对话 | 通过 OpenAI 兼容端点从你的知识库中获取回答（默认 DeepSeek；自定义 base URL 支持网关/Ollama）。混合模式（知识库优先，通用知识回退）或严格模式（仅知识库），附带来源链接 |
| 多存储后端 | 本地 / WebDAV / S3（MinIO）/ 最多 5 个额外本地挂载路径 |
| 目录大纲 | 每篇文章右侧栏目录；反向链接面板 |
| 管理面板 | 密码保护，GUI 配置 |
| Obsidian 同步 | 内置 WebDAV 端点，一键同步 |
| 公共 API | 列表、搜索、文件 CRUD、AI 对话、图谱数据 |
| 字体预设 | 在系统无衬线字体和自托管衬线字体（DejaVu Serif 承担西文；中文始终用系统字体）之间切换阅读字体 |
| 仅本地资源 | marked、DOMPurify、lunr、highlight.js、pdf.js、KaTeX、Excalidraw vendor——无 CDN |

## 架构

```
Obsidian ──WebDAV sync──▶ vault/ (Markdown + PDFs + drawings + canvases)
                             │
Browser ◀──render── index.php (single PHP entry)
        ◀──ask───  POST /api/ask (retrieval + OpenAI-compatible LLM)
```

| 路径 | 用途 |
|------|------|
| `index.php` | 前端：文章渲染、搜索、AI 对话、WebDAV、公共 API、SSR |
| `admin.php` | 管理面板（nginx 将 `/admin` 和 `/api/admin/*` 路由到此处） |
| `functions.php` | 共享层：配置、认证、S3 客户端、文件扫描、挂载合并 |
| `assets/` | 本地库（marked、DOMPurify、highlight.js、pdf.js、KaTeX、Excalidraw vendor、字体）——无 CDN |
| `vault/` | 内容源：每个 `.md` 是一个页面，每个 `.pdf` 在阅读器中打开，`.excalidraw.md` 是绘图，`.canvas` 是白板 |
| `config.json` | 所有配置（包含密钥——必须阻止 Web 访问） |

## 快速配置

`config.json` 中的关键字段（每个字段也可在管理面板中编辑）：

| 字段 | 含义 |
|------|------|
| `password_hash` | 管理员密码（bcrypt）。首次访问 `/admin` 时设置（或通过 `/api/setup`）。 |
| `webdav_mounts` | Obsidian Remotely Save 同步账号：`{ user, pass, path }` 数组——每个账号独立，`path` 相对 `vault/`。在管理面板 → **WebDAV** 管理。 |
| `render_webdav` / `render_minio` | 存储源是否渲染开关（vault 渲染**默认关闭**；同步 ≠ 发布）。 |
| `minio_*` | S3 兼容（MinIO）设置。 |
| `custom_paths` | 最多 5 个额外本地路径（绝对服务器路径），每个带有 `on` 标志。 |
| `exclude_paths` | 对前端隐藏的路径（树形结构、搜索、直接访问均不可见）。 |
| `pinned_dirs` / `pinned_articles` | 置顶目录 / 置顶文章。 |
| `expanded_dirs` | 侧边栏中默认展开的目录。 |
| `site_title` | 站点标题。 |
| `home_article` | 首页文章（相对于 `vault/`）。 |
| `content_width` | 阅读列宽度（px，范围 480–1600，默认 840）。 |
| `default_light` | 强制浅色主题为默认。 |
| `pin_navbar` | 始终显示导航栏（滚动时不隐藏）。 |
| `font_preset` | `nunito`（默认，系统无衬线）或 `serif`（自托管 DejaVu Serif 承担西文；中文用系统字体）。 |
| `graph_path` | 图谱视图别名 URL。 |
| `graph_show_labels` | 默认显示图谱节点标签。 |
| `api_token` | 写入 API 的 Bearer 令牌（空 = 禁用）。 |
| `ai_enabled` | AI 对话总开关。 |
| `ai_api_base` | OpenAI 兼容 base URL（空 = `https://api.deepseek.com`；用于 NewAPI/one-api 网关或 `http://127.0.0.1:11434/v1` 连接 Ollama）。 |
| `ai_api_key` | LLM API 密钥（空 = 禁用；同时支持无密钥本地模型）。 |
| `ai_model` | 模型名称，如 `deepseek-chat`。 |
| `ai_mode` | `hybrid`（知识库优先，通用知识回退）或 `strict`（仅知识库）。 |

## 快速开始

1. **环境要求**：PHP 8.0+（含 `curl`、`mbstring`），Nginx 或 Apache。
2. 上传项目文件夹；将笔记（`.md`）、PDF、绘图（`.excalidraw.md`）和白板（`.canvas`）放入 `vault/`。
3. 配置 Nginx（见下文）或 Apache；阻止 `config.json` 的 Web 访问。
4. 访问网站，然后前往 `/admin` 设置管理员密码。
5. 可选：在管理面板 → **WebDAV** 创建 WebDAV 同步账号（Obsidian 同步），并添加 LLM API 密钥以启用 AI 对话。

## Nginx 配置说明

```nginx
# vault 静态文件（图片、PDF 原文件）直接提供
location ^~ /vault/ { }

# .md / .excalidraw.md 文件 → 动态渲染（重写到 index.php）
location ~* \.md$ { rewrite ^(.*)$ /index.php last; }

# .pdf 文件 → 阅读器页面（非原始下载）
location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }

# .canvas 文件 → 白板渲染
location ~* \.canvas$ { rewrite ^(.*)$ /index.php last; }

# admin
location ^~ /admin { ... }
location ^~ /api/admin/ { ... }

# 阻止敏感文件（config.json、.user.ini、.env、备份、日志）
location ~* (config\.json|\.user\.ini|\.env|\.bak|\.tmp|\.log) { return 404; }
```

> `.excalidraw.md` 和 `.canvas` 规则必须与 `.md`/`.pdf` 规则并列。因为 `.excalidraw.md` 以 `.md` 结尾，`.md$` 规则已经覆盖它；而 `.canvas` 规则是白板所必需的。

## 安全说明

- `config.json` 包含所有密钥（密码哈希、API 密钥、WebDAV 密码）——**必须阻止 Web 访问**（Nginx 返回 404）。
- 管理员密码以 bcrypt 哈希存储，绝不以明文保存。
- LLM 密钥保留在服务器端，绝不暴露给客户端。
- 备份应存放在 Web 根目录之外。
- `vault/` 仅需 PHP 用户可写。

## 项目结构

```
brainpress/
├── assets/          # CSS / JS / 字体 / 供应商库（本地）
├── vault/           # 你的笔记 + PDF + 绘图 + 白板
├── admin.php        # 管理面板入口
├── config.json      # 所有配置（密钥）
├── functions.php    # 核心库
├── index.php        # 前端入口
└── README.md
```

## 公共 API

| 端点 | 描述 |
|------|------|
| `GET /api/list` | 侧边栏树（遵循隐藏/置顶规则） |
| `GET /api/file?path=` | 笔记的原始 Markdown 内容 |
| `GET /api/search?q=` | 全文搜索 |
| `GET /api/graph` | 图谱节点和边（用于 `/graph` 视图和外部使用） |
| `GET /api/article-list` | 轻量文章清单 |
| `GET /api/llms.txt` | LLM 友好的站点索引（llms.txt 规范） |
| `POST /api/ask` | AI 对话（需启用 AI） |
| `POST/DELETE /api/note` | 创建/覆盖/删除笔记（需要 Bearer 令牌） |
| `GET /api/admin/config`、`POST /api/admin/config`、`POST /api/admin/password` | 管理配置（需要会话） |

完整详情见 `API-Reference.md`。

## 许可证

**作者**：Ryan
**性质**：个人项目，可自由使用和修改。

## 后续计划

- 更完善的全文搜索排序
- 跨刷新记住折叠树状态
- 阅读进度跟踪
- 导出为 Markdown 打包文件
- 更深度的 AI 对话体验
