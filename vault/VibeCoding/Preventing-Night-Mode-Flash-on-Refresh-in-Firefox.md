# Preventing Night-Mode Flash on Refresh in Firefox

## Symptom

A Markdown knowledge base site with a manual light/dark theme toggle (user choice stored in `localStorage`) flashes the **light theme for one frame** when refreshing in Firefox while in dark mode: the page briefly renders white, then switches to dark. The title separator line under the article title flashes light too, then settles.

Chrome does not show this; only Firefox does.

## Why Firefox flashes

Both browsers apply the theme by adding a `dark` class to `<html>` from an inline script in `<head>`:

```js
if (localStorage.getItem('vp-theme') === 'dark') {
    document.documentElement.classList.add('dark');
}
```

- **Chrome** blocks the first paint until the head scripts run, so the first frame already has the class — no flash.
- **Firefox** paints a speculative first frame with the *default* styles before executing the script, then the class lands and the page re-renders dark. The first frame is light — the flash.

In other words: **Firefox paints first and thinks later**. An inline script in `<head>` is not early enough, because the first frame is not waiting for it.

## The fix: server-side theme announcement via cookie

The robust fix removes JS from the critical path entirely — the server announces the theme in the first bytes of HTML:

1. **JS writes a cookie whenever the theme changes** (alongside `localStorage`):
   ```js
   localStorage.setItem('vp-theme', dark ? 'dark' : 'light');
   document.cookie = 'vp-theme=' + (dark ? 'dark' : 'light') + '; path=/';
   ```

2. **PHP reads the cookie and emits the class directly on the `<html>` tag**:
   ```php
   <html lang="zh-CN" class="<?php echo (($_COOKIE['vp-theme'] ?? '') === 'dark') ? 'dark' : ''; ?>">
   ```

3. The first frame of HTML is already dark — Firefox has nothing to flash. The inline head script stays as a fallback for the first visit (no cookie yet).

## The pitfall inside the fix: `default_light` vs manual choice

The first attempt guarded the server-side class with the config flag that forces light mode by default:

```php
// WRONG: forced-light flag silently cancels a manual dark choice
class="<?php echo (($_COOKIE['vp-theme'] ?? '') === 'dark' && !$defaultLight) ? 'dark' : ''; ?>"
```

A user who had manually switched to dark while the site config said "default light" still flashed — the server refused to emit `dark`, the first frame was light, and only the late-running JS restored dark.

**Lesson**: the cookie represents the user's *actual current choice* — it must not be filtered by default-preference flags. Defaults only apply when there is no choice at all (no cookie, no `localStorage`).

## Analogy

Previously the page was like a lamp wired to a switch that starts ON: the browser draws a lit room (default light), then the script walks over and turns the lamp off (adds `dark`) — one bright frame. The cookie approach hands the browser a lamp that is already wired OFF: the first frame is dark, nothing to correct.

## Verification

```bash
# with the dark cookie → the first HTML frame carries the class
curl -s -b "vp-theme=dark" https://your.site/?v=test | grep -o '<html[^>]*>'
# → <html lang="zh-CN" class="dark">

# without cookie → default light, class empty
curl -s https://your.site/?v=test2 | grep -o '<html[^>]*>'
```

Manual test: switch to dark once (writes the cookie), then press F5 in Firefox — the page is dark from the very first frame. No flash, including the title separator line (it is just another `var(--line)` border that used to flash with the default value).
