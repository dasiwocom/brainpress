# Rendering Obsidian Excalidraw Drawings in a Markdown Knowledge Base

## The goal

A self-hosted Markdown knowledge base renders `.md`, `.pdf` and graph views. Add one more type: **Excalidraw drawings** saved by the Obsidian Excalidraw plugin (`.excalidraw.md`) — click a drawing in the tree and see the canvas as an interactive SVG, exactly like it looks in Obsidian.

## The file format (the first trap)

An Obsidian Excalidraw file looks like:

```markdown
---
excalidraw-plugin: parsed
tags: [excalidraw]
---
==⚠ Switch to EXCALIDRAW VIEW...
# Text Elements
G ^xYm6DtRp
C ^g9LmDvct
...
## Drawing
```compressed-json
N4KAkARALgngDgUwgLgAQQQDwMYEMA2AlgCYBOuA7hADTgQBuCpAzoQPYB2Kq...
```
```

The scene data lives in a `compressed-json` code block. **It is NOT zlib, gzip, brotli or raw deflate.** The first bytes (`37 82 80 90...`) defeat every standard decompressor. It is **lz-string** (`LZString.decompressFromBase64`). Test all candidates in Node to identify it:

```js
// inflate / inflateRaw / gunzip / brotli all fail — lz-string works
const scene = JSON.parse(LZString.decompressFromBase64(b64.replace(/\s+/g, '')));
// => { type: "excalidraw", version: 2, elements: [...] }
```

Download `lz-string.min.js` (~5 KB) into the site's assets.

## Architecture

1. **PHP**: detect `.excalidraw.md` in the URL (exclude it from the normal `.md` branch — both end in `.md`), inline the raw file content as a JS variable.
2. **Frontend**: `LZString.decompressFromBase64` → parse scene → build an SVG string → insert into a container.
3. View switching mirrors the PDF reader: hide the markdown view, show the drawing container, set the title from the filename.

Element types rendered to SVG: `text`, `arrow`, `line`, `rectangle`, `ellipse`, `diamond`. Arrows need a `<marker>`; multi-point arrows must be `<polyline>` (a `<line>` between first and last point flattens curves).

## Pitfalls (each one cost real debugging time)

### 1. Line-bound text renders at the LINE MIDPOINT, not at its saved coordinates

The big one. Some letters (F, I, H, J, A) were offset by up to 253 px from their lines. The saved `text.x/y` for those was **not snapped to the line**, yet Obsidian displayed them on the line. Why?

Excalidraw's **bound text**: a text element has a `containerId` pointing at its line; the line's `boundElements` lists the text. The engine renders bound text **centered on the line's midpoint**, ignoring unsnapped saved coordinates. Detection:

- Do NOT check `text.boundElementIds` — it is empty. Check `text.containerId` (the line id) and the line's `boundElements`.
- Build a `lineId -> midpoint` map, then for any text with `containerId`, replace its box center with the line midpoint:

```js
const lineMid = {};
els.forEach(a => {
  if (a.type !== 'arrow' && a.type !== 'line') return;
  const pts = a.points || [[0,0],[100,0]];
  lineMid[a.id] = { x: (a.x + pts[0][0] + a.x + pts.at(-1)[0]) / 2,
                    y: (a.y + pts[0][1] + a.y + pts.at(-1)[1]) / 2 };
});
// text rendering: if (e.containerId && lineMid[e.containerId])
//   box center = lineMid[e.containerId]  (left = mid.x - w/2, top = mid.y - h/2)
```

**Verify with geometry, not eyes**: compute every text's box center vs the nearest line midpoint in the data. Letters that match (distance 0) are unsnapped-but-fine; letters that don't match are exactly the ones users report as wrong.

### 2. `verticalAlign: "middle"` is a trap — y is still the box top

The data has `verticalAlign: middle` on most texts, which *looks* like "y is the center". Measuring box-center-to-line-midpoint distance proves y is the top (distance 0.0 when rendered as top). Do not "fix" it by shifting — verify against the geometry first.

### 3. Font matters for exact positioning

Excalidraw measures text width/height with its hand-drawn font **Virgil**. Without it, fallback fonts shift centered text. Download `Virgil.woff2` (from the `@excalidraw/excalidraw` npm package via jsDelivr) and register it with `@font-face`. `text-anchor:middle` keeps the x-center fixed, but height/metrics still affect y.

### 4. `dominant-baseline` is not reliable — use baseline compensation

`dominant-baseline: text-before-edge` is ignored by some WebViews (older WeChat/Chromium), silently falling back to the alphabetic baseline and shifting glyphs up ~20 px. Instead of relying on it, render with the normal baseline and compensate:

```js
// canvas-measure the real ascent after the font loads (fallback 0.9)
const y = textTop + ascentRatio * fontSize;   // glyph top ≈ textTop
```

`await document.fonts.load('20px Virgil')` first, then measure with `canvas.getContext('2d').measureText(...).actualBoundingBoxAscent`.

### 5. The mobile WebView cache hides every fix

User kept reporting "no change" through several fixes. The code was changing every round; WeChat's WebView cache served the old page every time. Break the loop by having the user open the URL in the phone's real browser (Safari/Chrome) or append `?v=timestamp`. Verify the fix server-side with curl first, then distinguish cache from code.

### 6. Don't forget to show the container

The container starts `display:none`; after `innerHTML = svg` set `style.display = 'block'`, or the page is blank with no error.

## Takeaways

1. Identify the compression before writing code — test every candidate (zlib/gzip/brotli/lz-string) in Node first.
2. Read ALL binding fields: `boundElementIds` vs `containerId` vs `boundElements` — they are different sides of the same mechanism.
3. Verify rendering positions mathematically against the scene geometry (letter center vs line midpoint) instead of guessing and re-deploying.
4. When the user says "no change" repeatedly, suspect the client cache before touching the code.

## Related

- [[Debugging-Font-Mismatch-Between-SVG-and-HTML-Text]]
- [[Building-a-Graph-View-for-a-Markdown-Knowledge-Base]]
- [[Server-Side-Rendering-for-Markdown-Sites]]
