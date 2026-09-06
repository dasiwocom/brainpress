# BrainPress

如果 Obsidian 是你的第二大脑，BrainPress 就是大脑印刷机。

自托管 Markdown 知识库系统——指向一个笔记文件夹，立刻变成可浏览、可搜索、可 AI 问答的网站。无需构建、无需数据库、无需 Node.js。内置 PDF 阅读器、知识图谱、Excalidraw 绘图、Canvas 白板、WebDAV 同步。

## 快速开始

### Docker 部署（推荐）

```bash
docker run -d --name brainpress --restart unless-stopped -p 8080:80 -v /www/brainpress-data:/data -v /www/brainpress-vault:/var/www/html/vault crpi-k60hf4g69i7wfk22.cn-hongkong.personal.cr.aliyuncs.com/dasiwocom/brainpress:latest
```

1. 访问 `http://服务器IP:8080`
2. `/admin` 首次设置管理员密码
3. 笔记上传到 `/www/brainpress-vault/`，网页即时可见

镜像首次启动会自动把内置示例库注入 vault 卷，不会空白。配置持久化在 `/www/brainpress-data/config.json`。

### 源码部署

环境：PHP 8.0+（含 `curl`、`mbstring`）+ Nginx 或 Apache。

```bash
git clone https://github.com/dasiwocom/brainpress.git
cd brainpress
# 本地开发
./start.sh   # http://127.0.0.1:8080
```

生产部署将代码上传到服务器，笔记放进 `vault/`，配置伪静态规则，访问 `/admin` 设置密码。详细配置见 [部署文档](./vault/BrainPress/Deployment.zh.md)。

## 项目结构

```
├── index.php / admin.php / api.php / dav.php / functions.php / ima.php   网站源码
├── router.php / start.sh                        开发用路由与启动脚本
├── assets/                                      JS / CSS / 字体 / 第三方库
├── vault/                                       笔记库（Markdown 内容即站点内容）
├── Dockerfile / build.sh                        Docker 构建
├── apache-site.conf / php.ini / entrypoint.sh   容器运行配置
├── config.template.json                         默认配置模板
└── config.json / cache/ / ima_cache.json        运行时产物；config.json 为随仓库发布的干净默认配置（不含密钥），cache/、ima_cache.json 不提交
```

网页版与 Docker 版共用仓库根同一份源码，无副本。`.dockerignore` 挡掉 config.json、cache、日志；`vault/` 随镜像打包为种子库。

## 公开 API

| 接口 | 说明 |
|------|------|
| `GET /api/list` | 目录树 |
| `GET /api/file?path=` | Markdown 原文 |
| `GET /api/search?q=` | 全文搜索 |
| `GET /api/graph` | 知识图谱 |
| `POST /api/ask` | AI 问答（需启用） |
| `POST/DELETE /api/note` | 创建/删除笔记（需 Token） |

完整参数见 [API 参考](./vault/BrainPress/API-Reference.zh.md)。

## 文档

| 文档 | 说明 |
|------|------|
| [Introduction](./vault/BrainPress/Introduction.zh.md) | 总览与架构 |
| [Getting Started](./vault/BrainPress/GettingStarted.zh.md) | 上手教程 |
| [Configuration](./vault/BrainPress/Configuration.zh.md) | 全部配置字段 |
| [Deployment](./vault/BrainPress/Deployment.zh.md) | 部署与安全（Nginx/Apache/宝塔） |
| [API Reference](./vault/BrainPress/API-Reference.zh.md) | API 详解 |

## 维护者：发布新版本

```bash
./build.sh              # 构建 latest
./build.sh 1.0.0        # 额外打版本 tag
./build.sh 1.0.0 push   # 构建 + 推送阿里云 ACR
```

推送只传新增层，用户 `docker pull` 增量拉取。

## 安全

- `config.json` 含全部密钥，必须阻止 Web 访问（部署文档里有规则）。
- 管理员密码以 bcrypt 哈希存储，AI Key 仅服务端使用。
- 备份 = 打包 `/www/brainpress-data` + `/www/brainpress-vault`（Docker）或 `config.json` + `vault/`（源码）。

## 许可证

个人项目，自由使用、自由修改。
