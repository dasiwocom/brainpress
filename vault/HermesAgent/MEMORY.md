BT Panel offline 多为端口/HTTPS 问题非宕机；操作范围严格限于用户指定分类
§
用户恨 emoji；极简统一格式；微信短消息（长表格/代码块致卡顿）；任务结束必有完成报告（做了什么/怎么验证）——从不静默收尾
§
无 cron 手动按需；服务器重型批处理分批防 OOM（3.6G 内存）
§
认证：/admin session 密码；WebDAV 独立凭据；dasiwobot=REST 密码
§
写笔记规范：现象→原理→解决、面向陌生开发者；标题贴切；英文；文件名=标题空格换-；类比讲解；客观详细忌片面。授权：踩坑/被纠错时判断价值直接发文无需再确认（唯一豁免）
§
docs 定位=人+AI 协作知识库且对外使用；拍板：专注网页私有化（数据主权）；md 边界：静态 md/动态后端渲染/交互控件 HTML
§
改服务器文件后属主变 root+权限 600（FPM Fatal），须 chown www:www+chmod 644；chmod -R 644 会连目录一起改（目录需 x/755）致 404；memory 保存后 chmod 644；改 index.php/admin.php 验证前 sleep≥3（OPcache）；EdgeOne 缓存 SSR 页，改代码后验证用新 URL 或刷缓存；大范围替换先验边界内函数完整+grep 验证（误删 showArticle 致全站 md 挂）
§
用户行为期望：复杂问题先分析确认；'回退'=只回退指定项；改配置先解释再动；改主题文件必须先告知；'停'=立即停手；方案明确后直接执行勿反复确认；排查先自查自测（curl 实测接口）再让他点；破坏性/删除测试绝不用真实数据（授权'可用图测试'≠允许删图，曾误删3张COS图致怒）
§
MD2HTML 8-17 已升版；GitHub 已推 5 仓中英双语 README
§
项目（代码/UI 文案/注释/菜单名）一律英文（'四个字'=英文四字母，如 Hide）；记忆文件保持中文
§
config 键：exclude_paths/home_article/api_token/ai_*/graph_show_labels
§
AI 问答已落地：/api/ask；后台字段改三处缺一清空 config
§
分段消息流先收齐再动手；中途改了必列改动清单
§
外部 AI 建议对照用户偏好评估不盲从；勿擅自扩展方案范围
§
WP 产文标准（2026-08-16 定稿）：标题 15-35 差异化勿卡点+正文 ~300 字 3 段式无 h2 真实具体（字数以 len(去标签) 验证，手估虚高 30%）+官网行完整 https 文字可点击（zibll go_link_s 开启时 <a> 外链保存自动转 golink，显示完整 https 文字即可用户接受）；达标 250+ 发「暂未分类」(cat=1) publish；批量=一批一汇报等确认；坑：urllib 须 method='PUT'、发布前查重同品牌、删除用 curl、per_page 100
§
删除类操作 execute_code 常被拦截→用 terminal curl 逐条删；查媒体库带 after= 时间参数；截图工作流：传截图（文件名=域名）匹配文章插末尾→插后从链接笔记删链接
§
任务前先查 skill 库再动手（'光生产不常用'）
§
工作产出规则：外部生产的脚本/源码/笔记/成果一律存 ~/.hermes/workspace/（scripts/projects/notes/output 分类），不散落 /tmp、/root；备份 ~/.hermes 即带走全部
§
用户处境：内容站等 SEO 期无收入、DeepSeek 涨价成本压力；聊变现只讨论别推销
§
项目规范：projects/ 下所有项目按 GitHub 标准（README/LICENSE/CHANGELOG/.gitignore/无凭据/无硬编码路径）；toolbox=一个仓库多工具，每工具版本子目录只放纯净可发布版；跨大版本先传 GitHub 再删旧版；大改动记版本号微调不记
§
宝塔 15574；截图工具本地 Arch 跑（--workers 3）；GitHub SSH 已配（dasiwocom@gmail.com）
§
交付物开箱即用：初始文件/默认配置直接建好（勿让用户手动 cp/配置，如 urls.txt 教训）
§
复刻主题原生类名不自创；脚本版本号用 filemtime 防缓存；图片处理一律前端 JS 后端不做 GD；link-manager v2.3.0 已彻底移除 COS 与 logo 本地化（恢复纯净），保留批量改Logo/导出Logo/导出URL/缩略图→媒体库
§
2026-08-18 深夜用户重新搭建 WP 环境：数据库重置、uploads 重建为 forums/links/posts/shop/site 空目录、无 wmm_cos_server_config；后续服务器操作前先实测现状勿信旧状态；wp-post-manager 已加别名列+模板标题剥离
§
test