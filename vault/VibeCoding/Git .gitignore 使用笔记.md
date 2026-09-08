> `.gitignore` 是一个特殊文件，放在**仓库根目录**，用来告诉 Git：哪些文件/文件夹不要纳入版本控制，不要提交上传到 GitHub。 注意：**已经被Git跟踪过的文件，写进.gitignore不会自动删除，只对后续新文件生效**。

## 创建 .gitignore

进入仓库根目录：

```
cd ~/md2html
nano .gitignore
```

粘贴下面模板：

```
# 日志文件
*.log

# 图片
*.png
*.jpg
*.jpeg
*.gif
*.webp

# Python缓存
__pycache__/
*.pyc
*.pyo
*.pyd

# Node.js
node_modules/
npm-debug.log

# 系统生成文件
.DS_Store
Thumbs.db

# 临时文件、备份文件
*.bak
*.backup
*~

# 环境变量、密钥配置（千万不要上传密钥！）
.env
*.key
```

保存退出nano：`Ctrl+O` →回车 →`Ctrl+X`

## 将 .gitignore 加入版本管理

```
git add .gitignore
git commit -m "add gitignore config"
git push
```

## 语法简单说明

```
# # 代表注释
*.log        # 匹配所有 .log结尾的文件
__pycache__/ # 带斜杠 / 代表文件夹，匹配整个文件夹
/temp        # 只匹配仓库根目录下temp，子目录temp不生效
```

## 常见问题

### 问题：文件已经提交到GitHub，现在想忽略它

仅仅写进 `.gitignore` 没用，需要手动从git缓存移除：

```
git rm --cached 文件名
git commit -m "remove file from git track"
git push
```

> `--cached`：只从git版本记录移除，**本地磁盘文件保留不会删掉**。

### 问题：我不想提交某文件夹

直接写文件夹名字末尾加 `/`

```
output/
```

## 搭配日常完整流程

```
git status
git add .
git commit -m "这里写改动说明"
git push
```
