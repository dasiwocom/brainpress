# Configuration Reference

All site configuration lives in `config.json` at the site root (the only file the web server must never serve — see Deployment). Every key is editable from the admin panel at `/admin` → System Settings, except where noted.

```json
{
  "password_hash": "$2y$10$...",
  "webdav_user": "user",
  "webdav_pass": "pass",
  "render_webdav": true,
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
  "site_title": "MD2HTML",
  "home_article": "guide/what-is-md2html.md",
  "default_light": false,
  "front_drawer_expanded": true,
  "api_token": "hex-token-for-write-api",
  "ai_enabled": true,
  "ai_api_key": "sk-...",
  "ai_model": "deepseek-chat",
  "ai_mode": "hybrid"
}
```

## Authentication

| Key | Meaning |
| --- | --- |
| `password_hash` | Admin password, **bcrypt** (never plaintext). Set on first `/admin` login or via the password form. |

## Mounts

| Key | Meaning |
| --- | --- |
| `webdav_user` / `webdav_pass` | WebDAV Basic Auth credentials (for Obsidian Remotely Save). Independent of the admin password. |
| `render_webdav` | Include the local `vault/` folder in the rendered content tree. |
| `render_minio` | Also merge an S3-compatible bucket (MinIO) into the tree. |
| `minio_endpoint` / `minio_access` / `minio_secret` / `minio_bucket` | S3 connection. Files are merged by name, local wins. |
| `custom_paths` | Up to 5 extra local paths to render. Each entry: `path` (absolute server path; a dir renders all `.md` inside, a file renders that file) + `on` (enabled). Paths outside the PHP `open_basedir` are rejected. |

## Content visibility

| Key | Meaning |
| --- | --- |
| `exclude_paths` | Paths hidden from the frontend — excluded from the tree, search, and direct access (`/api/file` and SSR return "文件不存在" for them). Match rules: exact path, directory prefix (hide whole dir), or bare filename (hide everywhere). Admin panel: Hide. |
| `pinned_dirs` | Directories sorted to the **top** of the sidebar tree. List form (multiple allowed). |
| `pinned_articles` | Articles sorted first **within their directory**. |
| `expanded_dirs` | Directories **forced expanded** in the drawer, overriding the default-collapse toggle. |

## Site

| Key | Meaning |
| --- | --- |
| `site_title` | Shown in the navbar and as the page title suffix. |
| `home_article` | Path (relative to `vault/`) rendered as the homepage. Absolute paths and leading slashes are tolerated. Empty = placeholder text. |
| `default_light` | `true` forces light theme as default (ignores saved dark preference). |
| `front_drawer_expanded` | Default expand state of the sidebar tree (`true` = expanded). |
| `api_token` | Bearer token for the write API (`POST/DELETE /api/note`). Empty = write API disabled. Editable in the Site view. |

## AI

| Key | Meaning |
| --- | --- |
| `ai_enabled` | Master switch for the AI chat. `false` hides the ask button in the navbar and `/api/ask` returns 403. |
| `ai_api_key` | DeepSeek API key (platform.deepseek.com). Empty = AI disabled. Stored server-side only, never exposed to visitors. |
| `ai_model` | Model name, e.g. `deepseek-chat`. |
| `ai_mode` | `hybrid` (default): answer from the vault when relevant (with source links), otherwise general knowledge. `strict`: answers only from the vault — "no relevant articles" when nothing matches. |

## Behavior notes

- **Paths in lists** (`exclude_paths`, `pinned_*`, `expanded_dirs`) are relative to `vault/` with `/` separators, e.g. `knowledge/article/note.md`.
- A directory in `exclude_paths` hides every file under it; a bare filename hides all files with that name anywhere.
- `custom_paths` uses absolute server paths (they are mounts outside `vault/`, so they cannot be relative).
- Editing `config.json` by hand while the site runs is fine — the next request re-reads it. Keep a backup before hand-editing.
