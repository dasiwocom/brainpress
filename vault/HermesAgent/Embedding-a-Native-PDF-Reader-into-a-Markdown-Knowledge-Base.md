# Embedding a Native PDF Reader into a Markdown Knowledge Base

## Symptom

You run a self-hosted Markdown knowledge base (single PHP entry, SSR for `.md` files, SPA client). Users want PDF books to open **in-site** like articles: click a PDF in the tree → a reader page renders it — no download, no redirect to the browser's native viewer. Plus it must respect the site's light/dark theme.

This note documents the full integration and every pitfall hit along the way (routing, permissions, canvas rendering, mobile sharpness, fullscreen, theme-switch flicker).

## Architecture

```
/xxx.pdf (page URL, no /vault/ prefix)
   → nginx: location ~* \.pdf$ { rewrite ^(.*)$ /index.php last; }   (SSR reader page)
/vault/xxx.pdf (file URL)
   → nginx: location ^~ /vault/ { }                                    (static original, pdf.js fetches this)
```

The front end loads `pdf.js` lazily (only when opening a PDF), fetches the original via `/vault/...`, and renders **one page at a time** onto a `<canvas>` element. Each page is a bitmap drawn from the PDF's vector instructions — not HTML text. Page turn = re-render canvas; zoom = re-render at a new scale (pixel-accurate, not stretched).

## Pitfall 1: File permission silently kills the whole site

**Symptom**: after editing `functions.php` with a patch tool, every page returns a 200 error page containing `Fatal error: require(.../functions.php): Failed to open stream: Permission denied`.

**Cause**: the patch tool rewrites the file as `root` with mode `600`. PHP-FPM runs as `www` and cannot read it. Because the fatal happens before headers are sent, a CDN may cache the error page — the site looks "unchanged" for a long time even after the fix.

**Fix**: after any server-side file edit, run `chown www:www file` and `chmod 644 file`, then verify with `curl` (use a fresh URL or bust the CDN cache, e.g. rename the test file).

## Pitfall 2: Wide range replacements can delete functions in between

**Symptom**: markdown articles stop rendering. Server returns 200 and valid HTML, but the console says `Cannot find variable: showArticle`. Clicking any tree item does nothing.

**Cause**: a script replaced a large code block (from `// PDF reader` to `async function selectFile`) but the replaced range contained the `showArticle` function definition in the middle. Syntax was fine; the function simply no longer existed at runtime.

**Fix**: after any large-range replacement, grep for the boundary functions (`grep -c "function showArticle"`). Prefer smaller patches with unique anchors. Extract the final JS and run `node --check` (strip `<?php ... ?>` first — replace with `null;` — otherwise PHP echoes break the check).

## Pitfall 3: Fit-to-width must not read container width too early

**Symptom**: initial zoom shows a tiny 30% thumbnail instead of filling the content area; horizontal scrolling appears.

**Cause**: `clientWidth` of the canvas wrapper read right after `display` switching can still be 0 (layout not computed), so the fallback clamps the scale to the minimum.

**Fix**: measure a stable element instead — `document.querySelector('.doc-wrap').clientWidth` (the same width as markdown articles) with a window-width fallback. Also: **fit must have no range clamp** (0.3~1.5). Wide or small PDF pages legitimately need values outside that range to fill the width; the clamp is only for the manual zoom buttons.

## Pitfall 4: Zoom buttons do nothing because an auto-correct loop overrides them

**Symptom**: `+` / `−` buttons appear dead.

**Cause**: an "auto-correct" check (if canvas wider than container → force refit) was re-triggering after every zoom-in, snapping the scale back to fit. The user never saw the zoom.

**Fix**: delete auto-correct entirely. Zoom buttons re-render the page at the new scale; nothing else should fight them.

## Pitfall 5: Function scope — renderer referencing a local variable

**Symptom**: reading works but zoom/page-turn throws `ReferenceError: cw is not defined`.

**Cause**: `renderPdfPage()` (module scope) referenced `cw`, a local variable created inside `openPdf()`. Also `fitScale` was defined inside `openPdf` but called from `renderPdfPage`.

**Fix**: declare all PDF state and helpers at module level (`pdfDoc`, `pdfScale`, `fitScale`, `renderPdfPage`, `openPdf`), keep only UI-wiring closures inside `openPdf`.

## Pitfall 6: Blurry text on phones — canvas pixel density

**Symptom**: text is fuzzy on mobile but acceptable on desktop.

**Cause**: canvas pixels are logical pixels; phone screens have a device pixel ratio (DPR) of 2–3, so each canvas pixel is stretched over 2–3 physical pixels.

**Fix**: render at `scale × devicePixelRatio × 1.5` (supersampling), then set the canvas CSS size to the logical size (`pixelWidth / dpr`). Crisp text, layout unchanged. Trade-off: bigger canvas, slightly slower page turns.

## Pitfall 7: Scanned PDFs ignore transparent backgrounds

**Symptom**: text-based PDFs get a theme-matching background (transparent canvas → page background shows through), but scanned books stay black after inversion.

**Cause**: scanned PDFs are full-page images — the white background is inside the image pixels, not a renderer background. `page.render({ background: 'transparent' })` cannot help.

**Fix**: post-process pixels — after rendering, walk the `ImageData` and set near-white pixels (`r,g,b > 235`) to alpha 0. Combined with the CSS `invert` filter, dark text turns white and the page background shows through. Text-based PDFs are unaffected (their backgrounds are already transparent).

## Pitfall 8: Theme toggle flicker — the full chain

The flicker fight had four rounds; each round's "fix" caused the next symptom. Recorded here so you can skip ahead:

### Round 1: whole PDF frame flashes

Adding `#pdf-view, #pdf-view * { transition: none !important; }` (to "stop the canvas from transitioning") made the whole frame (background, toolbar, border) **snap instantly while the page faded** over 0.25 s. That desynchronization is the classic `transition: none !important` pitfall (see the theme-transition note). **Remove it** — the global `*, *::before, *::after { transition: ... }` rule must also cover the PDF frame.

### Round 2: canvas region still flashes on dark → light

The canvas is a bitmap: its pixels change instantly (no CSS transition), while the page background animates over 0.25 s. Specifically, removing `html.dark` drops the `filter: invert` immediately — text jumps from white to black mid-background-fade.

**Fix A (mechanics)**: cache pixel buffers. After each render, save the original `ImageData`; on first dark switch, build a processed copy (white→transparent) once. Theme switches then only `putImageData` (fast copy, no per-switch pixel walk, no intermediate frame).

**Fix B (timing)**: give the canvas a filter transition so inversion fades in sync with the background:

```css
.pdf-canvas-wrap canvas { transition: filter .25s ease; }
```

**Fix C (order)**: switch class and pixels in the order that keeps visuals stable under the current filter state:

```js
if (dark) {
    // night: swap pixels first (invert not active yet — visually identical), then add class (filter fades)
    putImageData(darkCopy); document.documentElement.classList.add('dark');
} else {
    // day: remove class first (filter fades — text white→black with background), then restore original pixels
    document.documentElement.classList.remove('dark'); putImageData(original);
}
```

After all three, toggling themes is a smooth, synchronized fade: background and canvas text interpolate along the same 0.25 s curve.

## Pitfall 9: Fullscreen button dead on phones

**Symptom**: the fullscreen button does nothing in mobile browsers / WeChat WebView.

**Cause**: iOS Safari and many WebViews only support the Fullscreen API for `<video>`; `div.requestFullscreen` is missing or silently fails.

**Fix**: try native fullscreen first, then fall back to a CSS simulation — add a class that makes the reader `position:fixed; inset:0; z-index:9999` with its own flex column layout. Re-fit width on enter/exit (fullscreen uses `window.innerWidth`, normal mode uses the doc-wrap width).

## Summary

- PDF in a Markdown site = lazy-loaded `pdf.js` + one canvas page at a time + nginx routing that separates page URLs (`/x.pdf` → SSR) from file URLs (`/vault/x.pdf` → static).
- Five recurring classes of bugs: file permissions after edits, wide replacements eating sibling functions, premature width reads, auto-correct loops fighting user actions, and function-scope leaks.
- Theme switching on a canvas needs three things: no `transition: none !important`, pixel-buffer caching, and a `filter` transition timed with the global background fade.
- Mobile quality requires DPR-aware supersampled rendering; scanned PDFs need pixel-level white→transparent processing for night mode; mobile fullscreen needs a CSS fallback.
