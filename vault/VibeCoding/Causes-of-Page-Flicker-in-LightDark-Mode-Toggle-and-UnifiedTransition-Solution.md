## Symptom

When toggling light‑dark mode, page elements change color at inconsistent speeds: large areas (background, main text) animate with smooth gradients, while details such as dividing lines, code blocks and tables snap instantly to new colors. The two different transition speeds create an unpleasant flickering visual effect. The official VitePress website does not exhibit this behavior during theme switching.

## Principle

### Why desynchronization occurs

Theme switching works by toggling the `html.dark` CSS class. CSS variables such as `--line` and `--vp‑c‑code` take new values immediately. However, **whether an element animates smoothly depends on its own `transition` property**, and CSS `transition` is **not inherited**.

Typical incorrect pattern (common pitfall):
```css
/* Transition only applied to containers */
html, body, .md, #content {
    transition: background-color .25s ease, color .25s ease, border-color .25s ease;
}
```

Result:

| Element | Has transition | Behavior on theme toggle |
|---|---|---|
| body, .md (containers) | Yes | Gradual change over 0.25 s |
| Top border of `.md h2`, `hr` dividing lines | No | Instant snap |
| `.md code`, `.md pre` (code blocks) | No | Instant snap |
| `.md table`, `th`, `td` borders | No | Instant snap |

While main content fades gradually, decorative details change abruptly. This mismatch of timings produces the flicker sensation.

### Why VitePress avoids this issue

VitePress applies unified color transitions to **all elements including pseudo‑elements**:

```css
*, *::before, *::after {
    transition: background-color .25s ease, color .25s ease, border-color .25s ease;
}
```

Every color‑changing element uses identical duration and timing‑function, so animations stay perfectly synchronized and no flicker appears.

## Solutions

### Option 1: Global unified transition (recommended, VitePress‑style)

```css
*, *::before, *::after {
    transition: background-color .25s ease, color .25s ease, border-color .25s ease;
}
```

One‑time fix; any newly added elements automatically participate in synchronized theme transitions.

**Three important caveats**:

1. **Overriding existing animations**: The universal selector `*` has very low specificity. If an element already has `transform` / `opacity`‑based animations (e.g. drawer slide‑out, toast fade‑in), use a **higher‑specificity selector to override its transition**. Note: assigning `transition` replaces the whole property instead of merging individual values.

2. **Slower hover / active state color changes**: The global rule will apply the 0.25 s fade to hover‑triggered color shifts. If instant hover feedback is required, account for this behavior. (In this project hover‑color rules have already been removed so conflicts are not expected.)

3. **`transition: none !important` disables theme transitions (recurring pitfall)**: Adding `transition: none !important` to fix view‑switch flicker also suppresses the theme‑switch color animation for that region, bringing back snap‑style flicker. `!important` overrides the global `*` rule and cannot be reversed by it.
> Correct approach: Resolve view‑switch flicker on the JavaScript side (render content only after it is ready, manipulate `display`). Avoid `transition: none !important`. If certain components (e.g. transform‑animated icons) need custom transition behavior, explicitly include `color`, `background‑color` and `border‑color` inside their `transition` value instead of wiping all transitions.

> **Real‑world case (syntax‑highlighted code blocks)**: After adding highlight.js with per‑token classes (`.hljs-keyword`, `.hljs-string`, …), toggling the theme made the code block area flash. The fix attempt was to disable transitions on the tokens and the copy button with `transition: none !important`. This made it worse: the tokens snapped instantly while the `pre` background and the rest of the page faded over 0.25 s, so the region visibly "jumped" against the page. The correct fix was to **remove every `transition: none !important` rule from the code block and let all elements (including `.hljs-*` tokens and the copy button) follow the global `*` transition**. Additionally, the code block background should use the same variable family as the page background (e.g. `--vp-c-bg-soft` instead of a separate `--code-bg`), so the background and the page interpolate along the same color path. Symptom to recognize: it is not an animation glitch inside the block — it is the whole block (background, border, tokens, button) changing at a different speed from the page. Any region that "snaps while everything else fades" is almost always an element with `transition: none !important` (or a missing transition), not a repaint problem.

### Option 2: Targeted incremental transitions

Add transition properties only to missing detail elements:

```css
.md h2, .md hr, .md code, .md pre, .md table, .md th, .md td,
.md blockquote, .archive-item {
    transition: background-color .25s ease, color .25s ease, border-color .25s ease;
}
```

Pros: Leaves unrelated animations untouched.
Cons: Easy to forget newly‑added components, introducing the same bug again.

## Summary

‑ Root cause of theme‑toggle flicker: **`transition` is not inherited**. Containers carry transition rules but child detail elements do not; two different animation timings run simultaneously during one theme change.
‑ Preferred fix: Apply consistent color transition rules to all elements and pseudo‑elements for full synchronization.
‑ General principle: For full‑page color‑swap animations, transition rules must cover every element whose colors change, not only top‑level containers.