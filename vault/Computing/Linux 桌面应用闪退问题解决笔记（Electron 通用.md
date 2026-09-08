## 一、哪些软件会闪退
Linux 下所有 **Electron / Chromium 内核**软件极易闪退：
- QQ音乐（deb 版）
- Linux QQ
- Linux 微信
- 部分新版客户端、IDE

## 二、闪退根本原因
Linux Mint / Ubuntu 系列系统对 **Chromium 沙箱（sandbox）** 权限限制严格，Electron 程序默认开启沙箱会冲突、直接崩溃退出。

## 三、万能解决原理
启动命令加参数：
```
--no-sandbox
```
作用：**关闭冲突沙箱，完美解决闪退**
本地日常使用安全，无风险。

## 四、临时测试是否有效（所有软件通用）
终端直接运行：
```bash
# QQ音乐
qqmusic --no-sandbox

# Linux QQ
qq --no-sandbox

# 微信
wechat --no-sandbox
```
能正常打开 = 沙箱问题，可永久修复。

## 五、永久修复（deb 安装软件）
### 1. 编辑软件启动配置文件
以 QQ音乐 为例：
```bash
sudo nano /usr/share/applications/qqmusic.desktop
```

### 2. 修改 Exec 启动行
原内容（会闪退）：
```
Exec=/opt/qqmusic/qqmusic %U
```

修改后（修复闪退）：
```
Exec=/opt/qqmusic/qqmusic --no-sandbox
```

### 3. 保存并刷新系统缓存
```bash
update-desktop-database /usr/share/applications/
```


## 六、还原官方原版（以后需要恢复时）
改回原始启动参数即可：
```
Exec=/opt/qqmusic/qqmusic %U
```

## 七、通用总结
1. Linux 90% 软件闪退 = **Electron 沙箱冲突**
2. 万能解法：启动参数加 `--no-sandbox`
3. 永久修复：修改对应 `.desktop` 文件
4. 搭配输入法必须遵守「**先开软件、后切中文**」

需要我帮你**补充 Wayland 兼容兜底方案**，适配更多闪退场景吗？