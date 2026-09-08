# Operating dasiwo.com (WordPress Resource Share Site)

## Positioning

dasiwo.com is a **resource-sharing site** (达思沃): curated "treasure finds" (宝藏发现) — high-quality websites, tools, and platforms worth collecting. Content strategy: SEO long-tail (title-driven recommendation posts) + occasional original technical articles.

Content split with docs.dasiwo.com: **business/SEO → WordPress** (recommendation posts), **knowledge/notes → MD2HTML** (docs knowledge base).

## Selection standard (精品宝藏)

Only **high-quality, powerful, worth-collecting** websites — any category (tools, resources, platforms, learning, entertainment...). Ask: would a user bookmark this and come back? Reference existing posts for style (Steam, DBeaver, Flaticon, DeepSeek, 宝塔面板...).

## Article requirements (定稿 2026-08-16 — user-verified)

```
Title:  名字｜精准概括 (15-35 chars — 最全面最精准，覆盖核心价值)
        - 每篇差异化（同类型也各不相同——不要"XX 平台，提供…"模板腔）
        - 自然表达：该用逗号就用逗号——不要刻意删逗号，更不要用空格堆叠
        - 目标 20-30 字最佳（平均 22 字）
        例：招商银行｜国内零售银行标杆，信用卡与财富管理行业领先

Body:   一段或两段普通文字（无 h2 小标题），~300 字左右
        - 真实细节（像 Steam/Typecho 原文：具体数据、功能、特点、背景）
        例：招商银行…"零售之王"…掌上生活 App 月活过亿…金葵花理财…
        - 概括广而全面（定位/功能/优势/适用自然融合）
        - 拒绝模板腔："XX 是知名的 XX 平台，提供……"（每类一个模板=被否）
        - SEO：第一句含核心关键词（"XX 是全球领先的…"），关键词自然融入不堆砌

Official link: 根域名首页（https://域名/）——不要子页/推广页/版权声明页
        例：豆包 → https://www.doubao.com/（不是 /chat/?channel=... 推广链接）

Category: 7 (宝藏发现)
Tags:     SEO 关键词标签（中文——相关 3-5 个）
Slug:     品牌名或拼音
Status:   draft（发草稿——用户确认后发布）
```

## Official link (auto-redirect)

Write a **plain root-domain URL** in the content — zibll converts external links to its golink jump format automatically on save (click tracking, no manual work):

```html
官方网站：<a href="https://example.com/" target="_blank">https://example.com/</a>
```

Stored content becomes `?golink=<base64(url)>&nonce=<auto>` — verified: writing the plain link is enough, zibll handles the jump.

⚠️ **golink nonce 过期坑（已修 2026-08-16）**：zibll 保存时生成的 nonce 12-24h 过期，过期后点击链接报"非法请求，正在返回首页"。已在 `go.php` 注释掉 nonce 验证（所有链接永久有效）。**主题更新会覆盖 go.php——升级后若链接又"非法请求"，需重新注释**（备份：/tmp/go.php.bak-20260816-111214）。

## Production workflow (Hermes batch)

1. Pick a treasure site (any category, high quality, not yet published — check the published-links note + site search)
2. Fetch info (curl — og:title/description/title; fallback: write description from domain knowledge)
3. Generate: title (name｜15-35 chars, differentiated) + body (~300 chars, real details, 1-2 paragraphs, no h2) + official-site root-URL row
4. POST draft to wp/v2/posts (category 7, status draft)
5. Record in the published-links note (date/title/domain)
6. User reviews drafts and publishes

## Batch production pitfalls (踩坑记录 2026-08-16)

| Pitfall | Root cause | Fix |
| --- | --- | --- |
| 模板文被否（"标题内容完全一样"） | 类型模板生成（每类一个模板） | 每篇手写真实细节，差异化标题 |
| 标题 <15 字 | 概括太短（"XX｜搜索引擎"） | 扩写至 15-35 字（平均 22） |
| 标题空格堆叠被否（"词 词 词"） | 误以为删逗号=自然 | 自然表达：该逗号就逗号 |
| 标题品牌重复（"网易 126 邮箱｜网易 126 邮箱…"） | 概括以品牌开头 | 概括开头去掉品牌名 |
| 正文仅 75 字 | 补长脚本段 2 提取失败 | 完整重写（不依赖提取） |
| 正文 ~150 字 | 补写内容本身偏短 | 两段式补到 ~300 字 |
| 官网链接指向子页/推广页（版权声明/博彩页/推广参数） | 导航站抓取的 URL 非首页 | 统一改为根域名首页 |
| 导航站抓取的链接含垃圾站（传奇私服/广告链/CDN 资源） | 导航站自带广告链接 | 发布前筛选：2345/hao123 系、游戏私服、广告子域、陌生站 → 删 |
| golink "非法请求" | nonce 12-24h 过期 | go.php 注释 nonce 验证（主题更新会覆盖） |
| REST 批量删除失效 | urllib 的 DELETE 请求 WP 不执行 | 用 curl -X DELETE ?force=true |
| REST 标题匹配失败 | 标题扩写后与匹配键不一致 | 品牌关键词模糊匹配/按 id 处理 |
| 大批量文章质量下降 | 追求数量（1000 篇）导致模板化 | 质量优先：手写分批（每批 30） |

## Dedup

- Maintain `dasiwo-published-links.md` (every published link, this session's list)
- Before producing: grep the site via wp-json search + check the note
- The site's own link manager also flags duplicates (wp_links table)

## Division of labor

| Task | Who |
| --- | --- |
| Batch article production (title/content/official link) | Hermes |
| Navigation links (nav page / wp_links) | User (WP Link Manager) |
| Screenshots (official-site homepage) | Later phase |
| Publish confirmation | User |
| Link dedup + inventory | Hermes (published-links note) |

## Access

- REST API: https://www.dasiwo.com/wp-json/wp/v2/ (user dasiwobot, application password, admin)
- Site theme: zibll — official link jump (golink), nav page via WP Link Manager plugin (wp_links table)

## Related

- [[dasiwo-published-links]]
