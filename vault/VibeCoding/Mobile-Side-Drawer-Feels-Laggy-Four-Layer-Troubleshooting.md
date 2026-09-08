# Mobile Side-Drawer Feels Laggy or Slow to Respond: A Four-Layer Troubleshooting Chain

## Symptom

A full-screen side drawer (slide-in menu) on a mobile web app feels bad in three distinct ways:

1. **First tap is janky** — the drawer eventually appears, but the tap-to-open is visibly slow/stuttered.
2. **The slide animation drops frames** — motion is not smooth, especially on WeChat's built-in browser (X5/XWeb) or low-end devices.
3. **Response feels delayed** — there is a noticeable gap between tapping the button and the drawer starting to move.

Each symptom has a different root cause. Fix them in this order.

## Layer 1: First-tap jank → render work happening inside the click handler

### Cause
The menu content was rendered *lazily on first click*: `marked.parse()` + `DOMPurify.sanitize()` + binding click handlers on every link, all executed synchronously inside the button's `click` handler. The animation only started after all that finished, so the first tap blocked for tens of milliseconds (or more on slow devices).

### Fix
Prerender the content once at page load (the drawer is off-screen/translated out, so rendering is invisible), and let the click handler only toggle a CSS class:

```js
// At page init:
drawerContent.innerHTML = DOMPurify.sanitize(marked.parse(MENU_MD, { gfm: true }));
// ... bind link handlers ...
// In click handler: only classList.toggle('open')
```

## Layer 2: Dropped frames during animation → backdrop-filter being recomputed every frame

### Cause
The top navbar uses `backdrop-filter: blur(30px)` (glassmorphism). While a full-screen drawer slides underneath it, the blurred region's content changes every frame, forcing the browser to re-run the expensive blur filter per frame. Combined with CPU (non-GPU) transform animation, the result is obvious frame drops in mobile WebViews.

### Fix
Two parts:

1. Force GPU compositing for the drawer:
```css
#drawer {
    transform: translate3d(-100%, 0, 0);
    will-change: transform;
    transition: transform .2s ease-out;
}
#drawer.open { transform: translate3d(0, 0, 0); }
```

2. Temporarily disable the navbar blur while the drawer is open (restore it when closed):
```css
#nav.no-blur, #nav.no-blur > * {
    -webkit-backdrop-filter: none !important;
    backdrop-filter: none !important;
}
```
```js
function setDrawer(open) {
    drawer.classList.toggle('open', open);
    nav.classList.toggle('no-blur', open);
}
```

## Layer 3: Delayed response → the mobile 300 ms click delay

### Cause
Mobile browsers (iOS Safari, Android Chrome, and especially WeChat's X5/XWeb WebView) historically delay `click` events by ~300 ms to decide whether the tap is a double-tap (e.g. for zooming). Removing `user-scalable=no` from the viewport meta (for accessibility) brings this delay back — the button visually responds 300 ms late, which reads as "slow".

### Fix
One CSS line tells the browser this element does not need double-tap detection:
```css
.vp-icon-btn { touch-action: manipulation; }
```
No JavaScript needed. (Do NOT rely on `user-scalable=no` — it is ignored by iOS Safari 10+ anyway, and disabling zoom hurts accessibility.)

## Layer 4: Animation duration

A 0.3 s ease-in-out curve feels sluggish on mobile; `0.2 s ease-out` (fast start, settle quickly) feels snappy. Minor, but noticeable together with the fixes above.

## Summary: Troubleshooting checklist for "drawer feels laggy"

| Symptom | Root cause | Fix |
| --- | --- | --- |
| First tap janky | Render work inside click handler | Prerender at page load |
| Animation drops frames | backdrop-filter recompute + CPU transform | `translate3d` + `will-change` + disable blur during animation |
| Delayed response | Mobile 300 ms click delay | `touch-action: manipulation` |
| Feels slow overall | Long animation curve | `0.2s ease-out` |

## Related

- [Mobile Input Focus Zoom and Page Jumping Causes and CSS/JS Workarounds](../knowledge/article/Mobile-Input-Focus-Zoom-and-Page-Jumping-Causes-and-CSS-JS-Workarounds.md) — same family of mobile-web pitfalls: input focus zoom, viewport handling, `touch-action`.
