
> 文档说明：本文包含 **通用标准Markdown、GFM GitHub扩展语法、Obsidian 专属扩展语法**，附带「源码写法」+「预览渲染效果」，同时提供多种语法组合实战案例，可直接复制进Obsidian测试。 适配范围：Obsidian 官方原生支持语法（不含第三方插件语法，如 Dataview、Templater）

---

## 目录

1. [YAML 前置元数据（Frontmatter，Obsidian核心）](#1-yaml-%E5%89%8D%E7%BD%AE%E5%85%83%E6%95%B0%E6%8D%AEfrontmatterobsidian%E6%A0%B8%E5%BF%83)
2. [标题语法（H1~H6）](#2-%E6%A0%87%E9%A2%98%E8%AF%AD%E6%B3%95h1h6)
3. [文字基础样式：粗体、斜体、删除线、高亮、行内代码](#3-%E6%96%87%E5%AD%97%E5%9F%BA%E7%A1%80%E6%A0%B7%E5%BC%8F%E7%B2%97%E4%BD%93%E6%96%9C%E4%BD%93%E5%88%A0%E9%99%A4%E7%BA%BF%E9%AB%98%E4%BA%AE%E8%A1%8C%E5%86%85%E4%BB%A3%E7%A0%81)
4. [列表：无序列表、有序列表、任务清单（待办）](#4-%E5%88%97%E8%A1%A8%E6%97%A0%E5%BA%8F%E5%88%97%E8%A1%A8%E6%9C%89%E5%BA%8F%E5%88%97%E8%A1%A8%E4%BB%BB%E5%8A%A1%E6%B8%85%E5%8D%95%E5%BE%85%E5%8A%9E)
5. [引用块、多级嵌套引用](#5-%E5%BC%95%E7%94%A8%E5%9D%97%E5%A4%9A%E7%BA%A7%E5%B5%8C%E5%A5%97%E5%BC%95%E7%94%A8)
6. [代码块（多语言语法高亮）](#6-%E4%BB%A3%E7%A0%81%E5%9D%97%E5%A4%9A%E8%AF%AD%E8%A8%80%E8%AF%AD%E6%B3%95%E9%AB%98%E4%BA%AE)
7. [表格](#7-%E8%A1%A8%E6%A0%BC)
8. [脚注](#8-%E8%84%9A%E6%B3%A8)
9. [水平分割线](#9-%E6%B0%B4%E5%B9%B3%E5%88%86%E5%89%B2%E7%BA%BF)
10. [通用超链接 & Obsidian 双链（WikiLink）](#10-%E9%80%9A%E7%94%A8%E8%B6%85%E9%93%BE%E6%8E%A5--obsidian-%E5%8F%8C%E9%93%BEwikilink)
11. [嵌入文件（Obsidian 特有：嵌入笔记、图片、PDF、音视频、画布）](#11-%E5%B5%8C%E5%85%A5%E6%96%87%E4%BB%B6obsidian-%E7%89%B9%E6%9C%89%E5%B5%8C%E5%85%A5%E7%AC%94%E8%AE%B0%E5%9B%BE%E7%89%87pdf%E9%9F%B3%E8%A7%86%E9%A2%91%E7%94%BB%E5%B8%83)
12. [块ID + 块引用（精确跳转到段落，Obsidian灵魂功能）](#12-%E5%9D%97id--%E5%9D%97%E5%BC%95%E7%94%A8%E7%B2%BE%E7%A1%AE%E8%B7%B3%E8%BD%AC%E5%88%B0%E6%AE%B5%E8%90%BDobsidian%E7%81%B5%E9%AD%82%E5%8A%9F%E8%83%BD)
13. [标签系统](#13-%E6%A0%87%E7%AD%BE%E7%B3%BB%E7%BB%9F)
14. [注释（预览不可见，仅编辑视图可见）](#14-%E6%B3%A8%E9%87%8A%E9%A2%84%E8%A7%88%E4%B8%8D%E5%8F%AF%E8%A7%81%E4%BB%85%E7%BC%96%E8%BE%91%E8%A7%86%E5%9B%BE%E5%8F%AF%E8%A7%81)
15. [Callout 提示块（Obsidian 原生调用框）](#15-callout-%E6%8F%90%E7%A4%BA%E5%9D%97obsidian-%E5%8E%9F%E7%94%9F%E8%B0%83%E7%94%A8%E6%A1%86)
16. [LaTeX 数学公式（行内公式 / 公式块）](#16-latex-%E6%95%B0%E5%AD%A6%E5%85%AC%E5%BC%8F%E8%A1%8C%E5%86%85%E5%85%AC%E5%BC%8F--%E5%85%AC%E5%BC%8F%E5%9D%97)
17. [HTML 原生支持](#17-html-%E5%8E%9F%E7%94%9F%E6%94%AF%E6%8C%81)
18. [多语法组合实战示例（重点！日常写笔记组合用法）](#18-%E5%A4%9A%E8%AF%AD%E6%B3%95%E7%BB%84%E5%90%88%E5%AE%9E%E6%88%98%E7%A4%BA%E4%BE%8B%E9%87%8D%E7%82%B9%E6%97%A5%E5%B8%B8%E5%86%99%E7%AC%94%E8%AE%B0%E7%BB%84%E5%90%88%E7%94%A8%E6%B3%95)

---

## 1. YAML 前置元数据（Frontmatter，Obsidian核心）

放在笔记最顶部，`---` 包裹，用来定义笔记别名、标签、日期、属性，可配合反向链接、搜索、画布使用

```
---
aliases: ["大脑印刷机", "BrainPress项目文档"]
tags: ["项目开发/php知识库", "AI知识库"]
created: 2026-08-24
updated: 2026-08-24
status: 进行中
---
```

> 作用：`aliases` 别名支持双链通过别名跳转，属性可用于Obsidian内部筛选

## 2. 标题语法（H1~H6）

```
# H1 一级标题（整篇文档主标题，建议一篇笔记只用1个）
## H2 二级标题
### H3 三级标题
#### H4 四级标题
##### H5 五级标题
###### H6 六级标题
```

## 3. 文字基础样式：粗体、斜体、删除线、高亮、行内代码

|源码写法|预览效果|备注|
|---|---|---|
|`*斜体文字*`|_斜体文字_|通用Markdown|
|`_斜体文字_`|_斜体文字_|通用Markdown|
|`**粗体文字**`|**粗体文字**|通用Markdown|
|`__粗体文字__`|**粗体文字**|通用Markdown|
|`***粗斜体文字***`|_**粗斜体文字**_|组合写法|
|`~~删除线文字~~`|~~删除线文字~~|GFM扩展|
|`==高亮文字==`|==高亮文字==|✅ Obsidian专属扩展|
|`` `行内代码` ``|`行内代码`|通用Markdown|

## 4. 列表：无序列表、有序列表、任务清单（待办）

### 无序列表（支持嵌套）

```
- 一级项目
  - 二级嵌套项目
    - 三级嵌套项目
- 第二个一级项目
```

### 有序列表

```
1. 第一项
2. 第二项
   3. 嵌套子项
   4. 嵌套子项第二条
5. 第三项
```

### 任务清单（待办列表，GFM + Obsidian原生支持）

```
- [ ] 未完成任务：学习Obsidian语法
- [x] 已完成任务：搭建BrainPress项目
- [ ] 待优化：对接对象存储
```

## 5. 引用块、多级嵌套引用

```
> 一级引用段落
>> 二级嵌套引用
>>> 三级嵌套引用
> 引用内可以**粗体**、==高亮==、`行内代码`，支持混合格式
```

## 6. 代码块（多语言语法高亮）

三反引号包裹，第一行填写语言名称实现语法高亮，支持几乎所有编程语言

````
```php
<?php
// BrainPress 示例代码
$content = "渲染Markdown文章";
echo $content;
```

```bash
# Debian 终端命令示例
sudo apt update
```

```javascript
function test() {
  console.log("前端JS代码演示");
}
```
````

## 7. 表格

```
| 功能模块 | 技术栈 | 完成状态 |
| ---- | ---- | ---- |
| Markdown渲染 | PHP | ✅ 完成 |
| 对象存储对接 | SDK | ⏳ 开发中 |
| AI笔记写入 | Ollama | 📋 规划 |

# 对齐控制（左、中、右）
| 左对齐 | 居中对齐 | 右对齐 |
| :--- | :---: | ---: |
| 内容 | 内容 | 内容 |
```

## 8. 脚注

```
人脑的记忆存在遗忘曲线[^footnote1]

[^footnote1]: 艾宾浩斯遗忘曲线，1885年提出，Obsidian知识库用来对抗遗忘。
```

## 9. 水平分割线

```
---
***
```

任意一种均可，渲染为横向分割线

## 10. 通用超链接 & Obsidian 双链（WikiLink）

### 通用外部链接（标准Markdown）

```
[Obsidian官网](https://obsidian.md "鼠标悬浮提示文字")
<https://obsidian.md> 自动识别链接
```

### ✅ Obsidian 双链（WikiLink，核心特色，生成双向链接）

```
[[BrainPress项目文档]]
# 自定义显示文本
[[BrainPress项目文档|大脑印刷机项目]]
# 跳转到目标笔记内指定标题
[[BrainPress项目文档#功能架构]]
# 跳转到目标笔记内【指定块】（后文块ID讲解）
[[BrainPress项目文档#^core-logic]]
```

> 关键特性：写入双链后，目标笔记右侧「反向链接」面板自动收录引用关系，支撑知识图谱

## 11. 嵌入文件（Obsidian 特有：嵌入笔记、图片、PDF、音视频、画布）

> 语法：`![[文件名称]]`，区别于双链 `[[ ]]`：双链=跳转；嵌入=直接把内容渲染在当前笔记内

```
# 嵌入整篇笔记
![[BrainPress项目文档]]

# 仅嵌入目标笔记内指定标题章节
![[BrainPress项目文档#功能架构]]

# 嵌入图片，限定宽度
![[screenshot.png|400]]

# 嵌入PDF，指定页码
![[手册.pdf#page=3]]

# 嵌入Obsidian Canvas无限画布文件
![[流程图.canvas]]

# 嵌入音频 / 视频
![[demo.mp3]]
![[demo.mp4]]
```

## 12. 块ID + 块引用（精确跳转到段落，Obsidian灵魂功能）

### 第一步：给任意段落添加块ID（段落末尾空格 + `^自定义id`，英文小写、短横）

```
这是一段需要被外部笔记精准引用的核心业务逻辑 ^core-logic
```

### 第二步：其他笔记引用 / 嵌入这个块

```
# 跳转链接到这个块
[[BrainPress项目文档#^core-logic]]

# 直接把这个段落嵌入当前笔记渲染
![[BrainPress项目文档#^core-logic]]
```

> 使用场景：论文引用、规范复用、知识库片段复用，不用复制粘贴全文

## 13. 标签系统

Obsidian原生标签，支持嵌套标签，支持标签面板筛选

```
#项目开发
#AI/知识库
#php/markdown渲染
#2026/08/24
```

> 规则：标签不能包含空格，`/` 代表多级嵌套

## 14. 注释（预览不可见，仅编辑视图可见）

```
%%
多行注释内容
预览模式完全隐藏，只有编辑模式可以看到
适合写草稿、临时备注、开发todo
%%

单行注释 %% 这里是单行隐藏注释 %%
```

## 15. Callout 提示块（Obsidian 原生调用框）

> 语法 `> [!类型] 标题`，支持折叠展开，内置多种预设类型：note、tip、warning、danger、info、example

```
> [!note] 普通提示
> 基础说明文字，可搭配**粗体**、`代码`

> [!warning] ⚠️ 重要警告
> 对象存储公网访问会产生流量计费，务必使用内网Endpoint

> [!tip] 💡 优化建议
> 后端中转渲染文章，不要暴露OSS直链

> [!danger] 高危提醒
> 不要直接把Ubuntu deb包安装到Debian，依赖极易冲突

> [!example] 示例演示
> 双链写法：[[笔记名称]]

# 可折叠（添加 `-`）
> [!info]- 可折叠信息块（默认收起）
> 点击箭头展开内容
```

## 16. LaTeX 数学公式（行内公式 / 公式块）

Obsidian 原生支持 LaTeX，适合技术、理科笔记

```
# 行内公式 $...$
牛顿第二定律：$F=ma$

# 独立公式块 $$ ... $$
$$
E = mc^2
$$
```

## 17. HTML 原生支持

Obsidian 支持直接嵌入简单HTML（复杂HTML内部不会解析Markdown语法）

```
<span style="color:red;">红色文字演示</span>
<br>
<hr>
```

---

## 18. 多语法组合实战示例（重点！日常写笔记组合用法）

> 复制下面整段到Obsidian，查看混合渲染效果，覆盖80%日常写作场景

````
---
aliases: ["Obsidian语法示例笔记"]
tags: ["知识库/语法", "Obsidian教程"]
created: 2026-08-24
---

# Obsidian + BrainPress 知识库方案 H1
## 整体架构 H2
> [!tip] 方案定位
> [[BrainPress项目文档|大脑印刷机]]：Obsidian作为**第二大脑存储知识库**，BrainPress实现AI自主记笔记、内容批量输出。

### 核心流程 H3
1.  人工维护 Markdown 笔记存入Vault
2.  - [x] 对接对象存储
    - [ ] AI自动摘要入库
    - [ ] 网页渲染发布
3.  核心代码片段：
```php
<?php
// 读取md文件并渲染
$md_content = file_get_contents("article.md");
````

### 成本注意事项 H3

> [!warning] 计费提醒 服务器读取对象存储：**同地域内网免费**，公网访问 ~~会产生高额流量费~~，必须配置内网Endpoint。 参考文档：[[对象存储接入规范#^oss-rule]]

|组件|部署位置|计费说明|
|---|---|---|
|BrainPress后端|云服务器|服务器带宽承担网页流量|
|Markdown源文件|对象存储|内网读取几乎零成本|

### 配套学习资料

- 官方文档：[Obsidian Flavored Markdown](https://obsidian.md/help/obsidian-flavored-markdown)
- 示例画布：![[架构流程图.canvas]]

%% 临时备注：后续补充Ollama对接演示，预览看不见本段 %%

```

---
## 19. Mermaid 流程图 & 选择性发布（BrainPress 渲染扩展）

> 说明：Mermaid 在 Obsidian 中属第三方插件语法（如 Bat.apK 的 Mermaid），但 **BrainPress 已原生内置渲染**——在任意笔记里写 ```` ```mermaid ```` 代码块即自动变成图形。选择性发布也是 BrainPress 服务端扩展。

### 19.1 Mermaid 图表（BrainPress 自动渲染）

> 用 ```` ``` ```` 代码块写图，打开笔记时自动渲染成图形。该代码块不参与代码高亮、不加行号。

**流程图（graph）**
````
```
graph LR
    A[开始] --> B{能渲染吗?}
    B -- 能 --> C[显示为图形 ✅]
    B -- 不能 --> D[显示为代码]
```
````

**时序图（sequence）**
````
```
sequenceDiagram
    participant 用户
    participant 网站
    用户->>网站: 请求文章
    网站->>网站: 渲染 Markdown
    网站-->>用户: 返回正文
```
````

**思维导图（mindmap）**
````
```
mindmap
  root((知识库))
    写作
      笔记
      发布
    阅读
      图谱
      标签
```
````

**甘特图（gantt）**
````
```
gantt
    title 项目排期
    dateFormat YYYY-MM-DD
    section 设计
      原型    :a1, 2026-09-01, 3d
    section 开发
      前端    :a2, after a1, 5d
      后端    :a3, after a2, 5d
```
````

### 19.2 选择性发布（frontmatter）

笔记顶部 `---` 内写 `published: false` 或 `draft: true`，该笔记即对访客隐藏（树/搜索/图谱/直接访问/RSS/Sitemap 全移除），作者仍可在 Obsidian 编辑。

````
---
title: 草稿
published: false
---

# 访客看不到这段
````

实例演示见 [[MarkDown]].

---
# 补充边界说明
1. 本文全部为 **Obsidian原生内置语法**，不需要安装任何第三方插件；
2. 第三方插件扩展语法（Dataview 查询、Excalidraw绘图）不在本文范围；其中 **Mermaid 流程图 BrainPress 已原生支持**——如需，可参考 [[MarkDown]] 的完整实例；
3. 标准Markdown兼容：导出PDF、复制到大部分博客平台时，基础语法通用，**Obsidian特有语法（双链、块引用、callout、==高亮==）在外部平台会失效**。

如果你需要，我可以再输出一份【极简速查表】适合放在Obsidian笔记顶部随时查阅。
```