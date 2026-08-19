# API Reference

MD2HTML exposes a small, plain-HTTP API. Everything is JSON (UTF-8), and the two read endpoints are **public** — no authentication required, so any script or tool can consume the knowledge base. Admin endpoints require a session cookie obtained from `/api/login`.

**Base URL**: `https://docs.dasiwo.com`

## Response envelope

Every JSON endpoint returns a consistent envelope:

```json
{ "ok": true, ...data }
```

Failures return `ok: false` with a human-readable message and an appropriate HTTP status:

```json
{ "ok": false, "error": "File not found" }
```

> **Always check `ok`** — HTTP 200 only means the request reached the server; business success/failure lives in the JSON body.

## Endpoints

### `GET /api/list` — file tree

Returns the full content tree of the vault (directories first, pinned/expanded rules applied, hidden paths excluded).

```bash
curl https://docs.dasiwo.com/api/list
```

```json
{
  "ok": true,
  "tree": [
    { "name": "guide", "path": "guide", "type": "dir",
      "children": [
        { "name": "api-reference.md", "path": "guide/api-reference.md", "type": "file" }
      ] }
  ]
}
```

- `type`: `"dir"` or `"file"`
- `path`: relative to the vault root, URL-encoded (`/` kept, spaces as `%20`)
- Recursive via `children`

### `GET /api/file?path=` — read a note

Reads one note's **raw Markdown content** (server-side render pipeline not applied).

```bash
curl "https://docs.dasiwo.com/api/file?path=guide/api-reference.md"
```

```json
{
  "ok": true,
  "path": "guide/api-reference.md",
  "content": "# API Reference\n\n..."
}
```

Errors:

| Status | Body | Meaning |
| --- | --- | --- |
| 400 | `{"ok":false,"error":"文件不存在"}` | File does not exist or is hidden (excluded paths behave exactly like missing files) |
| 400 | `{"ok":false,"error":"文件过大"}` | File exceeds the size limit |

Path traversal attempts are rejected with `400`.

### `POST /api/login` — session login

```bash
curl -c cookies.txt -H "Content-Type: application/json" \
  -d '{"password":"your-password"}' \
  https://docs.dasiwo.com/api/login
```

```json
{ "ok": true }
```

Sets a session cookie (capture it with `-c cookies.txt`). Wrong password → `400` with `ok:false`.

### `GET /api/logout` — end session

```bash
curl -b cookies.txt https://docs.dasiwo.com/api/logout
```

### `GET /api/admin/config` — read configuration (auth required)

```bash
curl -b cookies.txt https://docs.dasiwo.com/api/admin/config
```

Returns the full configuration: mounts, render toggles, custom paths, exclude/pinned/expanded lists, site title, home article, theme defaults.

### `POST /api/admin/config` — update configuration (auth required)

```bash
curl -b cookies.txt -H "Content-Type: application/json" \
  -d '{"site_title":"My Docs","home_article":"guide/what-is-md2html.md"}' \
  https://docs.dasiwo.com/api/admin/config
```

Partial updates are allowed — only the keys you send are written.

### `POST /api/admin/password` — change admin password (auth required)

```bash
curl -b cookies.txt -H "Content-Type: application/json" \
  -d '{"current":"old-password","new":"new-password"}' \
  https://docs.dasiwo.com/api/admin/password
```

The current password must match; otherwise `400` with `ok:false`.

### `GET /api/search?q=` — full-text search (server-side)

Scans every note's title and content for the query (case-insensitive), returns path + name + a snippet around the first hit. Hidden paths are excluded.

```bash
curl "https://docs.dasiwo.com/api/search?q=Hyprland"
```

```json
{
  "ok": true,
  "query": "Hyprland",
  "count": 4,
  "results": [
    { "path": "knowledge/article/How-to-set-dark-theme-on-Hyprland-Arch.md",
      "name": "How-to-set-dark-theme-on-Hyprland-Arch",
      "snippet": "…the adapter boots into USB composite mass storage mode…" }
  ]
}
```

Empty query → `400` with `ok:false`.

### `GET /api/article-list` — plain article list

Lightweight list of every note (path + name only). No recursion, no tree — useful for feeds, AI ingestion, and scripts that just need the inventory.

```bash
curl https://docs.dasiwo.com/api/article-list
```

```json
{
  "ok": true,
  "count": 28,
  "articles": [ { "path": "guide/api-reference.md", "name": "api-reference" } ]
}
```

### `GET /api/llms.txt` — LLM-friendly index

Plain-text manifest following the [llms.txt](https://llmstxt.org) convention: site title, one-line description, and a link to every note's server-rendered page. Aimed at AI crawlers and tools that want the knowledge base in one fetch.

```bash
curl https://docs.dasiwo.com/api/llms.txt
```

```text
# MD2HTML Knowledge Base

> Markdown notes published at https://docs.dasiwo.com — plain Markdown, server-rendered pages.

- [What is MD2HTML](https://docs.dasiwo.com/guilde/what-is-md2html.md)
- [API Reference](https://docs.dasiwo.com/guilde/api-reference.md)
...
```

### `POST /api/note` — create or overwrite a note (write API)

Remote write access for agents and scripts. Requires a Bearer token (set in admin panel → Site → API token; empty = endpoint disabled).

```bash
TOKEN="your-api-token"
curl -X POST https://docs.dasiwo.com/api/note \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"path":"guide/notes/2026-08-13.md","content":"# Today\n\nWritten by an agent."}'
```

```json
{ "ok": true, "path": "guide/notes/2026-08-13.md", "bytes": 41, "created": true }
```

- Writes are **atomic** (temp file + rename) — a crash never leaves a half-written note.
- Creating a note in a directory that does not exist yet → `400` (create the directory via WebDAV `MKCOL` first, or keep to existing folders).
- Overwriting an existing note with the same path is allowed.

### `DELETE /api/note` — delete a note (write API)

```bash
curl -X DELETE https://docs.dasiwo.com/api/note \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"path":"guide/notes/2026-08-13.md"}'
```

```json
{ "ok": true, "path": "guide/notes/2026-08-13.md", "deleted": true }
```

**Write API rules** (all enforced server-side):

| Rule | Behavior |
| --- | --- |
| Missing/wrong token | `401` |
| Token not configured | `403` (endpoint disabled) |
| Path not `.md` | `400` |
| Path escapes the vault (`..`, absolute paths) | `400` |
| Deleting a missing note | `400` "文件不存在" |

### `POST /api/ask` — AI chat (retrieval + answer)

Answers a question using the knowledge base (retrieval over `vault/` + DeepSeek). Requires the AI master switch on and an API key configured (admin panel → AI). Read-only — never modifies content.

```bash
curl -X POST https://docs.dasiwo.com/api/ask \
  -H "Content-Type: application/json" \
  -d '{"question":"How does atomic write work?"}'
```

```json
{
  "ok": true,
  "answer": "The write API uses a temp file plus rename...",
  "sources": [
    { "path": "guide/api-reference.md", "name": "api-reference" }
  ]
}
```

**Behavior**:
- `hybrid` mode (default): answers from the vault when relevant, with `sources`; otherwise answers from general knowledge with empty `sources`.
- `strict` mode: answers only from the vault — "No relevant articles found..." when nothing matches.
- Chinese questions are tokenized (English words + 2-char sliding window) so retrieval works without spaces.

| Condition | Response |
| --- | --- |
| AI master switch off | `403` "AI disabled" |
| No API key configured | `503` "AI not configured" |
| Empty question | `400` |
| DeepSeek error | `502` with error message |

## WebDAV (`/dav/`) — read/write sync

Full WebDAV endpoint for Obsidian Remotely Save (or any WebDAV client). Basic Auth with the configured WebDAV credentials (different from the admin password). The vault root is served at `/dav/`.

```bash
curl -u "user:pass" -X PROPFIND https://docs.dasiwo.com/dav/
```

## Server-rendered pages (the "HTML API")

Every note is also available as a fully server-rendered page:

```
https://docs.dasiwo.com/guide/api-reference.md
```

The server reads the note, inlines the Markdown, and returns the complete page — content is visible before any JavaScript runs. This is also what makes the site crawlable by search engines.

## Notes

- All JSON responses use `Content-Type: application/json; charset=utf-8` and are `Cache-Control: no-store` (always fresh).
- Public read endpoints have no rate limits — they are plain PHP reads over local files.
- Excluded (hidden) paths are consistently absent: not in `/api/list`, and `/api/file` returns "文件不存在" for them.
