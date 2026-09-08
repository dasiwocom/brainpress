## ddcutil Brightness Adjustment Note
> Desktop PC, AOC 24G51F, HDMI‑A‑1, supports DDC‑CI VCP 2.2
> brightnessctl is **not for desktop systems**, already removed. It is only for laptop built‑in screens.

### 1. Setup: i2c permission (no sudo required)
```bash
sudo usermod -aG i2c $USER
```
⚠️ **You must fully log out of Hyprland and log back in. `hyprctl reload` will NOT apply group permission changes.**

Verify your monitor is detected:
```bash
ddcutil detect
```

### 2. Manual commands
```bash
ddcutil getvcp 10              # Read current brightness. VCP 10 = brightness
ddcutil setvcp 10 70           # Set fixed brightness to 70 (range 0‑100)
ddcutil setvcp 10 +5           # Increase brightness by 5
ddcutil setvcp 10 -5           # Decrease brightness by 5
```

### 3. Hyprland hotkey config (add to hyprland.conf)
```ini
# Hardware brightness control for AOC 24G51F
bind = ,XF86MonBrightnessUp, exec, ddcutil setvcp 10 +5
bind = ,XF86MonBrightnessDown, exec, ddcutil setvcp 10 -5
```
Reload hyprland config after editing:
```bash
hyprctl reload
```

## Known small notes
- Minor 1‑2 second delay is normal for DDC‑CI commands.
- If commands stop working: run `ddcutil detect` to refresh or re‑plug HDMI cable.
- This uses official VESA DDC‑CI standard, same backend used by KDE / GNOME desktop environments.