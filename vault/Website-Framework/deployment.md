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

The three routing rules that matter:

```nginx
# API + admin routes
location ^~ /api/admin/ { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }
location ^~ /api/      { try_files $uri $uri/ /index.php?$query_string; include enable-php-83.conf; }
location ^~ /admin     { try_files $uri /admin.php?$query_string; include enable-php-83.conf; }

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
