# 部署

BrainPress 是一个基于 Markdown 文件夹的单 PHP 入口点应用。部署就是复制文件——无需数据库、无需构建步骤、无需包管理器。

## 环境要求

- PHP 8.0+ 及 FPM（在 PHP 8.3 / nginx / LEMP 环境下测试通过）
- PHP 扩展：`mbstring`、`curl`（用于可选的 S3/MinIO）、标准文件函数
- 任何 Linux 主机；代码约 10 MB 磁盘空间，外加你的笔记

## 目录结构

```
docs.dasiwo.com/
├── index.php        # 主入口（前端 + API + WebDAV）
├── admin.php        # 管理面板（nginx 将 /admin、/api/admin/* 路由到此处）
├── functions.php    # 共享层
├── config.json      # 所有配置（密钥存放于此——必须阻止 Web 访问！）
├── assets/          # 仅本地库（marked、DOMPurify、lunr、highlight.js、pdf.js、KaTeX、Excalidraw vendor、字体）
└── vault/           # 你的笔记：.md 页面、.pdf 阅读器、.excalidraw.md 绘图、.canvas 白板
```

## nginx 核心配置

关键路由规则：

```nginx
# 前端控制器回退：无扩展名的别名 URL（如树中的 /Visual-Knowledge/Dashboard）
# 背后没有真实文件，必须到达 PHP
location /          { try_files $uri $uri/ /index.php?$query_string; }

# API + admin 路由
location ^~ /api/admin/ { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }
location ^~ /api/      { try_files $uri /index.php?$query_string; include enable-php-83.conf; }
location ^~ /admin     { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }

# 图谱管理页面（虚拟路由，由 index.php 处理）
location ^~ /graph     { try_files $uri /index.php?$query_string; include enable-php-83.conf; }

# Obsidian 同步用的 WebDAV
location ^~ /dav/ { try_files $uri /index.php?$query_string; include enable-php-83.conf; }

# 敏感文件必须返回 404（config.json、备份、日志、点文件……）
location ~* (\.user\.ini|config\.json|\.bak(up)?|\.log|\.sql|README\.md|composer\.json|\.env.*)$ { return 404; }

# Vault 静态文件（图片、阅读器加载的 PDF 原文件）——必须在 .pdf 规则之前
location ^~ /vault/ { }

# Markdown 路径服务端渲染（绝不提供原始 .md 文件）。
# .excalidraw.md 也会匹配 .md$，因此此单一规则覆盖 Markdown + Excalidraw。
location ~* \.md$ { rewrite ^(.*)$ /index.php last; }

# Canvas 白板服务端渲染
location ~* \.canvas$ { rewrite ^(.*)$ /index.php last; }

# PDF 页面 URL（无 /vault/ 前缀）打开内置阅读器页面
location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }
```

> 敏感文件规则必须在 `.md` 规则**之前**声明，这样 `README.md` 等文件名继续返回 404，同时知识库文章正常渲染。

## 宝塔面板一键配置

宝塔的**伪静态**配置框包含在站点 `server{}` 块内，因此 location 块可直接使用。部署 = 上传文件 + 粘贴一次——无需手动编辑生成的 vhost 配置。

1. 将以下文件上传到 Web 根目录（`/www/wwwroot/docs.dasiwo.com/`）：

   ```
   index.php  admin.php  functions.php  config.json  assets/  vault/
   ```

   （`router.php` 仅用于开发环境，可以不上传。）修复文件权限：

   ```bash
   chown -R www:www /www/wwwroot/docs.dasiwo.com
   ```

2. 站点 → 设置 → **伪静态** → 粘贴以下完整代码块 → 保存。面板会自动检查语法并重载 nginx。

   ```nginx
   # 别名 URL（树中的条目）必须到达 PHP——背后没有真实文件
   location /          { try_files $uri $uri/ /index.php?$query_string; }

   location ^~ /api/admin/ { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }
   location ^~ /api/      { try_files $uri /index.php?$query_string; include enable-php-83.conf; }
   location ^~ /admin     { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }
   location ^~ /graph     { try_files $uri /index.php?$query_string; include enable-php-83.conf; }
   location ^~ /dav/      { try_files $uri /index.php?$query_string; include enable-php-83.conf; }

   location ~* (\.user\.ini|config\.json|\.bak(up)?|\.log|\.sql|README\.md|composer\.json|\.env.*)$ { return 404; }

   location ^~ /vault/ { }

   location ~* \.md$  { rewrite ^(.*)$ /index.php last; }
   location ~* \.canvas$ { rewrite ^(.*)$ /index.php last; }
   location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }
   ```

3. 将 PHP 处理器匹配到你安装的版本：`enable-php-83.conf` 表示 PHP 8.3。在软件商店（软件商店）中查看实际版本并调整编号（`74` = 7.4，`00` = 无 PHP）。编号错误会导致 PHP 执行失败。

4. 打开 `/admin`，首次登录时设置管理员密码。

规则保存在每个站点独立的重写文件中，因此它们在面板升级和重新保存其他站点设置时不受影响。删除规则 = 清空配置框。

## 首次运行

1. 将站点文件夹复制到 Web 根目录。
2. 使 `vault/`（和站点文件夹）可被 PHP-FPM 用户写入：`chown -R www:www /www/wwwroot/docs.dasiwo.com`。
3. 打开网站——前端会立即使用 `vault/` 中的所有 `.md` 文件正常工作。
4. 访问 `/admin`，首次登录时设置管理员密码。

## 对外订阅与收录（RSS / Sitemap）

BrainPress 对外发布时提供两个给读者 / 搜索引擎的地址，二者都由 `index.php` 处理（前端控制器兜底规则会让它们命中 PHP，无需额外配置）：

- **`/rss.xml`** — RSS 订阅源。列出最新（按修改时间倒序）的公开文章，供访客填入阅读器（如 Feedly）自动跟踪更新。未公开（`published: false` / `draft: true`）的文章不会出现在其中。
- **`/sitemap.xml`** — 站点地图。列出所有公开文章地址，供 Google 搜索控制台 / 百度站长平台收录。未公开文章同样被排除。

发布后把这两个地址提交给搜索引擎即可：
- Google：搜索控制台 → 站点地图 → 填入 `https://your.site/sitemap.xml`
- 百度：百度站长平台 → 普通收录 → 站点地图

## 部署后验证

```bash
curl -s -o /dev/null -w "%{http_code}" https://your.site/                  # 200
curl -s -o /dev/null -w "%{http_code}" https://your.site/api/list          # 200
curl -s -o /dev/null -w "%{http_code}" https://your.site/guide/what-is-brainpress.md   # 200 (SSR)
curl -s -o /dev/null -w "%{http_code}" https://your.site/config.json       # 404 (必须被阻止！)
curl -s -o /dev/null -w "%{http_code}" https://your.site/admin             # 200
curl -s -o /dev/null -w "%{http_code}" https://your.site/graph             # 200
curl -s -o /dev/null -w "%{http_code}" https://your.site/rss.xml           # 200 (RSS 订阅源)
curl -s -o /dev/null -w "%{http_code}" https://your.site/sitemap.xml       # 200 (站点地图)
```

## 更新与备份

- 站点是无状态的：**备份 = 复制 `index.php`、`admin.php`、`functions.php`、`assets/`、`config.json`、`vault/`**。
- 备份存放在 Web 根目录之外（如 `/www/wwwroot/backup/`）。
- 恢复 = 解压归档、修复文件权限（`chown -R www:www`），完成。

## 安全检查清单

- [ ] `config.json` 通过 HTTP 访问返回 404（密钥存放在其中）
- [ ] PHP-FPM 以非特权用户（`www`）运行，而非 root
- [ ] `.bak`/`.log`/点文件被敏感文件规则阻止
- [ ] 首次登录后已修改管理员默认密码
- [ ] 备份存放在 Web 根目录之外
