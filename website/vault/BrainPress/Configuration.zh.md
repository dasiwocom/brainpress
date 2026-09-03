# 配置参考

所有站点配置位于站点根目录的 `config.json` 中（这是 Web 服务器必须禁止访问的唯一文件——参见部署文档）。除特别说明外，每个字段都可在管理面板 `/admin` → 对应视图中编辑。

```json
{
  "password_hash": "$2y$10$...",
  "webdav_mounts": [
    { "user": "alice", "pass": "secret", "path": "" }
  ],
  "webdav_user": "alice",
  "webdav_pass": "secret",
  "render_webdav": false,
  "render_minio": false,
  "minio_endpoint": "https://s3.example.com",
  "minio_access": "access-key",
  "minio_secret": "secret-key",
  "minio_bucket": "vault",
  "custom_paths": [
    { "path": "/root/.hermes/memories", "on": true }
  ],
  "exclude_paths": ["draft/secret-note.md"],
  "pinned_dirs": ["knowledge"],
  "pinned_articles": ["knowledge/article/Featured-Note.md"],
  "expanded_dirs": ["draft"],
  "site_title": "BrainPress",
  "home_article": "guide/what-is-brainpress.md",
  "content_width": 840,
  "default_light": false,
  "front_drawer_expanded": true,
  "pin_navbar": true,
  "font_preset": "nunito",
  "graph_path": "Visual-Knowledge/Graphview",
  "graph_show_labels": true,
  "api_token": "hex-token-for-write-api",
  "ai_enabled": true,
  "ai_api_base": "https://api.deepseek.com",
  "ai_api_key": "sk-...",
  "ai_model": "deepseek-chat",
  "ai_mode": "hybrid"
}
```

## 认证

| 字段 | 含义 |
| --- | --- |
| `password_hash` | 管理员密码，**bcrypt** 加密（绝不以明文存储）。首次登录 `/admin` 时设置，或通过 `/api/setup` / 密码表单设置。 |

## WebDAV 同步

WebDAV 是同步侧：为 Obsidian Remotely Save 在 `vault/` 中存储和编辑笔记。**同步 ≠ 发布**——前台渲染由 Mounts 下的 `render_webdav` 单独控制。

| 字段 | 含义 |
| --- | --- |
| `webdav_mounts` | 同步账号：`{ user, pass, path }` 数组。每个账号有独立的 Basic Auth 凭据，并挂载一个**相对 `vault/`** 的同步目录（`path` 留空 = vault 根）。DAV 端点只暴露 `vault/`，绝不暴露其外部。在管理面板 → **WebDAV** 管理。 |
| `webdav_user` / `webdav_pass` | 旧版单账号字段，随 `webdav_mounts` 第一组账号同步保留，供旧读取方兼容。 |

## 挂载（渲染什么）

| 字段 | 含义 |
| --- | --- |
| `render_webdav` | 将本地 `vault/` 文件夹包含在渲染内容树、搜索和图谱中。**默认关闭**——同步的笔记只存储、不发布，直到你在管理面板 → Mounts 打开它。 |
| `render_minio` | 同时将 S3 兼容桶（MinIO）合并到内容树中。 |
| `minio_endpoint` / `minio_access` / `minio_secret` / `minio_bucket` | S3 连接设置。文件按名称合并，本地优先。 |
| `custom_paths` | 最多 5 个额外本地路径用于渲染。每个条目：`path`（绝对服务器路径；目录会渲染其下所有 `.md`，文件则渲染该文件）+ `on`（启用开关）。超出 PHP `open_basedir` 的路径会被拒绝。挂载内容在树的顶层合并（与 `vault/` 同级），而非嵌套在合成目录下。 |

## 内容可见性

| 字段 | 含义 |
| --- | --- |
| `exclude_paths` | 对前端隐藏的路径——从树、搜索和直接访问中排除（`/api/file` 和 SSR 对这些路径返回"file not found"）。匹配规则：精确路径、目录前缀（隐藏整个目录）或裸文件名（全局隐藏）。管理面板：Tree → Hidden paths。 |
| `pinned_dirs` | 排序到侧边栏树**顶部**的目录。 |
| `pinned_articles` | 在其所在目录中**优先排序**的文章。 |
| `expanded_dirs` | 在侧边栏中**强制展开**的目录，覆盖默认折叠设置。 |

## 站点

| 字段 | 含义 |
| --- | --- |
| `site_title` | 显示在导航栏中，作为页面标题后缀。 |
| `home_article` | 渲染为首页的路径（相对于 `vault/`）。允许绝对路径和前导斜杠。空值 = 占位文本。 |
| `content_width` | 阅读列宽度（px，范围 480–1600，默认 840）。 |
| `default_light` | `true` 强制浅色主题为默认（忽略已保存的深色偏好）。 |
| `front_drawer_expanded` | 侧边栏树的默认展开状态（`true` = 展开）。 |
| `api_token` | 写入 API 的 Bearer 令牌（`POST/DELETE /api/note`）。空值 = 写入 API 禁用。可在 Site 视图中编辑。 |
| `graph_path` | 图谱视图别名 URL；匹配的菜单项会注入到树中。管理面板：Graph。 |

## 外观

| 字段 | 含义 |
| --- | --- |
| `pin_navbar` | `true` 使导航栏始终可见（滚动时不隐藏）。管理面板：Preferences。 |
| `font_preset` | 阅读字体：`nunito`（默认，系统无衬线）或 `serif`（自托管 DejaVu Serif 承担西文；中文用系统宋体/SimSun）。`serif` 额外加载 `/assets/fonts/dejavu-serif.woff2`（+ 粗体）。管理面板：Preferences。 |

## 图谱

| 字段 | 含义 |
| --- | --- |
| `graph_show_labels` | 默认显示图谱节点标签（否则仅在悬停/缩放时显示）。管理面板：Graph。 |
| `graph_path` | 图谱视图别名 URL（见上方 Site 部分）。管理面板：Graph。 |

## AI

| 字段 | 含义 |
| --- | --- |
| `ai_enabled` | AI 对话总开关。`false` 时隐藏导航栏中的提问按钮，且 `/api/ask` 返回 403。 |
| `ai_api_base` | OpenAI 兼容的 base URL。空值 = `https://api.deepseek.com`。用于网关（NewAPI/one-api）或本地模型如 `http://127.0.0.1:11434/v1`（Ollama/LM Studio）。请求发送到 `{base}/chat/completions`，因此本地服务器的 URL 必须包含 `/v1`。由于调用由 PHP 在服务器端发起，该地址必须可从服务器访问。 |
| `ai_api_key` | LLM API 密钥。空值 = 禁用，同时支持无密钥本地模型（不发送 `Authorization` 头）。仅存储在服务器端，绝不暴露给访客。 |
| `ai_model` | 模型名称，如 `deepseek-chat`。 |
| `ai_mode` | `hybrid`（默认）：从知识库回答相关问题（附带来源链接），否则从通用知识回答。`strict`：仅从知识库回答——无匹配时返回"未找到相关文章"。 |

## 特殊文件类型

除 `.md` 外，知识库可包含原生的非 Markdown 笔记：

| 类型 | 行为 |
| --- | --- |
| `.pdf` | 在内置 pdf.js 阅读器中打开（嵌入和直接访问共享同一界面）。使用 `![[book.pdf]]` 嵌入；使用 `[[book.pdf#page=3]]` 深度链接。 |
| `.excalidraw.md` | Excalidraw 绘图，使用官方引擎渲染（lz-string 压缩 JSON → SVG）。通过 `.excalidraw.md` 扩展名**或**内容（`excalidraw-plugin:` 标记 + ```` ```compressed-json ```` 块）检测。 |
| `.canvas` | Obsidian Canvas 白板，渲染为实时 SVG 场景（文本/文件/链接/分组节点、贝塞尔箭头）。 |

以上三种均通过 `index.php` 提供（`.md`/`.pdf`/`.canvas` 重写规则），不从 `/vault/` 下的静态服务中提供。

## 行为说明

- **列表路径的双表示法**（`exclude_paths`、`pinned_dirs`、`pinned_articles`、`expanded_dirs`）：**相对路径**（或裸文件名）仅匹配主 `vault/`，如 `knowledge/article/note.md`；**绝对路径**（`/…`）匹配 `custom_paths` 挂载下的条目——精确匹配，或匹配其内部任何路径（挂载目录作为整棵子树匹配）。这消除了挂载与主知识库文件夹同名时的歧义。
- `exclude_paths` 中的目录会隐藏其下所有文件；裸文件名会在全局隐藏所有同名文件。
- `custom_paths` 使用绝对服务器路径（它们是 `vault/` 之外的挂载，因此不能使用相对路径）。
- 在网站运行时手动编辑 `config.json` 是允许的——下一个请求会重新读取它。手动编辑前请备份。
- 管理面板视图：**WebDAV**（同步账号）、**Mounts**（渲染来源）、**Preferences**、**Site**、**AI**、**Graph**、**Tree**。
