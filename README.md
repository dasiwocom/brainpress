<div align="center">

# 🧠 BrainPress

**如果 Obsidian 是你的第二大脑，BrainPress 就是大脑印刷机。**  
**If Obsidian is your second brain, then BrainPress is your brain printer.**

用 Obsidian 写笔记，用 BrainPress 印成书。  
*Write with Obsidian, print with BrainPress.*

---

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-personal-5672cd?style=flat-square)
![Self-hosted](https://img.shields.io/badge/self--hosted-✔-3e63dd?style=flat-square)
![No build](https://img.shields.io/badge/no--build-✔-22c55e?style=flat-square)
![No DB](https://img.shields.io/badge/no--database-✔-22c55e?style=flat-square)
![Docker ready](https://img.shields.io/badge/docker-ready-2496ed?style=flat-square&logo=docker&logoColor=white)

[**文档 · Docs**](https://github.com/yourorg/brainpress) · [**Docker 版 · Docker**](./docker/README.md) · [**API 参考 · API Reference**](#-public-api)

</div>

---

## ✨ 这是什么？ | What is this?

**BrainPress** 是一个**自托管的 Markdown 知识库系统**：指向一个笔记文件夹，它立即渲染成一个可浏览、可搜索、可 AI 问答的网站——**无需构建、无需数据库、无需 Node.js**。随手搭配 **PDF 阅读器**、**知识图谱**、**Excalidraw 绘图**与 **Obsidian Canvas 白板**。

同时内置 **WebDAV 同步端点**，可与 Obsidian 的 **Remotely Save** 插件一键同步。

> 最初为 **AI 记忆可视化** 而生——AI 在服务器上自动写下踩坑笔记，你用网页看它的学习过程；如今它同样适合人类。

<p align="center">
  <i>截图待补充 · screenshot placeholder</i>
</p>

---

## 🎯 核心特性 | Features

- 📄 **实时 Markdown 渲染** — 改笔记、刷新即生效，无需重新构建
- ✍️ **完整 Obsidian 语法** — Callout、KaTeX 公式、高亮、嵌套标签、块 ID、脚注、双链 `[[]]` 与嵌入 `![[]]`；代码块带行号（Quartz 同款）
- 🌳 **自动目录树** — 由文件夹结构驱动，支持置顶、强制展开、隐藏路径
- 📖 **内置 PDF 阅读器** — pdf.js：大纲、翻页、缩放、全屏；`![[book.pdf]]` 嵌入与直达同界面（与文章内容区同宽对齐）
- 🎨 **Excalidraw + Canvas** — `.excalidraw.md` 绘图与 `.canvas` 白板全屏铺满中+右（保留左树）、平移/缩放；Canvas 支持拖拽卡片（连线实时跟随）与单击进笔记
- 🕸️ **知识图谱** — `/graph` 视图，d3-force 物理引擎；节点大小随连接数增大（枢纽更明显）、移动端加大命中点、布局更分散
- 🤖 **AI 智能问答** — 基于知识库的 RAG 回答（OpenAI 兼容端点 / DeepSeek / Ollama）
- ☁️ **多存储后端** — 本地 / WebDAV / S3 (MinIO) / ima / 自定义挂载
- 🔒 **后台管理面板** — 密码保护，图形化配置；一级菜单重构为 Sources / Tree / Settings（Unix 风格）
- 🔗 **Obsidian 一键同步** — 内置 WebDAV 端点
- 📡 **公开 API** — 清单、搜索、文件读写、AI 问答、图谱数据
- 🌗 **日夜模式** + 字体预设 + 仅本地资源（无 CDN）

---

## 🚀 快速开始 | Quick Start

**环境要求**：PHP 8.0+（含 `curl`、`mbstring`）、Nginx 或 Apache。

1. 上传 `website/` 到服务器，把笔记放进 `website/vault/`
2. 配置 Nginx（要点见下）或 Apache，阻止 `config.json` 的 Web 访问
3. 访问网站 → `/admin` 设置管理员密码
4. （可选）在后台配置 WebDAV 账号（Obsidian 同步）与 LLM API Key（AI）

本地没有 Nginx？在 `website/` 里直接 `./start.sh`（PHP 内置服务器 + 路由模拟）。

```bash
cd website && ./start.sh   # http://127.0.0.1:8080
```

### Nginx 配置要点

```nginx
location ^~ /vault/  { try_files $uri @brainpress; }          # 静态资源
location ~* \.md$    { rewrite ^(.*)$ /index.php last; }     # Markdown 渲染
location ~* \.pdf$   { rewrite ^(.*)$ /index.php last; }     # PDF 阅读器
location ~* \.canvas$ { rewrite ^(.*)$ /index.php last; }    # Canvas 白板
location ^~ /admin   { ... }                                  # 后台
location ^~ /api/admin/ { ... }                               # 后台 API
# 阻止敏感文件
location ~* (config\.json|\.user\.ini|\.env|\.bak|\.tmp|\.log) { return 404; }
```

> 详细部署、Apache、安全加固见 **[部署文档 · Deployment](./website/vault/BrainPress/Deployment.md)**。

---

## 🐳 Docker 版 | Docker Edition

Docker 版是自包含的独立项目（自带代码副本、示例库与完整文档），部署/备份/发布到 Docker Hub 的步骤见 **[Docker README](./docker/README.md)**。

---

## 📋 项目结构 | Project Structure

```
brainpress/
├── website/        # 🌐 网站版（nginx/PHP 部署，开发/部署只跟这个目录打交道）
├── docker/         # 🐳 Docker 版（独立项目，详见 docker/README.md）
├── landing/        # 🏠 官网展示页（纯静态）
├── electron/       # 🖥️ 桌面端
└── README.md       # 本文件（总索引）
```

---

## 📡 公开 API | Public API

| Endpoint | 说明 |
|----------|------|
| `GET /api/list` | 侧边栏目录树（遵循隐藏/置顶规则） |
| `GET /api/file?path=` | 获取某篇 Markdown 原始内容 |
| `GET /api/search?q=` | 全文搜索 |
| `GET /api/graph` | 知识图谱节点与连线 |
| `GET /api/article-list` | 文章清单（轻量） |
| `GET /api/llms.txt` | LLM 友好站点清单（llms.txt 规范） |
| `POST /api/ask` | AI 问答（需启用 AI） |
| `POST/DELETE /api/note` | 创建/覆盖/删除笔记（需 Bearer Token） |

详细参数见 **[API 参考 · API Reference](./website/vault/BrainPress/API-Reference.md)**。

---

## 📚 文档 | Documentation

详细文档以 Markdown 存在 vault 中（中文 `.zh.md`）：

| 文档 | 说明 |
|------|------|
| [**Introduction**](./website/vault/BrainPress/Introduction.zh.md) | 总览、架构、快速配置、API |
| [**Getting Started**](./website/vault/BrainPress/GettingStarted.zh.md) | 上手教程 |
| [**Configuration**](./website/vault/BrainPress/Configuration.zh.md) | 全部配置字段 |
| [**Deployment**](./website/vault/BrainPress/Deployment.zh.md) | 部署与安全 |
| [**API Reference**](./website/vault/BrainPress/API-Reference.zh.md) | 公开 API 详解 |

---

## 🔒 安全提示 | Security Notes

- `config.json` 含全部密钥（密码哈希、API Key、WebDAV 密码）——**必须阻止 Web 访问**。
- ⚠️ 若仓库公开：上传前确认 `config.json` 无真实密钥；一旦 commit 真实密钥会永久留在 git 历史。
- 管理员密码以 bcrypt 哈希存储；AI Key 仅服务器端使用，永不发往前端。
- 备份存放在 Web 根目录之外；`vault/` 仅需 PHP 用户可写。

---

## 📄 许可证 | License

**作者**：Ryan · **性质**：个人项目，自由使用、自由修改。  
*Personal project — free to use and modify.*

---

<div align="center"><b>用 Obsidian 写，用 BrainPress 印。</b><br><i>Write with Obsidian, print with BrainPress.</i></div>
