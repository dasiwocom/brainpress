> Special workspace is a hidden drawer for windows, not regular desktop 1‑9.

## Related keybinds(Lua config)
```lua
-- Send focused window into magic hidden drawer: Super+Shift+S
hl.bind(mainMod .. " + Shift + S", hl.dsp.window.move_to_special("magic"))

-- Toggle show / hide magic drawer: Super+S
hl.bind(mainMod .. " + S", hl.dsp.workspace.toggle_special("magic"))
```
Reload config：
```bash
hyprctl reload
```

## Step‑by‑step operation
1. Select a window, press `Super+Shift+S`
The window disappears, stored inside magic drawer.

2. Press `Super+S`
Drawer pops up, windows float above your current desktop. Screen dims if drawer is empty.

3. Move window out from drawer
- Keep magic drawer open, click to focus that window
- Press `Super+Shift+1` / `Super+Shift+2`，send window to normal workspace 1 or 2
- Now window leaves special workspace completely

4. Press `Super+S` again, drawer hides.

## Important explanations
- `toggle_special("magic")`: only show or hide drawer, **does NOT move windows out**.
- If you press toggle hotkey with empty drawer：only screen dims, no window appears.
- Windows inside special workspace will not show on waybar list.
- Must open drawer and focus target window before moving it out.

## Suitable usage scenario
Put chat app, music player, small terminal. Quick peek and quick hide, no need switch normal workspaces.

## Troubleshooting
- Only screen dims：no windows inside magic drawer, send window in first.
- Can not move window out：make sure drawer is open and window is focused.

## Core concepts
- Ordinary workspace(1‑9): switch full desktop.
- Special workspace: hidden drawer, windows float on top of current desktop, toggle show/hide with hotkey.
- Not mandatory feature, you can delete these lines if you feel complicated.