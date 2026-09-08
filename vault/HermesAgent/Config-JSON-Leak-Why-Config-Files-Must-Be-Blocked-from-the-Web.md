# A Config JSON Leak: Why Your Site's Config File Must Be Blocked from the Web

## Symptom

A single-file PHP site stores its configuration in `config.json` next to the entry script. One day, a routine security check reveals:

```
$ curl -s https://example.com/config.json
{
    "webdav_pass": "test",
    "minio_secret": "bnRPEmtSC8Z4E8NK",
    "password_hash": "$2y$10$...",
    ...
}
```

HTTP 200, full file. All secrets — object-storage credentials, WebDAV passwords, admin password hash — are publicly downloadable. The site itself works fine; nobody noticed because the app never links to the file.

## Principle

Nginx (and most web servers) **serve any existing static file** that a request maps to, unless a rule explicitly blocks it:

- The PHP entry script is routed through `location` blocks, but `config.json` is not PHP — it matches the **default static handling** (`try_files $uri $uri/ ...`) and is returned verbatim.
- Sites typically maintain a "sensitive files" blacklist regex (`\.env`, `.git`, `.bak`, README...), but the list is hand-maintained and **easy to miss application-specific files** like `config.json`, `settings.json`, `db.json`.
- Single-file apps are the worst case: config with plaintext secrets sits **inside the web root**, right next to the code.

Two aggravating facts:

1. The password is a bcrypt hash (`password_hash()`), so it is not directly reversible — but a downloadable hash enables **offline brute force**. Plaintext fields (S3 secret, WebDAV password) leak immediately.
2. Permissions (`600`, owner `www`) do not help: the web server itself runs as `www`, so it can read the file and happily serves it.

## Detection

A five-second check every site should pass:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://example.com/config.json
```

If it returns 200, you have a leak. Also probe variants: `?v=1`, `../config.json`, and other app files (`composer.json` is usually blocked, but what about `config.php`, `config.yaml`, `*.tar.gz` backups?).

## Fix

Add the file to the sensitive-file blacklist in nginx:

```nginx
location ~* (\.user\.ini|config\.json|\.env.*|\.bak(up)?|...) $ {
    return 404;
}
```

Then reload and verify, including bypass variants:

```bash
nginx -t && nginx -s reload
curl -s -o /dev/null -w "%{http_code}\n" https://example.com/config.json        # 404
curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/config.json?v=1"  # 404
```

## Defense in depth

- **Move config outside the web root** (e.g. `/etc/myapp/config.json`, or one directory above the document root) and reference it by absolute path. This is the real fix — nginx blacklists are whack-a-mole.
- **Hash everything sensitive** — passwords via `password_hash()`, and never store plaintext API secrets if avoidable (at minimum rotate them if a leak window existed).
- **Keep `600` permissions** and a dedicated service user (does not stop the web server, but stops other local users).
- **Rotate secrets after a leak window** — if the file was downloadable for any period, assume it was downloaded. Change S3 keys, WebDAV passwords, admin password.
- **Add the curl probe to your deploy checklist** — leaks are silent; only a check finds them.

## Summary

- Nginx serves existing static files by default; your config JSON is static and will be served unless blocked.
- Sensitive-file blacklists are hand-maintained and always miss something — audit them for app-specific files (`config.json`, `*.tar.gz`, backups).
- Detection is one curl; fix is one nginx rule; the robust fix is moving config out of the web root entirely.
