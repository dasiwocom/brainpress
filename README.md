<div align="center">

[**简体中文**](./README.md) · [**English**](./README.en.md)

# 🧠 BrainPress

**如果 Obsidian 是你的第二大脑，BrainPress 就是大脑印刷机。**

用 Obsidian 写笔记，用 BrainPress 印成书。

---
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-personal-5672cd?style=flat-square)
![Self-hosted](https://img.shields.io/badge/self--hosted-✔-3e63dd?style=flat-square)
![No build](https://img.shields.io/badge/no--build-✔-22c55e?style=flat-square)
![No DB](https://img.shields.io/badge/no--database-✔-22c55e?style=flat-square)
![Docker ready](https://img.shields.io/badge/docker-ready-2496ed?style=flat-square&logo=docker&logoColor=white)
![WebDAV](https://img.shields.io/badge/WebDAV-Obsidian%20Sync-7c3aed?style=flat-square)
![AI RAG](https://img.shields.io/badge/AI-RAG%20QnA-f59e0b?style=flat-square)

[**官方站点**](https://docs.dasiwo.com) · [**核心特性**](#核心特性) · [**快速部署**](#快速部署) · [**详细文档**](#详细文档)

</div>

---

<a name="这是什么"></a>

## ✨ 这是什么？

**BrainPress** 是一个**自托管 Markdown 知识库系统**：指向一个笔记文件夹，立即渲染成可浏览、可搜索、可 AI 问答的网站——**无需构建、无需数据库、无需 Node.js**。

同时内置 **WebDAV 同步端点**，Obsidian 的 *Remotely Save* 插件可一键同步，笔记即站点、站点即笔记。

> 最初为 **AI 记忆可视化** 而生——AI 在服务器上自动写下踩坑笔记，你用网页看它的「学习过程」；如今它同样适合人类。

---

<a name="核心特性"></a>

## 🎯 核心特性

- 📄 **实时 Markdown 渲染** — 改笔记、刷新即生效，无需重新构建
- ✍️ **完整 Obsidian 语法** — Callout、KaTeX 公式、高亮、嵌套标签、块 ID、脚注、双链 `[[]]` 与嵌入 `![[]]`；代码块带行号
- 🌳 **自动目录树** — 文件夹结构即站点侧边栏，支持置顶、强制展开、隐藏路径
- 📖 **内置 PDF 阅读器** — pdf.js：大纲、翻页、缩放、全屏；`![[book.pdf]]` 嵌入与直达同界面
- 🎨 **Excalidraw + Canvas** — `.excalidraw.md` 绘图与 `.canvas` 白板全屏铺满、平移缩放，卡片连线实时跟随
- 🕸️ **知识图谱** — d3-force 物理引擎，节点大小随连接数变化、移动端加大命中点
- 🤖 **AI 智能问答** — 基于知识库的 RAG 回答（OpenAI 兼容端点 / DeepSeek / Ollama）
- ☁️ **多数据源** — 本地 / WebDAV / S3 (MinIO) / 腾讯 ima / 自定义挂载
- 🔗 **Obsidian 一键同步** — 内置 WebDAV 端点
- 🔒 **后台管理面板** — 密码保护，图形化配置
- 🌗 **日夜模式** + 多字体预设，**仅本地资源**（无 CDN）

---

<a name="快速部署"></a>

## 🚀 快速部署

### 方式一：Docker 一键部署

```bash
docker run -d --name brainpress \
  --restart unless-stopped \
  -p 8080:80 \
  -v "$PWD/brainpress-data":/data \
  -v "$PWD/brainpress-vault":/var/www/html/vault \
  crpi-k60hf4g69i7wfk22.cn-hongkong.personal.cr.aliyuncs.com/dasiwocom/brainpress:latest
```

1. 浏览器访问 `http://服务器IP:8080`
2. 打开 `http://服务器IP:8080/admin` **首次设置密码**
3. 配置自动持久化在 `./brainpress-data/config.json`，笔记放在 `./brainpress-vault/`（删容器不丢）

> 首次启动会自动注入内置示例笔记库，不会空白；也可以不挂 `vault` 卷，直接用内置示例。

### 方式二：源码部署 + 伪静态

**环境**：PHP 8.0+（需要 `curl`、`mbstring`）+ Nginx 或 Apache。

1. 将仓库源码上传至站点目录（宝塔：面板「上传/解压」，文件属主自动为 `www`，无需额外权限设置）
2. 网站设置里配置**伪静态**规则（见下方示例，Apache 直接用包内 `apache-site.conf`）
3. 浏览器打开 `/admin` **首次设置密码**
4. 笔记放入 `vault/`，页面即时可见

**Nginx 伪静态示例**：

```nginx
# 后台
location ~ ^/admin(/.*)?$         { rewrite .* /admin.php last; }
location ~ ^/api/admin(/.*)?$     { rewrite .* /admin.php last; }
# API / 图谱 / WebDAV → 主入口
location ~ ^/api(/.*)?$           { rewrite .* /index.php last; }
location ~ ^/graph(/.*)?$         { rewrite .* /index.php last; }
location ~ ^/dav(/.*)?$           { rewrite .* /index.php last; }
# Markdown / PDF / Canvas / HTML 渲染
location ~* \.(md|pdf|canvas|html)$ { rewrite .* /index.php last; }
# 阻止敏感文件
location ~* (config\.json|\.user\.ini|\.env|\.bak|\.tmp|\.log)$ { return 404; }
```

> 本地调试无需 Nginx：仓库根执行 `./start.sh` 即可（PHP 内置服务器 + 路由模拟）。

> ⚠️ 权限小贴士：若解压后文件属主不是 PHP 运行用户（如终端以 root 解压），首次设密码可能报 500。宝塔面板在「网站目录」里把属主改为 `www` 即可；或后台 `/admin` 页顶部也会给出具体提示。

---

<a name="详细文档"></a>

## 📚 详细文档

详细文档都以 Markdown 存放在仓库 `vault/BrainPress/`，与站点内容同源：

| 文档 | 入口 |
|------|------|
| **Introduction** · 总览 / 架构 / 快速配置 | [Introduction.zh.md](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Introduction.zh.md) |
| **Getting Started** · 上手教程 | [GettingStarted.zh.md](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/GettingStarted.zh.md) |
| **Deployment** · 部署与安全加固 | [Deployment.zh.md](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Deployment.zh.md) |
| **Configuration** · 全部配置字段 | [Configuration.zh.md](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/Configuration.zh.md) |
| **API Reference** · API 详解 | [API-Reference.zh.md](https://github.com/dasiwocom/brainpress/blob/main/vault/BrainPress/API-Reference.zh.md) |

---

<a name="许可证"></a>

## 📄 许可证

**作者**：Ryan · **性质**：个人项目，自由使用、自由修改。

---

<div align="center"><b>用 Obsidian 写，用 BrainPress 印。</b></div>