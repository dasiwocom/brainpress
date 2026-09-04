# BrainPress

**If Obsidian is your second brain, BrainPress is your brain printer.**

Write with Obsidian, print with BrainPress.

## What is this?

**BrainPress** is a **self-hosted Markdown knowledge base**. Point it at a folder of notes and it instantly renders a browsable, searchable, AI-chat-ready website — **no build step, no database, no Node toolchain**.

It also ships a **PDF reader** and a **WebDAV sync endpoint** so it pairs with Obsidian's Remotely Save plugin, plus first-class support for **Excalidraw drawings** and **Obsidian Canvas** whiteboards rendered as live SVG, and a **knowledge graph** view.

> The project was originally built for **AI memory visualization**: an AI agent writes its lessons-learned notes on the server, and you watch its learning through a web interface. Today it works just as well for human users.

## Core features

| Feature | Description |
|---------|-------------|
| Real-time Markdown rendering | Edit a note, refresh (or just click) to see changes — no rebuild |
| Full Obsidian syntax | Callouts, math (KaTeX), `==highlights==`, nested tags (`#a/b/c`), block IDs (`^id`), footnotes, wiki links (`[[...]]`) & embeds (`![[...]]`), block transclusion (`![[note#^id]]`), comments (`%%...%%`), media embeds (images/audio/video) |
| Auto-generated sidebar tree | Driven by your folder structure; supports pinning, forced-expand, and hidden paths |
| Dark / Light mode | One-click toggle, remembered in `localStorage`; optional forced-light default |
| PDF reader (pdf.js) | Outline, page navigation, zoom presets, fullscreen — one shared UI for embeds (`![[book.pdf]]`) and direct access, with `[[book.pdf#page=3]]` deep links |
| Excalidraw drawings | `.excalidraw.md` files render with the official Excalidraw engine (lz-string compressed-JSON → SVG), identical to Obsidian, server-rendered and SPA-navigable |
| Obsidian Canvas | `.canvas` whiteboard files render as an SVG scene (text/file/link/group nodes, bezier arrows, themed) |
| Knowledge graph | `/graph` view with d3-force physics, label thresholds, and click-to-open |
| AI chat | Answers from your vault via an OpenAI-compatible endpoint (DeepSeek by default; custom base URL for gateways/Ollama). Hybrid (vault-first, general fallback) or strict (vault-only) modes, with source links |
| Multiple storage backends | Local / WebDAV / S3 (MinIO) / up to 5 extra local mount paths |
| TOC outline | Per-article table of contents in a right-hand rail; backlinks panel |
| Admin panel | Password-protected, GUI configuration |
| Obsidian sync | Built-in WebDAV endpoint for one-click sync |
| Public API | Listing, search, file CRUD, AI chat, graph data |
| Font preset | Switch the reading typeface between system sans and a self-hosted serif (DejaVu Serif for Latin; Chinese always uses system fonts) |
| Local assets only | marked, DOMPurify, lunr, highlight.js, pdf.js, KaTeX, Excalidraw vendor — no CDN |

## Architecture

```
Obsidian ──WebDAV sync──▶ vault/ (Markdown + PDFs + drawings + canvases)
                             │
Browser ◀──render── index.php (single PHP entry)
        ◀──ask───  POST /api/ask (retrieval + OpenAI-compatible LLM)
```

| Path | Purpose |
|------|---------|
| `index.php` | Frontend: article rendering, search, AI chat, WebDAV, public APIs, SSR |
| `admin.php` | Admin panel (nginx routes `/admin` and `/api/admin/*` here) |
| `functions.php` | Shared: config, auth, S3 client, file scanning, mount merging |
| `assets/` | Local libraries (marked, DOMPurify, highlight.js, pdf.js, KaTeX, Excalidraw vendor, fonts) – no CDN |
| `vault/` | Content source: every `.md` is a page, every `.pdf` opens in the reader, `.excalidraw.md` draws, `.canvas` whiteboards |
| `config.json` | All configuration (secrets included – must be blocked from the web) |

## Quick config

Key fields in `config.json` (every key is also editable in the admin panel):

| Key | Meaning |
|-----|---------|
| `password_hash` | Admin password (bcrypt). Set on first visit to `/admin` (or via `/api/setup`). |
| `webdav_mounts` | Sync accounts for Obsidian Remotely Save: array of `{ user, pass, path }` — each account independent, `path` relative to `vault/`. Managed in Admin → **WebDAV**. |
| `render_webdav` / `render_minio` | Toggle whether storage sources are rendered (vault render is **off by default**; syncing ≠ publishing). |
| `minio_*` | S3-compatible (MinIO) settings. |
| `custom_paths` | Up to 5 extra local paths (absolute server paths), each with an `on` flag. |
| `exclude_paths` | Paths hidden from the frontend (tree, search, direct access). |
| `pinned_dirs` / `pinned_articles` | Pinned directories / articles. |
| `expanded_dirs` | Directories expanded by default in the drawer. |
| `site_title` | Site title. |
| `home_article` | Homepage article (relative to `vault/`). |
| `content_width` | Reading column width in px (clamped 480–1600, default 840). |
| `default_light` | Force light theme as default. |
| `pin_navbar` | Keep the navbar always visible (don't hide on scroll). |
| `font_preset` | `nunito` (default, system sans) or `serif` (self-hosted DejaVu Serif for Latin; Chinese uses system fonts). |
| `graph_path` | Alias URL for the graph view. |
| `graph_show_labels` | Show graph node labels by default. |
| `api_token` | Bearer token for the write API (empty = disabled). |
| `ai_enabled` | Master switch for AI chat. |
| `ai_api_base` | OpenAI-compatible base URL (empty = `https://api.deepseek.com`; use for NewAPI/one-api gateways or `http://127.0.0.1:11434/v1` for Ollama). |
| `ai_api_key` | LLM API key (empty = disabled; also allows keyless local models). |
| `ai_model` | Model name, e.g. `deepseek-chat`. |
| `ai_mode` | `hybrid` (vault first, general fallback) or `strict` (vault only). |

## Quick start

1. **Requirements**: PHP 8.0+ (with `curl`, `mbstring`), Nginx or Apache.
2. Upload the project folder; place notes (`.md`), PDFs, drawings (`.excalidraw.md`) and canvases (`.canvas`) into `vault/`.
3. Configure Nginx (see below) or Apache; block `config.json` from web access.
4. Visit the site, then go to `/admin` to set the admin password.
5. Optional: create WebDAV sync accounts (Obsidian sync) in Admin → **WebDAV**, and add an LLM API key to enable AI chat.

### No Nginx at hand?

The repo ships a local dev-server script (PHP built-in server + router emulation) that runs everything locally (run inside `website/`):

```bash
./start.sh          # start/restart (default http://127.0.0.1:8080)
./start.sh 9090     # custom port
./start.sh stop     # stop
```

| File | Purpose |
|------|---------|
| `start.sh` | Launcher: starts `php -S` with `router.php` attached |
| `router.php` | Dev router: reproduces the production Nginx rules (`/admin` forwarding, `.md`/`.pdf` rewrites, sensitive-file 404s) in PHP; the custom-mount streaming fallback lives inside index.php |

> Both files are **for local development only** — production deployments behind Nginx/Apache don't need them, though keeping them in the repo is harmless.

## Nginx notes

```nginx
# vault static files (images, PDF originals) served directly
location ^~ /vault/ { }

# .md / .excalidraw.md files → dynamic render (rewrite to index.php)
location ~* \.md$ { rewrite ^(.*)$ /index.php last; }

# .pdf files → reader page (not a raw download)
location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }

# .canvas files → whiteboard render
location ~* \.canvas$ { rewrite ^(.*)$ /index.php last; }

# admin
location ^~ /admin { ... }
location ^~ /api/admin/ { ... }

# block sensitive files (config.json, .user.ini, .env, backups, logs)
location ~* (config\.json|\.user\.ini|\.env|\.bak|\.tmp|\.log) { return 404; }
```

> The `.excalidraw.md` and `.canvas` rules must sit alongside the `.md`/`.pdf` rules. Because `.excalidraw.md` ends in `.md`, the `.md$` rule already covers it; the `.canvas` rule is required for whiteboards.

## Security notes

- `config.json` holds every secret (password hash, API key, WebDAV password) — **must be blocked from the web** (Nginx returns 404).
- Admin password is stored as a bcrypt hash, never plaintext.
- The LLM key stays server-side, never exposed to the client.
- Backups should live outside the web root.
- `vault/` needs to be writable only by the PHP user.

## Project structure

```
brainpress/
├── website/             # 🌐 Website edition (nginx/PHP deploy; dev & deploy only touch this dir)
│   ├── assets/          #   CSS / JS / fonts / vendor (local)
│   ├── vault/           #   your notes + PDFs + drawings + canvases
│   ├── admin.php        #   admin panel entry
│   ├── api.php          #   public API handlers (loaded by index.php)
│   ├── dav.php          #   WebDAV endpoint handler (loaded by index.php)
│   ├── config.json      #   all configuration (secrets; scrub before publishing)
│   ├── functions.php    #   core library (with scan cache)
│   ├── index.php        #   frontend entry
│   ├── router.php       #   💻 dev only: nginx rule emulation
│   └── start.sh         #   💻 dev only: one-command launcher
├── docker/              # 🐳 Docker edition (fully self-contained; docs in docker/README.md)
├── landing/             # 🏠 Landing page (pure static)
├── electron/            # 🖥️ Desktop app
└── README.md            # Master index (per-project docs live in their own dirs)
```

> The **Docker edition** is a separate `docker/` project: it bundles its own copy of the site code, a seed vault and full docs, and shares no files with the website edition — updates only flow via `docker/sync-website.sh`. Deployment, backup and Docker Hub publishing steps are all in **`docker/README.md`**.

## Public API

| Endpoint | Description |
|----------|-------------|
| `GET /api/list` | Sidebar tree (respects hidden/pinned rules) |
| `GET /api/file?path=` | Raw Markdown content of a note |
| `GET /api/search?q=` | Full-text search |
| `GET /api/graph` | Graph nodes & edges (for the `/graph` view and external use) |
| `GET /api/article-list` | Lightweight article inventory |
| `GET /api/llms.txt` | LLM-friendly site manifest (llms.txt spec) |
| `POST /api/ask` | AI chat (requires AI enabled) |
| `POST/DELETE /api/note` | Create/overwrite/delete a note (Bearer token required) |
| `GET /api/admin/config`, `POST /api/admin/config`, `POST /api/admin/password` | Admin config (session required) |

Full details in `API-Reference.md`.

## License

**Author**: Ryan
**Nature**: personal project, free to use and modify.

## Next steps

- Richer full-text search ranking
- Remember collapsed-tree state across reloads
- Reading progress tracking
- Export as a Markdown bundle
- Deeper AI conversation experience