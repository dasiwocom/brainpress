# Getting Started

BrainPress turns a folder of Markdown notes into a live website — no build step, no database. PDFs, Excalidraw drawings, and Obsidian Canvas whiteboards in the same folder open in built-in viewers, and an optional AI assistant answers questions about your vault.

## 1. Deploy

1. Copy the project folder to your server (any LEMP stack, PHP 8.0+).
2. Configure nginx: route `/admin` and `/api/admin/*` to `admin.php`, rewrite `.md`, `.pdf` and `.canvas` paths to `index.php`, serve `/vault/` statically, and block `config.json` (see `Deployment.md` for the exact rules).
3. Open the site — it works with an empty vault (placeholder page).

## 2. Add content

- Drop `.md` notes into `vault/`. Subfolders become categories in the sidebar tree.
- Drop `.pdf` books anywhere in `vault/` — they appear in the tree and open in the built-in reader (outline, page navigation, zoom presets, fullscreen). PDFs can also be embedded inside notes with Obsidian's `![[book.pdf]]` syntax — embeds and standalone pages share the same reader UI, and `[[book.pdf#page=3]]` deep-links to a page.
- Draw in **Excalidraw** and save the file as `something.excalidraw.md` — it renders with the official Excalidraw engine (same as Obsidian). A file is recognized as Excalidraw either by the `.excalidraw.md` extension or by its content (`excalidraw-plugin:` marker + a ```` ```compressed-json ```` block), so a renamed or pasted file still renders correctly.
- Build **Obsidian Canvas** whiteboards (`.canvas` files) — they render as a live SVG scene (text/file/link/group nodes and bezier arrows), both via direct URL and tree click.
- Edit a note with any editor (or Obsidian synced over WebDAV) — changes are live on next visit. No rebuild.

## 3. Configure

Visit `/admin` and set the admin password on first login. The admin panel has these views:

- **WebDAV** — manage **sync storage**: create one or more sync accounts (each with its own username/password and a sync folder under `vault/`), for Obsidian Remotely Save.
- **Mounts** — choose what is **rendered** on the frontend: the synced vault (a single switch, off by default), an optional MinIO/S3 bucket, and up to 5 custom local mount paths. Rendering only reads files.
- **Preferences** — default light mode, pin navbar (always visible), and the **font preset** (system sans or self-hosted DejaVu Serif for Latin; Chinese always uses system fonts).
- **Site** — site title, homepage article, content width (reading column px), and the API token for the write API.
- **AI** — OpenAI-compatible chat settings (API base URL, key, model, enabled, hybrid/strict mode) and the read-only Agent API info.
- **Graph** — graph view settings (show file names, path alias).
- **Tree** — hide paths, pin directories/articles, force-expand directories.

## 4. Enable the AI assistant (optional)

1. Get an API key from an OpenAI-compatible provider (DeepSeek at platform.deepseek.com is the default; pay-per-use, cheap).
2. Admin → **AI** → paste the key (API key), keep `deepseek-chat` as model, or set **API base URL** to a gateway (NewAPI/one-api) or a local model such as `http://127.0.0.1:11434/v1` (Ollama/LM Studio; leave the key empty for keyless local inference).
3. Make sure **AI enabled** is on. The ask button appears in the navbar.

Ask anything about your vault: answers come from your notes with source links. In hybrid mode (default) questions outside the vault are answered from general knowledge; flip **Hybrid mode** off for strict vault-only answers.

## 5. Daily use

- Browse the tree, read notes, open PDFs, view drawings and whiteboards, or open the knowledge graph at `/graph`.
- Use the search box for full-text search.
- The AI panel is one click away — it reads the same vault the visitor sees.
- Notes sync from Obsidian via WebDAV (`/dav/`, Basic Auth). Once synced, published notes still need the **Render synced vault** switch on in Admin → **Mounts** — syncing stores your notes, rendering publishes them. Editing files on the server works the same way.

That's it. The vault is the product — the site is just how it looks.
