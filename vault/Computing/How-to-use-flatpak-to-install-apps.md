## 1. Install flatpak (Arch official repo)
```bash
sudo pacman -S flatpak
```

Add flathub remote (only run one time):
```bash
flatpak remote-add --if-not-exists flathub https://flathub.org/repo/flathub.flatpakrepo
```

## 2. Search applications
Normal search (may truncate text with `...`):
```bash
flatpak search wechat
flatpak search qq
```

Raw full output, no truncation with `| cat`
```bash
flatpak search wechat | cat
flatpak search qq | cat
```
- `|` : pipe, pass output from left command into right command
- `cat` : print raw text, removes terminal table formatting

## 3. Install app
Use the full `application id` you get from search
```bash
flatpak install flathub com.tencent.WeChat
flatpak install flathub com.qq.QQ
```

## 4. Run flatpak app
```bash
flatpak run com.tencent.WeChat
flatpak run com.qq.QQ
```
Fix missing tray icon on Hyprland:
```bash
XDG_CURRENT_DESKTOP=GNOME flatpak run com.tencent.WeChat
```

## 5. Useful commands
List all installed flatpak apps:
```bash
flatpak list
```

Update all flatpak applications:
```bash
flatpak update
```

Uninstall flatpak app:
```bash
flatpak uninstall com.tencent.WeChat
```

## Key concept
- **Application ID**: long dot‑separated string like `com.tencent.WeChat`. This is what flatpak uses, not the short display name.
- flathub: the main software source for flatpak.