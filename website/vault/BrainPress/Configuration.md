# Configuration Reference

All site configuration lives in `config.json` at the site root (the only file the web server must never serve — see Deployment). Every key is editable from the admin panel at `/admin` → the relevant view, except where noted.

```json
{
  "password_hash": "$2y$10$...",
  "webdav_mounts": [
    { "user": "alice", "pass": "secret", "path": "" }
  ],
  "webdav_user": "alice",
  "webdav_pass": "secret",
  "render_webdav": false,
  "render_minio": false,
  "minio_endpoint": "https://s3.example.com",
  "minio_access": "access-key",
  "minio_secret": "secret-key",
  "minio_bucket": "vault",
  "custom_paths": [
    { "path": "/root/.hermes/memories", "on": true }
  ],
  "exclude_paths": ["draft/secret-note.md"],
  "pinned_dirs": ["knowledge"],
  "pinned_articles": ["knowledge/article/Featured-Note.md"],
  "expanded_dirs": ["draft"],
  "site_title": "BrainPress",
  "home_article": "guide/what-is-brainpress.md",
  "content_width": 840,
  "default_light": false,
  "front_drawer_expanded": true,
  "pin_navbar": true,
  "font_preset": "nunito",
  "graph_path": "Visual-Knowledge/Graphview",
  "graph_show_labels": true,
  "api_token": "hex-token-for-write-api",
  "ai_enabled": true,
  "ai_api_base": "https://api.deepseek.com",
  "ai_api_key": "sk-...",
  "ai_model": "deepseek-chat",
  "ai_mode": "hybrid"
}
```

## Authentication

| Key | Meaning |
| --- | --- |
| `password_hash` | Admin password, **bcrypt** (never plaintext). Set on first `/admin` login or via `/api/setup` / the password form. |

## WebDAV sync

WebDAV is the sync side: it stores and edits notes in `vault/` for Obsidian Remotely Save. **Syncing is not publishing** — what reaches the frontend is controlled separately by `render_webdav` under Mounts.

| Key | Meaning |
| --- | --- |
| `webdav_mounts` | Sync accounts: array of `{ user, pass, path }`. Each account has its own Basic Auth credentials and mounts a sync folder **relative to** `vault/` (empty `path` = vault root). Only `vault/` is ever exposed by the DAV endpoint. Managed in Admin → **WebDAV**. |
| `webdav_user` / `webdav_pass` | Legacy single-account fields, kept in sync with the first `webdav_mounts` entry for older readers. |

## Mounts (what is rendered)

| Key | Meaning |
| --- | --- |
| `render_webdav` | Include the local `vault/` folder in the rendered content tree, search and graph. **Off by default** — synced notes are stored but not published until you turn this on (Admin → Mounts). |
| `render_minio` | Also merge an S3-compatible bucket (MinIO) into the tree. |
| `minio_endpoint` / `minio_access` / `minio_secret` / `minio_bucket` | S3 connection. Files are merged by name, local wins. |
| `custom_paths` | Up to 5 extra local paths to render. Each entry: `path` (absolute server path; a dir renders all `.md` inside, a file renders that file) + `on` (enabled). Paths outside the PHP `open_basedir` are rejected. Mounted content is merged at the top level of the tree (same level as `vault/`), not nested under a synthetic directory. |

## Content visibility

| Key | Meaning |
| --- | --- |
| `exclude_paths` | Paths hidden from the frontend — excluded from the tree, search, and direct access (`/api/file` and SSR return "file not found" for them). Match rules: exact path, directory prefix (hide whole dir), or bare filename (hide everywhere). Admin panel: Tree → Hidden paths. |
| `pinned_dirs` | Directories sorted to the **top** of the sidebar tree. |
| `pinned_articles` | Articles sorted first **within their directory**. |
| `expanded_dirs` | Directories **forced expanded** in the drawer, overriding the default-collapse toggle. |

## Site

| Key | Meaning |
| --- | --- |
| `site_title` | Shown in the navbar and as the page title suffix. |
| `home_article` | Path (relative to `vault/`) rendered as the homepage. Absolute paths and leading slashes are tolerated. Empty = placeholder text. |
| `content_width` | Reading column width in px (clamped 480–1600, default 840). |
| `default_light` | `true` forces light theme as default (ignores saved dark preference). |
| `front_drawer_expanded` | Default expand state of the sidebar tree (`true` = expanded). |
| `api_token` | Bearer token for the write API (`POST/DELETE /api/note`). Empty = write API disabled. Editable in the Site view. |
| `graph_path` | Alias URL for the graph view; a matching menu entry is injected into the tree. Admin panel: Graph. |

## Appearance

| Key | Meaning |
| --- | --- |
| `pin_navbar` | `true` keeps the navbar always visible (does not hide on scroll). Admin panel: Preferences. |
| `font_preset` | Reading typeface: `nunito` (default, system sans) or `serif` (self-hosted DejaVu Serif for Latin; Chinese uses the system Song/SimSun fonts). `serif` adds `/assets/fonts/dejavu-serif.woff2` (+ bold). Admin panel: Preferences. |

## Graph

| Key | Meaning |
| --- | --- |
| `graph_show_labels` | Show graph node labels by default (otherwise only on hover / when zoomed in). Admin panel: Graph. |
| `graph_path` | Alias URL for the graph view (see Site above). Admin panel: Graph. |

## AI

| Key | Meaning |
| --- | --- |
| `ai_enabled` | Master switch for the AI chat. `false` hides the ask button in the navbar and `/api/ask` returns 403. |
| `ai_api_base` | OpenAI-compatible base URL. Empty = `https://api.deepseek.com`. Use for gateways (NewAPI/one-api) or local models such as `http://127.0.0.1:11434/v1` (Ollama/LM Studio). The request goes to `{base}/chat/completions`, so the URL must include `/v1` for local servers. Because the call is made server-side by PHP, the address must be reachable from the server. |
| `ai_api_key` | LLM API key. Empty = disabled, and also allows keyless local models (no `Authorization` header is sent). Stored server-side only, never exposed to visitors. |
| `ai_model` | Model name, e.g. `deepseek-chat`. |
| `ai_mode` | `hybrid` (default): answer from the vault when relevant (with source links), otherwise general knowledge. `strict`: answers only from the vault — "no relevant articles" when nothing matches. |

## Special file types

Beyond `.md`, the vault can contain first-class non-Markdown notes:

| Type | Behavior |
| --- | --- |
| `.pdf` | Opens in the built-in pdf.js reader (embeds and direct access share one UI). Embed with `![[book.pdf]]`; deep-link with `[[book.pdf#page=3]]`. |
| `.excalidraw.md` | Excalidraw drawing rendered with the official engine (lz-string compressed-JSON → SVG). Detected by the `.excalidraw.md` extension **or** by content (`excalidraw-plugin:` marker + ```` ```compressed-json ```` block). |
| `.canvas` | Obsidian Canvas whiteboard rendered as a live SVG scene (text/file/link/group nodes, bezier arrows). |

All three are served through `index.php` (the `.md`/`.pdf`/`.canvas` rewrite rules) and are excluded from static serving under `/vault/`.

## Behavior notes

- **Dual notation for list paths** (`exclude_paths`, `pinned_dirs`, `pinned_articles`, `expanded_dirs`): a **relative path** (or bare name) matches the main `vault/` only, e.g. `knowledge/article/note.md`; an **absolute path** (`/…`) matches entries under `custom_paths` mounts instead — exact entry, or any path inside it (a mount directory is matched as a whole subtree). This removes ambiguity when a mount has the same name as a main-vault folder.
- A directory in `exclude_paths` hides every file under it; a bare filename hides all files with that name anywhere.
- `custom_paths` uses absolute server paths (they are mounts outside `vault/`, so they cannot be relative).
- Editing `config.json` by hand while the site runs is fine — the next request re-reads it. Keep a backup before hand-editing.
- The admin panel is organized into views: **WebDAV** (sync accounts), **Mounts** (render sources), **Preferences**, **Site**, **AI**, **Graph**, **Tree**.
