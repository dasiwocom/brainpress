## 🧠 BrainPress

**如果 Obsidian 是你的第二大脑，那 BrainPress 就是大脑印刷机。**  
**If Obsidian is your second brain, then BrainPress is your brain printer.**

用 Obsidian 写笔记，用 BrainPress 印成书。  
Write with Obsidian, print with BrainPress.

## 📖 这是什么？ | What is this?

**BrainPress** 是一个**自托管的 Markdown 知识库系统**。你只需把笔记文件夹指向它，它就会即时渲染成一个可浏览、可搜索、可 AI 问答的网站——**无需构建、无需数据库、无需 Node.js**。

同时，它也内置了 **PDF 阅读器**和 **WebDAV 同步端点**，可以直接与 Obsidian 的 Remotely Save 插件配合使用。

> 这个项目最初是为 **AI 记忆可视化** 设计的：AI 在服务器上自动写下踩坑笔记，你通过网页监控它的学习过程。现在它也完全适合人类使用。

**BrainPress** is a **self-hosted Markdown knowledge base system**. Point it at a folder of notes, and it instantly renders a browsable, searchable, AI‑chat‑ready website — **no build step, no database, no Node toolchain**.

It also includes a **PDF reader** and a **WebDAV sync endpoint**, so you can pair it with Obsidian’s Remotely Save plugin.

> The project was originally built for **AI memory visualization**: AI automatically writes down lessons learned, and you monitor its progress through a web interface. Today it works just as well for human users.

## ✨ 核心特性 | Core Features

| 中文 | English |
|------|---------|
| 📄 **Markdown 即时渲染** — 修改笔记，刷新即生效 | **Real‑time Markdown rendering** — edit a note, refresh to see changes |
| 🌳 **自动目录树** — 基于文件夹结构生成侧边栏 | **Auto‑generated sidebar tree** — driven by your folder structure |
| 🌗 **日夜模式切换** — 一键切换深色/浅色 | **Dark / Light mode** — one‑click toggle |
| 📖 **PDF 阅读器** — 内置 pdf.js，支持夜间模式 | **Built‑in PDF reader** (pdf.js) with night‑mode inversion |
| 🤖 **AI 智能问答** — 基于知识库内容回答（DeepSeek API） | **AI chat** — answers from your vault (DeepSeek API) |
| ☁️ **多种存储** — 本地 / WebDAV / S3 (MinIO) | **Multiple storage backends** — Local / WebDAV / S3 (MinIO) |
| 🔒 **后台管理** — 密码保护，图形化配置 | **Admin panel** — password‑protected, GUI configuration |
| 🔗 **Obsidian 同步** — 通过 WebDAV 端点一键同步 | **Obsidian sync** — via built‑in WebDAV endpoint |
| 📡 **公开 API** — 清单、搜索、文件读写、AI 问答 | **Public API** — listing, search, file CRUD, AI chat |

---

## 🏗️ 架构 | Architecture

```
Obsidian ──WebDAV sync──▶ vault/ (Markdown + PDFs)
                             │
Browser ◀──render── index.php (single PHP entry)
        ◀──ask───  POST /api/ask (retrieval + DeepSeek)
```

| 路径 | 作用 |
|------|------|
| `index.php` | 前台入口：文章渲染、搜索、AI 聊天、WebDAV、公开 API |
| `admin.php` | 后台入口：登录、配置管理（nginx 将 `/admin` 和 `/api/admin/*` 转发至此） |
| `functions.php` | 共享层：配置加载、认证、S3 客户端、文件扫描 |
| `assets/` | 本地库（marked、DOMPurify、highlight.js、pdf.js、字体），无 CDN |
| `vault/` | 内容源：每个 `.md` 为一篇文章，每个 `.pdf` 在阅读器中打开 |
| `config.json` | 全部配置（含密钥，必须阻止 Web 访问） |

| Path | Purpose |
|------|---------|
| `index.php` | Frontend: article rendering, search, AI chat, WebDAV, public APIs |
| `admin.php` | Admin panel (nginx routes `/admin` and `/api/admin/*` here) |
| `functions.php` | Shared: config, auth, S3 client, file helpers |
| `assets/` | Local libraries (marked, DOMPurify, highlight.js, pdf.js, fonts) – no CDN |
| `vault/` | Content source: every `.md` is a page, every `.pdf` is a reader view |
| `config.json` | All configuration (secrets included – must be blocked from the web) |

---

## ⚙️ 配置速览 | Quick Config

`config.json` 中的关键字段：

| Key | 含义 |
|-----|------|
| `password_hash` | 管理员密码（bcrypt），首次访问 `/admin` 时设置 |
| `webdav_user` / `webdav_pass` | WebDAV 同步账号密码（供 Obsidian 使用） |
| `render_webdav` / `render_minio` | 启用远程存储开关 |
| `minio_*` | S3 兼容存储（MinIO）配置 |
| `custom_paths` | 额外本地路径（最多 5 个） |
| `exclude_paths` | 前端隐藏路径（目录树、搜索、直接访问均不可见） |
| `pinned_dirs` / `pinned_articles` | 置顶目录/文章 |
| `expanded_dirs` | 默认展开的目录 |
| `site_title` | 网站标题 |
| `home_article` | 主页文章路径（相对于 `vault/`） |
| `default_light` | 强制浅色主题 |
| `api_token` | 写 API 的 Bearer Token（空则禁用写 API） |
| `ai_enabled` | AI 问答总开关 |
| `ai_api_key` | DeepSeek API Key |
| `ai_model` | 模型名称，如 `deepseek-chat` |
| `ai_mode` | `hybrid`（知识库优先，回退通用）或 `strict`（仅从知识库回答） |

所有配置均可在后台（`/admin` → 系统设置）中图形化修改。

Key fields in `config.json`:

| Key | Meaning |
|-----|---------|
| `password_hash` | Admin password (bcrypt). Set on first visit to `/admin`. |
| `webdav_user` / `webdav_pass` | WebDAV credentials for Obsidian sync |
| `render_webdav` / `render_minio` | Toggle remote storage sources |
| `minio_*` | S3‑compatible (MinIO) settings |
| `custom_paths` | Up to 5 extra local paths |
| `exclude_paths` | Paths hidden from frontend (tree, search, direct access) |
| `pinned_dirs` / `pinned_articles` | Pinned directories / articles |
| `expanded_dirs` | Directories expanded by default |
| `site_title` | Site title |
| `home_article` | Homepage article (relative to `vault/`) |
| `default_light` | Force light theme |
| `api_token` | Bearer token for write API (empty = disabled) |
| `ai_enabled` | Master switch for AI chat |
| `ai_api_key` | DeepSeek API key |
| `ai_model` | Model name, e.g. `deepseek-chat` |
| `ai_mode` | `hybrid` (vault first, fallback to general) or `strict` (vault only) |

All settings can be edited via the admin panel (`/admin` → System Settings).

---

## 🚀 快速开始 | Quick Start

1. **环境要求**：PHP 8.0+（含 `curl`、`mbstring` 扩展），Nginx 或 Apache。
2. 将项目文件夹上传至服务器，把笔记（`.md`）和 PDF 放入 `vault/` 目录。
3. 配置 Nginx（见下文）或 Apache，确保 `config.json` 被阻止 Web 访问。
4. 访问网站首页，然后访问 `/admin` 设置管理员密码。
5. 可选：配置 WebDAV 账号（供 Obsidian 同步），填写 DeepSeek API Key 启用 AI 问答。

1. **Requirements**: PHP 8.0+ (with `curl`, `mbstring`), Nginx or Apache.
2. Upload the folder, place notes (`.md`) and PDFs into `vault/`.
3. Configure Nginx (see below) or Apache, block `config.json` from web access.
4. Visit the site, then go to `/admin` to set the admin password.
5. Optionally set up WebDAV credentials (for Obsidian sync) and add a DeepSeek API key for AI chat.

---

## 🧩 Nginx 配置要点 | Nginx Notes

```nginx
# vault 静态文件（图片、PDF 原件）直接提供
location ^~ /vault/ { }

# .md 文件 → 动态渲染（改写至 index.php）
location ~* \.md$ { rewrite ^(.*)$ /index.php last; }

# .pdf 文件 → 阅读器页面（非直接下载）
location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }

# 后台管理
location ^~ /admin { ... }
location ^~ /api/admin/ { ... }

# 阻止敏感文件（config.json, .user.ini, .env, 备份等）
location ~* (config\.json|\.user\.ini|\.env|\.bak|\.tmp|\.log) { return 404; }
```

---

## 🔒 安全提示 | Security Notes

- `config.json` 包含所有密钥（密码哈希、API Key、WebDAV 密码等）——**必须阻止 Web 访问**（Nginx 返回 404）。
- 管理员密码以 bcrypt 哈希存储，永不明文。
- AI Key 仅服务器端使用，不会发送到前端。
- 备份应存放在 Web 根目录之外（如 `/www/wwwroot/backup/`）。
- `vault/` 目录仅需 PHP 用户可写。

- `config.json` contains all secrets (password hash, API key, WebDAV credentials) – **must be blocked from the web** (Nginx returns 404).
- Admin password is stored as bcrypt hash, never plaintext.
- AI key stays on the server, never exposed to the client.
- Backups should live outside the web root.
- `vault/` should be writable only by the PHP user.

---

## 📂 项目结构 | Folder Structure

```
brainpress/
├── assets/          # CSS / JS / 字体（本地）
├── vault/           # 📖 你的笔记 + PDF 文件
├── admin.php        # 后台管理入口
├── config.json      # ⚙️ 所有配置（含密钥）
├── functions.php    # 核心函数库
├── index.php        # 前台主入口
└── README.md
```

---

## 🔌 公开 API | Public API

| Endpoint | 说明 |
|----------|------|
| `GET /api/list` | 侧边栏目录树（遵循隐藏/置顶规则） |
| `GET /api/file?path=` | 获取某篇 Markdown 原始内容 |
| `GET /api/search?q=` | 全文搜索 |
| `GET /api/article-list` | 文章清单（轻量） |
| `GET /api/llms.txt` | LLM 友好站点清单（llms.txt 规范） |
| `POST /api/ask` | AI 问答（需启用 AI） |
| `POST/DELETE /api/note` | 创建/覆盖/删除笔记（需 Bearer Token） |

详细文档参见 `vault/guide/api-reference.md`。

| Endpoint | Description |
|----------|-------------|
| `GET /api/list` | Sidebar tree (respects hidden/pinned rules) |
| `GET /api/file?path=` | Raw markdown content of a note |
| `GET /api/search?q=` | Full‑text search |
| `GET /api/article-list` | Lightweight article inventory |
| `GET /api/llms.txt` | LLM‑friendly site manifest (llms.txt spec) |
| `POST /api/ask` | AI chat (requires AI enabled) |
| `POST/DELETE /api/note` | Create/overwrite/delete a note (Bearer token required) |

Full details in `vault/guide/api-reference.md`.

---

## 📄 许可证 | License

**作者**：Ryan  
**性质**：个人项目，自由使用、自由修改。  
**Author**: Ryan  
**License**: Personal project – free to use and modify.

---

## 🧭 下一步 | Next Steps

- **全文搜索** 优化（已支持，可改进排序）
- **目录树记忆**（刷新后保持折叠状态）
- **笔记双链 `[[]]`** 支持
- **阅读进度** 记录
- **导出为 Markdown 压缩包**
- 更完善的 **AI 对话** 体验

---

**用 Obsidian 写，用 BrainPress 印。**  
**Write with Obsidian, print with BrainPress.**

---     