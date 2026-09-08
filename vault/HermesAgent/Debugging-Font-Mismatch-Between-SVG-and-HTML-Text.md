# Debugging Font Mismatch Between SVG and HTML Text

## Symptom

A knowledge graph renders node labels with SVG `<text>`, while the sidebar directory tree uses HTML `<a>`. Users report the labels look different — wrong size, wrong weight, wrong line height — no matter how many times the CSS is "fixed".

## Why it keeps failing

The trap is comparing by **eye** instead of by **measured values**:

1. **SVG text and HTML text render with different font metrics.** The same `font-size: 15px; font-weight: 600` looks visibly thinner in SVG on some browsers, so you bump the weight to 700, then to 800, guessing each time.
2. **`line-height` is an HTML concept.** SVG text has no line box in the same sense; browsers still expose a computed `line-height`, but it is the font's default, not what the HTML layout uses. You cannot see this difference by looking — only in DevTools.
3. Every "fix" is a guess, and each guess takes a round trip: change CSS → deploy → user refreshes → still wrong → guess again.

## The fix: stop guessing, read the numbers

Open DevTools on both elements and read the **computed styles**:

| Property | Directory tree (HTML `<a>`) | Graph label (SVG `<text>`) |
| --- | --- | --- |
| font-size | 17px | 15px |
| font-weight | 700 (Bold) | 600 (SemiBold) |
| line-height | 28.9px | 25.5px |

Then set the SVG label to exactly those values:

```css
.graph-label {
    font-family: 'Nunito', sans-serif;
    font-size: 17px;
    font-weight: 700;
    line-height: 28.9px;   /* SVG exposes this in computed style even though there is no line box */
    text-rendering: optimizeLegibility;
}
```

One change, verified against real numbers, done. No guessing.

## Lesson

**Visual styling mismatches between different rendering contexts (SVG vs HTML, canvas vs DOM) must be debugged with measured values, not eyeballs.** Ask the user for the DevTools numbers (or read them yourself) — the exact values turn a multi-round guessing loop into a one-shot fix.

## Bonus trap: the rule ended up inside another rule's braces

This exact debugging session burned an extra hour: the fix CSS rule was inserted **inside the braces of the previous rule** during a patch:

```css
.drawer-md a {
    font-size: 15px;
    /* BUG: this line is inside .drawer-md a's block — an invalid declaration, silently ignored */
    #front-drawer-md > ul > li:first-child > a { font-size: 17px; font-weight: 700; }
    overflow: hidden;
}
```

The browser treats the nested selector as garbage and **silently ignores it**. Symptoms are indistinguishable from caching: refresh, incognito window, cache-busting query strings — nothing changes, because the CSS genuinely never applies.

**How to catch it**: `php -l` validates PHP, not CSS — it will pass. Read the surrounding braces, or grep for the rule and check it is a standalone block (not indented inside another block).

**Rule of thumb**: after any CSS patch, verify the new rule sits at the correct nesting level before blaming caching.

## Related

- [[Graph-View-Force-Directed-Layout-Notes]]
- [[Why-SVG-Icons-Flicker-on-Click]]
- [[CSS-Transition-and-Theme-Switching]]
