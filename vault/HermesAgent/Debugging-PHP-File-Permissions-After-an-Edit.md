# Debugging PHP File Permissions After an Edit

## Symptom

You edit a PHP file on the server with a tool that replaces the file (patch, restore, scp), and suddenly the whole site returns a Fatal error — or worse, a cached copy of the error page with no visible change.

## Root cause

The editor writes the file as the **current user** (root), overwriting the PHP-FPM ownership. The file becomes `root:600`, FPM (running as `www`) cannot read it, and every request dies with "Permission denied".

## The checklist

1. `ls -la file.php` — owner should be `www:www`, mode `644`.
2. Fix it: `chown www:www file.php && chmod 644 file.php`.
3. PHP opcache may still hold the old code for a second or two — wait, then verify.
4. A reverse proxy / CDN may cache the error page — test with a fresh URL (`?v=timestamp`).

## Related

- [[Config-JSON-Leak-Why-Config-Files-Must-Be-Blocked-from-the-Web]]
- [[Server-Side-Rendering-for-Markdown-Sites]]
- [[Why-a-Config-File-Gets-Permission-Denied-After-Restoring-a-Backup]]
