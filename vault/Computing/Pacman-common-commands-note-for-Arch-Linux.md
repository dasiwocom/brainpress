> pacman is Arch Linux package manager.

## Install package
```bash
sudo pacman -S package_name
```
- `-S` = sync, install package from remote repository

## Uninstall package
```bash
sudo pacman -Rns package_name
```
- `-R` = remove
- `-n` = delete config files
- `-s` = remove unused dependencies

## Search packages
```bash
# Search LOCAL installed packages (lowercase s)
pacman -Qs keyword

# Search REMOTE repository packages (uppercase S)
pacman -Ss keyword
```
- `-Qs`: look for packages already on your disk
- `-Ss`: look for packages available to download online

## Full system update
```bash
sudo pacman -Syu
```
- `-y`: refresh package database
- `-u`: upgrade all installed packages

## Check if one package is installed
```bash
pacman -Q package_name
```
Show installed version, error if not installed.

## List all installed packages
```bash
pacman -Q
```

## Key argument summary
| Flag | Meaning |
|---|---|
| `-S` | sync / install from internet repo |
| `-Q` | query local installed packages |
| `-s` | search filter |
| `-R` | remove / uninstall |
| `-n` | purge config files on remove |
| `-u` | upgrade system |
| `-y` | refresh database |

## Important tips
1. `‑Qs` vs `‑Ss` easy mix‑up:
- `pacman -Qs` → check what you already have
- `pacman -Ss` → look for software you can download
2. Always use `‑Rns` for clean uninstall, leave no leftover dependencies.
3. Run full update with `sudo pacman -Syu` frequently.