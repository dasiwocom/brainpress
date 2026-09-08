## Symptom

On mobile devices, tapping an input field to bring up the keyboard causes the whole page to suddenly zoom‑in and jitter. Alternatively, the viewport scrolls and jumps erratically when the input receives focus. The layout returns to normal when tapping elsewhere on the page.

## Principle

### Root Cause of Auto‑Zoom: Font‑Size Smaller Than 16px

This is a hard‑enforced rule for **iOS Safari and some Android browsers**:

> When `<input>` / `<textarea>` elements have a computed font‑size below 16 px, triggering `focus` will cause the browser to automatically zoom the page.

Rationale: On small mobile screens, browser vendors want to guarantee readable text for user input. Safari forces zooming to achieve a minimum readable font size of 16 px on focus, and reverts zoom on blur.

This creates a well‑known frontend pitfall: designers set input font‑size to 14 px / 15 px for aesthetic reasons, which triggers involuntary browser zoom and layout shifting on mobile.

Android Chrome exhibits comparable behavior. Implementation details differ, but input fields under 16 px are also prone to unwanted scaling.

### Root Cause of Page Jumping: Automatic Scroll‑on‑Focus

Upon receiving focus, browsers automatically scroll the focused input into the viewport, especially when it lies off‑screen or gets covered by the virtual keyboard.
Combined with keyboard pop‑up and ongoing page animations, this native scroll behavior produces jarring visual jumps.

## Solutions

### Option 1: Input font‑size ≥ 16 px (Primary Fix)
```css
input, textarea {
    font-size: 16px;   /* iOS will not trigger forced zoom when value >=16px */
}
```
This is the only fundamental solution. Viewport meta tags that disable zoom only mitigate symptoms. Setting proper font‑size addresses the root cause.

> Important note: Applying `transform: scale()` to visually shrink a 16 px input down to 14 px does **not** work. iOS evaluates the final rendered visual size, and auto‑zoom will still activate.

### Option 2: Suppress automatic scrolling on focus
Use this when you want to avoid viewport jumps:
```javascript
input.focus({ preventScroll: true });  // Focus element without scrolling the page
```

### Option 3: Defer focus until animation completes
If your input sits inside a sliding‑out / expanding UI component (e.g. a slide‑down search panel). Calling focus while the transition is still running causes browser scrolling to conflict with your animation, resulting in jank.

```javascript
// Wait for panel transition (example: 300 ms) before activating focus
setTimeout(() => input.focus({ preventScroll: true }), 320);
```

## Summary
‑ Unwanted zoom on mobile input focus originates from **iOS’s mandatory zoom rule for inputs with font‑size < 16 px**.
‑ Primary resolution: set `font‑size: 16px` or larger for input elements.
‑ For layout jumps: use `focus({ preventScroll: true })` to skip automatic scrolling. When inside animated containers, delay focus until animations finish.
‑ This is standard mobile‑browser behavior, independent of which framework generates your markup.