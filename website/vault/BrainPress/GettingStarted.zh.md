# 快速入门

BrainPress 将一个 Markdown 笔记文件夹转化为实时网站——无需构建步骤、无需数据库。同一文件夹中的 PDF、Excalidraw 绘图和 Obsidian Canvas 白板会在内置查看器中打开，还可选启用 AI 助手来回答关于你知识库的问题。

## 1. 部署

1. 将项目文件夹复制到服务器（任何 LEMP 环境，PHP 8.0+）。
2. 配置 nginx：将 `/admin` 和 `/api/admin/*` 路由到 `admin.php`，将 `.md`、`.pdf` 和 `.canvas` 路径重写到 `index.php`，静态提供 `/vault/`，并阻止 `config.json`（具体规则见 `Deployment.md`）。
3. 打开网站——空知识库也能正常工作（显示占位页面）。

## 2. 添加内容

- 将 `.md` 笔记放入 `vault/`。子文件夹在侧边栏树中显示为分类。
- 将 `.pdf` 书籍放入 `vault/` 的任意位置——它们会出现在树中并在内置阅读器中打开（大纲、页面导航、缩放预设、全屏）。PDF 也可使用 Obsidian 的 `![[book.pdf]]` 语法嵌入笔记——嵌入和独立页面共享同一阅读器界面，`[[book.pdf#page=3]]` 可深度链接到指定页面。
- 在 **Excalidraw** 中绘图并保存为 `something.excalidraw.md`——使用官方 Excalidraw 引擎渲染（与 Obsidian 一致）。文件通过 `.excalidraw.md` 扩展名或内容（`excalidraw-plugin:` 标记 + ```` ```compressed-json ```` 块）被识别为 Excalidraw，因此重命名或粘贴的文件仍能正确渲染。
- 构建 **Obsidian Canvas** 白板（`.canvas` 文件）——渲染为实时 SVG 场景（文本/文件/链接/分组节点和贝塞尔箭头），通过直接 URL 和树中点击均可访问。
- 使用任意编辑器（或通过 WebDAV 同步的 Obsidian）编辑笔记——下次访问时即生效。无需重新构建。

## 3. 配置

访问 `/admin`，首次登录时设置管理员密码。管理面板视图如下：

- **WebDAV** —— 管理**同步存储**：创建一个或多个同步账号（各自独立的用户名/密码，绑定 `vault/` 下一个同步目录），供 Obsidian Remotely Save 使用。
- **Mounts** —— 选择哪些内容被**渲染**到前台：同步 vault（一个开关，默认关闭）、可选 MinIO/S3 桶，以及最多 5 个自定义本地挂载路径。渲染只读文件。
- **Preferences** —— 默认浅色模式、固定导航栏（始终可见）和**字体预设**（系统无衬线，或自托管 DejaVu Serif 承担西文；中文始终用系统字体）。
- **Site** —— 站点标题、首页文章、内容宽度（阅读列像素数）和写入 API 的 API 令牌。
- **AI** —— OpenAI 兼容的对话设置（API base URL、密钥、模型、启用开关、混合/严格模式）和只读 Agent API 信息。
- **Graph** —— 图谱视图设置（显示文件名、路径别名）。
- **Tree** —— 隐藏路径、置顶目录/文章、强制展开目录。

## 4. 启用 AI 助手（可选）

1. 从 OpenAI 兼容的提供商获取 API 密钥（默认为 platform.deepseek.com 的 DeepSeek；按用量付费，价格低廉）。
2. 管理面板 → **AI** → 粘贴密钥（API key），保持模型为 `deepseek-chat`，或设置 **API base URL** 为网关（NewAPI/one-api）或本地模型如 `http://127.0.0.1:11434/v1`（Ollama/LM Studio；本地推理无需密钥时留空）。
3. 确保 **AI enabled** 已开启。导航栏中会出现提问按钮。

向知识库提问：回答来自你的笔记并附带来源链接。在混合模式（默认）下，超出知识库范围的问题会从通用知识中回答；关闭 **Hybrid mode** 则严格仅从知识库回答。

## 5. 日常使用

- 浏览树形结构、阅读笔记、打开 PDF、查看绘图和白板，或在 `/graph` 打开知识图谱。
- 使用搜索框进行全文搜索。
- AI 面板一键可达——它读取与访客相同的知识库。
- 笔记通过 WebDAV（`/dav/`，Basic Auth）从 Obsidian 同步。同步完成后，若要发布这些笔记，还需要在管理面板 → **Mounts** 打开 **Render synced vault** 开关——同步负责存储，渲染负责发布。直接在服务器上编辑文件同理。

就是这样。知识库就是产品——网站只是它的呈现方式。
