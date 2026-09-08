# Why a Config File Suddenly Gets Permission Denied After Restoring a Backup

## Symptom

After testing a new version of a PHP panel on a temporary server and then restoring the production config file, the production site breaks with:

```
Warning: file_get_contents(config.json): Failed to open stream: Permission denied
```

The file still exists. Its size looks fine. But PHP-FPM cannot read it anymore.

## Root Cause

The restore step was done as `root`:

```bash
cp config.json /tmp/config.json.bak   # backup (owner stays www)
# ... tests run, config.json gets modified by the test ...
mv /tmp/config.json.bak config.json   # restore as root!
```

`mv` does not preserve the destination file's owner. When run as `root`, the restored file becomes owned by `root:root`.

Meanwhile the PHP-FPM worker runs as `www` (or `www-data`). The config file typically has mode `600` (private), so `www` has zero read access. Result: every request that loads the config dies with `Permission denied` — even though the path and contents are correct.

Note: `cp` would have been safe here (the backup kept its `www` owner), and `chown www:www` after restore fixes it. The trap is that *restore-as-root* silently changes ownership — you only notice when the app can no longer read its own config.

## Fix

```bash
chown www:www config.json
ls -la config.json   # confirm: www www
```

Then verify the site again (home page, admin page, list API) before telling anyone it is fixed.

## Lesson

1. **Backup and restore must preserve ownership.** Prefer `cp` for round-trips, or always run `chown` after a `mv` restore.
2. **After any file restore, check `ls -la`** — owner/group/permissions are part of the file's state, not just its content.
3. **When PHP-FPM reports `Permission denied` on a file that exists**, first suspect ownership: the worker user (`www`) must be the owner (or the file must be group-readable).

## Related

- Same class of bug: `write_file` created markdown notes as `root` inside a `www`-owned web root → the site API returns empty content. Fix is identical: `chown www:www` after writing.

## Related: mounting root-owned private files into a PHP site

Same symptom, different cause: rendering a directory outside the web root (e.g. `/root/.hermes/memories`) via a "custom render path". The API returned broken JSON (`null is not an object` in the frontend) because:

- The directory chain `/root` → `.hermes` → `memories` was enterable by `www` (mode `705`), so the file **list** worked.
- But the markdown files themselves were `600 root` → `file_get_contents()` failed with a PHP `Warning` that got prepended to the JSON body → frontend `r.json()` threw → null errors.

Fix: `chmod 644 <file>.md` (keep `root` ownership, let `www` read). If files are rewritten later with private perms, the error returns — keep the permission check in mind after any rewrite.
