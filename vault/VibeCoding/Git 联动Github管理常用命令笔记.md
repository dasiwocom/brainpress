> 前提：已经配置好 GitHub SSH 密钥，可正常访问远程仓库。 注意：所有 git 命令，**必须在 git 仓库文件夹内执行**（文件夹里面有隐藏 `.git` 目录）。

## 一、基础仓库操作

### 1. 把本地文件夹变成Git仓库（仅执行一次）

```
git init
```

### 2. 克隆远程仓库到本地（电脑还没有项目文件夹）

```
git clone git@github.com:dasiwocom/仓库名.git
```

### 3. 绑定远程仓库（本地已有文件夹，关联GitHub）

```
git remote add origin git@github.com:dasiwocom/仓库名.git
```

查看已绑定远程：

```
git remote -v
```

如果之前绑定过，报错 `remote origin already exists`，删除旧绑定：

```
git remote remove origin
```

## 二、文件提交（本地操作，还没有上传GitHub）

### 1. 查看当前状态（非常常用）

```
git status
```

标记含义：

- `[?]`：全新未跟踪文件
- `[!]`：已跟踪文件被修改

### 2. 将文件加入暂存区

```
# 添加全部文件
git add .

# 只添加单个文件
git add filename.md
```

### 3. 生成本地提交记录

```
git commit -m "写清楚本次做了什么修改"
```

## 三、和远程GitHub交互

### 1. 推送本地提交到远程

第一次推送，绑定上游：

```
git push -u origin main
```

绑定完成后，后续直接简写：

```
git push
```

### 2. 拉取远程最新代码到本地

```
git pull
```

> ⚠️特殊场景：本地init，远程已经有README，第一次拉取：

```
git pull origin main --allow-unrelated-histories
```

## 四、分支相关

查看本地分支

```
git branch
```

重命名当前分支为 main

```
git branch -M main
```

## 五、切换文件夹（终端）

```
# 进入项目文件夹
cd ~/md2html

# 返回上一级目录
cd ..

# 回到家目录
cd

# 在两个目录来回跳转
cd -
```

## 六、概念速记

1. **origin**：远程仓库别名，只在当前仓库有效，代表你的GitHub地址。
2. **main**：GitHub默认主分支。
3. `git add`：告诉git要管哪些文件。
4. `git commit`：在本地生成版本快照，**不会上传网络**。
5. `git push`：把本地commit上传到GitHub。
6. `git pull`：把GitHub上面的更新下载合并到本地。

## 七、常见报错小处理

1. `fatal: remote origin already exists.`
    
    > 已经绑定过远程，执行 `git remote remove origin` 再重新绑定。
    
2. `error: unknown option`
    
    > 参数拼写错误，仔细核对命令。
    
3. 推送失败，提示远程有新内容
    
    > 先执行 `git pull`，解决冲突后再push。
    

## 八、完整日常工作流程（记住这套）

```
#1 修改文件
#2 查看状态
git status
#3 添加文件
git add .
#4 本地提交
git commit -m "修改描述"
#5 上传到GitHub
git push
```

> 提示：`.gitignore` 文件，用来告诉git哪些文件不要纳入版本管理（日志、缓存、图片等）。
