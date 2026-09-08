> Hyprland itself has no dark‑mode switch. You need set GTK, Qt and Flatpak separately.

## Install required tools
```bash
sudo pacman -S nwg-look qt6ct papirus-icon-theme
```
- `nwg‑look`: GUI config for GTK applications
- `qt6ct`: GUI config for Qt6 applications
- `papirus‑icon‑theme`: icon pack, includes dark variant

## Set GTK dark theme
1. Open `nwg-look` via rofi(drun)
2. Widget theme: `Adwaita‑dark`
3. Icon theme: `Papirus‑Dark`
4. Color scheme: `prefer‑dark`
5. Save and close

## Set Qt6 dark theme
1. Open `qt6ct` via rofi(drun)
2. Theme: `Breeze‑Dark`
3. Icon theme: `Papirus‑Dark`

Add environment variables to `~/.config/hypr/hyprland.conf`
```ini
env = QT_QPA_PLATFORMTHEME,qt6ct
env = GTK_THEME,Adwaita:dark
```

## Reload Hyprland config
```bash
hyprctl reload
```
> Already opened windows will not change. Close and reopen apps to apply theme.

## Make Flatpak apps respect dark theme (run once)
```bash
flatpak override --filesystem=~/.themes:ro --filesystem=~/.icons:ro --user
```
Restart your Flatpak apps (QQ / WeChat).

## Key explanations
- **GTK**: UI toolkit for most common Linux apps, configured by nwg‑look
- **Qt**: UI toolkit for KDE‑style programs, configured by qt6ct
- **Hyprland**: only window manager. It controls window borders/gaps, cannot change inner UI colors of applications.
- Flatpak is sandboxed; by default it cannot read your local theme files. The override command gives it read‑only access.

## Troubleshooting
- Apps still light: fully close app and re‑launch
- Qt apps keep light: verify the two env lines exist in hyprland.conf
- Flatpak ignore theme: re‑run the flatpak override command and restart flatpak programs