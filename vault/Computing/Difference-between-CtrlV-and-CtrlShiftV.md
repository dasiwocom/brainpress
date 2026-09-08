> This difference applies **only inside terminal apps (kitty, alacritty)**. Normal GUI apps like browsers do not behave this way.

## Ctrl + V
- Inside terminal: **NOT paste**. It inserts raw control characters.
- When you press `Ctrl+V` in terminal, you will see `^V` show up instead of your copied text.
- Purpose: for inserting special keys like Tab, Escape.
- In browsers / text editors: works as normal paste.

## Ctrl + Shift + V
- Inside terminal: **this is the real paste shortcut**. It pastes content from your clipboard.
- Matching copy shortcut for terminal: `Ctrl + Shift + C`.

### Quick reference table
| Shortcut | Behavior inside terminal | Behavior in browser/GUI apps |
|---|---|---|
| `Ctrl + V` | Insert control character, **does not paste** | Normal paste |
| `Ctrl + Shift + V` | Paste clipboard content | Paste plain‑text (strip formatting) |
| `Ctrl + C` | Stop running program | Copy selected text |
| `Ctrl + Shift + C` | Copy selected text | No effect |

### Real example workflow
1. Copy a command in browser: `sudo pacman -S swww`
2. Switch to terminal
- Press `Ctrl+V` → outputs `^V`, nothing pasted ❌
- Press `Ctrl+Shift+V` → your command gets pasted ✅

## Two Linux clipboards (simple explanation)
1. **Clipboard**: what you copy with `Ctrl+C`. Paste with `Ctrl+Shift+V` in terminal. This is your daily copy‑paste.
2. **Primary selection**: text gets copied automatically when you select it with mouse. Click mouse middle‑button to paste it.

> Note: This is Wayland / Linux terminal convention, different from Windows.