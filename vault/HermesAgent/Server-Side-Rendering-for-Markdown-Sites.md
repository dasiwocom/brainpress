# Server-Side Rendering for Markdown Sites

## The idea

A Markdown knowledge base can render two ways: client-side (fetch the file, render in the browser) or server-side (render in PHP, ship finished HTML). Server-side rendering means:

- The first paint already contains the article — no loading flash
- Search engines and link previews see real content
- The page works even if JavaScript fails to load

## Routing notes

Route by URL shape in the single PHP entry:

```nginx
location ~* \.md$  { rewrite ^(.*)$ /index.php last; }
location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }
location = /graph { rewrite ^(.*)$ /index.php last; }
```

Serve static assets with **absolute paths** (`/assets/...`) — relative paths break on nested article URLs like `/guide/note.md`.

## Caching traps

- Add `Cache-Control: no-cache` on SSR pages or the CDN serves stale articles
- Verify changes with a fresh query string (`?v=123`) — CDNs cache by URL

## Related

- [[What-Is-MD2HTML]]
- [[getting-started]]
- [[Debugging-PHP-File-Permissions-After-an-Edit]]
- [[Config-JSON-Leak-Why-Config-Files-Must-Be-Blocked-from-the-Web]]
