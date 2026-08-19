# MD2HTML

A self-hosted, real-time Markdown knowledge base. Point it at a folder of Markdown notes and it becomes a browsable, searchable website — no build step, no database, no Node toolchain. Also renders PDF books in-site (pdf.js) and answers questions about the vault with an AI assistant (DeepSeek API).

**Version**: 1.1.0

**Key idea**: the vault folder is the site. Edit a note, the change is live on next visit.

## Architecture

```
Obsidian ──WebDAV sync──▶ vault/ (Markdown notes + PDFs)
                             │
Browser ◀──render── index.php (single PHP entry)
        ◀──ask───  POST /api/ask (retrieval + DeepSeek)   [AI chat panel]
```

| Path | Purpose |
| --- | --- |
| `index.php` | Main entry: frontend pages, article/PDF rendering, search, AI chat, WebDAV endpoint, public APIs |
| `admin.php` | Admin panel: login, configuration (6 views), content management (nginx routes `/admin` and `/api/admin/*` here) |
| `functions.php` | Shared layer: config loading, auth, S3/MinIO client, file scanning, helpers |
| `assets/` | Local libraries only: marked, DOMPurify, highlight.js, pdf.js, fonts (no CDN) |
| `vault/` | Content source: every `.md` is a page, every `.pdf` opens in the built-in reader, every subfolder is a tree directory |
| `config.json` | All site configuration (secrets included — must be blocked from the web) |

## Requirements

- PHP 8.0+ (FPM recommended) with `curl`, `mbstring`; any LEMP stack
- No database, no Composer, no build step — deploy by copying the folder

## Quick start

1. Drop Markdown notes (and optionally PDFs) into `vault/` (subfolders become categories in the sidebar tree).
2. Serve the folder with nginx (see nginx notes below) and open the site.
3. First visit to `/admin` prompts you to set the admin password.
4. Optionally connect Obsidian Remotely Save to the WebDAV endpoint (`/dav/`, Basic Auth) so sync is one click.
5. Optionally fill in a DeepSeek API key (admin → AI) to enable the ask button.

## Configuration (`config.json`)

| Key | Meaning |
| --- | --- |
| `password_hash` | Admin password (bcrypt). Empty until first setup. |
| `webdav_user` / `webdav_pass` | WebDAV Basic Auth credentials for Obsidian sync |
| `render_webdav` / `render_minio` | Toggle content sources on/off |
| `minio_endpoint` / `minio_access` / `minio_secret` / `minio_bucket` | Optional S3-compatible storage (MinIO) |
| `custom_paths` | Up to 5 extra local paths to render (dir = all md inside; file = that file only) |
| `exclude_paths` | Paths hidden from the frontend (tree, search, direct access) |
| `pinned_dirs` | Directories pinned to the top of the sidebar tree |
| `pinned_articles` | Articles pinned first within their directory |
| `expanded_dirs` | Directories forced expanded (overrides the default-collapse toggle) |
| `site_title` | Site title shown in the navbar |
| `home_article` | Path (relative to `vault/`) rendered as the homepage |
| `default_light` | Force light theme as default |
| `front_drawer_expanded` | Default expand state of the sidebar tree |
| `api_token` | Bearer token for the write API (`POST/DELETE /api/note`). Empty = write API disabled |
| `ai_enabled` | Master switch for the AI chat (false hides the ask button and rejects `/api/ask`) |
| `ai_api_key` | DeepSeek API key (empty = AI disabled) |
| `ai_model` | DeepSeek model, e.g. `deepseek-chat` |
| `ai_mode` | `hybrid` (knowledge base first, general fallback) or `strict` (answers only from the vault) |

All of the above are editable from the admin panel (`/admin` → System Settings).

## Frontend features

- Real-time rendering — no rebuild ever
- Collapsible sidebar tree driven by the `vault/` folder structure
- Full-text search (server-side, offline)
- Light/dark theme, code highlighting, TOC
- **PDF reader**: PDFs in `vault/` open in a built-in reader (pdf.js, local) — page turn, zoom, night-mode inversion, fullscreen
- **AI chat**: ask button in the navbar opens a panel; answers come from the vault with source links (or general knowledge in hybrid mode)
- Homepage = any chosen note; pinned / expanded / hidden content controls
- WebDAV endpoint for direct Obsidian sync

## Public API

| Endpoint | Description |
| --- | --- |
| `GET /api/list` | Sidebar tree (excludes hidden paths, respects pinned/expanded) |
| `GET /api/file?path=` | Raw markdown of one note (hidden paths rejected) |
| `GET /api/search?q=` | Full-text search across the vault |
| `GET /api/article-list` | Lightweight article inventory |
| `GET /api/llms.txt` | LLM-friendly site manifest (llms.txt spec) |
| `POST /api/ask` | AI chat: question → retrieval → DeepSeek answer + sources (needs `ai_enabled` + `ai_api_key`) |
| `POST/DELETE /api/note` | Create/overwrite/delete a note (Bearer `api_token`, vault-only, atomic write) |

See `vault/guide/api-reference.md` for full details.

## Admin panel

`/admin` (password required). Views under System Settings:

- **Mounts** — WebDAV credentials, MinIO config, custom local paths
- **Preferences** — default light mode
- **Site** — site title, home article, API token, password change (current + new)
- **AI** — AI master switch, hybrid/strict mode, DeepSeek API key + model, test connection
- **Hide** — paths hidden from the frontend (dir name = whole dir, file path = one file)
- **Tree** — pinned dirs, pinned articles, expanded dirs, default expand toggle

## nginx notes

```nginx
# vault static files (images, PDF originals) served directly — must come before the .pdf rule
location ^~ /vault/ { }

# article SSR: /xxx.md → rendered page
location ~* \.md$ { rewrite ^(.*)$ /index.php last; }

# PDF page URL (no /vault/ prefix) → built-in reader page
location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }

# admin + admin API
location ^~ /admin { ... }
location ^~ /api/admin/ { ... }

# block secrets: config.json, .user.ini, .env, backups, logs, etc. → 404
```

## Security notes

- `config.json` contains secrets (WebDAV password, MinIO key, password hash, API token, AI key). **Block it from the web** — nginx must return 404 for `/config.json` (see the sensitive-files location rule). Verify with `curl -s -o /dev/null -w "%{http_code}" https://your.site/config.json` (must be 404).
- Admin password is stored as a bcrypt hash, never plaintext.
- The AI key never leaves the server (stored in `config.json`, used server-side only).
- Backups live outside the web root (e.g. `/www/wwwroot/backup/`).
- Keep `vault/` writable only by the PHP user; never expose `.bak`/`.tmp`/log files (nginx sensitive-files rule covers them).

## Backup & restore

```bash
# backup (run from the parent of the site folder)
tar czf /www/wwwroot/backup/docs.dasiwo.com-$(date +%Y%m%d-%H%M%S).tar.gz -C /www/wwwroot docs.dasiwo.com

# restore: extract, then fix ownership for PHP-FPM
chown -R www:www /www/wwwroot/docs.dasiwo.com
```

## License / Author

Author: Ryan. Personal project — use freely, adapt freely.
