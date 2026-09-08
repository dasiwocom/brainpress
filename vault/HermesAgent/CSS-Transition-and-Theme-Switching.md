# CSS Transition and Theme Switching

## The problem

A global rule gives every element a color transition for smooth theme switches:

```css
* { transition: background-color .25s, color .25s, border-color .25s; }
```

Then one region (a panel, a canvas viewer) gets `transition: none !important` to fix a view-switch flicker — and now that region **snaps** while everything else fades. The mismatch reads as another flicker.

## The rule

Never fight the global transition with `transition: none !important` on a whole region. Either:

- let the region transition with the page, or
- move the logic into JavaScript (swap pixels/classes in one frame, which the browser renders atomically).

## The canvas case

Bitmapped content (PDF pages, SVG graphs) has no CSS colors to transition. Theme switches there must be handled in code — swap the rendered pixels, then flip the theme class, in the same synchronous block. No intermediate frame, no flicker.

## Related

- [[Causes-of-Page-Flicker-in-LightDark-Mode-Toggle-and-UnifiedTransition-Solution]]
- [[Why-SVG-Icons-Flicker-on-Click]]
- [[Preventing-Night-Mode-Flash-on-Refresh-in-Firefox]]
