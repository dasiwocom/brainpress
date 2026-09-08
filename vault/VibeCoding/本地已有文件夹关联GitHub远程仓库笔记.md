> 适用场景：GitHub上已经建好仓库，电脑本地已经存在项目文件夹，需要把两者绑定。 前提：本机已经配置好GitHub SSH密钥，可正常 `ssh -T git@github.com` 连通。

## 1、进入本地项目文件夹

```
cd ~/md2html
```

查看当前位置确认：

```
pwd
```

## 2、把文件夹初始化为Git仓库

```
git init
```

## 3、绑定远程仓库，给远程地址设置别名 origin

```
git remote add origin git@github.com:dasiwocom/md2html.git
```

- `origin`：远程仓库的别名，只是一个代号，代表后面这一串GitHub地址。后续所有操作直接写`origin`就代表这个远程仓库。
    
    > 如果报错 `fatal: remote origin already exists.`，代表这个仓库之前绑定过远程，先删除旧绑定： ```bash
    

> git remote remove origin ```

查看已经绑定的远程信息：

```
git remote -v
```

## 4、修改本地分支名称为main（GitHub默认分支名）

旧版本git初始化默认分支叫master，GitHub默认main，统一名字避免冲突。

```
git branch -M main
```

## 5、分两种情况处理

### 情况A：GitHub网页新建仓库时勾选了README / LICENSE / gitignore

👉远程仓库已经有文件，本地是一套独立git历史，需要先拉取合并

```
git pull origin main --allow-unrelated-histories
```

参数解释：

- `git pull`：拉取远程文件并且自动合并
- `origin main`：拉取origin这个远程的main分支
- `--allow-unrelated-histories`：允许两套互不相关的git提交历史进行合并。

### 情况B：GitHub仓库完全空白，没有任何文件

👉不要执行pull，跳过这一步。

## 6、提交本地文件

把文件夹内文件交给git管理，生成本地提交记录

```
git add .
git commit -m "initial commit"
```

> 如果文件夹完全为空，git不允许空提交，需要新建至少一个文件：

```
echo "# md2html" > README.md
git add README.md
git commit -m "initial commit"
```

## 7、推送到GitHub，绑定上游

```
git push -u origin main
```

- `-u`：设置上游关联，绑定完成后，后续操作就不用写完整地址分支。
    
    > 后续日常提交只需要简短命令：
    

```
git add .
git commit -m "修改说明"
git push
```

> 拉取远程更新只需要：

```
git pull
```

## 常用排查命令

1. 查看git状态

```
git status
```

2. 查看分支

```
git branch
```

3. 查看远程仓库地址

```
git remote -v
```

## 关键概念备忘

1. `origin`：远程仓库别名，仅对当前这个本地git仓库生效，换文件夹就失效。
2. `main`：主分支名称，GitHub默认。
3. `git init`：**只需要执行一次**，不要重复执行。
4. `--allow-unrelated-histories`：只第一次合并两套独立历史时才用，以后正常pull不需要。

## 两种建仓库方案区分

- 方案1（本篇笔记）：本地文件夹已经存在 → `git init` + `git remote add origin xxx`
- 方案2：本地还没有文件夹，直接从GitHub下载仓库：

```
git clone git@github.com:dasiwocom/md2html.git
```
