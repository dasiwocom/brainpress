# Getting Started

MD2HTML turns a folder of Markdown notes into a live website — no build step, no database. PDFs in the same folder open in a built-in reader, and an optional AI assistant answers questions about your vault.

## 1. Deploy

1. Copy the project folder to your server (any LEMP stack, PHP 8.0+).
2. Configure nginx: route `/admin` and `/api/admin/*` to `admin.php`, rewrite `.md` and `.pdf` paths to `index.php`, serve `/vault/` statically, and block `config.json` (see `guide/deployment.md` for the exact rules).
3. Open the site — it works with an empty vault (placeholder page).

## 2. Add content

- Drop `.md` notes into `vault/`. Subfolders become categories in the sidebar tree.
- Drop `.pdf` books anywhere in `vault/` — they appear in the tree and open in the built-in reader (page turn, zoom, night mode, fullscreen).
- Edit a note with any editor (or Obsidian synced over WebDAV) — changes are live on next visit. No rebuild.

## 3. Configure

Visit `/admin` and set the admin password on first login. Under System Settings:

- **Mounts** — WebDAV credentials (for Obsidian sync), optional MinIO/S3 bucket, custom local paths.
- **Site** — site title, homepage article, API token (for the write API).
- **Hide / Tree** — hide paths, pin directories/articles, force-expand directories.
- **Preferences** — default light mode.

## 4. Enable the AI assistant (optional)

1. Get a DeepSeek API key at platform.deepseek.com (pay-per-use, cheap).
2. Admin → **AI** → paste the key (AI API key), keep `deepseek-chat` as model.
3. Make sure **AI enabled** is on. The ask button appears in the navbar.

Ask anything about your vault: answers come from your notes with source links. In hybrid mode (default) questions outside the vault are answered from general knowledge; flip **Hybrid mode** off for strict vault-only answers.

## 5. Daily use

- Browse the tree, read notes, open PDFs.
- Search box for full-text search.
- The AI panel is one click away — it reads the same vault the visitor sees.
- Notes sync from Obsidian via WebDAV (`/dav/`, Basic Auth) or by editing files directly.

That's it. The vault is the product — the site is just how it looks.
