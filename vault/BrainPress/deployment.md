# Deployment

MD2HTML is a single PHP entry point over a folder of Markdown files. Deploying it is copying files — no database, no build step, no package manager.

## Requirements

- PHP 8.0+ with FPM (tested on PHP 8.3 / nginx / LEMP)
- PHP extensions: `mbstring`, `curl` (for optional S3/MinIO), standard file functions
- Any Linux host; ~10 MB disk for the code, plus your notes

## Layout

```
docs.dasiwo.com/
├── index.php        # main entry (frontend + API + WebDAV)
├── admin.php        # admin panel (nginx routes /admin, /api/admin/* here)
├── functions.php    # shared layer
├── config.json      # all configuration (secrets live here — block it from the web!)
├── assets/          # local libraries only (marked, DOMPurify, lunr, highlight.js, fonts)
└── vault/           # your notes: every .md is a page, every subfolder is a tree dir
```

## nginx essentials

The four routing rules that matter:

```nginx
# Front controller fallback: extension-less alias URLs (tree entries like
# /Visual-Knowledge/Dashboard) have no real file behind them and must reach PHP.
location /          { try_files $uri $uri/ /index.php?$query_string; }

# API + admin routes
location ^~ /api/admin/ { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }
location ^~ /api/      { try_files $uri /index.php?$query_string; include enable-php-83.conf; }
location ^~ /admin     { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }

# Graph management page (virtual route, handled by index.php)
location ^~ /graph     { try_files $uri /index.php?$query_string; include enable-php-83.conf; }

# WebDAV for Obsidian sync
location ^~ /dav/ { try_files $uri /index.php?$query_string; include enable-php-83.conf; }

# Sensitive files must 404 (config.json, backups, logs, dotfiles…)
location ~* (\.user\.ini|config\.json|\.bak(up)?|\.log|\.sql|README\.md|composer\.json|\.env.*)$ { return 404; }

# Vault static files (images, PDF originals loaded by the reader) — must come before the .pdf rule
location ^~ /vault/ { }

# Markdown paths render server-side (never serve raw .md files)
location ~* \.md$ { rewrite ^(.*)$ /index.php last; }

# PDF page URLs (no /vault/ prefix) open the built-in reader page
location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }
```

> The sensitive-files rule must be declared **before** the `.md` rule so names like `README.md` keep returning 404 while vault articles render normally.

## One-paste setup for BT panel (宝塔)

BT's **pseudo-static** box is included inside the site `server{}` block, so location blocks work there unchanged. Deploying = upload files + paste once — no manual editing of the generated vhost config.

1. Upload these into the web root (`/www/wwwroot/docs.dasiwo.com/`):

   ```
   index.php  admin.php  functions.php  config.json  assets/  vault/
   ```

   (`router.php` is dev-only and can stay out.) Fix ownership:

   ```bash
   chown -R www:www /www/wwwroot/docs.dasiwo.com
   ```

2. Site → Settings → **Pseudo-static** (伪静态) → paste the whole block below → Save. The panel checks syntax and reloads nginx automatically.

   ```nginx
   # Alias URLs (tree entries) must reach PHP — no real files behind them
   location /          { try_files $uri $uri/ /index.php?$query_string; }

   location ^~ /api/admin/ { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }
   location ^~ /api/      { try_files $uri /index.php?$query_string; include enable-php-83.conf; }
   location ^~ /admin     { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }
   location ^~ /graph     { try_files $uri /index.php?$query_string; include enable-php-83.conf; }
   location ^~ /dav/      { try_files $uri /index.php?$query_string; include enable-php-83.conf; }

   location ~* (\.user\.ini|config\.json|\.bak(up)?|\.log|\.sql|README\.md|composer\.json|\.env.*)$ { return 404; }

   location ^~ /vault/ { }

   location ~* \.md$  { rewrite ^(.*)$ /index.php last; }
   location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }
   ```

3. Match the PHP handler to your installed version: `enable-php-83.conf` means PHP 8.3. Check Software Store (软件商店) for the actual version and adjust the number (`74` = 7.4, `00` = no PHP). A wrong number breaks PHP execution.

4. Open `/admin` and set the admin password on first login.

Rules live in their own per-site rewrite file, so they survive panel upgrades and re-saving other site settings. Removing them = clearing the box.

## First run

1. Copy the site folder into your web root.
2. Make `vault/` (and the site folder) writable by the PHP-FPM user: `chown -R www:www /www/wwwroot/docs.dasiwo.com`.
3. Open the site — the frontend works immediately with whatever `.md` files are in `vault/`.
4. Visit `/admin` and set the admin password on first login.

## Verify after deploying

```bash
curl -s -o /dev/null -w "%{http_code}" https://your.site/                  # 200
curl -s -o /dev/null -w "%{http_code}" https://your.site/api/list          # 200
curl -s -o /dev/null -w "%{http_code}" https://your.site/guide/what-is-md2html.md   # 200 (SSR)
curl -s -o /dev/null -w "%{http_code}" https://your.site/config.json       # 404 (must be blocked!)
curl -s -o /dev/null -w "%{http_code}" https://your.site/admin             # 200
curl -s -o /dev/null -w "%{http_code}" https://your.site/graph             # 200
```

## Updates & backups

- The site is stateless: **backup = copy `index.php`, `admin.php`, `functions.php`, `assets/`, `config.json`, `vault/`**.
- Keep backups outside the web root (e.g. `/www/wwwroot/backup/`).
- Restoring = extract the archive, fix ownership (`chown -R www:www`), done.

## Security checklist

- [ ] `config.json` returns 404 over HTTP (secrets live there)
- [ ] PHP-FPM runs as an unprivileged user (`www`), not root
- [ ] `.bak`/`.log`/dotfiles are blocked by the sensitive-files rule
- [ ] Admin password changed from the default after first login
- [ ] Backups live outside the web root
