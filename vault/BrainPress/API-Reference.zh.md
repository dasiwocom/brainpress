# API 参考

BrainPress 暴露一个简洁的纯 HTTP API。所有响应均为 JSON（UTF-8），读取端点是**公开的**——无需认证，因此任何脚本或工具都可以访问知识库。管理端点需要从 `/api/login`（或首次运行时的 `/api/setup`）获取的会话 cookie。

**Base URL**：`https://docs.dasiwo.com`

## 响应信封

每个 JSON 端点返回一致的信封格式：

```json
{ "ok": true, ...data }
```

失败时返回 `ok: false`，附带人类可读的消息和适当的 HTTP 状态码：

```json
{ "ok": false, "error": "File not found" }
```

> **务必检查 `ok`** ——HTTP 200 仅表示请求到达了服务器；业务层面的成功/失败在 JSON 响应体中。

## 端点

### `GET /api/list` — 文件树

返回知识库的完整内容树（目录优先，遵循置顶/展开规则，排除隐藏路径）。

```bash
curl https://docs.dasiwo.com/api/list
```

```json
{
  "ok": true,
  "tree": [
    { "name": "guide", "path": "guide", "type": "dir",
      "children": [
        { "name": "api-reference.md", "path": "guide/api-reference.md", "type": "file" }
      ] }
  ]
}
```

- `type`：`"dir"` 或 `"file"`
- `path`：相对于知识库根目录，URL 编码（`/` 保留，空格编码为 `%20`）
- 通过 `children` 递归嵌套

### `GET /api/file?path=` — 读取笔记

读取单个笔记的**原始 Markdown 内容**（不经过服务端渲染管道）。适用于 `.md` 笔记。对于 `.excalidraw.md` 和 `.canvas` 文件也返回原始源码。

```bash
curl "https://docs.dasiwo.com/api/file?path=guide/api-reference.md"
```

```json
{
  "ok": true,
  "path": "guide/api-reference.md",
  "content": "# API Reference\n\n..."
}
```

错误：

| 状态码 | 响应体 | 含义 |
| --- | --- | --- |
| 400 | `{"ok":false,"error":"文件不存在"}` | 文件不存在或已被隐藏（排除路径的行为与缺失文件一致） |
| 400 | `{"ok":false,"error":"文件过大"}` | 文件超出大小限制 |

路径遍历尝试会返回 `400`。

### `POST /api/setup` — 首次运行设置密码（无需认证）

在尚未配置密码时设置管理员密码。

```bash
curl -H "Content-Type: application/json" \
  -d '{"password":"your-password"}' \
  https://docs.dasiwo.com/api/setup
```

```json
{ "ok": true }
```

密码必须至少 4 个字符。如果已设置密码，返回 `400` 和 `ok:false`。

### `POST /api/login` — 会话登录

```bash
curl -c cookies.txt -H "Content-Type: application/json" \
  -d '{"password":"your-password"}' \
  https://docs.dasiwo.com/api/login
```

```json
{ "ok": true }
```

设置会话 cookie（使用 `-c cookies.txt` 捕获）。密码错误 → `400` 和 `ok:false`。

### `GET /api/logout` — 结束会话

```bash
curl -b cookies.txt https://docs.dasiwo.com/api/logout
```

### `GET /api/admin/config` — 读取配置（需认证）

```bash
curl -b cookies.txt https://docs.dasiwo.com/api/admin/config
```

返回完整配置：挂载、渲染开关、自定义路径、排除/置顶/展开列表、站点标题、首页文章、主题默认值、字体预设、图谱设置。

### `POST /api/admin/config` — 更新配置（需认证）

```bash
curl -b cookies.txt -H "Content-Type: application/json" \
  -d '{"site_title":"My Docs","home_article":"guide/what-is-brainpress.md"}' \
  https://docs.dasiwo.com/api/admin/config
```

支持部分更新——仅写入你发送的字段。

### `POST /api/admin/password` — 修改管理员密码（需认证）

```bash
curl -b cookies.txt -H "Content-Type: application/json" \
  -d '{"current":"old-password","new":"new-password"}' \
  https://docs.dasiwo.com/api/admin/password
```

当前密码必须匹配；否则返回 `400` 和 `ok:false`。

### `GET /api/search?q=` — 全文搜索（服务端）

扫描每篇笔记的标题和内容以查找查询词（不区分大小写），返回路径 + 名称 + 首次匹配处的摘要。隐藏路径被排除。覆盖 `.md` 和 `.canvas` 内容。

```bash
curl "https://docs.dasiwo.com/api/search?q=Hyprland"
```

```json
{
  "ok": true,
  "query": "Hyprland",
  "count": 4,
  "results": [
    { "path": "knowledge/article/How-to-set-dark-theme-on-Hyprland-Arch.md",
      "name": "How-to-set-dark-theme-on-Hyprland-Arch",
      "snippet": "…the adapter boots into USB composite mass storage mode…" }
  ]
}
```

空查询 → `400` 和 `ok:false`。

### `GET /api/graph` — 图谱数据

返回 `/graph` 视图使用的节点和边：每篇笔记是一个节点，wiki 链接（`[[...]]`）是边。也可用于外部可视化。

```bash
curl "https://docs.dasiwo.com/api/graph"
```

```json
{
  "ok": true,
  "nodes": [ { "id": "guide/api-reference.md", "name": "api-reference" } ],
  "edges": [ { "source": "guide/a.md", "target": "guide/b.md" } ]
}
```

### `GET /api/article-list` — 简单文章列表

轻量级笔记列表（仅路径 + 名称）。无递归、无树结构——适用于订阅源、AI 摄取和仅需清单的脚本。

```bash
curl https://docs.dasiwo.com/api/article-list
```

```json
{
  "ok": true,
  "count": 28,
  "articles": [ { "path": "guide/api-reference.md", "name": "api-reference" } ]
}
```

### `GET /api/llms.txt` — LLM 友好索引

纯文本清单，遵循 [llms.txt](https://llmstxt.org) 规范：站点标题、一行描述，以及每篇笔记服务端渲染页面的链接。面向 AI 爬虫和希望一次获取整个知识库的工具。

```bash
curl https://docs.dasiwo.com/api/llms.txt
```

```text
# BrainPress Knowledge Base

> Markdown notes published at https://docs.dasiwo.com — plain Markdown, server-rendered pages.

- [What is BrainPress](https://docs.dasiwo.com/guilde/what-is-brainpress.md)
- [API Reference](https://docs.dasiwo.com/guilde/api-reference.md)
...
```

### `POST /api/note` — 创建或覆盖笔记（写入 API）

为代理和脚本提供远程写入访问。需要 Bearer 令牌（在管理面板 → Site → API token 中设置；空 = 端点禁用）。

```bash
TOKEN="your-api-token"
curl -X POST https://docs.dasiwo.com/api/note \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"path":"guide/notes/2026-08-13.md","content":"# Today\n\nWritten by an agent."}'
```

```json
{ "ok": true, "path": "guide/notes/2026-08-13.md", "bytes": 41, "created": true }
```

- 写入是**原子的**（临时文件 + 重命名）——崩溃不会留下半写入的笔记。
- 在尚不存在的目录中创建笔记 → `400`（需先通过 WebDAV `MKCOL` 创建目录，或使用已有文件夹）。
- 允许用相同路径覆盖已有笔记。
- 写入 API 仅针对主 `vault/`（挂载路径被有意排除）。

### `DELETE /api/note` — 删除笔记（写入 API）

```bash
curl -X DELETE https://docs.dasiwo.com/api/note \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"path":"guide/notes/2026-08-13.md"}'
```

```json
{ "ok": true, "path": "guide/notes/2026-08-13.md", "deleted": true }
```

**写入 API 规则**（均在服务端强制执行）：

| 规则 | 行为 |
| --- | --- |
| 缺失/错误令牌 | `401` |
| 令牌未配置 | `403`（端点禁用） |
| 路径不是 `.md` | `400` |
| 路径逃逸知识库（`..`、绝对路径） | `400` |
| 删除不存在的笔记 | `400` "文件不存在" |

### `POST /api/ask` — AI 对话（检索 + 回答）

使用知识库回答问题（对 `vault/` 进行检索 + OpenAI 兼容 LLM）。需要 AI 总开关开启且已配置 API 密钥 / base URL（管理面板 → AI）。只读——不会修改内容。

```bash
curl -X POST https://docs.dasiwo.com/api/ask \
  -H "Content-Type: application/json" \
  -d '{"question":"How does atomic write work?"}'
```

```json
{
  "ok": true,
  "answer": "The write API uses a temp file plus rename...",
  "sources": [
    { "path": "guide/api-reference.md", "name": "api-reference" }
  ]
}
```

**行为**：
- `hybrid` 模式（默认）：从知识库回答相关问题，附带 `sources`；否则从通用知识回答，`sources` 为空。
- `strict` 模式：仅从知识库回答——无匹配时返回"未找到相关文章..."。
- 中文问题经过分词处理（英文单词 + 2 字符滑动窗口），使检索在无空格时也能工作。

| 条件 | 响应 |
| --- | --- |
| AI 总开关关闭 | `403` "AI disabled" |
| 未配置 API 密钥 | `503` "AI not configured" |
| 空问题 | `400` |
| LLM 错误 | `502` 附带错误消息 |

## WebDAV（`/dav/`）— 读写同步

完整的 WebDAV 端点，用于 Obsidian Remotely Save（或任何 WebDAV 客户端）。使用配置的 WebDAV 凭据进行 Basic Auth（与管理员密码独立）。知识库根目录在 `/dav/` 提供。

```bash
curl -u "user:pass" -X PROPFIND https://docs.dasiwo.com/dav/
```

## 服务端渲染页面（"HTML API"）

每篇笔记也可作为完全服务端渲染的页面访问：

```
https://docs.dasiwo.com/guide/api-reference.md
```

服务器读取笔记、内联 Markdown 并返回完整页面——在任何 JavaScript 运行之前内容即可见。这也使网站可被搜索引擎爬取。

`.pdf` 路径以相同方式工作：通过 URL 请求知识库中的 PDF 会返回应用外壳，内置阅读器已为该文档预加载（无下载提示）——与笔记中 `![[book.pdf]]` 嵌入使用的共享阅读器界面相同。

`.excalidraw.md` 和 `.canvas` 路径也返回完全渲染的页面：Excalidraw 绘图（官方引擎 SVG）或 Canvas 白板（SVG 场景）在服务端预渲染，因此这些视图无需等待客户端渲染即可工作。

## 备注

- 所有 JSON 响应使用 `Content-Type: application/json; charset=utf-8`，且 `Cache-Control: no-store`（始终获取最新内容）。
- 公共读取端点无速率限制——它们是对本地文件的纯 PHP 读取。
- 排除（隐藏）路径始终不存在：不在 `/api/list` 中，`/api/file` 对它们返回"文件不存在"。
