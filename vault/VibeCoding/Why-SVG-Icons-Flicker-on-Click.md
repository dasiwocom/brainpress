# Why SVG Icons Flicker on Click

## Symptom

Icon buttons built with inline SVG flicker briefly when clicked on mobile browsers (iOS Safari / WebView), even though the same page is smooth on desktop.

## Why

The click triggers a style/layout recalculation, and the browser repaints the SVG icon for one frame. On iOS this repaint is visible as a flash because the icon lives on the main compositing layer.

## Fix

Force the icon onto its own GPU layer so the repaint never reaches the screen:

```css
.vp-icon-btn svg {
    -webkit-backface-visibility: hidden;
    backface-visibility: hidden;
}
.vp-icon-btn { transform: translateZ(0); }
```

Do **not** put `translateZ(0)` on the SVG itself when the icon has a `transform` animation (the morph) — it overrides the animation. The button is the safe host for the layer hint.

## Related

- [[Causes-of-Page-Flicker-in-LightDark-Mode-Toggle-and-UnifiedTransition-Solution]]
- [[Preventing-Night-Mode-Flash-on-Refresh-in-Firefox]]
- [[CSS-Transition-and-Theme-Switching]]
