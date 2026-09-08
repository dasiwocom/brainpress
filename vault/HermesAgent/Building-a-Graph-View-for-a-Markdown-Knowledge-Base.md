# Building a Graph View for a Markdown Knowledge Base

## The goal

Add an Obsidian-style knowledge graph to a self-hosted Markdown knowledge base: every note is a node, every `[[wikilink]]` is an edge, nodes drift into place with a force-directed simulation, you can drag/zoom/pan, and clicking a node opens the note.

## Architecture (three pieces)

```
GET /api/graph          → scans vault, parses [[wikilinks]], returns {nodes, links}
/graph (virtual URL)    → nginx rewrites to index.php; SSR detects it and flags the frontend
Frontend SVG renderer   → force-directed simulation + interactions (zero dependencies)
```

### 1. Data — `GET /api/graph`

Scan every `.md` in the vault (reuse the existing tree scanner), exclude hidden paths, and parse links:

```php
preg_match_all('/\[\[([^\]\|#]+)(?:\|[^\]]*)?\]\]/u', $content, $m)
```

Match targets by basename (`Note-Name` matches `dir/Note-Name.md`) so links survive small moves. Deduplicate undirected edges (`source<target` key). Optional `?dir=` filter limits scope.

### 2. Routing — `/graph` is a virtual page, not a file

The single PHP entry decides what to render from the URL:

- `/xxx.md` → article SSR
- `/xxx.pdf` → PDF reader SSR
- `/graph` → graph SSR (`SSR_GRAPH = true` inline, frontend opens the graph view)

**Pitfall**: nginx needs an explicit rule — `location = /graph { rewrite ^(.*)$ /index.php last; }`. Without it the URL hits the default location, looks for a file, and 404s before PHP ever runs.

### 3. Frontend — force-directed SVG, zero dependencies

Iterate physical forces each frame (or once at open):

- **Repulsion** between every node pair (O(n²) — fine for tens of nodes)
- **Spring** attraction along links
- **Center gravity** (keeps the cluster from drifting)
- **Damping** (energy loss — without it, oscillation forever)
- Optional **same-directory weak attraction** (cluster notes by folder *without* drawing lines)

Interactions: drag nodes (pointer events), wheel zoom around cursor, pan on empty drag, pinch zoom on mobile (two-pointer distance ratio), hover shows the label and highlights neighbors, click opens the note (suppressed after a real drag — see below).

## Problems encountered (in order)

### 1. Relative asset paths break on nested URLs

Assets referenced as `assets/foo.css` resolve against the current URL — on `/guide/note.md` they become `/guide/assets/foo.css` → 404 → the JS bundle fails → *everything* breaks (blank page, no graph). **Fix**: use absolute paths `/assets/...` everywhere.

### 2. The graph container ended up inside a hidden panel — blank page for hours

During a refactor the graph `<div>` was inserted inside the TOC panel, whose parent has `display:none`. A `display:none` ancestor hides the whole subtree — no matter what `display`/`z-index`/`!important` the child sets, it never renders. **Debugging lesson**: when an element with `position:fixed` and `background:red` still does not show, check *where in the DOM it actually sits*, not what its own styles say.

### 3. Height collapse

`height:100%` on a child of a flex container with no explicit height resolves to `auto` → 0 → nothing visible. **Fix**: `position:fixed; top:56px; left:0; right:0; bottom:0` — fixed positioning gives a guaranteed size independent of parent layout.

### 4. The simulation never settles — nodes bounce forever

Bad parameters: repulsion too strong, center gravity too weak, damping too low. Nodes oscillated in a fight between forces. **Fix**: modest repulsion, stronger center gravity, `DAMP 0.85–0.9`, per-node speed clamp, and a **hard frame cap** (e.g. 200–600 frames) so the simulation is guaranteed to stop. Then tune "loose vs tight" with center gravity (0.03 = snappy, 0.015 = loose, less jelly-like).

### 5. Drag triggers click — jumping to the article while dragging

The browser fires `click` after `pointerdown`+`pointerup` on the same element, so a drag ended in navigation. **Fix**: track pointer movement; if it exceeds ~5px, mark the node as dragged and suppress the click handler.

### 6. Mobile drag freezes

Raw `pointermove` handlers fire at 120Hz+; each one updated the DOM → the main thread choked. **Fix**: coalesce moves with `requestAnimationFrame` (store the latest coordinates, apply once per frame) and add `touch-action:none` on the canvas so the browser does not fight the drag with scrolling/gestures.

### 7. Dragging feels "dead" — only the dragged node moves

Removed the simulation during drag for performance, which killed the Obsidian feel. **Fix**: run one force step per drag frame (cheap at 34 nodes) and update only nodes whose velocity exceeds a threshold (`updateMovingEls`) — the graph responds without the cost of redrawing everything.

### 8. Font mismatch between SVG labels and HTML tree

SVG `<text>` and HTML text render with different metrics; eyeballing the difference leads to endless guessing (see [[Debugging-Font-Mismatch-Between-SVG-and-HTML-Text]]). **Fix**: read computed styles in DevTools (17px / 700 / line-height 28.9px) and set the SVG label to exactly those values.

### 9. Perceived jank

- 30fps simulation (every-other-frame) reads as laggy → simulate every frame.
- `setAttribute('transform')` triggers attribute/layout updates → switch node movement to CSS `style.transform` (compositor/GPU path; safe because the SVG has no viewBox, so CSS px == SVG units) plus `will-change: transform` on the container group.

## Final behavior

- Open `/graph`: layout mostly settled before paint (220 pre-iteration passes), a brief gentle settle, then static.
- Drag a node: neighbors follow (live force), hover highlight shows the relation network, background dims.
- Wheel/pinch zoom with labels auto-hiding below a scale threshold (Obsidian behavior, toggle in admin).
- Click a node (not dragged): opens the note.
- Admin panel: Graph settings view (show/hide file names).

## Takeaways

1. Blank page + working code usually means the element is **in the wrong DOM container** or **assets 404** — check those before blaming the logic.
2. Force simulations need hard stop conditions (frame cap + velocity threshold) or they oscillate forever.
3. Mobile smoothness = coalesce pointer events + touch-action + update only what moves.
4. Visual parity between SVG and HTML = measured values from DevTools, never eyeballs.

## Related

- [[Graph-View-Force-Directed-Layout-Notes]]
- [[Debugging-Font-Mismatch-Between-SVG-and-HTML-Text]]
- [[Markdown-Wikilinks-Connect-Your-Knowledge]]
- [[Server-Side-Rendering-for-Markdown-Sites]]
